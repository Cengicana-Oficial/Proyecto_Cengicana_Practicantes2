<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';

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

function labelRevision($value)
{
    $value = str_replace('_', ' ', (string) $value);
    return ucwords($value);
}

function revisionFormatoValor($value): string
{
    if (is_bool($value)) {
        return $value ? 'Sí' : 'No';
    }

    if (is_array($value) || is_object($value)) {
        return '';
    }

    $texto = trim((string) $value);
    return $texto === '' ? '-' : $texto;
}

function revisionCamposLista(array $tabla, array $fila): array
{
    $pk = $tabla['primary_key'] ?? null;
    $ocultos = [
        $pk,
        'id_formulario',
        'id_encabezado',
        'numero_laboratorio',
        'numero_muestra',
        'no_lab',
        'lote',
        'codigo_lote',
    ];

    $capturados = [];
    $resultados = [];

    foreach (($tabla['columnas'] ?? []) as $columna) {
        $nombre = (string) ($columna['Field'] ?? '');
        if ($nombre === '' || in_array($nombre, $ocultos, true)) {
            continue;
        }

        if (!array_key_exists($nombre, $fila)) {
            continue;
        }

        $valor = revisionFormatoValor($fila[$nombre]);
        if ($valor === '-') {
            continue;
        }

        $item = [
            'etiqueta' => labFormularioRevisionEtiquetaCampo($nombre),
            'valor' => $valor,
        ];

        if (labFormularioRevisionPrincipalCampoOrden($nombre) < 9) {
            $resultados[] = $item;
        } else {
            $capturados[] = $item;
        }
    }

    return [
        'capturados' => $capturados,
        'resultados' => $resultados,
    ];
}

$formulariosRevision = $formulariosRevision ?? [];
$puedeAprobarRevision = (bool) ($puedeAprobarRevision ?? false);
$puedeGuardarErrores = (bool) ($puedeGuardarErrores ?? false);
$puedeVerObservacion = $puedeAprobarRevision || $puedeGuardarErrores;

$codigoLoteRevision = (string) ($resumenRango['codigo_lote'] ?? '-');
$subtituloRevision = 'Lote ' . $codigoLoteRevision
    . ' · Rango ' . ($resumenRango['inicio'] ?? '-')
    . ' - ' . ($resumenRango['fin'] ?? '-');

