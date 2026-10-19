<?php
/**
 * Ingenios e instituciones: catálogo único que usan cursos, participantes, inscripciones, usuarios,
 * eventos, proformas y evaluaciones (todas con FK a ingenios.id). Desde 2026-10 absorbe a
 * «Organizaciones»: cada registro tiene tipo (ingenio, universidad…) y contacto.
 * Crear, editar y eliminar se hacen aquí mismo (POST + redirección); eliminar solo se
 * permite si nada lo usa. guardar_/actualizar_/eliminar_ingenios.php quedan por compatibilidad.
 * Esquema: migrations/20261104_ingenios_unifica_organizaciones.sql (se completa aquí si falta).
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';

cengi_require_ver_ingenios();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_ingenios();
$conDatosContacto = cengi_ing_asegurar_columnas($db);
$h = static function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};

const CENGI_ING_TIPOS = ['Ingenio azucarero', 'Universidad', 'Empresa proveedora', 'Institución técnica', 'Otra'];

/**
 * Asegura tipo y contacto en ingenios y, la primera vez, copia los datos de la antigua tabla
 * organizaciones (que se conserva como respaldo). Idempotente.
 */
function cengi_ing_asegurar_columnas(PDO $db)
{
    try {
        $db->query('SELECT tipo, contacto_nombre, contacto_correo, contacto_telefono FROM ingenios LIMIT 0');
        return true;
    } catch (PDOException $e) {
        // Falta: se aplica la migración de unificación.
    }
    try {
        $sql = (string) @file_get_contents(__DIR__ . '/migrations/20261104_ingenios_unifica_organizaciones.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
            $db->exec($sentencia);
        }
        return true;
    } catch (PDOException $e) {
        error_log('Ingenios (unificar organizaciones): ' . $e->getMessage());
        return false;
    }
}

/** Tablas que referencian a ingenios (FK) y cómo se nombran al explicar por qué no se elimina. */
const CENGI_ING_USOS = [
    'cursos' => 'curso(s)',
    'participantes' => 'participante(s)',
    'solicitudes_inscripcion' => 'solicitud(es) de inscripción',
    'users' => 'usuario(s)',
    'evento_participantes' => 'participante(s) de eventos',
    'proformas' => 'proforma(s)',
    'pagos_grupales' => 'pago(s) grupal(es)',
    'evaluaciones_instructor' => 'evaluación(es) de instructor',
];

function cengi_ing_usos(PDO $db, $id)
{
    $usos = [];
    foreach (CENGI_ING_USOS as $tabla => $texto) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$tabla} WHERE ingenio_id = ?");
            $stmt->execute([(int) $id]);
            if ($n = (int) $stmt->fetchColumn()) {
                $usos[] = $n . ' ' . $texto;
            }
        } catch (PDOException $e) {
            // Tabla ausente en esta instalación: no cuenta.
        }
    }
    return $usos;
}

