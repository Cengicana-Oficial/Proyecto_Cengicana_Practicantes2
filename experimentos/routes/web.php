<?php

use App\Http\Controllers\Admin\UsuariosController;
use App\Http\Controllers\Analisis\AnalisisController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Investigacion\ClimaBaseController;
use App\Http\Controllers\Investigacion\ClimaController;
use App\Http\Controllers\Investigacion\EnsayosController;
use App\Http\Controllers\Investigacion\EvaluacionesController;
use App\Http\Controllers\Investigacion\ProgramasController;
use App\Http\Controllers\Investigacion\ProyectosController;
use App\Http\Controllers\Investigacion\VariablesController;
use App\Http\Controllers\Soporte\ArchivosController;
use App\Http\Controllers\Soporte\BitacoraController;
use App\Http\Controllers\Soporte\FormulariosController;
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
        Route::get('proyectos/codigo', [ProyectosController::class, 'codigo'])->name('proyectos.codigo');
        Route::post('proyectos', [ProyectosController::class, 'store'])->name('proyectos.store');
        Route::put('proyectos/{proyecto}', [ProyectosController::class, 'update'])->name('proyectos.update');
        Route::delete('proyectos/{proyecto}', [ProyectosController::class, 'destroy'])->name('proyectos.destroy');
        Route::post('proyectos/{proyecto}/equipo', [ProyectosController::class, 'guardarIntegrante'])->name('proyectos.equipo.store');
        Route::delete('proyectos/{proyecto}/equipo/{asignacion}', [ProyectosController::class, 'quitarIntegrante'])->name('proyectos.equipo.destroy');

        Route::get('ensayos', [EnsayosController::class, 'index'])->name('ensayos.index');
        Route::get('ensayos/codigo', [EnsayosController::class, 'codigo'])->name('ensayos.codigo');
        Route::post('ensayos', [EnsayosController::class, 'store'])->name('ensayos.store');
        Route::put('ensayos/{ensayo}', [EnsayosController::class, 'update'])->name('ensayos.update');
        Route::delete('ensayos/{ensayo}', [EnsayosController::class, 'destroy'])->name('ensayos.destroy');
        Route::get('ensayos/{ensayo}', [EnsayosController::class, 'show'])->name('ensayos.show');
        Route::post('ensayos/{ensayo}/tratamientos', [EnsayosController::class, 'storeTratamiento'])->name('ensayos.tratamientos.store');
        Route::put('tratamientos/{tratamiento}', [EnsayosController::class, 'updateTratamiento'])->name('tratamientos.update');
        Route::delete('tratamientos/{tratamiento}', [EnsayosController::class, 'destroyTratamiento'])->name('tratamientos.destroy');
        Route::post('ensayos/{ensayo}/parcelas/generar', [EnsayosController::class, 'generarParcelas'])->name('ensayos.parcelas.generar');
        Route::post('ensayos/{ensayo}/parcelas/aleatorizar', [EnsayosController::class, 'aleatorizarParcelas'])->name('ensayos.parcelas.aleatorizar');
        Route::post('ensayos/{ensayo}/parcelas/intercambiar', [EnsayosController::class, 'intercambiarParcelas'])->name('ensayos.parcelas.intercambiar');
        Route::put('parcelas/{parcela}', [EnsayosController::class, 'updateParcela'])->name('parcelas.update');
        Route::post('ensayos/{ensayo}/croquis', [EnsayosController::class, 'storeCroquis'])->name('ensayos.croquis.store');
        Route::put('ensayos/{ensayo}/croquis', [EnsayosController::class, 'updateCroquis'])->name('ensayos.croquis.update');
        Route::delete('ensayos/{ensayo}/croquis', [EnsayosController::class, 'destroyCroquis'])->name('ensayos.croquis.destroy');
        Route::put('ensayos/{ensayo}/parcelas/geometria', [EnsayosController::class, 'guardarGeometrias'])->name('ensayos.parcelas.geometria');
        Route::post('ensayos/{ensayo}/ndvi', [EnsayosController::class, 'storeNdvi'])->name('ensayos.ndvi.store');
        Route::delete('ndvi/{registro}', [EnsayosController::class, 'destroyNdvi'])->name('ndvi.destroy');
        Route::post('ensayos/{ensayo}/ndvi-imagenes', [EnsayosController::class, 'storeNdviImagen'])->name('ensayos.ndvi-imagenes.store');
        Route::put('ndvi-imagenes/{imagen}', [EnsayosController::class, 'updateNdviImagen'])->name('ndvi-imagenes.update');
        Route::delete('ndvi-imagenes/{imagen}', [EnsayosController::class, 'destroyNdviImagen'])->name('ndvi-imagenes.destroy');
        Route::post('ndvi-imagenes/{imagen}/tratamientos', [EnsayosController::class, 'ndviImagenATratamientos'])->name('ndvi-imagenes.tratamientos');

        Route::get('clima/estaciones', [ClimaController::class, 'estaciones'])->name('clima.estaciones');
        Route::post('clima/estaciones', [ClimaController::class, 'store'])->name('clima.estaciones.store');
        Route::put('clima/estaciones/{estacion}', [ClimaController::class, 'update'])->name('clima.estaciones.update');
        Route::delete('clima/estaciones/{estacion}', [ClimaController::class, 'destroy'])->name('clima.estaciones.destroy');
        Route::get('clima/estaciones/{estacion}/datos', [ClimaController::class, 'datos'])->name('clima.estaciones.datos');
        Route::post('clima/estaciones/{estacion}/importar', [ClimaController::class, 'importar'])->name('clima.estaciones.importar');
        Route::get('clima', [ClimaController::class, 'pagina'])->name('clima.index');
        Route::get('clima/consulta', [ClimaController::class, 'consulta'])->name('clima.consulta');
        Route::get('clima/analisis', [ClimaController::class, 'analisis'])->name('clima.analisis');
        Route::get('clima/serie', [ClimaController::class, 'serie'])->name('clima.serie');
        Route::get('clima/base/estado', [ClimaBaseController::class, 'estado'])->name('clima.base.estado');
        Route::get('clima/base/cobertura-mes', [ClimaBaseController::class, 'coberturaMes'])->name('clima.base.cobertura');
        Route::get('clima/base/bitacora', [ClimaBaseController::class, 'bitacora'])->name('clima.base.bitacora');
        Route::get('clima/calidad/rangos', [ClimaBaseController::class, 'rangos'])->name('clima.calidad.rangos');
        Route::put('clima/calidad/rangos', [ClimaBaseController::class, 'guardarRangos'])->name('clima.calidad.rangos.guardar');
        Route::post('clima/calidad/revisar', [ClimaBaseController::class, 'revisar'])->name('clima.calidad.revisar');
        Route::get('clima/calidad/hallazgos', [ClimaBaseController::class, 'hallazgos'])->name('clima.calidad.hallazgos');
        Route::post('clima/calidad/accion', [ClimaBaseController::class, 'accion'])->name('clima.calidad.accion');
        Route::post('clima/estaciones-catalogo', [ClimaController::class, 'importarCatalogo'])->name('clima.estaciones.catalogo');
        Route::get('clima/estaciones-plantilla', [ClimaController::class, 'plantillaCatalogo'])->name('clima.estaciones.plantilla');
        Route::get('clima/icc', [ClimaController::class, 'iccEstado'])->name('clima.icc');
        Route::post('clima/icc/sincronizar', [ClimaController::class, 'iccSincronizar'])->name('clima.icc.sincronizar');
        Route::get('clima/icc/config', [ClimaController::class, 'iccConfig'])->name('clima.icc.config');
        Route::put('clima/icc/config', [ClimaController::class, 'iccConfigGuardar'])->name('clima.icc.config.guardar');
        Route::post('clima/icc/probar', [ClimaController::class, 'iccProbar'])->name('clima.icc.probar');

        Route::get('variables', [VariablesController::class, 'index'])->name('variables.index');
        Route::post('variables', [VariablesController::class, 'store'])->name('variables.store');
        Route::put('variables/{variable}', [VariablesController::class, 'update'])->name('variables.update');
        Route::delete('variables/{variable}', [VariablesController::class, 'destroy'])->name('variables.destroy');
        Route::get('variables/{variable}/resumen', [VariablesController::class, 'resumen'])->name('variables.resumen');
        Route::post('variables/lote', [VariablesController::class, 'lote'])->name('variables.lote');

        Route::get('evaluaciones', [EvaluacionesController::class, 'index'])->name('evaluaciones.index');
        Route::post('evaluaciones', [EvaluacionesController::class, 'store'])->name('evaluaciones.store');
        Route::post('evaluaciones/lote', [EvaluacionesController::class, 'lote'])->name('evaluaciones.lote');
        Route::get('evaluaciones/laboratorio', [EvaluacionesController::class, 'laboratorio'])->name('evaluaciones.laboratorio');
        Route::put('evaluaciones/{evaluacion}', [EvaluacionesController::class, 'update'])->name('evaluaciones.update');
        Route::delete('evaluaciones/{evaluacion}', [EvaluacionesController::class, 'destroy'])->name('evaluaciones.destroy');
    });

    Route::group(['as' => 'soporte.', 'prefix' => 'soporte'], function () {
        // El trabajo de laboratorio vive en el modulo Laboratorio del portal; SIGEC solo consulta sus resultados.
        Route::get('laboratorio', fn () => redirect()->route('soporte.muestras-consulta.index'))->name('laboratorio.index');

        Route::get('muestras/generar', [MuestrasGenController::class, 'index'])->name('muestras-gen.index');
        Route::post('muestras/generar', [MuestrasGenController::class, 'store'])->name('muestras-gen.store');
        Route::get('muestras/generar/lote', [MuestrasGenController::class, 'lote'])->name('muestras-gen.lote');
        Route::post('muestras/campos', [MuestrasGenController::class, 'guardarCampo'])->name('muestras-campos.store');
        Route::put('muestras/campos/{campo}', [MuestrasGenController::class, 'guardarCampo'])->name('muestras-campos.update');
        Route::delete('muestras/campos/{campo}', [MuestrasGenController::class, 'eliminarCampo'])->name('muestras-campos.destroy');

        Route::get('muestras/consulta', [MuestrasConsultaController::class, 'index'])->name('muestras-consulta.index');
        Route::put('muestras/consulta/{muestra}', [MuestrasConsultaController::class, 'update'])->name('muestras-consulta.update');
        Route::post('muestras/consulta/lote', [MuestrasConsultaController::class, 'lote'])->name('muestras-consulta.lote');
        Route::post('muestras/consulta/sincronizar-laboratorio', [MuestrasConsultaController::class, 'sincronizarLaboratorio'])->name('muestras-consulta.sincronizar');
        Route::get('muestras/consulta/vinculos-sugeridos', [MuestrasConsultaController::class, 'sugerencias'])->name('muestras-consulta.sugerencias');
        Route::get('muestras/consulta/envios-laboratorio', [MuestrasConsultaController::class, 'enviosLaboratorio'])->name('muestras-consulta.envios');
        Route::post('muestras/consulta/vincular', [MuestrasConsultaController::class, 'vincular'])->name('muestras-consulta.vincular');
        Route::post('muestras/consulta/{muestra}/desvincular', [MuestrasConsultaController::class, 'desvincular'])->name('muestras-consulta.desvincular');
        Route::get('imagenes-geo', [ImagenesGeoController::class, 'index'])->name('imagenes-geo.index');
        Route::post('imagenes-geo', [ImagenesGeoController::class, 'store'])->name('imagenes-geo.store');
        Route::delete('imagenes-geo/{imagen}', [ImagenesGeoController::class, 'destroy'])->name('imagenes-geo.destroy');

        // Las graficas se integraron: campo y laboratorio en Evaluaciones › Graficos; clima en Ensayos › Clima.
        Route::get('graficas', fn () => redirect()->route('investigacion.evaluaciones.index', ['tab' => 'graficos', 'vista' => 'tiempo']))->name('graficas.index');

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
        Route::put('formularios/asignaciones/{asignacion}', [FormulariosController::class, 'updateAsignacion'])->name('formularios.asignaciones.update');
        Route::post('formularios/{formulario}/mi-asignacion', [FormulariosController::class, 'miAsignacion'])->name('formularios.mi-asignacion');
        Route::get('formularios/asignaciones/{asignacion}/respuestas', [FormulariosController::class, 'respuestasAsignacion'])->name('formularios.asignaciones.respuestas');
        Route::post('formularios/asignaciones/{asignacion}/respuestas', [FormulariosController::class, 'guardarRespuestas'])->name('formularios.asignaciones.guardar');
        Route::get('formularios/{formulario}/respuestas', [FormulariosController::class, 'respuestasFormulario'])->name('formularios.respuestas');
        Route::post('formularios/respuestas/promover', [FormulariosController::class, 'promover'])->name('formularios.respuestas.promover');
        Route::delete('formularios/respuestas/{respuesta}', [FormulariosController::class, 'destroyRespuesta'])->name('formularios.respuestas.destroy');

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
