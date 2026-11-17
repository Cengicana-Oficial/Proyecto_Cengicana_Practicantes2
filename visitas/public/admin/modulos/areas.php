<?php
if (!function_exists('can_access')) { http_response_code(403); exit('Acceso no permitido.'); }
$conn = conexion::conectar();

/* =========================
LISTAR ÁREAS ACTIVAS
========================= */
$stmt = $conn->query("
    SELECT id_area, nombre_area, profesional_area, correo_area, estado
    FROM areas_interes
    WHERE estado = 1
    ORDER BY id_area ASC
");

$areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Áreas de visita</h2>
<p class="muted">Configura las áreas que pueden seleccionarse en los formularios, junto con su profesional responsable y correo de notificación.</p>

<!-- =========================
    NUEVA ÁREA
========================= -->
<button class="btn-save" onclick="abrirModalNuevaArea()">
    + Agregar Área
</button>

<!-- =========================
    LISTADO
========================= -->
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Área</th>
            <th>Profesional a cargo</th>
            <th>Correo electrónico</th>
            <th>Estado</th>
            <th>Acciones</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach($areas as $a): ?>
        <tr>
            <td><?= $a['id_area'] ?></td>
            <td><?= htmlspecialchars($a['nombre_area']) ?></td>
            <td><?= htmlspecialchars($a['profesional_area'] ?? '') ?: '<span class="muted">Sin asignar</span>' ?></td>
            <td><?= htmlspecialchars($a['correo_area']) ?></td>
            <td><?= $a['estado'] == 1 ? 'Activo' : 'Inactivo' ?></td>

            <td class="acciones">
                <button
                    type="button"
                    class="btn-edit"
                    onclick="abrirModalEditar(
                        '<?= $a['id_area'] ?>',
                        '<?= htmlspecialchars($a['nombre_area'], ENT_QUOTES) ?>',
                        '<?= htmlspecialchars($a['profesional_area'] ?? '', ENT_QUOTES) ?>',
                        '<?= htmlspecialchars($a['correo_area'], ENT_QUOTES) ?>',
                        '<?= $a['estado'] ?>'
                    )">
                    ✏ Editar
                </button>

                <a
                    class="btn-delete"
                    href="modulos/eliminar_area.php?id=<?= $a['id_area'] ?>"
                    onclick="return confirm('¿Desactivar área?')">
                    🗑 Eliminar
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- =========================
    MODAL EDITAR ÁREA
========================= -->
<div id="modalEditarArea" class="modal-area">
    <div class="modal-content-area">
        <button class="btn-close-modal" onclick="cerrarModalArea()">&times;</button>

        <h3>Editar Área</h3>

        <form action="modulos/editar_area.php" method="POST">
            <input type="hidden" name="id_area" id="edit_id_area">

            <label>Nombre del área</label>
            <input type="text" name="nombre_area" id="edit_nombre_area" required>

            <label>Profesional a cargo</label>
            <input type="text" name="profesional_area" id="edit_profesional_area" placeholder="Nombre del responsable" required>

            <label>Correo</label>
            <input type="email" name="correo_area" id="edit_correo_area" required>

            <label>Estado</label>
            <select name="estado" id="edit_estado">
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
            </select>

            <div class="modal-buttons-area">
                <button type="button" class="btn-cancelar" onclick="cerrarModalArea()">Cancelar</button>
                <button type="submit" class="btn-guardar">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<div id="modalNuevaArea" class="modal-area">
    <div class="modal-content-area">
        <button class="btn-close-modal" onclick="cerrarModalNuevaArea()">&times;</button>

        <h3>Nueva Área</h3>

        <form action="modulos/guardar_area.php" method="POST">

            <label>Nombre del área</label>
            <input type="text" name="nombre_area" required>

            <label>Profesional a cargo</label>
            <input type="text" name="profesional_area" placeholder="Nombre del responsable" required>

            <label>Correo del área</label>
            <input type="email" name="correo_area" required>

            <div class="modal-buttons-area">
                <button type="button" class="btn-cancelar" onclick="cerrarModalNuevaArea()">Cancelar</button>
                <button type="submit" class="btn-guardar">Guardar</button>
            </div>

        </form>
    </div>
</div>

<!-- =========================
    JAVASCRIPT MODAL
========================= -->
<script>
function abrirModalEditar(id, nombre, profesional, correo, estado) {
    document.getElementById('edit_id_area').value = id;
    document.getElementById('edit_nombre_area').value = nombre;
    document.getElementById('edit_profesional_area').value = profesional;
    document.getElementById('edit_correo_area').value = correo;
    document.getElementById('edit_estado').value = estado;

    document.getElementById('modalEditarArea').style.display = 'flex';
}

function cerrarModalArea() {
    document.getElementById('modalEditarArea').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const modal = document.getElementById('modalEditarArea');
    if (e.target === modal) {
        cerrarModalArea();
    }
});

function abrirModalNuevaArea() {
    document.getElementById('modalNuevaArea').style.display = 'flex';
}

function cerrarModalNuevaArea() {
    document.getElementById('modalNuevaArea').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const modalNueva = document.getElementById('modalNuevaArea');
    if (e.target === modalNueva) {
        cerrarModalNuevaArea();
    }
});
</script>
