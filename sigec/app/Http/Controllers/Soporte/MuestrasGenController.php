<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\Tratamiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class MuestrasGenController extends Controller
{
    public function index()
    {
        Gate::authorize('create_muestra');

        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        return view('component', [
            'title' => 'Generacion de ID de Muestras',
            'component' => 'muestras-gen-index',
            'params' => [
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'proyecto_id']),
                'tratamientos' => Tratamiento::whereIn('ensayo_id', $ensayoIds)->get(['id', 'ensayo_id', 'codigo']),
                'parcelas' => Parcela::whereIn('ensayo_id', $ensayoIds)->get(['id', 'ensayo_id', 'tratamiento_id', 'codigo', 'repeticion']),
                'tiposMuestra' => \App\Models\AnaliticoPorTipo::select('tipo_muestra')->distinct()->orderBy('tipo_muestra')->pluck('tipo_muestra'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create_muestra');

        $data = $request->validate([
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'required|exists:ensayos,id',
            'tipo' => 'required|string',
            'fecha' => 'required|date',
            'filas' => 'required|array|min:1',
            'filas.*.tratamiento_id' => 'nullable|exists:tratamientos,id',
            'filas.*.parcela_id' => 'nullable|exists:parcelas,id',
            'filas.*.repeticion' => 'nullable|integer|min:1',
            'filas.*.obs' => 'nullable|string|max:500',
        ]);

        $ensayo = Ensayo::findOrFail($data['ensayo_id']);
        $creadas = [];

        foreach ($data['filas'] as $fila) {
            $creadas[] = MuestraLab::create([
                'id_muestra' => LaboratorioController::generarIdMuestra($ensayo, $data['tipo']),
                'fecha' => $data['fecha'],
                'tipo' => $data['tipo'],
                'proyecto_id' => $data['proyecto_id'],
                'ensayo_id' => $data['ensayo_id'],
                'tratamiento_id' => $fila['tratamiento_id'] ?? null,
                'parcela_id' => $fila['parcela_id'] ?? null,
                'repeticion' => $fila['repeticion'] ?? null,
                'estado' => 'Recibida',
                'obs' => $fila['obs'] ?? null,
                'solicitante_id' => Auth::id(),
            ]);
        }

        return response()->json($creadas, 201);
    }
}
