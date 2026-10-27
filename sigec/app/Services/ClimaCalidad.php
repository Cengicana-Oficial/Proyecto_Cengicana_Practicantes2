<?php

namespace App\Services;

use App\Models\Configuracion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Control de calidad de la base climatica (clima_diario):
 * - Fuera del rango valido de la variable = ERROR: se excluye solo (el valor
 *   pasa a NULL en clima_diario y el original queda en clima_calidad).
 * - Fuera del rango de alerta, o atipico estadistico (|z| >= umbral respecto
 *   del promedio de esa estacion en ese mes del año) = ATIPICO: queda
 *   pendiente de revision y se sigue usando.
 * Las decisiones de las personas (validado / excluido a mano) se respetan al
 * volver a revisar o a descargar; las exclusiones automaticas se deshacen
 * solas si el valor deja de estar fuera de rango (p. ej. al ajustar rangos).
 */
class ClimaCalidad
{
    const VARIABLES = ['lluvia', 'tmax', 'tmin', 'tmed', 'hr', 'hr_min', 'hr_max', 'viento', 'radiacion', 'eto'];

    const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Minimo de dias de la estacion en ese mes para usar la regla estadistica. */
    const MIN_MUESTRA = 30;

    protected ?array $rangos = null;

    protected array $climatologia = [];

    public function rangos(): array
    {
        return $this->rangos ??= DB::table('clima_rangos')->get()->keyBy('variable')->map(fn ($r) => [
            'valido_min' => $r->valido_min !== null ? (float) $r->valido_min : null,
            'valido_max' => $r->valido_max !== null ? (float) $r->valido_max : null,
            'alerta_min' => $r->alerta_min !== null ? (float) $r->alerta_min : null,
            'alerta_max' => $r->alerta_max !== null ? (float) $r->alerta_max : null,
            'estadistico' => (bool) $r->estadistico,
        ])->all();
    }

    public function umbralZ(): float
    {
        $z = Configuracion::grupo('calidad')['z'] ?? null;

        return $z !== null && is_numeric($z) ? (float) $z : 3.0;
    }

    /**
     * Revisa [desde, hasta] (opcionalmente solo algunas estaciones) y aplica
     * las reglas. Devuelve conteos de lo encontrado y de lo que cambio.
     */
    public function revisar(Carbon $desde, Carbon $hasta, ?array $estaciones = null): array
    {
        $rangos = $this->rangos();
        $zLim = $this->umbralZ();
        $ini = $desde->format('Y-m-d');
        $fin = $hasta->format('Y-m-d');
        $res = ['registros' => 0, 'errores' => 0, 'atipicos' => 0, 'nuevos' => 0, 'excluidos' => 0, 'restaurados' => 0, 'resueltos' => 0];

        $q = DB::table('clima_calidad')->whereBetween('fecha', [$ini, $fin]);
        if ($estaciones) {
            $q->whereIn('estacion_id', $estaciones);
        }
        $existentes = $q->get()->keyBy(fn ($e) => $e->estacion_id.'|'.substr($e->fecha, 0, 10).'|'.$e->variable);

        $diario = DB::table('clima_diario')->whereBetween('fecha', [$ini, $fin]);
        if ($estaciones) {
            $diario->whereIn('estacion_id', $estaciones);
        }
        $ids = (clone $diario)->distinct()->pluck('estacion_id')->all();
        $this->cargarClimatologia($ids);

        $vistos = [];
        $insertar = [];
        $anular = [];   // [variable][] => [estacion_id, fecha]
        $ahora = now();
        foreach ($diario->select(array_merge(['estacion_id', 'fecha'], self::VARIABLES))->cursor() as $f) {
            $res['registros']++;
            $fecha = substr($f->fecha, 0, 10);
            $mes = (int) substr($fecha, 5, 2);
            foreach (self::VARIABLES as $v) {
                if ($f->{$v} === null) {
                    continue;
                }
                $x = (float) $f->{$v};
                $hallazgo = $this->evaluar($v, $x, $f->estacion_id, $mes, $rangos, $zLim);
                if (! $hallazgo) {
                    continue;
                }
                [$nivel, $regla, $detalle] = $hallazgo;
                $k = $f->estacion_id.'|'.$fecha.'|'.$v;
                $vistos[$k] = true;
                $res[$nivel === 'error' ? 'errores' : 'atipicos']++;
                $e = $existentes[$k] ?? null;

                if ($e) {
                    if ($e->estado === 'validado') {
                        continue; // una persona decidio conservarlo
                    }
                    if ($e->estado === 'excluido') {
                        // Re-descargado desde la API: se vuelve a excluir.
                        $anular[$v][] = [$f->estacion_id, $fecha];
                        DB::table('clima_calidad')->where('id', $e->id)->update(['valor' => $x, 'updated_at' => $ahora]);
                        continue;
                    }
                    $cambios = ['valor' => $x, 'nivel' => $nivel, 'regla' => $regla, 'detalle' => $detalle, 'updated_at' => $ahora];
                    if ($nivel === 'error') {
                        $cambios += ['estado' => 'excluido', 'automatico' => true];
                        $anular[$v][] = [$f->estacion_id, $fecha];
                        $res['excluidos']++;
                    }
                    DB::table('clima_calidad')->where('id', $e->id)->update($cambios);
                    continue;
                }

                $insertar[] = [
                    'estacion_id' => $f->estacion_id, 'fecha' => $fecha, 'variable' => $v, 'valor' => $x,
                    'nivel' => $nivel, 'regla' => $regla, 'detalle' => $detalle,
                    'estado' => $nivel === 'error' ? 'excluido' : 'pendiente', 'automatico' => $nivel === 'error',
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ];
                $res['nuevos']++;
                if ($nivel === 'error') {
                    $anular[$v][] = [$f->estacion_id, $fecha];
                    $res['excluidos']++;
                }
            }
        }

        foreach (array_chunk($insertar, 500) as $lote) {
            DB::table('clima_calidad')->insert($lote);
        }
        foreach ($anular as $v => $pares) {
            foreach ($pares as [$est, $fecha]) {
                DB::table('clima_diario')->where('estacion_id', $est)->where('fecha', $fecha)->update([$v => null]);
            }
        }

        // Hallazgos que ya no se detectan (valor en NULL por estar excluido, o rangos cambiados).
        foreach ($existentes as $k => $e) {
            if (isset($vistos[$k])) {
                continue;
            }
            $sigue = $this->evaluar($e->variable, (float) $e->valor, $e->estacion_id, (int) substr($e->fecha, 5, 2), $rangos, $zLim);
            if ($e->estado === 'excluido') {
                if ($sigue || ! $e->automatico) {
                    continue; // sigue fuera de rango, o lo excluyo una persona
                }
                DB::table('clima_diario')->where('estacion_id', $e->estacion_id)->where('fecha', substr($e->fecha, 0, 10))
                    ->update([$e->variable => $e->valor]);
                DB::table('clima_calidad')->where('id', $e->id)->delete();
                $res['restaurados']++;
            } elseif (! $sigue) {
                DB::table('clima_calidad')->where('id', $e->id)->delete();
                $res['resueltos']++;
            }
        }

        return $res;
    }

