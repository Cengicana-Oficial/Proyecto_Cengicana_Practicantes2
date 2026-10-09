<?php
class PermissionManager {
    private static $userPermissions = null;

    /**
     * Carga los permisos del usuario actual en la sesión.
     * Debe llamarse una vez al inicio de la sesión o en el Menu.php
     */
    public static function loadUserPermissions() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $idUsuario = $_SESSION['id_usuario'] ?? null;
        if (!$idUsuario) return;

        try {
            $conn = Conexion::conectarUsuariosMenu();

            // Rol y permisos del usuario EN ESTE MODULO (login/config/roles_modulo.php): la
            // sesion es compartida, y su rol en Cursos, Laboratorio, etc. no aplica aqui.
            require_once __DIR__ . '/../../login/config/roles_modulo.php';
            $modulo = (($_GET['modulo'] ?? '') === 'servicio' || stripos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'servicio') !== false) ? 'servicio' : 'visitas';
            cengi_rm_activar_modulo($conn, $modulo);
        } catch (Exception $e) {
            error_log("Error cargando permisos: " . $e->getMessage());
            $_SESSION['user_permissions'] = [];
        }
    }

    /**
     * Verifica si el usuario tiene un permiso específico.
     */
    public static function can($permission) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // El SuperAdmin tiene acceso total por defecto
        if (isset($_SESSION['es_superadmin']) && $_SESSION['es_superadmin'] == 1) {
            return true;
        }

        $userPermissions = $_SESSION['user_permissions'] ?? [];
        return in_array($permission, $userPermissions);
    }
}
