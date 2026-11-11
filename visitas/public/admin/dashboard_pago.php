<?php
require_once("../../config/auth.php");
require_login();
require_once __DIR__ . '/../../config/presentation.php';
require_once __DIR__ . '/../../config/payment_details.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!can_access_any(['ver_pagos', 'gestionar_pagos'])) {
    header("Location: dashboard_unificado.php");
    exit;
}

$_SESSION['visits_csrf'] ??= bin2hex(random_bytes(32));
$canManage = can_access('gestionar_pagos');
$flash = $_SESSION['visits_payment_flash'] ?? null;
unset($_SESSION['visits_payment_flash']);

$conn = Conexion::conectar();
$hasDetails = visitas_payment_details_available($conn);

$areasAggregate = Conexion::groupConcat('ai.nombre_area', true);
$detailColumns = $hasDetails
    ? 'm.monto_pagado, m.observacion_pago, m.fecha_pago, m.pago_actualizado_por, m.pago_actualizado_en,'
    : '';
$detailGroup = $hasDetails
    ? ', m.monto_pagado, m.observacion_pago, m.fecha_pago, m.pago_actualizado_por, m.pago_actualizado_en'
    : '';
$sql = "
SELECT
    s.id_solicitud,
    so.nombre_institucion,
    so.nombre_solicitante,
    so.telefono,
    MAX(COALESCE(m.total, 0)) AS total_museo,
    s.cantidad_visitantes,
    s.fecha_visita,
    s.hora_visita,
    e.nombre_estado,
    {$areasAggregate} AS areas,
    COALESCE(ep.nombre_estado_pago, 'PENDIENTE') AS nombre_estado_pago,
    {$detailColumns}
    m.id_estado_pago
FROM solicitudes s
INNER JOIN solicitantes so ON s.id_solicitante = so.id_solicitante
INNER JOIN estados e ON s.id_estado = e.id_estado
LEFT JOIN solicitud_areas sa ON s.id_solicitud = sa.id_solicitud
LEFT JOIN areas_interes ai ON sa.id_area = ai.id_area
INNER JOIN solicitud_museo m ON s.id_solicitud = m.id_solicitud
LEFT JOIN estado_pago ep ON m.id_estado_pago = ep.id_estado_pago
WHERE LOWER(e.nombre_estado) IN ('aprobado', 'notificado')
GROUP BY
    s.id_solicitud,
    so.nombre_institucion,
    so.nombre_solicitante,
    so.telefono,
    s.cantidad_visitantes,
    s.fecha_visita,
    s.hora_visita,
    e.nombre_estado,
    ep.nombre_estado_pago,
    m.id_estado_pago
    {$detailGroup}
ORDER BY s.fecha_registro DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = ['pending' => 0, 'pendingAmount' => 0.0, 'paid' => 0, 'paidAmount' => 0.0, 'quoted' => 0.0];
foreach ($datos as &$row) {
    $row['is_paid'] = (int) ($row['id_estado_pago'] ?? 0) === 2;
    $quoted = round((float) ($row['total_museo'] ?? 0), 2);
    $row['paid_amount'] = isset($row['monto_pagado']) && $row['monto_pagado'] !== null
        ? round((float) $row['monto_pagado'], 2)
        : $quoted;
    $summary['quoted'] += $quoted;
    if ($row['is_paid']) {
        $summary['paid']++;
        $summary['paidAmount'] += $row['paid_amount'];
    } else {
        $summary['pending']++;
        $summary['pendingAmount'] += $quoted;
    }
}
unset($row);
$summary['all'] = count($datos);

function pago_money($value): string
{
    return 'Q ' . number_format((float) $value, 2);
}

function pago_date($value): string
{
    $time = strtotime((string) $value);
    if (!$value || $time === false) {
        return 'Sin fecha';
    }
    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', $time) . ' ' . $months[(int) date('n', $time) - 1] . ' ' . date('Y', $time);
}

function pago_hour($value): string
{
    $value = trim((string) $value);
    if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $match)) {
        return str_pad($match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2];
    }
    return $value;
}

