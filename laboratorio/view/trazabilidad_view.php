<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/trazabilidad_model.php';

lab_require_permission('laboratorio.trazabilidad.ver');

$pdo = Conexion::conectar();

$lotes = lab_trazabilidad_obtener_lotes($pdo);
$idLoteSeleccionado = !empty($_GET['id_lote']) ? (int) $_GET['id_lote'] : null;
$loteSeleccionado = null;
$linea = [];
$tablaDisponible = lab_trazabilidad_tabla_existe($pdo, 'lab_evento_trazabilidad');

if ($idLoteSeleccionado) {
    foreach ($lotes as $lote) {
        if ((int) $lote['id_lote'] === $idLoteSeleccionado) {
            $loteSeleccionado = $lote;
            break;
        }
    }

    if ($loteSeleccionado) {
        $linea = lab_trazabilidad_obtener_linea_tiempo($pdo, $idLoteSeleccionado);
    }
}

function trz_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function trz_fecha_legible($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

lab_shell_head('Trazabilidad', 'Linea de tiempo completa de un lote: recepcion, analisis, validacion, informe y entrega', [
    '../css/lab_shell.css?v=1',
]);
lab_shell_open('trazabilidad_view.php');
?>

<?php if (!$tablaDisponible): ?>
    <div class="cengi-empty" style="margin-bottom:18px;">
        La tabla <code>lab_evento_trazabilidad</code> todavia no existe en esta base de datos.
        Aplique <code>laboratorio/database/002_lab_flujo_generico.sql</code> para habilitar el
        registro de eventos generales de lote (recepcion, boleta, entrega). Mientras tanto esta
        vista solo mostrara el historial por analisis ya existente (<code>historial_formulario</code>).
    </div>
<?php endif; ?>

<div class="cengi-card" style="margin-bottom:18px;">
    <h3>Seleccionar lote</h3>
    <div class="cengi-card-sub">Elige un lote para ver toda su trazabilidad, de la recepcion a la entrega</div>
    <form method="GET" class="flex" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select name="id_lote" style="min-width:260px;padding:9px 10px;border-radius:8px;border:1px solid var(--cengi-border);">
            <option value="">Seleccione un lote…</option>
            <?php foreach ($lotes as $lote): ?>
                <option value="<?= (int) $lote['id_lote'] ?>" <?= $idLoteSeleccionado === (int) $lote['id_lote'] ? 'selected' : '' ?>>
                    Lote <?= trz_e($lote['codigo_lote']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="cengi-btn cengi-btn-primary">
            <span class="material-symbols-outlined" style="font-size:16px;">search</span>
            Ver trazabilidad
        </button>
    </form>
</div>

<?php if ($idLoteSeleccionado && !$loteSeleccionado): ?>
    <div class="cengi-empty">El lote seleccionado no existe.</div>
<?php elseif ($loteSeleccionado): ?>
    <div class="cengi-card">
        <h3>Lote <?= trz_e($loteSeleccionado['codigo_lote']) ?></h3>
        <div class="cengi-card-sub"><?= count($linea) ?> eventos registrados</div>

        <?php if (empty($linea)): ?>
            <div class="cengi-empty">
                Todavia no hay eventos de trazabilidad para este lote.
            </div>
        <?php else: ?>
            <div class="cengi-timeline">
                <?php foreach ($linea as $evento): ?>
                    <div class="cengi-timeline-item <?= $evento['alerta'] ? 'is-warn' : 'is-ok' ?>">
                        <div class="cengi-timeline-dot">
                            <span class="material-symbols-outlined" style="font-size:12px;">
                                <?= $evento['alerta'] ? 'priority_high' : 'check' ?>
                            </span>
                        </div>
                        <div class="cengi-timeline-head">
                            <b><?= trz_e($evento['titulo']) ?></b>
                            <span class="cengi-timeline-fecha"><?= trz_e(trz_fecha_legible($evento['fecha'])) ?></span>
                        </div>
                        <?php if (!empty($evento['detalle'])): ?>
                            <div class="cengi-timeline-detail"><?= trz_e($evento['detalle']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($evento['usuario'])): ?>
                            <div class="cengi-timeline-detail" style="color:var(--cengi-muted);">Por <?= trz_e($evento['usuario']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="cengi-empty">Selecciona un lote para ver su trazabilidad.</div>
<?php endif; ?>

<?php lab_shell_close(); ?>
