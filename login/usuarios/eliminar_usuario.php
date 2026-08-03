<?php
session_start();
require_once "../config/conexion.php";

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login.php");
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

if ($id <= 0) {
    die("ID inválido");
}

if (!$esSuperadmin && !$scopeConfig) {
    header("Location: usuarios.php");
    exit;
}

// 🔒 VERIFICAR SI ES SUPERADMIN
$stmt = $conn->prepare("SELECT es_superadmin FROM usuarios WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Usuario no encontrado");
}

// 🚫 BLOQUEAR ELIMINACIÓN
if ($user['es_superadmin'] == 1) {
    die("No se puede eliminar un Superadmin");
}

// 🚫 BLOQUEAR AUTOELIMINACIÓN
if ($id === (int) $_SESSION['id_usuario']) {
    die("No puedes eliminar tu propia cuenta");
}

// 🔥 ELIMINAR RELACIÓN DE MÓDULOS (para evitar errores de FK)
$stmtDelMod = $conn->prepare("DELETE FROM usuario_modulo WHERE usuario_id = ?");
$stmtDelMod->execute([$id]);

// 🔥 ELIMINAR USUARIO
$stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
$stmt->execute([$id]);

header("Location: " . ($scopeConfig['return_url'] ?? 'usuarios.php'));
exit;