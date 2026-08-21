<?php
/**
 * migrar_permisos_sigec.php
 *
 * Backfill UNICO (idempotente, usa INSERT IGNORE) que otorga en la tabla
 * central rol_permiso el permiso "sigec.acceder" a los roles que deberian
 * tener acceso al modulo SIGEC por defecto al momento de introducirlo:
 * Superadmin (rol_id 1) y Administrador (rol_id 2). El resto de roles
 * (Instructor, Estudiante, Gestor, Administrador de ingenio, y cualquier
 * rol nuevo) NO reciben el permiso por defecto — se asignan a mano desde
 * login/roles/roles.php si un caso concreto lo requiere.
 *
 * CUANDO EJECUTARLO
 * ------------------
 * Una sola vez, por un superadmin, DESPUES de desplegar el sembrado del
 * permiso "sigec.acceder" en login/config/permisos_roles.php
 * (funcion sembrar_permisos_base()).
 *
 * Es seguro volver a ejecutarlo: usa INSERT IGNORE, así que no duplica
 * filas ni sobreescribe permisos ya asignados manualmente desde la UI de
 * Roles. NO revoca permisos existentes.
 *
 * COMO EJECUTARLO
 * ----------------
 * - Via navegador (con sesion de superadmin activa en login/): abrir
 *   login/config/migrar_permisos_sigec.php directamente.
 * - Via CLI (no requiere sesion de superadmin, pero se recomienda solo
 *   correrlo asi en un entorno controlado): `php migrar_permisos_sigec.php`
 *
 * Este script NO debe quedar enlazado desde ningun menu; es una
 * herramienta de mantenimiento de un solo uso por ambiente.
 */

session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_roles.php';

$esCli = PHP_SAPI === 'cli';

if (!$esCli) {
    if (empty($_SESSION['es_superadmin']) || (int) $_SESSION['es_superadmin'] !== 1) {
        http_response_code(403);
        die("Acceso restringido: este script solo puede ejecutarlo un superadmin autenticado.");
    }

    header('Content-Type: text/plain; charset=utf-8');
}

// Roles del compendio que reciben sigec.acceder por defecto. Ver
// sigec/config/sso.php::role_map para el mapeo correspondiente de estos
// mismos rol_id a un rol spatie dentro de SIGEC.
$rolesConAccesoPorDefecto = [1, 2];

$conn = Conexion::conectar();

// Asegura que las tablas permisos/rol_permiso existan y que el catalogo
// completo (incluyendo "sigec.acceder") este sembrado antes de asignarlo.
asegurar_tablas_permisos($conn);

$stmtRoles = $conn->query("SELECT id, nombre_rol FROM roles ORDER BY id");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

$insert = $conn->prepare("
    INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
    SELECT ?, id
    FROM permisos
    WHERE nombre_permiso = 'sigec.acceder'
");

$totalAsignaciones = 0;
$resumen = [];

foreach ($roles as $rol) {
    $rolId = (int) $rol['id'];

    if (!in_array($rolId, $rolesConAccesoPorDefecto, true)) {
        $resumen[] = sprintf("  - Rol #%d (%s): sin cambios (no esta en la lista de acceso por defecto).", $rolId, $rol['nombre_rol']);
        continue;
    }

    $insert->execute([$rolId]);
    $insertado = $insert->rowCount() > 0;

    if ($insertado) {
        $totalAsignaciones++;
    }

    $resumen[] = sprintf(
        "  - Rol #%d (%s): %s",
        $rolId,
        $rol['nombre_rol'],
        $insertado ? 'sigec.acceder asignado.' : 'sigec.acceder ya estaba asignado.'
    );
}

echo "Backfill de permisos de SIGEC (rol_permiso) completado.\n";
echo "Roles procesados: " . count($roles) . "\n";
echo "Filas nuevas insertadas en total: {$totalAsignaciones}\n\n";
echo "Detalle por rol:\n";
echo implode("\n", $resumen) . "\n";
