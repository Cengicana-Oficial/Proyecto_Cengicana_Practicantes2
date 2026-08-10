<?php
session_start();
require_once __DIR__ . "/_guard.php";
require_once("../config/conexion.php");

$conn = Conexion::conectar();

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: modulos.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM modulos WHERE id=?");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$m) {
    header("Location: modulos.php");
    exit;
}

if ($_POST) {
    $nombre = $_POST['nombre'];

    $stmt = $conn->prepare("UPDATE modulos SET nombre=? WHERE id=?");
    $stmt->execute([$nombre, $id]);

    header("Location: modulos.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar módulo | CENGICAÑA</title>
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

    <form method="POST" class="form-card">
        <h1>Editar módulo</h1>
        <p class="form-sub">Actualiza el nombre del módulo #<?= (int) $m['id'] ?>.</p>

        <div class="form-field">
            <label for="nombre">Nombre del módulo</label>
            <input id="nombre" name="nombre" value="<?= htmlspecialchars($m['nombre']) ?>" required autofocus>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined">check</span>
                Guardar cambios
            </button>
            <a href="modulos.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
</body>
</html>
