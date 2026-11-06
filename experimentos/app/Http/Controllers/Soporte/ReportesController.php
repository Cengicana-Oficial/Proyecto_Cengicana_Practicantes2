<?php

namespace App\Http\Controllers\Soporte;

use App\Exports\BitacoraExport;
use App\Exports\EnsayosExport;
use App\Exports\EvaluacionesExport;
use App\Exports\MuestrasLabExport;
use App\Exports\ParcelasExport;
use App\Exports\NdviExport;
use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\DisenoExperimental;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\MuestraLab;
use App\Models\Programa;
use App\Models\Proyecto;
use App\Services\ResultadosLaboratorio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reportes: informe tecnico de un ensayo (imprimible / PDF, con analisis estadistico) y
 * exportaciones a Excel. Todo respeta lo que el usuario puede ver y los filtros de
 * programa, proyecto, ensayo y fechas.
 */
class ReportesController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        abort_unless($user->can('view_reports_menu'), 403);

        $ensayos = Ensayo::visiblesPara($user)
            ->with('proyecto:id,programa_id')
            ->withCount(['parcelas', 'tratamientos', 'evaluaciones', 'muestras', 'bitacora', 'ndviImagenes'])
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'proyecto_id', 'estado']);

        return view('component', [
            'title' => 'Reportes',
            'component' => 'reportes-index',
            'params' => [
                'programas' => Programa::whereIn('id', $ensayos->pluck('proyecto.programa_id')->filter()->unique())->orderBy('sigla')->get(['id', 'sigla', 'nombre', 'color']),
                'proyectos' => Proyecto::whereIn('id', $ensayos->pluck('proyecto_id')->unique())->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'programa_id']),
                'baseUrl' => route('soporte.reportes.index'),
                'ensayos' => $ensayos->map(fn ($e) => [
                    'id' => $e->id, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'estado' => $e->estado,
                    'proyecto_id' => $e->proyecto_id, 'programa_id' => optional($e->proyecto)->programa_id,
                    'evaluaciones' => $e->evaluaciones_count, 'muestras' => $e->muestras_count,
                    'bitacora' => $e->bitacora_count, 'ndvi' => $e->ndvi_imagenes_count,
                    'parcelas' => $e->parcelas_count, 'tratamientos' => $e->tratamientos_count,
                ])->values(),
            ],
        ]);
    }

    /** Ensayos visibles que cumplen ?programa_id, ?proyecto_id y ?ensayo_id. */
    protected function ensayoIdsVisibles(Request $request)
    {
        $request->validate(['ensayo_id' => 'nullable|integer', 'proyecto_id' => 'nullable|integer', 'programa_id' => 'nullable|integer']);
        return Ensayo::visiblesPara(Auth::user())
            ->when($request->filled('ensayo_id'), fn ($q) => $q->whereKey($request->query('ensayo_id')))
            ->when($request->filled('proyecto_id'), fn ($q) => $q->where('proyecto_id', $request->query('proyecto_id')))
            ->when($request->filled('programa_id'), fn ($q) => $q->whereHas('proyecto', fn ($p) => $p->where('programa_id', $request->query('programa_id'))))
            ->pluck('id');
    }

    /** Periodo inclusivo, con fechas reales y orden valido. */
    protected function rango(Request $request): array
    {
        $d = $request->validate(['desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d' . ($request->filled('desde') ? '|after_or_equal:desde' : '')]);
        return [$d['desde'] ?? null, $d['hasta'] ?? null];
    }

    protected function nombreArchivo(string $base, Request $request): string
    {
        $codigo = $request->filled('ensayo_id') ? optional(Ensayo::visiblesPara(Auth::user())->whereKey($request->query('ensayo_id'))->first())->codigo : null;
        $codigo = $codigo ? preg_replace('/[^A-Za-z0-9_-]/', '_', $codigo) : null;

        return 'Experimentos_' . $base . ($codigo ? "_{$codigo}" : '') . '_' . now()->format('Y-m-d') . '.xlsx';
    }

    public function exportarEvaluaciones(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        return Excel::download(new EvaluacionesExport($this->ensayoIdsVisibles($request), ...$this->rango($request)), $this->nombreArchivo('evaluaciones', $request));
    }

    public function exportarMuestrasLab(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        return Excel::download(new MuestrasLabExport($this->ensayoIdsVisibles($request), ...$this->rango($request)), $this->nombreArchivo('muestras', $request));
    }

    public function exportarBitacora(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        return Excel::download(new BitacoraExport($this->ensayoIdsVisibles($request), ...$this->rango($request)), $this->nombreArchivo('bitacora', $request));
    }

    public function exportarEnsayos(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        return Excel::download(new EnsayosExport($this->ensayoIdsVisibles($request)), $this->nombreArchivo('cartera_ensayos', $request));
    }

    public function exportarParcelas(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);
        return Excel::download(new ParcelasExport($this->ensayoIdsVisibles($request)), $this->nombreArchivo('parcelas', $request));
    }

    public function exportarNdvi(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);
        return Excel::download(new NdviExport($this->ensayoIdsVisibles($request), ...$this->rango($request)), $this->nombreArchivo('ndvi', $request));
    }

    /** Version anterior del resumen: ahora es el informe tecnico. */
    public function resumenEnsayo(Request $request, Ensayo $ensayo)
    {
        abort_unless(Auth::user()->can('view_reports_menu') && Ensayo::visiblesPara(Auth::user())->whereKey($ensayo->id)->exists(), 403);
        [$desde, $hasta] = $this->rango($request);
        return redirect()->route('soporte.reportes.informe', ['ensayo' => $ensayo->id, 'desde' => $desde, 'hasta' => $hasta]);
    }

    /**
     * Informe tecnico del ensayo: los datos van al navegador, que calcula el analisis
     * estadistico (resources/js/estadistica.js, el mismo de Evaluaciones) y lo imprime.
     */
    public function informe(Request $request, Ensayo $ensayo, ResultadosLaboratorio $resultados)
    {
        $user = Auth::user();
        abort_unless($user->can('view_reports_menu'), 403);
        abort_unless(Ensayo::visiblesPara($user)->whereKey($ensayo->id)->exists(), 403);
        // El periodo de la URL solo es el valor inicial: el informe recibe todo el historial
        // del ensayo y filtra fechas, tratamientos y variables en el navegador, sin recargar.
        [$desde, $hasta] = $this->rango($request);

        $ensayo->load(['proyecto.programa', 'ingenio', 'responsables:users.id,users.name', 'tratamientos', 'parcelas.tratamiento:id,codigo']);
        $diseno = $ensayo->diseno ? DisenoExperimental::where('nombre', $ensayo->diseno)->first() : null;

        $campo = Evaluacion::with('variable:id,nombre,unidad,tipo,categoria,decimales')
            ->whereHas('parcela', fn ($q) => $q->where('ensayo_id', $ensayo->id))
            ->orderBy('fecha')
            ->get(['id', 'fecha', 'parcela_id', 'variable_id', 'valor']);
        $lab = $resultados->porEnsayos(collect([$ensayo->id]));
        $lab['registros'] = array_values(array_filter($lab['registros'], fn ($r) => ! empty($r['fecha'])));
        $idsLab = collect($lab['registros'])->pluck('variable_id');
        $lab['variables'] = array_values(array_filter($lab['variables'], fn ($v) => $idsLab->contains($v['id'])));

        return view('component', [
            'title' => 'Informe técnico · ' . $ensayo->codigo,
            'component' => 'informe-ensayo',
            'params' => [
                'reportesUrl' => route('soporte.reportes.index'),
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'ensayo' => [
                    'id' => $ensayo->id, 'codigo' => $ensayo->codigo, 'nombre' => $ensayo->nombre, 'objetivo' => $ensayo->objetivo,
                    'estado' => $ensayo->estado, 'diseno' => $ensayo->diseno, 'usa_bloques' => $diseno ? $diseno->usa_bloques : null,
                    'cultivo' => $ensayo->cultivo, 'variedad' => $ensayo->variedad, 'ciclo' => $ensayo->ciclo,
                    'fecha_siembra' => optional($ensayo->fecha_siembra)->format('Y-m-d'),
                    'fecha_cosecha_prevista' => optional($ensayo->fecha_cosecha_prevista)->format('Y-m-d'),
                    'fecha_cosecha' => optional($ensayo->fecha_cosecha)->format('Y-m-d'),
                    'finca' => $ensayo->finca, 'lote' => $ensayo->lote, 'estrato' => $ensayo->estrato, 'zona' => $ensayo->zona,
                    'area_total' => $ensayo->area_total, 'area_parcela' => $ensayo->area_parcela,
                    'num_repeticiones' => $ensayo->num_repeticiones,
                    'ingenio' => optional($ensayo->ingenio)->nombre,
                    'responsables' => $ensayo->responsables->pluck('name')->values(),
                    'proyecto' => $ensayo->proyecto ? ['codigo' => $ensayo->proyecto->codigo, 'nombre' => $ensayo->proyecto->nombre] : null,
                    'programa' => optional($ensayo->proyecto)->programa ? ['sigla' => $ensayo->proyecto->programa->sigla, 'nombre' => $ensayo->proyecto->programa->nombre] : null,
                ],
                'tratamientos' => $ensayo->tratamientos->map(fn ($t) => ['codigo' => $t->codigo, 'descripcion' => $t->descripcion])->values(),
                'parcelas' => $ensayo->parcelas->map(fn ($p) => ['id' => $p->id, 'codigo' => $p->codigo, 'tratamiento' => optional($p->tratamiento)->codigo, 'repeticion' => $p->repeticion])->values(),
                'variables' => $campo->pluck('variable')->filter()->unique('id')->map(fn ($v) => [
                    'id' => $v->id, 'nombre' => $v->nombre, 'unidad' => $v->unidad, 'tipo' => $v->tipo, 'categoria' => $v->categoria, 'decimales' => $v->decimales, 'origen' => 'campo',
                ])->concat($lab['variables'])->values(),
                'registros' => $campo->map(fn ($e) => ['fecha' => $e->fecha->format('Y-m-d'), 'parcela_id' => $e->parcela_id, 'variable_id' => $e->variable_id, 'valor' => $e->valor])
                    ->concat(collect($lab['registros'])->map(fn ($r) => ['fecha' => $r['fecha'], 'parcela_id' => $r['parcela_id'], 'variable_id' => $r['variable_id'], 'valor' => $r['valor']]))
                    ->values(),
                'ndvi' => $ensayo->ndviImagenes()->orderBy('fecha')->get(['id', 'fecha', 'fuente', 'valores'])->filter(fn ($i) => ! empty($i->valores))
                    ->map(fn ($i) => ['id' => $i->id, 'fecha' => $i->fecha->format('Y-m-d'), 'fuente' => $i->fuente, 'valores' => $i->valores])->values(),
                'muestras' => MuestraLab::where('ensayo_id', $ensayo->id)->orderBy('fecha')->get(['fecha', 'tipo', 'estado', 'parcela_id'])
                    ->map(fn ($m) => ['fecha' => optional($m->fecha)->format('Y-m-d'), 'tipo' => $m->tipo, 'estado' => $m->estado, 'parcela_id' => $m->parcela_id])->values(),
                'bitacora' => Bitacora::with('responsable:id,name')->where('ensayo_id', $ensayo->id)->orderBy('fecha')->get()
                    ->map(fn ($b) => ['fecha' => $b->fecha->format('Y-m-d'), 'tipo' => $b->tipo, 'descripcion' => $b->descripcion, 'responsable' => optional($b->responsable)->name])->values(),
                'fotos' => \App\Models\FotoParcela::with('subidoPor:id,name')->where('ensayo_id', $ensayo->id)->orderBy('fecha')->orderBy('id')->get()
                    ->map(fn ($f) => \App\Http\Controllers\Investigacion\FotosParcelaController::dto($f))->values(),
                'generado' => ['fecha' => now()->format('Y-m-d H:i'), 'por' => $user->name],
            ],
        ]);
    }
}
