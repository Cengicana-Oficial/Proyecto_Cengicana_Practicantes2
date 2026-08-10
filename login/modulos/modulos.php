<?php
session_start();
require_once __DIR__ . "/_guard.php";
require_once("../config/conexion.php");

$conn = Conexion::conectar();

// Obtener módulos
$stmt = $conn->query("SELECT * FROM modulos ORDER BY nombre");
$modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulos | CENGICAÑA</title>
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
            <h1>Módulos</h1>
            <p>Gestiona los módulos del sistema disponibles para los usuarios.</p>
        </div>

        <a href="crear_modulo.php" class="btn-primary">
            <span class="material-symbols-outlined">add</span>
            Crear módulo
        </a>
    </div>

    <div class="ingenios-card">
        <div class="ingenios-card-head">
            <strong>Módulos registrados</strong>
            <span class="ingenios-count"><?= count($modulos) ?></span>
        </div>

        <?php if (count($modulos) === 0): ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">widgets</span>
                <strong>Aún no hay módulos registrados</strong>
                <span>Crea el primero con el botón "Crear módulo".</span>
            </div>
        <?php else: ?>
            <table class="ingenios-table">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Acciones</th>
                </tr>

                <?php foreach ($modulos as $m): ?>
                    <tr>
                        <td><span class="id-chip">#<?= (int) $m['id'] ?></span></td>
                        <td>
                            <div class="ingenio-name">
                                <span class="ingenio-icon">
                                    <span class="material-symbols-outlined">widgets</span>
                                </span>
                                <?= htmlspecialchars($m['nombre']) ?>
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="action-btn" href="editar_modulo.php?id=<?= (int) $m['id'] ?>" title="Editar">
                                    <span class="material-symbols-outlined">edit</span>
                                </a>
                                <a class="action-btn is-danger btn-delete" href="#"
                                   data-url="eliminar_modulo.php?id=<?= (int) $m['id'] ?>" title="Eliminar">
                                    <span class="material-symbols-outlined">delete</span>
                                </a>
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
        <h3>¿Eliminar módulo?</h3>
        <p>Puede afectar a los usuarios asignados a este módulo.</p>

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
