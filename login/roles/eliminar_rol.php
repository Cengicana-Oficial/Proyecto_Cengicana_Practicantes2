<?php
session_start();
require_once("../config/conexion.php");
require_once("../config/permisos_roles.php");
require_once __DIR__ . "/_guard.php";

/**
 * Muestra una página de aviso con el mismo lenguaje visual del módulo
 * (usada en los casos en que la eliminación no puede continuar) y termina
 * la ejecución.
 */
function roles_mostrar_aviso($titulo, $mensaje)
{
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($titulo) ?> | CENGICAÑA</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
    </head>
    <body>
    <div class="ingenios-shell">
        <a href="roles.php" class="btn-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver a roles
        </a>

        <div class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p><?= htmlspecialchars($mensaje) ?></p>
            <a href="roles.php" class="btn-primary">Volver al listado</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$conn = Conexion::conectar();
asegurar_tablas_permisos($conn);

$id = $_GET['id'] ?? null;

if (!$id) {
    roles_mostrar_aviso("Rol inválido", "No se recibió un identificador de rol válido.");
}

$stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$id]);
$rol = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rol) {
    roles_mostrar_aviso("Rol no encontrado", "El rol que intentas eliminar ya no existe.");
}

if (strtolower($rol['nombre_rol']) === 'superadmin') {
    roles_mostrar_aviso("Acción no permitida", "No se puede eliminar el rol Superadmin.");
}

$stmtUsers = $conn->prepare("SELECT id FROM usuarios WHERE rol_id = ?");
$stmtUsers->execute([$id]);

if ($stmtUsers->rowCount() > 0) {
    roles_mostrar_aviso(
        "No se puede eliminar",
        "Este rol tiene usuarios asignados. Reasígnalos a otro rol antes de eliminarlo."
    );
}

try {
    $stmtRM = $conn->prepare("SELECT * FROM rol_modulo WHERE rol_id = ?");
    $stmtRM->execute([$id]);

    if ($stmtRM->rowCount() > 0) {
        roles_mostrar_aviso(
            "No se puede eliminar",
            "Este rol tiene módulos asignados. Quítalos antes de eliminarlo."
        );
    }
} catch (Exception $e) {
}

$stmtPermisos = $conn->prepare("DELETE FROM rol_permiso WHERE rol_id = ?");
$stmtPermisos->execute([$id]);

$stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
$stmt->execute([$id]);

header("Location: roles.php");
exit;
