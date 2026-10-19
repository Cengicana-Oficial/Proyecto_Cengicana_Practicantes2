<?php
/**
 * Base de datos de instructores: código de instructor (el mismo de Programa de sesiones, carga
 * masiva y pago), documentación (CV, carta de comprobación, formación), datos para el pago
 * (tarifa, comprobante, DPI), evaluación de los participantes y sesiones del año.
 * El Programa de sesiones avisa al asignar a un instructor con documentación incompleta.
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_gestionar_instructores();

$db = conectar();
cengi_pp_asegurar_codigos_instructor($db);
$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtro = in_array($_GET['docs'] ?? '', ['completa', 'incompleta', 'pago', 'inactivo'], true) ? $_GET['docs'] : '';
$anio = (int) ($_GET['anio'] ?? cengi_pp_anio_actual());
if ($anio < 2000 || $anio > 2100) {
    $anio = cengi_pp_anio_actual();
}
$ahora = date('Y-m-d H:i:s');
$cols = array_flip($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructores'")->fetchAll(PDO::FETCH_COLUMN));
$tienePago = isset($cols['dpi'], $cols['comprobante']);

$stmt = $db->prepare("
    SELECT i.*,
        (SELECT emisor FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1 ORDER BY d.creado DESC LIMIT 1) AS carta_emisor,
        (SELECT id FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1 ORDER BY d.creado DESC LIMIT 1) AS carta_id,
        (SELECT DATE(creado) FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1 ORDER BY d.creado DESC LIMIT 1) AS carta_fecha,
        COALESCE(ses.impartidas, 0) AS impartidas, COALESCE(ses.programadas, 0) AS programadas, ses.proxima,
        ev.total AS evaluaciones, ev.promedio, ev.recomienda
    FROM instructores i
    LEFT JOIN (
        -- Impartida: marcada o con su horario ya terminado; programada: por realizar.
        SELECT s.instructor_id,
            SUM(s.estado = 'impartida' OR (s.estado <> 'cancelada' AND TIMESTAMP(s.fecha, s.hora_fin) <= ?)) AS impartidas,
            SUM(s.estado = 'programada' AND TIMESTAMP(s.fecha, s.hora_fin) > ?) AS programadas,
            MIN(CASE WHEN s.estado = 'programada' AND TIMESTAMP(s.fecha, s.hora_inicio) > ? THEN s.fecha END) AS proxima
        FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
        WHERE p.anio = ? AND s.instructor_id IS NOT NULL GROUP BY s.instructor_id
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
$stmt->execute([$ahora, $ahora, $ahora, $anio]);
$todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$docs = cengi_pp_documentacion($db);

/** Lo que falta para pagarle: tarifa y, si cobra con recibo, su DPI. */
$faltaPago = static function (array $i) use ($tienePago) {
    $f = [];
    if ($i['tarifa_sesion'] === null || (float) $i['tarifa_sesion'] <= 0) {
        $f[] = 'tarifa';
    }
    if ($tienePago && ($i['comprobante'] ?? 'factura') === 'recibo' && trim((string) ($i['dpi'] ?? '')) === '') {
        $f[] = 'DPI para el recibo';
    }
    return $f;
};

