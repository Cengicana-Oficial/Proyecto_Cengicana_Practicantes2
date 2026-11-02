<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class MuestrasConsultaController extends Controller
{
    /**
     * Portado de CICLO_STEPS del prototipo SIGEC_v12.html. El estado
     * "Pendiente" (usado por el flujo de Laboratorio) se muestra en el
     * mismo paso que "Recibida" dentro del tracker de 3 pasos.
     */
    const CICLO_STEPS = ['Recibida', 'En análisis', 'Completado'];

    public function index()
    {
        abort_unless(Auth::user()->can('view_lab') || Auth::user()->can('create_muestra'), 403);

        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $muestras = MuestraLab::with(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante'])
            ->whereIn('ensayo_id', $ensayoIds)
            ->orderByDesc('fecha')
            ->get();

        return view('component', [
            'title' => 'Consulta de Muestras',
            'component' => 'muestras-consulta-index',
            'params' => [
                'muestras' => $muestras,
                'cicloSteps' => self::CICLO_STEPS,
                'puedeActualizar' => Auth::user()->can('create_muestra'),
            ],
        ]);
    }

    public function update(Request $request, MuestraLab $muestra)
    {
        Gate::authorize('create_muestra');

        $data = $request->validate([
            'estado' => 'required|in:Recibida,Pendiente,En proceso,Completado',
            'analistas' => 'nullable|string|max:150',
            'resultado_texto' => 'nullable|string|max:2000',
            'fecha_resultado' => 'nullable|date',
        ]);

        $muestra->update($data);

        return response()->json($muestra->load(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante']));
    }
}
