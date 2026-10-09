<?php
session_start();
require_once("../config/conexion.php");
require_once("../config/permisos_roles.php");
require_once __DIR__ . "/_guard.php";
require_once __DIR__ . "/_modulos_rol.php";

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
$modulos = rol_mod_listar_modulos($conn);
$modulosRol = cengi_rm_modulos_de_rol($conn, $id);
$nombresModulos = array_column($modulos, 'nombre', 'id');

if ($_POST) {
    $nombre = trim($_POST['nombre'] ?? '');
    $permisosSeleccionados = $_POST['permisos'] ?? [];
    $modulosRol = rol_mod_leer_post($_POST, $modulos);
    $conflictos = rol_mod_conflictos($conn, $id, $modulosRol);

    if ($nombre === "") {
        $error = "El nombre no puede estar vacio";
    } elseif ($conflictos) {
        $partes = [];
        foreach ($conflictos as $moduloId => $total) {
            $partes[] = ($nombresModulos[$moduloId] ?? 'módulo #' . $moduloId) . " ({$total} usuario(s))";
        }
        $error = "No puedes quitar el rol de: " . implode(', ', $partes) . ". Primero cambia el rol de esos usuarios en ese módulo.";
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
            rol_mod_guardar($conn, $id, $modulosRol);

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

        <?php rol_mod_render($modulos, $modulosRol); ?>

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

        <?php
        if ($_POST) {
            // Conserva lo marcado si el formulario vuelve con error.
            $permisosRol = array_map('strval', (array) ($_POST['permisos'] ?? []));
        }
        require __DIR__ . '/_permisos_form.php';
        ?>

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
    setTimeout(() => document.dispatchEvent(new Event("permisos:cambio")));
    document.querySelectorAll('input[name="permisos[]"]').forEach(input => {
        if (input.type === "checkbox") input.checked = checked;
    });
}
</script>
</body>
</html>
