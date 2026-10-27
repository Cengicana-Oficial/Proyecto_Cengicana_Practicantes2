<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Services\LaboratorioModulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Consulta y seguimiento de las muestras de SIGEC (Recibida -> En proceso ->
 * Completado). El avance y los resultados de las muestras que el modulo
 * Laboratorio ya recibio (escaneo de la etiqueta) se leen de ese modulo; las
 * demas se pueden seguir actualizando a mano.
 */
class MuestrasConsultaController extends Controller
{
    const CICLO_STEPS = ['Recibida', 'En proceso', 'Completado'];

    const ESTADOS = ['Recibida', 'Pendiente', 'En proceso', 'Completado'];

    const RELACIONES = ['proyecto:id,codigo', 'tratamiento:id,codigo', 'parcela:id,codigo', 'solicitante:id,name'];

    public function index(LaboratorioModulo $laboratorio)
    {
        abort_unless(Auth::user()->can('view_lab') || Auth::user()->can('create_muestra'), 403);

        // Trae avances y resultados del modulo Laboratorio (como maximo cada 5 minutos).
        $sync = $laboratorio->sincronizarSiCorresponde(5);

        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $muestras = MuestraLab::with(self::RELACIONES)
            ->whereIn('ensayo_id', $ensayoIds)
            ->orderByDesc('fecha')
            ->orderBy('id_muestra')
            ->get()
            ->map(fn ($m) => $this->dto($m));

        return view('component', [
            'title' => 'Consulta de Muestras',
            'component' => 'muestras-consulta-index',
            'params' => [
                'muestras' => $muestras,
                'ensayos' => Ensayo::whereIn('id', $ensayoIds)->orderBy('codigo')->get(['id', 'codigo', 'finca', 'lote', 'proyecto_id']),
                'campos' => \App\Models\CampoMuestra::orderBy('orden')->get(['tipo_muestra', 'clave', 'etiqueta', 'unidad']),
                'cicloSteps' => self::CICLO_STEPS,
                'sincronizacion' => $sync,
                'ultimoEnvio' => DB::table('lab_envios')->orderByDesc('id')->first(['recibido_en', 'informe', 'respuesta_http', 'aplicadas']),
                'puedeVerEnvios' => $user->can('admin_only'),
                'puedeActualizar' => $user->can('create_muestra'),
            ],
        ]);
    }

    /** Bitacora de envios recibidos por la API del Laboratorio (aceptados y rechazados). */
    public function enviosLaboratorio()
    {
        Gate::authorize('admin_only');

        return response()->json(
            DB::table('lab_envios')->orderByDesc('id')->limit(100)
                ->get(['id', 'recibido_en', 'ip', 'informe', 'version', 'estado', 'respuesta_http', 'muestras', 'aplicadas', 'rechazadas', 'error'])
                ->map(function ($e) {
                    $e->rechazadas = $e->rechazadas ? json_decode($e->rechazadas, true) : [];

                    return $e;
                })
        );
    }

    /** Sincronizacion manual con el modulo Laboratorio. */
    public function sincronizarLaboratorio(LaboratorioModulo $laboratorio)
    {
        abort_unless(Auth::user()->can('view_lab') || Auth::user()->can('create_muestra'), 403);

        $sync = $laboratorio->sincronizar();
        $ensayoIds = Ensayo::visiblesPara(Auth::user())->pluck('id');

        return response()->json([
            'sincronizacion' => $sync,
            'muestras' => MuestraLab::with(self::RELACIONES)->whereIn('ensayo_id', $ensayoIds)->whereNotNull('lab_muestra_id')->get()->map(fn ($m) => $this->dto($m)),
        ], $sync['ok'] ? 200 : 503);
    }

