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
            'title' => 'Usuarios del modulo Cursos',
        ],
        'visitas' => [
            'module_names' => ['solicitud de visitas'],
            'return_url' => '../../Pruebas/public/admin/dashboard_unificado.php?modulo=usuarios',
            'title' => 'Usuarios del modulo Solicitud de visitas',
        ],
    ];

    return $configs[$scope] ?? null;
}

$conn = Conexion::conectar();
$idUsuario = (int) $_SESSION['id_usuario'];
$esSuperadmin = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$scope = $_GET['scope'] ?? '';
$scopeConfig = user_scope_config($scope);

if (!$esSuperadmin && !$scopeConfig) {
    header("Location: ../Menu.php");
    exit;
}

$params = [];
$where = [];

if ($scopeConfig) {
    $placeholders = implode(',', array_fill(0, count($scopeConfig['module_names']), '?'));
    $where[] = "EXISTS (
        SELECT 1
        FROM usuario_modulo umf
        INNER JOIN modulos mf ON mf.id = umf.modulo_id
        WHERE umf.usuario_id = u.id
          AND LOWER(mf.nombre) IN ($placeholders)
    )";
    $params = array_merge($params, $scopeConfig['module_names']);
}

if (!$esSuperadmin && !$scopeConfig) {
    $where[] = "EXISTS (
        SELECT 1
        FROM usuario_modulo umscope
        WHERE umscope.usuario_id = u.id
          AND umscope.modulo_id IN (
              SELECT modulo_id FROM usuario_modulo WHERE usuario_id = ?
          )
    )";
    $params[] = $idUsuario;
}

$sql = "
    SELECT
        u.id,
        u.nombre,
        u.correo,
        r.nombre_rol,
        COALESCE(i.nombre_ingenio, 'Sin ingenio') AS ingenio,
        GROUP_CONCAT(DISTINCT m.nombre ORDER BY m.nombre SEPARATOR ', ') AS modulos
    FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id
    LEFT JOIN ingenios i ON i.id = u.ingenio_id
    LEFT JOIN usuario_modulo um ON um.usuario_id = u.id
    LEFT JOIN modulos m ON m.id = um.modulo_id
";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= "
    GROUP BY u.id, u.nombre, u.correo, r.nombre_rol, i.nombre_ingenio
    ORDER BY u.nombre
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$titulo = $scopeConfig['title'] ?? 'Usuarios del sistema';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios | CENGICAÑA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
</head>
<body>
<div class="ingenios-shell">
    <a href="../Menu.php" class="btn-back">
        <span class="material-symbols-outlined">arrow_back</span>
        Volver al panel
    </a>

    <div class="ingenios-topbar">
        <div class="page-heading">
            <span class="eyebrow">Administración</span>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p>Gestiona los usuarios registrados y los módulos a los que tienen acceso.</p>
        </div>

        <a href="crear_usuario.php<?= $scope ? '?scope=' . urlencode($scope) : '' ?>" class="btn-primary">
            <span class="material-symbols-outlined">add</span>
            Crear usuario
        </a>
    </div>

    <div class="ingenios-card">
        <div class="ingenios-card-head">
            <strong>Usuarios registrados</strong>
            <span class="ingenios-count"><?= count($usuarios) ?></span>
        </div>

        <?php if (count($usuarios) === 0): ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">group</span>
                <strong>Aún no hay usuarios registrados</strong>
                <span>Crea el primero con el botón "Crear usuario".</span>
            </div>
        <?php else: ?>
            <table class="ingenios-table">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Ingenio</th>
                    <th>Rol</th>
                    <th>Módulos</th>
                    <th>Acciones</th>
                </tr>

                <?php foreach ($usuarios as $usuario): ?>
                    <?php $esUsuarioSuperadmin = strtolower((string) $usuario['nombre_rol']) === 'superadmin'; ?>
                    <tr>
                        <td><span class="id-chip">#<?= (int) $usuario['id'] ?></span></td>
                        <td>
                            <div class="ingenio-name">
                                <span class="ingenio-icon">
                                    <span class="material-symbols-outlined">person</span>
                                </span>
                                <?= htmlspecialchars($usuario['nombre']) ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($usuario['correo']) ?></td>
                        <td><?= htmlspecialchars($usuario['ingenio']) ?></td>
                        <td><?= htmlspecialchars($usuario['nombre_rol']) ?></td>
                        <td>
                            <?php
                            $modulosUsuarioLista = array_filter(array_map('trim', explode(',', (string) ($usuario['modulos'] ?? ''))));
                            ?>
                            <?php if ($modulosUsuarioLista): ?>
                                <div class="module-badges">
                                    <?php foreach ($modulosUsuarioLista as $moduloNombre): ?>
                                        <span class="module-badge"><?= htmlspecialchars($moduloNombre) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted-small">Sin módulo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="action-btn"
                                   href="editar_usuario.php?id=<?= (int) $usuario['id'] ?><?= $scope ? '&scope=' . urlencode($scope) : '' ?>"
                                   title="Editar">
                                    <span class="material-symbols-outlined">edit</span>
                                </a>

                                <?php if (!$esUsuarioSuperadmin): ?>
                                    <a class="action-btn is-danger btn-delete" href="#"
                                       data-url="eliminar_usuario.php?id=<?= (int) $usuario['id'] ?><?= $scope ? '&scope=' . urlencode($scope) : '' ?>"
                                       title="Eliminar">
                                        <span class="material-symbols-outlined">delete</span>
                                    </a>
                                <?php else: ?>
                                    <span class="badge-protected">
                                        <span class="material-symbols-outlined">lock</span>
                                        Bloqueado
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="confirm-icon">
            <span class="material-symbols-outlined">warning</span>
        </div>
        <h3>¿Eliminar usuario?</h3>
        <p>Esta acción no se puede deshacer.</p>

        <div class="modal-buttons">
            <button id="cancelBtn" type="button">Cancelar</button>
            <a id="confirmDelete" href="#">Eliminar</a>
        </div>
    </div>
</div>

<script>
const modal = document.getElementById("deleteModal");
const confirmBtn = document.getElementById("confirmDelete");
const cancelBtn = document.getElementById("cancelBtn");

document.querySelectorAll(".btn-delete").forEach(btn => {
  btn.addEventListener("click", function (e) {
    e.preventDefault();
    confirmBtn.href = this.getAttribute("data-url");
    modal.classList.add("active");
  });
});

cancelBtn.onclick = () => modal.classList.remove("active");

window.onclick = (e) => {
  if (e.target === modal) modal.classList.remove("active");
};
</script>
</body>
</html>