function pago_datetime($value): string
{
    $time = strtotime((string) $value);
    return $value && $time !== false ? pago_date($value) . ', ' . date('H:i', $time) : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagos de visitas</title>
    <link rel="stylesheet" href="../../assets/dashboard.css">
    <link rel="stylesheet" href="../../assets/cengisalas-theme.css?v=20260902-2">
<?php visitas_ui_head(); ?>
    <style>
        .pay-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin: 0 0 20px; }
        .pay-kpi { display: grid; gap: 4px; padding: 16px 18px; border: 1px solid var(--visits-border); border-left: 4px solid var(--visits-primary); border-radius: 12px; background: #fff; box-shadow: var(--visits-shadow); }
        .pay-kpi.pending { border-left-color: #e08a00; }
        .pay-kpi.paid { border-left-color: var(--visits-deep); }
        .pay-kpi span { color: var(--visits-muted); font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .pay-kpi strong { color: var(--visits-ink); font-size: 24px; font-weight: 800; font-variant-numeric: tabular-nums; }
        .pay-kpi small { color: var(--text-muted); font-size: 12.5px; }

        .pay-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--visits-border); }
        .pay-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
        .pay-tabs button { display: inline-flex; align-items: center; gap: 7px; height: 34px; padding: 0 12px; border: 1px solid var(--visits-border); border-radius: 999px; color: var(--visits-muted); background: #fff; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
        .pay-tabs button b { min-width: 20px; padding: 1px 6px; border-radius: 999px; background: #eef2ec; font-size: 11.5px; text-align: center; }
        .pay-tabs button.active { border-color: var(--visits-deep); color: #fff; background: var(--visits-deep); }
        .pay-tabs button.active b { color: var(--visits-deep); background: #fff; }
        .pay-tabs button:focus-visible, .pay-btn:focus-visible { outline: 3px solid rgba(115, 188, 37, .45); outline-offset: 2px; }
        .pay-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .pay-search { display: flex; align-items: center; gap: 6px; height: 36px; min-width: 260px; padding: 0 10px; border: 1px solid var(--border-strong); border-radius: 9px; background: #fff; }
        .pay-search:focus-within { border-color: var(--visits-primary); box-shadow: 0 0 0 3px rgba(115, 188, 37, .2); }
        .pay-search svg { flex: none; color: var(--text-muted); }
        .pay-search input { width: 100%; border: 0; outline: 0; background: transparent; font: inherit; font-size: 13px; color: var(--visits-ink); }
        .pay-count { color: var(--visits-muted); font-size: 12.5px; font-weight: 600; white-space: nowrap; }

        .pay-table { width: 100%; min-width: 1040px; border-collapse: collapse; }
        .pay-table th { position: sticky; top: 0; z-index: 1; padding: 11px 14px; border-bottom: 1px solid var(--visits-border); color: var(--visits-muted); background: var(--surface-muted); font-size: 11.5px; font-weight: 700; letter-spacing: .04em; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .pay-table td { padding: 12px 14px; border-bottom: 1px solid #edf2ec; color: var(--visits-ink); font-size: 13.5px; vertical-align: middle; }
        .pay-table tbody tr:hover { background: #fafcf7; }
        .pay-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pay-table td small { display: block; margin-top: 2px; color: var(--text-muted); font-size: 12px; }
        .pay-id { color: var(--text-muted); font-variant-numeric: tabular-nums; }
        .pay-amount { font-weight: 800; }
        .pay-amount small.diff { color: #8a5300; font-weight: 700; }
        .pay-areas { display: flex; flex-wrap: wrap; gap: 4px; max-width: 220px; }
        .pay-areas span { padding: 2px 8px; border-radius: 999px; color: var(--visits-deep); background: var(--visits-soft); font-size: 12px; font-weight: 600; }
        .pay-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; letter-spacing: .03em; white-space: nowrap; }
        .pay-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .pay-badge.pending { color: #8a5300; background: #fff4de; }
        .pay-badge.paid { color: var(--visits-deep); background: var(--visits-soft); }
        .pay-badge.request { color: #3d5566; background: #edf2f6; }
        .pay-note { display: -webkit-box; max-width: 240px; margin-top: 6px; overflow: hidden; color: var(--visits-ink); font-size: 12.5px; line-height: 1.4; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .pay-note::before { content: "“"; color: var(--text-muted); }
        .pay-note::after { content: "”"; color: var(--text-muted); }
        .pay-action { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 6px; }
        .pay-action form { margin: 0; }
        .pay-btn { height: 32px; padding: 0 12px; border: 1px solid transparent; border-radius: 8px; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .pay-btn.primary { color: #fff; background: var(--visits-deep); }
        .pay-btn.primary:hover { background: #163d24; }
        .pay-btn.secondary { border-color: var(--border-strong); color: var(--visits-ink); background: #fff; }
        .pay-btn.secondary:hover { border-color: var(--visits-deep); }
        .pay-btn.ghost { border-color: var(--border-strong); color: var(--visits-muted); background: #fff; }
        .pay-btn.ghost:hover { border-color: #c0392b; color: #a52a1d; }
        .pay-btn:disabled { opacity: .6; cursor: progress; }
        .pay-empty { padding: 36px 16px !important; color: var(--visits-muted) !important; text-align: center; }

        .pay-flash { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 11px 14px; border-radius: 10px; font-size: 13.5px; font-weight: 600; }
        .pay-flash.ok { color: var(--visits-deep); background: var(--visits-soft); border: 1px solid #cfe6b5; }
        .pay-flash.error { color: #8f2418; background: #fdeceb; border: 1px solid #f3c4bf; }
        .pay-flash.warn { color: #7a4a00; background: #fff6e3; border: 1px solid #f1dbab; }

        .pay-dialog { width: min(480px, calc(100vw - 32px)); padding: 0; border: 0; border-radius: 14px; color: var(--visits-ink); box-shadow: 0 20px 60px rgb(20 40 25 / 25%); }
        .pay-dialog::backdrop { background: rgb(20 35 25 / 45%); }
        .pay-dialog form { display: grid; gap: 14px; padding: 22px; }
        .pay-dialog h3 { margin: 0; font-size: 18px; }
        .pay-dialog .pay-dialog-sub { margin: -8px 0 0; color: var(--visits-muted); font-size: 13px; }
        .pay-dialog label { display: grid; gap: 6px; color: var(--visits-muted); font-size: 12.5px; font-weight: 700; }
        .pay-dialog input, .pay-dialog textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--border-strong); border-radius: 9px; color: var(--visits-ink); background: #fff; font: inherit; font-size: 14px; font-weight: 500; box-sizing: border-box; }
        .pay-dialog textarea { min-height: 88px; resize: vertical; }
        .pay-dialog input:focus, .pay-dialog textarea:focus { border-color: var(--visits-primary); outline: 0; box-shadow: 0 0 0 3px rgba(115, 188, 37, .2); }
        .pay-dialog .pay-hint { color: var(--text-muted); font-size: 12px; font-weight: 500; }
        .pay-dialog .pay-hint.diff { color: #8a5300; font-weight: 700; }
        .pay-dialog-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; padding-top: 4px; }
        .pay-dialog-actions .pay-btn { height: 36px; }

        @media (max-width: 860px) {
            .pay-summary { grid-template-columns: 1fr; }
            .pay-search { min-width: 0; flex: 1 1 100%; }
            .pay-filters { width: 100%; }
        }
    </style>
</head>
<body class="visits-admin">
<?php visitas_ui_shell("Pagos de visitas", "Revisión de pagos de solicitudes aprobadas", "pagos"); ?>

<div class="layout">

<main class="main-content" id="visits-content" tabindex="-1">
    <header class="dashboard-header">
        <div>
            <h2>Pagos de visitas</h2>
            <p>Solicitudes aprobadas o notificadas con cobro asociado.<?= $canManage ? '' : ' Tienes acceso de solo lectura.' ?></p>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="pay-flash <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" role="status"><?= visitas_ui_escape($flash['message'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($canManage && !$hasDetails): ?>
        <div class="pay-flash warn" role="note">Para registrar el monto pagado y observaciones falta aplicar la migración <b>40_visit_payment_details.sql</b> en Supabase. Mientras tanto solo se puede marcar el pago como pagado o pendiente.</div>
    <?php endif; ?>

    <section class="pay-summary" aria-label="Resumen de pagos">
        <div class="pay-kpi pending">
            <span>Por cobrar</span>
            <strong><?= pago_money($summary['pendingAmount']) ?></strong>
            <small><?= $summary['pending'] ?> <?= $summary['pending'] === 1 ? 'visita pendiente' : 'visitas pendientes' ?></small>
        </div>
        <div class="pay-kpi paid">
            <span>Cobrado</span>
            <strong><?= pago_money($summary['paidAmount']) ?></strong>
            <small><?= $summary['paid'] ?> <?= $summary['paid'] === 1 ? 'visita pagada' : 'visitas pagadas' ?></small>
        </div>
        <div class="pay-kpi">
            <span>Total cotizado</span>
            <strong><?= pago_money($summary['quoted']) ?></strong>
            <small><?= $summary['all'] ?> <?= $summary['all'] === 1 ? 'visita en total' : 'visitas en total' ?></small>
        </div>
    </section>

    <div class="card-table">
        <div class="pay-toolbar">
            <div class="pay-tabs" role="group" aria-label="Filtrar por estado de pago">
                <button type="button" class="active" data-pay-filter="pending" aria-pressed="true">Pendientes <b><?= $summary['pending'] ?></b></button>
                <button type="button" data-pay-filter="paid" aria-pressed="false">Pagadas <b><?= $summary['paid'] ?></b></button>
                <button type="button" data-pay-filter="all" aria-pressed="false">Todas <b><?= $summary['all'] ?></b></button>
            </div>
            <div class="pay-filters">
                <label class="pay-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="paySearch" type="search" placeholder="Buscar institución, solicitante, área, ID u observación" aria-label="Buscar pagos">
                </label>
                <span id="payCount" class="pay-count" aria-live="polite"></span>
            </div>
        </div>
        <div class="table-scroll">
            <table class="pay-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Institución</th>
                        <th>Áreas</th>
                        <th>Fecha de visita</th>
                        <th class="num">Visitantes</th>
                        <th class="num">Monto</th>
                        <th>Solicitud</th>
                        <th>Pago</th>
                        <?php if ($canManage): ?><th class="num">Acción</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="payRows">
                    <?php foreach ($datos as $row): ?>
                        <?php
                        $id = (string) ($row['id_solicitud'] ?? '');
                        $quoted = round((float) ($row['total_museo'] ?? 0), 2);
                        $paidAmount = $row['paid_amount'];
                        $note = trim((string) ($row['observacion_pago'] ?? ''));
                        $hasDiff = $row['is_paid'] && abs($paidAmount - $quoted) >= 0.01;
                        $areas = array_values(array_filter(array_map('trim', explode(',', (string) ($row['areas'] ?? '')))));
                        $updatedBy = trim((string) ($row['pago_actualizado_por'] ?? ''));
                        $updatedAt = pago_datetime($row['pago_actualizado_en'] ?? '');
                        $searchText = mb_strtolower(implode(' ', [
                            $id,
                            $row['nombre_institucion'] ?? '',
                            $row['nombre_solicitante'] ?? '',
                            $row['telefono'] ?? '',
                            implode(' ', $areas),
                            $note,
                        ]));
                        ?>
                        <tr data-pay-status="<?= $row['is_paid'] ? 'paid' : 'pending' ?>" data-search="<?= visitas_ui_escape($searchText) ?>">
                            <td class="pay-id">#<?= visitas_ui_escape($id) ?></td>
                            <td>
                                <b><?= visitas_ui_escape($row['nombre_institucion'] ?? 'Sin institución') ?></b>
                                <small><?= visitas_ui_escape($row['nombre_solicitante'] ?? '') ?><?= !empty($row['telefono']) ? ' · ' . visitas_ui_escape($row['telefono']) : '' ?></small>
                            </td>
                            <td>
                                <?php if ($areas): ?>
                                    <div class="pay-areas"><?php foreach ($areas as $area): ?><span><?= visitas_ui_escape($area) ?></span><?php endforeach; ?></div>
                                <?php else: ?>
                                    <small>Sin área</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <b><?= visitas_ui_escape(pago_date($row['fecha_visita'] ?? '')) ?></b>
                                <small><?= visitas_ui_escape(pago_hour($row['hora_visita'] ?? '')) ?></small>
                            </td>
                            <td class="num"><?= (int) ($row['cantidad_visitantes'] ?? 0) ?></td>
                            <td class="num pay-amount">
                                <?php if ($row['is_paid']): ?>
                                    <?= pago_money($paidAmount) ?>
                                    <?php if ($hasDiff): ?>
                                        <small class="diff">Cotizado <?= pago_money($quoted) ?></small>
                                    <?php else: ?>
                                        <small>Pagado</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= pago_money($quoted) ?>
                                    <small>Cotizado</small>
                                <?php endif; ?>
                            </td>
                            <td><span class="pay-badge request"><?= visitas_ui_escape(mb_convert_case((string) ($row['nombre_estado'] ?? ''), MB_CASE_TITLE)) ?></span></td>
                            <td>
                                <?php if ($row['is_paid']): ?>
                                    <span class="pay-badge paid">Pagado</span>
                                <?php else: ?>
                                    <span class="pay-badge pending">Pendiente</span>
                                <?php endif; ?>
                                <?php if ($note !== ''): ?>
                                    <div class="pay-note" title="<?= visitas_ui_escape($note) ?>"><?= visitas_ui_escape($note) ?></div>
                                <?php endif; ?>
                                <?php if ($updatedBy !== '' || $updatedAt !== ''): ?>
                                    <small><?= visitas_ui_escape(trim($updatedBy . ($updatedAt !== '' ? ' · ' . $updatedAt : ''), ' ·')) ?></small>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td>
                                    <div class="pay-action">
                                        <?php if ($hasDetails): ?>
                                            <button type="button" class="pay-btn <?= $row['is_paid'] ? 'secondary' : 'primary' ?>"
                                                data-pay-open
                                                data-id="<?= visitas_ui_escape($id) ?>"
                                                data-paid="<?= $row['is_paid'] ? '1' : '0' ?>"
                                                data-institution="<?= visitas_ui_escape($row['nombre_institucion'] ?? '') ?>"
                                                data-quoted="<?= number_format($quoted, 2, '.', '') ?>"
                                                data-amount="<?= number_format($paidAmount, 2, '.', '') ?>"
                                                data-note="<?= visitas_ui_escape($note) ?>"><?= $row['is_paid'] ? 'Editar' : 'Registrar pago' ?></button>
                                        <?php endif; ?>
                                        <?php if ($row['is_paid'] || !$hasDetails): ?>
                                            <form method="POST" action="actualizar_pago.php" data-confirm="<?= $row['is_paid']
                                                ? visitas_ui_escape('¿Revertir el pago de la solicitud #' . $id . ' a pendiente? Se borrará el monto pagado registrado.')
                                                : visitas_ui_escape('¿Confirmas que la solicitud #' . $id . ' (' . pago_money($quoted) . ') ya fue pagada?') ?>">
                                                <input type="hidden" name="csrf" value="<?= visitas_ui_escape($_SESSION['visits_csrf']) ?>">
                                                <input type="hidden" name="id_solicitud" value="<?= visitas_ui_escape($id) ?>">
                                                <input type="hidden" name="accion" value="<?= $row['is_paid'] ? 'pendiente' : 'pagar' ?>">
                                                <?php if ($row['is_paid']): ?>
                                                    <button type="submit" class="pay-btn ghost">Revertir</button>
                                                <?php else: ?>
                                                    <button type="submit" class="pay-btn primary">Marcar pagado</button>
                                                <?php endif; ?>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="payEmpty" hidden>
                        <td class="pay-empty" colspan="<?= $canManage ? 9 : 8 ?>">No hay pagos que coincidan con el filtro o la búsqueda.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

</div>

<?php if ($canManage && $hasDetails): ?>
<dialog id="payDialog" class="pay-dialog" aria-labelledby="payDialogTitle">
    <form method="POST" action="actualizar_pago.php" id="payDialogForm">
        <h3 id="payDialogTitle">Registrar pago</h3>
        <p class="pay-dialog-sub" id="payDialogSub"></p>
        <input type="hidden" name="csrf" value="<?= visitas_ui_escape($_SESSION['visits_csrf']) ?>">
        <input type="hidden" name="id_solicitud" id="payDialogId">
        <label id="payAmountField">Monto pagado (Q)
            <input type="number" name="monto_pagado" id="payDialogAmount" min="0" step="0.01" inputmode="decimal" required>
            <span class="pay-hint" id="payDialogHint"></span>
        </label>
        <label>Observación
            <textarea name="observacion_pago" id="payDialogNote" maxlength="1000" placeholder="Ej.: pago con descuento institucional, número de boleta, pago parcial…"></textarea>
            <span class="pay-hint" id="payDialogNoteCount">0 / 1000</span>
        </label>
        <div class="pay-dialog-actions">
            <button type="button" class="pay-btn ghost" data-pay-close>Cancelar</button>
            <button type="submit" class="pay-btn secondary" name="accion" value="nota" id="payDialogNoteOnly" formnovalidate>Solo guardar observación</button>
            <button type="submit" class="pay-btn primary" name="accion" value="pagar" id="payDialogSubmit">Marcar pagado</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script>
(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#payRows tr[data-pay-status]'));
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-pay-filter]'));
    var search = document.getElementById('paySearch');
    var count = document.getElementById('payCount');
    var empty = document.getElementById('payEmpty');
    var status = 'pending';

    function normalize(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function apply() {
        var term = normalize(search.value.trim());
        var visible = 0;
        rows.forEach(function (row) {
            var show = (status === 'all' || row.dataset.payStatus === status)
                && (!term || normalize(row.dataset.search).indexOf(term) !== -1);
            row.hidden = !show;
            if (show) visible++;
        });
        empty.hidden = visible > 0;
        count.textContent = visible + (visible === 1 ? ' visita' : ' visitas');
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            status = tab.dataset.payFilter;
            tabs.forEach(function (other) {
                other.classList.toggle('active', other === tab);
                other.setAttribute('aria-pressed', other === tab ? 'true' : 'false');
            });
            apply();
        });
    });
    search.addEventListener('input', apply);

    function lockOnSubmit(form, submitter) {
        var buttons = form.querySelectorAll('button[type="submit"]');
        // Se difiere para que el valor del botón pulsado (accion) viaje en el envío.
        setTimeout(function () {
            buttons.forEach(function (button) { button.disabled = true; });
            if (submitter) submitter.textContent = 'Guardando…';
        }, 0);
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                return;
            }
            lockOnSubmit(form, event.submitter || form.querySelector('button[type="submit"]'));
        });
    });

    var dialog = document.getElementById('payDialog');
    if (!dialog) {
        apply();
        return;
    }
    var form = document.getElementById('payDialogForm');
    var amount = document.getElementById('payDialogAmount');
    var note = document.getElementById('payDialogNote');
    var hint = document.getElementById('payDialogHint');
    var noteCount = document.getElementById('payDialogNoteCount');
    var submit = document.getElementById('payDialogSubmit');
    var noteOnly = document.getElementById('payDialogNoteOnly');
    var quoted = 0;
    var opener = null;

    function money(value) {
        return 'Q ' + Number(value || 0).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateHint() {
        var value = parseFloat(amount.value);
        if (isNaN(value)) {
            hint.textContent = 'Total cotizado: ' + money(quoted);
            hint.classList.remove('diff');
            return;
        }
        var diff = Math.round((value - quoted) * 100) / 100;
        hint.classList.toggle('diff', diff !== 0);
        hint.textContent = diff === 0
            ? 'Coincide con el total cotizado (' + money(quoted) + ').'
            : 'Total cotizado ' + money(quoted) + ' · diferencia ' + (diff > 0 ? '+' : '−') + money(Math.abs(diff)) + '.';
    }

    function updateNoteCount() {
        noteCount.textContent = note.value.length + ' / 1000';
    }

    document.querySelectorAll('[data-pay-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var paid = button.dataset.paid === '1';
            opener = button;
            quoted = parseFloat(button.dataset.quoted) || 0;
            document.getElementById('payDialogId').value = button.dataset.id;
            document.getElementById('payDialogTitle').textContent = paid ? 'Editar pago' : 'Registrar pago';
            document.getElementById('payDialogSub').textContent = 'Solicitud #' + button.dataset.id + (button.dataset.institution ? ' · ' + button.dataset.institution : '');
            amount.value = button.dataset.amount;
            note.value = button.dataset.note || '';
            submit.value = paid ? 'editar' : 'pagar';
            submit.textContent = paid ? 'Guardar cambios' : 'Marcar pagado';
            submit.disabled = false;
            noteOnly.hidden = paid;
            noteOnly.disabled = false;
            noteOnly.textContent = 'Solo guardar observación';
            updateHint();
            updateNoteCount();
            dialog.showModal();
            amount.focus();
            amount.select();
        });
    });

    amount.addEventListener('input', updateHint);
    note.addEventListener('input', updateNoteCount);
    dialog.querySelector('[data-pay-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { if (opener) opener.focus(); });
    form.addEventListener('submit', function (event) {
        lockOnSubmit(form, event.submitter);
    });

    apply();
})();
</script>
</body>
</html>
