<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Services\LaboratorioModulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Muestras: una sola pagina con dos pestañas del mismo flujo.
 *  - Generar etiquetas (antes "Generar muestras"): crear el lote e imprimir los QR.
 *  - Seguimiento (antes "Muestras y resultados"): estado, vinculo con el Laboratorio y resultados.
 * Los datos de cada pestaña los arma su controlador (parametros()); sus acciones no cambian.
 */
class MuestrasController extends Controller
{
    public function index(Request $request, MuestrasGenController $generar, MuestrasConsultaController $seguimiento, LaboratorioModulo $laboratorio)
    {
        $user = Auth::user();
        $puedeGenerar = $user->can('create_muestra');
        abort_unless($puedeGenerar || $user->can('view_lab'), 403);

        return view('component', [
            'title' => 'Muestras',
            'component' => 'muestras-index',
            'params' => [
                'generar' => $puedeGenerar ? $generar->parametros() : null,
                'seguimiento' => $seguimiento->parametros($laboratorio),
            ],
        ]);
    }
}
