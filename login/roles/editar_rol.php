<?php
session_start();
require_once("../config/conexion.php");
require_once("../config/permisos_roles.php");
require_once __DIR__ . "/_guard.php";

/**
 * Muestra una página de aviso con el mismo lenguaje visual del módulo
 * (usada cuando no se puede continuar con la edición) y termina la
 * ejecución.
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
    roles_mostrar_aviso("Rol no encontrado", "El rol que intentas editar ya no existe.");
}

$error = "";
$permisos = obtener_permisos($conn);
$gruposPermisos = agrupar_permisos($permisos);
$permisosRol = obtener_permisos_rol($conn, $id);

if ($_POST) {
    $nombre = trim($_POST['nombre'] ?? '');
    $permisosSeleccionados = $_POST['permisos'] ?? [];

    if ($nombre === "") {
        $error = "El nombre no puede estar vacio";
    } else {
        $check = $conn->prepare("
            SELECT id
            FROM roles
            WHERE nombre_rol = ? AND id != ?
        ");
        $check->execute([$nombre, $id]);

        if ($check->rowCount() > 0) {
            $error = "Este rol ya existe";
        } else {
            $stmt = $conn->prepare("
                UPDATE roles
                SET nombre_rol = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $id]);

            guardar_permisos_rol($conn, $id, $permisosSeleccionados);

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
    <title>Editar rol | CENGICAÑA</title>
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
        <h1>Editar rol y permisos</h1>
        <p class="form-sub">Actualiza el nombre y las acciones disponibles para el rol #<?= (int) $rol['id'] ?>.</p>

        <div class="form-field">
            <label for="nombre">Nombre del rol</label>
            <input id="nombre" name="nombre" value="<?= htmlspecialchars($rol['nombre_rol']) ?>" required autofocus>
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
                                <?= in_array($permiso['nombre_permiso'], $permisosRol, true) ? 'checked' : '' ?>
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
                Guardar cambios
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
