<?php
require_once __DIR__ . '/../includes/auth.php';
lab_require_permission('laboratorio.blanco_control.ver');

require_once __DIR__ . '/../models/blanco_control_model.php';

$seccion = $_GET['seccion'] ?? 'blanco';
$seccion = in_array($seccion, ['blanco', 'control'], true) ? $seccion : 'blanco';
$msg = $_GET['msg'] ?? '';

$editing = null;
if (!empty($_GET['edit_id'])) {
    $id = (int)$_GET['edit_id'];
    $editing = $seccion === 'blanco' ? obtenerBlancoPorId($id) : obtenerControlPorId($id);
}

$editingBlanco = $seccion === 'blanco' ? $editing : null;
$editingControl = $seccion === 'control' ? $editing : null;
$blancos = listarBlancos();
$controles = listarControles();
$canManageBlancoControl = lab_can('laboratorio.blanco_control.gestionar');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function activoTexto($value) {
    return (int)$value === 1 ? 'Si' : 'No';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blancos y Controles</title>
    <link rel="stylesheet" href="../styles/blanco_control.css?v=20261006">
</head>
<body>
<main class="bc-shell">
    <a class="bc-back" href="../view/labc_index.php">&larr; Volver</a>

    <header class="bc-header">
        <div>
            <span class="bc-kicker">Recepcion</span>
            <h1>Blancos y Controles</h1>
        </div>
        <div class="bc-summary" aria-label="Resumen de registros">
            <span><strong><?= count($blancos) ?></strong> blancos</span>
            <span><strong><?= count($controles) ?></strong> controles</span>
        </div>
    </header>

    <?php if ($msg === 'created'): ?>
        <div class="alerta exito">Registro creado correctamente.</div>
    <?php elseif ($msg === 'updated'): ?>
        <div class="alerta exito">Registro actualizado correctamente.</div>
    <?php elseif ($msg === 'deleted'): ?>
        <div class="alerta exito">Registro eliminado correctamente.</div>
    <?php elseif ($msg === 'missing'): ?>
        <div class="alerta error">Completa los campos obligatorios de la seccion que estas guardando.</div>
    <?php endif; ?>

    <?php if ($canManageBlancoControl): ?>
    <section class="bc-form-grid" aria-label="Formularios de blanco y control">
        <article class="bc-panel <?= $seccion === 'blanco' ? 'is-active' : '' ?>">
            <div class="bc-panel-head">
                <div>
                    <span class="bc-eyebrow">Blanco</span>
                    <h2><?= $editingBlanco ? 'Editar blanco' : 'Nuevo blanco' ?></h2>
                </div>
                <span class="bc-count"><?= count($blancos) ?></span>
            </div>

            <form method="post" action="../controllers/blanco_control_controller.php" class="bc-form">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="seccion" value="blanco">
                <input type="hidden" name="id" value="<?= $editingBlanco ? (int)$editingBlanco['id_blanco'] : '' ?>">

                <label class="bc-field" for="blanco-id-rango">
                    <span>ID Rango</span>
                    <input id="blanco-id-rango" type="number" name="id_rango" required value="<?= $editingBlanco ? (int)$editingBlanco['id_rango'] : '' ?>">
                </label>

                <label class="bc-field" for="blanco-id-tipo">
                    <span>ID Tipo Analisis</span>
                    <input id="blanco-id-tipo" type="number" name="id_tipo_analisis" required value="<?= $editingBlanco ? (int)$editingBlanco['id_tipo_analisis'] : '' ?>">
                </label>

                <label class="bc-field" for="blanco-codigo">
                    <span>Codigo</span>
                    <input id="blanco-codigo" type="text" name="codigo" maxlength="50" required value="<?= $editingBlanco ? e($editingBlanco['codigo']) : '' ?>">
                </label>

                <label class="bc-field" for="blanco-valor">
                    <span>Valor de blanco</span>
                    <input id="blanco-valor" type="number" step="any" name="valor_blanco" required value="<?= $editingBlanco ? e($editingBlanco['valor']) : '' ?>">
                </label>

                <label class="bc-field full" for="blanco-descripcion">
                    <span>Descripcion</span>
                    <input id="blanco-descripcion" type="text" name="descripcion" maxlength="255" value="<?= $editingBlanco ? e($editingBlanco['descripcion']) : '' ?>">
                </label>

                <label class="bc-check" for="blanco-activo">
                    <input id="blanco-activo" type="checkbox" name="activo" value="1" <?= (!$editingBlanco || (int)$editingBlanco['activo'] === 1) ? 'checked' : '' ?>>
                    <span>Activo</span>
                </label>

                <div class="bc-actions">
                    <?php if ($editingBlanco): ?>
                        <a class="bc-button bc-button-quiet" href="?seccion=blanco">Cancelar</a>
                    <?php endif; ?>
                    <button class="bc-button bc-button-primary" type="submit"><?= $editingBlanco ? 'Actualizar blanco' : 'Guardar blanco' ?></button>
                </div>
            </form>
        </article>

        <article class="bc-panel <?= $seccion === 'control' ? 'is-active' : '' ?>">
            <div class="bc-panel-head">
                <div>
                    <span class="bc-eyebrow">Control</span>
                    <h2><?= $editingControl ? 'Editar control' : 'Nuevo control' ?></h2>
                </div>
                <span class="bc-count accent"><?= count($controles) ?></span>
            </div>

            <form method="post" action="../controllers/blanco_control_controller.php" class="bc-form">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="seccion" value="control">
                <input type="hidden" name="id" value="<?= $editingControl ? (int)$editingControl['id_control'] : '' ?>">

                <label class="bc-field" for="control-id-rango">
                    <span>ID Rango</span>
                    <input id="control-id-rango" type="number" name="id_rango" required value="<?= $editingControl ? (int)$editingControl['id_rango'] : '' ?>">
                </label>

                <label class="bc-field" for="control-id-tipo">
                    <span>ID Tipo Analisis</span>
                    <input id="control-id-tipo" type="number" name="id_tipo_analisis" required value="<?= $editingControl ? (int)$editingControl['id_tipo_analisis'] : '' ?>">
                </label>

                <label class="bc-field" for="control-codigo">
                    <span>Codigo</span>
                    <input id="control-codigo" type="text" name="codigo" maxlength="50" required value="<?= $editingControl ? e($editingControl['codigo']) : '' ?>">
                </label>

                <label class="bc-field" for="control-valor">
                    <span>Valor de control</span>
                    <input id="control-valor" type="number" step="any" name="valor_control" required value="<?= $editingControl ? e($editingControl['valor']) : '' ?>">
                </label>

                <label class="bc-field" for="control-minimo">
                    <span>Minimo</span>
                    <input id="control-minimo" type="number" step="any" name="minimo_control" value="<?= $editingControl ? e($editingControl['minimo']) : '' ?>">
                </label>

                <label class="bc-field" for="control-maximo">
                    <span>Maximo</span>
                    <input id="control-maximo" type="number" step="any" name="maximo_control" value="<?= $editingControl ? e($editingControl['maximo']) : '' ?>">
                </label>

                <label class="bc-field full" for="control-descripcion">
                    <span>Descripcion</span>
                    <input id="control-descripcion" type="text" name="descripcion" maxlength="255" value="<?= $editingControl ? e($editingControl['descripcion']) : '' ?>">
                </label>

                <label class="bc-check" for="control-activo">
                    <input id="control-activo" type="checkbox" name="activo" value="1" <?= (!$editingControl || (int)$editingControl['activo'] === 1) ? 'checked' : '' ?>>
                    <span>Activo</span>
                </label>

                <div class="bc-actions">
                    <?php if ($editingControl): ?>
                        <a class="bc-button bc-button-quiet" href="?seccion=control">Cancelar</a>
                    <?php endif; ?>
                    <button class="bc-button bc-button-primary accent" type="submit"><?= $editingControl ? 'Actualizar control' : 'Guardar control' ?></button>
                </div>
            </form>
        </article>
    </section>
    <?php endif; ?>

    <section class="bc-table-panel" aria-labelledby="tabla-blancos">
        <div class="bc-table-head">
            <h2 id="tabla-blancos">Registros de blanco</h2>
        </div>
        <div class="bc-table-wrap">
            <table class="bc-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ID Rango</th>
                        <th>ID Tipo Analisis</th>
                        <th>Codigo</th>
                        <th>Descripcion</th>
                        <th>Valor</th>
                        <th>Activo</th>
                        <?php if ($canManageBlancoControl): ?>
                            <th>Accion</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($blancos)): ?>
                        <tr><td colspan="<?= $canManageBlancoControl ? 8 : 7 ?>" class="bc-empty">No hay registros de blanco.</td></tr>
                    <?php else: ?>
                        <?php foreach ($blancos as $row): ?>
                            <tr>
                                <td><?= (int)$row['id_blanco'] ?></td>
                                <td><?= (int)$row['id_rango'] ?></td>
                                <td><?= (int)$row['id_tipo_analisis'] ?></td>
                                <td><?= e($row['codigo']) ?></td>
                                <td><?= e($row['descripcion']) ?></td>
                                <td><?= e($row['valor']) ?></td>
                                <td><span class="bc-status <?= (int)$row['activo'] === 1 ? 'is-on' : '' ?>"><?= activoTexto($row['activo']) ?></span></td>
                                <?php if ($canManageBlancoControl): ?>
                                    <td>
                                        <div class="bc-row-actions">
                                            <a href="?seccion=blanco&edit_id=<?= (int)$row['id_blanco'] ?>">Editar</a>
                                            <a class="danger" href="../controllers/blanco_control_controller.php?action=delete&seccion=blanco&id=<?= (int)$row['id_blanco'] ?>" onclick="return confirm('Eliminar este registro de blanco?')">Eliminar</a>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="bc-table-panel" aria-labelledby="tabla-controles">
        <div class="bc-table-head">
            <h2 id="tabla-controles">Registros de control</h2>
        </div>
        <div class="bc-table-wrap">
            <table class="bc-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ID Rango</th>
                        <th>ID Tipo Analisis</th>
                        <th>Codigo</th>
                        <th>Descripcion</th>
                        <th>Valor</th>
                        <th>Minimo</th>
                        <th>Maximo</th>
                        <th>Activo</th>
                        <?php if ($canManageBlancoControl): ?>
                            <th>Accion</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($controles)): ?>
                        <tr><td colspan="<?= $canManageBlancoControl ? 10 : 9 ?>" class="bc-empty">No hay registros de control.</td></tr>
                    <?php else: ?>
                        <?php foreach ($controles as $row): ?>
                            <tr>
                                <td><?= (int)$row['id_control'] ?></td>
                                <td><?= (int)$row['id_rango'] ?></td>
                                <td><?= (int)$row['id_tipo_analisis'] ?></td>
                                <td><?= e($row['codigo']) ?></td>
                                <td><?= e($row['descripcion']) ?></td>
                                <td><?= e($row['valor']) ?></td>
                                <td><?= e($row['minimo']) ?></td>
                                <td><?= e($row['maximo']) ?></td>
                                <td><span class="bc-status <?= (int)$row['activo'] === 1 ? 'is-on' : '' ?>"><?= activoTexto($row['activo']) ?></span></td>
                                <?php if ($canManageBlancoControl): ?>
                                    <td>
                                        <div class="bc-row-actions">
                                            <a href="?seccion=control&edit_id=<?= (int)$row['id_control'] ?>">Editar</a>
                                            <a class="danger" href="../controllers/blanco_control_controller.php?action=delete&seccion=control&id=<?= (int)$row['id_control'] ?>" onclick="return confirm('Eliminar este registro de control?')">Eliminar</a>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
