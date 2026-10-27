<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MuestraLab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Recibe del modulo Laboratorio el avance y los resultados de muestras de SIGEC.
 * El Laboratorio decide que envia; SIGEC solo aplica lo recibido a sus propias
 * muestras (nunca accede a la base del Laboratorio por esta via).
 * Contrato: docs/api-laboratorio-resultados.md. La firma la valida VerificarEnvioLaboratorio.
 */
class LaboratorioResultadosController extends Controller
{
    /** Estado enviado por el Laboratorio => [texto en SIGEC, estado de la muestra]. */
    const ESTADOS = [
        'RECIBIDA' => ['Recibida en laboratorio', 'En proceso'],
        'EN_ANALISIS' => ['En analisis', 'En proceso'],
        'DEVUELTO' => ['Devuelta al tecnico', 'En proceso'],
        'FIRMADO' => ['Informe firmado', 'Completado'],
        'ANULADO' => ['Informe anulado', 'En proceso'],
    ];

    public function recibir(Request $request)
    {
        $v = Validator::make($request->json()->all(), [
            'informe' => 'required|array',
            'informe.codigo' => 'required|string|max:60',
            'informe.version' => 'required|integer|min:1|max:100000',
            'informe.estado' => 'required|in:' . implode(',', array_keys(self::ESTADOS)),
            'informe.firmado_por' => 'nullable|string|max:150',
            'informe.fecha_firma' => 'nullable|date',
            'muestras' => 'required|array|min:1|max:1000',
            'muestras.*.id_sigec' => 'required|string|max:80',
            'muestras.*.codigo_lab' => 'nullable|string|max:60',
            'muestras.*.id_muestra_lab' => 'nullable|integer|min:1',
            'muestras.*.resultados' => 'nullable|array|max:200',
            'muestras.*.resultados.*.clave' => 'required|string|max:120',
            'muestras.*.resultados.*.nombre' => 'required|string|max:150',
            'muestras.*.resultados.*.unidad' => 'nullable|string|max:60',
            'muestras.*.resultados.*.valor' => 'required',
        ]);
        if ($v->fails()) {
            return $this->responder($request, 422, ['ok' => false, 'error' => 'Formato no valido.', 'detalle' => $v->errors()], null);
        }

        $inf = $request->json('informe');
        $firmado = $inf['estado'] === 'FIRMADO';
        if ($firmado && empty($inf['fecha_firma'])) {
            return $this->responder($request, 422, ['ok' => false, 'error' => 'Un informe FIRMADO debe incluir fecha_firma.'], $inf);
        }
        [$textoLab, $estadoSigec] = self::ESTADOS[$inf['estado']];

        $ids = collect($request->json('muestras'))->pluck('id_sigec')->map(fn ($id) => mb_strtoupper(trim($id)));
        $sigec = MuestraLab::whereIn('id_muestra', $ids)->get()->keyBy(fn ($m) => mb_strtoupper($m->id_muestra));

        $aplicadas = 0;
        $rechazadas = [];
        DB::transaction(function () use ($request, $inf, $firmado, $textoLab, $estadoSigec, $sigec, &$aplicadas, &$rechazadas) {
            foreach ($request->json('muestras') as $i => $mu) {
                $id = mb_strtoupper(trim($mu['id_sigec']));
                $m = $sigec->get($id);
                if (! $m) {
                    $rechazadas[] = ['id_sigec' => $mu['id_sigec'], 'motivo' => 'El ID no existe en SIGEC.'];
                    continue;
                }
                // Una version anterior del mismo informe no reemplaza una correccion ya recibida.
                if ($m->lab_informe === $inf['codigo'] && (int) $m->lab_informe_version > (int) $inf['version']) {
                    $rechazadas[] = ['id_sigec' => $mu['id_sigec'], 'motivo' => "Version {$inf['version']} anterior a la ya registrada ({$m->lab_informe_version})."];
                    continue;
                }
                if ($firmado && empty($mu['resultados'])) {
                    $rechazadas[] = ['id_sigec' => $mu['id_sigec'], 'motivo' => 'Informe firmado sin resultados para esta muestra.'];
                    continue;
                }

                $lista = [];
                $analitos = [];
                foreach ($firmado ? $mu['resultados'] : [] as $r) {
                    $valor = is_scalar($r['valor']) ? trim((string) $r['valor']) : '';
                    if ($valor === '') {
                        continue;
                    }
                    $lista[] = ['clave' => $r['clave'], 'nombre' => $r['nombre'], 'unidad' => (string) ($r['unidad'] ?? ''), 'valor' => mb_substr($valor, 0, 60)];
                    $analitos[$r['clave']] = mb_substr($valor, 0, 60);
                }

                $m->fill([
                    'lab_vinculo' => 'api',
                    'lab_muestra_id' => $mu['id_muestra_lab'] ?? $m->lab_muestra_id,
                    'lab_codigo' => $mu['codigo_lab'] ?? $m->lab_codigo,
                    'lab_estado' => $textoLab,
                    'lab_informe' => $inf['codigo'],
                    'lab_informe_version' => (int) $inf['version'],
                    'lab_firmado_por' => $firmado ? ($inf['firmado_por'] ?? null) : null,
                    'lab_firmado_en' => $firmado ? $inf['fecha_firma'] : null,
                    'resultados_lab' => $firmado ? ($lista ?: null) : null,
                    'analitos' => $firmado ? ($analitos ?: null) : null,
                    'fecha_resultado' => $firmado ? substr((string) $inf['fecha_firma'], 0, 10) : null,
                    'analistas' => $firmado ? ($inf['firmado_por'] ?? $m->analistas) : $m->analistas,
                    'estado' => $estadoSigec,
                    'lab_sincronizado_en' => now(),
                ])->save();
                $aplicadas++;
            }
        });

        return $this->responder($request, 200, [
            'ok' => true,
            'informe' => $inf['codigo'],
            'version' => (int) $inf['version'],
            'recibidas' => count($request->json('muestras')),
            'aplicadas' => $aplicadas,
            'rechazadas' => $rechazadas,
        ], $inf, $aplicadas, $rechazadas);
    }

    protected function responder(Request $request, int $http, array $cuerpo, ?array $inf, int $aplicadas = 0, array $rechazadas = [])
    {
        try {
            DB::table('lab_envios')->insert([
                'ip' => $request->ip(),
                'informe' => $inf['codigo'] ?? null,
                'version' => $inf['version'] ?? null,
                'estado' => $inf['estado'] ?? null,
                'respuesta_http' => $http,
                'muestras' => is_array($request->json('muestras')) ? count($request->json('muestras')) : 0,
                'aplicadas' => $aplicadas,
                'rechazadas' => $rechazadas ? json_encode($rechazadas, JSON_UNESCAPED_UNICODE) : null,
                'error' => $http >= 400 ? mb_substr((string) ($cuerpo['error'] ?? ''), 0, 255) : null,
                'recibido_en' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($cuerpo, $http);
    }
}
