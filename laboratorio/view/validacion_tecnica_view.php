<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/validacion_tecnica_model.php';

lab_require_permission('laboratorio.validacion_tecnica.ver');

$puedeResolver = lab_can('laboratorio.validacion_tecnica.aprobar');
$puedeVerErroneos = lab_can_view_error_forms();

$pdo = Conexion::conectar();

$idLoteFiltro = !empty($_GET['id_lote']) ? (int) $_GET['id_lote'] : null;
$tipoFiltro = trim((string) ($_GET['tipo'] ?? ''));

// Formato del prototipo: lista de formularios en revision + validados recientes.
$pendientesTodos = lab_validacion_lineas($pdo, 'revision');
$pendientes = array_values(array_filter($pendientesTodos, static function ($l) use ($idLoteFiltro, $tipoFiltro) {
    return (!$idLoteFiltro || (int) $l['id_lote'] === $idLoteFiltro)
        && ($tipoFiltro === '' || $l['tipo_muestra'] === $tipoFiltro);
}));
// Se piden más líneas validadas para que los 15 grupos (hojas) mostrados salgan completos.
$validados = lab_validacion_lineas($pdo, 'validado', null, null, 120);
$snapshots = lab_validacion_snapshots($pdo, array_merge(array_column($pendientesTodos, 'id_formulario'), array_column($validados, 'id_formulario')));

// Una hoja de trabajo con varios análisis genera un formulario por análisis: se validan juntos.
$gruposPendientes = lab_validacion_agrupar($pendientes, $snapshots);
$gruposValidados = array_slice(lab_validacion_agrupar($validados, $snapshots), 0, 15);
$totalGruposPendientes = count(lab_validacion_agrupar($pendientesTodos, $snapshots));

$lotesDisponibles = [];
$tiposDisponibles = [];
foreach ($pendientesTodos as $l) {
    $lotesDisponibles[(int) $l['id_lote']] = $l['codigo_lote'];
    $tiposDisponibles[$l['tipo_muestra']] = true;
}
asort($lotesDisponibles);
ksort($tiposDisponibles);

// Datos para calcular en el navegador los resultados fuera de rango (mismas formulas que el detalle).
$datosLista = ['endpoint' => '../controllers/validacion_tecnica_controller.php', 'motivos' => LAB_MOTIVOS_RECHAZO, 'formularios' => []];
foreach ($pendientesTodos as $l) {
    $idf = (int) $l['id_formulario'];
    if (isset($snapshots[$idf])) {
        $datosLista['formularios'][$idf] = [
            'cap' => $snapshots[$idf],
            'rango' => [
                'min' => $l['limite_min'] !== null ? (float) $l['limite_min'] : null,
                'max' => $l['limite_max'] !== null ? (float) $l['limite_max'] : null,
                'unidad' => $l['unidad'],
            ],
        ];
    }
}

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

function vt_rango(array $linea, ?array $snapshot): string
{
    $codigos = $snapshot ? array_values(array_filter(array_map(static function ($m) {
        return (string) ($m['codigo_muestra'] ?? '');
    }, $snapshot['muestras'] ?? []))) : [];
    $inicio = $codigos ? $codigos[0] : trim((string) ($linea['codigo_inicio'] ?? $linea['inicio'] ?? ''));
    $fin = $codigos ? end($codigos) : trim((string) ($linea['codigo_fin'] ?? $linea['fin'] ?? ''));
    if ($inicio === '' || $fin === '') {
        return '-';
    }
    return $inicio === $fin ? $inicio : $inicio . ' – ' . $fin;
}

