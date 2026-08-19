<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/documentos_model.php';

lab_require_permission('laboratorio.documentos.ver');

$pdo = Conexion::conectar();
$panelDocumentos = lab_documentos_panel($pdo);
$historiales = [];
foreach ($panelDocumentos['documentos'] as $documento) {
    if ((int) ($documento['total_cambios'] ?? 0) > 0) {
        $historiales[(int) $documento['id_documento']] = lab_documentos_historial($pdo, (int) $documento['id_documento']);
    }
}

function docs_e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function docs_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '—';
    }
    $timestamp = strtotime($fecha);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $fecha;
}

function docs_codigo(int $idDocumento): string
{
    return 'DOC-' . str_pad((string) $idDocumento, 4, '0', STR_PAD_LEFT);
}

function docs_tipo(string $tipo): string
{
    return $tipo === 'boleta' ? 'Boleta de recepción' : 'Informe de resultados';
}

function docs_svg(string $icono): string
{
    $trazos = [
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'pdf' => '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M8.5 16.5h2.2a1.8 1.8 0 0 0 0-3.6H8.5v5.6M14 18.5v-5.6h3.5M14 15.7h2.7"/>',
        'historial' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'cerrar' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'descargar' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/>',
    ];
    return '<svg class="documents-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($trazos[$icono] ?? $trazos['info']) . '</svg>';
}