$lista = array_values(array_filter($todos, static function ($i) use ($busqueda, $filtro, $docs, $faltaPago) {
    $d = $docs[$i['id']];
    if ($filtro === 'completa' && (!$d['completa'] || !$d['activo'])) return false;
    if ($filtro === 'incompleta' && ($d['completa'] || !$d['activo'])) return false;
    if ($filtro === 'pago' && (!$d['activo'] || !$faltaPago($i))) return false;
    if ($filtro === 'inactivo' && $d['activo']) return false;
    $texto = $i['nombre'] . ' ' . cengi_pp_codigo_instructor($i['id'], $i['codigo_catedratico']) . ' ' . cengi_pp_codigo_instructor($i['id']) . ' ' . $i['correo'] . ' ' . $i['especialidad'] . ' ' . $i['institucion'];
    return $busqueda === '' || strpos(cengi_texto_normalizado($texto), cengi_texto_normalizado($busqueda)) !== false;
}));

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Código de instructor', 'Nombre', 'Especialidad', 'Institución', 'Correo', 'Teléfono', 'Formación', 'CV', 'Carta de comprobación',
        'Emisor de la carta', 'Tarifa base (Q)', 'Comprobante', 'DPI', 'Evaluación promedio (1-4)', 'Recomendación (1-10)', 'N.° evaluaciones',
        'Sesiones impartidas (año ' . cengi_pp_anio_texto($anio) . ')', 'Sesiones por realizar', 'Documentación', 'Datos de pago', 'Estado']];
    foreach ($lista as $i) {
        $d = $docs[$i['id']];
        $fp = $faltaPago($i);
        $filas[] = [cengi_pp_codigo_instructor($i['id'], $i['codigo_catedratico']), $i['nombre'], $i['especialidad'], $i['institucion'], $i['correo'], $i['telefono'],
            $i['titulo'], $d['cv'] ? 'Entregado' : 'No entregado', !empty($d['cv_digital']) ? 'No requerida (CV digital entregado)' : ($d['carta'] ? 'Entregada' : 'No entregada'), $i['carta_emisor'], $i['tarifa_sesion'],
            ucfirst((string) ($i['comprobante'] ?? 'factura')), $i['dpi'] ?? '',
            $i['promedio'] !== null ? round($i['promedio'], 2) : '', $i['recomienda'] !== null ? round($i['recomienda'], 1) : '', (int) $i['evaluaciones'],
            $i['impartidas'], $i['programadas'], $d['completa'] ? 'Completa' : 'Incompleta: ' . implode(', ', $d['faltan']), $fp ? 'Falta: ' . implode(', ', $fp) : 'Completos',
            $d['activo'] ? 'Activo' : 'Inactivo'];
    }
    cengi_pp_csv('Base_de_datos_instructores', $filas);
}

