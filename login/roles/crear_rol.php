<?php
session_start();
require_once("../config/conexion.php");
require_once("../config/permisos_roles.php");
require_once __DIR__ . "/_guard.php";

$conn = Conexion::conectar();
asegurar_tablas_permisos($conn);

$permisos = obtener_permisos($conn);
$gruposPermisos = agrupar_permisos($permisos);
$error = "";

if ($_POST) {
    $nombre = trim($_POST['nombre'] ?? '');
    $permisosSeleccionados = $_POST['permisos'] ?? [];

    if ($nombre === "") {
        $error = "El nombre no puede estar vacio";
    } else {
        $check = $conn->prepare("SELECT id FROM roles WHERE nombre_rol = ?");
        $check->execute([$nombre]);

        if ($check->rowCount() > 0) {
            $error = "Este rol ya existe";
        } else {
            $stmt = $conn->prepare("INSERT INTO roles (nombre_rol) VALUES (?)");
            $stmt->execute([$nombre]);

            $rolId = $conn->lastInsertId();
            guardar_permisos_rol($conn, $rolId, $permisosSeleccionados);

            header("Location: roles.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear rol | CENGICAÑA</title>
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

    <?php if ($error): ?>
        <div class="alert-banner">
            <span class="material-symbols-outlined">error</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-card form-card-wide">
        <h1>Crear rol</h1>
        <p class="form-sub">Define el nombre y marca las acciones permitidas para este rol.</p>

        <div class="form-field">
            <label for="nombre">Nombre del rol</label>
            <input id="nombre" name="nombre" placeholder="Ej: Analista, Supervisor"
                   value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required autofocus>
        </div>

        <div class="permisos-toolbar">
            <h3>Permisos del rol</h3>
            <div class="permisos-toolbar-actions">
                <button type="button" class="btn-light" onclick="marcarPermisos(true)">Seleccionar todos</button>
                <button type="button" class="btn-light" onclick="marcarPermisos(false)">Limpiar</button>
            </div>
        </div>

        <?php if (empty($permisos)): ?>
            <div class="empty-permissions">
                No hay permisos en la base de datos. Ejecuta primero el script de permisos en MySQL Workbench.
            </div>
        <?php endif; ?>

        <?php foreach ($gruposPermisos as $grupo => $items): ?>
            <section class="permiso-section">
                <div class="permiso-section-head">
                    <div>
                        <span class="permiso-kicker"><?= htmlspecialchars(titulo_corto_grupo_permiso($grupo)) ?></span>
                        <h4><?= htmlspecialchars($grupo) ?></h4>
                    </div>
                    <span class="permiso-count"><?= count($items) ?> permisos</span>
                </div>
                <p class="permiso-section-copy"><?= htmlspecialchars(descripcion_grupo_permiso($grupo)) ?></p>
                <div class="permisos-grid">
                    <?php foreach ($items as $permiso): ?>
                        <label class="permiso-item">
                            <input
                                type="checkbox"
                                name="permisos[]"
                                value="<?= htmlspecialchars($permiso['nombre_permiso']) ?>"
                            >
                            <span>
                                <strong><?= htmlspecialchars(etiqueta_permiso($permiso['nombre_permiso'])) ?></strong>
                                <small><?= htmlspecialchars($permiso['descripcion'] ?: $permiso['nombre_permiso']) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined">check</span>
                Crear rol
            </button>
            <a href="roles.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<script>
function marcarPermisos(checked) {
    document.querySelectorAll('input[name="permisos[]"]').forEach(input => {
        input.checked = checked;
    });
}
</script>
</body>
</html>
