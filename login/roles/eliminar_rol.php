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

// Usado como rol de la plataforma o como rol en algun modulo (usuario_modulo.rol_id).
$stmtUsers = $conn->prepare("SELECT id FROM usuarios WHERE rol_id = ? UNION SELECT usuario_id FROM usuario_modulo WHERE rol_id = ?");
$stmtUsers->execute([$id, $id]);

if ($stmtUsers->rowCount() > 0) {
    roles_mostrar_aviso(
        "No se puede eliminar",
        "Este rol tiene usuarios asignados. Reasígnalos a otro rol antes de eliminarlo."
    );
}

if (empty($_SESSION['roles_eliminar_csrf'])) {
    $_SESSION['roles_eliminar_csrf'] = bin2hex(random_bytes(32));
}

// Con GET solo se confirma: el borrado va por POST con token (un enlace no puede borrar roles).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Eliminar rol | CENGICAÑA</title>
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
        <form method="POST" action="eliminar_rol.php?id=<?= (int) $rol['id'] ?>" class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1>Eliminar rol</h1>
            <p>Se eliminará el rol <?= htmlspecialchars($rol['nombre_rol']) ?> con sus permisos. Esta acción no se puede deshacer.</p>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['roles_eliminar_csrf']) ?>">
            <button type="submit" class="btn-primary">Eliminar</button>
            <a href="roles.php" class="btn-secondary">Cancelar</a>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['roles_eliminar_csrf'], $_POST['csrf'])) {
    roles_mostrar_aviso("Acción no permitida", "La sesión del formulario expiró. Vuelve a intentarlo.");
}

$conn->beginTransaction();
// Los modulos del rol (rol_modulo) solo indican donde se ofrece: se borran con el rol.
$conn->prepare("DELETE FROM rol_modulo WHERE rol_id = ?")->execute([$id]);
$conn->prepare("DELETE FROM rol_permiso WHERE rol_id = ?")->execute([$id]);
$conn->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
$conn->commit();

header("Location: roles.php");
exit;
