<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/firma_electronica_model.php';

lab_require_permission('laboratorio.firma.gestionar');

$pdo = Conexion::conectar();
$usuario = lab_current_user();
$errorFirma = '';

if (empty($_SESSION['firma_electronica_csrf'])) {
    $_SESSION['firma_electronica_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idDocumentoPost = filter_input(INPUT_POST, 'id_documento', FILTER_VALIDATE_INT) ?: 0;
    $token = (string) ($_POST['csrf_token'] ?? '');
    try {
        if (!hash_equals((string) $_SESSION['firma_electronica_csrf'], $token)) {
            throw new RuntimeException('La sesión de firma expiró. Recarga la página e inténtalo de nuevo.');
        }
        lab_firma_registrar($pdo, $idDocumentoPost, (string) ($_POST['firma_png'] ?? ''), $usuario);
        $_SESSION['firma_electronica_flash'] = 'Firma electrónica registrada correctamente.';
        header('Location: firma_electronica_view.php?id_documento=' . $idDocumentoPost);
        exit;
    } catch (Throwable $e) {
        $errorFirma = $e->getMessage();
    }
}

$idDocumentoSolicitado = filter_input(INPUT_GET, 'id_documento', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idDocumentoSolicitado = filter_input(INPUT_POST, 'id_documento', FILTER_VALIDATE_INT);
}
$panelFirma = lab_firma_panel($pdo, $idDocumentoSolicitado ?: null);
$informe = $panelFirma['seleccionado'];
$lote = $informe['lote'] ?? [];
$firma = $panelFirma['firma'];
$detalle = $panelFirma['detalle'];
$resumen = $panelFirma['resumen'];

$mensajeFirma = (string) ($_SESSION['firma_electronica_flash'] ?? '');
unset($_SESSION['firma_electronica_flash']);

$requeridos = (int) ($lote['analisis_requeridos'] ?? 0);
$ingresados = (int) ($lote['analisis_ingresados'] ?? 0);
$aprobados = (int) ($lote['analisis_aprobados'] ?? 0);
$analisisCompletos = $requeridos > 0 && $ingresados >= $requeridos;
$todoAprobado = $requeridos > 0 && $aprobados >= $requeridos;
$puedeCapturar = $informe !== null
    && $firma === null
    && !empty($panelFirma['tabla_firmas_disponible'])
    && $todoAprobado;

function firma_e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function firma_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '—';
    }
    $timestamp = strtotime($fecha);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $fecha;
}

function firma_svg(string $icono): string
{
    $trazos = [
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/>',
        'alerta' => '<path d="M10.3 4.1 2.7 17.4A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-2.6L13.7 4.1a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 16h.01"/>',
        'ok' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.7 2.7L16.5 9"/>',
        'borrar' => '<path d="m3 6 3 15h12l3-15M8 6V3h8v3M6 6h12M10 10v7M14 10v7"/>',
        'firma' => '<path d="M4 18c3.5-4.8 5.8-7.2 7-7.2 1.8 0-1.8 5.2.2 5.2 1.1 0 2.3-2.6 3.4-2.6.9 0-.3 2.6.9 2.6.8 0 1.4-1.4 2.5-1.4.8 0 1.1.7 2 .7"/><path d="M4 21h16"/>',
        'volver' => '<path d="m9 14-4-4 4-4"/><path d="M5 10h8a6 6 0 0 1 6 6v2"/>',
    ];
    return '<svg class="signature-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($trazos[$icono] ?? $trazos['info']) . '</svg>';
}

$etapas = [
    ['nombre' => 'Recibido', 'estado' => $informe ? 'done' : ''],
    ['nombre' => 'Analizado', 'estado' => $analisisCompletos ? 'done' : ($informe ? 'current' : '')],
    ['nombre' => 'Aprobado técnico', 'estado' => $todoAprobado ? 'done' : ($analisisCompletos ? 'current' : '')],
    ['nombre' => 'Firma jefe', 'estado' => $firma ? 'done' : ($todoAprobado ? 'current' : '')],
    ['nombre' => 'Entrega', 'estado' => $firma ? 'current' : ''],
];

$tituloInforme = $informe
    ? trim((string) ($informe['titulo'] ?? ''))
    : 'Sin informe seleccionado';
