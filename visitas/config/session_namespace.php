<?php
declare(strict_types=1);
// Cookie aislada: la identidad y el rol se vuelven a validar en cada petición privada.
if (session_status() !== PHP_SESSION_NONE) return;
require_once dirname(__DIR__, 2) . '/login/config/session.php';
require_once dirname(__DIR__, 2) . '/login/config/visitas_access.php';

$privateRequest = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/visitas/public/admin/');
if ($privateRequest) {
    session_name('PHPSESSID');
    session_id($_COOKIE['PHPSESSID'] ?? '');
    cengi_session_start();
    $portalId = (int) ($_SESSION['id_usuario'] ?? 0);
    session_write_close();
    $actor = null;
    if ($portalId > 0) {
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            $env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__, 2) . '/login/.env'));
            $db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env['DB_HOST'], $env['DB_PORT'] ?? '3306', $env['DB_NAME']),
                $env['DB_USER'], $env['DB_PASS'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4]);
            $actor = visitas_actor($db, $portalId);
        } catch (Throwable $error) {
            error_log('Visitas portal: ' . $error->getMessage());
            http_response_code(503);
            exit('No fue posible validar el acceso a Visitas. Inténtalo de nuevo.');
        }
    }
    if ($actor && $actor['role_code'] === 'gestor_pagos') {
        $route = substr((string) $_SERVER['SCRIPT_NAME'], strpos((string) $_SERVER['SCRIPT_NAME'], '/visitas/public/admin/') + strlen('/visitas/public/admin/'));
        if ($route === 'dashboard_unificado.php' && ($_GET['vista'] ?? '') === 'reportes' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            header('Location: /visitas/public/admin/dashboard_reportes.php', true, 303);
            exit;
        }
        if (!in_array($route, ['dashboard_reportes.php', 'dashboard_pago.php', 'actualizar_pago.php',
            'gestionar_pago_visita.php', 'gestionar_comprobante_pago.php', 'ver_comprobante_pago.php', 'logout.php'], true)) {
            http_response_code(403);
            exit('El Gestor de Pagos solo tiene acceso a Reportes y Pagos.');
        }
    }
    if (!$actor) {
        http_response_code(403);
        exit('Tu sesión no tiene acceso a Visitas. Regresa al menú de módulos.');
    }
}
session_name('CENGISALAS_VISIT');
session_id($_COOKIE['CENGISALAS_VISIT'] ?? '');
if ($privateRequest) {
    cengi_session_start();
    if ((int) ($_SESSION['portal_user_id'] ?? 0) !== $portalId) {
        $_SESSION = [];
    }
    $_SESSION['visitas_role'] = $actor['role_code'];
    $_SESSION['rol'] = $actor['role_code'];
    $_SESSION['es_superadmin'] = (int) $actor['es_superadmin'];
    $_SESSION['user_permissions'] = visitas_permissions($actor['role_code']);
    $_SESSION['module_permissions_loaded'] = true;
    $_SESSION['nombre'] = $actor['nombre'];
    $_SESSION['correo'] = $actor['correo'];
    $_SESSION['email'] = $actor['correo'];
}
