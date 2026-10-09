<?php
/**
 * roles_laboratorio.php
 *
 * Crea (si no existen) los roles del flujo de Laboratorio — Superadmin, Jefe de laboratorio,
 * Técnico, Analista y Recepcionista — y les asigna sus permisos "laboratorio.*" en rol_permiso.
 * La definición de cada rol está en laboratorio/config/roles_laboratorio.php y se consulta en
 * Laboratorio > Sistema > Roles y permisos, donde un superadmin también puede aplicarla con un
 * botón. Este script es la alternativa por CLI o navegador.
 *
 * Es idempotente (INSERT IGNORE). Por defecto SOLO AGREGA permisos; con ?sincronizar=1 (o
 * `php roles_laboratorio.php --sincronizar`) además quita a estos roles los permisos
 * "laboratorio.*" que no estén en su definición. Los permisos de otros módulos no se tocan.
 */

session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../../laboratorio/config/roles_laboratorio.php';

$esCli = PHP_SAPI === 'cli';
if (!$esCli) {
    if (empty($_SESSION['es_superadmin']) || (int) $_SESSION['es_superadmin'] !== 1) {
        http_response_code(403);
        die('Acceso restringido: este script solo puede ejecutarlo un superadmin autenticado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
$sincronizar = $esCli ? in_array('--sincronizar', $argv ?? [], true) : !empty($_GET['sincronizar']);

$resultado = lab_roles_aplicar(Conexion::conectar(), $sincronizar);

echo "Roles de Laboratorio\n====================\n";
foreach ($resultado['roles'] as $nombre => $r) {
    printf("- %s (rol #%d)%s: %d permisos definidos, %d asignados ahora%s, %d usuario(s) con acceso nuevo al módulo.\n",
        $nombre, $r['id'], $r['creado'] ? ' [CREADO]' : '', $r['definidos'], $r['nuevos'],
        $sincronizar ? ", {$r['quitados']} quitados" : '', $r['usuarios_modulo']);
}
if (!$resultado['modulo_laboratorio']) {
    echo "\nAviso: no existe el módulo 'Laboratorio' en la tabla modulos; asigne el módulo a los usuarios desde Administración.\n";
}
echo "\nListo. Los usuarios deben cerrar e iniciar sesión para que se apliquen los permisos.\n";
