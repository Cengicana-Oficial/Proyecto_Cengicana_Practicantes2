<?php
/**
 * Áreas técnicas: catálogo para clasificar cursos (cursos.area_tecnica) y planes de
 * Planificación (planificacion_cursos.area_tecnica). Ambos guardan el NOMBRE, por eso
 * renombrar, unificar o corregir el texto actualiza también cursos y planes.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$h = static function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};

/** Texto con tildes dañadas por doble codificación ("AgronomÃ­a" → "Agronomía"); null si no aplica. */
function cengi_area_texto_corregido($texto)
{
    if (!preg_match('/[ÃÂ][\x{0080}-\x{00BF}]/u', (string) $texto)) {
        return null;
    }
    $c = mb_convert_encoding((string) $texto, 'ISO-8859-1', 'UTF-8');
    return mb_check_encoding($c, 'UTF-8') && $c !== $texto ? $c : null;
}

/** Cambia el nombre en el catálogo, los cursos y los planes. */
function cengi_area_renombrar_uso(PDO $db, $anterior, $nuevo)
{
    $db->prepare('UPDATE cursos SET area_tecnica = ? WHERE area_tecnica = ?')->execute([$nuevo, $anterior]);
    $db->prepare('UPDATE planificacion_cursos SET area_tecnica = ? WHERE area_tecnica = ?')->execute([$nuevo, $anterior]);
}

function cengi_area_duplicada(PDO $db, $nombre, $id)
{
    $stmt = $db->prepare('SELECT nombre FROM areas_tecnicas WHERE ' . cengi_sql_texto_normalizado('nombre') . ' = ? AND id <> ?');
    $stmt->execute([cengi_texto_normalizado($nombre), (int) $id]);
    return $stmt->fetchColumn() ?: null;
}

