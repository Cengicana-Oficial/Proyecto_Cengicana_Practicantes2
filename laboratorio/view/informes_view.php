<?php
/**
 * Informes — formato de Prototipos/laboratorio-workflow-v3.html: un informe por lote; el jefe
 * de laboratorio lo revisa, lo aprueba y lo firma aquí mismo (antes era la sección "Firma
 * electrónica") o lo devuelve al técnico. El PDF firmado queda en Reportes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../includes/schema_safe_helper.php';
require_once __DIR__ . '/../models/informes_model.php';

lab_require_permission('laboratorio.informes.ver');

$pdo = Conexion::conectar();
lab_ensure_schema_safe(fn() => wfSchema($pdo), 'workflow_trabajo_v3');
lab_ensure_schema_safe(fn() => lab_informes_esquema($pdo), 'informes_v3');
$_SESSION['informes_csrf'] ??= bin2hex(random_bytes(32));
$puedeFirmar = lab_can('laboratorio.firma.gestionar');
$usuario = lab_current_user();

$idLote = (int) ($_GET['lote'] ?? 0);
$detalle = $idLote > 0 ? lab_informe_detalle($pdo, $idLote) : null;
$lotes = $detalle ? [] : lab_informes_lotes($pdo);
$estructuraInforme = $detalle ? lab_informe_estructura($detalle) : null;
$formatoInforme = $estructuraInforme['formato'] ?? null;

$cuenta = ['PREP' => 0, 'LISTO' => 0, 'DEVUELTO' => 0, 'FIRMADO' => 0];
foreach ($lotes as $l) {
    $cuenta[$l['estado']]++;
}

function inf_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function inf_fecha($f, bool $hora = false): string
{
    return $f && strtotime((string) $f) ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', strtotime((string) $f)) : '—';
}
$chipEstado = ['PREP' => '', 'LISTO' => 'is-review', 'DEVUELTO' => 'is-rea', 'FIRMADO' => 'is-approved'];

lab_shell_head('Informes', $detalle ? 'Informe del lote ' . $detalle['lote']['codigo_lote'] : 'Un informe por lote · aprobación y firma del jefe de laboratorio', [
    '../css/lab_shell.css?v=20261006',
    '../styles/validacion_tecnica.css?v=' . filemtime(__DIR__ . '/../styles/validacion_tecnica.css'),
    '../styles/informes_v3.css?v=' . filemtime(__DIR__ . '/../styles/informes_v3.css'),
    '../css/lab_pager.css?v=' . filemtime(__DIR__ . '/../css/lab_pager.css'),
]);
lab_shell_open('informes_view.php');
?>
<section class="vt-wrap inf-page">
<?php if (!$detalle): ?>
    <?php if ($idLote > 0): ?><div class="cengi-empty">El lote solicitado no tiene análisis registrados.</div><?php endif; ?>
    <div class="cengi-kpi-grid inf-kpis">
        <article class="cengi-kpi"><div class="cengi-kpi-val" style="color:#636A6F"><?= $cuenta['LISTO'] ?></div><div class="cengi-kpi-label">Listos para aprobación</div></article>
        <article class="cengi-kpi"><div class="cengi-kpi-val"><?= $cuenta['PREP'] ?></div><div class="cengi-kpi-label">En preparación</div></article>
        <article class="cengi-kpi"><div class="cengi-kpi-val" style="color:#B34B00"><?= $cuenta['DEVUELTO'] ?></div><div class="cengi-kpi-label">Devueltos al técnico</div></article>
        <article class="cengi-kpi"><div class="cengi-kpi-val" style="color:var(--cengi-primary-deep)"><?= $cuenta['FIRMADO'] ?></div><div class="cengi-kpi-label">Aprobados y firmados</div></article>
    </div>
    <p class="vt-intro">Un informe por lote. Cuando el técnico valida todos los análisis del lote, su informe queda <b>listo para aprobación</b>:
        el jefe lo revisa en el formato del laboratorio y lo <b>aprueba y firma</b>. El PDF firmado se guarda en <b>Reportes</b>.</p>
    <div class="vt-toolbar">
        <div class="inf-chips" role="tablist" aria-label="Filtrar informes">
            <?php foreach (['LISTO' => 'Por aprobar', 'PREP' => 'En preparación', 'DEVUELTO' => 'Devueltos', 'FIRMADO' => 'Firmados', 'TODOS' => 'Todos'] as $f => $txt): ?>
                <button type="button" data-filtro="<?= $f ?>" class="<?= $f === 'LISTO' ? 'on' : '' ?>"><?= $txt ?> <span><?= $f === 'TODOS' ? count($lotes) : $cuenta[$f] ?></span></button>
            <?php endforeach; ?>
        </div>
        <input type="search" id="inf-buscar" class="inf-buscar" placeholder="Buscar informe, lote o cliente…" aria-label="Buscar informe">
    </div>
    <div class="cengi-table-wrap"><table class="vt-table inf-table">
        <thead><tr><th>Informe</th><th>Lote</th><th>Cliente</th><th>Validación</th><th>Estado</th><th></th></tr></thead>
        <tbody id="inf-lista">
        <?php foreach ($lotes as $l): $inf = $l['informe']; ?>
            <tr data-estado="<?= $l['estado'] ?>" data-buscar="<?= inf_e(mb_strtolower(implode(' ', [$l['codigo_lote'], $l['cliente'], $inf['codigo'] ?? '']))) ?>">
                <td class="vt-mono"><b><?= inf_e($inf['codigo'] ?? '—') ?></b><?php if ($inf && (int) $inf['version'] > 1): ?> <span class="vt-chip">v<?= (int) $inf['version'] ?></span><?php endif; ?></td>
                <td><b class="vt-mono"><?= inf_e($l['codigo_lote']) ?></b><div class="small muted"><?= inf_e($l['tipo_muestra']) ?> · <span class="vt-chip pr-<?= inf_e($l['prioridad']) ?>"><?= inf_e(ucfirst($l['prioridad'])) ?></span></div></td>
                <td><?= inf_e($l['cliente']) ?><div class="small muted"><?= (int) $l['numero_muestras'] ?> muestra(s) · <?= (int) $l['total'] ?> análisis</div></td>
                <td style="min-width:170px"><div class="inf-bar"><span style="width:<?= (int) $l['porcentaje'] ?>%"></span></div><div class="small muted"><?= (int) $l['validados'] ?>/<?= (int) $l['total'] ?> validados (<?= (int) $l['porcentaje'] ?>%)</div></td>
                <td><span class="vt-chip <?= $chipEstado[$l['estado']] ?>"><?= inf_e($l['estado_texto']) ?></span>
                    <?php if ($l['estado'] === 'FIRMADO'): ?><div class="small muted"><?= inf_e($inf['firmado_por']) ?> · <?= inf_e(inf_fecha($inf['fecha_firma'])) ?></div><?php endif; ?></td>
                <td style="text-align:right;white-space:nowrap">
                    <a class="cengi-btn cengi-btn-sm <?= $l['estado'] === 'LISTO' && $puedeFirmar ? 'cengi-btn-primary' : 'cengi-btn-ghost' ?>" href="?lote=<?= (int) $l['id_lote'] ?>">
                        <?= $l['estado'] === 'LISTO' ? ($puedeFirmar ? 'Revisar y firmar' : 'Revisar') : ($l['estado'] === 'FIRMADO' ? 'Ver informe' : 'Ver borrador') ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr id="inf-vacio" hidden><td colspan="6" class="small muted" style="text-align:center;padding:18px">Sin informes con este filtro.</td></tr>
        </tbody>
    </table></div>
    <nav class="lab-pager" id="inf-paginas" aria-label="Páginas de informes" hidden>
        <span data-pager-rango></span>
        <div class="lab-pager-nav">
            <button type="button" class="lab-pager-btn" data-pager-prev aria-label="Página anterior"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></button>
            <b data-pager-info aria-live="polite"></b>
            <button type="button" class="lab-pager-btn" data-pager-next aria-label="Página siguiente"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
        </div>
    </nav>
<?php else: $l = $detalle['lote']; $inf = $l['informe']; ?>
    <a class="vt-backlink" href="informes_view.php"><span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span>Volver a informes</a>
    <div class="inf-layout">
        <div class="ws-card">
            <div class="ws-card-head"><div>
                <div class="ws-sec-title"><?= inf_e($inf['codigo'] ?? 'Borrador') ?> <span class="vt-chip <?= $chipEstado[$l['estado']] ?>"><?= inf_e($l['estado_texto']) ?></span></div>
                <div class="small muted">Lote <b class="vt-mono"><?= inf_e($l['codigo_lote']) ?></b> · <?= inf_e($l['cliente']) ?> · <?= inf_e($l['tipo_muestra']) ?> · <?= (int) $l['validados'] ?>/<?= (int) $l['total'] ?> análisis validados · prioridad <?= inf_e($l['prioridad']) ?></div>
            </div>
            <div class="ws-chips">
                <span class="vt-chip"><?= inf_e($formatoInforme['codigo'] ?? '') ?> · <?= inf_e($formatoInforme['titulo'] ?? '') ?></span>
                <button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" id="inf-excel">Descargar Excel (.xlsx)</button>
                <?php if ($l['estado'] === 'FIRMADO'): ?><a class="cengi-btn cengi-btn-ghost cengi-btn-sm" href="../controllers/documento_pdf.php?id_documento=<?= (int) $inf['id_documento'] ?>&download=1">Descargar PDF</a><?php endif; ?>
            </div></div>
            <div class="ws-card-body"><div class="inf-marco"><iframe id="inf-reporte" title="Informe de resultados con el formato del laboratorio"></iframe></div>
                <p class="small muted" id="inf-cargando">Calculando resultados validados…</p></div>
            <form id="inf-excel-form" method="post" action="../controllers/informe_excel.php" hidden>
                <input type="hidden" name="id_lote" value="<?= (int) $l['id_lote'] ?>"><input type="hidden" name="csrf" value="<?= inf_e($_SESSION['informes_csrf']) ?>">
                <input type="hidden" name="resultados"><input type="hidden" name="observaciones">
            </form>
        </div>
        <aside class="ws-card inf-panel"><div class="ws-card-body">
            <h3>Aprobación del jefe de laboratorio</h3>
            <?php if ($l['estado'] === 'FIRMADO'): ?>
                <div class="inf-ok"><b>✓ Informe aprobado y firmado</b><span><?= inf_e($inf['firmado_por']) ?> · <?= inf_e(inf_fecha($inf['fecha_firma'], true)) ?></span></div>
                <div class="vt-actions">
                    <a class="cengi-btn cengi-btn-primary cengi-btn-sm" href="../controllers/documento_pdf.php?id_documento=<?= (int) $inf['id_documento'] ?>" target="_blank" rel="noopener">Ver PDF firmado</a>
                    <a class="cengi-btn cengi-btn-ghost cengi-btn-sm" href="../controllers/documento_pdf.php?id_documento=<?= (int) $inf['id_documento'] ?>&download=1">Descargar PDF</a>
                    <a class="cengi-btn cengi-btn-ghost cengi-btn-sm" href="documentos_view.php">Ver en Reportes</a>
                </div>
            <?php elseif ($l['estado'] === 'LISTO' && $puedeFirmar): ?>
                <div class="inf-paso"><b>1. Revise el informe</b><span>Todos los análisis del lote fueron validados por el técnico. Revise los valores en el formato del laboratorio.</span></div>
                <div class="vt-field"><label for="inf-obs">2. Observaciones del informe</label><textarea id="inf-obs" maxlength="2000" placeholder="Aparecen en el informe (opcional)"><?= inf_e($inf['observaciones'] ?? '') ?></textarea></div>
                <div class="vt-field"><label>3. Firma del jefe de laboratorio</label>
                    <div class="inf-pad"><canvas id="inf-firma" width="520" height="170" aria-label="Firma del jefe de laboratorio"></canvas><span>Firme aquí</span></div>
                    <div class="inf-pad-foot"><button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" id="inf-limpiar">Limpiar</button><span class="small muted"><?= inf_e($usuario['nombre'] ?? '') ?></span></div>
                </div>
                <button type="button" class="cengi-btn cengi-btn-primary inf-firmar" id="inf-firmar">Aprobar y firmar informe</button>
                <details class="inf-dev"><summary>Devolver al técnico revisor</summary>
                    <textarea id="inf-motivo" maxlength="1000" placeholder="Motivo (obligatorio): ej. revisar el control de fósforo."></textarea>
                    <button type="button" class="cengi-btn vt-btn-red cengi-btn-sm" id="inf-devolver">Devolver con este motivo</button>
                    <p class="small muted">Los formularios del lote vuelven a Validación técnica con este motivo.</p>
                </details>
            <?php elseif ($l['estado'] === 'LISTO'): ?>
                <div class="inf-paso"><b>Listo para aprobación</b><span>Solo el jefe de laboratorio puede aprobar y firmar este informe.</span></div>
            <?php else: ?>
                <div class="inf-paso"><b><?= $l['estado'] === 'DEVUELTO' ? 'Devuelto al técnico' : 'En preparación' ?></b><span>El informe pasa a aprobación cuando todos los análisis del lote estén validados.</span></div>
                <?php $faltan = array_sum($l['pendientes']); ?>
                <div class="inf-faltan"><b>Faltan <?= $faltan ?> análisis por validar</b>
                    <?php foreach ($l['pendientes'] as $an => $n): ?><div><span><?= inf_e($an) ?></span><b><?= (int) $n ?></b></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p id="inf-estado" class="small" role="status"></p>
            <?php if ($detalle['historial']): ?>
                <details class="inf-hist"><summary>Historial del informe (<?= count($detalle['historial']) ?>)</summary><ol>
                    <?php foreach (array_reverse($detalle['historial']) as $h): ?>
                        <li><?= inf_e(inf_fecha($h['fecha'], true)) ?> · <?= inf_e($h['usuario']) ?> · <b><?= inf_e($h['accion']) ?></b><?= $h['detalle'] ? ' — ' . inf_e($h['detalle']) : '' ?></li>
                    <?php endforeach; ?>
                </ol></details>
            <?php endif; ?>
        </div></aside>
    </div>
    <script type="application/json" id="inf-data"><?= json_encode([
        'csrf' => $_SESSION['informes_csrf'], 'endpoint' => '../controllers/informes_controller.php',
        'detalle' => $detalle, 'preview' => $l['estado'] !== 'FIRMADO',
        // Columnas del formato (solo análisis solicitados en Recepción) y celda de cada muestra.
        'columnas' => array_map(static fn($c) => ['key' => $c['key'], 'an' => $c['an'], 'k' => $c['k'] ?? null, 'd' => $c['d'] ?? 2], $estructuraInforme['columnas']),
        'celdas' => $estructuraInforme['celdas'],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?></script>
    <script src="../js/hojas_definiciones.js?v=<?= filemtime(__DIR__ . '/../js/hojas_definiciones.js') ?>"></script>
    <script src="../js/validacion_tecnica.js?v=<?= filemtime(__DIR__ . '/../js/validacion_tecnica.js') ?>"></script>
<?php endif; ?>
</section>
<script src="../js/lab_pager.js?v=<?= filemtime(__DIR__ . '/../js/lab_pager.js') ?>"></script>
<script src="../js/informes.js?v=<?= filemtime(__DIR__ . '/../js/informes.js') ?>"></script>
<?php lab_shell_close(); ?>