/** Una fila por hoja enviada: agrupa los formularios (uno por análisis) del mismo envío. */
function vt_fila(array $grupo, array $snapshots, string $modo, bool $puedeResolver, bool $puedeVerErroneos): string
{
    $lineas = $grupo['lineas'];
    $ids = array_map('intval', $grupo['ids']);
    $primera = $lineas[0];
    $unicos = static function (string $campo, string $def) use ($lineas): array {
        return array_values(array_unique(array_map(static function ($l) use ($campo, $def) {
            return trim((string) ($l[$campo] ?? '')) !== '' ? (string) $l[$campo] : $def;
        }, $lineas)));
    };
    $analisis = $unicos('analisis_nombre', 'Analisis sin identificar');
    $lotes = $unicos('codigo_lote', '-');
    $tipos = $unicos('tipo_muestra', 'Sin tipo');
    $analistas = $unicos('analista', 'Sin analista');

    // Rango y número de muestras: unión de las muestras de todas las hojas del grupo.
    $muestras = [];
    $conHoja = true;
    foreach ($lineas as $l) {
        $snap = $snapshots[(int) $l['id_formulario']] ?? null;
        if (!$snap) {
            $conHoja = false;
            continue;
        }
        foreach ($snap['muestras'] ?? [] as $m) {
            $muestras[(int) ($m['id_muestra'] ?? 0)] = $m;
        }
    }
    if ($muestras) {
        uasort($muestras, static function ($a, $b) {
            return [(string) ($a['codigo_lote'] ?? ''), (int) ($a['numero_muestra'] ?? 0)] <=> [(string) ($b['codigo_lote'] ?? ''), (int) ($b['numero_muestra'] ?? 0)];
        });
        $codigos = array_values(array_filter(array_map(static function ($m) {
            return (string) ($m['codigo_muestra'] ?? '');
        }, $muestras)));
        $rango = $codigos ? ($codigos[0] === end($codigos) ? $codigos[0] : $codigos[0] . ' – ' . end($codigos)) : '-';
    } else {
        $rango = implode(', ', array_unique(array_map(static function ($l) {
            return vt_rango($l, null);
        }, $lineas)));
    }
    $nMuestras = $muestras ? count($muestras) : null;

    if ($conHoja) {
        $href = 'hoja_revision.php?ids=' . implode(',', $ids);
    } elseif (count($ids) === 1 && !empty($primera['id_rango']) && $puedeVerErroneos) {
        $href = '../controllers/formulario_revision_controller.php?id_rango=' . (int) $primera['id_rango'];
    } else {
        $href = null;
    }
    $multi = count($ids) > 1;
    $etiquetaForms = $multi ? count($ids) . ' formularios (#' . implode(', #', $ids) . ')' : 'Formulario #' . $ids[0];
    $ref = $etiquetaForms . ' · ' . implode(', ', $analisis) . ' · ' . implode(', ', $tipos) . ($nMuestras !== null ? ' · ' . $nMuestras . ' muestras' : '');
    $chip = $modo === 'revisor'
        ? '<span class="vt-chip is-review">Revisar</span>'
        : '<span class="vt-chip is-approved">Validado · en informe</span>';

    ob_start(); ?>
    <tr data-ids="<?= vt_e(implode(',', $ids)) ?>" data-ref="<?= vt_e($ref) ?>">
        <td class="vt-mono" title="<?= vt_e($etiquetaForms) ?>"><strong>#<?= $ids[0] ?></strong><?php if ($multi): ?><span class="small muted vt-sub">+<?= count($ids) - 1 ?> formulario<?= count($ids) > 2 ? 's' : '' ?></span><?php endif; ?></td>
        <td class="vt-mono"><strong><?= vt_e(implode(', ', $lotes)) ?></strong></td>
        <td class="vt-mono" style="white-space:nowrap;"><?= vt_e($rango) ?><?php if ($nMuestras !== null): ?><span class="small muted vt-sub"><?= $nMuestras ?> muestra<?= $nMuestras === 1 ? '' : 's' ?></span><?php endif; ?></td>
        <td><?= vt_e(implode(', ', $tipos)) ?></td>
        <td class="vt-analysis-cell">
            <?php if ($multi): ?>
                <span class="vt-analisis-list"><?php foreach ($analisis as $a): ?><span class="vt-chip"><?= vt_e($a) ?></span><?php endforeach; ?></span>
            <?php else: ?>
                <?= vt_e($analisis[0]) ?>
            <?php endif; ?>
            <span data-fuera-grupo="<?= vt_e(implode(',', $ids)) ?>"></span>
        </td>
        <td><?= vt_e(implode(', ', $analistas)) ?></td>
        <td style="white-space:nowrap;"><?= vt_e(vt_fecha($primera['fecha'] ?? null)) ?></td>
        <td><?= $chip ?></td>
        <td>
            <?php $resolver = $modo === 'revisor' && $puedeResolver; ?>
            <?php if ($href || $resolver): ?>
                <details class="vt-menu">
                    <summary class="cengi-btn cengi-btn-ghost cengi-btn-sm" aria-label="Acciones de <?= vt_e($etiquetaForms) ?>">Acciones <span aria-hidden="true">▾</span></summary>
                    <div class="vt-menu-list" role="menu">
                        <span class="vt-menu-title"><?= $multi ? 'Hoja · ' . count($ids) . ' formularios' : 'Formulario #' . $ids[0] ?></span>
                        <?php if ($href): ?>
                            <a role="menuitem" href="<?= vt_e($href) ?>" title="Datos del analista, blancos aplicados, cálculos y curva"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>Ver detalle<?= $multi ? ' de la hoja' : '' ?></a>
                        <?php endif; ?>
                        <?php if ($resolver): ?>
                            <span class="vt-menu-title">Resolución</span>
                            <button type="button" role="menuitem" class="js-vt-accion is-ok" data-accion="aprobar"><span class="material-symbols-outlined" aria-hidden="true">task_alt</span><?= $multi ? 'Validar hoja completa' : 'Validar' ?></button>
                            <button type="button" role="menuitem" class="js-vt-accion is-bad" data-accion="rechazar"><span class="material-symbols-outlined" aria-hidden="true">replay</span><?= $multi ? 'Rechazar hoja y enviar a reanálisis' : 'Rechazar y enviar a reanálisis' ?></button>
                        <?php endif; ?>
                    </div>
                </details>
            <?php else: ?>
                <span class="small muted">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

function vt_tabla(array $grupos, array $snapshots, string $modo, bool $puedeResolver, bool $puedeVerErroneos): string
{
    $html = '<div class="cengi-table-wrap vt-table-wrap"><table class="vt-table"><thead><tr>'
        . '<th>Formulario</th><th>Lote</th><th>Rango</th><th>Tipo</th><th>Análisis</th><th>Analista</th><th>Fecha</th><th>Estado</th><th style="text-align:right;">Acciones</th>'
        . '</tr></thead><tbody>';
    foreach ($grupos as $grupo) {
        $html .= vt_fila($grupo, $snapshots, $modo, $puedeResolver, $puedeVerErroneos);
    }
    return $html . '</tbody></table></div>';
}

lab_shell_head('Validación técnica', 'Revisión de datos capturados, cálculos y curvas', [
    '../css/lab_shell.css?v=20261006',
    '../styles/validacion_tecnica.css?v=' . filemtime(__DIR__ . '/../styles/validacion_tecnica.css'),
]);
lab_shell_open('validacion_tecnica_view.php');
?>
<section class="vt-wrap">

<?php if ($puedeVerErroneos): ?>
    <a href="../controllers/formularios_erroneos_controller.php" class="cengi-btn cengi-btn-ghost cengi-btn-sm" style="margin-bottom:14px;">
        <span class="material-symbols-outlined" style="font-size:15px;">report</span>
        Ver formularios originales con errores
    </a>
<?php endif; ?>

<div class="cengi-kpi-grid vt-kpis">
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">fact_check</span></span>
        <div class="cengi-kpi-val"><?= $totalGruposPendientes ?></div>
        <div class="cengi-kpi-label">Hojas pendientes de validar (<?= count($pendientesTodos) ?> formulario<?= count($pendientesTodos) === 1 ? '' : 's' ?>)</div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">inventory_2</span></span>
        <div class="cengi-kpi-val"><?= count($lotesDisponibles) ?></div>
        <div class="cengi-kpi-label">Lotes con análisis en revisión</div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi vt-kpi-alert">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">warning</span></span>
        <div class="cengi-kpi-val" id="kpiValFuera">0</div>
        <div class="cengi-kpi-label">Resultados fuera de rango</div>
        <span class="cengi-kpi-bar"></span>
    </article>
</div>

<div class="vt-toolbar">
    <h3>Líneas para validación técnica</h3>
    <form method="GET">
        <select name="id_lote" aria-label="Filtrar por lote" onchange="this.form.submit()">
            <option value="">Todos los lotes con análisis en revisión</option>
            <?php foreach ($lotesDisponibles as $idLote => $codigo): ?>
                <option value="<?= (int) $idLote ?>" <?= $idLoteFiltro === (int) $idLote ? 'selected' : '' ?>><?= vt_e($codigo) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tipo" aria-label="Filtrar por tipo de muestra" onchange="this.form.submit()">
            <option value="">Todo tipo de muestra</option>
            <?php foreach (array_keys($tiposDisponibles) as $tipo): ?>
                <option value="<?= vt_e($tipo) ?>" <?= $tipoFiltro === $tipo ? 'selected' : '' ?>><?= vt_e($tipo) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="cengi-btn cengi-btn-ghost">Filtrar</button></noscript>
    </form>
</div>

<p class="vt-intro">
    Cada línea corresponde a una <b>hoja de trabajo enviada</b>: si la hoja incluye varios análisis (p. ej. pH y CE),
    se validan <b>en conjunto</b>. <b>Detalle</b> muestra los datos del analista junto con los blancos aplicados, los
    resultados calculados de cada análisis y la curva de calibración; desde ahí puedes devolver muestras puntuales a reanálisis.
    Cuando todos los análisis de un lote están validados, su <b>informe</b> pasa a aprobación del jefe de laboratorio.
</p>

<?php if (!$gruposPendientes): ?>
    <div class="cengi-empty vt-empty-ok">
        <span class="material-symbols-outlined">task_alt</span>
        Sin formularios pendientes de validación con los filtros actuales.
    </div>
<?php else: ?>
    <?= vt_tabla($gruposPendientes, $snapshots, 'revisor', $puedeResolver, $puedeVerErroneos) ?>
<?php endif; ?>

<h2 class="vt-section-title">Validados · pasan al informe del lote</h2>
<?php if (!$gruposValidados): ?>
    <div class="cengi-empty">Aún no hay formularios validados.</div>
<?php else: ?>
    <?= vt_tabla($gruposValidados, $snapshots, 'lectura', $puedeResolver, $puedeVerErroneos) ?>
<?php endif; ?>

<?php if (!$puedeResolver): ?>
    <p class="vt-intro" style="margin-top:14px;font-size:11.5px;">
        Tu rol solo tiene permiso de consulta (<code>laboratorio.validacion_tecnica.ver</code>); no puedes validar ni rechazar análisis.
    </p>
<?php endif; ?>

</section>

<script type="application/json" id="vt-list-data"><?= json_encode($datosLista, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?></script>
<script src="../js/hojas_definiciones.js?v=<?= filemtime(__DIR__ . '/../js/hojas_definiciones.js') ?>"></script>
<script src="../js/validacion_tecnica.js?v=<?= filemtime(__DIR__ . '/../js/validacion_tecnica.js') ?>"></script>

<?php lab_shell_close(); ?>
