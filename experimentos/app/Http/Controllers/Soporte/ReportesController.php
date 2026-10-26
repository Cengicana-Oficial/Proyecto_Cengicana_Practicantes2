<?php

namespace App\Http\Controllers\Soporte;

use App\Exports\EvaluacionesExport;
use App\Exports\MuestrasLabExport;
use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\MuestraLab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ReportesController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        abort_unless($user->can('view_reports_menu'), 403);

        return view('component', [
            'title' => 'Reportes',
            'component' => 'reportes-index',
            'params' => [
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
            ],
        ]);
    }

    protected function ensayoIdsVisibles(Request $request)
    {
        $visibles = Ensayo::visiblesPara(Auth::user())->pluck('id');

        if ($request->filled('ensayo_id')) {
            return $visibles->filter(fn ($id) => $id == $request->query('ensayo_id'))->values();
        }

        return $visibles;
    }

    public function exportarEvaluaciones(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        $ensayoIds = $this->ensayoIdsVisibles($request);

        return Excel::download(new EvaluacionesExport($ensayoIds), 'evaluaciones_sigec.xlsx');
    }

    public function exportarMuestrasLab(Request $request)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        $ensayoIds = $this->ensayoIdsVisibles($request);

        return Excel::download(new MuestrasLabExport($ensayoIds), 'muestras_laboratorio_sigec.xlsx');
    }

    public function resumenEnsayo(Request $request, Ensayo $ensayo)
    {
        abort_unless(Auth::user()->can('view_reports_menu'), 403);

        $visibles = Ensayo::visiblesPara(Auth::user())->pluck('id');
        abort_unless($visibles->contains($ensayo->id), 403);

        $ensayo->load(['proyecto.programa', 'ingenio', 'responsable', 'tratamientos', 'parcelas']);

        $evaluaciones = Evaluacion::whereHas('parcela', fn ($q) => $q->where('ensayo_id', $ensayo->id))->count();
        $muestras = MuestraLab::where('ensayo_id', $ensayo->id)->get();
        $bitacora = Bitacora::where('ensayo_id', $ensayo->id)->orderByDesc('fecha')->get();

        return view('soporte.reportes.resumen-ensayo', [
            'title' => 'Resumen — '.$ensayo->codigo,
            'ensayo' => $ensayo,
            'totalEvaluaciones' => $evaluaciones,
            'muestras' => $muestras,
            'bitacora' => $bitacora,
        ]);
    }
}
