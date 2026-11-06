<?php
// Prueba de integración aislada. Nunca usa la base de datos de la aplicación.
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once getenv('IMPORTACION_SERVICE') ?: base_path('app/Services/ImportacionEnsayo.php');
require_once getenv('IMPORTACION_CONTROLLER') ?: base_path('app/Http/Controllers/Analisis/AnalisisController.php');

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Variable;
use App\Services\ImportacionEnsayo;

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== ':memory:') throw new RuntimeException('Se requiere SQLite en memoria.');
foreach (['ensayos', 'parcelas', 'tratamientos', 'variables', 'muestras_lab', 'analitos_por_tipo', 'evaluaciones', 'proyectos', 'users', 'ensayo_responsables'] as $tabla) {
    Schema::create($tabla, function (Blueprint $t) use ($tabla) {
        $t->id(); $t->timestamps();
        $campos = [
            'ensayos' => ['codigo', 'nombre', 'finca', 'proyecto_id'],
            'proyectos' => ['codigo', 'nombre'], 'users' => ['name'], 'ensayo_responsables' => ['ensayo_id', 'user_id'],
            'parcelas' => ['ensayo_id', 'tratamiento_id', 'codigo', 'repeticion', 'area', 'notas'],
            'tratamientos' => ['ensayo_id', 'codigo', 'descripcion'],
            'variables' => ['nombre', 'tipo', 'unidad', 'decimales', 'activa', 'opciones'],
            'muestras_lab' => ['ensayo_id', 'id_muestra', 'tipo', 'fecha', 'parcela_id', 'analitos', 'fecha_resultado', 'lab_muestra_id', 'lab_informe', 'resultados_lab'],
            'analitos_por_tipo' => ['tipo_muestra', 'clave', 'label', 'unidad'],
            'evaluaciones' => ['fecha', 'variable_id', 'parcela_id', 'valor', 'obs', 'responsable_id'],
        ][$tabla];
        foreach ($campos as $campo) $t->string($campo)->nullable();
    });
}
class ImportacionTestUser extends App\Models\User {
    public array $habilitadas = ['run_analysis', 'register_evaluacion', 'create_muestra', 'edit_ensayo'];
    public function hasRole($roles, string $guard = null): bool { return in_array('administrador', (array) $roles, true); }
    public function can($abilities, $arguments = []) { return in_array($abilities, $this->habilitadas, true); }
}
$usuario = new ImportacionTestUser(); $usuario->id = 1;
Auth::setUser($usuario); Gate::swap(new Illuminate\Auth\Access\Gate($app, fn () => Auth::user())); Gate::before(fn ($u, $a) => $u->can($a));
DB::table('proyectos')->insert(['id' => 1, 'codigo' => 'PR', 'nombre' => 'Proyecto de prueba']);
$ensayo = Ensayo::create(['codigo' => 'ENS-TEST', 'proyecto_id' => 1]);
$otro = Ensayo::create(['codigo' => 'OTRO', 'proyecto_id' => 1]);
$t = App\Models\Tratamiento::create(['ensayo_id' => $ensayo->id, 'codigo' => 'T1', 'descripcion' => '=No ejecutar como fórmula']);
$p = Parcela::create(['ensayo_id' => $ensayo->id, 'tratamiento_id' => $t->id, 'codigo' => 'P001', 'repeticion' => 1, 'area' => '30.00', 'notas' => 'Base']);
Parcela::create(['ensayo_id' => $otro->id, 'tratamiento_id' => $t->id, 'codigo' => 'OTRA']);
$v = Variable::create(['nombre' => 'Altura', 'tipo' => 'Numerica', 'unidad' => 'cm', 'decimales' => 2, 'activa' => true]);
$svc = new ImportacionEnsayo(); $checks = 0;
$ok = function ($cond, $msg) use (&$checks) { if (! $cond) throw new RuntimeException($msg); $checks++; };
$fila = ['ensayo' => 'ENS-TEST', 'parcela' => 'P001', 'tratamiento' => 'T1', 'repeticion' => 1, 'fecha' => '2026-11-02', 'variable' => 'Altura', 'valor' => '12,5'];
$filas = collect([collect($fila)]);
$r = $svc->procesar($ensayo, 'evaluaciones', $filas);
$ok($r['creadas'] === 1 && Evaluacion::count() === 0, 'La revisión no debe escribir.');
$svc->procesar($ensayo, 'evaluaciones', $filas, $r['firma']);
$ok(Evaluacion::count() === 1 && Evaluacion::first()->valor === '12.50', 'Debe crear y normalizar.');
$r = $svc->procesar($ensayo, 'evaluaciones', $filas);
$ok($r['sin_cambios'] === 1 && $r['creadas'] === 0, 'Reimportación sin duplicar.');
$cambio = collect([collect(array_merge($fila, ['valor' => 20]))]);
$r = $svc->procesar($ensayo, 'evaluaciones', $cambio);
$ok($r['actualizadas'] === 1, 'Debe proponer actualización.');
$svc->procesar($ensayo, 'evaluaciones', $cambio, $r['firma']);
$ok(Evaluacion::count() === 1 && Evaluacion::first()->valor === '20.00', 'Debe actualizar sin crear otro registro.');
$r = $svc->procesar($ensayo, 'evaluaciones', $filas);
Evaluacion::first()->update(['valor' => '21.00']);
try { $svc->procesar($ensayo, 'evaluaciones', $filas, $r['firma']); throw new RuntimeException('Debió rechazar revisión desactualizada.'); }
catch (Illuminate\Validation\ValidationException $e) { $checks++; }
$ok(Evaluacion::first()->valor === '21.00', 'Una revisión desactualizada no debe escribir.');
$malas = collect([
    collect(array_merge($fila, ['parcela' => 'OTRA'])), collect(array_merge($fila, ['tratamiento' => 'T9'])),
    collect(array_merge($fila, ['fecha' => '2026-02-30'])), collect(array_merge($fila, ['valor' => 'texto'])),
    collect(array_merge($fila, ['ensayo' => 'OTRO'])),
]);
$r = $svc->procesar($ensayo, 'evaluaciones', $malas);
$ok(count($r['omitidas']) === 5, 'Validación de pertenencia, fecha, tratamiento y tipo numérico.');
$r = $svc->procesar($ensayo, 'evaluaciones', collect([collect($fila), collect($fila)]));
$ok(count($r['omitidas']) === 1, 'Debe detectar duplicados en el archivo.');
$r = $svc->procesar($ensayo, 'evaluaciones', collect([collect(array_merge($fila, ['valor' => 'texto'])), collect($fila)]));
$ok(count($r['omitidas']) === 1 && $r['actualizadas'] === 1, 'Una fila inválida no debe bloquear la primera fila válida.');
$r = $svc->procesar($ensayo, 'evaluaciones', collect([collect(array_merge($fila, ['valor' => '']))]));
$ok($r['vacias'] === 1 && Evaluacion::first()->valor === '21.00', 'Vacíos no borran información.');
App\Models\AnaliticoPorTipo::create(['tipo_muestra' => 'Suelo', 'clave' => 'ph', 'label' => 'pH', 'unidad' => '']);
App\Models\AnaliticoPorTipo::create(['tipo_muestra' => 'Suelo', 'clave' => 'n', 'label' => 'N', 'unidad' => '%']);
$m = MuestraLab::create(['ensayo_id' => $ensayo->id, 'id_muestra' => 'M001', 'tipo' => 'Suelo', 'fecha' => '2026-11-02', 'parcela_id' => $p->id, 'analitos' => ['n' => '5']]);
$lab = collect([collect(['id_muestra' => 'M001', 'analito' => 'ph', 'valor' => '6.5']), collect(['id_muestra' => 'M001', 'analito' => 'n', 'valor' => '7'])]);
$r = $svc->procesar($ensayo, 'laboratorio', $lab);
$ok($r['actualizadas'] === 2 && !isset($m->fresh()->analitos['ph']), 'La revisión de laboratorio no debe escribir.');
$svc->procesar($ensayo, 'laboratorio', $lab, $r['firma']);
$ok($m->fresh()->analitos === ['n' => '7', 'ph' => '6.5'], 'Se deben conservar y combinar analitos por muestra.');
$m->update(['lab_muestra_id' => 99]);
$r = $svc->procesar($ensayo, 'laboratorio', $lab);
$ok(count($r['omitidas']) === 2 && !$r['actualizadas'], 'No sobrescribir resultados del módulo Laboratorio.');
$par = collect([collect(['parcela' => 'P001', 'area' => '40,5', 'notas' => ''])]);
$r = $svc->procesar($ensayo, 'parcelas', $par); $svc->procesar($ensayo, 'parcelas', $par, $r['firma']);
$ok($p->fresh()->area === '40.50' && $p->fresh()->notas === 'Base', 'Actualizar área y conservar notas vacías.');
$controller = new App\Http\Controllers\Analisis\AnalisisController();
$req = Illuminate\Http\Request::create('/importar/plantilla', 'GET', ['ensayo_id' => $ensayo->id, 'tipo' => 'evaluaciones', 'variable_id' => $v->id, 'fecha' => '2026-11-02']);
$response = $controller->plantilla($req);
ob_start(); $response->sendContent(); $bin = ob_get_clean();
$path = tempnam(sys_get_temp_dir(), 'importacion-') . '.xlsx'; file_put_contents($path, $bin);
$book = PhpOffice\PhpSpreadsheet\IOFactory::load($path);
$ok($book->getSheet(0)->getTitle() === 'Datos' && $book->getSheet(0)->getCell('B2')->getValue() === 'P001', 'Plantilla con parcelas reales.');
$ok($book->getSheet(0)->getCell('H2')->getValue() === '21.00', 'Plantilla con valores actuales.');
$ok($book->getSheetByName('Tratamientos')->getCell('B2')->getDataType() === 's', 'Referencias como texto, sin ejecutar fórmulas.');
$import = new App\Imports\PrimeraHojaImport(); Maatwebsite\Excel\Facades\Excel::import($import, $path);
$ok($import->hoja->filas->count() === 1 && $import->hoja->filas[0]['parcela'] === 'P001', 'Solo importar hoja Datos, no las referencias.');
unlink($path);
$diametro = Variable::create(['nombre' => 'Diámetro', 'tipo' => 'Numerica', 'unidad' => 'mm', 'decimales' => 1, 'activa' => true]);
$floracion = Variable::create(['nombre' => 'Floración', 'tipo' => 'Categorica', 'opciones' => ['Si', 'No'], 'activa' => true]);
$p2 = Parcela::create(['ensayo_id' => $ensayo->id, 'tratamiento_id' => $t->id, 'codigo' => 'P002', 'repeticion' => 2, 'area' => '30.00']);
$leerPlantilla = function (array $datos) use ($controller) {
    $response = $controller->plantilla(Illuminate\Http\Request::create('/importar/plantilla', 'GET', $datos));
    ob_start(); $response->sendContent(); $bin = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'multi-importacion-');
    file_put_contents($path, $bin);
    $book = PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    unlink($path);
    return $book;
};
$leerFilas = function ($book) {
    $path = tempnam(sys_get_temp_dir(), 'multi-importacion-') . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    $import = new App\Imports\PrimeraHojaImport();
    Maatwebsite\Excel\Facades\Excel::import($import, $path);
    unlink($path);
    return $import->hoja->filas;
};
$book = $leerPlantilla(['ensayo_id' => $ensayo->id, 'tipo' => 'evaluaciones', 'variable_ids' => [$v->id, $diametro->id, $floracion->id], 'fecha' => '2026-11-02']);
$sheet = $book->getSheet(0);
$ok($sheet->getHighestDataRow() === 7, 'Tres variables por dos parcelas en una sola plantilla.');
$ok($sheet->getCell('H2')->getValue() === '21.00', 'La plantilla múltiple conserva el valor de la variable y parcela correctas.');
for ($row = 2; $row <= 7; $row++) {
    $nombre = $sheet->getCell('F' . $row)->getValue();
    $sheet->setCellValueExplicit('H' . $row, ['Altura' => '25', 'Diámetro' => '12,34', 'Floración' => 'si'][$nombre], 's');
}
$multi = $leerFilas($book);
$r = $svc->procesar($ensayo, 'evaluaciones', $multi);
$ok($r['creadas'] === 5 && $r['actualizadas'] === 1 && !count($r['omitidas']), 'Crear y actualizar varias variables en una carga.');
$svc->procesar($ensayo, 'evaluaciones', $multi, $r['firma']);
$ok(Evaluacion::count() === 6 && Evaluacion::where('variable_id', $floracion->id)->where('valor', 'Si')->count() === 2, 'Conservar el tipo de cada variable numérica y categórica.');
$r = $svc->procesar($ensayo, 'evaluaciones', $multi);
$ok($r['sin_cambios'] === 6, 'Reimportación múltiple sin duplicar registros.');
$m->update(['lab_muestra_id' => null]);
$m2 = MuestraLab::create(['ensayo_id' => $ensayo->id, 'id_muestra' => 'M002', 'tipo' => 'Suelo', 'fecha' => '2026-11-02', 'parcela_id' => $p2->id, 'analitos' => []]);
MuestraLab::create(['ensayo_id' => $ensayo->id, 'id_muestra' => 'M003', 'tipo' => 'Suelo', 'fecha' => '2026-11-02', 'parcela_id' => $p2->id, 'lab_muestra_id' => 100]);
$book = $leerPlantilla(['ensayo_id' => $ensayo->id, 'tipo' => 'laboratorio']);
$sheet = $book->getSheet(0);
$ok($sheet->getHighestDataRow() === 5, 'Dos analitos de dos muestras en una plantilla; excluye muestras vinculadas.');
for ($row = 2; $row <= 5; $row++) $sheet->setCellValueExplicit('J' . $row, $sheet->getCell('H' . $row)->getValue() === 'ph' ? '6' : '3', 's');
$multi = $leerFilas($book);
$r = $svc->procesar($ensayo, 'laboratorio', $multi);
$ok($r['actualizadas'] === 4 && !count($r['omitidas']), 'Cargar varios analitos de varias muestras a la vez.');
$svc->procesar($ensayo, 'laboratorio', $multi, $r['firma']);
$ok($m->fresh()->analitos['ph'] === '6' && $m2->fresh()->analitos['n'] === '3', 'Resultados asociados a la muestra y analito correctos.');
$book = $leerPlantilla(['ensayo_id' => $ensayo->id, 'tipo' => 'parcelas']);
$sheet = $book->getSheet(0);
$ok($sheet->getHighestDataRow() === 3, 'Todas las parcelas en una plantilla.');
for ($row = 2; $row <= 3; $row++) { $sheet->setCellValueExplicit('E' . $row, (string) (50 + $row), 's'); $sheet->setCellValueExplicit('F' . $row, 'Nota ' . $row, 's'); }
$multi = $leerFilas($book);
$r = $svc->procesar($ensayo, 'parcelas', $multi);
$svc->procesar($ensayo, 'parcelas', $multi, $r['firma']);
$ok($r['actualizadas'] === 2 && $p->fresh()->notas === 'Nota 2' && $p2->fresh()->area === '53.00', 'Área y notas de varias parcelas en una sola aplicación.');
$usuario->habilitadas = ['run_analysis'];
try { $controller->plantilla($req); throw new RuntimeException('Debió denegar permiso de escritura.'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $ok($e->getStatusCode() === 403, 'Control de permisos por tipo.'); }
echo "OK: {$checks} comprobaciones de importación en SQLite en memoria.\n";
