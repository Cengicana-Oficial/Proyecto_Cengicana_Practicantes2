<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/validacion_tecnica_model.php';

lab_require_permission('laboratorio.validacion_tecnica.ver');

$puedeResolver = lab_can('laboratorio.validacion_tecnica.aprobar');

$pdo = Conexion::conectar();

$idLoteFiltro = !empty($_GET['id_lote']) ? (int) $_GET['id_lote'] : null;
$lotesDisponibles = $pdo->query("
    SELECT DISTINCT l.id_lote, l.codigo_lote
    FROM formulario f
    INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
    INNER JOIN lote l ON l.id_lote = lr.id_lote
    LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
    WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) LIKE '%revis%'
    ORDER BY l.codigo_lote DESC
")->fetchAll(PDO::FETCH_ASSOC);

$matriz = lab_validacion_obtener_matriz($pdo, $idLoteFiltro);
$totalPendientes = lab_validacion_contar_pendientes($pdo);

function vt_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function vt_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '-';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y', $ts) : $fecha;
}

lab_shell_head('Validacion tecnica', 'Matriz de aprobacion/rechazo de analisis capturados, por lote y tipo de analisis', [
    '../css/lab_shell.css?v=2',
]);
lab_shell_open('validacion_tecnica_view.php');

$puedeVerErroneos = lab_can_view_error_forms();
?>

<?php if ($puedeVerErroneos): ?>
    <a href="../controllers/formularios_erroneos_controller.php" class="cengi-btn cengi-btn-ghost cengi-btn-sm" style="margin-bottom:14px;">
        <span class="material-symbols-outlined" style="font-size:15px;">report</span>
        Ver formularios originales con errores
    </a>
<?php endif; ?>

<div class="cengi-kpi-grid">
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">fact_check</span></span>
        <div class="cengi-kpi-val"><?= (int) $totalPendientes ?></div>
        <div class="cengi-kpi-label">Analisis pendientes de validar</div>
        <div class="cengi-kpi-trend is-flat">Estado "En revision" / "Revisar"</div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">inventory_2</span></span>
        <div class="cengi-kpi-val"><?= count($matriz['lotes']) ?></div>
        <div class="cengi-kpi-label">Lotes con analisis en revision</div>
        <div class="cengi-kpi-trend is-flat">Filas de la matriz actual</div>
        <span class="cengi-kpi-bar"></span>
    </article>
</div>

