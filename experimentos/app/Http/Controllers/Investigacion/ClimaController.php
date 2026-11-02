<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Imports\FilasImport;
use App\Models\ClimaDiario;
use App\Models\EstacionMeteo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Pestana "Clima" del detalle de ensayo: catalogo de estaciones (red ICC /
 * REDMET u otras), consulta de registros diarios por periodo e importacion
 * de los archivos que exporta el portal REDMET. Cuando una estacion no tiene
 * datos importados para el periodo, el front consulta Open-Meteo en las
 * coordenadas de la estacion (ver ensayos/detalle.vue).
 */
class ClimaController extends Controller
{
    const REDES = ['ICC REDMET', 'INSIVUMEH', 'Ingenio', 'Otra'];

    /** Variables diarias almacenadas, en el orden de exportacion. */
    const VARIABLES = ['lluvia', 'tmax', 'tmin', 'tmed', 'hr', 'viento', 'radiacion', 'eto'];

    public function estaciones(Request $request)
    {
        $lat = $request->filled('lat') ? (float) $request->query('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->query('lng') : null;

        $estaciones = EstacionMeteo::withCount('registros')
            ->withMin('registros', 'fecha')
            ->withMax('registros', 'fecha')
            ->orderBy('nombre')
            ->get()
            ->map(function ($e) use ($lat, $lng) {
                $e->distancia_km = ($lat !== null && $lng !== null) ? round($this->distanciaKm($lat, $lng, $e->lat, $e->lng), 1) : null;
                return $e;
            });

        if ($lat !== null && $lng !== null) {
            $estaciones = $estaciones->sortBy('distancia_km')->values();
        }

        return response()->json(['estaciones' => $estaciones, 'redes' => self::REDES]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $estacion = EstacionMeteo::create($this->validar($request));

        return response()->json($estacion, 201);
    }

    public function update(Request $request, EstacionMeteo $estacion)
    {
        Gate::authorize('admin_only');

        $estacion->update($this->validar($request, $estacion));

        return response()->json($estacion);
    }

    public function destroy(EstacionMeteo $estacion)
    {
        Gate::authorize('admin_only');

        $estacion->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Registros diarios de la estacion en [desde, hasta]. Si falta la ETo
     * pero hay Tmax/Tmin, se estima con Hargreaves-Samani (eto_estimada).
     */
    public function datos(Request $request, EstacionMeteo $estacion)
    {
        $data = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        $filas = ClimaDiario::where('estacion_id', $estacion->id)
            ->whereBetween('fecha', [$data['desde'], $data['hasta']])
            ->orderBy('fecha')
            ->get(array_merge(['fecha'], self::VARIABLES))
            ->map(function ($r) use ($estacion) {
                $fila = $r->toArray();
                $fila['eto_estimada'] = false;
                if ($fila['eto'] === null && $fila['tmax'] !== null && $fila['tmin'] !== null) {
                    $fila['eto'] = $this->etoHargreaves($fila['tmax'], $fila['tmin'], $fila['tmed'], $estacion->lat, Carbon::parse($fila['fecha']));
                    $fila['eto_estimada'] = true;
                }
                return $fila;
            });

        return response()->json([
            'estacion' => $estacion,
            'dias' => $filas,
        ]);
    }

    /**
     * Importa un archivo exportado de REDMET (o cualquier CSV/Excel con
     * encabezados reconocibles). Acepta datos diarios o subdiarios (p. ej.
     * cada 15 min) y los agrega a un registro por dia; reimportar el mismo
     * periodo reemplaza los valores.
     */
    public function importar(Request $request, EstacionMeteo $estacion)
    {
        Gate::authorize('upload_files');

        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:20480']);

        $import = new FilasImport();
        Excel::import($import, $request->file('archivo'));
        $filas = $import->filas ?? collect();

        if ($filas->isEmpty()) {
            return response()->json(['message' => 'El archivo no tiene filas de datos.'], 422);
        }

        $columnas = $this->detectarColumnas(array_keys($filas->first()->toArray()));
        if (! isset($columnas['fecha'])) {
            return response()->json([
                'message' => 'No se encontro la columna de fecha. Encabezados leidos: '.implode(', ', array_keys($filas->first()->toArray())),
            ], 422);
        }
        if (count($columnas) === 1) {
            return response()->json(['message' => 'No se reconocio ninguna variable (lluvia, temperatura, humedad, viento, radiacion o ETo).'], 422);
        }

        $porDia = [];
        $omitidas = [];
        foreach ($filas as $i => $fila) {
            $fecha = $this->parsearFecha($fila[$columnas['fecha']] ?? null);
            if (! $fecha) {
                if (count($omitidas) < 20) {
                    $omitidas[] = ['fila' => $i + 2, 'motivo' => 'Fecha no reconocida: '.(string) ($fila[$columnas['fecha']] ?? '')];
                }
                continue;
            }
            $dia = $fecha->format('Y-m-d');
            foreach ($columnas as $var => $col) {
                if ($var === 'fecha') {
                    continue;
                }
                $v = $this->numero($fila[$col] ?? null);
                if ($v !== null) {
                    $porDia[$dia][$var][] = $v;
                }
            }
            $porDia[$dia] = $porDia[$dia] ?? [];
        }

        if (! $porDia) {
            return response()->json(['message' => 'No se pudo leer ninguna fecha valida.', 'omitidas' => $omitidas], 422);
        }

        $ahora = now();
        $registros = [];
        foreach ($porDia as $dia => $vals) {
            $registros[] = array_merge(
                ['estacion_id' => $estacion->id, 'fecha' => $dia, 'created_at' => $ahora, 'updated_at' => $ahora],
                $this->agregarDia($vals, $columnas)
            );
        }

        foreach (array_chunk($registros, 500) as $lote) {
            ClimaDiario::upsert($lote, ['estacion_id', 'fecha'], array_merge(self::VARIABLES, ['updated_at']));
        }

        $dias = array_keys($porDia);
        sort($dias);

        return response()->json([
            'dias' => count($dias),
            'desde' => $dias[0],
            'hasta' => end($dias),
            'filas_leidas' => $filas->count(),
            'columnas' => $columnas,
            'omitidas' => $omitidas,
        ]);
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function validar(Request $request, ?EstacionMeteo $estacion = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', Rule::unique('estaciones_meteo', 'codigo')->ignore($estacion)],
            'nombre' => 'required|string|max:150',
            'red' => ['required', Rule::in(self::REDES)],
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'altitud' => 'nullable|integer|min:0|max:6000',
            'ubicacion' => 'nullable|string|max:150',
            'activa' => 'boolean',
        ], [
            'codigo.unique' => 'Ya existe una estacion con ese codigo.',
        ], [
            'codigo' => 'codigo', 'nombre' => 'nombre', 'lat' => 'latitud', 'lng' => 'longitud',
        ]);
    }

    /**
     * Asocia los encabezados (normalizados por WithHeadingRow: minusculas,
     * sin acentos, "_" como separador) con cada variable. Excluye direccion
     * del viento y humedad de hoja, que REDMET tambien exporta.
     */
    protected function detectarColumnas(array $claves): array
    {
        $patrones = [
            'fecha' => '/^(fecha|date|dia|timestamp|fecha_?hora|fecha_y_hora)/',
            'tmax' => '/(t(emp(eratura)?)?_?(aire_)?max|maxima)/',
            'tmin' => '/(t(emp(eratura)?)?_?(aire_)?min|minima)/',
            'tmed' => '/(t(emp(eratura)?)?_?(aire_)?(med|prom|mean)|^temp(eratura)?(_aire)?(_|$)|^t_aire)/',
            'lluvia' => '/(lluvia|precip|^pp(_|$)|rain)/',
            'hr' => '/(^(hr|rh)(_|$)|humedad_rel|^humedad(_aire)?(_|$))/',
            'viento' => '/(vel(ocidad)?_?(del_)?viento|viento_?vel|^viento(_|$)|wind_?speed|^vv(_|$))/',
            'radiacion' => '/(radiacion|rad_?solar|^rad(_|$)|solar)/',
            'eto' => '/(^eto|^et0|evapotransp)/',
        ];
        $excluir = '/(dir|hoja|leaf|suelo|soil)/';

        $columnas = [];
        foreach ($patrones as $var => $re) {
            foreach ($claves as $c) {
                if (in_array($c, $columnas, true) || ($var !== 'fecha' && preg_match($excluir, $c))) {
                    continue;
                }
                if (preg_match($re, (string) $c)) {
                    $columnas[$var] = $c;
                    break;
                }
            }
        }

        return $columnas;
    }

    protected function agregarDia(array $vals, array $columnas): array
    {
        $prom = fn ($a) => $a ? array_sum($a) / count($a) : null;
        $r = array_fill_keys(self::VARIABLES, null);

        if (! empty($vals['lluvia'])) {
            $r['lluvia'] = array_sum($vals['lluvia']);
        }

        // Sin columnas de max/min (datos cada 15 min): se derivan de la temperatura.
        $tmax = $vals['tmax'] ?? ($vals['tmed'] ?? []);
        $tmin = $vals['tmin'] ?? ($vals['tmed'] ?? []);
        $r['tmax'] = $tmax ? max($tmax) : null;
        $r['tmin'] = $tmin ? min($tmin) : null;
        $r['tmed'] = isset($vals['tmed']) ? $prom($vals['tmed'])
            : (($r['tmax'] !== null && $r['tmin'] !== null) ? ($r['tmax'] + $r['tmin']) / 2 : null);

        $r['hr'] = $prom($vals['hr'] ?? []);

        $viento = $prom($vals['viento'] ?? []);
        if ($viento !== null && isset($columnas['viento']) && preg_match('/m_?s(_|$)/', $columnas['viento'])) {
            $viento *= 3.6; // m/s -> km/h
        }
        $r['viento'] = $viento;

        // Radiacion diaria en MJ/m2: si viene en MJ se suma por dia; si viene
        // en W/m2 (lo usual en estaciones automaticas) se promedia y convierte.
        if (! empty($vals['radiacion'])) {
            $r['radiacion'] = preg_match('/mj/', $columnas['radiacion'])
                ? array_sum($vals['radiacion'])
                : $prom($vals['radiacion']) * 0.0864;
        }

        if (! empty($vals['eto'])) {
            $r['eto'] = array_sum($vals['eto']);
        }

        // Valores fuera de rango fisico se descartan (sensores en falla).
        $rangos = ['lluvia' => [0, 500], 'tmax' => [-10, 55], 'tmin' => [-15, 45], 'tmed' => [-10, 50],
            'hr' => [0, 100], 'viento' => [0, 200], 'radiacion' => [0, 45], 'eto' => [0, 20]];
        foreach ($rangos as $k => [$min, $max]) {
            if ($r[$k] !== null && ($r[$k] < $min || $r[$k] > $max)) {
                $r[$k] = null;
            } elseif ($r[$k] !== null) {
                $r[$k] = round($r[$k], 2);
            }
        }

        return $r;
    }

    protected function parsearFecha($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v));
        }
        $v = trim((string) $v);
        $formatos = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i', 'd-m-Y', 'Y/m/d H:i', 'Y/m/d', 'j/n/Y H:i:s', 'j/n/Y H:i', 'j/n/Y', 'd/m/y H:i', 'd/m/y'];
        foreach ($formatos as $f) {
            try {
                $c = Carbon::createFromFormat('!'.$f, $v);
                if ($c && $c->format($f) === $v) {
                    return $c;
                }
            } catch (\Throwable $e) {
                // probar el siguiente formato
            }
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function numero($v): ?float
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = str_replace([' ', ','], ['', '.'], trim((string) $v));

        return is_numeric($s) ? (float) $s : null;
    }

    protected function distanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** ETo de Hargreaves-Samani (mm/dia) con radiacion extraterrestre FAO-56. */
    protected function etoHargreaves(float $tmax, float $tmin, ?float $tmed, float $lat, Carbon $fecha): float
    {
        $j = $fecha->dayOfYear;
        $phi = deg2rad($lat);
        $dr = 1 + 0.033 * cos(2 * M_PI * $j / 365);
        $delta = 0.409 * sin(2 * M_PI * $j / 365 - 1.39);
        $ws = acos(max(-1, min(1, -tan($phi) * tan($delta))));
        $ra = (24 * 60 / M_PI) * 0.0820 * $dr * ($ws * sin($phi) * sin($delta) + cos($phi) * cos($delta) * sin($ws));
        $tm = $tmed ?? ($tmax + $tmin) / 2;

        return round(max(0, 0.0023 * ($tm + 17.8) * sqrt(max(0, $tmax - $tmin)) * $ra * 0.408), 2);
    }
}
