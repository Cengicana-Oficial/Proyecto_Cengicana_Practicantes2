<?php
/**
 * dashboard.php
 *
 * Item "Dashboard" del sidebar (grupo "Flujo operativo"). KPIs y graficas
 * calculados con datos reales de la base de datos (formulario /
 * estado_formulario / historial_formulario / solicitud / muestra /
 * lab_evento_trazabilidad); nunca con los nombres/numeros de ejemplo del
 * mockup de referencia. Ver models/dashboard_kpis_model.php para el
 * detalle de cada consulta y las condiciones bajo las que un bloque se
 * omite (en vez de mostrarse con datos inventados) por falta de dato real
 * suficiente.
 *
 * El antiguo contenido de este archivo (buscador/exportador de
 * resultados por lote y tipo de reporte) se movio a view/informes_view.php,
 * que es el destino real del item de sidebar "Informes" y de los enlaces
 * histor cos "Informes"/"Historial" que ya traian view/labc_index.php y
 * view/menu_solicitud.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/dashboard_kpis_model.php';
require_once __DIR__ . '/../models/trazabilidad_model.php';

lab_require_module_access();

$pdo = Conexion::conectar();

$kpis = lab_dashboard_kpis($pdo);
$tiempoPorEtapa = lab_dashboard_tiempo_por_etapa($pdo);
$sla = lab_dashboard_cumplimiento_sla($pdo);
$productividad = lab_dashboard_productividad_analista($pdo);
$actividadReciente = lab_trazabilidad_actividad_reciente($pdo, 12);

function dash_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dash_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '-';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

$maxHoras = 0;
foreach ($tiempoPorEtapa as $etapa) {
    $maxHoras = max($maxHoras, $etapa['horas_promedio']);
}

$maxAnalisisAnalista = 0;
foreach ($productividad as $fila) {
    $maxAnalisisAnalista = max($maxAnalisisAnalista, (int) $fila['total']);
}

lab_shell_head('Dashboard', 'Panorama general del flujo del laboratorio, con datos en tiempo real', [
    '../css/lab_shell.css?v=1',
]);
lab_shell_open('dashboard.php');
?>

<div class="cengi-kpi-grid">
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">inbox</span></span>
        <div class="cengi-kpi-val"><?= (int) $kpis['muestras_hoy'] ?></div>
        <div class="cengi-kpi-label">Muestras recibidas hoy</div>
        <div class="cengi-kpi-trend is-flat">Suma de <code>solicitud.numero_muestras</code> de hoy</div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">science</span></span>
        <div class="cengi-kpi-val"><?= (int) $kpis['analisis_en_proceso'] ?></div>
        <div class="cengi-kpi-label">Analisis en proceso</div>
        <div class="cengi-kpi-trend is-flat">Estado "En proceso"</div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">fact_check</span></span>
        <div class="cengi-kpi-val"><?= (int) $kpis['en_revision_tecnica'] ?></div>
        <div class="cengi-kpi-label">En revision tecnica</div>
        <div class="cengi-kpi-trend is-flat">Pendientes de validacion</div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">report</span></span>
        <div class="cengi-kpi-val"><?= (int) $kpis['rechazados_mes'] ?></div>
        <div class="cengi-kpi-label">Rechazos este mes</div>
        <div class="cengi-kpi-trend is-flat">Transiciones a "Rechazado" del mes actual</div>
        <span class="cengi-kpi-bar"></span>
    </article>
</div>

<div class="cengi-dashboard-grid">
    <div class="cengi-card">
        <h3>Tiempo promedio por etapa</h3>
        <div class="cengi-card-sub">
            Horas promedio que un analisis permanece en cada estado antes de pasar al siguiente
            (calculado sobre las transiciones reales de <code>historial_formulario</code>).
        </div>

        <?php if (empty($tiempoPorEtapa)): ?>
            <div class="cengi-empty">
                Todavia no hay suficientes transiciones de estado consecutivas registradas para
                calcular un promedio. Este bloque se llenara automaticamente conforme el tablero de
                planificacion y la validacion tecnica registren mas movimientos.
            </div>
        <?php else: ?>
            <div class="cengi-barchart">
                <?php foreach ($tiempoPorEtapa as $etapa): ?>
                    <?php $pct = $maxHoras > 0 ? max(4, round(($etapa['horas_promedio'] / $maxHoras) * 100)) : 0; ?>
                    <div class="cengi-barchart-row">
                        <div class="cengi-barchart-label"><?= dash_e($etapa['estado']) ?></div>
                        <div class="cengi-barchart-track">
                            <div class="cengi-barchart-fill" style="width:<?= (int) $pct ?>%;"></div>
                        </div>
                        <div class="cengi-barchart-value"><?= dash_e($etapa['horas_promedio']) ?> h</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted" style="margin-top:12px;font-size:11px;color:var(--cengi-muted);">
                Basado en <?= array_sum(array_column($tiempoPorEtapa, 'transiciones')) ?> transiciones de estado registradas.
            </p>
        <?php endif; ?>
    </div>

    <div class="cengi-card">
        <h3>Cumplimiento de SLA</h3>
        <div class="cengi-card-sub">
            % de solicitudes con <code>fecha_estimada</code> ya vencida que llegaron a "Aprobado" en
            todos sus analisis en o antes de esa fecha.
        </div>

        <?php if ($sla === null): ?>
            <div class="cengi-empty">
                Todavia no hay ninguna solicitud con <code>fecha_estimada</code> vencida para poder
                juzgar cumplimiento. Este indicador se activara automaticamente cuando exista al menos
                un plazo comprometido que ya haya pasado.
            </div>
        <?php else: ?>
            <div class="cengi-donut-wrap">
                <div class="cengi-donut" style="background: conic-gradient(var(--cengi-primary) 0% <?= (float) $sla['porcentaje'] ?>%, #f0d9d9 <?= (float) $sla['porcentaje'] ?>% 100%);">
                    <div class="cengi-donut-hole">
                        <strong><?= dash_e($sla['porcentaje']) ?>%</strong>
                        <span>A tiempo</span>
                    </div>
                </div>
                <div class="cengi-donut-legend">
                    <div><span class="dot" style="background:var(--cengi-primary);"></span> A tiempo: <b><?= (int) $sla['cumplidas'] ?></b></div>
                    <div><span class="dot" style="background:#f0d9d9;"></span> Fuera de plazo: <b><?= (int) ($sla['total'] - $sla['cumplidas']) ?></b></div>
                    <div class="muted" style="color:var(--cengi-muted);margin-top:4px;"><?= (int) $sla['total'] ?> solicitudes con plazo vencido evaluadas</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="cengi-dashboard-grid">
    <div class="cengi-card">
        <h3>Productividad por analista</h3>
        <div class="cengi-card-sub">
            Analisis capturados por analista (campo <code>formulario.analista</code>, tal como lo
            escribe cada analista al guardar un formulario).
        </div>

        <?php if (empty($productividad)): ?>
            <div class="cengi-empty">
                El campo <code>formulario.analista</code> todavia no se ha completado en ningun
                formulario, asi que no hay datos reales de productividad por persona todavia.
            </div>
        <?php else: ?>
            <div class="cengi-barchart">
                <?php foreach ($productividad as $fila): ?>
                    <?php $pct = $maxAnalisisAnalista > 0 ? max(4, round(((int) $fila['total'] / $maxAnalisisAnalista) * 100)) : 0; ?>
                    <div class="cengi-barchart-row">
                        <div class="cengi-barchart-label"><?= dash_e($fila['analista']) ?></div>
                        <div class="cengi-barchart-track">
                            <div class="cengi-barchart-fill" style="width:<?= (int) $pct ?>%;"></div>
                        </div>
                        <div class="cengi-barchart-value"><?= (int) $fila['total'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cengi-card">
        <h3>Actividad reciente en el flujo</h3>
        <div class="cengi-card-sub">
            Ultimos eventos registrados (tablero de planificacion, validacion tecnica y trazabilidad
            general por lote).
        </div>

        <?php if (empty($actividadReciente)): ?>
            <div class="cengi-empty">Todavia no hay actividad registrada en el flujo.</div>
        <?php else: ?>
            <div class="cengi-timeline">
                <?php foreach ($actividadReciente as $evento): ?>
                    <div class="cengi-timeline-item <?= !empty($evento['alerta']) ? 'is-warn' : 'is-ok' ?>">
                        <div class="cengi-timeline-dot">
                            <span class="material-symbols-outlined" style="font-size:12px;">
                                <?= !empty($evento['alerta']) ? 'priority_high' : 'check' ?>
                            </span>
                        </div>
                        <div class="cengi-timeline-head">
                            <b><?= dash_e($evento['titulo']) ?></b>
                            <span class="cengi-timeline-fecha"><?= dash_e(dash_fecha($evento['fecha'])) ?></span>
                        </div>
                        <?php if (!empty($evento['lote'])): ?>
                            <div class="cengi-timeline-detail">Lote <?= dash_e($evento['lote']) ?><?= !empty($evento['detalle']) ? ' · ' . dash_e($evento['detalle']) : '' ?></div>
                        <?php elseif (!empty($evento['detalle'])): ?>
                            <div class="cengi-timeline-detail"><?= dash_e($evento['detalle']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($evento['usuario'])): ?>
                            <div class="cengi-timeline-detail" style="color:var(--cengi-muted);">Por <?= dash_e($evento['usuario']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php lab_shell_close(); ?>
