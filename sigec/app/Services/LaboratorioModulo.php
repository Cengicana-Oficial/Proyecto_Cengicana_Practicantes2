<?php

namespace App\Services;

use App\Models\MuestraLab;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conexion de SOLO LECTURA con el modulo Laboratorio del portal (base laboratorios_prueba).
 *
 * Vinculo: cuando el Laboratorio escanea la etiqueta QR de SIGEC, el ID queda en
 * muestra_identificacion.lectura_codigo (o en las etiquetas del lote). Con ese ID se
 * enlaza la muestra de SIGEC y se copia su avance; los resultados solo se toman del
 * informe FIRMADO (lab_informe.resultados_json), que es el resultado oficial.
 * SIGEC nunca escribe en la base del Laboratorio.
 */
class LaboratorioModulo
{
    const CONEXION = 'laboratorio';

    const CACHE_SYNC = 'sigec.laboratorio.ultima_sync';

    /** Estado del informe del lote en el Laboratorio => [texto para SIGEC, estado de la muestra en SIGEC]. */
    const ESTADOS = [
        'FIRMADO' => ['Informe firmado', 'Completado'],
        'LISTO' => ['Lista para aprobacion', 'En proceso'],
        'DEVUELTO' => ['Devuelta al tecnico', 'En proceso'],
        'PREP' => ['En analisis', 'En proceso'],
    ];

    protected function db()
    {
        return DB::connection(self::CONEXION);
    }

