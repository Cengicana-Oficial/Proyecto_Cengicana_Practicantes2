<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';

lab_require_permission('laboratorio.documentos.ver');

$pdo = Conexion::conectar();

$tablaDisponible = lab_documentos_tabla_existe($pdo, 'lab_documento');
$documentos = lab_documentos_listar($pdo);
$totalBoletas = lab_documentos_contar($pdo, 'boleta');
$totalInformes = lab_documentos_contar($pdo, 'informe');

$idDocumentoDetalle = !empty($_GET['id_documento']) ? (int) $_GET['id_documento'] : null;
$historialDetalle = $idDocumentoDetalle ? lab_documentos_historial($pdo, $idDocumentoDetalle) : [];

function docs_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function docs_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '-';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

lab_shell_head('Documentos (PDF)', 'Listado y version vigente de boletas e informes generados por lote', [
    '../css/lab_shell.css?v=1',
]);
lab_shell_open('documentos_view.php');
?>

<div class="cengi-kpi-grid">
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">description</span></span>
        <div class="cengi-kpi-val"><?= (int) $totalBoletas ?></div>
        <div class="cengi-kpi-label">Boletas registradas</div>
        <div class="cengi-kpi-trend is-flat">Tabla <code>lab_documento</code></div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">summarize</span></span>
        <div class="cengi-kpi-val"><?= (int) $totalInformes ?></div>
        <div class="cengi-kpi-label">Informes registrados</div>
        <div class="cengi-kpi-trend is-flat">Tabla <code>lab_documento</code></div>
        <span class="cengi-kpi-bar"></span>
    </article>
</div>

<?php if (!$tablaDisponible): ?>
    <div class="cengi-empty" style="margin-bottom:18px;">
        La tabla <code>lab_documento</code> todavia no existe en esta base de datos.
        Aplique <code>laboratorio/database/002_lab_flujo_generico.sql</code> para habilitar el
        registro versionado de documentos (boletas/informes).
    </div>
<?php endif; ?>

<div class="cengi-card">
    <h3>Documentos generados</h3>
    <div class="cengi-card-sub">
        Version vigente de cada boleta/informe generado por lote. Hoy ningun flujo del modulo
        escribe todavia en <code>lab_documento</code>, por lo que este listado refleja el estado
        real actual (vacio) en vez de datos de ejemplo.
    </div>

    <?php if (empty($documentos)): ?>
        <div class="cengi-empty">Todavia no se ha generado ningun documento versionado.</div>
    <?php else: ?>
        <div class="cengi-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Lote</th>
                        <th>Titulo</th>
                        <th>Version</th>
                        <th>Vigente</th>
                        <th>Generado por</th>
                        <th>Generado en</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $doc): ?>
                        <tr>
                            <td><?= docs_e(ucfirst((string) $doc['tipo_documento'])) ?></td>
                            <td>Lote <?= docs_e($doc['codigo_lote'] ?? '-') ?></td>
                            <td><?= docs_e($doc['titulo'] ?: '-') ?></td>
                            <td>v<?= (int) $doc['version'] ?></td>
                            <td>
                                <?php if (!empty($doc['vigente'])): ?>
                                    <span class="cengi-status-badge is-approved">Vigente</span>
                                <?php else: ?>
                                    <span class="cengi-status-badge is-neutral">Historica</span>
                                <?php endif; ?>
                            </td>
                            <td><?= docs_e($doc['generado_por'] ?: '-') ?></td>
                            <td><?= docs_e(docs_fecha($doc['generado_en'] ?? null)) ?></td>
                            <td>
                                <a class="cengi-btn cengi-btn-ghost cengi-btn-sm" href="?id_documento=<?= (int) $doc['id_documento'] ?>">
                                    Ver historial
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($idDocumentoDetalle): ?>
    <div class="cengi-card" style="margin-top:18px;">
        <h3>Historial de version &middot; Documento #<?= (int) $idDocumentoDetalle ?></h3>
        <div class="cengi-card-sub">Cambios registrados en <code>lab_documento_historial</code> para este documento.</div>

        <?php if (empty($historialDetalle)): ?>
            <div class="cengi-empty">Este documento todavia no tiene historial de cambios registrado.</div>
        <?php else: ?>
            <div class="cengi-timeline">
                <?php foreach ($historialDetalle as $h): ?>
                    <div class="cengi-timeline-item is-ok">
                        <div class="cengi-timeline-dot"><span class="material-symbols-outlined" style="font-size:12px;">history</span></div>
                        <div class="cengi-timeline-head">
                            <b>Version v<?= (int) $h['version'] ?></b>
                            <span class="cengi-timeline-fecha"><?= docs_e(docs_fecha($h['fecha'] ?? null)) ?></span>
                        </div>
                        <?php if (!empty($h['cambios'])): ?>
                            <div class="cengi-timeline-detail"><?= docs_e($h['cambios']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($h['usuario'])): ?>
                            <div class="cengi-timeline-detail" style="color:var(--cengi-muted);">Por <?= docs_e($h['usuario']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php lab_shell_close(); ?>
