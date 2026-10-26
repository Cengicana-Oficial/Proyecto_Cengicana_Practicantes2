<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EvaluacionesController extends Controller
{
    protected array $reglasValidacion = [
        'fecha' => 'required|date',
        'variable_id' => 'required|exists:variables,id',
        'parcela_id' => 'required|exists:parcelas,id',
        'valor' => 'required|string|max:150',
        'obs' => 'nullable|string|max:1000',
    ];

    public function index()
    {
        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $evaluaciones = Evaluacion::with(['variable', 'parcela.ensayo', 'parcela.tratamiento', 'responsable'])
            ->whereHas('parcela', fn ($q) => $q->whereIn('ensayo_id', $ensayoIds))
            ->orderByDesc('fecha')
            ->get();

        $parcelas = \App\Models\Parcela::whereIn('ensayo_id', $ensayoIds)
            ->with(['ensayo', 'tratamiento'])
            ->orderBy('codigo')
            ->get();

        return view('component', [
            'title' => 'Evaluaciones',
            'component' => 'evaluaciones-index',
            'params' => [
                'evaluaciones' => $evaluaciones,
                'variables' => Variable::orderBy('nombre')->get(['id', 'nombre', 'unidad']),
                'parcelas' => $parcelas,
                'puedeRegistrar' => $user->can('register_evaluacion'),
                'puedeEliminar' => $user->can('admin_only'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('register_evaluacion');

        $data = $request->validate($this->reglasValidacion);
        $data['responsable_id'] = Auth::id();

        $evaluacion = Evaluacion::create($data);

        return response()->json($evaluacion->load(['variable', 'parcela.ensayo', 'parcela.tratamiento', 'responsable']), 201);
    }

    public function update(Request $request, Evaluacion $evaluacion)
    {
        Gate::authorize('register_evaluacion');

        $evaluacion->update($request->validate($this->reglasValidacion));

        return response()->json($evaluacion->load(['variable', 'parcela.ensayo', 'parcela.tratamiento', 'responsable']));
    }

    public function destroy(Evaluacion $evaluacion)
    {
        Gate::authorize('admin_only');

        $evaluacion->delete();

        return response()->json(['ok' => true]);
    }
}
