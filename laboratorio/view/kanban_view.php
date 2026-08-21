<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/kanban_model.php';

lab_require_permission('laboratorio.kanban.ver');

$puedeGestionar = lab_can('laboratorio.kanban.gestionar');
$pdo = Conexion::conectar();
$tareas = lab_planificacion_tareas($pdo);
$colas = lab_planificacion_colas($tareas);
$resumen = lab_planificacion_resumen_muestras($tareas);

function plan_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function plan_norm($value): string
{
    $value = trim((string) $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function plan_svg(string $name): string
{
    $paths = [
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
        'check' => '<path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'sample' => '<path d="M8 3h8M9 3v13a3 3 0 0 0 6 0V3M9 9h6"/>',
        'progress' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v9l6 3"/>',
        'done' => '<path d="m5 12 4 4L19 6"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.5"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
    ];

    return '<svg class="plan-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['info']) . '</svg>';
}

function plan_cube_icon(string $tipo): string
{
    $normalizado = plan_norm($tipo);
    $color = '#7B838A';
    $path = '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>';

    if (strpos($normalizado, 'suelo') !== false) {
        $color = '#8B5E3C';
        $path = '<path d="M2 20h20M4 20V10l4-3 4 3 4-3 4 3v10"/>';
    } elseif (strpos($normalizado, 'foliar') !== false) {
        $color = '#3E8E4F';
        $path = '<path d="M11 20A7 7 0 0 1 4 13V7a1 1 0 0 1 1-1h1a7 7 0 0 1 7 7v7z"/><path d="M4 12h4M14 20a7 7 0 0 0 7-7V8"/>';
    } elseif (strpos($normalizado, 'agua') !== false) {
        $color = '#3A79C4';
        $path = '<path d="M12 2s7 8 7 13a7 7 0 0 1-14 0c0-5 7-13 7-13z"/>';
    } elseif (strpos($normalizado, 'caña') !== false || strpos($normalizado, 'cana') !== false) {
        $color = '#B08900';
        $path = '<path d="M12 22V6M12 6l5-4M12 10 7 6M12 14l5-4M12 18l-5-4"/>';
    } elseif (strpos($normalizado, 'fertiliz') !== false) {
        $color = '#6D48C8';
        $path = '<rect x="4" y="7" width="16" height="14" rx="2"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>';
    }

    return '<span class="plan-cube-icon" style="background:' . $color . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg></span>';
}

$tipos = [];
$clientes = [];
$analistas = [];
$colasPorTipo = [];
foreach ($colas as $cola) {
    $tipo = (string) $cola['tipo_muestra'];
    $tipos[$tipo] = $tipo;
    $colasPorTipo[$tipo][] = $cola;
    foreach ($cola['clientes'] as $cliente) {
        $clientes[$cliente] = $cliente;
    }
    foreach ($cola['analistas'] as $analista) {
        $analistas[$analista] = $analista;
    }
}
ksort($tipos, SORT_NATURAL | SORT_FLAG_CASE);
ksort($clientes, SORT_NATURAL | SORT_FLAG_CASE);
ksort($analistas, SORT_NATURAL | SORT_FLAG_CASE);

$matriz = [];
foreach ($tareas as $tarea) {
    $tipo = (string) $tarea['tipo_muestra'];
    $idMuestra = (int) $tarea['id_muestra'];
    $idAnalisis = (int) $tarea['id_tipo_analisis'];
    $matriz[$tipo]['analisis'][$idAnalisis] = (string) $tarea['analisis_nombre'];
    if (!isset($matriz[$tipo]['muestras'][$idMuestra])) {
        $matriz[$tipo]['muestras'][$idMuestra] = [
            'codigo' => (string) $tarea['codigo_muestra'],
            'lote' => (string) $tarea['codigo_lote'],
            'cliente' => (string) $tarea['cliente'],
            'tareas' => [],
        ];
    }
    $matriz[$tipo]['muestras'][$idMuestra]['tareas'][$idAnalisis] = $tarea;
}
foreach ($matriz as &$grupo) {
    asort($grupo['analisis'], SORT_NATURAL | SORT_FLAG_CASE);
}
unset($grupo);

$kanbanColumnas = [
    'pendientes' => ['label' => 'Pendientes', 'estado' => 'Pendiente'],
    'proceso' => ['label' => 'En proceso', 'estado' => 'En proceso'],
    'validacion' => ['label' => 'En validación', 'estado' => 'Revisar'],
    'calidad' => ['label' => 'Control de calidad', 'estado' => 'Aprobado'],
    'finalizadas' => ['label' => 'Finalizadas', 'estado' => 'Aprobado'],
];
$colasKanban = array_fill_keys(array_keys($kanbanColumnas), []);
foreach ($colas as $cola) {
    $columna = isset($colasKanban[$cola['kanban']]) ? $cola['kanban'] : 'pendientes';
    $colasKanban[$columna][] = $cola;
}

lab_shell_head('Planificación del trabajo', 'Colas, matriz de seguimiento y Kanban por análisis', [
    '../css/lab_shell.css?v=1',
    '../styles/planificacion_trabajo.css?v=' . (int) filemtime(__DIR__ . '/../styles/planificacion_trabajo.css'),
]);
lab_shell_open('kanban_view.php');
?>
<main class="plan-page" data-plan-page data-endpoint="../controllers/kanban_controller.php" data-can-manage="<?= $puedeGestionar ? '1' : '0' ?>">
    <div class="plan-alert plan-alert--info"><?= plan_svg('info') ?><div>Cada análisis solicitado se convierte automáticamente en una tarea. El trabajo se organiza por <strong>análisis</strong>, no por lote, y cualquier analista disponible puede tomarlo desde la Bandeja. La <strong>Matriz de seguimiento</strong> muestra el avance de lo solicitado por muestra y lote.</div></div>

    <section class="plan-kpis" aria-label="Resumen de planificación">
        <article><span><?= plan_svg('sample') ?></span><div><small>Muestras pendientes</small><strong><?= (int) $resumen['pendientes'] ?></strong></div></article>
        <article><span><?= plan_svg('progress') ?></span><div><small>Muestras en proceso</small><strong class="is-process"><?= (int) $resumen['proceso'] ?></strong></div></article>
        <article><span><?= plan_svg('done') ?></span><div><small>Muestras finalizadas</small><strong class="is-done"><?= (int) $resumen['finalizadas'] ?></strong></div></article>
    </section>

    <nav class="plan-tabs" role="tablist" aria-label="Vistas de planificación">
        <button type="button" data-plan-tab="queue" aria-selected="false">Cola por análisis</button>
        <button type="button" class="is-active" data-plan-tab="matrix" aria-selected="true">Matriz de seguimiento</button>
        <button type="button" data-plan-tab="kanban" aria-selected="false">Kanban</button>
    </nav>

    <section class="plan-panel" data-plan-panel="queue" hidden>
        <div class="plan-toolbar">
            <div class="plan-filters">
                <select data-queue-filter="type"><option value="">Todo tipo de muestra</option><?php foreach ($tipos as $tipo): ?><option value="<?= plan_e(plan_norm($tipo)) ?>"><?= plan_e($tipo) ?></option><?php endforeach; ?></select>
                <select data-queue-filter="state"><option value="">Todos los estados</option><option value="pendiente">RECIBIDA</option><option value="proceso">EN PROCESO</option><option value="validacion">EN REVISIÓN</option><option value="validado">APROBADA</option></select>
                <select data-queue-filter="priority"><option value="">Toda prioridad</option><option value="alta">Alta</option><option value="media">Media</option><option value="baja">Baja</option></select>
                <select data-queue-filter="analyst"><option value="">Todo analista</option><?php foreach ($analistas as $analista): ?><option value="<?= plan_e(plan_norm($analista)) ?>"><?= plan_e($analista) ?></option><?php endforeach; ?></select>
                <select data-queue-filter="client"><option value="">Todo cliente / ingenio</option><?php foreach ($clientes as $cliente): ?><option value="<?= plan_e(plan_norm($cliente)) ?>"><?= plan_e($cliente) ?></option><?php endforeach; ?></select>
            </div>
            <label class="plan-search"><?= plan_svg('search') ?><input type="search" placeholder="Buscar análisis…" data-queue-filter="search"></label>
        </div>

        <div class="plan-queue-grid" data-queue-grid>
            <?php foreach ($colasPorTipo as $tipo => $colasTipo): ?>
                <?php $muestrasTipo = array_sum(array_map(static fn($cola) => count($cola['items']), $colasTipo)); ?>
                <section class="plan-cube" data-queue-cube>
                    <header><div><?= plan_cube_icon($tipo) ?><div><h2><?= plan_e($tipo) ?></h2><p><?= $muestrasTipo ?> tareas · <?= count($colasTipo) ?> análisis</p></div></div></header>
                    <div class="plan-analysis-grid">
                        <?php foreach ($colasTipo as $cola): ?>
                            <?php
                                $approved = (int) ($cola['estado_counts']['validado'] ?? 0);
                                $analystSearch = plan_norm(implode(' ', $cola['analistas']));
                                $clientSearch = plan_norm(implode(' ', $cola['clientes']));
                            ?>
                            <article class="plan-analysis-card"
                                data-queue-card
                                data-type="<?= plan_e(plan_norm($cola['tipo_muestra'])) ?>"
                                data-state="<?= plan_e($cola['estado']['key']) ?>"
                                data-priority="<?= plan_e($cola['prioridad']['key']) ?>"
                                data-analyst="<?= plan_e($analystSearch) ?>"
                                data-client="<?= plan_e($clientSearch) ?>"
                                data-analysis="<?= plan_e(plan_norm($cola['analisis_nombre'])) ?>">
                                <div class="plan-card-title"><h3><?= plan_e($cola['analisis_nombre']) ?></h3><span class="plan-chip is-state-<?= plan_e($cola['estado']['key']) ?>"><?= plan_e($cola['estado']['label']) ?></span></div>
                                <p><?= count($cola['items']) ?> muestra<?= count($cola['items']) === 1 ? '' : 's' ?> · <?= count($cola['lotes']) ?> lote<?= count($cola['lotes']) === 1 ? '' : 's' ?> distinto<?= count($cola['lotes']) === 1 ? '' : 's' ?></p>
                                <div class="plan-card-chips"><span><?= $approved ?>/<?= count($cola['items']) ?> aprobados</span><span class="is-priority-<?= plan_e($cola['prioridad']['key']) ?>">Prioridad <?= plan_e($cola['prioridad']['label']) ?></span><span>Vence <?= plan_e($cola['fecha_estimada'] ?: 'sin fecha') ?></span></div>
                                <small>Disponible para: <?= $cola['analistas'] ? plan_e(implode(', ', $cola['analistas'])) : 'cualquier analista disponible' ?></small>
                                <button type="button" class="plan-view-button" data-queue-modal-open aria-haspopup="dialog"><?= plan_svg('eye') ?> Ver muestras (con lote de origen)</button>
                                <div class="plan-queue-detail" data-queue-detail hidden>
                                    <table><thead><tr><th>Muestra</th><th>Lote</th><th>Cliente</th><th>Estado</th></tr></thead><tbody>
                                    <?php foreach ($cola['items'] as $item): ?><tr><td><?= plan_e($item['codigo_muestra']) ?></td><td><?= plan_e($item['codigo_lote']) ?></td><td><?= plan_e($item['cliente']) ?></td><td><?= plan_e($item['estado']['label']) ?></td></tr><?php endforeach; ?>
                                    </tbody></table>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <div class="plan-empty" data-queue-empty hidden>No hay análisis con estos filtros.</div>
        </div>
    </section>

    <section class="plan-panel is-active" data-plan-panel="matrix">
        <div class="plan-alert plan-alert--ok"><?= plan_svg('check') ?><div>Matriz de seguimiento: cada fila es una muestra y cada columna un análisis solicitado. El color y el ícono indican su estado. Es útil para auditar o revisar el avance de un tipo de muestra de un vistazo.</div></div>
        <div class="plan-toolbar plan-toolbar--matrix">
            <div class="plan-filters">
                <select data-matrix-filter="state"><option value="">Todos los estados</option><option value="pendiente">Pendiente</option><option value="proceso">En proceso</option><option value="validacion">Finalizado (en validación)</option><option value="validado">Validado</option><option value="repetido">Repetido</option><option value="rechazado">Rechazado</option></select>
                <select data-matrix-filter="client"><option value="">Todo cliente / ingenio</option><?php foreach ($clientes as $cliente): ?><option value="<?= plan_e(plan_norm($cliente)) ?>"><?= plan_e($cliente) ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="plan-legend"><span><i class="mx-pendiente">○</i>Pendiente</span><span><i class="mx-proceso">◐</i>En proceso</span><span><i class="mx-validacion">●</i>Finalizado (en validación)</span><span><i class="mx-validado">✓</i>Validado</span><span><i class="mx-repetido">↻</i>Repetido</span><span><i class="mx-rechazado">✕</i>Rechazado</span></div>

        <div class="plan-matrix-grid" data-matrix-grid>
            <?php foreach ($matriz as $tipo => $grupo): ?>
                <section class="plan-cube plan-matrix-cube" data-matrix-cube>
                    <header><div><?= plan_cube_icon($tipo) ?><div><h2><?= plan_e($tipo) ?></h2><p><?= count($grupo['muestras']) ?> muestras registradas · <?= count($grupo['analisis']) ?> análisis solicitados</p></div></div></header>
                    <div class="plan-table-wrap"><table><thead><tr><th>Código</th><th>Cliente / Lote</th><?php foreach ($grupo['analisis'] as $nombre): ?><th><?= plan_e($nombre) ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php foreach ($grupo['muestras'] as $muestra): ?>
                        <?php $stateKeys = array_map(static fn($task) => (string) ($task['estado']['key'] ?? 'pendiente'), $muestra['tareas']); ?>
                        <tr data-matrix-row data-client="<?= plan_e(plan_norm($muestra['cliente'])) ?>" data-states="<?= plan_e(implode(' ', array_unique($stateKeys))) ?>">
                            <td><strong><?= plan_e($muestra['codigo']) ?></strong><small><?= plan_e($muestra['lote']) ?></small></td>
                            <td><?= plan_e($muestra['cliente']) ?><small><?= plan_e($muestra['lote']) ?></small></td>
                            <?php foreach ($grupo['analisis'] as $idAnalisis => $nombre): ?>
                                <?php $task = $muestra['tareas'][$idAnalisis] ?? null; ?>
                                <?php if (!$task): ?><td class="is-not-requested">n/s</td><?php else: ?>
                                    <td class="plan-matrix-state" title="<?= plan_e($nombre . ': ' . $task['estado']['label']) ?>"><i class="mx-<?= plan_e($task['estado']['key']) ?>"><?= plan_e($task['estado']['icon']) ?></i><span><?= plan_e($task['estado']['label']) ?></span></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p class="plan-ns">n/s = no solicitado para esa muestra</p>
                </section>
            <?php endforeach; ?>
            <div class="plan-empty" data-matrix-empty hidden>Sin muestras que coincidan con los filtros.</div>
        </div>
    </section>

    <section class="plan-panel" data-plan-panel="kanban" hidden>
        <div class="plan-alert plan-alert--info"><?= plan_svg('info') ?><div>Cada tarjeta representa una cola de trabajo por análisis y puede incluir muestras de varios lotes. Las colas sin formulario aún se muestran como pendientes; el movimiento solo se habilita cuando el análisis ya tiene un formulario persistente.</div></div>
        <div class="plan-kanban" data-kanban-board>
            <?php foreach ($kanbanColumnas as $key => $columna): ?>
                <section class="plan-kanban-col" data-kanban-col data-estado="<?= plan_e($columna['estado']) ?>">
                    <header><span><?= plan_e($columna['label']) ?></span><b data-kanban-count><?= count($colasKanban[$key]) ?></b></header>
                    <div class="plan-kanban-body">
                        <?php foreach ($colasKanban[$key] as $cola): ?>
                            <?php $draggable = $puedeGestionar && count($cola['form_ids']) > 0 && count($cola['form_ids']) === count($cola['lotes']); ?>
                            <article class="plan-kanban-card" draggable="<?= $draggable ? 'true' : 'false' ?>" data-form-ids='<?= plan_e(json_encode($cola['form_ids'])) ?>'>
                                <div><h3><?= plan_e($cola['analisis_nombre']) ?></h3><span class="plan-chip is-priority-<?= plan_e($cola['prioridad']['key']) ?>"><?= plan_e($cola['prioridad']['label']) ?></span></div>
                                <p><?= plan_e($cola['tipo_muestra']) ?> · <?= count($cola['items']) ?> muestras · <?= count($cola['lotes']) ?> lotes</p>
                                <?php if (!$draggable): ?><small><?= $puedeGestionar ? 'Se moverá cuando todos los lotes tengan formulario.' : 'Solo consulta.' ?></small><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$colasKanban[$key]): ?><p class="plan-kanban-empty">Sin tareas</p><?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php if (!$puedeGestionar): ?><p class="plan-permission-note">Tu rol tiene permiso de consulta; no puede mover tarjetas entre columnas.</p><?php endif; ?>
    </section>
</main>
<div class="plan-modal" data-queue-modal hidden>
    <section class="plan-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="queueModalTitle" tabindex="-1">
        <header class="plan-modal-header">
            <div>
                <h2 id="queueModalTitle" data-queue-modal-title>Detalle de muestras</h2>
                <p data-queue-modal-subtitle></p>
            </div>
            <button type="button" class="plan-modal-close" data-queue-modal-close aria-label="Cerrar modal"><?= plan_svg('close') ?></button>
        </header>
        <div class="plan-modal-body" data-queue-modal-body></div>
        <footer class="plan-modal-footer">
            <button type="button" data-queue-modal-close>Cerrar</button>
        </footer>
    </section>
</div>
<div class="plan-toast" data-plan-toast></div>
<script src="../js/planificacion_trabajo.js?v=<?= (int) filemtime(__DIR__ . '/../js/planificacion_trabajo.js') ?>"></script>
<?php lab_shell_close(); ?>
