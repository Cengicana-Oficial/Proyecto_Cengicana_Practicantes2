<?php
session_start();
require_once __DIR__ . "/_guard.php";
require_once("../config/conexion.php");

/**
 * Muestra una página de aviso con el mismo lenguaje visual del módulo
 * (usada para los casos en que la eliminación no puede continuar) y termina
 * la ejecución.
 */
function ingenios_mostrar_aviso($titulo, $mensaje)
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
        <a href="ingenios.php" class="btn-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver a ingenios
        </a>

        <div class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p><?= htmlspecialchars($mensaje) ?></p>
            <a href="ingenios.php" class="btn-primary">Volver al listado</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$conn = Conexion::conectar();

$id = $_GET['id'] ?? null;

if (!$id) {
    ingenios_mostrar_aviso("Ingenio inválido", "No se recibió un identificador de ingenio válido.");
}

$stmt = $conn->prepare("SELECT * FROM ingenios WHERE id = ?");
$stmt->execute([$id]);
$ingenio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ingenio) {
    ingenios_mostrar_aviso("Ingenio no encontrado", "El ingenio que intentas eliminar ya no existe.");
}

if ($ingenio['nombre_ingenio'] === 'Superadmin') {
    ingenios_mostrar_aviso("Acción no permitida", "No se puede eliminar el ingenio Superadmin.");
}

$stmtUsers = $conn->prepare("SELECT id FROM usuarios WHERE ingenio_id = ?");
$stmtUsers->execute([$id]);

if ($stmtUsers->rowCount() > 0) {
    ingenios_mostrar_aviso(
        "No se puede eliminar",
        "Este ingenio tiene usuarios asignados. Reasígnalos a otro ingenio antes de eliminarlo."
    );
}

try {
    $stmt = $conn->prepare("DELETE FROM ingenios WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: ingenios.php");
    exit;
} catch (PDOException $e) {
    ingenios_mostrar_aviso(
        "No se pudo eliminar",
        "Ocurrió un error al eliminar el ingenio. Intenta nuevamente o contacta al administrador del sistema."
    );
}
