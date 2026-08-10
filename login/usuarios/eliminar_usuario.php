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

if (!$esSuperadmin && !$scopeConfig) {
    header("Location: usuarios.php");
    exit;
}

// Verificar si es superadmin
$stmt = $conn->prepare("SELECT es_superadmin FROM usuarios WHERE id = ?");
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
if ($id === (int) $_SESSION['id_usuario']) {
    usuarios_mostrar_aviso("Acción no permitida", "No puedes eliminar tu propia cuenta.", $volverA);
}

// Eliminar relación de módulos (para evitar errores de FK)
$stmtDelMod = $conn->prepare("DELETE FROM usuario_modulo WHERE usuario_id = ?");
$stmtDelMod->execute([$id]);

// Eliminar usuario
$stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
$stmt->execute([$id]);

header("Location: " . $volverA);
exit;
