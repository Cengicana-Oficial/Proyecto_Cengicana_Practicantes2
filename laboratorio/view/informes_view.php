<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/informes_model.php';

lab_require_permission('laboratorio.informes.ver');

$pdo = Conexion::conectar();
$panelInformes = lab_informes_panel($pdo);
$puedeFirmar = lab_can('laboratorio.firma.gestionar');

function informes_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function informes_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '—';
    }
    $timestamp = strtotime($fecha);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $fecha;
}

function informes_svg(string $icono): string
{
    static $trazos = [
        'ok' => '<path d="m8 12 2.7 2.7L16.5 9"/><circle cx="12" cy="12" r="9"/>',
        'suelos' => '<path d="m3 18 5.2-7 3.3 4.2L14.5 11l6.5 7H3Z"/><path d="m6.7 13 1.5-2 1.1 1.4"/>',
        'agua' => '<path d="M12 3s6 6.4 6 11a6 6 0 0 1-12 0c0-4.6 6-11 6-11Z"/>',
        'foliares' => '<path d="M20 4C10 4 5 8.2 5 14c0 3.1 2.2 5 5 5 6 0 9-7 10-15Z"/><path d="M4 21c2.4-5.3 6.2-8.8 11-11"/>',
        'cana' => '<path d="M12 21V9M12 12c-4.2 0-7-2.2-7-6 4.2 0 7 2.2 7 6ZM12 16c4.2 0 7-2.2 7-6-4.2 0-7 2.2-7 6Z"/>',
        'otros' => '<path d="M9 3h6M10 3v5.2L5.6 18a2 2 0 0 0 1.8 3h9.2a2 2 0 0 0 1.8-3L14 8.2V3"/><path d="M8 15h8"/>',
        'flecha' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];
    $clave = strtolower(trim($icono));
    if (str_contains($clave, 'suelo')) {
        $clave = 'suelos';
    } elseif (str_contains($clave, 'agua')) {
        $clave = 'agua';
    } elseif (str_contains($clave, 'foliar')) {
        $clave = 'foliares';
    } elseif (str_contains($clave, 'caña') || str_contains($clave, 'cana')) {
        $clave = 'cana';
    }
    $trazo = $trazos[$clave] ?? $trazos['otros'];
    return '<svg class="reports-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $trazo . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informes</title>
    <link rel="stylesheet" href="../styles/informes_template.css?v=<?= filemtime(__DIR__ . '/../styles/informes_template.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('informes_view.php', 'Informes', 'Consolidado de resultados aprobados por lote'); ?>
    <div class="reports-page">
        <section class="reports-alert" aria-label="Información sobre la generación de informes">
            <?= informes_svg('ok') ?>
            <p>
                El informe se genera automáticamente por <strong>lote</strong>, integrando <strong>todos los análisis solicitados</strong>
                de cada muestra junto con la <strong>identificación capturada por QR, código de barras o etiqueta manual</strong>.
                Se organiza por tipo de muestra para que el jefe de laboratorio lo valide y lo envíe al correo del solicitante.
            </p>
        </section>

        <section class="reports-kpi-grid" aria-label="Resumen de informes">
            <article class="reports-kpi">
                <span class="reports-kpi-label">Informes pendientes de firma</span>
                <strong class="reports-kpi-value"><?= (int) ($panelInformes['pendientes_firma'] ?? 0) ?></strong>
            </article>
            <article class="reports-kpi">
                <span class="reports-kpi-label">Firmados y enviados</span>
                <strong class="reports-kpi-value is-success"><?= (int) ($panelInformes['firmados'] ?? 0) ?></strong>
            </article>
            <article class="reports-kpi">
                <span class="reports-kpi-label">Lotes con avance registrado</span>
                <strong class="reports-kpi-value"><?= (int) ($panelInformes['lotes_con_avance'] ?? 0) ?></strong>
            </article>
        </section>

        <div class="reports-section-title"><h1>Trazabilidad — progreso por lote</h1></div>
        <div class="reports-table-wrap">
            <table class="reports-table reports-progress-table">
                <thead>
                    <tr><th>Lote</th><th>Cliente</th><th>Muestras</th><th>Análisis aprobados</th><th>Progreso</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($panelInformes['lotes'])): ?>
                        <tr><td colspan="6" class="reports-table-empty">No hay lotes registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($panelInformes['lotes'] as $lote): ?>
                            <tr>
                                <td class="reports-code"><?= informes_e($lote['codigo_lote'] ?? '-') ?><small>Lote #<?= (int) ($lote['id_lote'] ?? 0) ?></small></td>
                                <td><?= informes_e($lote['cliente'] ?? 'Sin institución') ?><small><?= informes_e($lote['responsable'] ?? '') ?></small></td>
                                <td><?= (int) ($lote['total_muestras'] ?? 0) ?></td>
                                <td><?= (int) ($lote['analisis_aprobados'] ?? 0) ?>/<?= (int) ($lote['analisis_requeridos'] ?? 0) ?></td>
                                <td>
                                    <div class="reports-progress">
                                        <span class="reports-progress-track"><span style="width: <?= (int) ($lote['progreso'] ?? 0) ?>%"></span></span>
                                        <small><?= (int) ($lote['progreso'] ?? 0) ?>%</small>
                                    </div>
                                </td>
                                <td><span class="reports-status is-<?= informes_e($lote['estado']['codigo'] ?? 'recibido') ?>"><?= informes_e($lote['estado']['texto'] ?? 'RECIBIDA') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="reports-section-title reports-list-title"><h1>Informes por tipo de muestra</h1></div>
        <p class="reports-section-copy">Lista de lotes agrupada por tipo de muestra, lista para validación del jefe de laboratorio.</p>

        <?php if (empty($panelInformes['informes_por_tipo'])): ?>
            <section class="reports-empty">
                <span><?= informes_svg('otros') ?></span>
                <h2>Aún no hay informes generados</h2>
                <p>Se mostrarán aquí cuando un lote tenga un informe versionado registrado.</p>
                <?php if (empty($panelInformes['tabla_documentos_disponible'])): ?>
                    <small>La tabla de documentos todavía no está disponible en esta base de datos.</small>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <?php foreach ($panelInformes['informes_por_tipo'] as $tipo => $informes): ?>
                <section class="reports-cube">
                    <header class="reports-cube-head">
                        <span class="reports-cube-icon"><?= informes_svg($tipo) ?></span>
                        <div><h2><?= informes_e($tipo) ?></h2><p><?= count($informes) ?> lote<?= count($informes) === 1 ? '' : 's' ?> con informe</p></div>
                    </header>
                    <div class="reports-table-wrap">
                        <table class="reports-table reports-list-table">
                            <thead><tr><th>Informe / Lote</th><th>Cliente / Responsable</th><th>Muestras</th><th>Generado</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                            <tbody>
                                <?php foreach ($informes as $informe): ?>
                                    <tr>
                                        <td class="reports-code"><?= informes_e($informe['titulo'] ?: ('Informe #' . (int) $informe['id_documento'])) ?><small><?= informes_e($informe['lote']['codigo_lote'] ?? '-') ?></small></td>
                                        <td><?= informes_e($informe['lote']['cliente'] ?? 'Sin institución') ?><small><?= informes_e($informe['lote']['responsable'] ?? '') ?></small></td>
                                        <td><?= (int) ($informe['lote']['total_muestras'] ?? 0) ?> muestra<?= (int) ($informe['lote']['total_muestras'] ?? 0) === 1 ? '' : 's' ?></td>
                                        <td class="reports-muted"><?= informes_e(informes_fecha($informe['generado_en'] ?? null)) ?></td>
                                        <td><span class="reports-status <?= !empty($informe['firmado']) ? 'is-signed' : 'is-ready' ?>"><?= informes_e($informe['estado']) ?></span></td>
                                        <td class="reports-actions">
                                            <a class="reports-button is-ghost" href="documentos_view.php?id_documento=<?= (int) $informe['id_documento'] ?>">Ver detalle</a>
                                            <?php if (empty($informe['firmado']) && $puedeFirmar): ?>
                                                <a class="reports-button is-primary" href="firma_electronica_view.php?id_documento=<?= (int) $informe['id_documento'] ?>">Enviar a firma <?= informes_svg('flecha') ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php lab_shell_content_close(); ?>
</body>
</html>
