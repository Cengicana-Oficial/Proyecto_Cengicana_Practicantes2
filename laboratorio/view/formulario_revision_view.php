<?php
require_once __DIR__ . '/../includes/auth.php';

function eRevision($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fechaRevision($fecha)
{
    if (!$fecha) {
        return '-';
    }

    $timestamp = strtotime($fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : $fecha;
}

function inputTypeRevision($value)
{
    if (is_numeric($value)) {
        return 'number';
    }

    return 'text';
}

function labelRevision($value)
{
    $value = str_replace('_', ' ', (string) $value);
    return ucwords($value);
}

function columnaValorRevision(array $fila, array $nombres, $default = '')
{
    foreach ($nombres as $nombre) {
        if (array_key_exists($nombre, $fila) && $fila[$nombre] !== null && $fila[$nombre] !== '') {
            return $fila[$nombre];
        }
    }

    return $default;
}

function columnasAnalisisRevision(array $tabla): array
{
    $pk = $tabla['primary_key'] ?? null;
    $ocultas = [
        $pk,
        'id_formulario',
        'id_encabezado',
        'numero_laboratorio',
        'numero_muestra',
        'no_lab',
        'lote',
        'codigo_lote',
    ];

    $columnas = [];
    foreach ($tabla['columnas'] as $columna) {
        $nombre = (string) ($columna['Field'] ?? '');
        if ($nombre === '' || in_array($nombre, $ocultas, true)) {
            continue;
        }
        $columnas[] = $nombre;
    }

    return $columnas;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revision de formulario</title>
    <link rel="stylesheet" href="../styles/formularios.css">
</head>
<body>
<div class="page-wrap">
    <a href="../controllers/consolidacion_controller.php" class="back-link">Volver a consolidacion</a>

    <h2>Revision de formulario <?= eRevision($resumenRango['codigo_lote'] ?? '-') ?></h2>

    <?php if ($mensajeRevision): ?>
        <div class="alerta exito"><?= eRevision($mensajeRevision) ?></div>
    <?php endif; ?>
    <?php if ($errorRevision): ?>
        <div class="alerta error"><?= eRevision($errorRevision) ?></div>
    <?php endif; ?>

    <?php if (!$puedeEditarRevision && !$puedeGuardarErrores): ?>
        <div class="alerta">Solo puede ver esta revision. Para editar, aprobar o marcar errores se necesitan permisos adicionales.</div>
    <?php endif; ?>

    <?php if (empty($formulariosRevision)): ?>
        <div class="alerta">Este rango aun no tiene formularios ingresados.</div>
    <?php else: ?>
        <div class="review-summary">
            <span>Tipo <?= eRevision($resumenRango['tipo_muestra'] ?? '-') ?></span>
            <span>Ingreso <?= eRevision(fechaRevision($resumenRango['fecha_ingreso'] ?? null)) ?></span>
            <span>Rango <?= eRevision($resumenRango['inicio'] ?? '-') ?> - <?= eRevision($resumenRango['fin'] ?? '-') ?></span>
            <span><?= count($formulariosRevision) ?> formulario(s)</span>
        </div>

        <form method="POST">
            <input type="hidden" name="id_rango" value="<?= (int) $idRango ?>">
            <?php foreach ($formulariosRevision as $formulario): ?>
                <?php
                    $idFormulario = (int) $formulario['id_formulario'];
                    $doc_elemento = $formulario['analisis_nombre'] ?: 'Analisis';
                    $doc_tipo = $resumenRango['tipo_muestra'] ?? 'muestra';
                    $doc_codigo = 'LAB-' . str_pad((string) $idFormulario, 3, '0', STR_PAD_LEFT);
                    $doc_edicion = '001';
                    $doc_vf = 'VF-000';
                ?>
                <div class="card revision-card">
                    <?php include __DIR__ . '/../components/encabezado_doc.php'; ?>

                    <div class="form-body">
                        <div class="revision-status-row">
                            <span class="revision-pill"><?= eRevision($formulario['estado_nombre'] ?: 'Revisar') ?></span>
                            <span class="revision-pill">Formulario #<?= $idFormulario ?></span>
                        </div>

                        <div class="form-footer revision-footer">
                            <div class="footer-grid">
                                <div class="field">
                                    <label>Fecha analisis</label>
                                    <input
                                        type="date"
                                        name="formulario[<?= $idFormulario ?>][fecha]"
                                        value="<?= eRevision($formulario['fecha'] ?? '') ?>"
                                        <?= $puedeEditarRevision ? '' : 'disabled' ?>>
                                </div>
                                <div class="field">
                                    <label>Analista</label>
                                    <input
                                        type="text"
                                        name="formulario[<?= $idFormulario ?>][analista]"
                                        value="<?= eRevision($formulario['analista'] ?? '') ?>"
                                        placeholder="Nombre del analista"
                                        <?= $puedeEditarRevision ? '' : 'disabled' ?>>
                                </div>
                                <div class="field full">
                                    <label>Observaciones de revision</label>
                                    <textarea name="comentario_revision[<?= $idFormulario ?>]" placeholder="Opcional..." <?= ($puedeEditarRevision || $puedeGuardarErrores) ? '' : 'disabled' ?>></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="section-title">Datos de analisis</div>
                        <?php if (empty($formulario['tablas'])): ?>
                            <div class="alerta">No se encontraron datos detallados enlazados a este formulario.</div>
                        <?php else: ?>
                            <?php foreach ($formulario['tablas'] as $tabla): ?>
                                <?php
                                    $pk = $tabla['primary_key'] ?? null;
                                    $editables = labFormularioColumnasEditables($tabla);
                                    $columnasAnalisis = columnasAnalisisRevision($tabla);
                                ?>
                                <div class="lab-table-panel">
                                    <div class="lab-table-toolbar">
                                        <div class="section-title">Datos de analisis por muestra</div>
                                    </div>
                                    <div class="table-wrap lab-entry-table-wrap">
                                        <table class="lab-entry-table revision-entry-table">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Lote</th>
                                                    <th>Numero de laboratorio</th>
                                                    <?php foreach ($columnasAnalisis as $columna): ?>
                                                        <th><?= eRevision(labelRevision($columna)) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tabla['filas'] as $index => $fila): ?>
                                                    <?php $idFila = $pk !== null ? ($fila[$pk] ?? $index) : $index; ?>
                                                    <tr>
                                                        <td><?= $index + 1 ?></td>
                                                        <td><?= eRevision(columnaValorRevision($fila, ['lote', 'codigo_lote'], $resumenRango['codigo_lote'] ?? '-')) ?></td>
                                                        <td><?= eRevision(columnaValorRevision($fila, ['no_lab', 'numero_laboratorio', 'numero_muestra'], '-')) ?></td>
                                                        <?php foreach ($columnasAnalisis as $columna): ?>
                                                            <?php $valor = $fila[$columna] ?? ''; ?>
                                                            <td>
                                                                <?php if ($pk !== null && $puedeEditarRevision && in_array($columna, $editables, true)): ?>
                                                                    <input
                                                                        type="<?= inputTypeRevision($valor) ?>"
                                                                        name="datos[<?= $idFormulario ?>][<?= eRevision($tabla['tabla']) ?>][<?= eRevision($idFila) ?>][<?= eRevision($columna) ?>]"
                                                                        value="<?= eRevision($valor) ?>"
                                                                        step="any">
                                                                <?php else: ?>
                                                                    <?= eRevision($valor) ?>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="revision-version-list">
                            <strong>Versiones guardadas</strong>
                            <?php foreach ($formulario['versiones'] as $version): ?>
                                <span>
                                    v<?= (int) $version['version_numero'] ?>
                                    <?= eRevision(labelRevision($version['tipo_version'])) ?>
                                    <?= eRevision(fechaRevision($version['fecha'] ?? null)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($puedeGuardarErrores || $puedeGuardarCorreccion || $puedeAprobarRevision): ?>
                <div class="revision-actions">
                    <?php if ($puedeGuardarErrores): ?>
                        <button class="btn-submit secondary" type="submit" name="accion" value="marcar_error">Guardar con errores</button>
                    <?php endif; ?>
                    <?php if ($puedeGuardarCorreccion): ?>
                        <button class="btn-submit secondary" type="submit" name="accion" value="guardar">Guardar correccion</button>
                    <?php endif; ?>
                    <?php if ($puedeAprobarRevision): ?>
                        <button class="btn-submit" type="submit" name="accion" value="aprobar">Aprobar formulario</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
