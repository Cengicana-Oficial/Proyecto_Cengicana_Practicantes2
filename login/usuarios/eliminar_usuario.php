<?php
session_start();
require_once "../config/conexion.php";

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login.php");
    exit;
}

/**
 * Muestra una página de aviso con el mismo lenguaje visual del módulo
 * (usada en los casos en que la eliminación no puede continuar) y termina
 * la ejecución.
 */
function usuarios_mostrar_aviso($titulo, $mensaje, $volverA = 'usuarios.php')
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
        <a href="<?= htmlspecialchars($volverA) ?>" class="btn-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver
        </a>

        <div class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p><?= htmlspecialchars($mensaje) ?></p>
            <a href="<?= htmlspecialchars($volverA) ?>" class="btn-primary">Volver al listado</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

function user_scope_config($scope)
{
    $scope = strtolower(trim((string) $scope));

    $configs = [
        'cursos' => [
            'module_names' => ['cursos', 'cengicursos'],
            'return_url' => '../../cengicursos/ver_usuarios.php',
        ],
        'visitas' => [
            'module_names' => ['solicitud de visitas'],
            'return_url' => '../../Pruebas/public/admin/dashboard_unificado.php?modulo=usuarios',
        ],
        'laboratorio' => [
            'module_names' => ['laboratorio'],
            'return_url' => '../../laboratorio/view/usuarios_view.php',
        ],
        'solicitudes' => [
            'module_names' => ['solicitudes internas'],
            'return_url' => '../../sistema_de_solicitudes/index.php?view=usuarios',
        ],
    ];

    return $configs[$scope] ?? null;
}

$conn = Conexion::conectar();
$esSuperadmin = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$scope = $_GET['scope'] ?? '';
$scopeConfig = user_scope_config($scope);
$id = (int) ($_GET['id'] ?? 0);
$volverA = $scopeConfig['return_url'] ?? 'usuarios.php';

if ($id <= 0) {
    usuarios_mostrar_aviso("ID inválido", "No se recibió un identificador de usuario válido.", $volverA);
}

require_once __DIR__ . '/_autorizacion.php';
$idUsuario = (int) $_SESSION['id_usuario'];
$modulosGestionados = usr_exigir_acceso_scope($conn, $idUsuario, $esSuperadmin, $scope, $scopeConfig);

$stmt = $conn->prepare("SELECT id, nombre, correo, rol_id, es_superadmin FROM usuarios WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    usuarios_mostrar_aviso("Usuario no encontrado", "El usuario que intentas eliminar ya no existe.", $volverA);
}

// Bloquear eliminación de un Superadmin
if ($user['es_superadmin'] == 1) {
    usuarios_mostrar_aviso("Acción no permitida", "No se puede eliminar un Superadmin.", $volverA);
}

// Bloquear autoeliminación
if ($id === $idUsuario) {
    usuarios_mostrar_aviso("Acción no permitida", "No puedes eliminar tu propia cuenta.", $volverA);
}

$alcance = usr_alcance_sobre($conn, $idUsuario, $esSuperadmin, $user, $modulosGestionados);
if ($alcance === 'ninguno') {
    usuarios_mostrar_aviso("Acción no permitida", "No puedes eliminar este usuario desde este módulo.", $volverA);
}
// Si el usuario tiene acceso a modulos que quien elimina no gestiona, solo se le quita el
// acceso a los modulos de esta pantalla; la cuenta se conserva.
$soloQuitarAcceso = $alcance !== 'total';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $qs = http_build_query(array_filter(['id' => $id, 'scope' => $scopeConfig ? $scope : null]));
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Eliminar usuario | CENGICAÑA</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
    </head>
    <body>
    <div class="ingenios-shell">
        <a href="<?= htmlspecialchars($volverA) ?>" class="btn-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver
        </a>
        <form method="POST" action="eliminar_usuario.php?<?= htmlspecialchars($qs) ?>" class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1><?= $soloQuitarAcceso ? 'Quitar acceso al módulo' : 'Eliminar usuario' ?></h1>
            <p>
                <?php if ($soloQuitarAcceso): ?>
                    <?= htmlspecialchars($user['nombre']) ?> (<?= htmlspecialchars($user['correo']) ?>) también usa otros módulos: se le quitará el acceso a este módulo y su cuenta se conservará.
                <?php else: ?>
                    Se eliminará la cuenta de <?= htmlspecialchars($user['nombre']) ?> (<?= htmlspecialchars($user['correo']) ?>). Esta acción no se puede deshacer.
                <?php endif; ?>
            </p>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(usr_csrf_token()) ?>">
            <button type="submit" class="btn-primary"><?= $soloQuitarAcceso ? 'Quitar acceso' : 'Eliminar' ?></button>
            <a href="<?= htmlspecialchars($volverA) ?>" class="btn-secondary">Cancelar</a>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if (!usr_csrf_valido($_POST['csrf'] ?? null)) {
    usuarios_mostrar_aviso("Acción no permitida", "La sesión del formulario expiró. Vuelve a intentarlo.", $volverA);
}

$conn->beginTransaction();
if ($soloQuitarAcceso) {
    $ids = array_map(static fn($m) => (int) $m['id'], $modulosGestionados);
    $conn->prepare("DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
        ->execute(array_merge([$id], $ids));
} else {
    // Eliminar relación de módulos (para evitar errores de FK)
    $conn->prepare("DELETE FROM usuario_modulo WHERE usuario_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
}
$conn->commit();

header("Location: " . $volverA);
exit;