<div class="cengi-card" style="margin-bottom:18px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select name="id_lote" style="min-width:220px;padding:9px 10px;border-radius:8px;border:1px solid var(--cengi-border);">
            <option value="">Todos los lotes con analisis en revision</option>
            <?php foreach ($lotesDisponibles as $lote): ?>
                <option value="<?= (int) $lote['id_lote'] ?>" <?= $idLoteFiltro === (int) $lote['id_lote'] ? 'selected' : '' ?>>
                    Lote <?= vt_e($lote['codigo_lote']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="cengi-btn cengi-btn-ghost">Filtrar</button>
    </form>
</div>

<?php if (empty($matriz['lineas'])): ?>
    <div class="cengi-empty">
        No hay analisis pendientes de validacion tecnica en este momento
        (ningun <code>formulario</code> con estado "En revision"/"Revisar"),
        o ninguno coincide con el filtro seleccionado.
    </div>
<?php else: ?>
    <div class="cengi-card">
        <h3>Lineas para validacion tecnica</h3>
        <div class="cengi-card-sub">
            Cada linea corresponde a un formulario capturado. Los botones de validacion
            se muestran en la ultima columna para los analisis que estan en revision.
        </div>

        <div class="cengi-table-wrap vt-table-wrap">
            <table class="vt-table">
                <thead>
                    <tr>
                        <th>Formulario</th>
                        <th>Lote</th>
                        <th>Rango</th>
                        <th>Tipo</th>
                        <th>Analisis</th>
                        <th>Analista</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matriz['lineas'] as $linea): ?>
                        <?php
                            $estado = mb_strtolower(trim((string) ($linea['estado_nombre'] ?? '')));
                            $esRevision = lab_validacion_estado_es_revision($linea['estado_nombre'] ?? '');
                            $esAprobado = strpos($estado, 'aprob') !== false;
                            $esRechazado = strpos($estado, 'rechaz') !== false;
                            $claseEstado = $esAprobado ? 'is-approved' : ($esRechazado ? 'is-rejected' : ($esRevision ? 'is-review' : 'is-neutral'));
                            $inicio = trim((string) ($linea['inicio'] ?? ''));
                            $fin = trim((string) ($linea['fin'] ?? ''));
                            $rango = $inicio !== '' && $fin !== ''
                                ? ($inicio === $fin ? $inicio : $inicio . ' - ' . $fin)
                                : '-';
                        ?>
                        <tr class="<?= $esRevision ? 'vt-row-pending' : '' ?>" data-id-formulario="<?= (int) $linea['id_formulario'] ?>">
                            <td><strong>#<?= (int) $linea['id_formulario'] ?></strong></td>
                            <td><strong><?= vt_e($linea['codigo_lote'] ?: '-') ?></strong></td>
                            <td><?= vt_e($rango) ?></td>
                            <td><?= vt_e($linea['tipo_muestra'] ?: 'Sin tipo') ?></td>
                            <td class="vt-analysis-cell"><?= vt_e($linea['analisis_nombre'] ?: 'Analisis sin identificar') ?></td>
                            <td><?= vt_e($linea['analista'] ?: 'Sin analista') ?></td>
                            <td><?= vt_e(vt_fecha($linea['fecha'] ?? null)) ?></td>
                            <td>
                                <span class="cengi-status-badge <?= $claseEstado ?>">
                                    <i></i><?= vt_e($linea['estado_nombre'] ?: 'Sin estado') ?>
                                </span>
                            </td>
                            <td>
                                <div class="vt-line-actions">
                                    <?php if (!empty($linea['id_rango']) && $puedeVerErroneos): ?>
                                        <a
                                            class="cengi-btn cengi-btn-ghost cengi-btn-sm vt-detail"
                                            href="../controllers/formulario_revision_controller.php?id_rango=<?= (int) $linea['id_rango'] ?>"
                                            title="Ver los datos capturados">
                                            <span class="material-symbols-outlined">visibility</span>
                                            Detalle
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($esRevision && $puedeResolver): ?>
                                        <button type="button" class="cengi-btn cengi-btn-primary cengi-btn-sm vt-validate js-vt-accion" data-accion="aprobar">
                                            <span class="material-symbols-outlined">check_circle</span>
                                            Validar
                                        </button>
                                        <button type="button" class="cengi-btn cengi-btn-sm vt-reject js-vt-accion" data-accion="rechazar">
                                            <span class="material-symbols-outlined">cancel</span>
                                            Rechazar
                                        </button>
                                    <?php elseif (!$esRevision): ?>
                                        <span class="muted">Resuelto</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!$puedeResolver): ?>
    <p class="muted" style="margin-top:14px;font-size:11.5px;color:var(--cengi-muted);">
        Tu rol solo tiene permiso de consulta sobre esta matriz (<code>laboratorio.validacion_tecnica.ver</code>);
        no puedes aprobar ni rechazar analisis.
    </p>
<?php endif; ?>

<div id="vtToast" style="position:fixed;bottom:24px;right:24px;background:#20242A;color:#fff;padding:12px 18px;border-radius:10px;font-size:12.5px;font-weight:600;box-shadow:0 6px 20px rgba(20,24,20,.2);z-index:200;display:flex;align-items:center;gap:9px;transform:translateY(20px);opacity:0;transition:all .25s ease;"></div>
<style>#vtToast.show{transform:translateY(0);opacity:1;}</style>

<?php if ($puedeResolver): ?>
<script>
(function () {
    var toast = document.getElementById('vtToast');
    function showToast(msg, isError) {
        if (!toast) return;
        toast.textContent = msg;
        toast.style.borderLeft = '4px solid ' + (isError ? '#c94f4f' : '#73BC25');
        toast.classList.add('show');
        window.clearTimeout(toast._timer);
        toast._timer = window.setTimeout(function () { toast.classList.remove('show'); }, 2600);
    }

    document.querySelectorAll('.js-vt-accion').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var linea = btn.closest('tr[data-id-formulario]');
            var idFormulario = linea ? linea.dataset.idFormulario : null;
            var accion = btn.dataset.accion;
            if (!idFormulario || !accion) return;

            var comentario = '';
            if (accion === 'rechazar') {
                comentario = window.prompt('Motivo del rechazo (opcional):', '') || '';
            }

            var acciones = btn.closest('.vt-line-actions');
            acciones.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

            fetch('../controllers/validacion_tecnica_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_formulario: idFormulario, accion: accion, comentario: comentario }),
            })
                .then(function (res) { return res.json(); })
                .then(function (result) {
                    if (!result || !result.ok) {
                        throw new Error((result && result.error) || 'Error al guardar');
                    }
                    showToast(accion === 'aprobar' ? 'Analisis aprobado.' : 'Analisis rechazado.');
                    window.setTimeout(function () { window.location.reload(); }, 700);
                })
                .catch(function () {
                    showToast('No se pudo guardar la decision, intenta de nuevo.', true);
                    acciones.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                });
        });
    });
})();
</script>
<?php endif; ?>

<?php lab_shell_close(); ?>
