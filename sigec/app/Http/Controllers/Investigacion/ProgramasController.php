<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Programa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProgramasController extends Controller
{
    /**
     * Paleta de marca CENGICANA (verde principal, naranja, amarillo, verde
     * secundario, gris). El formulario permite elegir uno de estos colores;
     * si no se envia, se asigna de forma rotativa.
     */
    protected array $paleta = ['#73BC25', '#FF6B00', '#FFCC00', '#A3D300', '#CED2D5'];

    public function index()
    {
        $programas = $this->conConteos(Programa::visiblesPara(Auth::user()))
            ->orderBy('nombre')
            ->get();

        return view('component', [
            'title' => 'Programas de investigacion',
            'component' => 'programas-index',
            'params' => [
                'programas' => $programas,
                'puedeEditar' => Auth::user()->can('admin_only'),
                'paleta' => $this->paleta,
                'proyectosUrl' => route('investigacion.proyectos.index'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $this->validar($request);
        $data['color'] = $data['color'] ?? $this->paleta[Programa::count() % count($this->paleta)];

        $programa = Programa::create($data);

        return response()->json($this->recargar($programa), 201);
    }

    public function update(Request $request, Programa $programa)
    {
        Gate::authorize('admin_only');

        $data = $this->validar($request);
        if (empty($data['color'])) {
            unset($data['color']);
        }

        $programa->update($data);

        return response()->json($this->recargar($programa));
    }

    public function destroy(Programa $programa)
    {
        Gate::authorize('admin_only');

        // proyectos.programa_id (y ensayos.proyecto_id) son cascadeOnDelete:
        // borrar un programa con proyectos arrastraria proyectos y ensayos.
        $proyectos = $programa->proyectos()->count();
        if ($proyectos > 0) {
            return response()->json([
                'message' => "No se puede eliminar: el programa tiene {$proyectos} proyecto(s) vinculado(s). Reasigne o elimine esos proyectos primero.",
            ], 422);
        }

        $programa->delete();

        return response()->json(['ok' => true]);
    }

    protected function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:1000',
            'lider' => 'nullable|string|max:150',
            'color' => ['nullable', Rule::in($this->paleta)],
        ], [], [
            'nombre' => 'nombre del programa',
            'descripcion' => 'descripcion',
            'lider' => 'lider',
        ]);
    }

    protected function conConteos($query)
    {
        return $query->withCount([
            'proyectos',
            'proyectos as proyectos_activos_count' => fn ($q) => $q->where('estado', 'Activo'),
            'ensayos',
        ]);
    }

    protected function recargar(Programa $programa): Programa
    {
        return $this->conConteos(Programa::whereKey($programa->id))->first();
    }
}
