<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Programa;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ProyectosController extends Controller
{
    protected array $reglasValidacion = [
        'codigo' => 'required|string|max:30',
        'nombre' => 'required|string|max:200',
        'programa_id' => 'required|exists:programas,id',
        'responsable_id' => 'nullable|exists:users,id',
        'objetivo' => 'nullable|string|max:2000',
        'inicio' => 'nullable|date',
        'fin' => 'nullable|date|after_or_equal:inicio',
        'estado' => 'required|in:Activo,Pausado,Finalizado',
    ];

    public function index()
    {
        $user = Auth::user();

        $proyectos = Proyecto::visiblesPara($user)
            ->with(['programa', 'responsable'])
            ->withCount('ensayos')
            ->orderBy('codigo')
            ->get();

        return view('component', [
            'title' => 'Proyectos',
            'component' => 'proyectos-index',
            'params' => [
                'proyectos' => $proyectos,
                'programas' => Programa::visiblesPara($user)->orderBy('nombre')->get(['id', 'nombre']),
                'responsables' => User::orderBy('name')->get(['id', 'name']),
                'puedeCrear' => $user->can('create_proyecto'),
                'puedeEditar' => $user->can('edit_proyecto'),
                'puedeEliminar' => $user->can('admin_only'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create_proyecto');

        $data = $request->validate($this->reglasValidacion);

        $proyecto = Proyecto::create($data);

        return response()->json($proyecto->load(['programa', 'responsable'])->loadCount('ensayos'), 201);
    }

    public function update(Request $request, Proyecto $proyecto)
    {
        Gate::authorize('edit_proyecto');

        $data = $request->validate($this->reglasValidacion);

        $proyecto->update($data);

        return response()->json($proyecto->load(['programa', 'responsable'])->loadCount('ensayos'));
    }

    public function destroy(Proyecto $proyecto)
    {
        Gate::authorize('admin_only');

        $proyecto->delete();

        return response()->json(['ok' => true]);
    }
}
