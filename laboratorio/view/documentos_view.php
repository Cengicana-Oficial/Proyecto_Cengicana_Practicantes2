<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../includes/schema_safe_helper.php';
require_once __DIR__ . '/../models/documentos_model.php';

lab_require_permission('laboratorio.documentos.ver');

$pdo = Conexion::conectar();
lab_ensure_schema_safe(fn() => lab_documentos_esquema($pdo), 'documentos_v1');
// Reportes: informes de resultados por lote. Las boletas de recepción se consultan en Recepción.
$panelDocumentos = lab_documentos_panel($pdo, 'informe');
$puedeExcelInformes = lab_can('laboratorio.informes.ver');
$_SESSION['reportes_csrf'] ??= bin2hex(random_bytes(32));
$historiales = [];
$tiposMuestra = [];
foreach ($panelDocumentos['documentos'] as $documento) {
    if ((int) ($documento['total_cambios'] ?? 0) > 0) {
        $historiales[(int) $documento['id_documento']] = lab_documentos_historial($pdo, (int) $documento['id_documento']);
    }
    foreach (array_filter(array_map('trim', explode(',', (string) ($documento['tipo_muestra'] ?? '')))) as $tipoMuestra) {
        $tiposMuestra[mb_strtolower($tipoMuestra, 'UTF-8')] = docs_tipo_muestra($tipoMuestra);
    }
}
ksort($tiposMuestra);

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

function docs_lote(array $documento): string
{
    return trim((string) ($documento['codigo_lote'] ?? '')) ?: '—';
}

/** Tipo de muestra para mostrar: "suelos" → "Suelos". */
function docs_tipo_muestra(string $tipo): string
{
    $tipo = trim($tipo);
    return $tipo === "" ? "" : mb_convert_case($tipo, MB_CASE_TITLE, "UTF-8");
}