// ------------------------------------------------------------ acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')));
    $tipo = in_array($_POST['tipo'] ?? '', CENGI_ING_TIPOS, true) ? $_POST['tipo'] : 'Ingenio azucarero';
    $contacto = [
        mb_substr(trim((string) ($_POST['contacto_nombre'] ?? '')), 0, 255, 'UTF-8'),
        mb_substr(trim((string) ($_POST['contacto_correo'] ?? '')), 0, 255, 'UTF-8'),
        mb_substr(trim((string) ($_POST['contacto_telefono'] ?? '')), 0, 50, 'UTF-8'),
    ];
    $volver = ['confirmacion' => 'error'];
    try {
        if ($accion === 'crear' || $accion === 'editar') {
            if (mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 255) {
                $volver = ['confirmacion' => 'datos_invalidos', 'nombre' => $nombre];
            } elseif ($contacto[1] !== '' && !filter_var($contacto[1], FILTER_VALIDATE_EMAIL)) {
                $volver = ['confirmacion' => 'correo_invalido', 'nombre' => $nombre];
            } else {
                // Duplicado sin importar mayúsculas ni tildes ("La Unión" = "La Union").
                $dup = $db->prepare('SELECT nombre_ingenios FROM ingenios WHERE ' . cengi_sql_texto_normalizado('nombre_ingenios') . ' = ? AND id <> ?');
                $dup->execute([cengi_texto_normalizado($nombre), $id]);
                if ($otro = $dup->fetchColumn()) {
                    $volver = ['confirmacion' => 'duplicado', 'nombre' => $otro];
                } elseif ($accion === 'crear') {
                    if ($conDatosContacto) {
                        $db->prepare('INSERT INTO ingenios (nombre_ingenios, tipo, contacto_nombre, contacto_correo, contacto_telefono, creado) VALUES (?, ?, ?, ?, ?, NOW())')
                            ->execute(array_merge([$nombre, $tipo], $contacto));
                    } else {
                        $db->prepare('INSERT INTO ingenios (nombre_ingenios, creado) VALUES (?, NOW())')->execute([$nombre]);
                    }
                    $id = (int) $db->lastInsertId();
                    $volver = ['confirmacion' => 'creado', 'nombre' => $nombre];
                } else {
                    $stmt = $db->prepare('SELECT nombre_ingenios FROM ingenios WHERE id = ?');
                    $stmt->execute([$id]);
                    $anterior = $stmt->fetchColumn();
                    if ($anterior === false) {
                        $volver = ['confirmacion' => 'no_existe'];
                    } else {
                        if ($conDatosContacto) {
                            $db->prepare('UPDATE ingenios SET nombre_ingenios = ?, tipo = ?, contacto_nombre = ?, contacto_correo = ?, contacto_telefono = ? WHERE id = ?')
                                ->execute(array_merge([$nombre, $tipo], $contacto, [$id]));
                        } else {
                            $db->prepare('UPDATE ingenios SET nombre_ingenios = ? WHERE id = ?')->execute([$nombre, $id]);
                        }
                        $volver = ['confirmacion' => 'actualizado', 'nombre' => $nombre];
                    }
                }
            }
        } elseif ($accion === 'eliminar') {
            $stmt = $db->prepare('SELECT nombre_ingenios FROM ingenios WHERE id = ?');
            $stmt->execute([$id]);
            $nombreActual = $stmt->fetchColumn();
            if ($nombreActual === false) {
                $volver = ['confirmacion' => 'no_existe'];
            } elseif ($usos = cengi_ing_usos($db, $id)) {
                $volver = ['confirmacion' => 'en_uso', 'nombre' => $nombreActual, 'detalle' => implode(', ', $usos)];
            } else {
                $db->beginTransaction();
                // Su copia en la antigua tabla organizaciones (respaldo) no impide eliminarlo.
                try {
                    $db->prepare('DELETE FROM organizaciones WHERE ingenio_id = ?')->execute([$id]);
                } catch (PDOException $e) {
                    // Tabla ausente en esta instalación.
                }
                $db->prepare('DELETE FROM ingenios WHERE id = ?')->execute([$id]);
                $db->commit();
                $volver = ['confirmacion' => 'eliminado', 'nombre' => $nombreActual];
                $id = 0;
            }
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Ingenios: ' . $e->getMessage());
        $volver = ['confirmacion' => (string) $e->getCode() === '23000' ? 'en_uso' : 'error', 'nombre' => $nombre];
    }
    header('Location: ver_ingenios.php?' . http_build_query($volver) . ($id ? '#ing-' . $id : ''));
    exit;
}

// ------------------------------------------------------------ datos
$busqueda = trim((string) ($_GET['q'] ?? ''));
$orden = in_array($_GET['orden'] ?? '', ['nombre', 'participantes', 'cursos', 'actividad'], true) ? $_GET['orden'] : 'nombre';
$anio = (int) date('Y');