    /** [nivel, regla, detalle] o null si el valor es aceptable. */
    public function evaluar(string $v, float $x, int $estacion, int $mes, array $rangos, float $zLim): ?array
    {
        $r = $rangos[$v] ?? null;
        if (! $r) {
            return null;
        }
        $f = fn ($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        if (($r['valido_min'] !== null && $x < $r['valido_min']) || ($r['valido_max'] !== null && $x > $r['valido_max'])) {
            return ['error', 'rango', 'Fuera del rango valido ('.($r['valido_min'] !== null ? $f($r['valido_min']) : '-∞').' a '.($r['valido_max'] !== null ? $f($r['valido_max']) : '∞').')'];
        }
        if (($r['alerta_min'] !== null && $x < $r['alerta_min']) || ($r['alerta_max'] !== null && $x > $r['alerta_max'])) {
            return ['atipico', 'alerta', 'Fuera del rango de alerta ('.($r['alerta_min'] !== null ? $f($r['alerta_min']) : '-∞').' a '.($r['alerta_max'] !== null ? $f($r['alerta_max']) : '∞').')'];
        }
        if ($r['estadistico'] && isset($this->climatologia[$estacion][$mes][$v])) {
            [$n, $media, $sd] = $this->climatologia[$estacion][$mes][$v];
            if ($n >= self::MIN_MUESTRA && $sd > 0) {
                $z = ($x - $media) / $sd;
                if (abs($z) >= $zLim) {
                    return ['atipico', 'estadistico', sprintf('%s%.1f desv. est. del promedio de %s en la estacion (%s)', $z > 0 ? '+' : '−', abs($z), self::MESES[$mes - 1], $f($media))];
                }
            }
        }

        return null;
    }

    /** Promedio y desviacion por estacion, mes y variable con todo el historial guardado. */
    protected function cargarClimatologia(array $estaciones): void
    {
        $faltan = array_values(array_diff($estaciones, array_keys($this->climatologia)));
        if (! $faltan) {
            return;
        }
        $cols = ['estacion_id', DB::raw('MONTH(fecha) as mes')];
        foreach (self::VARIABLES as $v) {
            $cols[] = DB::raw("COUNT($v) as n_$v");
            $cols[] = DB::raw("AVG($v) as a_$v");
            $cols[] = DB::raw("STDDEV_POP($v) as s_$v");
        }
        $filas = DB::table('clima_diario')->whereIn('estacion_id', $faltan)->groupBy('estacion_id', DB::raw('MONTH(fecha)'))->get($cols);
        foreach ($faltan as $e) {
            $this->climatologia[$e] = [];
        }
        foreach ($filas as $r) {
            foreach (self::VARIABLES as $v) {
                $this->climatologia[$r->estacion_id][(int) $r->mes][$v] = [(int) $r->{"n_$v"}, (float) $r->{"a_$v"}, (float) $r->{"s_$v"}];
            }
        }
    }

    /** Excluir / validar / dejar pendiente (decision de una persona). */
    public function accion(array $ids, string $accion, ?string $nota = null): int
    {
        $n = 0;
        foreach (DB::table('clima_calidad')->whereIn('id', $ids)->get() as $e) {
            $fecha = substr($e->fecha, 0, 10);
            $base = ['revisado_por' => Auth::id(), 'revisado_en' => now(), 'updated_at' => now(), 'automatico' => false] + ($nota !== null ? ['nota' => $nota] : []);
            if ($accion === 'excluir') {
                DB::table('clima_diario')->where('estacion_id', $e->estacion_id)->where('fecha', $fecha)->update([$e->variable => null]);
                DB::table('clima_calidad')->where('id', $e->id)->update($base + ['estado' => 'excluido']);
            } else {
                // validar o volver a pendiente: el valor original se vuelve a usar.
                DB::table('clima_diario')->where('estacion_id', $e->estacion_id)->where('fecha', $fecha)->update([$e->variable => $e->valor]);
                DB::table('clima_calidad')->where('id', $e->id)->update($base + ['estado' => $accion === 'validar' ? 'validado' : 'pendiente']);
            }
            $n++;
        }

        return $n;
    }
}
