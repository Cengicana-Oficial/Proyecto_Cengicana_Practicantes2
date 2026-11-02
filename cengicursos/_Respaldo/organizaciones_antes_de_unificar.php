<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_organizaciones();
date_default_timezone_set('America/Guatemala');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_organizaciones();
$veTodo = cengi_ve_todo_por_rol_o_ingenio();

$tiposValidos = ['Ingenio azucarero', 'Universidad', 'Empresa proveedora', 'Institucion tecnica', 'Otra'];
const CENGI_ORG_MENSAJES = [
    'creada' => 'Organización registrada.',
    'actualizada' => 'Organización actualizada.',
    'estado' => 'Estado de la organización actualizado.',
];

function cengi_org_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_org_tipo_label($tipo)
{
    return $tipo === 'Institucion tecnica' ? 'Institución técnica' : $tipo;
}

function cengi_org_iniciales($nombre)
{
    $partes = array_slice(array_filter(preg_split('/\s+/', trim((string) $nombre))), 0, 2);
    $txt = '';
    foreach ($partes as $p) {
        $txt .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $txt ?: 'OR';
}

/** Semaforo de la paleta para asistencia y nota (80 / 60). */
function cengi_org_nivel($valor)
{
    return $valor === null ? '' : ($valor >= 80 ? 'is-bien' : ($valor >= 60 ? 'is-medio' : 'is-bajo'));
}

$mensaje = '';
$mensajeTipo = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($accion === 'crear' || $accion === 'actualizar') {
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $tipo = in_array($_POST['tipo'] ?? '', $tiposValidos, true) ? $_POST['tipo'] : 'Otra';
            $contactoNombre = trim((string) ($_POST['contacto_nombre'] ?? ''));
            $contactoCorreo = trim((string) ($_POST['contacto_correo'] ?? ''));
            $contactoTelefono = trim((string) ($_POST['contacto_telefono'] ?? ''));
            $ingenioId = (int) ($_POST['ingenio_id'] ?? 0) ?: null;
            $estado = (int) ($_POST['estado'] ?? 1) === 0 ? 0 : 1;
            if ($nombre === '' || mb_strlen($nombre) > 255) {
                throw new InvalidArgumentException('Escribe el nombre de la organización.');
            }
            if ($contactoCorreo !== '' && !filter_var($contactoCorreo, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo de contacto no es válido.');
            }
            if (mb_strlen($contactoNombre) > 255 || mb_strlen($contactoTelefono) > 50) {
                throw new InvalidArgumentException('Revisa el nombre (hasta 255) y el teléfono (hasta 50 caracteres) del contacto.');
            }
            $dup = $db->prepare('SELECT nombre FROM organizaciones WHERE (LOWER(nombre) = LOWER(?) OR (? IS NOT NULL AND ingenio_id = ?)) AND id <> ? LIMIT 1');
            $dup->execute([$nombre, $ingenioId, $ingenioId, $id]);
            if ($otra = $dup->fetchColumn()) {
                throw new InvalidArgumentException('Ya existe la organización “' . $otra . '” con ese nombre o vinculada a ese ingenio.');
            }
            $valores = [$nombre, $tipo, $contactoNombre, $contactoCorreo, $contactoTelefono, $ingenioId, $estado];
            if ($accion === 'crear') {
                $db->prepare('INSERT INTO organizaciones (nombre, tipo, contacto_nombre, contacto_correo, contacto_telefono, ingenio_id, estado) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($valores);
                $msg = 'creada';
            } else {
                $db->prepare('UPDATE organizaciones SET nombre = ?, tipo = ?, contacto_nombre = ?, contacto_correo = ?, contacto_telefono = ?, ingenio_id = ?, estado = ? WHERE id = ?')
                    ->execute(array_merge($valores, [$id]));
                $msg = 'actualizada';
            }
        } elseif ($accion === 'toggle_estado' && $id > 0) {
            $db->prepare('UPDATE organizaciones SET estado = 1 - estado WHERE id = ?')->execute([$id]);
            $msg = 'estado';
        } elseif ($accion === 'sincronizar_ingenios') {
            // Ingenios del catalogo sin organizacion: se registran como "Ingenio azucarero" activos.
            $n = $db->exec("INSERT INTO organizaciones (nombre, tipo, ingenio_id, estado)
                SELECT i.nombre_ingenios, 'Ingenio azucarero', i.id, 1 FROM ingenios i
                WHERE NOT EXISTS (SELECT 1 FROM organizaciones o WHERE o.ingenio_id = i.id)");
            header('Location: organizaciones.php?msg=sincronizadas&n=' . (int) $n);
            exit;
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        header('Location: organizaciones.php?msg=' . $msg . ($id ? '#org-' . $id : ''));
        exit;
    } catch (InvalidArgumentException $e) {
        $mensaje = $e->getMessage();
        $mensajeTipo = 'error';
    } catch (PDOException $e) {
        error_log('Organizaciones: ' . $e->getMessage());
        $mensaje = 'No fue posible guardar la organización. Intenta de nuevo.';
        $mensajeTipo = 'error';
    }
}
if ($mensaje === '' && ($_GET['msg'] ?? '') === 'sincronizadas') {
    $mensaje = (int) ($_GET['n'] ?? 0) . ' ingenio(s) registrados como organización. Completa su contacto con el botón Editar.';
} elseif ($mensaje === '' && isset(CENGI_ORG_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = CENGI_ORG_MENSAJES[$_GET['msg']];
}

// ---------------------------------------------------------------- datos
$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtroTipo = in_array($_GET['tipo'] ?? '', $tiposValidos, true) ? $_GET['tipo'] : '';
$filtroEstado = in_array($_GET['estado'] ?? '', ['activas', 'inactivas'], true) ? $_GET['estado'] : '';

$condiciones = [];
$params = [];
if (!$veTodo) {
    $condiciones[] = '(o.ingenio_id = ? OR o.ingenio_id IS NULL)';
    $params[] = cengi_ingenio_id_actual();
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$stmt = $db->prepare("SELECT o.*, i.nombre_ingenios FROM organizaciones o LEFT JOIN ingenios i ON i.id = o.ingenio_id {$where} ORDER BY o.estado DESC, o.nombre");
$stmt->execute($params);
$todas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Participacion real de los colaboradores de cada ingenio (no el ingenio sede del curso).
$numerico = "REGEXP '^[0-9]+(\\\\.[0-9]+)?$'";
$colab = [];
foreach ($db->query('SELECT ingenio_id, COUNT(*) total, SUM(estado_participantes = 1) activos FROM participantes WHERE ingenio_id IS NOT NULL GROUP BY ingenio_id') as $r) {
    $colab[(int) $r['ingenio_id']] = $r;
}
$resumenIng = [];
foreach ($db->query("SELECT p.ingenio_id, COUNT(DISTINCT a.id) asignaciones, COUNT(DISTINCT a.cursos_id) cursos,
        COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.cursos_id END) cursos_activos,
        AVG(CASE WHEN cc.asistencia $numerico THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) asistencia,
        AVG(CASE WHEN cc.posevaluacion $numerico THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) nota,
        COUNT(DISTINCT CASE WHEN NULLIF(cc.diploma, '') IS NOT NULL OR d.id IS NOT NULL THEN a.id END) diplomas,
        MAX(c.inicio) ultima
    FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id AND p.estado_participantes = 1
    INNER JOIN cursos c ON c.id = a.cursos_id LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
    WHERE a.estado_asignaciones = 1 AND p.ingenio_id IS NOT NULL GROUP BY p.ingenio_id") as $r) {
    $resumenIng[(int) $r['ingenio_id']] = $r;
}
$cursosIng = [];
foreach ($db->query("SELECT p.ingenio_id, c.id, c.codigo_curso, c.nombre_cursos, c.inicio, c.fin, COUNT(DISTINCT a.id) inscritos,
        AVG(CASE WHEN cc.asistencia $numerico THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) asistencia
    FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id AND p.estado_participantes = 1
    INNER JOIN cursos c ON c.id = a.cursos_id LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    WHERE a.estado_asignaciones = 1 AND p.ingenio_id IS NOT NULL
    GROUP BY p.ingenio_id, c.id, c.codigo_curso, c.nombre_cursos, c.inicio, c.fin
    ORDER BY (c.fin IS NULL OR c.fin >= CURDATE()) DESC, c.inicio DESC") as $r) {
    $cursosIng[(int) $r['ingenio_id']][] = $r;
}

$hoy = date('Y-m-d');
foreach ($todas as &$org) {
    $iid = (int) $org['ingenio_id'];
    $res = $resumenIng[$iid] ?? [];
    $org['m'] = [
        'colab_total' => (int) ($colab[$iid]['total'] ?? 0),
        'colab_activos' => (int) ($colab[$iid]['activos'] ?? 0),
        'cursos' => (int) ($res['cursos'] ?? 0),
        'cursos_activos' => (int) ($res['cursos_activos'] ?? 0),
        'asignaciones' => (int) ($res['asignaciones'] ?? 0),
        'asistencia' => isset($res['asistencia']) ? (float) $res['asistencia'] : null,
        'nota' => isset($res['nota']) ? (float) $res['nota'] : null,
        'diplomas' => (int) ($res['diplomas'] ?? 0),
        'ultima' => $res['ultima'] ?? null,
    ];
}
unset($org);

// KPIs del total visible (antes de los filtros de busqueda).
$kpi = ['total' => count($todas), 'activas' => 0, 'vinculadas' => 0, 'colab' => 0, 'asignaciones' => 0, 'diplomas' => 0];
$notas = [];
foreach ($todas as $org) {
    $kpi['activas'] += (int) $org['estado'] === 1 ? 1 : 0;
    if ($org['ingenio_id']) {
        $kpi['vinculadas']++;
        $kpi['colab'] += $org['m']['colab_activos'];
        $kpi['asignaciones'] += $org['m']['asignaciones'];
        $kpi['diplomas'] += $org['m']['diplomas'];
        if ($org['m']['nota'] !== null) {
            $notas[] = $org['m']['nota'];
        }
    }
}
$kpi['nota'] = $notas ? array_sum($notas) / count($notas) : null;
$porTipo = array_count_values(array_column($todas, 'tipo'));

$termino = mb_strtolower($busqueda, 'UTF-8');
$organizaciones = array_values(array_filter($todas, static function ($o) use ($termino, $filtroTipo, $filtroEstado) {
    if ($filtroTipo !== '' && $o['tipo'] !== $filtroTipo) return false;
    if ($filtroEstado === 'activas' && (int) $o['estado'] !== 1) return false;
    if ($filtroEstado === 'inactivas' && (int) $o['estado'] === 1) return false;
    if ($termino === '') return true;
    return mb_strpos(mb_strtolower($o['nombre'] . ' ' . $o['contacto_nombre'] . ' ' . $o['contacto_correo'] . ' ' . $o['nombre_ingenios'], 'UTF-8'), $termino) !== false;
}));

$ingenios = $db->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_ASSOC);
$ingeniosSinOrg = $veTodo ? (int) $db->query('SELECT COUNT(*) FROM ingenios i WHERE NOT EXISTS (SELECT 1 FROM organizaciones o WHERE o.ingenio_id = i.id)')->fetchColumn() : 0;
$urlFiltro = static function (array $cambios) use ($busqueda, $filtroTipo, $filtroEstado) {
    return 'organizaciones.php?' . http_build_query(array_filter(array_merge(['q' => $busqueda, 'tipo' => $filtroTipo, 'estado' => $filtroEstado], $cambios), 'strlen'));
};
?>
<!DOCTYPE html>
<html lang="es">
<?php include 'head.php'; ?>
<title>Organizaciones</title>
<link rel="stylesheet" href="css/organizaciones.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/organizaciones.css'); ?>">
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page org-page">
    <header class="org-hero">
        <div>
            <small class="org-hero-k">Instituciones y aliados</small>
            <h1>Organizaciones</h1>
            <p>Ingenios, universidades, proveedores e instituciones técnicas: su contacto y la participación de sus colaboradores en las capacitaciones.</p>
        </div>
        <?php if ($puedeGestionar): ?>
            <button type="button" class="org-btn-nueva" data-toggle="modal" data-target="#org-form-modal" data-org-modal="create"><span class="glyphicon glyphicon-plus"></span> Nueva organización</button>
        <?php endif; ?>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>" role="<?php echo $mensajeTipo === 'error' ? 'alert' : 'status'; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_org_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <section class="org-kpis" aria-label="Resumen">
        <div class="is-verde"><span class="glyphicon glyphicon-briefcase"></span><b><?php echo $kpi['activas']; ?> <small>/ <?php echo $kpi['total']; ?></small></b><em>Organizaciones activas</em></div>
        <div class="is-verde2"><span class="glyphicon glyphicon-link"></span><b><?php echo $kpi['vinculadas']; ?></b><em>Vinculadas a un ingenio</em></div>
        <div class="is-amarillo"><span class="glyphicon glyphicon-user"></span><b><?php echo number_format($kpi['colab']); ?></b><em>Colaboradores activos</em></div>
        <div class="is-verde"><span class="glyphicon glyphicon-education"></span><b><?php echo number_format($kpi['asignaciones']); ?></b><em>Inscripciones en cursos</em></div>
        <div class="is-gris <?php echo cengi_org_nivel($kpi['nota']); ?>"><span class="glyphicon glyphicon-stats"></span><b><?php echo $kpi['nota'] !== null ? number_format($kpi['nota'], 1) : '—'; ?></b><em>Nota final promedio</em></div>
        <div class="is-naranja"><span class="glyphicon glyphicon-certificate"></span><b><?php echo number_format($kpi['diplomas']); ?></b><em>Diplomas obtenidos</em></div>
    </section>

    <?php if ($puedeGestionar && $ingeniosSinOrg): ?>
        <div class="org-aviso"><span class="glyphicon glyphicon-info-sign"></span>
            <span><b><?php echo $ingeniosSinOrg; ?> ingenio(s)</b> del catálogo aún no tienen su organización: sin ella no aparecen aquí ni se les puede registrar un contacto.</span>
            <form method="post"><input type="hidden" name="accion" value="sincronizar_ingenios"><button class="btn btn-default btn-sm">Registrarlos ahora</button></form>
        </div>
    <?php endif; ?>

    <section class="org-filtros">
        <form method="get" class="org-filtros-form">
            <label class="org-buscar"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><span class="sr-only">Buscar</span>
                <input type="search" name="q" value="<?php echo cengi_org_html($busqueda); ?>" placeholder="Buscar por nombre, contacto, correo o ingenio…"></label>
            <select name="tipo" aria-label="Filtrar por tipo" onchange="this.form.submit()">
                <option value="">Todos los tipos</option>
                <?php foreach ($tiposValidos as $t): ?><option value="<?php echo cengi_org_html($t); ?>" <?php echo $filtroTipo === $t ? 'selected' : ''; ?>><?php echo cengi_org_html(cengi_org_tipo_label($t)); ?> (<?php echo (int) ($porTipo[$t] ?? 0); ?>)</option><?php endforeach; ?>
            </select>
            <?php if ($filtroEstado !== ''): ?><input type="hidden" name="estado" value="<?php echo cengi_org_html($filtroEstado); ?>"><?php endif; ?>
            <button class="btn btn-default">Buscar</button>
        </form>
        <nav class="org-chips" aria-label="Estado">
            <a href="<?php echo cengi_org_html($urlFiltro(['estado' => ''])); ?>" class="<?php echo $filtroEstado === '' ? 'is-activo' : ''; ?>">Todas <b><?php echo $kpi['total']; ?></b></a>
            <a href="<?php echo cengi_org_html($urlFiltro(['estado' => 'activas'])); ?>" class="<?php echo $filtroEstado === 'activas' ? 'is-activo' : ''; ?>">Activas <b><?php echo $kpi['activas']; ?></b></a>
            <a href="<?php echo cengi_org_html($urlFiltro(['estado' => 'inactivas'])); ?>" class="<?php echo $filtroEstado === 'inactivas' ? 'is-activo' : ''; ?>">Inactivas <b><?php echo $kpi['total'] - $kpi['activas']; ?></b></a>
            <?php if ($busqueda !== '' || $filtroTipo !== '' || $filtroEstado !== ''): ?><a href="organizaciones.php" class="org-limpiar">Limpiar filtros</a><?php endif; ?>
        </nav>
    </section>

    <p class="org-conteo"><?php echo count($organizaciones); ?> organización(es)<?php echo count($organizaciones) !== $kpi['total'] ? ' de ' . $kpi['total'] : ''; ?></p>

    <section class="org-grid org-lista" aria-label="Organizaciones">
        <?php if ($organizaciones): ?><div class="org-lista-cab" aria-hidden="true"><span>Organización</span><span>Contacto</span><span>Colaboradores · cursos · asistencia · nota</span><span>Acciones</span></div><?php endif; ?>
        <?php if (!$organizaciones): ?>
            <div class="org-vacio"><span class="glyphicon glyphicon-briefcase"></span><strong>No hay organizaciones con esos filtros</strong><small>Prueba con otra búsqueda<?php echo $puedeGestionar ? ' o registra una organización nueva' : ''; ?>.</small></div>
        <?php endif; ?>
        <?php foreach ($organizaciones as $org):
            $iid = (int) $org['ingenio_id'];
            $m = $org['m'];
            $activa = (int) $org['estado'] === 1;
            $tipoClase = ['Ingenio azucarero' => 'is-ingenio', 'Universidad' => 'is-universidad', 'Empresa proveedora' => 'is-empresa', 'Institucion tecnica' => 'is-tecnica'][$org['tipo']] ?? 'is-otra';
            $ficha = [
                'id' => (int) $org['id'], 'nombre' => $org['nombre'], 'tipo' => cengi_org_tipo_label($org['tipo']), 'ingenio' => $org['nombre_ingenios'] ?: '', 'ingenio_id' => $iid,
                'contacto' => $org['contacto_nombre'], 'correo' => $org['contacto_correo'], 'telefono' => $org['contacto_telefono'], 'activa' => $activa, 'm' => $m,
                'cursos' => array_map(static function ($c) use ($hoy) {
                    $fin = (string) $c['fin'];
                    return ['nombre' => $c['nombre_cursos'], 'codigo' => $c['codigo_curso'], 'inscritos' => (int) $c['inscritos'],
                        'asistencia' => $c['asistencia'] !== null ? round((float) $c['asistencia']) : null,
                        'estado' => $fin !== '' && $fin !== '0000-00-00' && $fin < $hoy ? 'Finalizado' : ((string) $c['inicio'] > $hoy ? 'Próximo' : 'En curso')];
                }, array_slice($cursosIng[$iid] ?? [], 0, 12)),
            ];
        ?>
            <article class="org-card <?php echo $activa ? '' : 'is-inactiva'; ?>" id="org-<?php echo (int) $org['id']; ?>">
                <header class="org-card-cab">
                    <span class="org-avatar <?php echo $tipoClase; ?>"><?php echo cengi_org_html(cengi_org_iniciales($org['nombre'])); ?></span>
                    <div class="org-card-tit">
                        <h2><?php echo cengi_org_html($org['nombre']); ?></h2>
                        <span class="org-tipo <?php echo $tipoClase; ?>"><?php echo cengi_org_html(cengi_org_tipo_label($org['tipo'])); ?></span>
                        <?php if ($org['nombre_ingenios'] && $org['nombre_ingenios'] !== $org['nombre']): ?><small class="org-ingenio"><span class="glyphicon glyphicon-link"></span> <?php echo cengi_org_html($org['nombre_ingenios']); ?></small><?php endif; ?>
                    </div>
                    <span class="cengi-status-badge <?php echo $activa ? 'is-active' : 'is-neutral'; ?>"><i></i><?php echo $activa ? 'Activa' : 'Inactiva'; ?></span>
                </header>
                <div class="org-contacto">
                    <?php if ($org['contacto_nombre'] || $org['contacto_correo'] || $org['contacto_telefono']): ?>
                        <?php if ($org['contacto_nombre']): ?><span><span class="glyphicon glyphicon-user"></span> <?php echo cengi_org_html($org['contacto_nombre']); ?></span><?php endif; ?>
                        <?php if ($org['contacto_correo']): ?><a href="mailto:<?php echo cengi_org_html($org['contacto_correo']); ?>"><span class="glyphicon glyphicon-envelope"></span> <?php echo cengi_org_html($org['contacto_correo']); ?></a><?php endif; ?>
                        <?php if ($org['contacto_telefono']): ?><a href="tel:<?php echo cengi_org_html(preg_replace('/[^0-9+]/', '', $org['contacto_telefono'])); ?>"><span class="glyphicon glyphicon-earphone"></span> <?php echo cengi_org_html($org['contacto_telefono']); ?></a><?php endif; ?>
                    <?php else: ?><span class="org-falta"><span class="glyphicon glyphicon-exclamation-sign"></span> Sin contacto registrado</span><?php endif; ?>
                </div>
                <?php if ($iid): ?>
                    <div class="org-metricas">
                        <div><b><?php echo $m['colab_activos']; ?></b><span>Colaboradores activos</span></div>
                        <div><b><?php echo $m['cursos']; ?></b><span>Cursos<?php echo $m['cursos_activos'] ? ' · ' . $m['cursos_activos'] . ' en curso' : ''; ?></span></div>
                        <div><b class="org-val <?php echo cengi_org_nivel($m['asistencia']); ?>"><?php echo $m['asistencia'] !== null ? round($m['asistencia']) . '%' : '—'; ?></b><span>Asistencia</span></div>
                        <div><b class="org-val <?php echo cengi_org_nivel($m['nota']); ?>"><?php echo $m['nota'] !== null ? number_format($m['nota'], 1) : '—'; ?></b><span>Nota final</span></div>
                    </div>
                <?php else: ?>
                    <p class="org-sin-ingenio">Sin ingenio vinculado: no se miden colaboradores ni resultados.<?php echo $puedeGestionar ? ' Vincúlalo al editar.' : ''; ?></p>
                <?php endif; ?>
                <footer class="org-card-pie"><details class="cengi-acc">
                    <summary class="cengi-course-actions-toggle"><span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span></summary>
                    <div class="cengi-course-actions-panel cengi-acc-panel">
                    <div class="course-actions-heading"><strong>Gestionar organización</strong><small><?php echo cengi_org_html($org['nombre']); ?></small></div>
                    <button type="button" class="org-btn is-ver" data-toggle="modal" data-target="#org-detail-modal" data-ficha="<?php echo cengi_org_html(json_encode($ficha, JSON_UNESCAPED_UNICODE)); ?>"><span class="glyphicon glyphicon-eye-open"></span> Ficha</button>
                    <?php if ($iid): ?><a class="org-btn" href="dashboard_ingenio.php?ingenio_id=<?php echo $iid; ?>"><span class="glyphicon glyphicon-dashboard"></span> Seguimiento</a><?php endif; ?>
                    <?php if ($puedeGestionar): ?>
                        <button type="button" class="org-btn" data-toggle="modal" data-target="#org-form-modal" data-org-modal="edit"
                            data-id="<?php echo (int) $org['id']; ?>" data-name="<?php echo cengi_org_html($org['nombre']); ?>" data-type="<?php echo cengi_org_html($org['tipo']); ?>"
                            data-ingenio="<?php echo $iid; ?>" data-estado="<?php echo (int) $org['estado']; ?>" data-contact-name="<?php echo cengi_org_html($org['contacto_nombre']); ?>"
                            data-contact-email="<?php echo cengi_org_html($org['contacto_correo']); ?>" data-contact-phone="<?php echo cengi_org_html($org['contacto_telefono']); ?>"><span class="glyphicon glyphicon-pencil"></span> Editar</button>
                        <form method="post" class="org-inline" onsubmit="return confirm('<?php echo $activa ? '¿Desactivar' : '¿Activar'; ?> “<?php echo cengi_org_html(addslashes($org['nombre'])); ?>”?');">
                            <input type="hidden" name="accion" value="toggle_estado"><input type="hidden" name="id" value="<?php echo (int) $org['id']; ?>">
                            <button class="org-btn <?php echo $activa ? 'is-desactivar' : 'is-activar'; ?>"><span class="glyphicon glyphicon-<?php echo $activa ? 'pause' : 'play'; ?>"></span> <?php echo $activa ? 'Desactivar' : 'Activar'; ?></button>
                        </form>
                    <?php endif; ?>
                    </div>
                </details></footer>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="org-permisos">
        <span class="glyphicon glyphicon-lock"></span>
        <div><b>Vista del usuario de ingenio:</b> ve solo su organización y sus propios colaboradores, cursos, asistencia y resultados; nunca datos de otras instituciones.</div>
    </section>
</main>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="org-form-modal" tabindex="-1" role="dialog" aria-labelledby="org-form-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="POST" id="org-form">
            <div class="modal-header org-modal-cab">
                <h4 class="modal-title" id="org-form-title">Nueva organización</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" id="org-form-accion" value="crear">
                <input type="hidden" name="id" id="org-form-id" value="">
                <div class="cengi-participant-modal-grid">
                    <div class="form-group"><label for="org-form-type">Tipo</label>
                        <select name="tipo" id="org-form-type" class="form-control">
                            <?php foreach ($tiposValidos as $t): ?><option value="<?php echo cengi_org_html($t); ?>"><?php echo cengi_org_html(cengi_org_tipo_label($t)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label for="org-form-ingenio">Ingenio vinculado <span class="text-muted">(opcional)</span></label>
                        <select name="ingenio_id" id="org-form-ingenio" class="form-control">
                            <option value="0">Ninguno</option>
                            <?php foreach ($ingenios as $ing): ?><option value="<?php echo (int) $ing['id']; ?>"><?php echo cengi_org_html($ing['nombre_ingenios']); ?></option><?php endforeach; ?>
                        </select>
                        <small class="text-muted">Permite medir a sus colaboradores en los cursos.</small>
                    </div>
                    <div class="form-group is-full"><label for="org-form-name">Nombre</label><input type="text" name="nombre" id="org-form-name" class="form-control" maxlength="255" placeholder="Ej. Ingenio Santa Ana" required></div>
                    <div class="form-group is-full"><label for="org-form-contact-name">Persona de contacto</label><input type="text" name="contacto_nombre" id="org-form-contact-name" class="form-control" maxlength="255" placeholder="Nombre y cargo"></div>
                    <div class="form-group"><label for="org-form-contact-email">Correo de contacto</label><input type="email" name="contacto_correo" id="org-form-contact-email" class="form-control" maxlength="255" placeholder="correo@organizacion.com"></div>
                    <div class="form-group"><label for="org-form-contact-phone">Teléfono de contacto</label><input type="tel" name="contacto_telefono" id="org-form-contact-phone" class="form-control" maxlength="50" placeholder="0000 0000"></div>
                    <div class="form-group"><label for="org-form-status">Estado</label>
                        <select name="estado" id="org-form-status" class="form-control"><option value="1">Activa</option><option value="0">Inactiva</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="org-form-submit">Guardar</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-org-detail-modal" id="org-detail-modal" tabindex="-1" role="dialog" aria-labelledby="org-detail-name">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="org-ficha-cab">
            <span class="org-avatar is-ingenio" id="org-detail-avatar">OR</span>
            <div><h4 id="org-detail-name">Organización</h4><p id="org-detail-sub"></p></div>
            <button type="button" class="org-ficha-cerrar" data-dismiss="modal" aria-label="Cerrar">&times;</button>
        </div>
        <div class="org-ficha-cuerpo">
            <div class="org-ficha-contacto" id="org-detail-contact"></div>
            <div class="org-metricas is-ficha" id="org-detail-stats"></div>
            <h5>Cursos con participación de sus colaboradores</h5>
            <div id="org-detail-course-list"></div>
        </div>
        <div class="org-ficha-pie">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            <a href="directorio_participantes.php" class="btn btn-default" id="org-detail-directory"><span class="glyphicon glyphicon-user"></span> Colaboradores</a>
            <a href="dashboard_ingenio.php" class="btn btn-primary" id="org-detail-followup"><span class="glyphicon glyphicon-dashboard"></span> Seguimiento completo</a>
        </div>
    </div></div>
</div>
<script>
(function () {
    'use strict';
    function esc(v) { var n = document.createElement('div'); n.textContent = v == null ? '' : String(v); return n.innerHTML; }
    function iniciales(nombre) { return (nombre || 'OR').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join(''); }
    function nivel(v) { return v === null || v === undefined ? '' : (v >= 80 ? 'is-bien' : (v >= 60 ? 'is-medio' : 'is-bajo')); }

    <?php if ($puedeGestionar): ?>
    $('#org-form-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, editar = t && t.getAttribute('data-org-modal') === 'edit', $ = function (id) { return document.getElementById(id); };
        $('org-form').reset();
        $('org-form-accion').value = editar ? 'actualizar' : 'crear';
        $('org-form-id').value = editar ? t.getAttribute('data-id') : '';
        $('org-form-title').textContent = editar ? 'Editar organización' : 'Nueva organización';
        $('org-form-status').value = '1';
        if (editar) {
            $('org-form-name').value = t.getAttribute('data-name') || '';
            $('org-form-type').value = t.getAttribute('data-type') || 'Otra';
            $('org-form-ingenio').value = t.getAttribute('data-ingenio') || '0';
            $('org-form-status').value = t.getAttribute('data-estado') || '1';
            $('org-form-contact-name').value = t.getAttribute('data-contact-name') || '';
            $('org-form-contact-email').value = t.getAttribute('data-contact-email') || '';
            $('org-form-contact-phone').value = t.getAttribute('data-contact-phone') || '';
        }
    });
    // Al elegir un ingenio en una organización nueva sin nombre, se propone su nombre.
    document.getElementById('org-form-ingenio').addEventListener('change', function () {
        var nombre = document.getElementById('org-form-name');
        if (!nombre.value.trim() && this.value !== '0') nombre.value = this.options[this.selectedIndex].text;
        if (this.value !== '0') document.getElementById('org-form-type').value = 'Ingenio azucarero';
    });
    <?php endif; ?>

    $('#org-detail-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, f = {};
        try { f = JSON.parse(t.getAttribute('data-ficha') || '{}'); } catch (ignore) { return; }
        var m = f.m || {};
        document.getElementById('org-detail-name').textContent = f.nombre || '';
        document.getElementById('org-detail-avatar').textContent = iniciales(f.nombre);
        document.getElementById('org-detail-sub').textContent = [f.tipo, f.ingenio && f.ingenio !== f.nombre ? 'Ingenio ' + f.ingenio : '', f.activa ? 'Activa' : 'Inactiva'].filter(Boolean).join(' · ');
        var contacto = [];
        if (f.contacto) contacto.push('<span><span class="glyphicon glyphicon-user"></span> ' + esc(f.contacto) + '</span>');
        if (f.correo) contacto.push('<a href="mailto:' + esc(f.correo) + '"><span class="glyphicon glyphicon-envelope"></span> ' + esc(f.correo) + '</a>');
        if (f.telefono) contacto.push('<a href="tel:' + esc(String(f.telefono).replace(/[^0-9+]/g, '')) + '"><span class="glyphicon glyphicon-earphone"></span> ' + esc(f.telefono) + '</a>');
        document.getElementById('org-detail-contact').innerHTML = contacto.length ? contacto.join('') : '<span class="org-falta">Sin contacto registrado</span>';
        var stats = document.getElementById('org-detail-stats');
        stats.innerHTML = f.ingenio_id ? [
            ['<b>' + (m.colab_activos || 0) + '</b>', 'Colaboradores activos (' + (m.colab_total || 0) + ' registrados)'],
            ['<b>' + (m.cursos || 0) + '</b>', 'Cursos · ' + (m.asignaciones || 0) + ' inscripciones'],
            ['<b class="org-val ' + nivel(m.asistencia) + '">' + (m.asistencia !== null ? Math.round(m.asistencia) + '%' : '—') + '</b>', 'Asistencia promedio'],
            ['<b class="org-val ' + nivel(m.nota) + '">' + (m.nota !== null ? m.nota.toFixed(1) : '—') + '</b>', 'Nota final promedio'],
            ['<b>' + (m.diplomas || 0) + '</b>', 'Diplomas obtenidos']
        ].map(function (s) { return '<div>' + s[0] + '<span>' + s[1] + '</span></div>'; }).join('') : '<p class="org-sin-ingenio">Sin ingenio vinculado: no hay colaboradores ni resultados que medir.</p>';
        var cursos = f.cursos || [];
        document.getElementById('org-detail-course-list').innerHTML = cursos.length ? cursos.map(function (c) {
            var est = c.estado === 'Finalizado' ? 'is-finished' : (c.estado === 'Próximo' ? 'is-upcoming' : 'is-active');
            return '<div class="org-curso"><div><strong>' + esc(c.nombre) + '</strong><small>' + esc(c.codigo || '') + '</small></div>'
                + '<span class="cengi-status-badge ' + est + '"><i></i>' + esc(c.estado) + '</span>'
                + '<span class="org-curso-n"><b>' + c.inscritos + '</b> inscrito(s)</span>'
                + '<span class="org-val ' + nivel(c.asistencia) + '">' + (c.asistencia !== null ? c.asistencia + '%' : '—') + '</span></div>';
        }).join('') : '<div class="org-vacio is-mini">Sus colaboradores aún no participan en cursos.</div>';
        var q = f.ingenio_id ? '?ingenio_id=' + encodeURIComponent(f.ingenio_id) : '';
        document.getElementById('org-detail-followup').href = 'dashboard_ingenio.php' + q;
        document.getElementById('org-detail-followup').style.display = f.ingenio_id ? '' : 'none';
        document.getElementById('org-detail-directory').href = 'directorio_participantes.php' + q;
        document.getElementById('org-detail-directory').style.display = f.ingenio_id ? '' : 'none';
    });

    // Resalta la organización recién guardada.
    if (location.hash && /^#org-\d+$/.test(location.hash)) {
        var card = document.querySelector(location.hash);
        if (card) { card.classList.add('is-destacada'); setTimeout(function () { card.classList.remove('is-destacada'); }, 2500); }
    }
})();
</script>
</body>
</html>
