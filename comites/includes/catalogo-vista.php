<?php
$catalogo = $pdo->query("SELECT c.*, l.nombre AS lider_nombre,
    (SELECT COUNT(*) FROM contacto_comite cc JOIN contactos ct ON ct.id=cc.contacto_id WHERE cc.comite_id=c.id AND ct.estado='Activo') AS integrantes,
    (SELECT COUNT(*) FROM reuniones r WHERE r.comite_id=c.id) AS reuniones,
    (SELECT MAX(fecha) FROM reuniones r WHERE r.comite_id=c.id AND realizada=1) AS ultima,
    (SELECT MIN(fecha) FROM reuniones r WHERE r.comite_id=c.id AND realizada=0 AND fecha>=CURRENT_DATE) AS proxima,
    (SELECT COUNT(*) FROM acuerdos a WHERE a.comite_id=c.id AND estado NOT IN ('Completado','Cancelado')) AS abiertos,
    (SELECT COUNT(*) FROM acuerdos a WHERE a.comite_id=c.id AND estado NOT IN ('Completado','Cancelado') AND plazo<CURRENT_DATE) AS vencidos
    FROM comites c LEFT JOIN contactos l ON l.id=c.lider_id ORDER BY c.nombre")->fetchAll();
$directorio = $pdo->query('SELECT * FROM contactos ORDER BY nombre')->fetchAll();
$relaciones = $pdo->query('SELECT contacto_id,comite_id FROM contacto_comite')->fetchAll();
$miembros = [];
foreach ($relaciones as $relacion) { $miembros[$relacion['comite_id']][] = (int) $relacion['contacto_id']; }
$gestionar = can_manage_resource($user, 'configuracion');
$busqueda = trim((string) ($_GET['q'] ?? ''));
$estadoFiltro = (string) ($_GET['estado'] ?? 'Activo');
$situacionFiltro = (string) ($_GET['situacion'] ?? '');
$ordenFiltro = (string) ($_GET['orden'] ?? 'nombre');
$vistaCatalogo = ($_GET['vista'] ?? '') === 'tabla' ? 'tabla' : 'tarjetas';

// Indicadores de cada comite en los ultimos 12 meses: asistencia de sus integrantes actuales,
// reuniones realizadas frente a las esperadas segun su periodicidad y alertas de organizacion.
$catHoy = new DateTimeImmutable('today');
$catDesde = $catHoy->modify('-12 months')->format('Y-m-d');
$consulta = $pdo->prepare('SELECT comite_id, COUNT(*) FROM reuniones WHERE realizada = 1 AND cancelada = 0 AND fecha >= ? GROUP BY comite_id');
$consulta->execute([$catDesde]);
$catRealizadas = $consulta->fetchAll(PDO::FETCH_KEY_PAIR);
$consulta = $pdo->prepare("SELECT r.comite_id, COUNT(*) FROM reunion_asistentes ra JOIN reuniones r ON r.id = ra.reunion_id
    JOIN contacto_comite cc ON cc.contacto_id = ra.contacto_id AND cc.comite_id = r.comite_id
    JOIN contactos ct ON ct.id = ra.contacto_id AND ct.estado = 'Activo'
    WHERE r.realizada = 1 AND r.cancelada = 0 AND r.fecha >= ? GROUP BY r.comite_id");
$consulta->execute([$catDesde]);
$catAsistencias = $consulta->fetchAll(PDO::FETCH_KEY_PAIR);
$catPeriodo = ['Mensual' => 1, 'Bimestral' => 2, 'Trimestral' => 3, 'Semestral' => 6];
$catSalud = ['atencion' => ['Requiere atención', 'ti-alert-triangle'], 'revisar' => ['Revisar', 'ti-alert-circle'], 'al_dia' => ['Al día', 'ti-circle-check'], 'inactivo' => ['Inactivo', 'ti-player-pause']];
$catAlertas = ['sin_integrantes' => 'Sin integrantes', 'sin_lider' => 'Sin líder', 'sin_proxima' => 'Sin próxima reunión', 'sin_sesion' => 'Sin reuniones recientes', 'vencidos' => 'Con pasos vencidos', 'asistencia' => 'Asistencia baja'];
$contactoPorId = array_column($directorio, null, 'id');
foreach ($catalogo as &$c) {
    $integrantes = array_values(array_filter(array_map(static fn($id) => $contactoPorId[$id] ?? null, $miembros[$c['id']] ?? []), static fn($x) => $x && $x['estado'] === 'Activo'));
    $liderId = (int) $c['lider_id'];
    usort($integrantes, static fn($a, $b) => ((int) $b['id'] === $liderId) <=> ((int) $a['id'] === $liderId) ?: strcmp($a['nombre'], $b['nombre']));
    $realizadas = (int) ($catRealizadas[$c['id']] ?? 0);
    $posibles = $realizadas * count($integrantes);
    $meses = $catPeriodo[$c['periodicidad']] ?? null;
    $edad = $c['creado_en'] ? (new DateTimeImmutable($c['creado_en']))->diff($catHoy) : null;
    $vida = $edad ? max(1, min(12, $edad->y * 12 + $edad->m + 1)) : 12;
    $c['integrantes_lista'] = $integrantes;
    $c['empresas'] = count(array_unique(array_filter(array_map(static fn($x) => normalize_text((string) $x['empresa']), $integrantes))));
    $c['realizadas12'] = $realizadas;
    $c['asistencia'] = $posibles ? min(100, (int) ($catAsistencias[$c['id']] ?? 0) / $posibles * 100) : null;
    $c['esperadas'] = $meses ? max(1, intdiv($vida, $meses)) : null;
    $c['cumplimiento'] = $c['esperadas'] ? min(100, $realizadas / $c['esperadas'] * 100) : null;
    $c['dias_ultima'] = $c['ultima'] ? (new DateTimeImmutable($c['ultima']))->diff($catHoy)->days : null;
    $c['dias_proxima'] = $c['proxima'] ? $catHoy->diff(new DateTimeImmutable($c['proxima']))->days : null;
    $alertas = [];
    if ((int) $c['activo']) {
        if (!$integrantes) { $alertas['sin_integrantes'] = 'Sin integrantes asignados'; }
        if (!$c['lider_nombre']) { $alertas['sin_lider'] = 'Sin líder asignado'; }
        if (!$c['proxima']) { $alertas['sin_proxima'] = 'Sin próxima reunión programada'; }
        if ($meses && ($c['ultima'] === null || $c['dias_ultima'] > $meses * 31 * 1.5)) { $alertas['sin_sesion'] = $c['ultima'] ? 'Sin reunirse hace ' . $c['dias_ultima'] . ' días' : 'Aún no ha sesionado'; }
        if ($c['vencidos']) { $alertas['vencidos'] = (int) $c['vencidos'] . ' paso(s) vencido(s)'; }
        if ($c['asistencia'] !== null && $c['asistencia'] < 50) { $alertas['asistencia'] = 'Asistencia baja (' . round($c['asistencia']) . '%)'; }
    }
    $c['alertas'] = $alertas;
    $c['salud'] = !(int) $c['activo'] ? 'inactivo' : (!$alertas ? 'al_dia' : (count($alertas) <= 2 && !isset($alertas['sin_integrantes']) ? 'revisar' : 'atencion'));
}
unset($c);

// Resumen general de los comites activos.
$catActivos = array_filter($catalogo, static fn($c) => (int) $c['activo']);
$catPersonas = [];
foreach ($catActivos as $c) { foreach ($c['integrantes_lista'] as $x) { $catPersonas[$x['id']] = true; } }
$catPosibles = array_sum(array_map(static fn($c) => $c['realizadas12'] * count($c['integrantes_lista']), $catActivos));
$catResumen = [
    'activos' => count($catActivos),
    'personas' => count($catPersonas),
    'realizadas' => array_sum(array_column($catActivos, 'realizadas12')),
    'programadas' => count(array_filter($catActivos, static fn($c) => $c['proxima'])),
    'asistencia' => $catPosibles ? array_sum(array_map(static fn($c) => (int) ($catAsistencias[$c['id']] ?? 0), $catActivos)) / $catPosibles * 100 : null,
    'abiertos' => array_sum(array_column($catActivos, 'abiertos')),
    'vencidos' => array_sum(array_column($catActivos, 'vencidos')),
    'atencion' => count(array_filter($catActivos, static fn($c) => in_array($c['salud'], ['atencion', 'revisar'], true))),
];
$catConteoAlertas = array_map(static fn($k) => count(array_filter($catActivos, static fn($c) => isset($c['alertas'][$k]))), array_combine(array_keys($catAlertas), array_keys($catAlertas)));

$resultados = array_filter($catalogo, static fn(array $c): bool => ($estadoFiltro === 'todos' || (int) $c['activo'] === ($estadoFiltro === 'Inactivo' ? 0 : 1))
    && ($busqueda === '' || mb_stripos($c['nombre'] . ' ' . ($c['descripcion'] ?? ''), $busqueda) !== false)
    && match ($situacionFiltro) { '' => true, 'al_dia' => $c['salud'] === 'al_dia', 'atencion' => in_array($c['salud'], ['atencion', 'revisar'], true), default => isset($c['alertas'][$situacionFiltro]) });
$catRango = array_flip(array_keys($catSalud));
usort($resultados, static fn($a, $b) => match ($ordenFiltro) {
    'integrantes' => count($b['integrantes_lista']) <=> count($a['integrantes_lista']),
    'asistencia' => ($b['asistencia'] ?? -1) <=> ($a['asistencia'] ?? -1),
    'ultima' => (string) $b['ultima'] <=> (string) $a['ultima'],
    'situacion' => $catRango[$a['salud']] <=> $catRango[$b['salud']] ?: count($b['alertas']) <=> count($a['alertas']),
    default => 0,
} ?: strcoll(normalize_text($a['nombre']), normalize_text($b['nombre'])));
$catUrl = static fn(array $extra): string => 'index.php?' . http_build_query(array_filter($extra + ['view' => 'comites', 'q' => $busqueda, 'estado' => $estadoFiltro, 'situacion' => $situacionFiltro, 'orden' => $ordenFiltro, 'vista' => $vistaCatalogo], static fn($v) => $v !== '' && $v !== null));
$catFecha = static fn(?string $f): string => $f ? date('d/m/Y', strtotime($f)) : '—';
$catIniciales = static function (string $nombre): string {
    $palabras = array_values(array_filter(preg_split('/\s+/u', trim($nombre)), static fn($p) => $p !== '' && !str_ends_with($p, '.')));
    return mb_strtoupper(mb_substr($palabras[0] ?? '?', 0, 1) . mb_substr($palabras[1] ?? '', 0, 1));
};
$catPct = static fn(?float $v): string => $v === null ? '—' : round($v) . '%';
$catTono = static fn(?float $v): string => $v === null ? '' : ($v >= 70 ? 'is-bien' : ($v >= 50 ? 'is-medio' : 'is-bajo'));
$seleccion = null;
$modalMiembros = isset($_GET['integrantes']);
$seleccionId = (string) ($_GET['integrantes'] ?? $_GET['editar'] ?? '');
foreach ($catalogo as $c) { if ($c['id'] === $seleccionId) { $seleccion = $c; } }
if (isset($_GET['nuevo'])) { $seleccion = ['id'=>'','nombre'=>'','descripcion'=>'','lider_id'=>'','periodicidad'=>'Mensual','activo'=>1,'logo'=>null]; }
if ($flashError !== '' && ($_POST['accion'] ?? '') === 'grupo_guardar') {
    $seleccion = array_merge($seleccion ?? [], $_POST, ['activo' => ($_POST['estado'] ?? '') === 'Inactivo' ? 0 : 1]);
}
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['catalogo_csrf']) . '">';
// Menu de acciones de un comite (tarjetas y tabla).
$catMenu = static function (array $c, bool $compacto = false) use ($csrf): string {
    $id = urlencode($c['id']); $activo = (int) $c['activo'];
    $resumen = $compacto ? '<summary aria-label="Acciones de ' . e($c['nombre']) . '" title="Acciones"><i class="ti ti-dots-vertical"></i></summary>' : '<summary><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary>';
    return '<details class="catalogo-menu' . ($compacto ? ' is-compacto' : '') . '">' . $resumen . '<div class="catalogo-menu-list" role="menu">'
        . '<a href="index.php?view=comites&integrantes=' . $id . '"><i class="ti ti-user-plus"></i> Agregar integrantes</a>'
        . '<a href="index.php?view=comites&editar=' . $id . '"><i class="ti ti-pencil"></i> Editar</a>'
        . '<form method="post">' . $csrf . '<input type="hidden" name="id" value="' . e($c['id']) . '"><button name="accion" value="grupo_estado"><i class="ti ' . ($activo ? 'ti-player-pause' : 'ti-player-play') . '"></i> ' . ($activo ? 'Inactivar' : 'Activar') . '</button></form>'
        . '<form method="post" onsubmit="return confirm(\'¿Eliminar este comité? Esta acción no se puede deshacer.\')">' . $csrf . '<input type="hidden" name="id" value="' . e($c['id']) . '"><button class="is-danger" name="accion" value="grupo_eliminar"><i class="ti ti-trash"></i> Eliminar</button></form>'
        . '</div></details>';
};
?>
<link rel="stylesheet" href="assets/css/catalogo.css?v=<?= @filemtime(__DIR__ . '/../assets/css/catalogo.css') ?>">
<section class="catalogo" aria-label="Catálogo de comités">
    <div class="catalogo-heading">
        <p>Estado de organización y actividad de cada comité en los últimos 12 meses: integrantes, reuniones según su periodicidad, asistencia y próximos pasos.</p>
        <?php if ($gestionar): ?><a class="btn btn-primary" href="index.php?view=comites&nuevo=1"><i class="ti ti-plus"></i> Nuevo grupo</a><?php endif; ?>
    </div>

    <div class="cat-kpis">
        <div><i class="ti ti-affiliate"></i><b><?= $catResumen['activos'] ?></b><span>Comités activos</span></div>
        <a href="index.php?view=contactos"><i class="ti ti-users"></i><b><?= $catResumen['personas'] ?></b><span>Integrantes únicos</span></a>
        <a href="index.php?view=reuniones"><i class="ti ti-calendar-check"></i><b><?= $catResumen['realizadas'] ?></b><span>Reuniones en 12 meses · <?= $catResumen['programadas'] ?> con próxima programada</span></a>
        <div class="<?= $catTono($catResumen['asistencia']) ?>"><i class="ti ti-user-check"></i><b><?= $catPct($catResumen['asistencia']) ?></b><span>Asistencia de integrantes</span></div>
        <a href="index.php?view=acuerdos" class="<?= $catResumen['vencidos'] ? 'is-bajo' : '' ?>"><i class="ti ti-list-check"></i><b><?= $catResumen['abiertos'] ?></b><span>Pasos abiertos<?= $catResumen['vencidos'] ? ' · ' . $catResumen['vencidos'] . ' vencido(s)' : '' ?></span></a>
        <a href="<?= e($catUrl(['situacion' => 'atencion', 'estado' => 'Activo'])) ?>" class="<?= $catResumen['atencion'] ? 'is-medio' : 'is-bien' ?>"><i class="ti ti-alert-triangle"></i><b><?= $catResumen['atencion'] ?></b><span>Requieren atención o revisión</span></a>
    </div>

    <?php if (array_filter($catConteoAlertas)): ?>
    <div class="cat-pendientes"><strong>Pendientes de organización</strong>
        <?php foreach ($catConteoAlertas as $clave => $n): if (!$n) continue; ?>
            <a class="cat-chip <?= $situacionFiltro === $clave ? 'is-active' : '' ?>" href="<?= e($catUrl(['situacion' => $situacionFiltro === $clave ? '' : $clave, 'estado' => 'Activo'])) ?>"><?= e($catAlertas[$clave]) ?> <b><?= $n ?></b></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form class="catalogo-filtros" method="get">
        <input type="hidden" name="view" value="comites"><input type="hidden" name="vista" value="<?= e($vistaCatalogo) ?>">
        <label class="catalogo-buscar"><i class="ti ti-search"></i><input class="form-control" name="q" value="<?= e($busqueda) ?>" placeholder="Buscar comité..." aria-label="Buscar comité"></label>
        <select class="form-control" name="estado" aria-label="Estado de los comités" onchange="this.form.submit()">
            <?php foreach (['Activo'=>'Activos','Inactivo'=>'Inactivos','todos'=>'Todos'] as $valor=>$label): ?><option value="<?= e($valor) ?>" <?= $estadoFiltro===$valor?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <select class="form-control" name="situacion" aria-label="Situación" onchange="this.form.submit()">
            <?php foreach (['' => 'Toda situación', 'al_dia' => 'Al día', 'atencion' => 'Requieren atención o revisión'] + $catAlertas as $valor=>$label): ?><option value="<?= e($valor) ?>" <?= $situacionFiltro===$valor?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <select class="form-control" name="orden" aria-label="Ordenar por" onchange="this.form.submit()">
            <?php foreach (['nombre'=>'Ordenar: nombre','situacion'=>'Ordenar: situación','integrantes'=>'Ordenar: integrantes','asistencia'=>'Ordenar: asistencia','ultima'=>'Ordenar: última reunión'] as $valor=>$label): ?><option value="<?= e($valor) ?>" <?= $ordenFiltro===$valor?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline" type="submit">Buscar</button><span class="catalogo-badge"><?= count($resultados) ?> comité(s)</span>
        <nav class="cat-vista" aria-label="Tipo de vista">
            <a href="<?= e($catUrl(['vista' => 'tarjetas'])) ?>" class="<?= $vistaCatalogo === 'tarjetas' ? 'is-active' : '' ?>" title="Tarjetas"><i class="ti ti-layout-grid"></i> Tarjetas</a>
            <a href="<?= e($catUrl(['vista' => 'tabla'])) ?>" class="<?= $vistaCatalogo === 'tabla' ? 'is-active' : '' ?>" title="Tabla"><i class="ti ti-list"></i> Tabla</a>
        </nav>
    </form>

    <?php if ($resultados && $vistaCatalogo === 'tarjetas'): ?>
    <div class="cat-grid">
        <?php foreach ($resultados as $c): $urlId = urlencode($c['id']); $lista = $c['integrantes_lista']; [$saludTexto, $saludIcono] = $catSalud[$c['salud']]; ?>
        <article class="cat-card salud-<?= e($c['salud']) ?>">
            <header>
                <?php if ($c['logo']): ?><img class="catalogo-logo" src="<?= e($c['logo']) ?>" alt="Logo de <?= e($c['nombre']) ?>"><?php else: ?><span class="catalogo-logo catalogo-icon"><i class="ti ti-affiliate"></i></span><?php endif; ?>
                <div class="cat-card-titulo">
                    <h3><?= e($c['nombre']) ?></h3>
                    <small><?= e($c['periodicidad']) ?> · Líder: <span class="<?= !$c['lider_nombre'] ? 'catalogo-alerta' : '' ?>"><?= e($c['lider_nombre'] ?: 'sin asignar') ?></span></small>
                    <span class="cat-salud salud-<?= e($c['salud']) ?>"><i class="ti <?= $saludIcono ?>"></i> <?= e($saludTexto) ?></span>
                </div>
                <?php if ($gestionar): ?><?= $catMenu($c, true) ?><?php endif; ?>
            </header>
            <?php if ($c['descripcion']): ?><p class="cat-desc" title="<?= e($c['descripcion']) ?>"><?= e($c['descripcion']) ?></p><?php endif; ?>

            <div class="cat-integrantes">
                <div class="cat-avatares">
                    <?php foreach (array_slice($lista, 0, 6) as $x): $interno = str_contains(normalize_text((string) $x['empresa']), 'cengica'); ?>
                        <span class="<?= $interno ? 'is-interno' : '' ?> <?= (int) $x['id'] === (int) $c['lider_id'] ? 'is-lider' : '' ?>" title="<?= e($x['nombre'] . ' · ' . $x['empresa'] . ((int) $x['id'] === (int) $c['lider_id'] ? ' · Líder' : '')) ?>"><?= e($catIniciales($x['nombre'])) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($lista) > 6): ?><span class="is-mas">+<?= count($lista) - 6 ?></span><?php endif; ?>
                </div>
                <a href="index.php?view=contactos&comite=<?= $urlId ?>"><b><?= count($lista) ?></b> integrante(s)<?= $c['empresas'] ? ' · ' . $c['empresas'] . ' empresa(s)' : '' ?></a>
            </div>

            <div class="cat-metricas">
                <div title="Reuniones realizadas en los últimos 12 meses frente a las esperadas según la periodicidad">
                    <span>Reuniones</span>
                    <b><?= $c['realizadas12'] ?><?= $c['esperadas'] ? '<small> de ' . $c['esperadas'] . '</small>' : '' ?></b>
                    <?php if ($c['cumplimiento'] !== null): ?><span class="cat-meter <?= $catTono($c['cumplimiento']) ?>"><span style="width:<?= max(2, round($c['cumplimiento'])) ?>%"></span></span><?php endif; ?>
                </div>
                <div title="Asistencias de los integrantes actuales sobre las reuniones realizadas">
                    <span>Asistencia</span>
                    <b><?= $catPct($c['asistencia']) ?></b>
                    <?php if ($c['asistencia'] !== null): ?><span class="cat-meter <?= $catTono($c['asistencia']) ?>"><span style="width:<?= max(2, round($c['asistencia'])) ?>%"></span></span><?php endif; ?>
                </div>
                <a href="index.php?view=acuerdos&comite=<?= $urlId ?>" title="Ver próximos pasos">
                    <span>Pasos abiertos</span>
                    <b class="<?= $c['vencidos'] ? 'catalogo-peligro' : '' ?>"><?= (int) $c['abiertos'] ?></b>
                    <small class="<?= $c['vencidos'] ? 'catalogo-peligro' : '' ?>"><?= $c['vencidos'] ? (int) $c['vencidos'] . ' vencido(s)' : 'ninguno vencido' ?></small>
                </a>
            </div>

            <div class="cat-fechas">
                <a href="index.php?view=reuniones&comite=<?= $urlId ?>"><i class="ti ti-calendar-check"></i><span>Última</span><b><?= $catFecha($c['ultima']) ?></b><small><?= $c['dias_ultima'] === null ? 'sin reuniones' : ($c['dias_ultima'] === 0 ? 'hoy' : 'hace ' . $c['dias_ultima'] . ' días') ?></small></a>
                <a href="index.php?view=reuniones&comite=<?= $urlId ?>" class="<?= !$c['proxima'] ? 'is-pendiente' : '' ?>"><i class="ti ti-calendar-event"></i><span>Próxima</span><b><?= $c['proxima'] ? $catFecha($c['proxima']) : 'Sin programar' ?></b><small><?= $c['dias_proxima'] === null ? 'programar reunión' : ($c['dias_proxima'] === 0 ? 'hoy' : 'en ' . $c['dias_proxima'] . ' días') ?></small></a>
            </div>

            <?php if ($c['alertas']): ?>
                <ul class="cat-alertas"><?php foreach ($c['alertas'] as $texto): ?><li><?= e($texto) ?></li><?php endforeach; ?></ul>
            <?php elseif ((int) $c['activo']): ?>
                <p class="cat-ok"><i class="ti ti-circle-check"></i> Organizado y con actividad al día.</p>
            <?php endif; ?>

            <footer>
                <a href="index.php?view=contactos&comite=<?= $urlId ?>"><i class="ti ti-users"></i> Integrantes</a>
                <a href="index.php?view=reuniones&comite=<?= $urlId ?>"><i class="ti ti-calendar"></i> Reuniones</a>
                <a href="index.php?view=acuerdos&comite=<?= $urlId ?>"><i class="ti ti-list-check"></i> Pasos</a>
            </footer>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($resultados && $vistaCatalogo === 'tabla'): ?>
    <div class="catalogo-lista">
        <table>
            <thead><tr><th>Comité</th><th class="num">Integrantes</th><th class="num">Reuniones</th><th class="num">Asistencia</th><th class="num">Pasos abiertos</th><th>Última / próxima reunión</th><th>Situación</th><?php if ($gestionar): ?><th class="acc">Acciones</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($resultados as $c): $urlId=urlencode($c['id']); ?>
                <tr class="<?= !(int)$c['activo']?'catalogo-inactivo':'' ?>">
                    <td>
                        <div class="catalogo-nombre">
                            <?php if ($c['logo']): ?><img class="catalogo-logo" src="<?= e($c['logo']) ?>" alt="Logo de <?= e($c['nombre']) ?>"><?php else: ?><span class="catalogo-logo catalogo-icon"><i class="ti ti-affiliate"></i></span><?php endif; ?>
                            <div class="catalogo-title">
                                <strong><?= e($c['nombre']) ?></strong>
                                <small><?= e($c['periodicidad']) ?> · Líder: <span class="<?= !$c['lider_nombre']?'catalogo-alerta':'' ?>"><?= e($c['lider_nombre'] ?: 'sin asignar') ?></span></small>
                                <?php if ($c['descripcion']): ?><small class="catalogo-descripcion" title="<?= e($c['descripcion']) ?>"><?= e($c['descripcion']) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="num"><a class="catalogo-num" href="index.php?view=contactos&comite=<?= $urlId ?>" title="Ver integrantes"><?= count($c['integrantes_lista']) ?></a></td>
                    <td class="num"><a class="catalogo-num" href="index.php?view=reuniones&comite=<?= $urlId ?>" title="Realizadas en 12 meses<?= $c['esperadas'] ? ' de ' . $c['esperadas'] . ' esperadas' : '' ?>"><?= $c['realizadas12'] ?><?= $c['esperadas'] ? '/' . $c['esperadas'] : '' ?></a></td>
                    <td class="num"><span class="catalogo-num <?= $catTono($c['asistencia']) ?>"><?= $catPct($c['asistencia']) ?></span></td>
                    <td class="num"><a class="catalogo-num <?= $c['vencidos']?'is-danger':'' ?>" href="index.php?view=acuerdos&comite=<?= $urlId ?>" title="Ver próximos pasos"><?= (int)$c['abiertos'] ?></a><?php if ($c['vencidos']): ?><small class="catalogo-peligro"><?= (int)$c['vencidos'] ?> vencido(s)</small><?php endif; ?></td>
                    <td class="catalogo-fechas">
                        <span><i class="ti ti-calendar-check"></i> <?= $c['ultima'] ? date('d/m/Y',strtotime($c['ultima'])) : '—' ?></span>
                        <span class="<?= !$c['proxima']?'catalogo-alerta':'' ?>"><i class="ti ti-calendar-event"></i> <?= $c['proxima'] ? date('d/m/Y',strtotime($c['proxima'])) : 'Sin programar' ?></span>
                    </td>
                    <td><span class="cat-salud salud-<?= e($c['salud']) ?>" title="<?= e(implode(' · ', $c['alertas'])) ?>"><i class="ti <?= $catSalud[$c['salud']][1] ?>"></i> <?= e($catSalud[$c['salud']][0]) ?></span></td>
                    <?php if ($gestionar): ?><td class="acc"><?= $catMenu($c) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if (!$resultados): ?><div class="catalogo-vacio">No hay comités con esos filtros.</div><?php endif; ?>

    <?php if ($seleccion !== null && $gestionar):
        $id = (string) ($seleccion['id'] ?? '');
        $actuales = $miembros[$id] ?? [];
        if ($flashError !== '' && ($_POST['accion'] ?? '') === 'grupo_guardar') { $actuales = array_map('intval',(array)($_POST['integrantes'] ?? [])); }
    ?>
    <div class="catalogo-overlay">
        <section class="catalogo-dialog" role="dialog" aria-modal="true" aria-labelledby="catalogo-modal-title">
            <header><h2 id="catalogo-modal-title"><?= $modalMiembros ? 'Agregar integrantes · ' . e($seleccion['nombre']) : ($id ? 'Editar grupo' : 'Nuevo grupo') ?></h2><a href="index.php?view=comites" aria-label="Cerrar"><i class="ti ti-x"></i></a></header>
            <?php if ($flashError): ?><div class="alert error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
            <?php if (!$modalMiembros): ?>
            <form method="post" enctype="multipart/form-data" class="catalogo-form">
                <?= $csrf ?><input type="hidden" name="accion" value="grupo_guardar"><input type="hidden" name="id" value="<?= e($id) ?>">
                <div class="catalogo-fields">
                    <label>Nombre del comité o grupo<input class="form-control" name="nombre" maxlength="160" required value="<?= e($seleccion['nombre']) ?>" placeholder="Ej. Comité de Riego"></label>
                    <label>Periodicidad de reuniones<select class="form-control" name="periodicidad"><?php foreach (['Mensual','Bimestral','Trimestral','Semestral','Según necesidad'] as $op): ?><option <?= ($seleccion['periodicidad'] ?? 'Mensual')===$op?'selected':'' ?>><?= e($op) ?></option><?php endforeach; ?></select></label>
                    <label>Líder del comité<select class="form-control" name="lider_id" id="catalogo-lider"><option value="">Sin asignar</option><?php foreach ($directorio as $contacto): if ($contacto['estado'] !== 'Activo') continue; ?><option value="<?= (int)$contacto['id'] ?>" <?= (int)($seleccion['lider_id'] ?? 0)===(int)$contacto['id']?'selected':'' ?>><?= e($contacto['nombre'].' · '.$contacto['empresa']) ?></option><?php endforeach; ?></select><small>Se agrega automáticamente como integrante.</small></label>
                    <label>Estado<select class="form-control" name="estado"><option <?= (int)$seleccion['activo']?'selected':'' ?>>Activo</option><option <?= !(int)$seleccion['activo']?'selected':'' ?>>Inactivo</option></select></label>
                    <div class="catalogo-wide"><label>Logo <small>PNG, JPG, SVG o WebP, hasta 2 MB.</small><input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp" id="catalogo-logo-input"></label><img id="catalogo-logo-preview" class="catalogo-preview" src="<?= e($seleccion['logo'] ?? '') ?>" alt="Vista previa del logo" <?= empty($seleccion['logo'])?'hidden':'' ?>><label><input type="checkbox" name="quitar_logo" value="1"> Quitar logo actual</label></div>
                    <label class="catalogo-wide">Objetivo o descripción<textarea class="form-control" rows="2" name="descripcion"><?= e($seleccion['descripcion'] ?? '') ?></textarea></label>
                </div>
                <label>Integrantes (<span id="catalogo-total">0</span>)</label>
                <input class="form-control" type="search" data-catalogo-search="catalogo-seleccion" placeholder="Buscar contacto..." aria-label="Buscar integrante">
                <div class="catalogo-contactos" id="catalogo-seleccion">
                    <?php foreach ($directorio as $contacto): if ($contacto['estado'] !== 'Activo') continue; ?><label class="catalogo-contacto" data-catalogo-row><input type="checkbox" name="integrantes[]" value="<?= (int)$contacto['id'] ?>" <?= in_array((int)$contacto['id'],$actuales,true)?'checked':'' ?>><span><?= e($contacto['nombre']) ?><small><?= e($contacto['cargo'].' · '.$contacto['empresa']) ?></small></span><span data-lider-tag hidden class="catalogo-badge catalogo-verde">Líder</span></label><?php endforeach; ?>
                    <?php if (!$directorio): ?><p class="catalogo-vacio">Sin contactos. Agréguelos primero en el Directorio.</p><?php endif; ?>
                </div>
                <div class="catalogo-actions"><a class="btn btn-outline" href="index.php?view=comites">Cancelar</a><button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy"></i> Guardar grupo</button></div>
            </form>
            <?php else: ?>
            <nav class="catalogo-tabs" aria-label="Agregar integrantes"><button type="button" data-catalogo-tab="directorio" class="active">Del directorio</button><button type="button" data-catalogo-tab="nuevo">Registrar nuevo contacto</button><button type="button" data-catalogo-tab="cargar">Cargar lista (Excel)</button></nav>
            <div data-catalogo-panel="directorio">
                <input class="form-control" type="search" data-catalogo-search="catalogo-disponibles" placeholder="Buscar en el directorio por nombre, cargo, empresa o correo..." aria-label="Buscar en el directorio">
                <div class="catalogo-contactos" id="catalogo-disponibles">
                    <?php $disponibles=0; foreach ($directorio as $contacto): if ($contacto['estado'] !== 'Activo' || in_array((int)$contacto['id'],$actuales,true)) continue; $disponibles++; ?>
                    <form method="post" class="catalogo-contacto" data-catalogo-row><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="contacto_id" value="<?= (int)$contacto['id'] ?>"><span><?= e($contacto['nombre']) ?><small><?= e($contacto['cargo'].' · '.$contacto['empresa'].' · '.$contacto['email']) ?></small></span><button class="btn btn-outline" name="accion" value="grupo_integrante_agregar">+ Agregar</button></form>
                    <?php endforeach; if (!$disponibles): ?><p class="catalogo-vacio">Sin coincidencias. Use “Registrar nuevo contacto”.</p><?php endif; ?>
                </div>
            </div>
            <form method="post" data-catalogo-panel="nuevo" hidden><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="accion" value="grupo_integrante_nuevo">
                <div class="catalogo-fields"><?php foreach (['nombre'=>'Nombre completo','email'=>'Correo','cargo'=>'Cargo','empresa'=>'Empresa / ingenio','area'=>'Área','telefono'=>'Teléfono'] as $key=>$label): ?><label><?= $label ?><input class="form-control" name="<?= $key ?>" type="<?= $key==='email'?'email':'text' ?>" maxlength="<?= $key==='telefono'?'40':'180' ?>" <?= $key==='nombre'?'required':'' ?> value="<?= ($flashError && ($_POST['accion'] ?? '')==='grupo_integrante_nuevo')?e((string)($_POST[$key] ?? '')):'' ?>"></label><?php endforeach; ?></div>
                <div class="catalogo-actions"><button class="btn btn-primary">Registrar y agregar al comité</button></div>
            </form>
            <form method="post" enctype="multipart/form-data" data-catalogo-panel="cargar" hidden><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="accion" value="grupo_integrante_importar">
                <p>Selecciona una lista Excel (.xlsx) o CSV. La primera fila debe incluir: Nombre, Correo, Cargo, Empresa, Área y Teléfono. Solo Nombre es obligatorio. Los correos existentes se vinculan al comité sin duplicar el contacto.</p>
                <label class="catalogo-upload">Cargar lista<input class="form-control" type="file" name="lista" accept=".xlsx,.csv" required></label>
                <div class="catalogo-actions"><button class="btn btn-primary">Cargar y agregar integrantes</button></div>
            </form>
            <div class="catalogo-miembros"><h3>Integrantes actuales (<?= count($actuales) ?>)</h3><div class="catalogo-chips">
                <?php foreach ($directorio as $contacto): if (!in_array((int)$contacto['id'],$actuales,true)) continue; ?><form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="contacto_id" value="<?= (int)$contacto['id'] ?>"><span><?= e($contacto['nombre']) ?></span><button name="accion" value="grupo_integrante_quitar" aria-label="Quitar <?= e($contacto['nombre']) ?>">×</button></form><?php endforeach; ?>
                <?php if (!$actuales): ?><span>Sin integrantes.</span><?php endif; ?>
            </div></div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</section>
<script src="assets/js/catalogo.js?v=<?= @filemtime(__DIR__ . '/../assets/js/catalogo.js') ?>" defer></script>
