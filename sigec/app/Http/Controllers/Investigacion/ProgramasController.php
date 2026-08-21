<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Programa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ProgramasController extends Controller
{
    /**
     * Paleta de colores rotativa para nuevos programas, portada de los
     * colores de marca usados en el prototipo SIGEC_v12.html (el color no
     * es un campo editable en el formulario, se asigna automaticamente).
     */
    protected array $paleta = ['#73BC25', '#FF6B00', '#FFCC00', '#A3D300', '#1f6fbf', '#7B2FBE', '#00897B'];

    public function index()
    {
        $programas = Programa::visiblesPara(Auth::user())
            ->withCount('proyectos')
            ->orderBy('nombre')
            ->get();

        return view('component', [
            'title' => 'Programas',
            'component' => 'programas-index',
            'params' => [
                'programas' => $programas,
                'puedeEditar' => Auth::user()->can('admin_only'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $request->validate([
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:1000',
            'lider' => 'nullable|string|max:150',
        ]);

        $data['color'] = $this->paleta[Programa::count() % count($this->paleta)];

        $programa = Programa::create($data);

        return response()->json($programa->loadCount('proyectos'), 201);
    }

    public function update(Request $request, Programa $programa)
    {
        Gate::authorize('admin_only');

        $data = $request->validate([
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:1000',
            'lider' => 'nullable|string|max:150',
        ]);

        $programa->update($data);

        return response()->json($programa->loadCount('proyectos'));
    }

    public function destroy(Programa $programa)
    {
        Gate::authorize('admin_only');

        $programa->delete();

        return response()->json(['ok' => true]);
    }
}
