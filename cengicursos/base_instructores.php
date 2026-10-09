<?php
/**
 * Base de datos de instructores: documentacion (CV, carta de comprobacion, formacion),
 * evaluacion promedio y sesiones del año. Alimenta el Programa de sesiones, que avisa
 * al asignar a un instructor con documentacion incompleta.
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_gestionar_instructores();

$db = conectar();
$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtroDocs = in_array($_GET['docs'] ?? '', ['completa', 'incompleta', 'inactivo'], true) ? $_GET['docs'] : '';
$anio = (int) ($_GET['anio'] ?? cengi_pp_anio_actual());
if ($anio < 2000 || $anio > 2100) {
    $anio = cengi_pp_anio_actual();
}

$stmt = $db->prepare("
    SELECT i.*,
        (SELECT emisor FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1 ORDER BY d.creado DESC LIMIT 1) AS carta_emisor,
        (SELECT id FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1 ORDER BY d.creado DESC LIMIT 1) AS carta_id,
        (SELECT DATE(creado) FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1 ORDER BY d.creado DESC LIMIT 1) AS carta_fecha,
        COALESCE(ses.impartidas, 0) AS impartidas, COALESCE(ses.programadas, 0) AS programadas,
        ev.total AS evaluaciones, ev.promedio, ev.recomienda
    FROM instructores i
    LEFT JOIN (
        SELECT s.instructor_id, SUM(s.estado = 'impartida') AS impartidas, SUM(s.estado = 'programada') AS programadas
        FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
        WHERE p.anio = ? GROUP BY s.instructor_id
    ) ses ON ses.instructor_id = i.id
    LEFT JOIN (
        SELECT eei.instructor_id, COUNT(*) AS total, AVG(ei.recomendaria_instructor) AS recomienda,
               AVG((ei.instructor_lenguaje_claro + ei.instructor_material_adecuado + ei.instructor_conocimiento_tema
                   + ei.instructor_respeto_participantes + ei.instructor_puntualidad_objetivos) / 5) AS promedio
        FROM evaluaciones_instructor ei INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
        GROUP BY eei.instructor_id
    ) ev ON ev.instructor_id = i.id
    ORDER BY i.estado DESC, i.nombre
");
$stmt->execute([$anio]);
$todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$docs = cengi_pp_documentacion($db);

$lista = array_values(array_filter($todos, static function ($i) use ($busqueda, $filtroDocs, $docs) {
    $d = $docs[$i['id']];
    if ($filtroDocs === 'completa' && !$d['completa']) {
        return false;
    }
    if ($filtroDocs === 'incompleta' && ($d['completa'] || !$d['activo'])) {
        return false;
    }
    if ($filtroDocs === 'inactivo' && $d['activo']) {
        return false;
    }
    $texto = $i['nombre'] . ' ' . cengi_pp_codigo_instructor($i['id']) . ' ' . $i['codigo_catedratico'] . ' ' . $i['correo'] . ' ' . $i['especialidad'];
    return $busqueda === '' || strpos(cengi_texto_normalizado($texto), cengi_texto_normalizado($busqueda)) !== false;
}));

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Código', 'Nombre', 'Especialidad', 'Institución', 'Código catedrático', 'Correo', 'Teléfono', 'Formación', 'CV', 'Carta de comprobación',
        'Emisor de la carta', 'Tarifa base (Q)', 'Evaluación promedio (1-4)', 'Recomendación (1-10)', 'N.° evaluaciones',
        'Sesiones impartidas (año ' . cengi_pp_anio_texto($anio) . ')', 'Sesiones programadas', 'Documentación', 'Estado']];
    foreach ($lista as $i) {
        $d = $docs[$i['id']];
        $filas[] = [cengi_pp_codigo_instructor($i['id']), $i['nombre'], $i['especialidad'], $i['institucion'], $i['codigo_catedratico'], $i['correo'], $i['telefono'],
            $i['titulo'], $d['cv'] ? 'Entregado' : 'No entregado', $d['carta'] ? 'Entregada' : 'No entregada', $i['carta_emisor'], $i['tarifa_sesion'],
            $i['promedio'] !== null ? round($i['promedio'], 2) : '', $i['recomienda'] !== null ? round($i['recomienda'], 1) : '', (int) $i['evaluaciones'],
            $i['impartidas'], $i['programadas'], $d['completa'] ? 'Completa' : 'Incompleta: ' . implode(', ', $d['faltan']), $d['activo'] ? 'Activo' : 'Inactivo'];
    }
    cengi_pp_csv('Base_de_datos_instructores', $filas);
}

$activos = array_filter($todos, static function ($i) use ($docs) {
    return $docs[$i['id']]['activo'];
});
$completos = array_filter($activos, static function ($i) use ($docs) {
    return $docs[$i['id']]['completa'];
});
$incompletosConSesiones = array_filter($activos, static function ($i) use ($docs) {
    return !$docs[$i['id']]['completa'] && $i['programadas'] > 0;
});
$filtros = array_filter(['q' => $busqueda, 'docs' => $filtroDocs, 'anio' => $anio]);
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Base de datos de instructores'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <nav class="pp-subnav">
        <a href="instructores.php"><span class="glyphicon glyphicon-chevron-left"></span>Instructores</a>
        <a href="base_instructores.php" class="is-active" aria-current="page"><span class="glyphicon glyphicon-list-alt"></span>Base de datos y documentos</a>
        <a href="programa_sesiones.php"><span class="glyphicon glyphicon-list"></span>Programa de sesiones</a>
        <a href="pago_instructores.php"><span class="glyphicon glyphicon-credit-card"></span>Pago a instructores</a>
    </nav>

    <div class="cengi-feedback is-warning" style="margin-bottom:16px;"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div>
        <div><p>Esta base de datos alimenta el Programa de sesiones y el pago a instructores. Un instructor tiene <b>documentación completa</b> cuando tiene <b>CV</b>, <b>carta de comprobación de su formación</b> y <b>formación registrada</b>; si falta algo, se avisa al asignarlo a una sesión. La evaluación promedio sale de las encuestas de los participantes.</p></div></div>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi(count($activos), 'Instructores activos', (count($todos) - count($activos)) . ' inactivo(s)', '#3E7A12', 'user');
        echo cengi_pp_kpi(count($completos), 'Documentación completa', count($activos) ? round(count($completos) * 100 / count($activos)) . '% de los activos' : '', '#1f5632', 'ok');
        echo cengi_pp_kpi(count($activos) - count($completos), 'Documentación incompleta', $incompletosConSesiones ? count($incompletosConSesiones) . ' con sesiones programadas' : 'Ninguno con sesiones programadas', count($incompletosConSesiones) ? '#B34E00' : '#8A6600', 'warning-sign', count($incompletosConSesiones) ? '#FFE9D9' : '#FFF6DA');
        echo cengi_pp_kpi(count(array_filter($activos, static function ($i) {
            return !$i['evaluaciones'];
        })), 'Sin evaluaciones', 'Activos sin encuestas de participantes', '#5B6459', 'star', '#EDEFEA');
        ?>
    </div>

    <section class="pp-card">
        <form class="pp-toolbar" method="get">
            <div class="pp-toolbar-group">
                <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar por nombre, código o correo..." aria-label="Buscar">
                <select name="docs" aria-label="Documentación" onchange="this.form.submit()">
                    <option value="">Toda documentación</option>
                    <option value="completa" <?php echo $filtroDocs === 'completa' ? 'selected' : ''; ?>>Documentación completa</option>
                    <option value="incompleta" <?php echo $filtroDocs === 'incompleta' ? 'selected' : ''; ?>>Documentación incompleta</option>
                    <option value="inactivo" <?php echo $filtroDocs === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                </select>
                <select name="anio" aria-label="Año de las sesiones" onchange="this.form.submit()">
                    <?php foreach (cengi_pp_anios_disponibles($db, $anio) as $z): ?><option value="<?php echo $z; ?>" <?php echo $z === $anio ? 'selected' : ''; ?>>Sesiones año <?php echo cengi_pp_anio_texto($z); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="base_instructores.php?<?php echo cengi_pp_h(http_build_query($filtros + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar base de datos</a>
                <a class="btn btn-primary btn-sm" href="instructores.php"><span class="glyphicon glyphicon-plus"></span> Nuevo instructor</a>
            </div>
        </form>
    </section>

    <section class="cengi-courses-section">
        <div class="pp-table-wrap">
            <table class="cengi-org-table" style="min-width:1100px;">
                <thead><tr><th>Código</th><th>Instructor</th><th>Contacto</th><th>Formación que lo respalda</th><th>CV</th><th>Carta de comprobación</th><th>Evaluación</th><th>Sesiones en el año</th><th>Documentación</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$lista): ?><tr><td colspan="10" class="pp-empty">Ningún instructor coincide con los filtros.</td></tr><?php endif; ?>
                    <?php foreach ($lista as $i): $d = $docs[$i['id']]; $ficha = 'ficha_instructor.php?id=' . (int) $i['id']; ?>
                        <tr class="<?php echo $d['activo'] ? '' : 'pp-muted-row'; ?>">
                            <td class="pp-mono" style="white-space:nowrap;"><?php echo cengi_pp_codigo_instructor($i['id']); ?><span class="pp-sub pp-mono"><?php echo cengi_pp_h($i['codigo_catedratico'] ?: '—'); ?></span></td>
                            <td style="min-width:170px;"><strong><a href="<?php echo $ficha; ?>"><?php echo cengi_pp_h($i['nombre']); ?></a></strong><span class="pp-sub"><?php echo cengi_pp_h(($i['especialidad'] ?: 'Sin especialidad') . ($i['institucion'] ? ' · ' . $i['institucion'] : '')); ?></span></td>
                            <td style="font-size:12px;"><?php echo $i['correo'] ? '<a href="mailto:' . cengi_pp_h($i['correo']) . '">' . cengi_pp_h($i['correo']) . '</a>' : '—'; ?><span class="pp-sub"><?php echo cengi_pp_h($i['telefono']); ?></span></td>
                            <td style="min-width:160px;font-size:12px;"><?php echo $i['titulo'] ? cengi_pp_h($i['titulo']) : '<span class="pp-sub">Sin registrar</span>'; ?></td>
                            <td><?php echo $d['cv'] ? '<a class="pp-chip is-ok" target="_blank" rel="noopener" href="documento_instructor.php?cv=' . (int) $i['id'] . '">Ver CV</a>' : '<a class="pp-chip is-danger" href="' . $ficha . '&amp;tab=documentos">Subir</a>'; ?></td>
                            <td><?php echo $d['carta'] ? '<a class="pp-chip is-ok" target="_blank" rel="noopener" href="documento_instructor.php?doc=' . (int) $i['carta_id'] . '">Ver carta</a><span class="pp-sub">' . cengi_pp_h($i['carta_emisor'] ?: cengi_pp_fecha($i['carta_fecha'])) . '</span>' : '<a class="pp-chip is-danger" href="' . $ficha . '&amp;tab=documentos">Subir</a>'; ?></td>
                            <td class="pp-num"><?php echo $i['promedio'] !== null ? '<b style="color:#3E7A12;">' . number_format($i['promedio'], 1) . '</b> <span class="pp-sub" style="display:inline;">/ 4 · ' . (int) $i['evaluaciones'] . '</span>' : '<span class="pp-sub">Sin evaluaciones</span>'; ?></td>
                            <td class="pp-num"><?php echo (int) $i['impartidas']; ?> impartidas<span class="pp-sub"><?php echo (int) $i['programadas']; ?> programadas</span></td>
                            <td><?php echo !$d['activo'] ? '<span class="cengi-status-badge is-neutral"><i></i>Inactivo</span>' : ($d['completa'] ? '<span class="cengi-status-badge is-active"><i></i>Completa</span>'
                                : '<span class="cengi-status-badge is-pending"><i></i>Incompleta</span><span class="pp-sub">Falta: ' . cengi_pp_h(implode(', ', $d['faltan'])) . '</span>'); ?></td>
                            <td class="is-actions"><div class="cengi-row-actions">
                                <a class="cengi-action-btn is-view" href="<?php echo $ficha; ?>" data-tooltip="Ver ficha" aria-label="Ver ficha"><span class="glyphicon glyphicon-user"></span></a>
                                <a class="cengi-action-btn is-edit" href="<?php echo $ficha; ?>&amp;tab=documentos" data-tooltip="Documentos" aria-label="Documentos"><span class="glyphicon glyphicon-folder-open"></span></a>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