lab_shell_head('Revision de formulario', $subtituloRevision, [
    '../styles/formularios.css?v=2',
    '../css/revision_shell.css?v=1',
]);
lab_shell_open('validacion_tecnica_view.php');
?>
<div class="revision-page cengi-shell-page">
    <div class="cengi-page-actions">
        <a href="../view/validacion_tecnica_view.php" class="cengi-btn cengi-btn-ghost cengi-btn-sm">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver a validacion tecnica
        </a>
    </div>

    <?php if ($mensajeRevision): ?>
        <div class="alerta exito"><?= eRevision($mensajeRevision) ?></div>
    <?php endif; ?>
    <?php if ($errorRevision): ?>
        <div class="alerta error"><?= eRevision($errorRevision) ?></div>
    <?php endif; ?>

    <?php if (!$puedeVerObservacion): ?>
        <div class="alerta">Solo puede ver esta revision. Para aprobar o mandar a corregir se necesitan permisos adicionales.</div>
    <?php endif; ?>

    <?php if (empty($formulariosRevision)): ?>
        <div class="alerta">Este rango aun no tiene formularios ingresados.</div>
    <?php else: ?>
        <div class="review-summary">
            <span>Tipo <?= eRevision($resumenRango['tipo_muestra'] ?? '-') ?></span>
            <span>Ingreso <?= eRevision(fechaRevision($resumenRango['fecha_ingreso'] ?? null)) ?></span>
            <span>Rango <?= eRevision($resumenRango['inicio'] ?? '-') ?> - <?= eRevision($resumenRango['fin'] ?? '-') ?></span>
            <span><?= count($formulariosRevision) ?> laboratorio(s)</span>
        </div>

        <form method="POST" class="revision-form">
            <input type="hidden" name="id_rango" value="<?= (int) $idRango ?>">

            <div class="revision-workbench">
                <div class="table-shell revision-table-shell cengi-table-wrap">
                    <table class="consolidacion-table revision-table">
                        <thead>
                            <tr>
                                <th>Número de laboratorio</th>
                                <th>Resultado resumido</th>
                                <th>Estado</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($formulariosRevision as $indice => $grupo): ?>
                                <?php
                                    $numeroLaboratorio = trim((string) ($grupo['numero_laboratorio'] ?? '-'));
                                    $formulariosGrupo = $grupo['formularios'] ?? [];
                                    $estadoResumen = $grupo['estado_resumen'] ?? ['texto' => 'Revisar', 'clase' => 'estado-revision'];
                                    $resultadoResumen = trim((string) ($grupo['resultado_resumen'] ?? '-'));
                                    $panelId = 'revision-group-' . ($indice + 1);
                                ?>
                                <tr class="revision-summary-row" data-revision-row="<?= eRevision($panelId) ?>">
                                    <td>
                                        <div class="revision-lab-cell">
                                            <strong><?= eRevision($numeroLaboratorio) ?></strong>
                                            <span><?= count($formulariosGrupo) ?> formulario(s)</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="revision-result-cell">
                                            <?= eRevision($resultadoResumen) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="revision-pill <?= eRevision((string) ($estadoResumen['clase'] ?? 'is-neutral')) ?>">
                                            <?= eRevision((string) ($estadoResumen['texto'] ?? 'Revisar')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="cengi-btn cengi-btn-ghost cengi-btn-sm revision-toggle"
                                            data-revision-toggle
                                            aria-expanded="false"
                                            aria-controls="<?= eRevision($panelId) ?>">
                                            Detalle
                                        </button>
                                    </td>
                                </tr>
                                <tr class="revision-detail-row" id="<?= eRevision($panelId) ?>" hidden>
                                    <td colspan="4">
                                        <div class="revision-panel">
                                            <div class="revision-panel-head">
                                                <div class="revision-panel-copy">
                                                    <span class="revision-panel-kicker">Laboratorio <?= eRevision($numeroLaboratorio) ?></span>
                                                    <h3><?= eRevision($resumenRango['codigo_lote'] ?? '-') ?></h3>
                                                    <p>Rango <?= eRevision($resumenRango['inicio'] ?? '-') ?> - <?= eRevision($resumenRango['fin'] ?? '-') ?></p>
                                                </div>
                                                <div class="revision-version-list">
                                                    <strong><?= count($formulariosGrupo) ?> formulario(s)</strong>
                                                    <?php foreach ($formulariosGrupo as $formulario): ?>
                                                        <span>
                                                            Formulario #<?= (int) $formulario['id_formulario'] ?>
                                                            · <?= eRevision($formulario['analisis_nombre'] ?: 'Análisis') ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div class="revision-detail-block">
                                                <?php foreach ($formulariosGrupo as $formulario): ?>
                                                    <?php
                                                        $idFormulario = (int) $formulario['id_formulario'];
                                                        $tablasFormulario = $formulario['tablas'] ?? [];
                                                        $versionesFormulario = $formulario['versiones'] ?? [];
                                                    ?>
                                                    <section class="revision-form-card">
                                                        <div class="revision-form-head">
                                                            <div class="revision-panel-copy">
                                                                <span class="revision-panel-kicker">Formulario #<?= $idFormulario ?></span>
                                                                <h3><?= eRevision($formulario['analisis_nombre'] ?: 'Análisis') ?></h3>
                                                                <p><?= eRevision($formulario['numero_laboratorio'] ?? $numeroLaboratorio) ?></p>
                                                            </div>
                                                            <div class="revision-version-list">
                                                                <strong>Versiones guardadas</strong>
                                                                <?php foreach ($versionesFormulario as $version): ?>
                                                                    <span>
                                                                        v<?= (int) $version['version_numero'] ?>
                                                                        <?= eRevision(labelRevision($version['tipo_version'])) ?>
                                                                        <?= eRevision(fechaRevision($version['fecha'] ?? null)) ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>

                                                        <div class="revision-form-summary">
                                                            <span class="revision-mini-label">Resultado principal</span>
                                                            <strong><?= eRevision($formulario['resultado_principal'] ?? '-') ?></strong>
                                                        </div>

                                                        <div class="revision-form-data">
                                                            <div class="section-title">Datos capturados y resultados</div>
                                                            <?php if (empty($tablasFormulario)): ?>
                                                                <div class="alerta">No se encontraron datos detallados enlazados a este formulario.</div>
                                                            <?php else: ?>
                                                                <?php foreach ($tablasFormulario as $tabla): ?>
                                                                    <div class="revision-dataset">
                                                                        <div class="revision-dataset-head">
                                                                            <div class="section-title"><?= eRevision(labelRevision($tabla['tabla'] ?? 'Datos')) ?></div>
                                                                            <span class="revision-dataset-meta"><?= count($tabla['filas'] ?? []) ?> fila(s)</span>
                                                                        </div>
                                                                        <div class="revision-row-list">
                                                                            <?php foreach (($tabla['filas'] ?? []) as $index => $fila): ?>
                                                                                <?php $camposFila = revisionCamposLista($tabla, $fila); ?>
                                                                                <section class="revision-row-block">
                                                                                    <?php if (count($tabla['filas'] ?? []) > 1): ?>
                                                                                        <div class="revision-row-head">
                                                                                            <span class="revision-row-badge">Fila <?= $index + 1 ?></span>
                                                                                        </div>
                                                                                    <?php endif; ?>

                                                                                    <div class="revision-row-grid">
                                                                                        <div class="revision-list-group">
                                                                                            <div class="revision-list-title">Datos capturados</div>
                                                                                            <?php if (empty($camposFila['capturados'])): ?>
                                                                                                <div class="revision-empty-list">Sin datos capturados visibles.</div>
                                                                                            <?php else: ?>
                                                                                                <dl class="revision-parameter-list">
                                                                                                    <?php foreach ($camposFila['capturados'] as $campo): ?>
                                                                                                        <div class="revision-parameter-item">
                                                                                                            <dt><?= eRevision($campo['etiqueta']) ?></dt>
                                                                                                            <dd><?= eRevision($campo['valor']) ?></dd>
                                                                                                        </div>
                                                                                                    <?php endforeach; ?>
                                                                                                </dl>
                                                                                            <?php endif; ?>
                                                                                        </div>

                                                                                        <div class="revision-list-group">
                                                                                            <div class="revision-list-title">Resultados calculados</div>
                                                                                            <?php if (empty($camposFila['resultados'])): ?>
                                                                                                <div class="revision-empty-list">Sin resultados calculados visibles.</div>
                                                                                            <?php else: ?>
                                                                                                <dl class="revision-parameter-list">
                                                                                                    <?php foreach ($camposFila['resultados'] as $campo): ?>
                                                                                                        <div class="revision-parameter-item is-result">
                                                                                                            <dt><?= eRevision($campo['etiqueta']) ?></dt>
                                                                                                            <dd><?= eRevision($campo['valor']) ?></dd>
                                                                                                        </div>
                                                                                                    <?php endforeach; ?>
                                                                                                </dl>
                                                                                            <?php endif; ?>
                                                                                        </div>
                                                                                    </div>
                                                                                </section>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </section>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($puedeVerObservacion): ?>
                <div class="revision-review-actions">
                    <div class="field full">
                        <label for="comentarioRevision">Observación del técnico</label>
                        <textarea id="comentarioRevision" name="comentario_revision" placeholder="Escribe la observación antes de aprobar o mandar a corregir."></textarea>
                    </div>

                    <div class="revision-panel-actions">
                        <?php if ($puedeGuardarErrores): ?>
                            <button class="cengi-btn cengi-btn-sm is-danger" type="submit" name="accion" value="marcar_error" data-requires-comment="1">
                                <span class="material-symbols-outlined">error</span>
                                Mandar a corregir
                            </button>
                        <?php endif; ?>
                        <?php if ($puedeAprobarRevision): ?>
                            <button class="cengi-btn cengi-btn-primary cengi-btn-sm" type="submit" name="accion" value="aprobar">
                                <span class="material-symbols-outlined">check_circle</span>
                                Aprobar
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
<script>
(function () {
    const toggleButtons = Array.from(document.querySelectorAll('[data-revision-toggle]'));
    const panelRows = Array.from(document.querySelectorAll('.revision-detail-row'));
    const form = document.querySelector('.revision-form');
    const observation = document.getElementById('comentarioRevision');

    function closeAll() {
        toggleButtons.forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.textContent = 'Detalle';
        });
        panelRows.forEach((panel) => {
            panel.hidden = true;
        });
    }

    toggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const panelId = button.getAttribute('aria-controls');
            const panel = panelId ? document.getElementById(panelId) : null;
            const isOpen = button.getAttribute('aria-expanded') === 'true';

            closeAll();

            if (!isOpen && panel) {
                panel.hidden = false;
                button.setAttribute('aria-expanded', 'true');
                button.textContent = 'Cerrar';
            }
        });
    });

    if (form && observation) {
        form.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLButtonElement)) {
                return;
            }

            if (target.dataset.requiresComment === '1') {
                observation.required = true;
            } else if (target.name === 'accion' && target.value === 'aprobar') {
                observation.required = false;
            }
        });
    }
})();
</script>
<?php lab_shell_close(); ?>
