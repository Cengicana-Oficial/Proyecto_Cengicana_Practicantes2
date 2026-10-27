<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampoMuestra;
use App\Models\MuestraLab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * El modulo Laboratorio consulta los datos de una muestra de SIGEC a partir del ID
 * leido en la etiqueta QR, para guardarlos en su propia recepcion. Solo lectura:
 * la consulta no cambia nada en SIGEC. Misma firma HMAC que el envio de resultados
 * (VerificarEnvioLaboratorio). Contrato: docs/api-laboratorio-resultados.md.
 */
class LaboratorioMuestrasController extends Controller
{
    const MAX_IDS = 200;

    /** Tipo de muestra de SIGEC => tipo_muestra.nombre del Laboratorio. */
    const TIPOS_LAB = ['Suelo' => 'suelos', 'Agua' => 'agua', 'Tejido vegetal' => 'foliares', 'Jugos' => 'cañas', 'Mieles' => 'mieles'];

    public function consultar(Request $request)
    {
        $v = Validator::make($request->json()->all(), [
            'ids' => 'required|array|min:1|max:' . self::MAX_IDS,
            'ids.*' => 'required|string|max:80',
        ]);
        if ($v->fails()) {
            return $this->responder($request, 422, ['ok' => false, 'error' => 'Formato no valido.', 'detalle' => $v->errors()], 0);
        }

        $ids = collect($request->json('ids'))->map(fn ($id) => mb_strtoupper(trim($id)))->filter()->unique()->values();
        $muestras = MuestraLab::with(['ensayo.proyecto', 'ensayo.ingenio', 'tratamiento', 'parcela'])
            ->whereIn('id_muestra', $ids)->get()->keyBy(fn ($m) => mb_strtoupper($m->id_muestra));
        $campos = CampoMuestra::where('activo', true)->orderBy('orden')->get()->groupBy('tipo_muestra');

        $encontradas = $ids->filter(fn ($id) => $muestras->has($id))
            ->map(fn ($id) => $this->datos($muestras[$id], $campos->get($muestras[$id]->tipo, collect())))->values();

        return $this->responder($request, 200, [
            'ok' => true,
            'solicitadas' => $ids->count(),
            'encontradas' => $encontradas->count(),
            'muestras' => $encontradas,
            'no_encontradas' => $ids->reject(fn ($id) => $muestras->has($id))->values(),
        ], $encontradas->count());
    }

    protected function datos(MuestraLab $m, $campos): array
    {
        $e = $m->ensayo;
        $trat = $m->tratamiento;
        $parcela = $m->parcela->codigo ?? null;
        $repeticion = $m->repeticion ?? ($m->parcela->repeticion ?? null);
        $tratTexto = $trat ? trim($trat->codigo . ($trat->descripcion ? ' - ' . $trat->descripcion : '')) : null;

        $datos = (array) ($m->datos ?? []);
        $personalizados = $campos->map(fn ($c) => [
            'clave' => $c->clave,
            'etiqueta' => $c->etiqueta,
            'valor' => isset($datos[$c->clave]) && $datos[$c->clave] !== '' ? (string) $datos[$c->clave] : null,
            'unidad' => $c->unidad,
        ])->values();

        // Claves de tipo_muestra_campo del Laboratorio (fecha, finca, loteCampo, ensayo...).
        // Los campos personalizados de SIGEC con la misma clave (profundidad, pante...) tambien entran.
        $camposLab = array_filter([
            'fecha' => optional($m->fecha)->format('Y-m-d'),
            'ensayo' => $e->codigo ?? null,
            'finca' => $m->finca ?? ($e->finca ?? null),
            'loteCampo' => $m->lote ?? ($e->lote ?? null),
            'parcela' => $parcela,
            'tratamiento' => $tratTexto,
            'repeticion' => $repeticion !== null ? (string) $repeticion : null,
            'variedad' => $datos['variedad'] ?? ($e->variedad ?? null),
            'estrato' => $e->estrato ?? null,
            'obs' => $m->obs,
        ], fn ($x) => $x !== null && $x !== '');
        foreach ($personalizados as $c) {
            if ($c['valor'] !== null && ! isset($camposLab[$c['clave']])) {
                $camposLab[$c['clave']] = $c['valor'];
            }
        }

        return [
            'id_sigec' => $m->id_muestra,
            'tipo' => $m->tipo,
            'tipo_lab' => self::TIPOS_LAB[$m->tipo] ?? null,
            'fecha_muestreo' => optional($m->fecha)->format('Y-m-d'),
            // Lo que el Laboratorio escribe en "Nombre del ensayo" de la recepcion.
            'nombre_ensayo' => $e->codigo ?? null,
            'ensayo' => $e ? ['codigo' => $e->codigo, 'nombre' => $e->nombre, 'diseno' => $e->diseno, 'cultivo' => $e->cultivo, 'variedad' => $e->variedad] : null,
            'proyecto' => $e && $e->proyecto ? ['codigo' => $e->proyecto->codigo, 'nombre' => $e->proyecto->nombre] : null,
            'institucion' => $e->ingenio->nombre ?? null,
            'finca' => $m->finca ?? ($e->finca ?? null),
            'lote' => $m->lote ?? ($e->lote ?? null),
            'parcela' => $parcela,
            'tratamiento' => $trat ? ['codigo' => $trat->codigo, 'descripcion' => $trat->descripcion] : null,
            'repeticion' => $repeticion,
            'observaciones' => $m->obs,
            'campos' => $personalizados,
            // Listo para muestra_identificacion del Laboratorio.
            'identificacion' => [
                'lectura_codigo' => $m->id_muestra,
                'parcela' => $parcela,
                'bloque' => $datos['bloque'] ?? null,
                'tratamiento' => $tratTexto,
                'repeticion' => $repeticion !== null ? (string) $repeticion : null,
                'variedad' => $camposLab['variedad'] ?? null,
                'observaciones' => $m->obs,
            ],
            'campos_lab' => $camposLab,
            'estado_sigec' => $m->estado,
        ];
    }

    /** Toda consulta queda en la bitacora lab_envios (estado CONSULTA). */
    protected function responder(Request $request, int $http, array $cuerpo, int $encontradas)
    {
        try {
            DB::table('lab_envios')->insert([
                'ip' => $request->ip(),
                'estado' => 'CONSULTA',
                'respuesta_http' => $http,
                'muestras' => is_array($request->json('ids')) ? count($request->json('ids')) : 0,
                'aplicadas' => $encontradas,
                'rechazadas' => ! empty($cuerpo['no_encontradas']) && count($cuerpo['no_encontradas'])
                    ? json_encode(collect($cuerpo['no_encontradas'])->map(fn ($id) => ['id_sigec' => $id, 'motivo' => 'El ID no existe en SIGEC.']), JSON_UNESCAPED_UNICODE) : null,
                'error' => $http >= 400 ? mb_substr((string) ($cuerpo['error'] ?? ''), 0, 255) : null,
                'recibido_en' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($cuerpo, $http, [], JSON_UNESCAPED_UNICODE);
    }
}
