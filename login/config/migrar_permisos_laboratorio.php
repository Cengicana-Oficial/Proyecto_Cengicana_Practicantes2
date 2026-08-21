<?php
/**
 * migrar_permisos_laboratorio.php
 *
 * Backfill UNICO (idempotente, usa INSERT IGNORE) que otorga en la tabla
 * central rol_permiso los permisos "laboratorio.*" que cada rol existente
 * tenia HOY de forma implicita, calculados por el antiguo fallback
 * hardcodeado laboratorio/includes/auth.php::lab_default_permissions_for_role()
 * (por rol_id 1-4 y coincidencia de substrings en el nombre del rol:
 * superadmin/jefa/administrador -> todos los permisos de laboratorio,
 * tecnico -> su subconjunto, analista/laboratorista -> su subconjunto,
 * recepcion -> su subconjunto).
 *
 * CUANDO EJECUTARLO
 * ------------------
 * Una sola vez, por un superadmin, DESPUES de desplegar el sembrado de los
 * 11 permisos "laboratorio.*" faltantes en login/config/permisos_roles.php
 * (funcion sembrar_permisos_base()), y junto con / antes del deploy del
 * cambio que elimina el fallback hardcodeado en tiempo de ejecucion de
 * laboratorio/includes/auth.php (lab_user_permissions() ahora solo lee de
 * $_SESSION['user_permissions'], sin fallback). Sin este backfill, los
 * roles que hoy dependen del fallback hardcodeado se quedarian sin acceso
 * a Laboratorio el dia del deploy.
 *
 * Es seguro volver a ejecutarlo: usa INSERT IGNORE, así que no duplica
 * filas ni sobreescribe permisos ya asignados manualmente desde la UI de
 * Roles. NO revoca permisos existentes.
 *
 * COMO EJECUTARLO
 * ----------------
 * - Via navegador (con sesion de superadmin activa en login/): abrir
 *   login/config/migrar_permisos_laboratorio.php directamente.
 * - Via CLI (no requiere sesion de superadmin, pero se recomienda solo
 *   correrlo asi en un entorno controlado): `php migrar_permisos_laboratorio.php`
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

/**
 * Normaliza un nombre de rol igual que
 * laboratorio/includes/auth.php::lab_normalized_role(), pero como funcion
 * pura sobre un string (esa version depende de la sesion del usuario
 * actual, no sirve para recorrer todos los roles de la tabla).
 */
function migrar_lab_normalizar_rol($nombreRol)
{
    $rol = strtolower(trim((string) $nombreRol));

    $from = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'];
    $to = ['a', 'e', 'i', 'o', 'u', 'u', 'n'];
    $rol = str_replace($from, $to, $rol);

    if (function_exists('iconv')) {
        $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rol);
        if ($normalizado !== false) {
            $rol = strtolower($normalizado);
        }
    }

    return strtr($rol, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
}

/**
 * Replica exacta del criterio que tenia
 * laboratorio/includes/auth.php::lab_default_permissions_for_role() para
 * decidir, por rol_id/nombre de rol, que permisos "laboratorio.*" le
 * correspondian por defecto. Se mantiene aqui, en el script de backfill,
 * como el unico lugar donde este criterio historico sigue vivo (el
 * fallback en tiempo de ejecucion ya fue retirado de auth.php).
 */
function migrar_lab_permisos_por_defecto($rolId, $nombreRol)
{
    $rolId = (int) $rolId;
    $rol = migrar_lab_normalizar_rol($nombreRol);

    if (
        $rolId === 1
        || strpos($rol, 'superadmin') !== false
        || strpos($rol, 'jefa') !== false
        || $rolId === 2
        || strpos($rol, 'administrador') !== false
        || $rol === 'admin'
    ) {
        return lab_all_permissions();
    }

    if ($rolId === 3 || strpos($rol, 'tecnico') !== false) {
        return [
            'laboratorio.acceder',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.analisis.crear',
            'laboratorio.analisis.editar',
            'laboratorio.formularios_pendientes.ver',
            'laboratorio.kanban.ver',
            'laboratorio.validacion_tecnica.ver',
            'laboratorio.validacion_tecnica.aprobar',
            'laboratorio.trazabilidad.ver',
            'laboratorio.documentos.ver',
            'laboratorio.firma.gestionar',
            'laboratorio.informes.ver',
        ];
    }

    if ($rolId === 4 || strpos($rol, 'analista') !== false || strpos($rol, 'laboratorista') !== false) {
        return [
            'laboratorio.acceder',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.analisis.crear',
            'laboratorio.analisis.editar',
            'laboratorio.kanban.ver',
            'laboratorio.trazabilidad.ver',
        ];
    }

    if (strpos($rol, 'recepcion') !== false) {
        return [
            'laboratorio.acceder',
            'laboratorio.solicitudes.ver',
            'laboratorio.solicitudes.crear',
            'laboratorio.solicitudes.editar',
            'laboratorio.solicitudes.editar.foliar',
            'laboratorio.solicitudes.editar.suelo',
            'laboratorio.solicitudes.editar.aguas',
            'laboratorio.solicitudes.editar.cana',
            'laboratorio.lotes.ver',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.consolidacion.ver',
            'laboratorio.consolidacion.aprobar',
            'laboratorio.formularios_erroneos.ver',
            'laboratorio.formularios.guardar_corregidos',
            'laboratorio.formularios.guardar_errores',
            'laboratorio.trazabilidad.ver',
            'laboratorio.documentos.ver',
            'laboratorio.documentos.gestionar',
            'laboratorio.informes.ver',
        ];
    }

    // Cualquier otro rol solo tenia acceso minimo al modulo.
    return ['laboratorio.acceder'];
}

