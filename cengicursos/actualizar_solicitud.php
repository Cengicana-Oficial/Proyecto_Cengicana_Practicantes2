<?php
/**
 * Guarda la corrección de una solicitud directa pendiente (ventana "Corregir" de solicitudes.php).
 * Solo solicitudes sin planificación; el curso debe ser uno creado sin planificación.
 */
require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_editar_solicitudes('solicitudes.php');

$db = conectar();
$id = (int) ($_POST['id_solicitud'] ?? 0);

function cengi_sol_volver($resultado, $id, $mensaje = '', $editar = false)
{
    $q = ['resultado' => $resultado, 'id' => $id];
    if ($mensaje !== '') {
        $q['mensaje'] = $mensaje;
    }
    if ($editar) {
        $q['editar'] = $id;
    }
    header('Location: solicitudes.php?' . http_build_query($q) . '#sol-' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id <= 0) {
    header("Location: solicitudes.php");
    exit;
}

$stmtActual = $db->prepare("
    SELECT s.id_solicitud, s.estado, s.planificacion_id, s.cui_participante, s.ingenio_id, i.nombre_ingenios
    FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON i.id = s.ingenio_id
    WHERE s.id_solicitud = ?
    LIMIT 1
");
$stmtActual->execute([$id]);
$actual = $stmtActual->fetch(PDO::FETCH_ASSOC);

if (!$actual || $actual['estado'] !== 'Pendiente' || !empty($actual['planificacion_id'])) {
    cengi_sol_volver('error', $id, 'Solo se pueden corregir solicitudes directas pendientes.');
}

if (
    !cengi_ve_todo_por_rol_o_ingenio() &&
    cengi_texto_normalizado($actual['nombre_ingenios'] ?? '') !== cengi_texto_normalizado(cengi_ingenio_nombre_actual())
) {
    cengi_sol_volver('error', $id, 'No puedes gestionar solicitudes de otro ingenio.');
}

$texto = static function ($campo, $max = 255) {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$campo] ?? ''))), 0, $max, 'UTF-8');
};
$datos = [
    'nombre_participante' => $texto('nombre_participante'),
    'cui_participante' => $texto('cui_participante', 20) ?: (string) $actual['cui_participante'],
    'puesto_participante' => $texto('puesto_participante'),
    'area_participante' => $texto('area_participante'),
    'grado_academico' => $texto('grado_academico'),
    'correo' => mb_strtolower($texto('correo'), 'UTF-8'),
    'telefono' => $texto('telefono', 50),
    'ingenio_id' => cengi_ve_todo_por_rol_o_ingenio() ? (int) ($_POST['ingenio_id'] ?? 0) : (int) $actual['ingenio_id'],
    'curso_id' => (int) ($_POST['curso_id'] ?? 0),
    'tipo_pago' => in_array($_POST['tipo_pago'] ?? '', ['Ingenio', 'Propio'], true) ? $_POST['tipo_pago'] : '',
];

$errores = [];
foreach (['nombre_participante' => 'el nombre', 'puesto_participante' => 'el puesto', 'area_participante' => 'el área', 'grado_academico' => 'el grado académico', 'telefono' => 'el teléfono'] as $campo => $nombre) {
    if ($datos[$campo] === '') {
        $errores[] = 'Falta ' . $nombre . '.';
    }
}
if (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'El correo no es válido.';
}
if ($datos['tipo_pago'] === '') {
    $errores[] = 'Elige quién paga.';
}
$existe = $db->prepare('SELECT COUNT(*) FROM ingenios WHERE id = ?');
$existe->execute([$datos['ingenio_id']]);
if (!$existe->fetchColumn()) {
    $errores[] = 'Elige un ingenio válido.';
}
// El curso debe existir y no venir de una planificación (esas se inscriben por convocatoria).
$curso = $db->prepare('SELECT c.id, EXISTS (SELECT 1 FROM planificacion_cursos p WHERE p.curso_id = c.id) AS de_plan FROM cursos c WHERE c.id = ?');
$curso->execute([$datos['curso_id']]);
$curso = $curso->fetch(PDO::FETCH_ASSOC);
if (!$curso) {
    $errores[] = 'Elige un curso válido.';
} elseif ($curso['de_plan']) {
    $errores[] = 'Ese curso viene de una planificación: la persona debe inscribirse por su convocatoria.';
}
if ($errores) {
    cengi_sol_volver('error', $id, implode(' ', $errores), true);
}

$columnas = implode(', ', array_map(static function ($c) {
    return $c . ' = ?';
}, array_keys($datos)));
$db->prepare("UPDATE solicitudes_inscripcion SET {$columnas} WHERE id_solicitud = ? AND estado = 'Pendiente'")
    ->execute(array_merge(array_values($datos), [$id]));

cengi_sol_volver('editada', $id);
