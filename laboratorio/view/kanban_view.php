<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/kanban_model.php';

lab_require_permission('laboratorio.kanban.ver');

$puedeGestionar = lab_can('laboratorio.kanban.gestionar');

$pdo = Conexion::conectar();

$idLoteFiltro = !empty($_GET['id_lote']) ? (int) $_GET['id_lote'] : null;
$lotes = $pdo->query('SELECT id_lote, codigo_lote FROM lote ORDER BY id_lote DESC')->fetchAll(PDO::FETCH_ASSOC);

$columnas = lab_kanban_columnas($pdo);
$tarjetas = lab_kanban_tarjetas($pdo, $idLoteFiltro);

$porEstado = [];
foreach ($columnas as $col) {
    $porEstado[mb_strtolower($col['nombre'])] = [];
}
foreach ($tarjetas as $tarjeta) {
    $clave = mb_strtolower(trim((string) ($tarjeta['estado_nombre'] ?? 'pendiente')));
    if ($clave === '' || $clave === '0') {
        $clave = 'pendiente';
    }
    if (!isset($porEstado[$clave])) {
        $porEstado[$clave] = [];
    }
    $porEstado[$clave][] = $tarjeta;
}

function kb_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

lab_shell_head('Planificacion', 'Tablero de asignacion de analisis por analista (arrastra las tarjetas entre columnas)', [
    '../css/lab_shell.css?v=1',
]);
lab_shell_open('kanban_view.php');
?>

<div class="cengi-card" style="margin-bottom:18px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select name="id_lote" style="min-width:220px;padding:9px 10px;border-radius:8px;border:1px solid var(--cengi-border);">
            <option value="">Todos los lotes</option>
            <?php foreach ($lotes as $lote): ?>
                <option value="<?= (int) $lote['id_lote'] ?>" <?= $idLoteFiltro === (int) $lote['id_lote'] ? 'selected' : '' ?>>
                    Lote <?= kb_e($lote['codigo_lote']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="cengi-btn cengi-btn-ghost">Filtrar</button>
        <span class="cengi-status-badge is-neutral" style="margin-left:auto;">
            <?= count($tarjetas) ?> analisis en tablero
        </span>
    </form>
</div>

<?php if (empty($tarjetas)): ?>
    <div class="cengi-empty">
        No hay analisis registrados en <code>formulario</code> todavia (o ninguno coincide con el filtro).
        Las tarjetas de este tablero se generan a partir de los analisis asignados a un rango de lote
        (tabla <code>formulario</code>), igual que en la Bandeja del analista.
    </div>
<?php endif; ?>

<div class="cengi-kanban-board" id="kanbanBoard" data-endpoint="../controllers/kanban_controller.php">
    <?php foreach ($columnas as $col): ?>
        <?php $clave = mb_strtolower($col['nombre']); $items = $porEstado[$clave] ?? []; ?>
        <div class="cengi-kanban-col" data-estado="<?= kb_e($col['nombre']) ?>">
            <div class="cengi-kanban-col-head">
                <span><?= kb_e($col['nombre']) ?></span>
                <span class="cengi-status-badge is-neutral"><?= count($items) ?></span>
            </div>
            <div class="cengi-kanban-col-body" style="display:flex;flex-direction:column;gap:8px;min-height:120px;">
                <?php foreach ($items as $tarjeta): ?>
                    <div class="cengi-kanban-card"
                         draggable="<?= $puedeGestionar ? 'true' : 'false' ?>"
                         data-id-formulario="<?= (int) $tarjeta['id_formulario'] ?>">
                        <h5><?= kb_e($tarjeta['analisis_nombre'] ?: 'Analisis sin nombre') ?></h5>
                        <div class="muted">Lote <?= kb_e($tarjeta['codigo_lote']) ?> · <?= kb_e($tarjeta['tipo_muestra'] ?: '—') ?></div>
                        <div class="muted"><?= $tarjeta['analista'] ? 'Analista: ' . kb_e($tarjeta['analista']) : 'Sin analista asignado' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$puedeGestionar): ?>
    <p class="muted" style="margin-top:14px;font-size:11.5px;color:var(--cengi-muted);">
        Tu rol solo tiene permiso de consulta sobre este tablero (<code>laboratorio.kanban.ver</code>);
        no puedes arrastrar tarjetas entre columnas.
    </p>
<?php endif; ?>

<div id="kanbanToast" style="position:fixed;bottom:24px;right:24px;background:#20242A;color:#fff;padding:12px 18px;border-radius:10px;font-size:12.5px;font-weight:600;box-shadow:0 6px 20px rgba(20,24,20,.2);z-index:200;display:flex;align-items:center;gap:9px;transform:translateY(20px);opacity:0;transition:all .25s ease;"></div>
<style>#kanbanToast.show{transform:translateY(0);opacity:1;}</style>

<?php if ($puedeGestionar): ?>
    <script src="../js/kanban.js?v=1"></script>
<?php endif; ?>

<?php lab_shell_close(); ?>
