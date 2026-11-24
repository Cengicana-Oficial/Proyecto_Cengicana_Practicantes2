<?php
/**
 * Uso de las pantallas anteriores (LABC), solo lectura y solo línea de comandos.
 *
 *   php laboratorio/tools/revisar_uso_labc.php
 *
 * Sirve para decidir si se pueden retirar el panel LABC, los formularios por análisis,
 * blancos y controles generales, y formularios con errores. Se pueden retirar si todos los
 * conteos marcados con "debe ser 0" dan 0: el flujo actual captura en hojas de trabajo
 * (lab_hoja_formulario), con blancos y controles dentro de cada hoja.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../config/roles_laboratorio.php';

$pdo = Conexion::conectar();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function rul_valor(PDO $pdo, string $sql)
{
    try {
        return $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 'no disponible (' . $e->getMessage() . ')';
    }
}

$sinHoja = 'FROM formulario f WHERE NOT EXISTS (SELECT 1 FROM lab_hoja_formulario h WHERE h.id_formulario = f.id_formulario)';
$filas = [
    ['Formularios en total', 'SELECT COUNT(*) FROM formulario', false],
    ['Formularios capturados en hojas de trabajo', 'SELECT COUNT(DISTINCT id_formulario) FROM lab_hoja_formulario', false],
    ['Formularios capturados en LABC (sin hoja)', "SELECT COUNT(*) $sinHoja", true],
    ['  de ellos aún sin aprobar ni rechazar', "SELECT COUNT(*) $sinHoja AND f.id_estado NOT IN (SELECT id_estado FROM estado_formulario
        WHERE LOWER(nombre) LIKE '%aprob%' OR LOWER(nombre) LIKE '%rechaz%')", true],
    ['Blancos generales (tabla blanco)', 'SELECT COUNT(*) FROM blanco', true],
    ['Controles generales (tabla control_laboratorio)', 'SELECT COUNT(*) FROM control_laboratorio', true],
    ['Versiones de formulario con errores', "SELECT COUNT(*) FROM formulario_version WHERE tipo_version = 'con_errores'", true],
];

echo "== Datos del laboratorio\n";
foreach ($filas as [$texto, $sql, $debeSerCero]) {
    printf("%-50s %s%s\n", $texto, rul_valor($pdo, $sql), $debeSerCero ? '   (debe ser 0)' : '');
}

// Roles que solo capturaban con el permiso antiguo: perderían la captura en la Bandeja.
echo "\n== Roles con formularios_labc.ver y sin analisis.ver (debe estar vacío)\n";
try {
    $central = lab_roles_db_central();
    $roles = $central->query("
        SELECT r.nombre_rol FROM roles r
        WHERE EXISTS (SELECT 1 FROM rol_permiso rp JOIN permisos p ON p.id = rp.permiso_id
                      WHERE rp.rol_id = r.id AND p.nombre_permiso = 'laboratorio.formularios_labc.ver')
          AND NOT EXISTS (SELECT 1 FROM rol_permiso rp JOIN permisos p ON p.id = rp.permiso_id
                      WHERE rp.rol_id = r.id AND p.nombre_permiso = 'laboratorio.analisis.ver')
    ")->fetchAll(PDO::FETCH_COLUMN);
    echo $roles ? implode("\n", $roles) . "\n" : "(ninguno)\n";
} catch (Throwable $e) {
    echo 'No se pudo consultar la base central: ' . $e->getMessage() . "\n";
}
