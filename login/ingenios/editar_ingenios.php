<?php
session_start();
require_once __DIR__ . "/_guard.php";
require_once("../config/conexion.php");

$conn = Conexion::conectar();

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: ingenios.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM ingenios WHERE id=?");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$m) {
    header("Location: ingenios.php");
    exit;
}

$error = null;

if ($_POST) {
    $nombre = trim((string) ($_POST['nombre_ingenio'] ?? ''));

    if ($nombre === '') {
        $error = "El nombre del ingenio es obligatorio.";
    } else {
        try {
            $stmt = $conn->prepare("UPDATE ingenios SET nombre_ingenio=? WHERE id=?");
            $stmt->execute([$nombre, $id]);

            header("Location: ingenios.php");
            exit;
        } catch (PDOException $e) {
            $error = "No se pudo guardar el cambio. Intenta nuevamente o contacta al administrador del sistema.";
        }
    }

    $m['nombre_ingenio'] = $nombre;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar ingenio | CENGICAÑA</title>
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

    <?php if ($error): ?>
        <div class="alert-banner">
            <span class="material-symbols-outlined">error</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-card">
        <h1>Editar ingenio</h1>
        <p class="form-sub">Actualiza el nombre del ingenio #<?= (int) $m['id'] ?>.</p>

        <div class="form-field">
            <label for="nombre_ingenio">Nombre del ingenio</label>
            <input id="nombre_ingenio" name="nombre_ingenio"
                   value="<?= htmlspecialchars($m['nombre_ingenio']) ?>" required autofocus>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined">check</span>
                Guardar cambios
            </button>
            <a href="ingenios.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
</body>
</html>
