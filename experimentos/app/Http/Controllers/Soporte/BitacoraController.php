<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\Ensayo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class BitacoraController extends Controller
{
    /**
     * Portado de la constante TIPO_BITACORA del prototipo SIGEC_v12.html.
     */
    const TIPOS = [
        'Siembra', 'Fertilización', 'Control de maleza', 'Aplicación de producto',
        'Evaluación de campo', 'Muestreo', 'Cosecha', 'Mantenimiento',
        'Incidencia fitosanitaria', 'Visita técnica', 'Otro',
    ];

    public function index()
    {
        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $entradas = Bitacora::with(['ensayo', 'responsable'])
            ->whereIn('ensayo_id', $ensayoIds)
            ->orderByDesc('fecha')
            ->get();

        return view('component', [
            'title' => 'Bitacora',
            'component' => 'bitacora-index',
            'params' => [
                'entradas' => $entradas,
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
                'tipos' => self::TIPOS,
                'puedeEscribir' => $user->can('write_bitacora'),
                'puedeEliminar' => $user->can('admin_only'),
            ],
        ]);
    }

    protected function reglas(): array
    {
        return [
            'ensayo_id' => 'required|exists:ensayos,id',
            'fecha' => 'required|date',
            'tipo' => 'required|in:'.implode(',', self::TIPOS),
            'descripcion' => 'required|string|max:2000',
        ];
    }

    public function store(Request $request)
    {
        Gate::authorize('write_bitacora');

        $data = $request->validate($this->reglas());
        $data['responsable_id'] = Auth::id();

        $entrada = Bitacora::create($data);

        return response()->json($entrada->load(['ensayo', 'responsable']), 201);
    }

    public function update(Request $request, Bitacora $bitacora)
    {
        Gate::authorize('write_bitacora');

        $bitacora->update($request->validate($this->reglas()));

        return response()->json($bitacora->load(['ensayo', 'responsable']));
    }

    public function destroy(Bitacora $bitacora)
    {
        Gate::authorize('admin_only');

        $bitacora->delete();

        return response()->json(['ok' => true]);
    }
}