    /** Ultima sincronizacion (resumen guardado en cache). */
    public static function ultima(): ?array
    {
        try {
            return Cache::get(self::CACHE_SYNC);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Guarda el resumen; si la cache no se puede escribir, la pagina sigue funcionando. */
    protected function recordar(array $resumen): void
    {
        try {
            Cache::forever(self::CACHE_SYNC, $resumen);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Sincroniza solo si la ultima fue hace mas de $minutos (para abrir paginas sin esperar). */
    public function sincronizarSiCorresponde(int $minutos = 5): ?array
    {
        $u = self::ultima();
        if ($u && isset($u['en']) && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($u['en'])) < $minutos) {
            return $u;
        }

        return $this->sincronizar();
    }

    /**
     * Lee del Laboratorio las muestras con ID de SIGEC y actualiza las de SIGEC.
     * Devuelve un resumen; ante cualquier error de conexion devuelve ok=false sin lanzar.
     */
    public function sincronizar(): array
    {
        $resumen = ['ok' => true, 'en' => now()->toDateTimeString(), 'vinculadas' => 0, 'con_resultado' => 0, 'actualizadas' => 0, 'sin_coincidencia' => 0, 'error' => null];

        try {
            $sigec = MuestraLab::where('id_muestra', 'like', 'SIGEC-%')->get()->keyBy(fn ($m) => mb_strtoupper(trim($m->id_muestra)));
            if ($sigec->isEmpty()) {
                $this->recordar($resumen);

                return $resumen;
            }

            $codigos = $this->codigosLeidos(); // id_muestra del laboratorio => ID de SIGEC (escaneo)
            $escaneados = $codigos;
            // Vinculos confirmados a mano en SIGEC (sin escaneo); el escaneo tiene prioridad.
            MuestraLab::where('lab_vinculo', 'manual')->whereNotNull('lab_muestra_id')->get(['id_muestra', 'lab_muestra_id'])
                ->each(function ($mm) use (&$codigos) {
                    $codigos[(int) $mm->lab_muestra_id] = $codigos[(int) $mm->lab_muestra_id] ?? mb_strtoupper(trim($mm->id_muestra));
                });
            $filas = $codigos ? $this->detalleMuestras(array_keys($codigos)) : [];
            $formatos = config('laboratorio.formatos', []);
            $equiv = config('laboratorio.equivalencias', []);
            $unidadesLab = $codigos ? $this->unidadesPorAnalisis() : [];

            foreach ($codigos as $idLab => $codigoSigec) {
                $m = $sigec->get($codigoSigec);
                if (! $m) {
                    $resumen['sin_coincidencia']++;
                    continue;
                }
                // Lo que el Laboratorio envio por la API tiene prioridad sobre la lectura directa.
                if ($m->lab_vinculo === 'api') {
                    continue;
                }
                $f = $filas[$idLab] ?? null;
                if (! $f) {
                    continue;
                }
                $resumen['vinculadas']++;

                $estadoInf = $f->inf_estado ?: 'PREP';
                [$textoLab, $estadoSigec] = self::ESTADOS[$estadoInf] ?? ['En analisis', 'En proceso'];
                $cambios = [
                    'lab_muestra_id' => (int) $idLab,
                    'lab_vinculo' => isset($escaneados[$idLab]) ? 'escaneo' : 'manual',
                    'lab_codigo' => $f->codigo_lab,
                    'lab_estado' => $textoLab,
                    'lab_informe' => $f->inf_codigo,
                    'lab_firmado_por' => null,
                    'lab_firmado_en' => null,
                    'estado' => $estadoSigec,
                    'lab_sincronizado_en' => now(),
                ];

                if ($estadoInf === 'FIRMADO') {
                    $valores = (json_decode((string) $f->resultados_json, true) ?: [])[$idLab] ?? [];
                    $formato = $formatos[$equiv[$m->tipo] ?? ''] ?? [];
                    $lista = [];
                    $analitos = [];
                    foreach ($valores as $clave => $valor) {
                        if ($valor === null || $valor === '') {
                            continue;
                        }
                        [$analisis, $campo] = array_pad(explode('|', $clave, 2), 2, '');
                        // Nombre y unidad: copia del formato de informe; si el Laboratorio agrego un
                        // analisis nuevo, se toman de su catalogo de analisis (tipo_analisis).
                        $col = $formato[$clave] ?? null;
                        $lista[] = [
                            'clave' => $clave,
                            'nombre' => $col['h'] ?? ($campo && $campo !== $analisis && ! preg_match('/^(res|r_|valor)/', $campo) ? "{$analisis} ({$campo})" : $analisis),
                            'unidad' => $col['u'] ?? ($unidadesLab[$this->normal($analisis)] ?? ''),
                            'valor' => (string) $valor,
                        ];
                        $analitos[$clave] = (string) $valor;
                    }
                    $cambios = array_merge($cambios, [
                        'resultados_lab' => $lista ?: null,
                        'analitos' => $analitos ?: null,
                        'lab_firmado_por' => $f->firmado_por,
                        'lab_firmado_en' => $f->fecha_firma,
                        'fecha_resultado' => $f->fecha_firma ? substr($f->fecha_firma, 0, 10) : $m->fecha_resultado,
                        'analistas' => $f->firmado_por ?: $m->analistas,
                    ]);
                    if ($lista) {
                        $resumen['con_resultado']++;
                    }
                }

                $m->fill($cambios);
                if ($m->isDirty(array_diff(array_keys($cambios), ['lab_sincronizado_en']))) {
                    $resumen['actualizadas']++;
                }
                $m->save();
            }
        } catch (Throwable $e) {
            report($e);
            $resumen['ok'] = false;
            $resumen['error'] = 'No se pudo leer el modulo Laboratorio: ' . $e->getMessage();
        }

        $this->recordar($resumen);

        return $resumen;
    }

    /* ======================= Vinculo sugerido (sin escaneo) ======================= */

    /** Tipo de muestra del Laboratorio => tipo de SIGEC. */
    const TIPOS_LAB = ['suelos' => 'Suelo', 'agua' => 'Agua', 'aguas' => 'Agua', 'foliares' => 'Tejido vegetal', 'canas' => 'Jugos', 'mieles' => 'Mieles'];

    /**
     * Propone a que muestras de SIGEC corresponden las muestras del Laboratorio que no
     * se escanearon. Se usan el "Nombre del ensayo" de Recepcion (contra el codigo,
     * lote o finca del ensayo en SIGEC), el tipo de muestra, la fecha de muestreo
     * (hasta 7 dias de diferencia) y la parcela de la identificacion.
     * Devuelve solicitudes del Laboratorio con sus muestras y la pareja propuesta.
     */
    public function sugerencias($ensayosVisibles): array
    {
        $ensayos = \App\Models\Ensayo::whereIn('id', $ensayosVisibles)->get(['id', 'codigo', 'lote', 'finca']);
        $porTexto = [];
        foreach ($ensayos as $e) {
            foreach ([$e->codigo, $e->lote, "SIGEC-{$e->codigo}"] as $t) {
                if ($t !== null && trim($t) !== '') {
                    $porTexto[$this->normal($t)] = $e;
                }
            }
        }
        if (! $porTexto) {
            return [];
        }

        $vinculados = MuestraLab::whereNotNull('lab_muestra_id')->pluck('lab_muestra_id')->flip();
        $escaneados = $this->codigosLeidos();
        $out = [];

        $solicitudes = $this->db()->table('solicitud as s')
            ->leftJoin('tipo_muestra as t', 't.id_tipo', '=', 's.id_tipo')
            ->whereNotNull('s.codigo_muestreo')->where('s.codigo_muestreo', '<>', '')
            ->orderByDesc('s.fecha_muestreo')
            ->limit(300)
            ->get(['s.id_solicitud', 's.codigo_muestreo', 's.fecha_muestreo', 's.institucion', 't.nombre as tipo_lab']);

        foreach ($solicitudes as $s) {
            $ensayo = $porTexto[$this->normal($s->codigo_muestreo)] ?? null;
            $tipo = self::TIPOS_LAB[$this->normal($s->tipo_lab, false)] ?? null;
            if (! $ensayo || ! $tipo) {
                continue;
            }
            $muestrasLab = $this->db()->table('muestra as m')
                ->leftJoin('muestra_identificacion as mi', 'mi.id_muestra', '=', 'm.id_muestra')
                ->where('m.id_solicitud', $s->id_solicitud)
                ->orderBy('m.numero_muestra')
                ->get(['m.id_muestra', 'm.numero_muestra', 'm.codigo_lab', 'mi.parcela', 'mi.tratamiento', 'mi.repeticion'])
                ->reject(fn ($m) => isset($vinculados[$m->id_muestra]) || isset($escaneados[$m->id_muestra]))
                ->values();
            if ($muestrasLab->isEmpty()) {
                continue;
            }

            // Candidatas en SIGEC: mismo ensayo y tipo, sin vincular, muestreo cercano.
            $fecha = $s->fecha_muestreo ? \Illuminate\Support\Carbon::parse($s->fecha_muestreo) : null;
            $candidatas = MuestraLab::with('parcela:id,codigo')
                ->where('ensayo_id', $ensayo->id)->where('tipo', $tipo)->whereNull('lab_muestra_id')
                ->when($fecha, fn ($q) => $q->whereBetween('fecha', [$fecha->copy()->subDays(7)->toDateString(), $fecha->copy()->addDays(7)->toDateString()]))
                ->orderBy('id_muestra')
                ->get(['id', 'id_muestra', 'fecha', 'parcela_id', 'tratamiento_id', 'repeticion']);
            if ($candidatas->isEmpty()) {
                continue;
            }

            $usadas = [];
            $pares = [];
            foreach ($muestrasLab as $ml) {
                $propuesta = null;
                $motivo = null;
                if ($ml->parcela !== null && trim($ml->parcela) !== '') {
                    $propuesta = $candidatas->first(fn ($c) => ! isset($usadas[$c->id]) && $c->parcela && $this->mismaParcela($c->parcela->codigo, $ml->parcela));
                    $motivo = $propuesta ? 'misma parcela' : null;
                }
                $pares[] = [
                    'lab_muestra_id' => (int) $ml->id_muestra,
                    'codigo_lab' => $ml->codigo_lab,
                    'parcela_lab' => $ml->parcela,
                    'numero' => $ml->numero_muestra,
                    'sigec_id' => $propuesta ? $propuesta->id : null,
                    'motivo' => $motivo,
                ];
                if ($propuesta) {
                    $usadas[$propuesta->id] = true;
                }
            }
            // Si el Laboratorio no registro parcelas y las cantidades coinciden, se propone el mismo orden.
            if (! collect($pares)->contains(fn ($p) => $p['sigec_id']) && count($pares) === $candidatas->count()) {
                foreach ($pares as $i => &$p) {
                    $p['sigec_id'] = $candidatas[$i]->id;
                    $p['motivo'] = 'mismo orden (revisar)';
                }
                unset($p);
            }

            $out[] = [
                'id_solicitud' => (int) $s->id_solicitud,
                'nombre_ensayo' => $s->codigo_muestreo,
                'fecha_muestreo' => $s->fecha_muestreo,
                'institucion' => $s->institucion,
                'tipo' => $tipo,
                'ensayo_id' => $ensayo->id,
                'ensayo' => $ensayo->codigo,
                'pares' => $pares,
                'candidatas' => $candidatas->map(fn ($c) => ['id' => $c->id, 'id_muestra' => $c->id_muestra, 'fecha' => $c->fecha->format('Y-m-d'), 'parcela' => optional($c->parcela)->codigo])->values(),
            ];
        }

        return $out;
    }

    /** Guarda en SIGEC los vinculos confirmados por el usuario y sincroniza. [sigec_id => lab_muestra_id] */
    public function vincular(array $pares, int $usuarioId): array
    {
        $existentes = $this->db()->table('muestra')->whereIn('id_muestra', array_values($pares))->pluck('id_muestra')->flip();
        $ocupados = MuestraLab::whereIn('lab_muestra_id', array_values($pares))->pluck('id', 'lab_muestra_id');
        $hechos = 0;
        foreach ($pares as $sigecId => $labId) {
            if (! isset($existentes[$labId]) || (isset($ocupados[$labId]) && (int) $ocupados[$labId] !== (int) $sigecId)) {
                continue;
            }
            $hechos += MuestraLab::whereKey($sigecId)->whereNull('lab_muestra_id')
                ->update(['lab_muestra_id' => $labId, 'lab_vinculo' => 'manual', 'lab_vinculado_por' => $usuarioId]);
        }

        return ['vinculadas' => $hechos, 'sincronizacion' => $this->sincronizar()];
    }

    /** Quita un vinculo manual (los de escaneo vuelven a enlazarse solos). */
    public function desvincular(MuestraLab $m): void
    {
        $m->update([
            'lab_muestra_id' => null, 'lab_vinculo' => null, 'lab_vinculado_por' => null, 'lab_codigo' => null, 'lab_estado' => null,
            'lab_informe' => null, 'lab_firmado_por' => null, 'lab_firmado_en' => null, 'resultados_lab' => null, 'lab_sincronizado_en' => null,
            'estado' => 'Recibida',
        ]);
    }

    /** Texto comparable: sin tildes, mayusculas y sin espacios ni signos (o solo minusculas sin tildes). */
    protected function normal(?string $s, bool $compacto = true): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return $compacto ? preg_replace('/[^a-z0-9]/', '', $s) : $s;
    }

    /** "P-01" en SIGEC y "1", "01", "P1" o "p-01" en el Laboratorio son la misma parcela. */
    protected function mismaParcela(string $sigec, string $lab): bool
    {
        if ($this->normal($sigec) === $this->normal($lab)) {
            return true;
        }
        preg_match('/\d+/', $sigec, $a);
        preg_match('/\d+/', $lab, $b);

        return $a && $b && (int) $a[0] === (int) $b[0] && preg_replace('/\d+/', '', $this->normal($lab)) === '';
    }

    /** Unidad por nombre de analisis del catalogo del Laboratorio (tipo_analisis). */
    protected function unidadesPorAnalisis(): array
    {
        try {
            return $this->db()->table('tipo_analisis')->whereNotNull('unidad')->where('unidad', '<>', '')
                ->get(['nombre', 'unidad'])
                ->mapWithKeys(fn ($r) => [$this->normal($r->nombre) => $r->unidad])->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Codigos de SIGEC leidos por el Laboratorio: [id_muestra del laboratorio => ID SIGEC].
     * Se buscan en la lectura de la etiqueta y, como respaldo, en las etiquetas del lote.
     */
    protected function codigosLeidos(): array
    {
        $out = [];
        $tablas = $this->tablasExistentes(['muestra_identificacion', 'lab_identificacion_lote']);

        if (isset($tablas['muestra_identificacion'])) {
            $this->db()->table('muestra_identificacion')
                ->where('lectura_codigo', 'like', '%SIGEC-%')
                ->get(['id_muestra', 'lectura_codigo'])
                ->each(function ($r) use (&$out) {
                    if (preg_match('/SIGEC-[A-Z0-9-]+-\d{2}/i', (string) $r->lectura_codigo, $mm)) {
                        $out[(int) $r->id_muestra] = mb_strtoupper($mm[0]);
                    }
                });
        }

        if (isset($tablas['lab_identificacion_lote'])) {
            $this->db()->table('lab_identificacion_lote')
                ->where('etiquetas_json', 'like', '%SIGEC-%')
                ->pluck('etiquetas_json')
                ->each(function ($json) use (&$out) {
                    foreach ((array) json_decode((string) $json, true) as $idLab => $etq) {
                        if (! is_numeric($idLab) || isset($out[(int) $idLab])) {
                            continue;
                        }
                        if (preg_match('/SIGEC-[A-Z0-9-]+-\d{2}/i', json_encode($etq, JSON_UNESCAPED_UNICODE), $mm)) {
                            $out[(int) $idLab] = mb_strtoupper($mm[0]);
                        }
                    }
                });
        }

        return $out;
    }

    /** Muestra del laboratorio con su solicitud, tipo e informe del lote. */
    protected function detalleMuestras(array $ids): array
    {
        $tablas = $this->tablasExistentes(['lab_informe']);
        $q = $this->db()->table('muestra as m')
            ->join('solicitud as s', 's.id_solicitud', '=', 'm.id_solicitud')
            ->leftJoin('tipo_muestra as t', 't.id_tipo', '=', 's.id_tipo')
            ->whereIn('m.id_muestra', $ids)
            ->select('m.id_muestra', 'm.codigo_lab', 's.id_lote', 's.fecha_ingreso', 't.nombre as tipo_lab');
        if (isset($tablas['lab_informe'])) {
            $q->leftJoin('lab_informe as li', 'li.id_lote', '=', 's.id_lote')
                ->addSelect('li.estado as inf_estado', 'li.codigo as inf_codigo', 'li.firmado_por', 'li.fecha_firma', 'li.resultados_json');
        } else {
            $q->addSelect(DB::raw('NULL as inf_estado, NULL as inf_codigo, NULL as firmado_por, NULL as fecha_firma, NULL as resultados_json'));
        }

        return $q->get()->keyBy('id_muestra')->all();
    }

    /** Las tablas del Laboratorio se crean al usarse; se verifica que existan antes de leerlas. */
    protected function tablasExistentes(array $nombres): array
    {
        $base = $this->db()->getDatabaseName();

        return DB::connection(self::CONEXION)->table('information_schema.tables')
            ->where('table_schema', $base)->whereIn('table_name', $nombres)
            ->selectRaw('table_name as nombre')
            ->pluck('nombre')->flip()->all();
    }
}
