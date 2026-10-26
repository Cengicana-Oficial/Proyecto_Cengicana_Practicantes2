<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class VariablesController extends Controller
{
    protected array $reglasValidacion = [
        'nombre' => 'required|string|max:150',
        'unidad' => 'nullable|string|max:50',
        'tipo' => 'required|in:Numerica,Categorica,Texto',
        'categoria' => 'required|in:Desarrollo,Cosecha',
    ];

    public function index()
    {
        $variables = Variable::orderBy('categoria')->orderBy('nombre')->get();

        return view('component', [
            'title' => 'Variables',
            'component' => 'variables-index',
            'params' => [
                'variables' => $variables,
                'puedeEditar' => Auth::user()->can('admin_only'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $variable = Variable::create($request->validate($this->reglasValidacion));

        return response()->json($variable, 201);
    }

    public function update(Request $request, Variable $variable)
    {
        Gate::authorize('admin_only');

        $variable->update($request->validate($this->reglasValidacion));

        return response()->json($variable);
    }

    public function destroy(Variable $variable)
    {
        Gate::authorize('admin_only');

        $variable->delete();

        return response()->json(['ok' => true]);
    }
}