$documentosJs = [];
foreach ($panelDocumentos['documentos'] as $documento) {
    $idDocumento = (int) $documento['id_documento'];
    $documentosJs[$idDocumento] = [
        'codigo' => docs_codigo($idDocumento),
        'titulo' => trim((string) ($documento['titulo'] ?? '')) ?: docs_tipo((string) $documento['tipo_documento']),
        'tipo' => docs_tipo((string) $documento['tipo_documento']),
        'version' => (int) ($documento['version'] ?? 1),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos (PDF)</title>
    <link rel="stylesheet" href="../styles/documentos_template.css?v=<?= filemtime(__DIR__ . '/../styles/documentos_template.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('documentos_view.php', 'Documentos (PDF)', 'Repositorio y control de versiones'); ?>
    <div class="documents-page">
        <p class="documents-intro">
            Repositorio de los PDF generados por el sistema — boletas de recepción e informes de resultados — organizado por lote.
            Los datos analíticos ya firmados <strong>no se editan aquí</strong>; cada corrección registrada conserva motivo, usuario y fecha.
        </p>

        <section class="documents-kpi-grid" aria-label="Resumen de documentos">
            <article class="documents-kpi">
                <span class="documents-kpi-label">Documentos generados</span>
                <strong class="documents-kpi-value"><?= (int) $panelDocumentos['total'] ?></strong>
            </article>
            <article class="documents-kpi">
                <span class="documents-kpi-label">Con corrección registrada</span>
                <strong class="documents-kpi-value is-warning"><?= (int) $panelDocumentos['corregidos'] ?></strong>
            </article>
            <article class="documents-kpi">
                <span class="documents-kpi-label">Lotes con boleta y/o informe</span>
                <strong class="documents-kpi-value is-success"><?= (int) $panelDocumentos['lotes'] ?></strong>
            </article>
        </section>

        <section class="documents-repository">
            <div class="documents-toolbar">
                <label class="documents-filter">
                    <span class="sr-only">Filtrar por tipo</span>
                    <select id="documentTypeFilter">
                        <option value="">Todo tipo de documento</option>
                        <option value="boleta">Boleta de recepción</option>
                        <option value="informe">Informe de resultados</option>
                    </select>
                </label>
                <label class="documents-search">
                    <?= docs_svg('buscar') ?>
                    <span class="sr-only">Buscar documentos</span>
                    <input type="search" id="documentSearch" placeholder="Buscar por documento, lote o cliente…" autocomplete="off">
                </label>
            </div>

            <div class="documents-table-wrap">
                <table class="documents-table">
                    <thead>
                        <tr><th>Documento</th><th>Tipo</th><th>Lote / Cliente</th><th>Versión</th><th>Generado por</th><th>Fecha</th><th><span class="sr-only">Acciones</span></th></tr>
                    </thead>
                    <tbody id="documentsTableBody">
                        <?php if (empty($panelDocumentos['documentos'])): ?>
                            <tr class="documents-empty-row"><td colspan="7">
                                <?php if (!$panelDocumentos['tabla_disponible']): ?>
                                    La tabla de documentos todavía no está disponible. Se mostrará aquí el repositorio cuando se aplique la migración del flujo documental.
                                <?php else: ?>
                                    Sin documentos generados aún — se crean al registrar una boleta o al generar un informe versionado.
                                <?php endif; ?>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($panelDocumentos['documentos'] as $documento): ?>
                                <?php
                                $idDocumento = (int) $documento['id_documento'];
                                $tipoCodigo = (string) $documento['tipo_documento'];
                                $tipoTexto = docs_tipo($tipoCodigo);
                                $codigo = docs_codigo($idDocumento);
                                $cliente = trim((string) ($documento['cliente'] ?? '')) ?: 'Sin institución';
                                $codigoLote = trim((string) ($documento['codigo_lote'] ?? '')) ?: '—';
                                $textoBusqueda = mb_strtolower(implode(' ', [
                                    $codigo,
                                    $documento['titulo'] ?? '',
                                    $tipoTexto,
                                    $codigoLote,
                                    $cliente,
                                    $documento['generado_por'] ?? '',
                                ]), 'UTF-8');
                                ?>
                                <tr class="document-row" data-type="<?= docs_e($tipoCodigo) ?>" data-search="<?= docs_e($textoBusqueda) ?>">
                                    <td class="documents-code">
                                        <?= docs_e($codigo) ?>
                                        <?php if ((int) ($documento['version'] ?? 1) > 1): ?><span class="documents-version-badge">v<?= (int) $documento['version'] ?></span><?php endif; ?>
                                    </td>
                                    <td class="documents-small"><?= docs_e($tipoTexto) ?></td>
                                    <td><?= docs_e($cliente) ?><small><?= docs_e($codigoLote) ?></small></td>
                                    <td class="documents-mono">v<?= (int) ($documento['version'] ?? 1) ?></td>
                                    <td class="documents-small"><?= docs_e($documento['generado_por'] ?: '—') ?></td>
                                    <td class="documents-muted"><?= docs_e(docs_fecha($documento['generado_en'] ?? null)) ?></td>
                                    <td class="documents-actions">
                                        <button type="button" class="documents-button is-ghost" data-view-document="<?= $idDocumento ?>">Ver PDF</button>
                                        <button type="button" class="documents-button is-ghost" data-view-history="<?= $idDocumento ?>" <?= empty($documento['total_cambios']) ? 'disabled' : '' ?>>Historial</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="documents-empty-row is-filtered" id="documentsFilteredEmpty" hidden><td colspan="7">No hay documentos que coincidan con los filtros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="documents-modal" id="documentModal" hidden>
        <div class="documents-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="documentModalTitle">
            <header class="documents-modal-head">
                <h2 id="documentModalTitle">Documento</h2>
                <button type="button" class="documents-modal-close" data-close-modal aria-label="Cerrar"><?= docs_svg('cerrar') ?></button>
            </header>
            <div class="documents-modal-body">
                <div class="documents-preview" id="documentPreview">
                    <iframe id="documentFrame" title="Vista previa del documento PDF"></iframe>
                </div>
                <section class="documents-history" id="documentHistory" hidden>
                    <h3>Historial de cambios</h3>
                    <div id="documentHistoryList"></div>
                </section>
            </div>
            <footer class="documents-modal-foot">
                <button type="button" class="documents-button is-ghost" data-close-modal>Cerrar</button>
                <a class="documents-button is-primary" id="documentDownload" href="#">
                    <?= docs_svg('descargar') ?> Descargar PDF
                </a>
            </footer>
        </div>
    </div>

<?php lab_shell_content_close(); ?>
<script>
(function () {
    var typeFilter = document.getElementById('documentTypeFilter');
    var search = document.getElementById('documentSearch');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.document-row'));
    var filteredEmpty = document.getElementById('documentsFilteredEmpty');
    var modal = document.getElementById('documentModal');
    var modalTitle = document.getElementById('documentModalTitle');
    var frame = document.getElementById('documentFrame');
    var preview = document.getElementById('documentPreview');
    var history = document.getElementById('documentHistory');
    var historyList = document.getElementById('documentHistoryList');
    var download = document.getElementById('documentDownload');
    var documents = <?= json_encode($documentosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var histories = <?= json_encode($historiales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function normalize(value) {
        return (value || '').toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function filterRows() {
        var type = typeFilter ? typeFilter.value : '';
        var term = normalize(search ? search.value.trim() : '');
        var visible = 0;
        rows.forEach(function (row) {
            var matchesType = !type || row.dataset.type === type;
            var matchesSearch = !term || normalize(row.dataset.search).indexOf(term) !== -1;
            row.hidden = !(matchesType && matchesSearch);
            if (!row.hidden) visible++;
        });
        if (filteredEmpty) filteredEmpty.hidden = visible !== 0;
    }

    function formatDate(value) {
        if (!value) return 'Fecha no disponible';
        var date = new Date(value.replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString('es-GT', {dateStyle: 'medium', timeStyle: 'short'});
    }

    function renderHistory(id) {
        historyList.textContent = '';
        var entries = histories[id] || [];
        if (!entries.length) {
            var empty = document.createElement('p');
            empty.className = 'documents-history-empty';
            empty.textContent = 'Este documento no tiene correcciones registradas.';
            historyList.appendChild(empty);
            return;
        }
        entries.forEach(function (entry) {
            var item = document.createElement('article');
            item.className = 'documents-history-item';
            var dot = document.createElement('span');
            dot.className = 'documents-history-dot';
            dot.innerHTML = <?= json_encode(docs_svg('historial')) ?>;
            var content = document.createElement('div');
            var head = document.createElement('div');
            head.className = 'documents-history-head';
            var version = document.createElement('strong');
            version.textContent = 'Versión v' + (entry.version || '—');
            var date = document.createElement('span');
            date.textContent = formatDate(entry.fecha);
            head.appendChild(version);
            head.appendChild(date);
            var change = document.createElement('p');
            change.textContent = entry.cambios || 'Cambio sin descripción.';
            content.appendChild(head);
            content.appendChild(change);
            if (entry.usuario) {
                var user = document.createElement('small');
                user.textContent = 'Por ' + entry.usuario;
                content.appendChild(user);
            }
            item.appendChild(dot);
            item.appendChild(content);
            historyList.appendChild(item);
        });
    }

    function openModal(id, historyOnly) {
        var doc = documents[id];
        if (!doc || !modal) return;
        modalTitle.textContent = doc.codigo + ' — ' + doc.tipo;
        frame.src = '../controllers/documento_pdf.php?id_documento=' + encodeURIComponent(id);
        download.href = '../controllers/documento_pdf.php?id_documento=' + encodeURIComponent(id) + '&download=1';
        renderHistory(id);
        preview.hidden = !!historyOnly;
        history.hidden = !historyOnly && !(histories[id] || []).length;
        modal.hidden = false;
        document.body.classList.add('documents-modal-open');
        modal.querySelector('.documents-modal-close').focus();
    }

    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        frame.src = 'about:blank';
        document.body.classList.remove('documents-modal-open');
    }

    if (typeFilter) typeFilter.addEventListener('change', filterRows);
    if (search) search.addEventListener('input', filterRows);
    document.addEventListener('click', function (event) {
        var viewButton = event.target.closest('[data-view-document]');
        var historyButton = event.target.closest('[data-view-history]');
        if (viewButton) openModal(viewButton.dataset.viewDocument, false);
        if (historyButton && !historyButton.disabled) openModal(historyButton.dataset.viewHistory, true);
        if (event.target.closest('[data-close-modal]') || event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) closeModal();
    });
})();
</script>
</body>
</html>
