<?php
require_once __DIR__ . '/../../../config/auth.php';
require_login();
if (!can_access('gestionar_solicitudes')) {
    http_response_code(403);
    exit('No tienes permiso para modificar solicitudes.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_SESSION['visits_csrf'])
    || !hash_equals($_SESSION['visits_csrf'], (string) ($_POST['csrf'] ?? '')))) {
    http_response_code(403);
    exit('La sesión de edición expiró.');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* verificar login */
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit;
}

/* verificar envío */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../superadmin.php?modulo=solicitudes");
    exit;
}

$conn = conexion::conectar();

/* datos recibidos */
$id_solicitud = $_POST['id_solicitud'] ?? null;
$id_estado    = $_POST['id_estado'] ?? null;

if (!$id_solicitud || !$id_estado) {
    header("Location: ../superadmin.php?modulo=solicitudes");
    exit;
}

/* aprobador logueado */
$id_aprobador = $_SESSION['usuario']['id_usuario'] ?? null;

/*
=====================================
ACTUALIZAR ESTADO GENERAL
=====================================
*/
$stmt = $conn->prepare("
    UPDATE solicitudes
    SET id_estado = ?
    WHERE id_solicitud = ?
");
$stmt->execute([$id_estado, $id_solicitud]);

/*
=====================================
GUARDAR APROBADOR
SOLO SI ESTÁ APROBADO (id_estado = 2)
=====================================
*/
if ($id_estado == 2 && $id_aprobador) {

    /* verificar si ya aprobó antes */
    $check = $conn->prepare("
        SELECT COUNT(*) 
        FROM aprobacion_solicitud
        WHERE id_solicitud = ?
        AND id_aprobador = ?
    ");

    $check->execute([$id_solicitud, $id_aprobador]);

    $existe = $check->fetchColumn();

    /* si no existe, insertar */
    if ($existe == 0) {

        $insert = $conn->prepare("
            INSERT INTO aprobacion_solicitud
            (
                id_solicitud,
                id_aprobador,
                id_estado,
                fecha_aprobacion
            )
            VALUES (?, ?, ?, NOW())
        ");

        $insert->execute([
            $id_solicitud,
            $id_aprobador,
            $id_estado
        ]);
    }
}

header("Location: ../superadmin.php?modulo=solicitudes");
exit;
?>