    /** Correspondencias propuestas para muestras que el Laboratorio recibio sin escanear la etiqueta. */
    public function sugerencias(LaboratorioModulo $laboratorio)
    {
        Gate::authorize('create_muestra');

        try {
            return response()->json(['solicitudes' => $laboratorio->sugerencias(Ensayo::visiblesPara(Auth::user())->pluck('id'))]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo leer el modulo Laboratorio.'], 503);
        }
    }

    /** Confirma vinculos manuales: pares [{sigec_id, lab_muestra_id}]. */
    public function vincular(Request $request, LaboratorioModulo $laboratorio)
    {
        Gate::authorize('create_muestra');

        $data = $request->validate([
            'pares' => 'required|array|min:1|max:500',
            'pares.*.sigec_id' => 'required|integer',
            'pares.*.lab_muestra_id' => 'required|integer',
        ]);
        $ids = collect($data['pares'])->pluck('sigec_id');
        $this->verificarEnsayos(MuestraLab::whereIn('id', $ids)->pluck('ensayo_id')->unique()->all());
        if ($ids->count() !== $ids->unique()->count() || collect($data['pares'])->pluck('lab_muestra_id')->duplicates()->isNotEmpty()) {
            return response()->json(['message' => 'Cada muestra solo puede vincularse una vez.'], 422);
        }

        $r = $laboratorio->vincular(collect($data['pares'])->pluck('lab_muestra_id', 'sigec_id')->all(), Auth::id());
        $r['muestras'] = MuestraLab::with(self::RELACIONES)->whereIn('id', $ids)->get()->map(fn ($m) => $this->dto($m));

        return response()->json($r);
    }

    public function desvincular(MuestraLab $muestra, LaboratorioModulo $laboratorio)
    {
        Gate::authorize('create_muestra');
        $this->verificarEnsayos([$muestra->ensayo_id]);
        abort_unless($muestra->lab_vinculo === 'manual', 422, 'Solo se pueden quitar los vinculos manuales.');

        $laboratorio->desvincular($muestra);

        return response()->json($this->dto($muestra->fresh(self::RELACIONES)));
    }

    public function update(Request $request, MuestraLab $muestra)
    {
        Gate::authorize('create_muestra');
        $this->verificarEnsayos([$muestra->ensayo_id]);
        abort_if($muestra->lab_muestra_id, 422, 'El avance de esta muestra lo registra el modulo Laboratorio.');

        $data = $request->validate([
            'estado' => 'required|in:' . implode(',', self::ESTADOS),
            'analistas' => 'nullable|string|max:150',
            'resultado_texto' => 'nullable|string|max:2000',
            'fecha_resultado' => 'nullable|date',
        ]);
        if ($data['estado'] === 'Completado' && empty($data['fecha_resultado'])) {
            $data['fecha_resultado'] = now()->toDateString();
        }

        $muestra->update($data);

        return response()->json($this->dto($muestra->fresh(self::RELACIONES)));
    }

    /** Cambio de estado de varias muestras a la vez (solo las que el Laboratorio aun no recibio). */
    public function lote(Request $request)
    {
        Gate::authorize('create_muestra');

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'integer',
            'estado' => 'required|in:' . implode(',', self::ESTADOS),
            'analistas' => 'nullable|string|max:150',
            'fecha_resultado' => 'nullable|date',
        ]);

        $muestras = MuestraLab::whereIn('id', $data['ids'])->whereNull('lab_muestra_id')->get();
        $this->verificarEnsayos($muestras->pluck('ensayo_id')->unique()->all());

        $cambios = ['estado' => $data['estado']];
        if (! empty($data['analistas'])) {
            $cambios['analistas'] = $data['analistas'];
        }
        if ($data['estado'] === 'Completado') {
            $cambios['fecha_resultado'] = $data['fecha_resultado'] ?? now()->toDateString();
        }

        DB::transaction(fn () => MuestraLab::whereIn('id', $muestras->pluck('id'))->update($cambios));

        return response()->json(
            MuestraLab::with(self::RELACIONES)->whereIn('id', $muestras->pluck('id'))->get()->map(fn ($m) => $this->dto($m))
        );
    }

    protected function verificarEnsayos(array $ids): void
    {
        $visibles = Ensayo::visiblesPara(Auth::user())->pluck('id')->flip();
        foreach ($ids as $id) {
            abort_unless(isset($visibles[$id]), 403);
        }
    }

    protected function dto(MuestraLab $m): array
    {
        $analitos = collect($m->analitos ?: [])->filter(fn ($v) => $v !== null && $v !== '');

        return [
            'id' => $m->id,
            'id_muestra' => $m->id_muestra,
            'tipo' => $m->tipo,
            'fecha' => $m->fecha ? $m->fecha->format('Y-m-d') : null,
            'ensayo_id' => $m->ensayo_id,
            'proyecto' => optional($m->proyecto)->codigo,
            'parcela' => optional($m->parcela)->codigo,
            'tratamiento' => optional($m->tratamiento)->codigo,
            'repeticion' => $m->repeticion,
            'estado' => $m->estado,
            'analistas' => $m->analistas,
            'resultado_texto' => $m->resultado_texto,
            'fecha_resultado' => $m->fecha_resultado ? $m->fecha_resultado->format('Y-m-d') : null,
            // Resultados del informe firmado del Laboratorio (nombre, unidad y valor).
            'resultados' => $m->resultados_lab ?: $analitos->map(fn ($v, $k) => ['clave' => $k, 'nombre' => $k, 'unidad' => '', 'valor' => $v])->values()->all(),
            'n_analitos' => $m->resultados_lab ? count($m->resultados_lab) : $analitos->count(),
            'obs' => $m->obs,
            'datos' => (object) ($m->datos ?: []),
            'solicitante' => optional($m->solicitante)->name,
            'creado' => $m->created_at ? $m->created_at->format('Y-m-d H:i') : null,
            'lab' => $m->lab_muestra_id ? [
                'codigo' => $m->lab_codigo,
                'vinculo' => $m->lab_vinculo,
                'estado' => $m->lab_estado,
                'informe' => $m->lab_informe,
                'version' => $m->lab_informe_version,
                'firmado_por' => $m->lab_firmado_por,
                'firmado_en' => $m->lab_firmado_en ? $m->lab_firmado_en->format('Y-m-d H:i') : null,
                'sincronizado_en' => $m->lab_sincronizado_en ? $m->lab_sincronizado_en->format('Y-m-d H:i') : null,
            ] : null,
        ];
    }
}
