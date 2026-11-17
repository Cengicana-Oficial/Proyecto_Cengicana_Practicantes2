<?php
// Integration isolée: SQLite en memoria, sin alterar la base de la aplicación.
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fuentes = getenv('REPORTES_SOURCE') ?: base_path('app');
require_once $fuentes . '/Exports/ReporteExcel.php';
foreach (['EvaluacionesExport', 'MuestrasLabExport', 'EnsayosExport', 'BitacoraExport', 'ParcelasExport', 'NdviExport'] as $clase) require_once $fuentes . '/Exports/' . $clase . '.php';
require_once getenv('REPORTES_CONTROLLER') ?: base_path('app/Http/Controllers/Soporte/ReportesController.php');
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\Parcela;
use App\Models\MuestraLab;

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== ':memory:') throw new RuntimeException('Solo SQLite en memoria.');
$campos = [
    'users' => ['name'], 'programas' => ['sigla', 'nombre', 'color'], 'proyectos' => ['programa_id', 'codigo', 'nombre'],
    'programa_usuario' => ['programa_id', 'usuario_id'], 'proyecto_asignaciones' => ['proyecto_id', 'usuario_id'], 'ensayo_responsables' => ['ensayo_id', 'user_id', 'orden'], 'ingenios' => ['nombre'],
    'ensayos' => ['proyecto_id', 'ingenio_id', 'codigo', 'nombre', 'estado', 'objetivo', 'diseno', 'cultivo', 'variedad', 'ciclo', 'fecha_siembra', 'fecha_cosecha_prevista', 'fecha_cosecha', 'finca', 'lote', 'estrato', 'zona', 'area_total', 'area_parcela', 'num_repeticiones'],
    'tratamientos' => ['ensayo_id', 'codigo', 'descripcion'], 'parcelas' => ['ensayo_id', 'tratamiento_id', 'codigo', 'repeticion', 'area', 'notas'],
    'variables' => ['nombre', 'unidad', 'tipo', 'categoria', 'decimales'],
    'evaluaciones' => ['fecha', 'parcela_id', 'variable_id', 'valor', 'obs', 'responsable_id'],
    'muestras_lab' => ['ensayo_id', 'proyecto_id', 'tratamiento_id', 'parcela_id', 'solicitante_id', 'id_muestra', 'tipo', 'fecha', 'estado', 'analitos', 'resultados_lab', 'lab_codigo', 'lab_informe', 'lab_estado', 'lab_firmado_por', 'analistas', 'fecha_resultado', 'resultado_texto', 'obs', 'repeticion'],
    'analitos_por_tipo' => ['tipo_muestra', 'clave', 'label', 'unidad'], 'disenos_experimentales' => ['nombre', 'usa_bloques'],
    'ndvi_imagenes' => ['ensayo_id', 'fecha', 'fuente', 'valores', 'nombre'], 'bitacora' => ['ensayo_id', 'fecha', 'tipo', 'descripcion', 'responsable_id'],
];
foreach ($campos as $tabla => $columnas) Schema::create($tabla, function (Blueprint $t) use ($columnas) { $t->id(); $t->timestamps(); foreach ($columnas as $c) { if (str_ends_with($c, '_id') || in_array($c, ['repeticion', 'orden', 'decimales', 'num_repeticiones', 'usa_bloques'])) $t->integer($c)->nullable(); else $t->text($c)->nullable(); } });
class ReportesTestUser extends App\Models\User {
    public bool $admin = true; public bool $ver = true;
    public function hasRole($roles, string $guard = null): bool { return $this->admin && in_array('administrador', (array) $roles, true); }
    public function can($abilities, $arguments = []) { return $this->ver && $abilities === 'view_reports_menu'; }
}
$u = new ReportesTestUser(); $u->id = 1; $u->name = 'Prueba'; Auth::setUser($u);
DB::table('users')->insert(['id' => 1, 'name' => 'Prueba']);
DB::table('programas')->insert([['id' => 1, 'sigla' => 'A', 'nombre' => 'Programa A'], ['id' => 2, 'sigla' => 'B', 'nombre' => 'Programa B']]);
DB::table('proyectos')->insert([['id' => 1, 'programa_id' => 1, 'codigo' => 'PA', 'nombre' => 'Proyecto A'], ['id' => 2, 'programa_id' => 2, 'codigo' => 'PB', 'nombre' => 'Proyecto B']]);
DB::table('programa_usuario')->insert(['programa_id' => 1, 'usuario_id' => 1]);
$e = Ensayo::create(['codigo' => 'VISIBLE', 'nombre' => 'Ensayo A', 'proyecto_id' => 1, 'estado' => 'Activo', 'diseno' => 'Bloques', 'num_repeticiones' => 2, 'fecha_siembra' => '2026-01-01']);
$oculto = Ensayo::create(['codigo' => 'NO-VISIBLE', 'proyecto_id' => 2]);
$asignado = Ensayo::create(['codigo' => 'ASIGNADO', 'proyecto_id' => 2]);
DB::table('ensayo_responsables')->insert(['ensayo_id' => $asignado->id, 'user_id' => 1, 'orden' => 0]);
DB::table('disenos_experimentales')->insert(['nombre' => 'Bloques', 'usa_bloques' => 1]);
$v = App\Models\Variable::create(['nombre' => 'Altura', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'unidad' => 'cm', 'decimales' => 2]);
$parcelas = [];
foreach (['T1', 'T2'] as $ti => $codigo) {
    $t = App\Models\Tratamiento::create(['ensayo_id' => $e->id, 'codigo' => $codigo, 'descripcion' => '=2+2']);
    foreach ([1, 2] as $rep) {
        $p = Parcela::create(['ensayo_id' => $e->id, 'tratamiento_id' => $t->id, 'codigo' => $codigo . '-P' . $rep, 'repeticion' => $rep, 'area' => 30]); $parcelas[] = $p;
        foreach (['2026-10-01', '2026-11-01'] as $fecha) Evaluacion::create(['fecha' => $fecha, 'parcela_id' => $p->id, 'variable_id' => $v->id, 'valor' => (string) (10 + $ti * 8 + $rep * 2), 'responsable_id' => 1]);
    }
}
$pOculta = Parcela::create(['ensayo_id' => $oculto->id, 'codigo' => 'SECRETA']);
Evaluacion::create(['fecha' => '2026-11-01', 'parcela_id' => $pOculta->id, 'variable_id' => $v->id, 'valor' => '999']);
App\Models\AnaliticoPorTipo::create(['tipo_muestra' => 'Suelo', 'clave' => 'ph', 'label' => 'pH', 'unidad' => '']);
MuestraLab::create(['ensayo_id' => $e->id, 'proyecto_id' => 1, 'parcela_id' => $parcelas[0]->id, 'tratamiento_id' => $parcelas[0]->tratamiento_id, 'fecha' => '2026-11-01', 'id_muestra' => 'M001', 'tipo' => 'Suelo', 'estado' => 'Completado', 'analitos' => ['ph' => '6.5'], 'solicitante_id' => 1]);
foreach (['2026-10-01', '2026-11-01'] as $fecha) {
    App\Models\NdviImagen::create(['ensayo_id' => $e->id, 'fecha' => $fecha, 'fuente' => 'Prueba', 'nombre' => 'Imagen', 'valores' => [$parcelas[0]->id => ['media' => 0, 'min' => 0, 'max' => 0, 'n' => 10]]]);
    App\Models\Bitacora::create(['ensayo_id' => $e->id, 'fecha' => $fecha, 'tipo' => 'Riego', 'descripcion' => 'Actividad', 'responsable_id' => 1]);
}
$c = new App\Http\Controllers\Soporte\ReportesController(); $checks = 0;
$ok = function ($cond, $msg) use (&$checks) { if (!$cond) throw new RuntimeException($msg); $checks++; };
$u->admin = false;
$datos = $c->index()->getData()['params'];
$ok($datos['ensayos']->count() === 2, 'Respeta programas asignados y responsabilidad directa.');
$ok($datos['programas']->count() === 2 && $datos['proyectos']->count() === 2, 'Filtros incluyen los padres de ensayos por responsabilidad directa.');
$q = ['programa_id' => 1, 'proyecto_id' => 1, 'ensayo_id' => $e->id, 'desde' => '2026-11-01', 'hasta' => '2026-11-01'];
$req = fn ($d) => Request::create('/soporte/reportes', 'GET', $d);
$leerExcel = function ($response) use ($ok) {
    $path = $response->getFile()->getPathname();
    $book = PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $s = $book->getSheet(0);
    $ok($s->getFreezePane() === 'A2' && $s->getAutoFilter()->getRange() !== '', 'Encabezados fijos y filtros de Excel.');
    unlink($path);
    return $s;
};
$s = $leerExcel($c->exportarEvaluaciones($req($q)));
$ok($s->getHighestDataRow() === 6, 'Exporta cuatro evaluaciones y un resultado de laboratorio en el periodo. Filas: ' . json_encode($s->toArray()));
$filas = $s->toArray();
$ok(count(array_filter(array_slice($filas, 1), fn ($r) => $r[0] === '2026-11-01' && $r[1] === 'VISIBLE')) === 5, 'Fechas inclusivas y ensayo correcto en campo y laboratorio.');
$s = $leerExcel($c->exportarMuestrasLab($req($q)));
$ok($s->getHighestDataRow() === 2 && str_contains($s->getCell('O2')->getValue(), '6.5'), 'Muestras con resultados reales.');
$s = $leerExcel($c->exportarEnsayos($req($q)));
$ok($s->getHighestDataRow() === 2 && $s->getCell('C2')->getValue() === 'VISIBLE', 'Cartera filtrada por programa, proyecto y ensayo.');
$s = $leerExcel($c->exportarParcelas($req($q)));
$ok($s->getHighestDataRow() === 5 && $s->getCell('F2')->getDataType() === 's', 'Parcelas con descripciones como texto, no fórmulas.');
$s = $leerExcel($c->exportarBitacora($req($q)));
$ok($s->getHighestDataRow() === 2 && $s->getCell('A2')->getValue() === '2026-11-01', 'Bitácora filtrada por periodo.');
$s = $leerExcel($c->exportarNdvi($req($q)));
$ok($s->getHighestDataRow() === 2 && (float) $s->getCell('H2')->getValue() === 0.0, 'NDVI filtra por fecha y conserva el cero.');
$s = $leerExcel($c->exportarEvaluaciones($req(['ensayo_id' => $oculto->id])));
$ok($s->getHighestDataRow() === 1, 'No exportar un ensayo oculto aunque se envíe su ID.');
try { $c->exportarEvaluaciones($req(['desde' => '2026-02-30'])); throw new RuntimeException('Debió rechazar fecha inválida.'); } catch (Illuminate\Validation\ValidationException $ex) { $checks++; }
try { $c->exportarNdvi($req(['desde' => '2026-11-02', 'hasta' => '2026-11-01'])); throw new RuntimeException('Debió rechazar periodo invertido.'); } catch (Illuminate\Validation\ValidationException $ex) { $checks++; }
$informe = $c->informe($req($q), $e, new App\Services\ResultadosLaboratorio())->getData();
$ok($informe['component'] === 'informe-ensayo', 'Informe conectado al componente correcto.');
$info = $informe['params'];
$ok($info['registros']->count() === 5 && $info['bitacora']->count() === 1 && $info['ndvi']->count() === 1 && (int) $info['muestras'][0]->n === 1, 'El informe filtra todos sus apartados por fecha.');
$ok($info['ensayo']['usa_bloques'] === true, 'El informe usa el diseño del catálogo.');
try { $c->informe($req([]), $oculto, new App\Services\ResultadosLaboratorio()); throw new RuntimeException('Debió denegar informe oculto.'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $ex) { $ok($ex->getStatusCode() === 403, 'Informe sujeto a visibilidad.'); }
$u->ver = false;
foreach (['index', 'exportarEvaluaciones', 'exportarMuestrasLab', 'exportarEnsayos', 'exportarParcelas', 'exportarBitacora', 'exportarNdvi'] as $metodo) {
    try { $metodo === 'index' ? $c->$metodo() : $c->$metodo($req([])); throw new RuntimeException('Debió denegar acceso sin permiso.'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $ex) { $ok($ex->getStatusCode() === 403, 'Permiso de reportes en ' . $metodo); }
}
echo "OK: {$checks} comprobaciones de reportes con SQLite en memoria.\n";