$ingenios = $db->query("
    SELECT i.id, i.nombre_ingenios, i.creado,
        COALESCE(p.total, 0) AS participantes, COALESCE(p.activos, 0) AS participantes_activos,
        COALESCE(c.total, 0) AS cursos_propios,
        COALESCE(a.inscripciones, 0) AS inscripciones, COALESCE(a.capacitados, 0) AS capacitados, COALESCE(a.capacitados_anio, 0) AS capacitados_anio,
        COALESCE(a.cursos, 0) AS cursos_con_participantes, a.ultimo_curso,
        COALESCE(s.pendientes, 0) AS solicitudes_pendientes,
        COALESCE(u.total, 0) AS usuarios,
        " . ($conDatosContacto ? 'i.tipo, i.contacto_nombre, i.contacto_correo, i.contacto_telefono'
            : "'Ingenio azucarero' AS tipo, '' AS contacto_nombre, '' AS contacto_correo, '' AS contacto_telefono") . "
    FROM ingenios i
    LEFT JOIN (SELECT ingenio_id, COUNT(*) AS total, SUM(estado_participantes = 1) AS activos FROM participantes GROUP BY ingenio_id) p ON p.ingenio_id = i.id
    LEFT JOIN (SELECT ingenio_id, COUNT(*) AS total FROM cursos GROUP BY ingenio_id) c ON c.ingenio_id = i.id
    LEFT JOIN (
        SELECT pa.ingenio_id, COUNT(*) AS inscripciones, COUNT(DISTINCT pa.id) AS capacitados,
            COUNT(DISTINCT CASE WHEN YEAR(cu.inicio) = {$anio} THEN pa.id END) AS capacitados_anio,
            COUNT(DISTINCT cu.id) AS cursos, MAX(cu.inicio) AS ultimo_curso
        FROM asignaciones asg
        INNER JOIN participantes pa ON pa.id = asg.participantes_id
        INNER JOIN cursos cu ON cu.id = asg.cursos_id
        WHERE asg.estado_asignaciones = 1
        GROUP BY pa.ingenio_id
    ) a ON a.ingenio_id = i.id
    LEFT JOIN (SELECT ingenio_id, COUNT(*) AS pendientes FROM solicitudes_inscripcion WHERE estado = 'Pendiente' GROUP BY ingenio_id) s ON s.ingenio_id = i.id
    LEFT JOIN (SELECT ingenio_id, COUNT(*) AS total FROM users GROUP BY ingenio_id) u ON u.ingenio_id = i.id
    ORDER BY i.nombre_ingenios
")->fetchAll(PDO::FETCH_ASSOC);

