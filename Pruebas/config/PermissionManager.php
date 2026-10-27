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
            // El modulo es fijo: Pruebas/ es "Solicitud de visitas". No se toma de la URL, porque
            // Visitas y Servicio tecnico comparten nombres de permiso y el usuario podria cargar
            // el rol del otro modulo. Servicio tecnico aun no tiene pantallas propias (Menu.php
            // solo lo muestra al superadmin, que no depende del rol del modulo).
            cengi_rm_activar_modulo($conn, 'visitas');
        } catch (Throwable $e) {
            error_log("Error cargando permisos: " . $e->getMessage());
            $_SESSION['rol'] = $_SESSION['nombre_rol'] = '';
            $_SESSION['rol_id'] = 0;
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