// ------------------------------------------------------------ acciones (POST + redirección)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')));
    $msg = ['msg' => 'error'];
    try {
        $actual = null;
        if ($id > 0) {
            $stmt = $db->prepare('SELECT * FROM areas_tecnicas WHERE id = ?');
            $stmt->execute([$id]);
            $actual = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$actual) {
                throw new InvalidArgumentException('El área técnica ya no existe.');
            }
        }
        if ($accion === 'crear' || $accion === 'actualizar') {
            if (mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 120) {
                throw new InvalidArgumentException('Escribe un nombre de 2 a 120 caracteres.');
            }
            if ($otra = cengi_area_duplicada($db, $nombre, $id)) {
                throw new InvalidArgumentException('Ya existe el área “' . $otra . '” (sin importar mayúsculas ni tildes). Si son la misma, usa “Unificar”.');
            }
            if ($accion === 'crear') {
                $db->prepare('INSERT INTO areas_tecnicas (nombre, estado) VALUES (?, 1)')->execute([$nombre]);
                $id = (int) $db->lastInsertId();
                $msg = ['msg' => 'creada', 'n' => $nombre];
            } else {
                if (!$actual) {
                    throw new InvalidArgumentException('El área técnica no es válida.');
                }
                $db->beginTransaction();
                $db->prepare('UPDATE areas_tecnicas SET nombre = ? WHERE id = ?')->execute([$nombre, $id]);
                cengi_area_renombrar_uso($db, $actual['nombre'], $nombre);
                $db->commit();
                $msg = ['msg' => 'actualizada', 'n' => $nombre];
            }
        } elseif ($accion === 'corregir_texto') {
            // Corrige todas las áreas con tildes dañadas, en el catálogo, los cursos y los planes.
            $n = 0;
            $db->beginTransaction();
            foreach ($db->query('SELECT id, nombre FROM areas_tecnicas')->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $c = cengi_area_texto_corregido($a['nombre']);
                if ($c === null || cengi_area_duplicada($db, $c, $a['id'])) {
                    continue;
                }
                $db->prepare('UPDATE areas_tecnicas SET nombre = ? WHERE id = ?')->execute([$c, $a['id']]);
                cengi_area_renombrar_uso($db, $a['nombre'], $c);
                $n++;
            }
            $db->commit();
            $msg = ['msg' => 'corregidas', 'n' => $n];
            $id = 0;
        } elseif ($accion === 'toggle_estado') {
            if (!$actual) {
                throw new InvalidArgumentException('El área técnica no es válida.');
            }
            $db->prepare('UPDATE areas_tecnicas SET estado = 1 - estado WHERE id = ?')->execute([$id]);
            $msg = ['msg' => (int) $actual['estado'] === 1 ? 'desactivada' : 'activada', 'n' => $actual['nombre']];
        } elseif ($accion === 'unificar') {
            $destinoId = (int) ($_POST['destino_id'] ?? 0);
            $stmt = $db->prepare('SELECT nombre FROM areas_tecnicas WHERE id = ?');
            $stmt->execute([$destinoId]);
            $destino = $stmt->fetchColumn();
            if (!$actual || $destino === false || $destinoId === $id) {
                throw new InvalidArgumentException('Elige el área en la que se unificará.');
            }
            $db->beginTransaction();
            cengi_area_renombrar_uso($db, $actual['nombre'], $destino);
            $db->prepare('DELETE FROM areas_tecnicas WHERE id = ?')->execute([$id]);
            $db->commit();
            $msg = ['msg' => 'unificada', 'n' => $actual['nombre'] . ' → ' . $destino];
            $id = $destinoId;
        } elseif ($accion === 'eliminar') {
            if (!$actual) {
                throw new InvalidArgumentException('El área técnica no es válida.');
            }
            $uso = $db->prepare('SELECT (SELECT COUNT(*) FROM cursos WHERE area_tecnica = ?) + (SELECT COUNT(*) FROM planificacion_cursos WHERE area_tecnica = ?)');
            $uso->execute([$actual['nombre'], $actual['nombre']]);
            if ((int) $uso->fetchColumn() > 0) {
                throw new InvalidArgumentException('“' . $actual['nombre'] . '” está en uso en cursos o planes: desactívala o unifícala con otra área.');
            }
            $db->prepare('DELETE FROM areas_tecnicas WHERE id = ?')->execute([$id]);
            $msg = ['msg' => 'eliminada', 'n' => $actual['nombre']];
            $id = 0;
        } elseif ($accion === 'agregar_catalogo') {
            // Un área escrita en cursos o planes que no está en el catálogo.
            if ($nombre === '' || cengi_area_duplicada($db, $nombre, 0)) {
                throw new InvalidArgumentException('Esa área ya está en el catálogo.');
            }
            $db->prepare('INSERT INTO areas_tecnicas (nombre, estado) VALUES (?, 1)')->execute([mb_substr($nombre, 0, 120, 'UTF-8')]);
            $id = (int) $db->lastInsertId();
            $msg = ['msg' => 'creada', 'n' => $nombre];
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            $msg = ['msg' => 'error', 'texto' => $e->getMessage()];
        } elseif ($e instanceof PDOException && (string) $e->getCode() === '23000') {
            $msg = ['msg' => 'error', 'texto' => 'Ya existe un área técnica con ese nombre.'];
        } else {
            error_log('Áreas técnicas: ' . $e->getMessage());
            $msg = ['msg' => 'error', 'texto' => 'No fue posible guardar el área técnica.'];
        }
        $id = 0;
    }
    header('Location: areas_tecnicas.php?' . http_build_query($msg) . ($id ? '#area-' . $id : ''));
    exit;
}

