<?php
/**
 * Guard de acceso para el CRUD de Ingenios (login/ingenios/*.php).
 *
 * Requiere sesión activa (id_usuario) y que el usuario sea superadmin
 * o tenga el permiso 'gestionar_ingenios' en $_SESSION['user_permissions'],
 * el mismo permiso que protege el link "Ingenios" en Menu.php (menu_can()).
 *
 * Debe incluirse después de session_start() y antes de cualquier acción
 * sobre la base de datos.
 */

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login.php");
    exit;
}

$esSuperadminIngenios = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$tienePermisoIngenios = in_array('gestionar_ingenios', $_SESSION['user_permissions'] ?? [], true);

if (!$esSuperadminIngenios && !$tienePermisoIngenios) {
    http_response_code(403);
    die("Acceso restringido: no tienes permiso para gestionar ingenios.");
}
