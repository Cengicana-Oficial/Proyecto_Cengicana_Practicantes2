<?php

use App\Http\Controllers\Admin\UsuariosController;
use App\Http\Controllers\Analisis\AnalisisController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Investigacion\EnsayosController;
use App\Http\Controllers\Investigacion\EvaluacionesController;
use App\Http\Controllers\Investigacion\ProgramasController;
use App\Http\Controllers\Investigacion\ProyectosController;
use App\Http\Controllers\Investigacion\VariablesController;
use App\Http\Controllers\Soporte\ArchivosController;
use App\Http\Controllers\Soporte\BitacoraController;
use App\Http\Controllers\Soporte\FormulariosController;
use App\Http\Controllers\Soporte\GraficasController;
use App\Http\Controllers\Soporte\ImagenesGeoController;
use App\Http\Controllers\Soporte\LaboratorioController;
use App\Http\Controllers\Soporte\MuestrasConsultaController;
use App\Http\Controllers\Soporte\MuestrasGenController;
use App\Http\Controllers\Soporte\ReportesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::redirect('/', '/login');

Auth::routes();

// Puente de SSO desde login/Menu.php del compendio (ver config/sso.php y
// App\Http\Controllers\Auth\SsoController). Fuera del grupo 'auth': esta
// ruta es la que crea la sesion.
Route::get('/sso/entrar', [SsoController::class, 'entrar'])
    ->middleware('throttle:10,1')
    ->name('sso.entrar');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::group(['as' => 'investigacion.', 'prefix' => 'investigacion'], function () {
        Route::get('programas', [ProgramasController::class, 'index'])->name('programas.index');
        Route::post('programas', [ProgramasController::class, 'store'])->name('programas.store');
        Route::put('programas/{programa}', [ProgramasController::class, 'update'])->name('programas.update');
        Route::delete('programas/{programa}', [ProgramasController::class, 'destroy'])->name('programas.destroy');

        Route::get('proyectos', [ProyectosController::class, 'index'])->name('proyectos.index');
        Route::post('proyectos', [ProyectosController::class, 'store'])->name('proyectos.store');
        Route::put('proyectos/{proyecto}', [ProyectosController::class, 'update'])->name('proyectos.update');
        Route::delete('proyectos/{proyecto}', [ProyectosController::class, 'destroy'])->name('proyectos.destroy');

        Route::get('ensayos', [EnsayosController::class, 'index'])->name('ensayos.index');
        Route::post('ensayos', [EnsayosController::class, 'store'])->name('ensayos.store');
        Route::put('ensayos/{ensayo}', [EnsayosController::class, 'update'])->name('ensayos.update');
        Route::delete('ensayos/{ensayo}', [EnsayosController::class, 'destroy'])->name('ensayos.destroy');

        Route::get('variables', [VariablesController::class, 'index'])->name('variables.index');
        Route::post('variables', [VariablesController::class, 'store'])->name('variables.store');
        Route::put('variables/{variable}', [VariablesController::class, 'update'])->name('variables.update');
        Route::delete('variables/{variable}', [VariablesController::class, 'destroy'])->name('variables.destroy');

        Route::get('evaluaciones', [EvaluacionesController::class, 'index'])->name('evaluaciones.index');
        Route::post('evaluaciones', [EvaluacionesController::class, 'store'])->name('evaluaciones.store');
        Route::put('evaluaciones/{evaluacion}', [EvaluacionesController::class, 'update'])->name('evaluaciones.update');
        Route::delete('evaluaciones/{evaluacion}', [EvaluacionesController::class, 'destroy'])->name('evaluaciones.destroy');
    });

    Route::group(['as' => 'soporte.', 'prefix' => 'soporte'], function () {
        Route::get('laboratorio', [LaboratorioController::class, 'index'])->name('laboratorio.index');
        Route::post('laboratorio', [LaboratorioController::class, 'store'])->name('laboratorio.store');
        Route::put('laboratorio/{muestra}', [LaboratorioController::class, 'update'])->name('laboratorio.update');
        Route::delete('laboratorio/{muestra}', [LaboratorioController::class, 'destroy'])->name('laboratorio.destroy');

        Route::get('muestras/generar', [MuestrasGenController::class, 'index'])->name('muestras-gen.index');
        Route::post('muestras/generar', [MuestrasGenController::class, 'store'])->name('muestras-gen.store');

        Route::get('muestras/consulta', [MuestrasConsultaController::class, 'index'])->name('muestras-consulta.index');
        Route::put('muestras/consulta/{muestra}', [MuestrasConsultaController::class, 'update'])->name('muestras-consulta.update');
        Route::get('imagenes-geo', [ImagenesGeoController::class, 'index'])->name('imagenes-geo.index');
        Route::post('imagenes-geo', [ImagenesGeoController::class, 'store'])->name('imagenes-geo.store');
        Route::delete('imagenes-geo/{imagen}', [ImagenesGeoController::class, 'destroy'])->name('imagenes-geo.destroy');

        Route::get('graficas', [GraficasController::class, 'index'])->name('graficas.index');
        Route::get('graficas/datos-variable', [GraficasController::class, 'datosVariable'])->name('graficas.datos-variable');
        Route::get('graficas/datos-analito', [GraficasController::class, 'datosAnalito'])->name('graficas.datos-analito');

        Route::get('archivos', [ArchivosController::class, 'index'])->name('archivos.index');
        Route::post('archivos', [ArchivosController::class, 'store'])->name('archivos.store');
        Route::delete('archivos/{archivo}', [ArchivosController::class, 'destroy'])->name('archivos.destroy');

        Route::get('bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
        Route::post('bitacora', [BitacoraController::class, 'store'])->name('bitacora.store');
        Route::put('bitacora/{bitacora}', [BitacoraController::class, 'update'])->name('bitacora.update');
        Route::delete('bitacora/{bitacora}', [BitacoraController::class, 'destroy'])->name('bitacora.destroy');
        Route::get('formularios', [FormulariosController::class, 'index'])->name('formularios.index');
        Route::post('formularios', [FormulariosController::class, 'store'])->name('formularios.store');
        Route::put('formularios/{formulario}', [FormulariosController::class, 'update'])->name('formularios.update');
        Route::delete('formularios/{formulario}', [FormulariosController::class, 'destroy'])->name('formularios.destroy');
        Route::post('formularios/{formulario}/asignaciones', [FormulariosController::class, 'storeAsignacion'])->name('formularios.asignaciones.store');
        Route::delete('formularios/asignaciones/{asignacion}', [FormulariosController::class, 'destroyAsignacion'])->name('formularios.asignaciones.destroy');

        Route::get('reportes', [ReportesController::class, 'index'])->name('reportes.index');
        Route::get('reportes/exportar/evaluaciones', [ReportesController::class, 'exportarEvaluaciones'])->name('reportes.exportar-evaluaciones');
        Route::get('reportes/exportar/muestras-lab', [ReportesController::class, 'exportarMuestrasLab'])->name('reportes.exportar-muestras-lab');
        Route::get('reportes/resumen/{ensayo}', [ReportesController::class, 'resumenEnsayo'])->name('reportes.resumen-ensayo');
    });

    Route::group(['as' => 'analisis.', 'prefix' => 'analisis'], function () {
        Route::get('/', [AnalisisController::class, 'index'])->name('index');
        Route::post('importar', [AnalisisController::class, 'importar'])->name('importar');
    });

    Route::group(['as' => 'admin.', 'prefix' => 'admin'], function () {
        Route::get('usuarios', [UsuariosController::class, 'index'])->name('usuarios.index');
        Route::post('usuarios', [UsuariosController::class, 'store'])->name('usuarios.store');
        Route::put('usuarios/{usuario}', [UsuariosController::class, 'update'])->name('usuarios.update');
        Route::delete('usuarios/{usuario}', [UsuariosController::class, 'destroy'])->name('usuarios.destroy');
    });
});