$activos = array_filter($todos, static function ($i) use ($docs) { return $docs[$i['id']]['activo']; });
$completos = array_filter($activos, static function ($i) use ($docs) { return $docs[$i['id']]['completa']; });
$incompletosConSesiones = array_filter($activos, static function ($i) use ($docs) { return !$docs[$i['id']]['completa'] && $i['programadas'] > 0; });
$sinPago = array_filter($activos, static function ($i) use ($faltaPago) { return (bool) $faltaPago($i); });
$sinEval = array_filter($activos, static function ($i) { return !$i['evaluaciones']; });
$conCodigoPropio = array_filter($activos, static function ($i) { return strpos((string) $i['codigo_catedratico'], 'INS-') !== 0; });
$filtros = array_filter(['q' => $busqueda, 'docs' => $filtro, 'anio' => $anio]);
$url = static function (array $cambios) use ($filtros) {
    return 'base_instructores.php?' . http_build_query(array_filter(array_merge($filtros, $cambios), 'strlen'));
};
$nivel = static function ($v) { return $v === null ? '' : ($v >= 3.2 ? 'is-bien' : ($v >= 2.4 ? 'is-medio' : 'is-bajo')); };
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Base de datos de instructores'); ?>
<link rel="stylesheet" href="css/instructores_tema.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/instructores_tema.css'); ?>"></head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page bi-page">
    <?php cengi_nav_barra('base_instructores.php'); ?>

    <header class="inst-hero">
        <div><small>Instructores · base de datos</small><h1>Base de datos y documentos</h1>
            <p>Un solo <b>código de instructor</b> para todo el sistema: el mismo aparece en el Programa de sesiones, la carga masiva y el pago. Desde aquí se revisa la documentación, los datos para el pago y su desempeño.</p></div>
    </header>

    <section class="inst-kpis" aria-label="Resumen">
        <div class="is-verde"><span class="glyphicon glyphicon-user"></span><b><?php echo count($activos); ?> <small>/ <?php echo count($todos); ?></small></b><em>Instructores activos</em></div>
        <div class="is-verde2"><span class="glyphicon glyphicon-ok"></span><b><?php echo count($completos); ?></b><em>Documentación completa<?php echo $activos ? ' · ' . round(count($completos) * 100 / count($activos)) . '%' : ''; ?></em></div>
        <div class="<?php echo $incompletosConSesiones ? 'is-naranja' : 'is-amarillo'; ?>"><span class="glyphicon glyphicon-warning-sign"></span><b><?php echo count($activos) - count($completos); ?></b><em>Documentación incompleta<?php echo $incompletosConSesiones ? ' · ' . count($incompletosConSesiones) . ' con sesiones' : ''; ?></em></div>
        <div class="<?php echo $sinPago ? 'is-naranja' : 'is-gris'; ?>"><span class="glyphicon glyphicon-credit-card"></span><b><?php echo count($sinPago); ?></b><em>Datos de pago incompletos</em></div>
        <div class="is-gris"><span class="glyphicon glyphicon-star"></span><b><?php echo count($sinEval); ?></b><em>Sin evaluaciones</em></div>
        <div class="is-verde"><span class="glyphicon glyphicon-tag"></span><b><?php echo count($conCodigoPropio); ?></b><em>Con código de catedrático propio</em></div>
    </section>

    <section class="pp-card bi-toolbar">
        <form class="pp-toolbar" method="get">
            <div class="pp-toolbar-group">
                <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar por nombre, código (INS-023), correo o institución…" aria-label="Buscar">
                <?php if ($filtro !== ''): ?><input type="hidden" name="docs" value="<?php echo cengi_pp_h($filtro); ?>"><?php endif; ?>
                <select name="anio" aria-label="Año de las sesiones" onchange="this.form.submit()">
                    <?php foreach (cengi_pp_anios_disponibles($db, $anio) as $z): ?><option value="<?php echo $z; ?>" <?php echo $z === $anio ? 'selected' : ''; ?>>Sesiones año <?php echo cengi_pp_anio_texto($z); ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-default btn-sm">Buscar</button>
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="<?php echo cengi_pp_h($url(['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar base de datos</a>
                <a class="btn btn-primary btn-sm" href="instructores.php"><span class="glyphicon glyphicon-plus"></span> Nuevo instructor</a>
            </div>
        </form>
        <nav class="bi-chips" aria-label="Filtrar">
            <?php foreach (['' => ['Todos', count($todos)], 'completa' => ['Documentación completa', count($completos)], 'incompleta' => ['Documentación incompleta', count($activos) - count($completos)],
                'pago' => ['Datos de pago incompletos', count($sinPago)], 'inactivo' => ['Inactivos', count($todos) - count($activos)]] as $clave => [$txt, $n]): ?>
                <a href="<?php echo cengi_pp_h($url(['docs' => $clave])); ?>" class="<?php echo $filtro === $clave ? 'is-activo' : ''; ?>"><?php echo $txt; ?> <b><?php echo $n; ?></b></a>
            <?php endforeach; ?>
        </nav>
    </section>

    <section class="bi-tabla-wrap">
        <div class="pp-table-wrap">
            <table class="bi-tabla">
                <thead><tr><th>Código</th><th>Instructor</th><th>Documentación</th><th>Pago</th><th>Evaluación</th><th>Sesiones <?php echo cengi_pp_anio_texto($anio); ?></th><th></th></tr></thead>
                <tbody>
                    <?php if (!$lista): ?><tr><td colspan="7" class="pp-empty">Ningún instructor coincide con los filtros.</td></tr><?php endif; ?>
                    <?php foreach ($lista as $i):
                        $d = $docs[$i['id']];
                        $ficha = 'ficha_instructor.php?id=' . (int) $i['id'];
                        $codigo = cengi_pp_codigo_instructor($i['id'], $i['codigo_catedratico']);
                        $interno = (bool) preg_match('/^INS-\d+$/', $codigo);
                        $fp = $faltaPago($i);
                        $prom = $i['promedio'] !== null ? (float) $i['promedio'] : null;
                    ?>
                        <tr class="<?php echo $d['activo'] ? ($d['completa'] ? 'is-completa' : 'is-incompleta') : 'is-inactivo'; ?>">
                            <td><span class="bi-codigo"><?php echo cengi_pp_h($codigo); ?></span><small class="bi-sub"><?php echo $interno ? 'Código interno' : 'Catedrático · ' . cengi_pp_h(cengi_pp_codigo_instructor($i['id'])); ?></small></td>
                            <td class="bi-persona"><strong><a href="<?php echo $ficha; ?>"><?php echo cengi_pp_h($i['nombre']); ?></a></strong>
                                <small class="bi-sub"><?php echo cengi_pp_h(($i['especialidad'] ?: 'Sin especialidad') . ($i['institucion'] ? ' · ' . $i['institucion'] : '')); ?></small>
                                <small class="bi-sub"><?php echo $i['correo'] ? '<a href="mailto:' . cengi_pp_h($i['correo']) . '">' . cengi_pp_h($i['correo']) . '</a>' : '<span class="bi-falta">Sin correo</span>'; ?><?php echo $i['telefono'] ? ' · ' . cengi_pp_h($i['telefono']) : ''; ?></small></td>
                            <td class="bi-docs">
                                <div class="bi-doc-fila">
                                    <?php if (!empty($d['cv_digital'])): ?>
                                    <a class="bi-doc is-ok" target="_blank" rel="noopener" href="instructor_cv.php?id=<?php echo (int) $i['id']; ?>">✓ CV digital entregado</a>
                                    <?php else: ?>
                                    <?php echo $d['cv'] ? '<a class="bi-doc is-ok" target="_blank" rel="noopener" href="documento_instructor.php?cv=' . (int) $i['id'] . '">✓ CV</a>' : '<a class="bi-doc is-falta" href="' . $ficha . '&amp;tab=documentos">+ CV</a>'; ?>
                                    <?php echo $d['carta'] ? '<a class="bi-doc is-ok" target="_blank" rel="noopener" href="documento_instructor.php?doc=' . (int) $i['carta_id'] . '" title="' . cengi_pp_h($i['carta_emisor'] ?: cengi_pp_fecha($i['carta_fecha'])) . '">✓ Carta</a>' : '<a class="bi-doc is-falta" href="' . $ficha . '&amp;tab=documentos">+ Carta</a>'; ?>
                                    <?php echo $i['titulo'] ? '<span class="bi-doc is-ok" title="' . cengi_pp_h($i['titulo']) . '">✓ Formación</span>' : '<a class="bi-doc is-falta" href="' . $ficha . '&amp;editar=1">+ Formación</a>'; ?>
                                    <?php endif; ?>
                                </div>
                                <?php echo !$d['activo'] ? '<span class="cengi-status-badge is-neutral"><i></i>Inactivo</span>' : ($d['completa'] ? '<span class="cengi-status-badge is-active"><i></i>Completa</span>' : '<span class="cengi-status-badge is-pending"><i></i>Falta ' . count($d['faltan']) . '</span>'); ?>
                            </td>
                            <td class="bi-pago">
                                <b><?php echo $i['tarifa_sesion'] !== null && (float) $i['tarifa_sesion'] > 0 ? 'Q' . number_format((float) $i['tarifa_sesion'], 2) : '<span class="bi-falta">Sin tarifa</span>'; ?></b>
                                <?php if ($tienePago): ?><small class="bi-sub"><?php echo ($i['comprobante'] ?? 'factura') === 'recibo' ? 'Recibo' . (trim((string) $i['dpi']) !== '' ? ' · DPI ✓' : ' · <span class="bi-falta">sin DPI</span>') : 'Factura'; ?></small><?php endif; ?>
                            </td>
                            <td class="bi-eval"><?php echo $prom !== null ? '<b class="bi-val ' . $nivel($prom) . '">' . number_format($prom, 1) . '<small>/4</small></b><small class="bi-sub">' . (int) $i['evaluaciones'] . ' encuesta(s)' . ($i['recomienda'] !== null ? ' · recomienda ' . number_format((float) $i['recomienda'], 1) : '') . '</small>' : '<span class="bi-sub">Sin evaluaciones</span>'; ?></td>
                            <td class="bi-ses"><b><?php echo (int) $i['impartidas']; ?></b> impartidas<small class="bi-sub"><?php echo (int) $i['programadas']; ?> por realizar<?php echo $i['proxima'] ? ' · próxima ' . cengi_pp_h(cengi_pp_fecha_corta($i['proxima'])) : ''; ?></small>
                                <?php if (!$d['completa'] && $d['activo'] && $i['programadas'] > 0): ?><small class="bi-falta">Tiene sesiones con documentación incompleta</small><?php endif; ?></td>
                            <td class="is-actions"><div class="bi-acciones">
                                <a class="bi-btn is-ver" href="<?php echo $ficha; ?>" title="Ficha completa"><span class="glyphicon glyphicon-user"></span> Ficha</a>
                                <a class="bi-btn" href="<?php echo $ficha; ?>&amp;tab=documentos" title="Documentos"><span class="glyphicon glyphicon-folder-open"></span></a>
                                <a class="bi-btn" href="programa_sesiones.php?instructor=<?php echo (int) $i['id']; ?>" title="Sus sesiones en el Programa de sesiones"><span class="glyphicon glyphicon-list"></span></a>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="bi-nota">El <b>CV digital entregado</b> completa la documentación sin exigir archivos individuales. Si se utiliza únicamente el CV en PDF, se requiere además la carta de comprobación y la formación registrada. El Programa de sesiones avisa si falta documentación al asignarlo.
            El <b>código interno</b> se asigna consecutivamente desde INS-001, independientemente del ID del registro. También puedes registrar un código de catedrático institucional en la ficha.
            Evaluación: promedio de los 5 aspectos de la encuesta de participantes (escala 1 a 4).</p>
    </section>
</main>
</body>
</html>
