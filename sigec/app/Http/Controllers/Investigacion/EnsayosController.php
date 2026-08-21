<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\Ingenio;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EnsayosController extends Controller
{
    protected array $reglasValidacion = [
        'codigo' => 'required|string|max:30',
        'proyecto_id' => 'required|exists:proyectos,id',
        'ingenio_id' => 'nullable|exists:ingenios,id',
        'finca' => 'nullable|string|max:150',
        'lote' => 'nullable|string|max:150',
        'diseno' => 'nullable|string|max:150',
        'cultivo' => 'nullable|string|max:150',
        'variedad' => 'nullable|string|max:150',
        'responsable_id' => 'nullable|exists:users,id',
        'estado' => 'required|in:Planificado,En campo,Finalizado',
        'lat' => 'nullable|numeric|between:-90,90',
        'lng' => 'nullable|numeric|between:-180,180',
        'num_tratamientos' => 'nullable|integer|min:0|max:50',
        'num_repeticiones' => 'nullable|integer|min:0|max:20',
        'area_parcela' => 'nullable|numeric|min:0',
    ];

    public function index()
    {
        $user = Auth::user();

        $ensayos = Ensayo::visiblesPara($user)
            ->with(['proyecto', 'ingenio', 'responsable'])
            ->orderBy('codigo')
            ->get();

        return view('component', [
            'title' => 'Ensayos',
            'component' => 'ensayos-index',
            'params' => [
                'ensayos' => $ensayos,
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'ingenios' => Ingenio::orderBy('nombre')->get(['id', 'nombre']),
                'responsables' => User::orderBy('name')->get(['id', 'name']),
                'puedeCrear' => $user->can('create_ensayo'),
                'puedeEditar' => $user->can('edit_ensayo'),
                'puedeEliminar' => $user->can('admin_only'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create_ensayo');

        $data = $request->validate($this->reglasValidacion);

        $ensayo = Ensayo::create($data);

        return response()->json($ensayo->load(['proyecto', 'ingenio', 'responsable']), 201);
    }

    public function update(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');

        $data = $request->validate($this->reglasValidacion);

        $ensayo->update($data);

        return response()->json($ensayo->load(['proyecto', 'ingenio', 'responsable']));
    }

    public function destroy(Ensayo $ensayo)
    {
        Gate::authorize('admin_only');

        $ensayo->delete();

        return response()->json(['ok' => true]);
    }
}