// ------------------------------------------------------------ datos
$anio = (int) date('Y');
$areas = $db->query("
    SELECT at.id, at.nombre, at.estado,
        COALESCE(c.cursos, 0) AS cursos, COALESCE(c.cursos_anio, 0) AS cursos_anio, c.ultimo_inicio,
        COALESCE(c.inscritos, 0) AS inscritos,
        COALESCE(p.planes, 0) AS planes, COALESCE(p.planes_anio, 0) AS planes_anio
    FROM areas_tecnicas at
    LEFT JOIN (
        SELECT cu.area_tecnica, COUNT(*) AS cursos, SUM(YEAR(cu.inicio) = {$anio}) AS cursos_anio, MAX(cu.inicio) AS ultimo_inicio,
            SUM((SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = cu.id AND a.estado_asignaciones = 1)) AS inscritos
        FROM cursos cu GROUP BY cu.area_tecnica
    ) c ON c.area_tecnica = at.nombre
    LEFT JOIN (
        SELECT area_tecnica, COUNT(*) AS planes, SUM(anio = {$anio}) AS planes_anio FROM planificacion_cursos GROUP BY area_tecnica
    ) p ON p.area_tecnica = at.nombre
    ORDER BY at.estado DESC, at.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Áreas escritas en cursos o planes que no están en el catálogo, y registros sin área.
$enCatalogo = array_map('cengi_texto_normalizado', array_column($areas, 'nombre'));
$huerfanas = [];
foreach ($db->query("SELECT area_tecnica, SUM(t = 'c') AS cursos, SUM(t = 'p') AS planes FROM (
        SELECT area_tecnica, 'c' AS t FROM cursos UNION ALL SELECT area_tecnica, 'p' FROM planificacion_cursos
    ) u WHERE COALESCE(TRIM(area_tecnica), '') <> '' GROUP BY area_tecnica")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    if (!in_array(cengi_texto_normalizado($u['area_tecnica']), $enCatalogo, true)) {
        $huerfanas[] = $u;
    }
}
$sinArea = $db->query("SELECT (SELECT COUNT(*) FROM cursos WHERE COALESCE(TRIM(area_tecnica), '') = '') AS cursos,
    (SELECT COUNT(*) FROM planificacion_cursos WHERE COALESCE(TRIM(area_tecnica), '') = '' AND anio = {$anio}) AS planes")->fetch(PDO::FETCH_ASSOC);
$conTextoDanado = array_values(array_filter($areas, static function ($a) {
    return cengi_area_texto_corregido($a['nombre']) !== null;
}));

$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtro = in_array($_GET['estado'] ?? '', ['activas', 'inactivas', 'sin_uso'], true) ? $_GET['estado'] : '';
$lista = array_values(array_filter($areas, static function ($a) use ($busqueda, $filtro) {
    if ($busqueda !== '' && strpos(cengi_texto_normalizado($a['nombre']), cengi_texto_normalizado($busqueda)) === false) {
        return false;
    }
    if ($filtro === 'activas') {
        return (int) $a['estado'] === 1;
    }
    if ($filtro === 'inactivas') {
        return (int) $a['estado'] !== 1;
    }
    if ($filtro === 'sin_uso') {
        return !$a['cursos'] && !$a['planes'];
    }
    return true;
}));
$activas = count(array_filter($areas, static function ($a) {
    return (int) $a['estado'] === 1;
}));
$sinUso = count(array_filter($areas, static function ($a) {
    return !$a['cursos'] && !$a['planes'];
}));
$maxUso = max(array_merge([1], array_map(static function ($a) {
    return (int) $a['cursos'] + (int) $a['planes'];
}, $areas)));

$mensajes = [
    'creada' => ['success', 'Área técnica registrada: %s.'],
    'actualizada' => ['success', 'Área renombrada a “%s”. Se actualizaron sus cursos y planes.'],
    'activada' => ['success', '“%s” está activa: aparece al crear cursos y planes.'],
    'desactivada' => ['success', '“%s” se desactivó: ya no se ofrece en cursos nuevos; los existentes la conservan.'],
    'unificada' => ['success', 'Áreas unificadas: %s. Sus cursos y planes pasaron al área destino.'],
    'eliminada' => ['success', 'Se eliminó “%s” del catálogo.'],
    'corregidas' => ['success', 'Se corrigieron %s nombre(s) con tildes dañadas, también en cursos y planes.'],
];
$codigoMsg = (string) ($_GET['msg'] ?? '');
[$mensajeTipo, $mensaje] = $codigoMsg === 'error'
    ? ['error', (string) ($_GET['texto'] ?? 'No fue posible guardar el área técnica.')]
    : (isset($mensajes[$codigoMsg]) ? [$mensajes[$codigoMsg][0], sprintf($mensajes[$codigoMsg][1], (string) ($_GET['n'] ?? ''))] : ['', '']);

$url = static function (array $cambios) use ($busqueda, $filtro) {
    return 'areas_tecnicas.php?' . http_build_query(array_filter(array_merge(['q' => $busqueda, 'estado' => $filtro], $cambios), 'strlen'));
};
$css = static function ($archivo) {
    return (int) (@filemtime(__DIR__ . '/css/' . $archivo) ?: 1);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Áreas técnicas</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo $css('proyecto.css'); ?>">
    <link rel="stylesheet" href="css/cengi-tema.css?v=<?php echo $css('cengi-tema.css'); ?>">
    <link rel="stylesheet" href="css/ingenios.css?v=<?php echo $css('ingenios.css'); ?>">
    <link rel="stylesheet" href="css/areas_tecnicas.css?v=<?php echo $css('areas_tecnicas.css'); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container ing-page at-page">
    <header class="ing-hero">
        <div>
            <small>Catálogo base</small>
            <h1>Áreas técnicas</h1>
            <p>Clasifican los cursos y los planes de Planificación. Solo las áreas <b>activas</b> se ofrecen al crear un curso o un plan;
                al renombrar o unificar un área, sus cursos y planes se actualizan solos.</p>
        </div>
        <button type="button" class="ing-btn is-claro" data-toggle="modal" data-target="#area-form-modal" data-modo="crear"><span class="glyphicon glyphicon-plus"></span> Nueva área técnica</button>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="ing-aviso <?php echo $mensajeTipo === 'error' ? 'is-error' : 'is-ok'; ?>" role="status">
            <span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'warning-sign' : 'ok'; ?>"></span>
            <div><p><?php echo $h($mensaje); ?></p></div>
            <button type="button" class="ing-aviso-cerrar" aria-label="Cerrar">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($conTextoDanado): ?>
        <div class="ing-aviso is-warn">
            <span class="glyphicon glyphicon-font"></span>
            <div><strong>Hay <?php echo count($conTextoDanado); ?> nombre(s) con tildes dañadas</strong>
                <p><?php echo implode(' · ', array_map(static function ($a) use ($h) {
                    return $h($a['nombre']) . ' → <b>' . $h(cengi_area_texto_corregido($a['nombre'])) . '</b>';
                }, $conTextoDanado)); ?>. Se corrige el catálogo y también los cursos y planes que las usan.</p></div>
            <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Corregir los nombres con tildes dañadas en el catálogo, los cursos y los planes?');">
                <input type="hidden" name="accion" value="corregir_texto"><button type="submit" class="ing-btn">Corregir ahora</button></form>
        </div>
    <?php endif; ?>

    <nav class="ing-kpis at-kpis" aria-label="Resumen">
        <a href="<?php echo $h($url(['estado' => ''])); ?>" class="is-verde<?php echo $filtro === '' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-tags"></span><b><?php echo count($areas); ?></b><em>Áreas en el catálogo</em></a>
        <a href="<?php echo $h($url(['estado' => 'activas'])); ?>" class="is-verde2<?php echo $filtro === 'activas' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-ok"></span><b><?php echo $activas; ?></b><em>Activas (se ofrecen)</em></a>
        <a href="<?php echo $h($url(['estado' => 'sin_uso'])); ?>" class="is-gris<?php echo $filtro === 'sin_uso' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-inbox"></span><b><?php echo $sinUso; ?></b><em>Sin cursos ni planes</em></a>
        <a href="planificacion_cursos.php" class="<?php echo $sinArea['planes'] || $sinArea['cursos'] ? 'is-naranja' : 'is-gris'; ?>"><span class="glyphicon glyphicon-warning-sign"></span><b><?php echo (int) $sinArea['planes']; ?></b><em>Planes <?php echo $anio; ?> sin área · <?php echo (int) $sinArea['cursos']; ?> curso(s) sin área</em></a>
    </nav>

    <section class="ing-panel">
        <div class="ing-toolbar">
            <form method="get" class="ing-buscar" role="search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo $h($busqueda); ?>" placeholder="Buscar área técnica…" aria-label="Buscar">
                <select name="estado" aria-label="Estado" onchange="this.form.submit()">
                    <?php foreach (['' => 'Todas', 'activas' => 'Activas', 'inactivas' => 'Inactivas', 'sin_uso' => 'Sin cursos ni planes'] as $k => $t): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filtro === $k ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="ing-btn">Buscar</button>
                <?php if ($busqueda !== '' || $filtro !== ''): ?><a class="ing-btn is-linea" href="areas_tecnicas.php">Limpiar</a><?php endif; ?>
            </form>
            <span class="at-conteo"><?php echo count($lista); ?> de <?php echo count($areas); ?> área(s)</span>
        </div>

        <?php if (!$lista): ?>
            <div class="ing-vacio"><span class="glyphicon glyphicon-tags"></span>
                <h2><?php echo $areas ? 'Ninguna área coincide' : 'No hay áreas técnicas registradas'; ?></h2>
                <p><?php echo $areas ? '<a href="areas_tecnicas.php">Quitar filtros</a>' : 'Registra la primera para clasificar los cursos.'; ?></p></div>
        <?php else: ?>
            <div class="ing-lista">
                <div class="ing-fila at-fila ing-cabecera" aria-hidden="true"><span>Área técnica</span><span>Cursos</span><span>Planes</span><span>Uso</span><span>Acciones</span></div>
                <?php foreach ($lista as $a):
                    $id = (int) $a['id'];
                    $activa = (int) $a['estado'] === 1;
                    $usada = $a['cursos'] || $a['planes'];
                    $danado = cengi_area_texto_corregido($a['nombre']);
                ?>
                    <article class="ing-fila at-fila <?php echo $activa ? ($usada ? 'is-activo' : 'is-libre') : 'is-sin'; ?>" id="area-<?php echo $id; ?>">
                        <div class="ing-nombre">
                            <span class="ing-avatar" aria-hidden="true"><?php echo $h(mb_strtoupper(mb_substr($danado ?? $a['nombre'], 0, 2, 'UTF-8'), 'UTF-8')); ?></span>
                            <div><strong><?php echo $h($a['nombre']); ?></strong>
                                <span class="at-estado <?php echo $activa ? 'is-activa' : 'is-inactiva'; ?>"><?php echo $activa ? 'Activa' : 'Inactiva · no se ofrece'; ?></span>
                                <?php if ($danado): ?><small class="ing-falta">Tildes dañadas: debería ser “<?php echo $h($danado); ?>”</small><?php endif; ?>
                            </div>
                        </div>
                        <div class="ing-dato">
                            <b><?php echo (int) $a['cursos']; ?></b>
                            <small><?php echo (int) $a['cursos_anio']; ?> en <?php echo $anio; ?> · <?php echo (int) $a['inscritos']; ?> inscrito(s)</small>
                            <?php if ($a['cursos']): ?><a class="ing-enlace" href="ver_cursos.php?anio=todos&amp;q=<?php echo urlencode($a['nombre']); ?>">Ver cursos</a><?php endif; ?>
                        </div>
                        <div class="ing-dato">
                            <b><?php echo (int) $a['planes']; ?></b>
                            <small><?php echo (int) $a['planes_anio']; ?> en <?php echo $anio; ?></small>
                            <?php if ($a['planes']): ?><a class="ing-enlace" href="planificacion_cursos.php?q=<?php echo urlencode($a['nombre']); ?>">Ver planes</a><?php endif; ?>
                        </div>
                        <div class="ing-dato">
                            <?php if ($usada): ?>
                                <span class="ing-barra" title="Cursos y planes respecto al área más usada"><span style="width:<?php echo round(((int) $a['cursos'] + (int) $a['planes']) * 100 / $maxUso); ?>%"></span></span>
                                <small><?php echo $a['ultimo_inicio'] ? 'Último curso: ' . date('d/m/Y', strtotime($a['ultimo_inicio'])) : 'Sin cursos aún'; ?></small>
                            <?php else: ?>
                                <span class="ing-chip is-amarillo">Sin uso</span>
                            <?php endif; ?>
                        </div>
                        <div class="ing-acciones">
                            <details class="cengi-acc">
                            <summary class="cengi-course-actions-toggle"><span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span></summary>
                            <div class="cengi-course-actions-panel cengi-acc-panel">
                            <div class="course-actions-heading"><strong>Gestionar área técnica</strong><small><?php echo $h($a['nombre']); ?></small></div>
                            <button type="button" class="ing-btn is-linea" data-toggle="modal" data-target="#area-form-modal" data-modo="editar" data-id="<?php echo $id; ?>" data-name="<?php echo $h($a['nombre']); ?>" data-uso="<?php echo (int) $a['cursos'] + (int) $a['planes']; ?>" title="Renombrar"><span class="glyphicon glyphicon-pencil"></span> Renombrar</button>
                            <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="toggle_estado"><input type="hidden" name="id" value="<?php echo $id; ?>">
                                <button type="submit" class="ing-btn <?php echo $activa ? 'is-linea' : ''; ?>" title="<?php echo $activa ? 'Dejar de ofrecerla en cursos y planes nuevos' : 'Volver a ofrecerla'; ?>"><span class="glyphicon glyphicon-<?php echo $activa ? 'eye-close' : 'eye-open'; ?>"></span> <?php echo $activa ? 'Desactivar' : 'Activar'; ?></button></form>
                            <?php if (count($areas) > 1): ?>
                                <button type="button" class="ing-btn is-linea" data-toggle="modal" data-target="#unificar-modal" data-id="<?php echo $id; ?>" data-name="<?php echo $h($a['nombre']); ?>" data-uso="<?php echo (int) $a['cursos'] . ' curso(s) y ' . (int) $a['planes'] . ' plan(es)'; ?>" title="Unificar con otra área"><span class="glyphicon glyphicon-resize-small"></span> Unificar con otra área</button>
                            <?php endif; ?>
                            <?php if (!$usada): ?>
                                <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Eliminar “<?php echo $h(addslashes($a['nombre'])); ?>” del catálogo?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <button type="submit" class="ing-btn is-peligro is-delete" title="Eliminar (no tiene cursos ni planes)"><span class="glyphicon glyphicon-trash"></span> Eliminar</button></form>
                            <?php endif; ?>
                            </div>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($huerfanas): ?>
        <section class="ing-panel at-huerfanas">
            <div class="at-panel-titulo"><h2>Áreas usadas que no están en el catálogo <b><?php echo count($huerfanas); ?></b></h2>
                <p>Se escribieron en cursos o planes (por ejemplo, con la carga masiva) pero no existen en el catálogo, así que no se ofrecen. Agrégalas o corrige esos registros.</p></div>
            <div class="ing-lista">
                <?php foreach ($huerfanas as $u): ?>
                    <div class="ing-fila at-fila-simple">
                        <div class="ing-nombre"><div><strong><?php echo $h($u['area_tecnica']); ?></strong><small><?php echo (int) $u['cursos']; ?> curso(s) · <?php echo (int) $u['planes']; ?> plan(es)</small></div></div>
                        <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="agregar_catalogo"><input type="hidden" name="nombre" value="<?php echo $h($u['area_tecnica']); ?>">
                            <button type="submit" class="ing-btn is-linea"><span class="glyphicon glyphicon-plus"></span> Agregar al catálogo</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <p class="ing-nota">Desactivar no cambia los cursos ni planes que ya la tienen; solo deja de ofrecerse. Eliminar solo es posible si no tiene cursos ni planes; si se repite con otra, usa <b>Unificar</b>.</p>
</main>

<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="area-form-modal" tabindex="-1" role="dialog" aria-labelledby="area-form-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="post" id="area-form" autocomplete="off">
            <input type="hidden" name="accion" id="area-form-action" value="crear">
            <input type="hidden" name="id" id="area-form-id" value="">
            <div class="modal-header">
                <h4 class="modal-title" id="area-form-title">Nueva área técnica</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="area-form-name">Nombre</label>
                    <input type="text" name="nombre" id="area-form-name" class="form-control" minlength="2" maxlength="120" placeholder="Ej. Nutrición vegetal" required>
                    <p class="help-block" id="area-form-ayuda">Aparecerá al crear o editar cursos y planes.</p>
                    <p class="ing-similar" id="area-form-similar" hidden></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="area-form-guardar">Guardar</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="unificar-modal" tabindex="-1" role="dialog" aria-labelledby="unificar-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="accion" value="unificar"><input type="hidden" name="id" id="unificar-id">
            <div class="modal-header">
                <h4 class="modal-title" id="unificar-title">Unificar área</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p>Los <span id="unificar-uso"></span> de <strong id="unificar-nombre"></strong> pasarán al área elegida y <strong>se quitará</strong> del catálogo.</p>
                <div class="form-group"><label for="unificar-destino">Unificar en</label>
                    <select class="form-control" id="unificar-destino" name="destino_id" required>
                        <?php foreach ($areas as $a): ?><option value="<?php echo (int) $a['id']; ?>"><?php echo $h($a['nombre'] . ((int) $a['estado'] === 1 ? '' : ' (inactiva)')); ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Unificar</button>
            </div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var nombres = <?php echo json_encode(array_map(static function ($a) {
        return ['id' => (int) $a['id'], 'n' => $a['nombre']];
    }, $areas), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim(); }
    var idActual = 0, nombre = document.getElementById('area-form-name'), aviso = document.getElementById('area-form-similar'), guardar = document.getElementById('area-form-guardar');

    nombre.addEventListener('input', function () {
        var v = norm(nombre.value), igual = null;
        nombres.forEach(function (x) { if (x.id !== idActual && norm(x.n) === v) igual = x.n; });
        aviso.hidden = !igual;
        aviso.textContent = igual ? 'Ya existe “' + igual + '”. Si son la misma, usa Unificar.' : '';
        guardar.disabled = !!igual;
    });

    $('#area-form-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, editar = t && t.getAttribute('data-modo') === 'editar', uso = editar ? parseInt(t.getAttribute('data-uso'), 10) || 0 : 0;
        idActual = editar ? parseInt(t.getAttribute('data-id'), 10) : 0;
        document.getElementById('area-form-action').value = editar ? 'actualizar' : 'crear';
        document.getElementById('area-form-id').value = idActual || '';
        nombre.value = editar ? (t.getAttribute('data-name') || '') : '';
        document.getElementById('area-form-title').textContent = editar ? 'Renombrar área técnica' : 'Nueva área técnica';
        document.getElementById('area-form-ayuda').textContent = editar
            ? (uso ? 'El nuevo nombre se aplicará también a sus ' + uso + ' curso(s) y plan(es).' : 'No tiene cursos ni planes todavía.')
            : 'Aparecerá al crear o editar cursos y planes.';
        aviso.hidden = true; guardar.disabled = false;
    }).on('shown.bs.modal', function () { nombre.focus(); nombre.select(); });

    $('#unificar-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, id = t.getAttribute('data-id'), sel = document.getElementById('unificar-destino'), primera = null;
        document.getElementById('unificar-id').value = id;
        document.getElementById('unificar-nombre').textContent = t.getAttribute('data-name');
        document.getElementById('unificar-uso').textContent = t.getAttribute('data-uso');
        Array.prototype.forEach.call(sel.options, function (o) { o.hidden = o.disabled = o.value === id; if (!o.disabled && !primera) primera = o; });
        if (sel.value === id && primera) sel.value = primera.value;
    });

    $('.ing-aviso-cerrar').on('click', function () { $(this).closest('.ing-aviso').slideUp(160); });
})();
</script>
</body>
</html>