// Resumen sobre todo el catálogo (no cambia con la búsqueda).
$totalParticipantes = array_sum(array_column($ingenios, 'participantes'));
$totalCapacitadosAnio = array_sum(array_column($ingenios, 'capacitados_anio'));
$sinActividad = count(array_filter($ingenios, static function ($i) {
    return !$i['inscripciones'];
}));
$sinContacto = count(array_filter($ingenios, static function ($i) {
    return trim((string) $i['contacto_correo']) === '' && trim((string) $i['contacto_telefono']) === '';
}));
$maxParticipantes = max(array_merge([1], array_map('intval', array_column($ingenios, 'participantes'))));
// Conteo por tipo (para los filtros) y filtro activo.
$porTipo = array_count_values(array_column($ingenios, 'tipo'));
$tipoFiltro = isset($porTipo[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';

$lista = $ingenios;
if ($tipoFiltro !== '') {
    $lista = array_values(array_filter($lista, static function ($i) use ($tipoFiltro) { return $i['tipo'] === $tipoFiltro; }));
}
if ($busqueda !== '') {
    $q = cengi_texto_normalizado($busqueda);
    $lista = array_values(array_filter($lista, static function ($i) use ($q) {
        return strpos(cengi_texto_normalizado($i['nombre_ingenios'] . ' ' . $i['contacto_nombre'] . ' ' . $i['contacto_correo']), $q) !== false;
    }));
}
usort($lista, static function ($a, $b) use ($orden) {
    switch ($orden) {
        case 'participantes': return $b['participantes'] <=> $a['participantes'] ?: strcasecmp($a['nombre_ingenios'], $b['nombre_ingenios']);
        case 'cursos': return $b['cursos_con_participantes'] <=> $a['cursos_con_participantes'] ?: strcasecmp($a['nombre_ingenios'], $b['nombre_ingenios']);
        case 'actividad': return strcmp((string) $b['ultimo_curso'], (string) $a['ultimo_curso']) ?: strcasecmp($a['nombre_ingenios'], $b['nombre_ingenios']);
        default: return strcasecmp(cengi_texto_normalizado($a['nombre_ingenios']), cengi_texto_normalizado($b['nombre_ingenios']));
    }
});

// Exportación CSV de la lista visible.
if (($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ingenios_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Ingenio o institución', 'Tipo', 'Participantes', 'Participantes activos', 'Capacitados (con curso)', 'Capacitados ' . $anio, 'Inscripciones activas',
        'Cursos con participantes', 'Cursos propios', 'Último curso', 'Solicitudes pendientes', 'Usuarios', 'Contacto', 'Correo', 'Teléfono']);
    foreach ($lista as $i) {
        fputcsv($out, [$i['nombre_ingenios'], $i['tipo'], $i['participantes'], $i['participantes_activos'], $i['capacitados'], $i['capacitados_anio'], $i['inscripciones'],
            $i['cursos_con_participantes'], $i['cursos_propios'], $i['ultimo_curso'] ? date('d/m/Y', strtotime($i['ultimo_curso'])) : '', $i['solicitudes_pendientes'],
            $i['usuarios'], $i['contacto_nombre'], $i['contacto_correo'], $i['contacto_telefono']]);
    }
    fclose($out);
    exit;
}

$puedeDashboard = function_exists('cengi_puede_ver_dashboard_ingenio') && cengi_puede_ver_dashboard_ingenio();
$puedeDirectorio = function_exists('cengi_puede_ver_participantes') ? cengi_puede_ver_participantes() : true;

$confirmacion = (string) ($_GET['confirmacion'] ?? '');
$nombreConfirmacion = trim((string) ($_GET['nombre'] ?? ''));
$avisos = [
    'creado' => ['is-ok', 'ok', 'Institución agregada', 'Ya está disponible en cursos, participantes e inscripciones.'],
    'actualizado' => ['is-ok', 'pencil', 'Datos actualizados', 'El cambio se refleja en todo el sistema.'],
    'correo_invalido' => ['is-warn', 'envelope', 'Revisa el correo de contacto', 'Escribe un correo válido o déjalo vacío.'],
    'eliminado' => ['is-ok', 'trash', 'Institución eliminada', 'Se quitó del catálogo.'],
    'duplicado' => ['is-warn', 'duplicate', 'La institución ya existe', 'Ya hay una registrada con ese nombre (sin importar mayúsculas o tildes). Edita la existente.'],
    'datos_invalidos' => ['is-warn', 'warning-sign', 'Revisa el nombre', 'Escribe un nombre de 2 a 255 caracteres.'],
    'en_uso' => ['is-warn', 'lock', 'No se puede eliminar', 'Está en uso' . (!empty($_GET['detalle']) ? ': ' . $_GET['detalle'] : '') . '. Si ya no se usa, renómbrala o deja de asignarla.'],
    'no_existe' => ['is-error', 'remove', 'No encontrada', 'La institución ya no existe.'],
    'error' => ['is-error', 'remove', 'No se pudo guardar', 'Ocurrió un problema. Intenta nuevamente.'],
];
$aviso = $avisos[$confirmacion] ?? null;

$url = static function (array $cambios) use ($busqueda, $orden, $tipoFiltro) {
    return 'ver_ingenios.php?' . http_build_query(array_filter(array_merge(['q' => $busqueda, 'orden' => $orden === 'nombre' ? '' : $orden, 'tipo' => $tipoFiltro], $cambios), 'strlen'));
};
$fechaCorta = static function ($iso) {
    $meses = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $t = strtotime((string) $iso);
    return (int) date('j', $t) . ' ' . $meses[(int) date('n', $t)] . ' ' . date('Y', $t);
};
$editarId = (int) ($_GET['editar'] ?? 0);
// Atributos data-* con los datos que precargan el formulario de edición.
$datosFila = static function (array $i) use ($h) {
    return 'data-id="' . (int) $i['id'] . '" data-name="' . $h($i['nombre_ingenios']) . '" data-tipo="' . $h($i['tipo']) . '"'
        . ' data-contacto-nombre="' . $h($i['contacto_nombre']) . '" data-contacto-correo="' . $h($i['contacto_correo']) . '" data-contacto-telefono="' . $h($i['contacto_telefono']) . '"';
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
    <title>Ingenios e instituciones</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo $css('proyecto.css'); ?>">
    <link rel="stylesheet" href="css/cengi-tema.css?v=<?php echo $css('cengi-tema.css'); ?>">
    <link rel="stylesheet" href="css/ingenios.css?v=<?php echo $css('ingenios.css'); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container ing-page">
    <header class="ing-hero">
        <div>
            <small>Catálogo base</small>
            <h1>Ingenios e instituciones</h1>
            <p>Un solo catálogo para ingenios, universidades, empresas e instituciones: alimenta cursos, participantes,
                inscripciones y usuarios. Aquí ves cuánto participa cada uno, su contacto y entras directo a su seguimiento.</p>
        </div>
        <?php if ($puedeGestionar): ?>
            <button type="button" class="ing-btn is-claro" data-toggle="modal" data-target="#ingenio-form-modal" data-modo="crear"><span class="glyphicon glyphicon-plus"></span> Nueva institución</button>
        <?php endif; ?>
    </header>

    <?php if ($aviso): [$clase, $icono, $titulo, $texto] = $aviso; ?>
        <div class="ing-aviso <?php echo $clase; ?>" role="status">
            <span class="glyphicon glyphicon-<?php echo $icono; ?>"></span>
            <div><strong><?php echo $h($titulo); ?><?php echo $nombreConfirmacion !== '' ? ': ' . $h($nombreConfirmacion) : ''; ?></strong><p><?php echo $h($texto); ?></p></div>
            <button type="button" class="ing-aviso-cerrar" aria-label="Cerrar">&times;</button>
        </div>
    <?php endif; ?>

    <section class="ing-kpis" aria-label="Resumen">
        <div class="is-verde"><span class="glyphicon glyphicon-globe"></span><b><?php echo count($ingenios); ?></b><em>Ingenios e instituciones</em></div>
        <div class="is-verde2"><span class="glyphicon glyphicon-user"></span><b><?php echo number_format($totalParticipantes); ?></b><em>Participantes registrados</em></div>
        <div class="is-amarillo"><span class="glyphicon glyphicon-education"></span><b><?php echo number_format($totalCapacitadosAnio); ?></b><em>Capacitados en cursos de <?php echo $anio; ?></em></div>
        <div class="<?php echo $sinActividad ? 'is-naranja' : 'is-gris'; ?>"><span class="glyphicon glyphicon-warning-sign"></span><b><?php echo $sinActividad; ?></b><em>Sin participantes en cursos<?php echo $sinContacto ? ' · ' . $sinContacto . ' sin contacto' : ''; ?></em></div>
    </section>

    <section class="ing-panel">
        <div class="ing-toolbar">
            <form method="get" class="ing-buscar" role="search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo $h($busqueda); ?>" placeholder="Buscar ingenio, institución o contacto…" aria-label="Buscar">
                <select name="orden" aria-label="Ordenar" onchange="this.form.submit()">
                    <?php foreach (['nombre' => 'Orden: nombre', 'participantes' => 'Orden: más participantes', 'cursos' => 'Orden: más cursos', 'actividad' => 'Orden: actividad reciente'] as $k => $t): ?>
                        <option value="<?php echo $k; ?>" <?php echo $orden === $k ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="ing-btn">Buscar</button>
                <?php if ($busqueda !== ''): ?><a class="ing-btn is-linea" href="<?php echo $h($url(['q' => ''])); ?>">Limpiar</a><?php endif; ?>
            </form>
            <a class="ing-btn is-linea" href="<?php echo $h($url(['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar</a>
        </div>
        <?php if (count($porTipo) > 1 || $tipoFiltro !== ''): ?>
        <nav class="ing-tipos" aria-label="Filtrar por tipo">
            <a href="<?php echo $h($url(['tipo' => ''])); ?>" class="<?php echo $tipoFiltro === '' ? 'is-active' : ''; ?>">Todos <b><?php echo count($ingenios); ?></b></a>
            <?php foreach (CENGI_ING_TIPOS as $t): if (empty($porTipo[$t])) continue; ?>
                <a href="<?php echo $h($url(['tipo' => $t])); ?>" class="<?php echo $tipoFiltro === $t ? 'is-active' : ''; ?>"><?php echo $h($t); ?> <b><?php echo (int) $porTipo[$t]; ?></b></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php if (!$lista): ?>
            <div class="ing-vacio"><span class="glyphicon glyphicon-globe"></span>
                <h2><?php echo $busqueda !== '' ? 'Ninguna coincide con la búsqueda' : 'No hay instituciones registradas'; ?></h2>
                <p><?php echo $busqueda !== '' ? '<a href="ver_ingenios.php">Quitar la búsqueda</a>' : 'Registra la primera para asignarla a cursos y participantes.'; ?></p></div>
        <?php else: ?>
            <div class="ing-lista">
                <div class="ing-fila ing-cabecera" aria-hidden="true"><span>Ingenio o institución</span><span>Participantes</span><span>Capacitación</span><span>Contacto</span><span>Acciones</span></div>
                <?php foreach ($lista as $i):
                    $id = (int) $i['id'];
                    $activo = $i['inscripciones'] > 0;
                    $pct = round($i['participantes'] * 100 / $maxParticipantes);
                ?>
                    <article class="ing-fila <?php echo $activo ? 'is-activo' : 'is-sin'; ?>" id="ing-<?php echo $id; ?>">
                        <div class="ing-nombre">
                            <span class="ing-avatar" aria-hidden="true"><?php echo $h(mb_strtoupper(mb_substr($i['nombre_ingenios'], 0, 2, 'UTF-8'), 'UTF-8')); ?></span>
                            <div><strong><?php echo $h($i['nombre_ingenios']); ?></strong>
                                <span class="ing-tipo<?php echo $i['tipo'] === 'Ingenio azucarero' ? '' : ' is-otro'; ?>"><?php echo $h($i['tipo']); ?></span>
                                <small><?php echo (int) $i['usuarios']; ?> usuario(s)<?php echo $i['cursos_propios'] ? ' · ' . (int) $i['cursos_propios'] . ' curso(s) propio(s)' : ''; ?></small>
                                <?php if ($i['solicitudes_pendientes']): ?><a class="ing-chip is-amarillo" href="solicitudes.php?estado=pendiente&amp;q=<?php echo urlencode($i['nombre_ingenios']); ?>"><?php echo (int) $i['solicitudes_pendientes']; ?> solicitud(es) pendiente(s)</a><?php endif; ?>
                            </div>
                        </div>
                        <div class="ing-dato">
                            <b><?php echo number_format($i['participantes']); ?></b>
                            <small><?php echo (int) $i['participantes_activos']; ?> activos</small>
                            <span class="ing-barra" title="Respecto al ingenio con más participantes"><span style="width:<?php echo $pct; ?>%"></span></span>
                        </div>
                        <div class="ing-dato">
                            <?php if ($activo): ?>
                                <b><?php echo (int) $i['capacitados']; ?> <small>capacitados</small></b>
                                <small><?php echo (int) $i['capacitados_anio']; ?> en <?php echo $anio; ?> · <?php echo (int) $i['cursos_con_participantes']; ?> curso(s)</small>
                                <small>Último curso: <?php echo $h($fechaCorta($i['ultimo_curso'])); ?></small>
                            <?php else: ?>
                                <span class="ing-chip is-naranja">Sin participantes en cursos</span>
                            <?php endif; ?>
                        </div>
                        <div class="ing-contacto">
                            <?php if ($i['contacto_nombre'] || $i['contacto_correo'] || $i['contacto_telefono']): ?>
                                <strong><?php echo $h($i['contacto_nombre'] ?: 'Contacto'); ?></strong>
                                <?php if ($i['contacto_correo']): ?><a href="mailto:<?php echo $h($i['contacto_correo']); ?>"><span class="glyphicon glyphicon-envelope"></span><?php echo $h($i['contacto_correo']); ?></a><?php endif; ?>
                                <?php if ($i['contacto_telefono']): ?><a href="tel:<?php echo $h(preg_replace('/[^\d+]/', '', $i['contacto_telefono'])); ?>"><span class="glyphicon glyphicon-earphone"></span><?php echo $h($i['contacto_telefono']); ?></a><?php endif; ?>
                            <?php else: ?>
                                <small class="ing-falta">Sin contacto</small>
                                <?php if ($puedeGestionar && $conDatosContacto): ?><button type="button" class="ing-enlace" data-toggle="modal" data-target="#ingenio-form-modal" data-modo="editar" data-foco="contacto" <?php echo $datosFila($i); ?>>Agregar contacto</button><?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="ing-acciones">
                            <details class="cengi-acc">
                                <summary class="cengi-course-actions-toggle"><span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span></summary>
                                <div class="cengi-course-actions-panel cengi-acc-panel">
                                    <div class="course-actions-heading"><strong>Gestionar institución</strong><small><?php echo $h($i['nombre_ingenios']); ?></small></div>
                                    <?php if ($puedeDashboard): ?><a href="dashboard_ingenio.php?ingenio_id=<?php echo $id; ?>"><span class="glyphicon glyphicon-stats"></span> Seguimiento del ingenio</a><?php endif; ?>
                                    <?php if ($puedeDirectorio): ?><a href="directorio_participantes.php?ingenio_id=<?php echo $id; ?>"><span class="glyphicon glyphicon-user"></span> Directorio de participantes</a><?php endif; ?>
                                    <?php if ($puedeGestionar): ?>
                                        <button type="button" data-toggle="modal" data-target="#ingenio-form-modal" data-modo="editar" <?php echo $datosFila($i); ?>><span class="glyphicon glyphicon-pencil"></span> Editar datos y contacto</button>
                                        <?php $enUso = $i['participantes'] || $i['cursos_propios'] || $i['usuarios'] || $i['solicitudes_pendientes']; ?>
                                        <button type="button" class="is-delete" data-toggle="modal" data-target="#confirm-delete" data-id="<?php echo $id; ?>" data-name="<?php echo $h($i['nombre_ingenios']); ?>"
                                            data-en-uso="<?php echo $enUso ? '1' : ''; ?>"><span class="glyphicon glyphicon-trash"></span> <?php echo $enUso ? 'Eliminar (en uso)' : 'Eliminar'; ?></button>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <p class="ing-nota">Capacitados: participantes del ingenio con al menos una inscripción activa en un curso. Una institución solo se puede eliminar si no la usa ningún curso, participante, usuario, inscripción, evento o proforma. Este catálogo reemplaza a «Organizaciones».</p>
</main>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="ingenio-form-modal" tabindex="-1" role="dialog" aria-labelledby="ingenio-form-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="post" id="ingenio-form" action="ver_ingenios.php" autocomplete="off">
            <input type="hidden" name="accion" id="ingenio-form-accion" value="crear">
            <input type="hidden" name="id" id="ingenio-form-id" value="">
            <div class="modal-header">
                <h4 class="modal-title" id="ingenio-form-title">Nueva institución</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="ingenio-form-name">Nombre</label>
                    <input type="text" name="nombre" id="ingenio-form-name" class="form-control" minlength="2" maxlength="255" placeholder="Ej. Ingenio Santa Ana" required>
                    <p class="help-block" id="ingenio-form-ayuda">Estará disponible en cursos, participantes e inscripciones.</p>
                    <p class="ing-similar" id="ingenio-form-similar" hidden></p>
                </div>
                <?php if ($conDatosContacto): ?>
                <div class="form-group">
                    <label for="ingenio-form-tipo">Tipo</label>
                    <select name="tipo" id="ingenio-form-tipo" class="form-control">
                        <?php foreach (CENGI_ING_TIPOS as $t): ?><option value="<?php echo $h($t); ?>"><?php echo $h($t); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <fieldset class="ing-form-contacto">
                    <legend>Contacto <small>opcional</small></legend>
                    <div class="form-group"><label for="ingenio-form-cnombre">Nombre</label><input type="text" name="contacto_nombre" id="ingenio-form-cnombre" class="form-control" maxlength="255" placeholder="Persona de contacto"></div>
                    <div class="ing-form-dos">
                        <div class="form-group"><label for="ingenio-form-ccorreo">Correo</label><input type="email" name="contacto_correo" id="ingenio-form-ccorreo" class="form-control" maxlength="255" placeholder="correo@institucion.com"></div>
                        <div class="form-group"><label for="ingenio-form-ctel">Teléfono</label><input type="text" name="contacto_telefono" id="ingenio-form-ctel" class="form-control" maxlength="50" placeholder="0000 0000"></div>
                    </div>
                </fieldset>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="ingenio-form-guardar">Guardar</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="post" action="ver_ingenios.php">
            <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" id="delete-id">
            <div class="modal-header">
                <h4 class="modal-title" id="delete-title">Eliminar institución</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p id="delete-texto">¿Deseas eliminar <strong id="delete-ingenio-name">esta institución</strong>?</p>
                <p class="ing-similar" id="delete-en-uso" hidden>Está en uso (participantes, cursos o usuarios), así que no se puede eliminar. Si el nombre está mal, usa <b>Editar datos</b>.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger" id="delete-ok">Eliminar</button>
            </div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var nombres = <?php echo json_encode(array_map(static function ($i) {
        return ['id' => (int) $i['id'], 'n' => $i['nombre_ingenios']];
    }, $ingenios), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim(); }
    var enfocar = null, idActual = 0, nombre = document.getElementById('ingenio-form-name'), aviso = document.getElementById('ingenio-form-similar'), guardar = document.getElementById('ingenio-form-guardar');

    // Aviso de duplicado mientras se escribe (sin importar mayúsculas ni tildes).
    nombre.addEventListener('input', function () {
        var v = norm(nombre.value), igual = null;
        nombres.forEach(function (x) { if (x.id !== idActual && norm(x.n) === v) igual = x.n; });
        aviso.hidden = !igual;
        aviso.textContent = igual ? 'Ya existe “' + igual + '”.' : '';
        guardar.disabled = !!igual;
    });

    $('#ingenio-form-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, editar = t && t.getAttribute('data-modo') === 'editar';
        idActual = editar ? parseInt(t.getAttribute('data-id'), 10) : 0;
        document.getElementById('ingenio-form-accion').value = editar ? 'editar' : 'crear';
        document.getElementById('ingenio-form-id').value = idActual || '';
        nombre.value = editar ? (t.getAttribute('data-name') || '') : '';
        // Tipo y contacto (catálogo unificado con Organizaciones).
        [['ingenio-form-tipo', 'data-tipo', 'Ingenio azucarero'], ['ingenio-form-cnombre', 'data-contacto-nombre', ''],
         ['ingenio-form-ccorreo', 'data-contacto-correo', ''], ['ingenio-form-ctel', 'data-contacto-telefono', '']].forEach(function (c) {
            var el = document.getElementById(c[0]);
            if (el) el.value = editar ? (t.getAttribute(c[1]) || c[2]) : c[2];
        });
        enfocar = editar && t.getAttribute('data-foco') === 'contacto' ? document.getElementById('ingenio-form-cnombre') : nombre;
        document.getElementById('ingenio-form-title').textContent = editar ? 'Editar institución' : 'Nueva institución';
        document.getElementById('ingenio-form-ayuda').textContent = editar
            ? 'El nombre se verá igual en todo el sistema (cursos, participantes, inscripciones).'
            : 'Estará disponible en cursos, participantes e inscripciones.';
        aviso.hidden = true; guardar.disabled = false;
    }).on('shown.bs.modal', function () { (enfocar || nombre).focus(); if (enfocar === nombre) nombre.select(); });

    $('#confirm-delete').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, enUso = t && t.getAttribute('data-en-uso') === '1';
        document.getElementById('delete-id').value = t ? t.getAttribute('data-id') : '';
        document.getElementById('delete-ingenio-name').textContent = t ? (t.getAttribute('data-name') || 'esta institución') : 'esta institución';
        document.getElementById('delete-en-uso').hidden = !enUso;
        document.getElementById('delete-ok').disabled = enUso;
    });

    <?php if ($editarId): ?>
    var b = document.querySelector('#ing-<?php echo $editarId; ?> [data-modo="editar"]'); if (b) b.click();
    <?php elseif (!empty($_GET['nuevo'])): ?>
    $('#ingenio-form-modal').modal('show');
    <?php endif; ?>
})();
</script>
<?php endif; ?>
<script>
$('.ing-aviso-cerrar').on('click', function () { $(this).closest('.ing-aviso').slideUp(160); });
</script>
</body>
</html>
