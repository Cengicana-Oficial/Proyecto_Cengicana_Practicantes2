<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';

function eErrorForm($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fechaErrorForm($fecha): string
{
    if (!$fecha) {
        return '-';
    }

    $timestamp = strtotime($fecha);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : (string) $fecha;
}

function labelErrorForm($value): string
{
    return ucwords(str_replace('_', ' ', (string) $value));
}

function columnaValorErrorForm(array $fila, array $nombres, $default = '')
{
    foreach ($nombres as $nombre) {
        if (array_key_exists($nombre, $fila) && $fila[$nombre] !== null && $fila[$nombre] !== '') {
            return $fila[$nombre];
        }
    }

    return $default;
}

function columnasErrorForm(array $tabla): array
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
    foreach ($tabla['columnas'] ?? [] as $columna) {
        $nombre = (string) ($columna['Field'] ?? '');
        if ($nombre === '' || in_array($nombre, $ocultas, true)) {
            continue;
        }
        $columnas[] = $nombre;
    }

    return $columnas;
}

$detalleDatos = is_array($detalleError['datos'] ?? null) ? $detalleError['datos'] : [];
$detalleFormulario = is_array($detalleDatos['formulario'] ?? null) ? $detalleDatos['formulario'] : [];
$detalleTablas = is_array($detalleDatos['tablas'] ?? null) ? $detalleDatos['tablas'] : [];

$subtituloErrores = $detalleError
    ? 'Detalle del formulario #' . (int) $detalleError['id_formulario'] . ' · Version v' . (int) $detalleError['version_numero']
    : count($formulariosErroneos) . ' registro(s) conservados para revision';

lab_shell_head('Formularios con errores', $subtituloErrores, [
    '../styles/consolidacion.css?v=2',
    '../css/revision_shell.css?v=1',
]);
lab_shell_open('validacion_tecnica_view.php');
?>
<div class="review-wrap cengi-shell-page">
    <div class="cengi-page-actions">
        <a href="../view/validacion_tecnica_view.php" class="cengi-btn cengi-btn-ghost cengi-btn-sm">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver a validacion tecnica
        </a>
        <?php if ($detalleError): ?>
            <a class="cengi-btn cengi-btn-primary cengi-btn-sm" href="../controllers/formularios_erroneos_controller.php">
                <span class="material-symbols-outlined">list</span>
                Ver listado
            </a>
        <?php endif; ?>
    </div>

    <?php if ($detalleError): ?>
        <div class="summary-row">
            <span>Formulario #<?= (int) $detalleError['id_formulario'] ?></span>
            <span>Version v<?= (int) $detalleError['version_numero'] ?></span>
            <span>Lote <?= eErrorForm($detalleError['codigo_lote'] ?? '-') ?></span>
            <span><?= eErrorForm($detalleError['analisis_nombre'] ?? '-') ?></span>
            <span>Guardado <?= eErrorForm(fechaErrorForm($detalleError['fecha'] ?? null)) ?></span>
        </div>

        <section class="review-section cengi-card">
            <div class="review-section-head">
                <div>
                    <h2>Datos originales con errores</h2>
                    <div class="review-id">Registrado por <?= eErrorForm($detalleError['usuario'] ?? '-') ?></div>
                </div>
                <?php if (!empty($detalleError['id_rango'])): ?>
                    <a class="cengi-btn cengi-btn-ghost cengi-btn-sm" href="../controllers/formulario_revision_controller.php?id_rango=<?= urlencode((string) $detalleError['id_rango']) ?>">
                        <span class="material-symbols-outlined">fact_check</span>
                        Abrir revision actual
                    </a>
                <?php endif; ?>
            </div>

            <div class="review-meta">
                <label>Tipo muestra
                    <input type="text" value="<?= eErrorForm($detalleError['tipo_muestra'] ?? '-') ?>" disabled>
                </label>
                <label>Rango
                    <input type="text" value="<?= eErrorForm(($detalleError['inicio'] ?? '-') . ' - ' . ($detalleError['fin'] ?? '-')) ?>" disabled>
                </label>
                <label>Fecha analisis original
                    <input type="text" value="<?= eErrorForm($detalleFormulario['fecha'] ?? '-') ?>" disabled>
                </label>
                <label>Analista original
                    <input type="text" value="<?= eErrorForm($detalleFormulario['analista'] ?? '-') ?>" disabled>
                </label>
            </div>

            <?php if (!empty($detalleError['comentario'])): ?>
                <div class="review-alert">
                    <?= nl2br(eErrorForm($detalleError['comentario'])) ?>
                </div>
            <?php endif; ?>

            <?php if (!$detalleTablas): ?>
                <div class="empty-state compact">La version guardada no tiene datos detallados.</div>
            <?php else: ?>
                <?php foreach ($detalleTablas as $tabla): ?>
                    <?php $columnas = columnasErrorForm($tabla); ?>
                    <div class="table-shell review-table-shell cengi-table-wrap">
                        <table class="consolidacion-table review-table">
                            <caption><?= eErrorForm(labelErrorForm($tabla['tabla'] ?? 'Datos')) ?></caption>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Lote</th>
                                    <th>Numero de laboratorio</th>
                                    <?php foreach ($columnas as $columna): ?>
                                        <th><?= eErrorForm(labelErrorForm($columna)) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($tabla['filas'] ?? []) as $index => $fila): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= eErrorForm(columnaValorErrorForm($fila, ['lote', 'codigo_lote'], $detalleError['codigo_lote'] ?? '-')) ?></td>
                                        <td><?= eErrorForm(columnaValorErrorForm($fila, ['numero_laboratorio', 'no_lab', 'numero_muestra'], '-')) ?></td>
                                        <?php foreach ($columnas as $columna): ?>
                                            <td><?= eErrorForm($fila[$columna] ?? '') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <div class="summary-row">
            <span><?= count($formulariosErroneos) ?> registro(s)</span>
        </div>

        <?php if (!$formulariosErroneos): ?>
            <div class="empty-state">Aun no hay formularios guardados con errores.</div>
        <?php else: ?>
            <div class="table-shell cengi-table-wrap">
                <table class="consolidacion-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Lote</th>
                            <th>Rango</th>
                            <th>Tipo</th>
                            <th>Analisis</th>
                            <th>Formulario</th>
                            <th>Usuario</th>
                            <th>Comentario</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($formulariosErroneos as $item): ?>
                            <tr>
                                <td><?= eErrorForm(fechaErrorForm($item['fecha'] ?? null)) ?></td>
                                <td><?= eErrorForm($item['codigo_lote'] ?? '-') ?></td>
                                <td><?= eErrorForm(($item['inicio'] ?? '-') . ' - ' . ($item['fin'] ?? '-')) ?></td>
                                <td><?= eErrorForm($item['tipo_muestra'] ?? '-') ?></td>
                                <td><?= eErrorForm($item['analisis_nombre'] ?? '-') ?></td>
                                <td>#<?= (int) $item['id_formulario'] ?> / v<?= (int) $item['version_numero'] ?></td>
                                <td><?= eErrorForm($item['usuario'] ?? '-') ?></td>
                                <td><?= eErrorForm($item['comentario'] ?? '-') ?></td>
                                <td>
                                    <a class="cengi-btn cengi-btn-ghost cengi-btn-sm" href="../controllers/formularios_erroneos_controller.php?id_version=<?= (int) $item['id_version'] ?>">
                                        <span class="material-symbols-outlined">visibility</span>
                                        Ver
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php lab_shell_close(); ?>
