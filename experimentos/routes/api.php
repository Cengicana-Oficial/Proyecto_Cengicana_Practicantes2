<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
 * Modulo Laboratorio -> SIGEC: el Laboratorio envia el avance y los resultados de
 * las muestras de SIGEC. Firma HMAC obligatoria (VerificarEnvioLaboratorio);
 * contrato en docs/api-laboratorio-resultados.md.
 */
Route::post('laboratorio/resultados', [\App\Http\Controllers\Api\LaboratorioResultadosController::class, 'recibir'])
    ->middleware(\App\Http\Middleware\VerificarEnvioLaboratorio::class)
    ->name('api.laboratorio.resultados');

// Laboratorio -> SIGEC (solo lectura): datos de las muestras por el ID leido en el QR.
Route::post('laboratorio/muestras', [\App\Http\Controllers\Api\LaboratorioMuestrasController::class, 'consultar'])
    ->middleware(\App\Http\Middleware\VerificarEnvioLaboratorio::class)
    ->name('api.laboratorio.muestras');
