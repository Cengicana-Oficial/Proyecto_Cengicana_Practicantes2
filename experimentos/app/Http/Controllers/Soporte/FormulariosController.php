<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Asignacion;
use App\Models\Ensayo;
use App\Models\Formulario;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class FormulariosController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if ($user->can('manage_formularios')) {
            $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

            $formularios = Formulario::with(['proyecto', 'ensayo', 'creadoPor', 'asignaciones.usuario'])
                ->whereIn('ensayo_id', $ensayoIds)
                ->orderByDesc('fecha')
                ->get();

            return view('component', [
                'title' => 'Formularios de campo',
                'component' => 'formularios-index',
                'params' => [
                    'modo' => 'builder',
                    'formularios' => $formularios,
                    'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
                    'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'proyecto_id']),
                    'parcelas' => Parcela::whereIn('ensayo_id', $ensayoIds)->get(['id', 'ensayo_id', 'codigo']),
                    'muestreadores' => User::role('muestreador')->orderBy('name')->get(['id', 'name']),
                ],
            ]);
        }

        abort_unless($user->can('ver_formulario_asignado'), 403);

        $asignaciones = Asignacion::with(['formulario', 'ensayo', 'proyecto'])
            ->where('usuario_id', $user->id)
            ->orderByDesc('fecha_inicio')
            ->get();

        return view('component', [
            'title' => 'Formularios de campo',
            'component' => 'formularios-index',
            'params' => [
                'modo' => 'muestreador',
                'asignaciones' => $asignaciones,
            ],
        ]);
    }

    protected function reglasFormulario(): array
    {
        return [
            'nombre' => 'required|string|max:150',
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'required|exists:ensayos,id',
            'campos' => 'required|array|min:1',
            'campos.*.label' => 'required|string|max:150',
            'campos.*.tipo' => 'required|in:numero,texto,select,foto',
            'campos.*.requerido' => 'boolean',
            'campos.*.opciones' => 'nullable|array',
        ];
    }

    public function store(Request $request)
    {
        Gate::authorize('manage_formularios');

        $data = $request->validate($this->reglasFormulario());
        $data['creado_por'] = Auth::id();
        $data['fecha'] = now();
        $data['estado'] = 'Activo';

        $formulario = Formulario::create($data);

        return response()->json($formulario->load(['proyecto', 'ensayo', 'creadoPor', 'asignaciones.usuario']), 201);
    }

    public function update(Request $request, Formulario $formulario)
    {
        Gate::authorize('manage_formularios');

        $formulario->update($request->validate($this->reglasFormulario()));

        return response()->json($formulario->load(['proyecto', 'ensayo', 'creadoPor', 'asignaciones.usuario']));
    }

    public function destroy(Formulario $formulario)
    {
        Gate::authorize('admin_only');

        $formulario->delete();

        return response()->json(['ok' => true]);
    }

    public function storeAsignacion(Request $request, Formulario $formulario)
    {
        Gate::authorize('manage_formularios');

        $data = $request->validate([
            'usuario_id' => 'required|exists:users,id',
            'parcelas' => 'nullable|array',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $asignacion = Asignacion::create([
            'formulario_id' => $formulario->id,
            'usuario_id' => $data['usuario_id'],
            'proyecto_id' => $formulario->proyecto_id,
            'ensayo_id' => $formulario->ensayo_id,
            'parcelas' => $data['parcelas'] ?? [],
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'estado' => 'Activo',
        ]);

        return response()->json($asignacion->load('usuario'), 201);
    }

    public function destroyAsignacion(Asignacion $asignacion)
    {
        Gate::authorize('manage_formularios');

        $asignacion->delete();

        return response()->json(['ok' => true]);
    }
}