if ($informe && $tituloInforme === '') {
    $tituloInforme = 'Informe #' . (int) $informe['id_documento'];
}
$subtituloInforme = $informe
    ? (($lote['codigo_lote'] ?? '-') . ' · ' . ($lote['cliente'] ?? 'Sin institución') . ' · ' . (int) ($lote['total_muestras'] ?? 0) . ' muestras')
    : 'No hay informes versionados pendientes de revisión.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firma electrónica</title>
    <link rel="stylesheet" href="../styles/firma_electronica_template.css?v=<?= filemtime(__DIR__ . '/../styles/firma_electronica_template.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('firma_electronica_view.php', 'Firma electrónica', 'Aprobación final de informes'); ?>
    <div class="signature-page">
        <section class="signature-toolbar" aria-label="Selección de informe">
            <h2>Informe a revisar</h2>
            <label class="signature-select-wrap">
                <span class="sr-only">Seleccionar informe</span>
                <select id="firmaInforme" <?= empty($panelFirma['documentos']) ? 'disabled' : '' ?>>
                    <?php if (empty($panelFirma['documentos'])): ?>
                        <option>No hay informes disponibles</option>
                    <?php else: ?>
                        <?php foreach ($panelFirma['documentos'] as $documento): ?>
                            <?php
                            $nombreDocumento = trim((string) ($documento['titulo'] ?? ''));
                            if ($nombreDocumento === '') {
                                $nombreDocumento = 'Informe #' . (int) $documento['id_documento'];
                            }
                            ?>
                            <option value="<?= (int) $documento['id_documento'] ?>" <?= (int) $documento['id_documento'] === (int) ($informe['id_documento'] ?? 0) ? 'selected' : '' ?>>
                                <?= firma_e($nombreDocumento . ' · ' . ($documento['codigo_lote'] ?? '-')) ?><?= !empty($documento['firmado']) ? ' · Firmado' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>
        </section>

        <?php if ($mensajeFirma !== ''): ?>
            <div class="signature-alert is-success"><?= firma_svg('ok') ?><span><?= firma_e($mensajeFirma) ?></span></div>
        <?php endif; ?>
        <?php if ($errorFirma !== ''): ?>
            <div class="signature-alert is-danger"><?= firma_svg('alerta') ?><span><?= firma_e($errorFirma) ?></span></div>
        <?php endif; ?>

        <div class="signature-grid">
            <section class="signature-card signature-main-card">
                <header class="signature-card-head">
                    <h1><?= firma_e($tituloInforme) ?></h1>
                    <p><?= firma_e($subtituloInforme) ?></p>
                </header>

                <div class="signature-stepper" aria-label="Flujo del informe">
                    <?php foreach ($etapas as $indice => $etapa): ?>
                        <div class="signature-step <?= firma_e($etapa['estado']) ?>">
                            <?php if ($indice > 0): ?><span class="signature-step-line"></span><?php endif; ?>
                            <span class="signature-step-dot">
                                <?= $etapa['estado'] === 'done' ? firma_svg('ok') : (int) ($indice + 1) ?>
                            </span>
                            <span class="signature-step-name"><?= firma_e($etapa['nombre']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="signature-divider"></div>

                <?php if (!$panelFirma['tabla_documentos_disponible'] || !$panelFirma['tabla_firmas_disponible']): ?>
                    <div class="signature-alert is-warning">
                        <?= firma_svg('alerta') ?>
                        <span>La migración de documentos y firmas aún no está aplicada en esta base de datos. La pantalla quedará lista para operar cuando existan esas tablas.</span>
                    </div>
                <?php elseif ($informe === null): ?>
                    <div class="signature-alert is-info">
                        <?= firma_svg('info') ?>
                        <span>Selecciona un informe generado para revisar su trazabilidad, resultados y destinatario.</span>
                    </div>
                <?php else: ?>
                    <div class="signature-alert is-info">
                        <?= firma_svg('info') ?>
                        <span><strong>Observaciones del técnico revisor:</strong> <?= firma_e($detalle['observaciones'] !== '' ? $detalle['observaciones'] : 'Sin observaciones registradas.') ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($informe !== null && !$todoAprobado): ?>
                    <div class="signature-alert is-warning">
                        <?= firma_svg('alerta') ?>
                        <span>Este lote todavía tiene análisis sin aprobar (<?= $aprobados ?>/<?= $requeridos ?>). La firma se habilitará al completar el control de calidad.</span>
                    </div>
                <?php elseif ($firma !== null): ?>
                    <div class="signature-alert is-success">
                        <?= firma_svg('ok') ?>
                        <span>Informe firmado por <strong><?= firma_e($firma['firmante_nombre'] ?: 'Usuario autorizado') ?></strong> el <?= firma_e(firma_fecha($firma['fecha_firma'] ?? null)) ?>.</span>
                    </div>
                <?php endif; ?>

                <div class="signature-field">
                    <div class="signature-field-label">
                        <label for="signatureCanvas">Firma electrónica — Jefe de laboratorio</label>
                        <?php if ($puedeCapturar): ?>
                            <button type="button" class="signature-clear" id="clearSignature"><?= firma_svg('borrar') ?> Limpiar</button>
                        <?php endif; ?>
                    </div>

                    <?php if ($firma !== null): ?>
                        <div class="signature-pad is-signed">
                            <?php if (str_starts_with((string) ($firma['firma_png'] ?? ''), 'data:image/png;base64,')): ?>
                                <img src="<?= firma_e($firma['firma_png']) ?>" alt="Firma electrónica registrada">
                            <?php else: ?>
                                <?= firma_svg('ok') ?><strong>Firma registrada</strong>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($puedeCapturar): ?>
                        <div class="signature-pad" id="signaturePad">
                            <canvas id="signatureCanvas" aria-label="Área para dibujar la firma"></canvas>
                            <span class="signature-placeholder" id="signaturePlaceholder"><?= firma_svg('firma') ?>Dibuja aquí tu firma</span>
                        </div>
                    <?php else: ?>
                        <div class="signature-pad is-disabled">
                            <?= firma_svg('firma') ?>
                            <span><?= $informe === null ? 'Firma disponible al seleccionar un informe' : 'Firma disponible al aprobar todos los análisis' ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="signature-recipient">Se enviará al correo: <strong><?= firma_e($detalle['correo'] !== '' ? $detalle['correo'] : '—') ?></strong></p>

                <form method="post" id="signatureForm" class="signature-actions">
                    <input type="hidden" name="csrf_token" value="<?= firma_e($_SESSION['firma_electronica_csrf']) ?>">
                    <input type="hidden" name="id_documento" value="<?= (int) ($informe['id_documento'] ?? 0) ?>">
                    <input type="hidden" name="firma_png" id="firmaPng" value="">
                    <button type="submit" class="signature-button is-primary" id="submitSignature" disabled>
                        <?= firma_svg('firma') ?>
                        <?= $firma ? 'Informe firmado' : 'Aprobar y registrar firma electrónica' ?>
                    </button>
                    <a class="signature-button is-ghost<?= $informe ? '' : ' is-disabled' ?>" href="<?= $informe ? '../controllers/consolidacion_controller.php' : '#' ?>" <?= $informe ? '' : 'aria-disabled="true"' ?>>
                        <?= firma_svg('volver') ?> Volver a control de calidad
                    </a>
                </form>
            </section>

            <section class="signature-card signature-summary-card">
                <header class="signature-card-head">
                    <h2>Resumen de análisis del lote</h2>
                    <p>Incluye todos los análisis solicitados para cada muestra.</p>
                </header>
                <div class="signature-table-wrap">
                    <table class="signature-table">
                        <thead><tr><th>Muestra</th><th>Análisis</th><th>Valor</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php if (empty($resumen)): ?>
                                <tr><td colspan="4" class="signature-table-empty"><?= $informe ? 'No hay análisis asociados a este lote.' : 'Selecciona un informe para ver el resumen.' ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($resumen as $fila): ?>
                                    <?php $estadoClase = $fila['estado'] === 'Aprobado' ? 'is-approved' : ($fila['estado'] === 'En revisión' ? 'is-review' : 'is-pending'); ?>
                                    <tr>
                                        <td class="signature-sample"><?= firma_e($fila['muestra'] ?? '-') ?></td>
                                        <td><?= firma_e($fila['analisis'] ?? '-') ?></td>
                                        <td class="signature-value">—</td>
                                        <td><span class="signature-status <?= $estadoClase ?>"><?= firma_e($fila['estado'] ?? 'Pendiente') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

<?php lab_shell_content_close(); ?>
<?php if ($puedeCapturar): ?>
<script>
(function () {
    var canvas = document.getElementById('signatureCanvas');
    var pad = document.getElementById('signaturePad');
    var placeholder = document.getElementById('signaturePlaceholder');
    var clear = document.getElementById('clearSignature');
    var form = document.getElementById('signatureForm');
    var submit = document.getElementById('submitSignature');
    var output = document.getElementById('firmaPng');
    if (!canvas || !pad || !form) return;

    var context = canvas.getContext('2d');
    var drawing = false;
    var hasInk = false;
    var ratio = Math.max(window.devicePixelRatio || 1, 1);

    function sizeCanvas() {
        var rect = pad.getBoundingClientRect();
        canvas.width = Math.max(1, Math.round(rect.width * ratio));
        canvas.height = Math.max(1, Math.round(rect.height * ratio));
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.strokeStyle = '#24301f';
        context.lineWidth = 2.1;
        context.lineCap = 'round';
        context.lineJoin = 'round';
    }

    function point(event) {
        var rect = canvas.getBoundingClientRect();
        return {x: event.clientX - rect.left, y: event.clientY - rect.top};
    }

    function start(event) {
        event.preventDefault();
        drawing = true;
        var p = point(event);
        context.beginPath();
        context.moveTo(p.x, p.y);
        canvas.setPointerCapture(event.pointerId);
    }

    function move(event) {
        if (!drawing) return;
        event.preventDefault();
        var p = point(event);
        context.lineTo(p.x, p.y);
        context.stroke();
        hasInk = true;
        placeholder.hidden = true;
        submit.disabled = false;
    }

    function stop(event) {
        if (!drawing) return;
        drawing = false;
        context.closePath();
        if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    }

    function reset() {
        context.clearRect(0, 0, canvas.width / ratio, canvas.height / ratio);
        hasInk = false;
        placeholder.hidden = false;
        output.value = '';
        submit.disabled = true;
    }

    sizeCanvas();
    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    clear.addEventListener('click', reset);
    form.addEventListener('submit', function (event) {
        if (!hasInk) {
            event.preventDefault();
            return;
        }
        output.value = canvas.toDataURL('image/png');
        submit.disabled = true;
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    var selector = document.getElementById('firmaInforme');
    if (!selector || selector.disabled) return;
    selector.addEventListener('change', function () {
        window.location.href = 'firma_electronica_view.php?id_documento=' + encodeURIComponent(selector.value);
    });
})();
</script>
</body>
</html>
