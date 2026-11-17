<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/login/config/conexion.php';
require_once dirname(__DIR__) . '/login/config/cengisalas_supabase.php';
$db = Conexion::conectar();
if (($argv[1] ?? '--check') !== '--apply') {
    echo json_encode($db->query("SELECT role_code,COUNT(*) AS usuarios FROM cengisalas_module_access WHERE module_code='visitas' GROUP BY role_code")->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}
$pg = cengisalas_pg();
$pg->exec(file_get_contents(dirname(__DIR__) . '/visitas/database/20261008_roles_visitas.sql'));
$db->exec("ALTER TABLE cengisalas_module_access MODIFY role_code ENUM('user','payments','admin','manager','asistente_direccion','director_general','coordinador_visitas','gestor_pagos') NOT NULL");
$db->exec("UPDATE cengisalas_module_access SET role_code=CASE role_code WHEN 'admin' THEN 'coordinador_visitas' WHEN 'user' THEN 'asistente_direccion' WHEN 'payments' THEN 'gestor_pagos' ELSE role_code END WHERE module_code='visitas'");
echo "Cuatro roles de Visitas instalados. Asignaciones de otros módulos conservadas.
";
