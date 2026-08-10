<?php
/**
 * Guard de acceso para el CRUD de Módulos (login/modulos/*.php).
 *
 * Requiere sesión activa (id_usuario) y que el usuario sea superadmin
 * o tenga el permiso 'gestionar_modulos' en $_SESSION['user_permissions'],
 * el mismo permiso que protege el link "Módulos" en Menu.php (menu_can()).
 *
 * Antes de este archivo, modulos.php/crear_modulo.php/editar_modulo.php y
 * eliminar_modulo.php no verificaban sesión ni permisos: cualquier persona
 * con la URL directa podía crear, editar o eliminar módulos del sistema.
 * Este guard cierra esa laguna siguiendo el mismo patrón ya usado en
 * login/ingenios/_guard.php.
 *
 * Debe incluirse después de session_start() y antes de cualquier acción
 * sobre la base de datos.
 */

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login.php");
    exit;
}

$esSuperadminModulos = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$tienePermisoModulos = in_array('gestionar_modulos', $_SESSION['user_permissions'] ?? [], true);

if (!$esSuperadminModulos && !$tienePermisoModulos) {
    http_response_code(403);
    die("Acceso restringido: no tienes permiso para gestionar módulos.");
}
