<?php
session_start();
require_once __DIR__ . "/_guard.php";
require_once("../config/conexion.php");

/**
 * Muestra una página de aviso con el mismo lenguaje visual del módulo
 * (usada cuando la eliminación no puede continuar) y termina la ejecución.
 */
function modulos_mostrar_aviso($titulo, $mensaje)
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
        <a href="modulos.php" class="btn-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver a módulos
        </a>

        <div class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p><?= htmlspecialchars($mensaje) ?></p>
            <a href="modulos.php" class="btn-primary">Volver al listado</a>
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
    modulos_mostrar_aviso("Módulo inválido", "No se recibió un identificador de módulo válido.");
}

// Validar si tiene usuarios asignados
$stmtCheck = $conn->prepare("SELECT id FROM usuario_modulo WHERE modulo_id=?");
$stmtCheck->execute([$id]);

if ($stmtCheck->rowCount() > 0) {
    modulos_mostrar_aviso(
        "No se puede eliminar",
        "Este módulo tiene usuarios asignados. Reasígnalos antes de eliminarlo."
    );
}

try {
    $stmt = $conn->prepare("DELETE FROM modulos WHERE id=?");
    $stmt->execute([$id]);

    header("Location: modulos.php");
    exit;
} catch (PDOException $e) {
    modulos_mostrar_aviso(
        "No se pudo eliminar",
        "Ocurrió un error al eliminar el módulo. Intenta nuevamente o contacta al administrador del sistema."
    );
}
