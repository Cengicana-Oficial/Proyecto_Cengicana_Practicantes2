<?php
require_once "conexion.php";
$conn = Conexion::conectarUsuariosMenu();

echo "Iniciando configuración de permisos en DB_MENU...\n";

// 1. Crear tabla de permisos
$conn->exec("CREATE TABLE IF NOT EXISTS permisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_permiso VARCHAR(100) NOT NULL UNIQUE,
    descripcion VARCHAR(255)
)");
echo "✅ Tabla 'permisos' verificada/creada.\n";

// 2. Crear tabla de relación rol_permiso
$conn->exec("CREATE TABLE IF NOT EXISTS rol_permiso (
    rol_id INT,
    permiso_id INT,
    PRIMARY KEY (rol_id, permiso_id),
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
)");
echo "✅ Tabla 'rol_permiso' verificada/creada.\n";

// 3. Poblar permisos básicos
$permisos = [
    'ver_dashboard' => 'Permite ver el dashboard general',
    'gestionar_solicitudes' => 'Permite aprobar/rechazar solicitudes',
    'ver_solicitudes' => 'Permite ver el listado de solicitudes',
    'gestionar_pagos' => 'Permite marcar solicitudes como pagadas',
    'ver_pagos' => 'Permite ver el dashboard de pagos',
    'gestionar_usuarios' => 'Permite crear y editar usuarios',
    'gestionar_roles' => 'Permite editar roles y sus permisos',
    'gestionar_modulos' => 'Permite gestionar los módulos del sistema',
    'gestionar_ingenios' => 'Permite gestionar los ingenios',
    'gestionar_areas' => 'Permite crear y editar areas del modulo de visitas',
    'gestionar_reserva_salones' => 'Permite preparar y enviar reservaciones de salones desde el dashboard de visitas',
    'ver_solicitudes_aprobadas' => 'Permite ver solo solicitudes aprobadas',
    'enviar_correos' => 'Permite enviar correos de solicitudes',
    'ocultar_solicitudes' => 'Permite ocultar solicitudes del dashboard'
];

foreach ($permisos as $nombre => $desc) {
    $stmt = $conn->prepare("INSERT IGNORE INTO permisos (nombre_permiso, descripcion) VALUES (?, ?)");
    $stmt->execute([$nombre, $desc]);
}
echo "✅ Permisos básicos poblados.\n";

// 4. Asignaciones iniciales básicas para no dejar a nadie sin acceso
// Superadmin (asumiendo ID 1) tiene todo
$allPerms = $conn->query("SELECT id FROM permisos")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allPerms as $pId) {
    $stmt = $conn->prepare("INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES (1, ?)");
    $stmt->execute([$pId]);
}
echo "✅ Permisos asignados al SuperAdmin.\n";

echo "Configuración completada con éxito.\n";
