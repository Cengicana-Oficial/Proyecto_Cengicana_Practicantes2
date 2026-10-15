<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/login/config/conexion.php';
$apply = ($argv[1] ?? '--check') === '--apply';
$db = Conexion::conectar();
$names = "('visitas','visitas cengisalas','solicitud de visitas')";
$modules = $db->query("SELECT id,nombre FROM modulos WHERE LOWER(nombre) IN $names ORDER BY CASE WHEN LOWER(nombre)='visitas' THEN 0 WHEN LOWER(nombre)='visitas cengisalas' THEN 1 ELSE 2 END,id")->fetchAll(PDO::FETCH_ASSOC);
if (!$apply) {
    echo json_encode(['modulos_visitas' => $modules], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}
try {
    $db->beginTransaction();
    // Conserva el identificador y las asignaciones del módulo más reciente.
    if (!$modules) {
        $db->exec("INSERT INTO modulos(nombre) VALUES ('Visitas')");
        $canonical = (int) $db->lastInsertId();
    } else {
        $canonical = (int) $modules[0]['id'];
    }
    foreach ($modules as $module) {
        $moduleId = (int) $module['id'];
        // Los roles explícitos actuales tienen precedencia sobre los antiguos.
        $db->prepare("INSERT IGNORE INTO cengisalas_module_access(usuario_id,module_code,role_code)
            SELECT u.id,'visitas',CASE
                WHEN u.es_superadmin=1 THEN 'admin'
                WHEN EXISTS(SELECT 1 FROM rol_permiso rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=u.rol_id AND p.nombre_permiso='gestionar_solicitudes') THEN 'admin'
                WHEN EXISTS(SELECT 1 FROM rol_permiso rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=u.rol_id AND p.nombre_permiso='gestionar_pagos') THEN 'payments'
                ELSE 'user' END
            FROM usuarios u JOIN usuario_modulo um ON um.usuario_id=u.id WHERE um.modulo_id=?")
            ->execute([$moduleId]);
        if ($moduleId === $canonical) continue;
        $db->prepare('INSERT IGNORE INTO usuario_modulo(usuario_id,modulo_id) SELECT usuario_id,? FROM usuario_modulo WHERE modulo_id=?')->execute([$canonical, $moduleId]);
        $db->prepare('DELETE FROM usuario_modulo WHERE modulo_id=?')->execute([$moduleId]);
        $db->prepare('DELETE FROM modulos WHERE id=?')->execute([$moduleId]);
    }
    $db->prepare("UPDATE modulos SET nombre='Visitas' WHERE id=?")->execute([$canonical]);
    $db->prepare("INSERT IGNORE INTO usuario_modulo(usuario_id,modulo_id) SELECT usuario_id,? FROM cengisalas_module_access WHERE module_code='visitas'")->execute([$canonical]);
    $db->commit();
    echo "Visitas unificado: un módulo, asignaciones y roles conservados.
";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
