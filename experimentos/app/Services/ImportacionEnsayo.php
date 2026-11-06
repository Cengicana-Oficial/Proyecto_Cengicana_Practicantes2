<?php

namespace App\Services;

use App\Models\AnaliticoPorTipo;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Variable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Prepara cambios sin escribir; vuelve a validar el mismo archivo antes de aplicarlos. */
class ImportacionEnsayo
{
    public const TIPOS = ['evaluaciones', 'laboratorio', 'parcelas'];

    public static function clave($valor): string
    {
        return mb_strtolower(trim((string) $valor));
    }

    public static function fecha($valor): string
    {
        if (is_numeric($valor) && (float) $valor > 0 && (float) $valor < 100000) {
            return Date::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        }
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $valor));
        if (! $fecha || $fecha->format('Y-m-d') !== trim((string) $valor)) {
            throw new InvalidArgumentException('Fecha inválida. Usa AAAA-MM-DD o una fecha de Excel.');
        }

        return $fecha->format('Y-m-d');
    }

    public static function manual(MuestraLab $m): bool
    {
        return ! $m->lab_muestra_id && ! $m->lab_informe && empty($m->resultados_lab);
    }

    public function procesar(Ensayo $ensayo, string $tipo, $filas, ?string $firma = null): array
    {
        return DB::transaction(function () use ($ensayo, $tipo, $filas, $firma) {
            // Serializa las cargas de esta herramienta para un mismo ensayo.
            if ($firma !== null) Ensayo::whereKey($ensayo->id)->lockForUpdate()->firstOrFail();
            $parcelas = Parcela::with('tratamiento')->where('ensayo_id', $ensayo->id)->when($firma !== null, fn ($q) => $q->lockForUpdate())->get()->keyBy(fn ($p) => self::clave($p->codigo));
            $variables = Variable::get()->keyBy(fn ($v) => self::clave($v->nombre));
            $muestras = MuestraLab::where('ensayo_id', $ensayo->id)->when($firma !== null, fn ($q) => $q->lockForUpdate())->get()->keyBy(fn ($m) => self::clave($m->id_muestra));
            $analitos = AnaliticoPorTipo::get()->keyBy(fn ($a) => $a->tipo_muestra . '|' . self::clave($a->clave));
            $r = ['total' => $filas->count(), 'creadas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'vacias' => 0, 'omitidas' => [], 'detalle' => []];
            $modelos = []; $vistos = [];
            foreach ($filas as $i => $fila) {
                // En el formato ancho cada celda llega como fila; _fila es su fila en el Excel.
                $numFila = $fila['_fila'] ?? $i + 2;
                try {
                    $f = collect($fila)->map(fn ($v) => is_string($v) ? trim($v) : $v)->all();
                    if ($tipo !== 'parcelas' && (! isset($f['valor']) || (string) $f['valor'] === '')) { $r['vacias']++; continue; }
                    if (! empty($f['ensayo']) && self::clave($f['ensayo']) !== self::clave($ensayo->codigo)) {
                        throw new InvalidArgumentException('El código de ensayo no corresponde al ensayo seleccionado.');
                    }
                    if ($tipo === 'laboratorio') {
                        $m = $muestras->get(self::clave($f['id_muestra'] ?? ''));
                        if (! $m) throw new InvalidArgumentException('ID de muestra no encontrado en este ensayo.');
                        if (! self::manual($m)) throw new InvalidArgumentException('Muestra vinculada a Laboratorio: sus resultados se actualizan desde ese módulo.');
                        $p = $m->parcela_id ? $parcelas->firstWhere('id', $m->parcela_id) : null;
                        if (! $p) throw new InvalidArgumentException('Asocia la muestra a una parcela antes de importar resultados.');
                        $this->referencia($f, $p);
                        if (! empty($f['tipo']) && self::clave($f['tipo']) !== self::clave($m->tipo)) throw new InvalidArgumentException('El tipo de muestra no coincide.');
                        if (! empty($f['fecha']) && self::fecha($f['fecha']) !== $m->fecha->format('Y-m-d')) throw new InvalidArgumentException('La fecha de muestreo no coincide con la muestra.');
                        $a = $analitos->get($m->tipo . '|' . self::clave($f['analito'] ?? ''));
                        if (! $a) throw new InvalidArgumentException('Analito no registrado para este tipo de muestra. Usa su clave del catálogo.');
                        if (! empty($f['unidad']) && self::clave($f['unidad']) !== self::clave($a->unidad)) throw new InvalidArgumentException('La unidad no coincide con el analito del catálogo.');
                        $registro = "lab:{$m->id}:{$a->clave}";
                        $valor = (string) $f['valor'];
                        if (mb_strlen($valor) > 150) throw new InvalidArgumentException('El resultado supera 150 caracteres.');
                        $cambios = ['analitos' => array_merge($m->analitos ?: [], [$a->clave => $valor])];
                        $referencia = $m->id_muestra . ' · ' . $a->label;
                        $antes = ($m->analitos ?: [])[$a->clave] ?? '';
                        $despues = $valor;
                        if (! empty($f['fecha_resultado'])) $cambios['fecha_resultado'] = self::fecha($f['fecha_resultado']);
                        $modelo = $m; $llave = 'lab:' . $m->id;
                    } else {
                        $p = $parcelas->get(self::clave($f['parcela'] ?? ''));
                        if (! $p) throw new InvalidArgumentException('Parcela no encontrada en este ensayo.');
                        $this->referencia($f, $p);
                        if ($tipo === 'evaluaciones') {
                            $v = $variables->get(self::clave($f['variable'] ?? ''));
                            if (! $v) throw new InvalidArgumentException('Variable no encontrada en el catálogo.');
                            if (! empty($f['unidad']) && self::clave($f['unidad']) !== self::clave($v->unidad)) throw new InvalidArgumentException('La unidad no coincide con la variable del catálogo.');
                            $fecha = self::fecha($f['fecha'] ?? '');
                            $registro = "ev:{$p->id}:{$v->id}:{$fecha}";
                            $existentes = Evaluacion::where('parcela_id', $p->id)->where('variable_id', $v->id)->whereDate('fecha', $fecha)->when($firma !== null, fn ($q) => $q->lockForUpdate())->get();
                            if ($existentes->count() > 1) throw new InvalidArgumentException('Ya hay varias evaluaciones para esta parcela, variable y fecha. Revísalas en Evaluaciones.');
                            $modelo = $existentes->first() ?: new Evaluacion(['parcela_id' => $p->id, 'variable_id' => $v->id, 'fecha' => $fecha]);
                            if (mb_strlen((string) $f['valor']) > 150) throw new InvalidArgumentException('Valor demasiado largo (máximo 150 caracteres).');
                            $valor = $v->normalizar((string) $f['valor']);
                            if (! $modelo->exists && ! $v->activa) throw new InvalidArgumentException('La variable está inactiva; solo se pueden corregir registros existentes.');
                            $cambios = ['valor' => $valor];
                            $referencia = $p->codigo . ' · ' . $v->nombre . ' · ' . $fecha;
                            $antes = $modelo->valor ?? ''; $despues = $valor;
                            if (! $modelo->exists || $modelo->valor !== $valor) $cambios['responsable_id'] = Auth::id();
                            $llave = "ev:{$p->id}:{$v->id}:{$fecha}";
                        } else {
                            $registro = 'parcela:' . $p->id;
                            $modelo = $p; $llave = 'parcela:' . $p->id; $cambios = [];
                            if (isset($f['area']) && (string) $f['area'] !== '') {
                                $area = str_replace(',', '.', (string) $f['area']);
                                if (! is_numeric($area) || ! is_finite((float) $area) || (float) $area <= 0 || (float) $area > 99999999.99) throw new InvalidArgumentException('Área inválida: debe ser un número positivo.');
                                $cambios['area'] = number_format((float) $area, 2, '.', '');
                            }
                            if (! empty($f['notas'])) {
                                if (mb_strlen((string) $f['notas']) > 2000) throw new InvalidArgumentException('Notas demasiado largas (máximo 2000 caracteres).');
                                $cambios['notas'] = (string) $f['notas'];
                            }
                            if (! $cambios) { $r['vacias']++; continue; }
                            $referencia = $p->codigo;
                            $antes = json_encode(['area' => $p->area, 'notas' => $p->notas], JSON_UNESCAPED_UNICODE);
                            $despues = json_encode($cambios, JSON_UNESCAPED_UNICODE);
                        }
                    }
                    if ($tipo === 'evaluaciones' && ! empty($f['observaciones'])) {
                        if (mb_strlen((string) $f['observaciones']) > 1000) throw new InvalidArgumentException('Observaciones demasiado largas (máximo 1000 caracteres).');
                        $cambios['obs'] = (string) $f['observaciones'];
                    }
                    // No mutar modelos compartidos hasta que toda la fila sea válida.
                    $this->unica($vistos, $registro);
                    $anterior = $modelo->getAttributes();
                    $modelo->fill($cambios);
                    if ($anterior === $modelo->getAttributes() && $modelo->exists) { $r['sin_cambios']++; continue; }
                    $r[$modelo->exists ? 'actualizadas' : 'creadas']++;
                    if (count($r['detalle']) < 100) $r['detalle'][] = ['fila' => $numFila, 'referencia' => $referencia, 'accion' => $modelo->exists ? 'Actualizar' : 'Crear', 'antes' => $antes, 'despues' => $despues];
                    $modelos[$llave] = $modelo;
                } catch (InvalidArgumentException $e) {
                    $r['omitidas'][] = ['fila' => $numFila, 'referencia' => trim(implode(' · ', array_filter([$fila['parcela'] ?? null, $fila['variable'] ?? ($fila['id_muestra'] ?? null)]))), 'motivo' => $e->getMessage()];
                }
            }
            $huella = [$ensayo->id, $tipo, $filas->toArray(), array_map(fn ($m) => [$m->getRawOriginal(), $m->getAttributes()], $modelos)];
            $r['firma'] = hash('sha256', json_encode($huella, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($firma !== null) {
                if (! hash_equals($r['firma'], $firma)) throw ValidationException::withMessages(['archivo' => ['Los datos cambiaron desde la revisión. Revisa el archivo otra vez antes de importar.']]);
                foreach ($modelos as $m) $m->save();
            }
            $r['aplicado'] = $firma !== null;

            return $r;
        });
    }

    private function unica(array &$vistos, string $clave): void
    {
        if (isset($vistos[$clave])) throw new InvalidArgumentException('Registro repetido dentro del archivo; se conserva la primera fila válida.');
        $vistos[$clave] = true;
    }

    private function referencia(array $f, Parcela $p): void
    {
        if (! empty($f['parcela']) && self::clave($f['parcela']) !== self::clave($p->codigo)) throw new InvalidArgumentException('La parcela no coincide con la muestra.');
        if (! empty($f['tratamiento']) && self::clave($f['tratamiento']) !== self::clave(optional($p->tratamiento)->codigo)) throw new InvalidArgumentException('El tratamiento no corresponde a esta parcela.');
        if (isset($f['repeticion']) && (string) $f['repeticion'] !== '' && (string) $f['repeticion'] !== (string) $p->repeticion) throw new InvalidArgumentException('La repetición no corresponde a esta parcela.');
    }
}
