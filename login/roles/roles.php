<?php
session_start();
require_once("../config/conexion.php");
require_once("../config/permisos_roles.php");
require_once __DIR__ . "/_guard.php";

$conn = Conexion::conectar();
asegurar_tablas_permisos($conn);

$stmt = $conn->query("
    SELECT
        r.id,
        r.nombre_rol,
        COUNT(rp.permiso_id) AS total_permisos,
        GROUP_CONCAT(p.nombre_permiso ORDER BY p.nombre_permiso SEPARATOR ', ') AS permisos
    FROM roles r
    LEFT JOIN rol_permiso rp ON rp.rol_id = r.id
    LEFT JOIN permisos p ON p.id = rp.permiso_id
    GROUP BY r.id, r.nombre_rol
    ORDER BY r.id
");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles | CENGICAÑA</title>
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
            <h1>Roles y permisos</h1>
            <p>Gestiona los roles del sistema y los permisos asignados a cada uno.</p>
        </div>

        <a href="crear_rol.php" class="btn-primary">
            <span class="material-symbols-outlined">add</span>
            Crear rol
        </a>
    </div>

    <div class="ingenios-card">
        <div class="ingenios-card-head">
            <strong>Roles registrados</strong>
            <span class="ingenios-count"><?= count($roles) ?></span>
        </div>

        <?php if (count($roles) === 0): ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">admin_panel_settings</span>
                <strong>Aún no hay roles registrados</strong>
                <span>Crea el primero con el botón "Crear rol".</span>
            </div>
        <?php else: ?>
            <table class="ingenios-table">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Permisos asignados</th>
                    <th>Acciones</th>
                </tr>

                <?php foreach ($roles as $r): ?>
                    <?php
                    $permisosRol = array_filter(array_map('trim', explode(',', (string) ($r['permisos'] ?? ''))));
                    $esRolProtegido = strtolower($r['nombre_rol']) === 'superadmin';
                    ?>
                    <tr>
                        <td><span class="id-chip">#<?= (int) $r['id'] ?></span></td>
                        <td>
                            <div class="ingenio-name">
                                <span class="ingenio-icon">
                                    <span class="material-symbols-outlined">admin_panel_settings</span>
                                </span>
                                <?= htmlspecialchars($r['nombre_rol']) ?>
                            </div>
                        </td>
                        <td>
                            <div class="permisos-count-cell">
                                <strong><?= (int) $r['total_permisos'] ?> permiso(s)</strong>
                                <?php if ($permisosRol): ?>
                                    <div class="module-badges">
                                        <?php foreach ($permisosRol as $permisoNombre): ?>
                                            <span class="module-badge"><?= htmlspecialchars(etiqueta_permiso($permisoNombre)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted-small">Sin permisos asignados</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="action-btn" href="editar_rol.php?id=<?= (int) $r['id'] ?>" title="Editar permisos">
                                    <span class="material-symbols-outlined">tune</span>
                                </a>

                                <?php if (!$esRolProtegido): ?>
                                    <a class="action-btn is-danger btn-delete" href="#"
                                       data-url="eliminar_rol.php?id=<?= (int) $r['id'] ?>" title="Eliminar">
                                        <span class="material-symbols-outlined">delete</span>
                                    </a>
                                <?php else: ?>
                                    <span class="badge-protected">
                                        <span class="material-symbols-outlined">lock</span>
                                        Protegido
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
        <h3>¿Eliminar rol?</h3>
        <p>Puede afectar a los usuarios asignados a este rol.</p>

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