function docs_svg(string $icono): string
{
    $trazos = [
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'pdf' => '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M8.5 16.5h2.2a1.8 1.8 0 0 0 0-3.6H8.5v5.6M14 18.5v-5.6h3.5M14 15.7h2.7"/>',
        'historial' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'cerrar' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'excel' => '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M9 12l5 6M14 12l-5 6"/>',
        'correo' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'ver' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
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
        'lote' => docs_lote($documento),
        'tipo' => docs_tipo_muestra((string) ($documento['tipo_muestra'] ?? '')),
        'cliente' => trim((string) ($documento['cliente'] ?? '')) ?: 'Sin institución',
        'version' => (int) ($documento['version'] ?? 1),
        'correos' => lab_documentos_correos_lote($pdo, (int) ($documento['id_lote'] ?? 0)),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes</title>
    <link rel="stylesheet" href="../styles/documentos_template.css?v=<?= filemtime(__DIR__ . '/../styles/documentos_template.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=20261006">
    <link rel="stylesheet" href="../css/lab_pager.css?v=<?= filemtime(__DIR__ . '/../css/lab_pager.css') ?>">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('documentos_view.php', 'Reportes', 'Informes de resultados por lote'); ?>
    <div class="documents-page">
        <p class="documents-intro">
            Informes de resultados aprobados y firmados, identificados por <strong>número de lote</strong>: véalos o descárguelos en PDF o Excel y envíelos por correo al cliente.
            Los datos analíticos firmados <strong>no se editan aquí</strong>; cada corrección y cada envío quedan en el historial con usuario y fecha.
            Las boletas de recepción se consultan en <a href="solicitud_formulario.php">Recepción</a>.
        </p>

        <section class="documents-kpi-grid" aria-label="Resumen de documentos">
            <article class="documents-kpi">
                <span class="documents-kpi-label">Reportes generados</span>
                <strong class="documents-kpi-value"><?= (int) $panelDocumentos['total'] ?></strong>
            </article>
            <article class="documents-kpi">
                <span class="documents-kpi-label">Con nueva versión</span>
                <strong class="documents-kpi-value is-warning"><?= (int) $panelDocumentos['corregidos'] ?></strong>
            </article>
            <article class="documents-kpi">
                <span class="documents-kpi-label">Lotes con informe</span>
                <strong class="documents-kpi-value is-success"><?= (int) $panelDocumentos['lotes'] ?></strong>
            </article>
        </section>

        <section class="documents-repository">
            <div class="documents-toolbar">
                <label class="documents-filter">
                    <span class="sr-only">Filtrar por tipo de muestra</span>
                    <select id="documentTypeFilter">
                        <option value="">Todos los tipos de muestra</option>
                        <?php foreach ($tiposMuestra as $claveTipo => $tipoMuestra): ?>
                            <option value="<?= docs_e($claveTipo) ?>"><?= docs_e($tipoMuestra) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="documents-search">
                    <?= docs_svg('buscar') ?>
                    <span class="sr-only">Buscar reportes</span>
                    <input type="search" id="documentSearch" placeholder="Buscar por número de lote o cliente…" autocomplete="off">
                </label>
            </div>

            <div class="documents-table-wrap">
                <table class="documents-table">
                    <thead>
                        <tr><th>Lote</th><th>Tipo de muestra</th><th>Cliente</th><th>Versión</th><th>Firmado por</th><th>Fecha</th><th>Acciones</th></tr>
                    </thead>
                    <tbody id="documentsTableBody">
                        <?php if (empty($panelDocumentos['documentos'])): ?>
                            <tr class="documents-empty-row"><td colspan="7">
                                <?php if (!$panelDocumentos['tabla_disponible']): ?>
                                    La tabla de documentos todavía no está disponible. Los reportes aparecerán aquí cuando se aplique la migración del flujo documental.
                                <?php else: ?>
                                    Sin reportes todavía — se generan cuando el jefe de laboratorio aprueba y firma el informe de un lote en Informes.
                                <?php endif; ?>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($panelDocumentos['documentos'] as $documento): ?>
                                <?php
                                $idDocumento = (int) $documento['id_documento'];
                                $codigoLote = docs_lote($documento);
                                $tipoMuestra = trim((string) ($documento['tipo_muestra'] ?? '')) ?: '—';
                                $cliente = trim((string) ($documento['cliente'] ?? '')) ?: 'Sin institución';
                                $version = (int) ($documento['version'] ?? 1);
                                $textoBusqueda = mb_strtolower(implode(' ', [
                                    'lote ' . $codigoLote,
                                    $tipoMuestra,
                                    $cliente,
                                    $documento['generado_por'] ?? '',
                                    docs_codigo($idDocumento),
                                ]), 'UTF-8');
                                ?>
                                <tr class="document-row" data-type="<?= docs_e(mb_strtolower($tipoMuestra, 'UTF-8')) ?>" data-search="<?= docs_e($textoBusqueda) ?>">
                                    <td class="documents-lote">
                                        <strong><?= docs_e($codigoLote) ?></strong>
                                        <?php if ($version > 1): ?><span class="documents-version-badge">v<?= $version ?></span><?php endif; ?>
                                    </td>
                                    <td class="documents-small"><?= docs_e(docs_tipo_muestra($tipoMuestra)) ?></td>
                                    <td><?= docs_e($cliente) ?></td>
                                    <td class="documents-mono">v<?= $version ?></td>
                                    <td class="documents-small"><?= docs_e($documento['generado_por'] ?: '—') ?></td>
                                    <td class="documents-muted"><?= docs_e(docs_fecha($documento['generado_en'] ?? null)) ?></td>
                                    <td class="documents-actions">
                                        <details class="documents-menu">
                                            <summary class="documents-button is-ghost" aria-label="Acciones del reporte del lote <?= docs_e($codigoLote) ?>">Acciones <span aria-hidden="true">▾</span></summary>
                                            <div class="documents-menu-list" role="menu">
                                                <span class="documents-menu-title">Informe · Lote <?= docs_e($codigoLote) ?></span>
                                                <button type="button" role="menuitem" data-view-document="<?= $idDocumento ?>"><?= docs_svg('ver') ?>Ver PDF</button>
                                                <a role="menuitem" href="../controllers/documento_pdf.php?id_documento=<?= $idDocumento ?>&amp;download=1"><?= docs_svg('descargar') ?>Descargar PDF</a>
                                                <?php if ($puedeExcelInformes): ?>
                                                    <a role="menuitem" href="../controllers/informe_excel.php?lote=<?= (int) $documento['id_lote'] ?>" title="Informe firmado en Excel (.xlsx), con los valores aprobados"><?= docs_svg('excel') ?>Descargar Excel</a>
                                                <?php endif; ?>
                                                <button type="button" role="menuitem" data-send-document="<?= $idDocumento ?>"><?= docs_svg('correo') ?>Enviar por correo</button>
                                                <span class="documents-menu-title">Trazabilidad</span>
                                                <?php if (!empty($documento['total_cambios'])): ?>
                                                    <button type="button" role="menuitem" data-view-history="<?= $idDocumento ?>"><?= docs_svg('historial') ?>Historial (<?= (int) $documento['total_cambios'] ?>)</button>
                                                <?php else: ?>
                                                    <span class="documents-menu-disabled" title="Sin correcciones ni envíos registrados"><?= docs_svg('historial') ?>Sin historial</span>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="documents-empty-row is-filtered" id="documentsFilteredEmpty" hidden><td colspan="7">No hay reportes que coincidan con los filtros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav class="lab-pager" id="documentsPager" aria-label="Páginas de reportes" hidden>
                <span data-pager-rango></span>
                <div class="lab-pager-nav">
                    <button type="button" class="lab-pager-btn" data-pager-prev aria-label="Página anterior"><span aria-hidden="true">‹</span></button>
                    <b data-pager-info aria-live="polite"></b>
                    <button type="button" class="lab-pager-btn" data-pager-next aria-label="Página siguiente"><span aria-hidden="true">›</span></button>
                </div>
            </nav>
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

    <div class="documents-modal" id="sendModal" hidden>
        <form class="documents-modal-dialog documents-send-dialog" id="sendForm" role="dialog" aria-modal="true" aria-labelledby="sendModalTitle" novalidate>
            <header class="documents-modal-head">
                <h2 id="sendModalTitle">Enviar reporte por correo</h2>
                <button type="button" class="documents-modal-close" data-close-modal aria-label="Cerrar"><?= docs_svg('cerrar') ?></button>
            </header>
            <div class="documents-send-body">
                <p class="documents-send-summary" id="sendSummary"></p>
                <label class="documents-send-field">
                    <span>Destinatarios</span>
                    <textarea id="sendEmails" rows="3" placeholder="cliente@ejemplo.com, otro@ejemplo.com" required></textarea>
                    <small>Separe varios correos con coma. Se proponen los correos registrados en la recepción del lote. Se adjunta el PDF de la versión vigente.</small>
                </label>
                <p class="documents-send-status" id="sendStatus" role="status" aria-live="polite"></p>
            </div>
            <footer class="documents-modal-foot">
                <button type="button" class="documents-button is-ghost" data-close-modal>Cancelar</button>
                <button type="submit" class="documents-button is-primary" id="sendSubmit"><?= docs_svg('correo') ?> Enviar</button>
            </footer>
        </form>
    </div>

<?php lab_shell_content_close(); ?>
<script src="../js/lab_pager.js?v=<?= filemtime(__DIR__ . '/../js/lab_pager.js') ?>"></script>
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
    var sendModal = document.getElementById('sendModal');
    var sendForm = document.getElementById('sendForm');
    var sendEmails = document.getElementById('sendEmails');
    var sendSummary = document.getElementById('sendSummary');
    var sendStatus = document.getElementById('sendStatus');
    var sendSubmit = document.getElementById('sendSubmit');
    var sendId = null;
    var csrf = <?= json_encode($_SESSION['reportes_csrf']) ?>;
    var documents = <?= json_encode($documentosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var histories = <?= json_encode($historiales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function normalize(value) {
        return (value || '').toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    // 25 reportes por página (más recientes primero), sobre los que pasan el filtro y la búsqueda.
    var pager = window.LabPager.crear(document.getElementById('documentsPager'), 25);
    pager.alCambiar(function () {
        document.querySelector('.documents-repository').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    function filterRows() {
        var type = typeFilter ? typeFilter.value : '';
        var term = normalize(search ? search.value.trim() : '');
        var visibles = [];
        rows.forEach(function (row) {
            var matchesType = !type || row.dataset.type === type;
            var matchesSearch = !term || normalize(row.dataset.search).indexOf(term) !== -1;
            row.hidden = !(matchesType && matchesSearch);
            if (!row.hidden) visibles.push(row);
        });
        if (filteredEmpty) filteredEmpty.hidden = visibles.length !== 0;
        pager.aplicar(visibles);
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
        modalTitle.textContent = 'Informe · Lote ' + doc.lote + (doc.tipo ? ' (' + doc.tipo + ')' : '') + ' · v' + doc.version;
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
        if (modal) {
            modal.hidden = true;
            frame.src = 'about:blank';
        }
        if (sendModal) sendModal.hidden = true;
        document.body.classList.remove('documents-modal-open');
    }

    function openSend(id) {
        var doc = documents[id];
        if (!doc || !sendModal) return;
        sendId = id;
        sendSummary.textContent = 'Informe del lote ' + doc.lote + (doc.tipo ? ' (' + doc.tipo + ')' : '') + ' · ' + doc.cliente + ' · v' + doc.version;
        sendEmails.value = (doc.correos || []).join(', ');
        setSendStatus('', '');
        sendSubmit.disabled = false;
        sendModal.hidden = false;
        document.body.classList.add('documents-modal-open');
        sendEmails.focus();
    }

    function setSendStatus(text, kind) {
        sendStatus.textContent = text;
        sendStatus.className = 'documents-send-status' + (kind ? ' is-' + kind : '');
    }

    if (sendForm) sendForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var emails = sendEmails.value.split(/[,;\s]+/).map(function (v) { return v.trim(); }).filter(Boolean);
        var invalid = emails.filter(function (v) { return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v); });
        if (!emails.length) { setSendStatus('Ingrese al menos un correo.', 'error'); sendEmails.focus(); return; }
        if (invalid.length) { setSendStatus('Correo no válido: ' + invalid.join(', '), 'error'); sendEmails.focus(); return; }
        sendSubmit.disabled = true;
        setSendStatus('Generando el PDF y enviando…', '');
        fetch('../controllers/enviar_reporte_correo.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({id_documento: Number(sendId), emails: emails, csrf: csrf})
        }).then(function (response) {
            return response.json().catch(function () { return {ok: false, message: 'Respuesta no válida del servidor (' + response.status + ').'}; });
        }).then(function (data) {
            setSendStatus(data.message || (data.ok ? 'Enviado.' : 'No se pudo enviar.'), data.ok ? 'ok' : 'error');
            sendSubmit.disabled = !!data.ok;
        }).catch(function () {
            setSendStatus('No se pudo conectar con el servidor.', 'error');
            sendSubmit.disabled = false;
        });
    });

    // Menú de acciones: uno abierto a la vez, en posición fija para no quedar recortado por la tabla.
    function closeMenus(except) {
        document.querySelectorAll('details.documents-menu[open]').forEach(function (menu) {
            if (menu !== except) menu.removeAttribute('open');
        });
    }
    document.addEventListener('toggle', function (event) {
        var menu = event.target;
        if (!menu.matches || !menu.matches('details.documents-menu') || !menu.open) return;
        closeMenus(menu);
        var list = menu.querySelector('.documents-menu-list');
        var rect = menu.querySelector('summary').getBoundingClientRect();
        list.style.left = Math.max(8, rect.right - list.offsetWidth) + 'px';
        var below = rect.bottom + 4;
        list.style.top = (below + list.offsetHeight > window.innerHeight - 8 ? Math.max(8, rect.top - list.offsetHeight - 4) : below) + 'px';
    }, true);
    window.addEventListener('scroll', function () { closeMenus(null); }, true);
    window.addEventListener('resize', function () { closeMenus(null); });

    if (typeFilter) typeFilter.addEventListener('change', filterRows);
    if (search) search.addEventListener('input', filterRows);
    filterRows();
    document.addEventListener('click', function (event) {
        var viewButton = event.target.closest('[data-view-document]');
        var historyButton = event.target.closest('[data-view-history]');
        var sendButton = event.target.closest('[data-send-document]');
        if (!event.target.closest('details.documents-menu') || event.target.closest('.documents-menu-list [role="menuitem"]')) closeMenus(null);
        if (viewButton) openModal(viewButton.dataset.viewDocument, false);
        if (historyButton) openModal(historyButton.dataset.viewHistory, true);
        if (sendButton) openSend(sendButton.dataset.sendDocument);
        if (event.target.closest('[data-close-modal]') || event.target === modal || event.target === sendModal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if ((modal && !modal.hidden) || (sendModal && !sendModal.hidden)) closeModal();
        else closeMenus(null);
    });
})();
</script>
</body>
</html>