// Catalogo local: no depende de laboratorio/includes/auth.php para que este
// script de mantenimiento en login/ no cargue codigo de otro modulo.
if (!function_exists('lab_all_permissions')) {
    function lab_all_permissions()
    {
        return [
            'laboratorio.acceder',
            'laboratorio.solicitudes.ver',
            'laboratorio.solicitudes.crear',
            'laboratorio.solicitudes.editar',
            'laboratorio.solicitudes.editar.foliar',
            'laboratorio.solicitudes.editar.suelo',
            'laboratorio.solicitudes.editar.aguas',
            'laboratorio.solicitudes.editar.cana',
            'laboratorio.lotes.ver',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.analisis.crear',
            'laboratorio.analisis.editar',
            'laboratorio.analisis.editar_finalizado',
            'laboratorio.blanco_control.ver',
            'laboratorio.blanco_control.gestionar',
            'laboratorio.consolidacion.ver',
            'laboratorio.consolidacion.aprobar',
            'laboratorio.formularios_pendientes.ver',
            'laboratorio.formularios_erroneos.ver',
            'laboratorio.formularios.guardar_corregidos',
            'laboratorio.formularios.guardar_errores',
            'laboratorio.usuarios.gestionar',
            'laboratorio.roles.gestionar',
            'laboratorio.configuracion.gestionar',
            'laboratorio.kanban.ver',
            'laboratorio.kanban.gestionar',
            'laboratorio.validacion_tecnica.ver',
            'laboratorio.validacion_tecnica.aprobar',
            'laboratorio.trazabilidad.ver',
            'laboratorio.documentos.ver',
            'laboratorio.documentos.gestionar',
            'laboratorio.firma.gestionar',
            'laboratorio.informes.ver',
        ];
    }
}

$conn = Conexion::conectar();

// Asegura que las tablas permisos/rol_permiso existan y que el catalogo
// completo (incluyendo los 11 permisos "laboratorio.*" recien agregados)
// este sembrado antes de intentar asignarlos a los roles.
asegurar_tablas_permisos($conn);

$stmtRoles = $conn->query("SELECT id, nombre_rol FROM roles ORDER BY id");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

$insert = $conn->prepare("
    INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
    SELECT ?, id
    FROM permisos
    WHERE nombre_permiso = ?
");

$totalAsignaciones = 0;
$resumen = [];

foreach ($roles as $rol) {
    $rolId = (int) $rol['id'];
    $permisos = migrar_lab_permisos_por_defecto($rolId, $rol['nombre_rol']);

    $asignadosParaEsteRol = 0;
    foreach ($permisos as $permiso) {
        $insert->execute([$rolId, $permiso]);
        if ($insert->rowCount() > 0) {
            $asignadosParaEsteRol++;
        }
    }

    $totalAsignaciones += $asignadosParaEsteRol;
    $resumen[] = sprintf(
        "  - Rol #%d (%s): %d permiso(s) de laboratorio evaluados, %d fila(s) nueva(s) insertada(s).",
        $rolId,
        $rol['nombre_rol'],
        count($permisos),
        $asignadosParaEsteRol
    );
}

echo "Backfill de permisos de Laboratorio (rol_permiso) completado.\n";
echo "Roles procesados: " . count($roles) . "\n";
echo "Filas nuevas insertadas en total: {$totalAsignaciones}\n\n";
echo "Detalle por rol:\n";
echo implode("\n", $resumen) . "\n";
