<?php
/**
 * Ficha del instructor: datos de contacto y formacion, documentos de respaldo (CV, carta
 * de comprobacion, titulo y otros), sesiones del Programa de sesiones, cursos, evaluaciones
 * de los participantes (evaluaciones_instructor) y pagos (liquidaciones).
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_gestionar_instructores();

$db = conectar();
$hoy = cengi_pp_hoy();
$id = (int) ($_GET['id'] ?? 0);
$mensaje = '';
$mensajeTipo = 'success';
$reabrir = null;

const CENGI_FICHA_MAX_BYTES = 5 * 1024 * 1024;
const CENGI_FICHA_CRITERIOS = [
    'instructor_lenguaje_claro' => 'Lenguaje claro y apropiado',
    'instructor_material_adecuado' => 'Material adecuado',
    'instructor_conocimiento_tema' => 'Conocimiento del tema',
    'instructor_respeto_participantes' => 'Respeto a los participantes',
    'instructor_puntualidad_objetivos' => 'Puntualidad y objetivos',
];

function cengi_ficha_instructor(PDO $db, $id)
{
    $stmt = $db->prepare('SELECT * FROM instructores WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$instructor = cengi_ficha_instructor($db, $id);
if (!$instructor) {
    header('Location: base_instructores.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $usuario = cengi_pp_usuario_nombre();
    try {
        if ($accion === 'datos') {
            $v = [
                'nombre' => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? ''))),
                'especialidad' => mb_substr(trim((string) ($_POST['especialidad'] ?? '')), 0, 255, 'UTF-8'),
                'institucion' => mb_substr(trim((string) ($_POST['institucion'] ?? '')), 0, 255, 'UTF-8') ?: null,
                'correo' => mb_strtolower(trim((string) ($_POST['correo'] ?? '')), 'UTF-8'),
                'telefono' => mb_substr(trim((string) ($_POST['telefono'] ?? '')), 0, 50, 'UTF-8'),
                'titulo' => mb_substr(trim((string) ($_POST['titulo'] ?? '')), 0, 255, 'UTF-8') ?: null,
                'codigo_catedratico' => mb_substr(strtoupper(trim((string) ($_POST['codigo_catedratico'] ?? ''))), 0, 20, 'UTF-8') ?: null,
                'tarifa_sesion' => trim((string) ($_POST['tarifa_sesion'] ?? '')) === '' ? null : (float) str_replace(',', '', $_POST['tarifa_sesion']),
                'estado' => !empty($_POST['estado']) ? 1 : 0,
                'notas' => trim((string) ($_POST['notas'] ?? '')) ?: null,
            ];
            $errores = [];
            if (mb_strlen($v['nombre'], 'UTF-8') < 5 || mb_strlen($v['nombre'], 'UTF-8') > 255) {
                $errores[] = 'Escribe el nombre completo.';
            }
            if ($v['correo'] !== '' && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo no es válido.';
            }
            if ($v['tarifa_sesion'] !== null && $v['tarifa_sesion'] < 0) {
                $errores[] = 'La tarifa por sesión debe ser 0 o mayor.';
            }
            foreach (['nombre' => 'nombre', 'correo' => 'correo', 'codigo_catedratico' => 'código de catedrático'] as $campo => $texto) {
                if ($v[$campo] === null || $v[$campo] === '') {
                    continue;
                }
                $dup = $db->prepare("SELECT nombre FROM instructores WHERE LOWER($campo) = LOWER(?) AND id <> ?");
                $dup->execute([$v[$campo], $id]);
                if ($otro = $dup->fetchColumn()) {
                    $errores[] = 'El ' . $texto . ' ya pertenece a ' . $otro . '.';
                }
            }
            if ($errores) {
                $reabrir = 'datos';
                throw new InvalidArgumentException(implode('<br>', array_map('cengi_pp_h', $errores)));
            }
            $columnas = implode(', ', array_map(static function ($c) {
                return $c . ' = ?';
            }, array_keys($v)));
            $db->prepare("UPDATE instructores SET $columnas WHERE id = ?")->execute(array_merge(array_values($v), [$id]));
            $codigo = 'datos';
        } elseif ($accion === 'subir') {
            $tipo = array_key_exists($_POST['tipo'] ?? '', CENGI_PP_TIPOS_DOCUMENTO) ? $_POST['tipo'] : '';
            $archivo = $_FILES['archivo'] ?? null;
            $reabrir = 'subir';
            if ($tipo === '') {
                throw new InvalidArgumentException('Elige el tipo de documento.');
            }
            if (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
                throw new InvalidArgumentException('Elige el archivo.');
            }
            if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                throw new InvalidArgumentException(in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'El archivo supera el tamaño permitido.' : 'No se pudo recibir el archivo.');
            }
            if ($archivo['size'] > CENGI_FICHA_MAX_BYTES) {
                throw new InvalidArgumentException('El archivo supera los 5 MB.');
            }
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $permitidas = $tipo === 'cv' ? ['pdf', 'doc', 'docx'] : ['pdf', 'jpg', 'jpeg', 'png'];
            $mimes = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
                'doc' => ['application/msword', 'application/octet-stream', 'application/CDFV2'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
            if (!in_array($extension, $permitidas, true) || !in_array($mime, $mimes[$extension], true)) {
                throw new InvalidArgumentException('Formato no permitido. ' . ($tipo === 'cv' ? 'El CV debe ser PDF, DOC o DOCX.' : 'Usa PDF, JPG o PNG.'));
            }
            $fechaEmision = (string) ($_POST['fecha_emision'] ?? '');
            if ($fechaEmision !== '' && (!cengi_pp_es_fecha($fechaEmision) || $fechaEmision > $hoy)) {
                throw new InvalidArgumentException('La fecha de emisión no es válida.');
            }
            // Nombre aleatorio: los documentos son datos personales y uploads/ es publico.
            $ruta = cengi_guardar_archivo_subido($archivo['tmp_name'], 'instructores', $id . '_' . $tipo . '_' . bin2hex(random_bytes(12)) . '.' . $extension);
            if ($ruta === null) {
                throw new InvalidArgumentException('No fue posible guardar el archivo en el servidor. Revisa los permisos de la carpeta uploads.');
            }
            $db->beginTransaction();
            if ($tipo !== 'otro') {
                $db->prepare('UPDATE instructor_documentos SET vigente = 0 WHERE instructor_id = ? AND tipo = ?')->execute([$id, $tipo]);
            }
            $db->prepare('INSERT INTO instructor_documentos (instructor_id, tipo, nombre_original, ruta, tamano, emisor, fecha_emision, descripcion, subido_por_nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $tipo, mb_substr(basename($archivo['name']), 0, 255, 'UTF-8'), $ruta, (int) $archivo['size'],
                    mb_substr(trim((string) ($_POST['emisor'] ?? '')), 0, 255, 'UTF-8'), $fechaEmision ?: null,
                    mb_substr(trim((string) ($_POST['descripcion'] ?? '')), 0, 255, 'UTF-8'), $usuario]);
            if ($tipo === 'cv') {
                $db->prepare('UPDATE instructores SET cv_path = ? WHERE id = ?')->execute([$ruta, $id]);
            }
            $db->commit();
            $codigo = 'subido';
        } elseif ($accion === 'quitar') {
            $docId = (int) ($_POST['doc'] ?? 0);
            if (($_POST['tipo'] ?? '') === 'cv') {
                $db->prepare('UPDATE instructores SET cv_path = NULL WHERE id = ?')->execute([$id]);
                $db->prepare("UPDATE instructor_documentos SET vigente = 0 WHERE instructor_id = ? AND tipo = 'cv'")->execute([$id]);
            } else {
                $db->prepare('UPDATE instructor_documentos SET vigente = 0 WHERE id = ? AND instructor_id = ?')->execute([$docId, $id]);
            }
            $codigo = 'quitado';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        header('Location: ficha_instructor.php?' . http_build_query(['id' => $id, 'tab' => $_POST['tab'] ?? 'documentos', 'msg' => $codigo]));
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Ficha de instructor: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar los cambios.';
        }
    }
}

$mensajes = ['datos' => 'Datos del instructor actualizados.', 'subido' => 'Documento cargado.', 'quitado' => 'Documento retirado (se conserva en el historial).'];
if ($mensaje === '' && isset($mensajes[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h($mensajes[$_GET['msg']]);
}

// ---------------------------------------------------------------- datos de la ficha
$instructor = cengi_ficha_instructor($db, $id);
$doc = cengi_pp_documentacion($db, $id);
$tab = in_array($_GET['tab'] ?? '', ['sesiones', 'cursos', 'evaluaciones', 'pagos', 'documentos'], true) ? $_GET['tab'] : 'sesiones';

$stmt = $db->prepare("SELECT s.*, p.nombre AS plan_nombre, l.numero AS liq_numero FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN liquidaciones l ON l.id = s.liquidacion_id
    WHERE s.instructor_id = ? AND s.estado <> 'cancelada' ORDER BY s.fecha, s.hora_inicio");
$stmt->execute([$id]);
$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$proximas = array_values(array_filter($sesiones, static function ($s) use ($hoy) {
    return $s['fecha'] >= $hoy && $s['estado'] === 'programada';
}));
$pasadas = array_reverse(array_values(array_filter($sesiones, static function ($s) use ($hoy) {
    return !($s['fecha'] >= $hoy && $s['estado'] === 'programada');
})));
$impartidas = count(array_filter($sesiones, static function ($s) {
    return $s['estado'] === 'impartida';
}));

$stmt = $db->prepare("SELECT c.id, c.codigo_curso, c.nombre_cursos, c.tipo, c.inicio, c.fin, 'Instructor principal' AS rol,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id AND a.estado_asignaciones = 1) AS inscritos
    FROM cursos c WHERE c.instructor_id = ?
    UNION
    SELECT c.id, c.codigo_curso, c.nombre_cursos, c.tipo, c.inicio, c.fin, CONCAT('Módulo: ', cm.nombre) AS rol,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id AND a.estado_asignaciones = 1) AS inscritos
    FROM curso_modulo_instructores cmi INNER JOIN curso_modulos cm ON cm.id = cmi.curso_modulo_id INNER JOIN cursos c ON c.id = cm.curso_id
    WHERE cmi.instructor_id = ?
    ORDER BY inicio DESC");
$stmt->execute([$id, $id]);
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT ei.*, COALESCE(c.nombre_cursos, ei.curso_nombre) AS curso, ing.nombre_ingenios AS ingenio
    FROM evaluaciones_instructor ei INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
    LEFT JOIN cursos c ON c.id = eei.curso_id LEFT JOIN ingenios ing ON ing.id = ei.ingenio_id
    WHERE eei.instructor_id = ? ORDER BY COALESCE(ei.fecha_curso, DATE(ei.creado)) DESC, ei.id DESC");
$stmt->execute([$id]);
$evaluaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$porCriterio = [];
foreach (CENGI_FICHA_CRITERIOS as $campo => $texto) {
    $valores = array_filter(array_column($evaluaciones, $campo), static function ($x) {
        return $x !== null;
    });
    $porCriterio[$campo] = $valores ? array_sum($valores) / count($valores) : null;
}
$promedioEval = array_filter($porCriterio, static function ($x) {
    return $x !== null;
}) ? array_sum(array_filter($porCriterio)) / count(array_filter($porCriterio)) : null;
$recomiendan = array_filter(array_column($evaluaciones, 'recomendaria_instructor'), static function ($x) {
    return $x !== null;
});
$promedioRecomienda = $recomiendan ? array_sum($recomiendan) / count($recomiendan) : null;

$stmt = $db->prepare("SELECT d.*, l.numero, l.desde, l.hasta, l.estado, l.pago_fecha FROM liquidacion_detalle d
    INNER JOIN liquidaciones l ON l.id = d.liquidacion_id WHERE d.tipo = 'instructor' AND d.persona_id = ? ORDER BY l.desde DESC");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPagado = array_sum(array_map(static function ($p) {
    return $p['estado'] === 'pagada' ? (float) $p['total'] : 0;
}, $pagos));
$stmt = $db->prepare('SELECT t.*, p.nombre AS plan_nombre, p.anio FROM tarifas_instructor_plan t INNER JOIN planificacion_cursos p ON p.id = t.planificacion_id WHERE t.instructor_id = ? ORDER BY p.anio DESC, p.nombre');
$stmt->execute([$id]);
$tarifasCurso = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare('SELECT * FROM instructor_documentos WHERE instructor_id = ? ORDER BY creado DESC, id DESC');
$stmt->execute([$id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$vigente = [];
foreach ($documentos as $d) {
    if ($d['vigente'] && $d['tipo'] !== 'otro' && !isset($vigente[$d['tipo']])) {
        $vigente[$d['tipo']] = $d;
    }
}

function cengi_ficha_calificacion($valor)
{
    if ($valor === null) {
        return ['Sin datos', 'is-neutral'];
    }
    return $valor >= 3.5 ? ['Excelente', 'is-active'] : ($valor >= 2.8 ? ['Bueno', 'is-upcoming'] : ['Regular', 'is-pending']);
}

function cengi_ficha_tamano($bytes)
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Ficha · ' . $instructor['nombre']); ?>
<style>
    .pp-ficha-head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
    .pp-ficha-avatar { display: grid; place-items: center; width: 56px; height: 56px; border-radius: 50%; background: var(--cengi-primary-deep); color: #fff; font-size: 18px; font-weight: 800; }
    .pp-ficha-head h2 { margin: 0; color: var(--cengi-ink); font-size: 20px; font-weight: 800; }
    .pp-ficha-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 16px; }
    .pp-crit { display: grid; grid-template-columns: minmax(150px, 1fr) 2fr 40px; gap: 10px; align-items: center; padding: 5px 0; font-size: 12.5px; }
    .pp-doc { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 14px; margin-bottom: 10px; border: 1px dashed #C9D0C4; border-radius: 10px; }
    .pp-doc.is-ok { border-style: solid; border-color: #CFE9B3; background: #F6FBF0; }
    .pp-doc > div { flex: 1; min-width: 200px; }
</style>
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <nav class="pp-subnav">
        <a href="instructores.php"><span class="glyphicon glyphicon-chevron-left"></span>Instructores</a>
        <a href="base_instructores.php"><span class="glyphicon glyphicon-list-alt"></span>Base de datos y documentos</a>
        <a href="programa_sesiones.php"><span class="glyphicon glyphicon-list"></span>Programa de sesiones</a>
    </nav>
    <?php cengi_pp_feedback($reabrir ? '' : $mensaje, $mensajeTipo); ?>

    <section class="pp-card"><div class="pp-card-body">
        <div class="pp-ficha-head">
            <div class="pp-ficha-avatar" aria-hidden="true"><?php echo cengi_pp_h(cengi_pp_iniciales($instructor['nombre'])); ?></div>
            <div style="flex:1;min-width:240px;">
                <h2><?php echo cengi_pp_h($instructor['nombre']); ?></h2>
                <span class="pp-sub"><?php echo cengi_pp_h(($instructor['especialidad'] ?: 'Sin especialidad') . ' · ' . ($instructor['institucion'] ?: 'Institución sin registrar')); ?>
                    · <span class="pp-mono"><?php echo cengi_pp_codigo_instructor($id) . ' / ' . cengi_pp_h($instructor['codigo_catedratico'] ?: 'CAT sin asignar'); ?></span></span>
                <span class="pp-sub">
                    <?php if ($instructor['correo']): ?><a href="mailto:<?php echo cengi_pp_h($instructor['correo']); ?>"><?php echo cengi_pp_h($instructor['correo']); ?></a><?php endif; ?>
                    <?php if ($instructor['telefono']): ?> · <a href="tel:<?php echo cengi_pp_h(preg_replace('/[^\d+]/', '', $instructor['telefono'])); ?>"><?php echo cengi_pp_h($instructor['telefono']); ?></a><?php endif; ?>
                </span>
                <div style="margin-top:6px;"><?php echo cengi_pp_doc_chips($doc); ?>
                    <?php echo $doc['completa'] ? '<span class="cengi-status-badge is-active"><i></i>Documentación completa</span>' : '<span class="cengi-status-badge is-pending"><i></i>Falta: ' . cengi_pp_h(implode(', ', $doc['faltan']) ?: 'activarlo') . '</span>'; ?></div>
            </div>
            <div class="pp-toolbar-group">
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#subir-modal"><span class="glyphicon glyphicon-upload"></span> Subir documento</button>
                <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#datos-modal"><span class="glyphicon glyphicon-pencil"></span> Editar datos</button>
            </div>
        </div>
        <div class="pp-ficha-stats">
            <div class="pp-mini-stat"><span>Sesiones impartidas</span><strong><?php echo $impartidas; ?></strong></div>
            <div class="pp-mini-stat"><span>Sesiones programadas</span><strong><?php echo count($proximas); ?></strong></div>
            <div class="pp-mini-stat"><span>Cursos</span><strong><?php echo count(array_unique(array_column($cursos, 'id'))); ?></strong></div>
            <div class="pp-mini-stat"><span>Evaluación promedio</span><strong style="color:#3E7A12;"><?php echo $promedioEval !== null ? number_format($promedioEval, 1) . '<small class="pp-kpi-detalle"> / 4</small>' : '—'; ?></strong></div>
            <div class="pp-mini-stat"><span>¿Otro curso con él/ella?</span><strong><?php echo $promedioRecomienda !== null ? number_format($promedioRecomienda, 1) . '<small class="pp-kpi-detalle"> / 10</small>' : '—'; ?></strong></div>
            <div class="pp-mini-stat"><span>Evaluaciones</span><strong><?php echo count($evaluaciones); ?></strong></div>
            <div class="pp-mini-stat"><span>Pagado</span><strong><?php echo cengi_pp_q($totalPagado); ?></strong></div>
        </div>
    </div></section>

    <section class="pp-card">
        <nav class="pp-tabs">
            <?php foreach (['sesiones' => 'Sesiones', 'cursos' => 'Cursos', 'evaluaciones' => 'Evaluaciones', 'pagos' => 'Pagos y tarifas', 'documentos' => 'Documentos'] as $clave => $texto): ?>
                <a href="ficha_instructor.php?id=<?php echo $id; ?>&amp;tab=<?php echo $clave; ?>" class="<?php echo $tab === $clave ? 'is-active' : ''; ?>"><?php echo $texto; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="pp-card-body">
        <?php if ($tab === 'sesiones'): ?>
            <?php foreach (['Próximas sesiones' => $proximas, 'Sesiones pasadas e impartidas' => $pasadas] as $titulo => $lista): ?>
                <h5 style="font-weight:700;"><?php echo $titulo; ?> (<?php echo count($lista); ?>)</h5>
                <div class="pp-table-wrap" style="margin-bottom:16px;<?php echo $lista === $pasadas ? 'max-height:360px;overflow-y:auto;' : ''; ?>"><table class="cengi-org-table" style="min-width:640px;"><tbody>
                    <?php if (!$lista): ?><tr><td class="pp-empty">Sin sesiones.</td></tr><?php endif; ?>
                    <?php foreach ($lista as $s): ?>
                        <tr>
                            <td class="pp-num"><?php echo cengi_pp_fecha($s['fecha']); ?><span class="pp-sub"><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']); ?></span></td>
                            <td><strong><?php echo cengi_pp_h($s['plan_nombre']); ?></strong><span class="pp-sub"><?php echo cengi_pp_h($s['modulo'] . ' — ' . $s['tema']); ?></span></td>
                            <td><?php echo cengi_pp_h($s['modalidad'] . ' · ' . $s['lugar']); ?></td>
                            <td><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_SESION, $s['estado']); ?><?php echo $s['liq_numero'] ? '<span class="pp-sub pp-mono">' . cengi_pp_h($s['liq_numero']) . '</span>' : ''; ?></td>
                            <td><a class="cengi-action-btn is-edit" href="programa_sesiones.php?editar=<?php echo (int) $s['id']; ?>" data-tooltip="Editar sesión" aria-label="Editar sesión"><span class="glyphicon glyphicon-pencil"></span></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endforeach; ?>
        <?php elseif ($tab === 'cursos'): ?>
            <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:640px;">
                <thead><tr><th>Curso</th><th>Rol</th><th>Modalidad</th><th>Fechas</th><th>Inscritos</th></tr></thead>
                <tbody>
                    <?php if (!$cursos): ?><tr><td colspan="5" class="pp-empty">No tiene cursos asignados en Gestión de cursos.</td></tr><?php endif; ?>
                    <?php foreach ($cursos as $c): ?>
                        <tr>
                            <td><strong><a href="ver_participante_curso.php?id=<?php echo (int) $c['id']; ?>"><?php echo cengi_pp_h($c['nombre_cursos']); ?></a></strong><span class="pp-sub pp-mono"><?php echo cengi_pp_h($c['codigo_curso']); ?></span></td>
                            <td><?php echo cengi_pp_h($c['rol']); ?></td>
                            <td><?php echo cengi_pp_h($c['tipo']); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($c['inicio']) . ' – ' . cengi_pp_fecha($c['fin']); ?></td>
                            <td><?php echo (int) $c['inscritos']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php elseif ($tab === 'evaluaciones'): ?>
            <?php if (!$evaluaciones): ?>
                <span class="pp-sub">Todavía no hay evaluaciones de participantes para este instructor. Se reciben con el enlace de evaluación de cada curso (Instructores → Informe) o con la carga masiva de evaluaciones.</span>
            <?php else: ?>
                <div class="pp-two-col" style="grid-template-columns:1.4fr 1fr;margin-bottom:16px;">
                    <div class="pp-card" style="margin:0;"><div class="pp-card-body">
                        <span class="pp-sub" style="margin-bottom:8px;">Promedio por criterio (Excelente = 4 · Bueno = 3 · Regular = 2 · Deficiente = 1)</span>
                        <?php foreach (CENGI_FICHA_CRITERIOS as $campo => $texto): $valor = $porCriterio[$campo]; ?>
                            <div class="pp-crit"><span><?php echo $texto; ?></span><span class="pp-prog-track"><span style="width:<?php echo $valor !== null ? round($valor / 4 * 100) : 0; ?>%"></span></span><b class="pp-num"><?php echo $valor !== null ? number_format($valor, 1) : '—'; ?></b></div>
                        <?php endforeach; ?>
                    </div></div>
                    <div class="pp-card" style="margin:0;"><div class="pp-card-body">
                        <span class="pp-sub">¿Le gustaría recibir otro curso con este instructor?</span>
                        <strong style="font-size:28px;color:var(--cengi-ink);"><?php echo number_format((float) $promedioRecomienda, 1); ?></strong> <span class="pp-sub" style="display:inline;">/ 10 estrellas</span>
                        <span class="pp-prog" style="margin-top:8px;"><span class="pp-prog-track"><span style="width:<?php echo round((float) $promedioRecomienda * 10); ?>%;background:var(--cengi-amarillo);"></span></span></span>
                        <span class="pp-sub" style="margin-top:12px;">Basado en <?php echo count($evaluaciones); ?> evaluación(es) en <?php echo count(array_unique(array_column($evaluaciones, 'curso'))); ?> curso(s).</span>
                    </div></div>
                </div>
                <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:720px;">
                    <thead><tr><th>Participante</th><th>Curso</th><th>Fecha</th><th>Promedio</th><th>Comentarios</th></tr></thead>
                    <tbody>
                        <?php foreach ($evaluaciones as $e):
                            $valores = array_filter(array_map(static function ($c) use ($e) {
                                return $e[$c];
                            }, array_keys(CENGI_FICHA_CRITERIOS)), static function ($x) {
                                return $x !== null;
                            });
                            $prom = $valores ? array_sum($valores) / count($valores) : null;
                            [$etiqueta, $clase] = cengi_ficha_calificacion($prom);
                        ?>
                            <tr>
                                <td><?php echo cengi_pp_h($e['cargo'] ?: 'Anónimo'); ?><span class="pp-sub"><?php echo cengi_pp_h(($e['ingenio'] ?: $e['ingenio_otro'] ?: '') . ($e['modalidad'] ? ' · ' . $e['modalidad'] : '')); ?></span></td>
                                <td><?php echo cengi_pp_h($e['curso']); ?><?php echo $e['modulo_nombre'] ? '<span class="pp-sub">' . cengi_pp_h($e['modulo_nombre']) . '</span>' : ''; ?></td>
                                <td class="pp-num"><?php echo cengi_pp_fecha($e['fecha_curso'] ?: $e['creado']); ?></td>
                                <td><span class="cengi-status-badge <?php echo $clase; ?>"><i></i><?php echo $etiqueta . ($prom !== null ? ' · ' . number_format($prom, 1) : ''); ?></span><span class="pp-sub"><?php echo (int) $e['recomendaria_instructor']; ?>/10 lo recomendaría</span></td>
                                <td style="font-size:12px;min-width:220px;"><?php echo cengi_pp_h(trim($e['areas_mejora'] . ($e['capacitaciones_necesarias'] ? ' · Necesidades: ' . $e['capacitaciones_necesarias'] : '')) ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        <?php elseif ($tab === 'pagos'): ?>
            <div class="pp-strip">
                <div class="pp-mini-stat"><span>Tarifa base por sesión</span><strong><?php echo $instructor['tarifa_sesion'] !== null ? cengi_pp_q($instructor['tarifa_sesion'], 2) : '—'; ?></strong></div>
                <div class="pp-mini-stat"><span>Liquidaciones</span><strong><?php echo count($pagos); ?></strong></div>
                <div class="pp-mini-stat"><span>Total pagado</span><strong><?php echo cengi_pp_q($totalPagado, 2); ?></strong></div>
            </div>
            <?php if ($tarifasCurso): ?>
                <h5 style="font-weight:700;">Tarifas por curso</h5>
                <?php foreach ($tarifasCurso as $t): ?><div class="pp-mini-row"><span><?php echo cengi_pp_h($t['plan_nombre']); ?> <span class="pp-sub" style="display:inline;">· año <?php echo cengi_pp_anio_texto($t['anio']); ?><?php echo $t['observaciones'] ? ' · ' . cengi_pp_h($t['observaciones']) : ''; ?></span></span><b class="pp-num"><?php echo cengi_pp_q($t['tarifa'], 2); ?></b></div><?php endforeach; ?>
            <?php endif; ?>
            <h5 style="font-weight:700;margin-top:16px;">Liquidaciones</h5>
            <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:640px;">
                <thead><tr><th>N.° liquidación</th><th>Período</th><th>Curso</th><th>Sesiones</th><th>Total con impuesto</th><th>Estado</th></tr></thead>
                <tbody>
                    <?php if (!$pagos): ?><tr><td colspan="6" class="pp-empty">Sin liquidaciones.</td></tr><?php endif; ?>
                    <?php foreach ($pagos as $p): ?>
                        <tr>
                            <td class="pp-mono"><a href="pago_instructores.php?tab=liquidaciones&amp;ver=<?php echo (int) $p['liquidacion_id']; ?>"><?php echo cengi_pp_h($p['numero']); ?></a></td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($p['desde']) . ' – ' . cengi_pp_fecha($p['hasta']); ?></td>
                            <td><?php echo cengi_pp_h($p['curso_nombre']); ?></td>
                            <td><?php echo (int) $p['sesiones']; ?> × <?php echo cengi_pp_q($p['tarifa'], 2); ?></td>
                            <td class="pp-num"><strong><?php echo cengi_pp_q($p['total'], 2); ?></strong></td>
                            <td><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_LIQ, $p['estado']); ?><?php echo $p['pago_fecha'] ? '<span class="pp-sub">' . cengi_pp_fecha($p['pago_fecha']) . '</span>' : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <?php
            $tarjetas = [
                'cv' => [$instructor['cv_path'] ? ($vigente['cv'] ?? ['nombre_original' => basename((string) $instructor['cv_path']), 'legado' => true]) : null],
                'carta' => [$vigente['carta'] ?? null],
                'titulo' => [$vigente['titulo'] ?? null],
            ];
            foreach ($tarjetas as $tipo => [$d]):
            ?>
                <div class="pp-doc<?php echo $d ? ' is-ok' : ''; ?>">
                    <span class="glyphicon glyphicon-file" style="font-size:20px;color:<?php echo $d ? '#3E7A12' : '#9AA394'; ?>;"></span>
                    <div><strong><?php echo CENGI_PP_TIPOS_DOCUMENTO[$tipo]; ?></strong>
                        <span class="pp-sub"><?php
                            if (!$d) {
                                echo $tipo === 'titulo' ? 'Opcional: el respaldo del título. La formación se registra en “Editar datos”.' : 'No se ha cargado';
                            } elseif (!empty($d['legado'])) {
                                echo cengi_pp_h($d['nombre_original']) . ' · cargado desde Instructores';
                            } else {
                                echo cengi_pp_h($d['nombre_original']) . ' · ' . cengi_ficha_tamano($d['tamano']) . ' · subido ' . cengi_pp_fecha($d['creado']) . ' por ' . cengi_pp_h($d['subido_por_nombre'])
                                    . ($d['emisor'] ? '<br>Emitido por ' . cengi_pp_h($d['emisor']) . ($d['fecha_emision'] ? ' el ' . cengi_pp_fecha($d['fecha_emision']) : '') : '');
                            }
                        ?></span></div>
                    <?php if ($d): ?>
                        <a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="documento_instructor.php?<?php echo $tipo === 'cv' ? 'cv=' . $id : 'doc=' . (int) $d['id']; ?>">Ver documento</a>
                        <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Retirar este documento? Se conserva en el historial.');">
                            <input type="hidden" name="accion" value="quitar"><input type="hidden" name="tipo" value="<?php echo $tipo; ?>"><input type="hidden" name="doc" value="<?php echo (int) ($d['id'] ?? 0); ?>">
                            <button type="submit" class="btn btn-link btn-sm">Retirar</button></form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#subir-modal" data-tipo="<?php echo $tipo; ?>"><?php echo $d ? 'Reemplazar' : 'Subir'; ?></button>
                </div>
            <?php endforeach; ?>
            <div class="pp-mini-row"><span>Formación que lo respalda</span><b><?php echo cengi_pp_h($instructor['titulo'] ?: 'Sin registrar'); ?></b></div>
            <div class="pp-mini-row"><span>Estado de la documentación</span><span><?php echo $doc['completa'] ? '<span class="cengi-status-badge is-active"><i></i>Completa</span>' : '<span class="cengi-status-badge is-pending"><i></i>Falta: ' . cengi_pp_h(implode(', ', $doc['faltan']) ?: 'activarlo') . '</span>'; ?></span></div>
            <?php if ($instructor['notas']): ?><div class="pp-mini-row"><span>Notas</span><span style="max-width:65%;text-align:right;"><?php echo nl2br(cengi_pp_h($instructor['notas'])); ?></span></div><?php endif; ?>

            <?php $otros = array_filter($documentos, static function ($d) {
                return $d['tipo'] === 'otro' && $d['vigente'];
            }); ?>
            <h5 style="font-weight:700;margin-top:18px;">Otros documentos (<?php echo count($otros); ?>)</h5>
            <?php foreach ($otros as $d): ?>
                <div class="pp-mini-row"><span><a target="_blank" rel="noopener" href="documento_instructor.php?doc=<?php echo (int) $d['id']; ?>"><?php echo cengi_pp_h($d['descripcion'] ?: $d['nombre_original']); ?></a><span class="pp-sub"><?php echo cengi_pp_h($d['nombre_original']) . ' · ' . cengi_pp_fecha($d['creado']); ?></span></span>
                    <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Retirar este documento?');"><input type="hidden" name="accion" value="quitar"><input type="hidden" name="doc" value="<?php echo (int) $d['id']; ?>"><button type="submit" class="btn btn-link btn-sm">Retirar</button></form></div>
            <?php endforeach; ?>

            <?php $historial = array_filter($documentos, static function ($d) {
                return !$d['vigente'];
            }); ?>
            <?php if ($historial): ?>
                <details style="margin-top:14px;"><summary class="pp-sub" style="cursor:pointer;display:list-item;">Historial de versiones anteriores (<?php echo count($historial); ?>)</summary>
                    <?php foreach ($historial as $d): ?>
                        <div class="pp-mini-row"><span><a target="_blank" rel="noopener" href="documento_instructor.php?doc=<?php echo (int) $d['id']; ?>"><?php echo cengi_pp_h($d['nombre_original']); ?></a><span class="pp-sub"><?php echo CENGI_PP_TIPOS_DOCUMENTO[$d['tipo']] ?? $d['tipo']; ?> · subido <?php echo cengi_pp_fecha($d['creado']); ?></span></span></div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </section>
</main>

<div class="modal fade cengi-course-form-modal pp-modal" id="datos-modal" tabindex="-1" role="dialog" aria-labelledby="datos-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" autocomplete="off">
            <input type="hidden" name="accion" value="datos"><input type="hidden" name="tab" value="<?php echo $tab; ?>">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker"><?php echo cengi_pp_codigo_instructor($id); ?></span><h4 class="modal-title" id="datos-title">Editar datos del instructor</h4></div>
            <div class="modal-body">
                <?php if ($reabrir === 'datos'): ?><div class="pp-form-msg is-visible is-error"><?php echo $mensaje; ?></div><?php endif; ?>
                <?php $v = $reabrir === 'datos' ? $_POST : $instructor; ?>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full"><label for="fi-nombre">Nombre completo</label><input type="text" class="form-control" id="fi-nombre" name="nombre" maxlength="255" required value="<?php echo cengi_pp_h($v['nombre'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-esp">Especialidad</label><input type="text" class="form-control" id="fi-esp" name="especialidad" maxlength="255" value="<?php echo cengi_pp_h($v['especialidad'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-inst">Institución</label><input type="text" class="form-control" id="fi-inst" name="institucion" maxlength="255" placeholder="CENGICAÑA" value="<?php echo cengi_pp_h($v['institucion'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-correo">Correo</label><input type="email" class="form-control" id="fi-correo" name="correo" maxlength="255" value="<?php echo cengi_pp_h($v['correo'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-tel">Teléfono</label><input type="text" class="form-control" id="fi-tel" name="telefono" maxlength="50" value="<?php echo cengi_pp_h($v['telefono'] ?? ''); ?>"></div>
                    <div class="form-group is-full"><label for="fi-titulo">Formación que lo respalda</label><input type="text" class="form-control" id="fi-titulo" name="titulo" maxlength="255" placeholder="Ej. M.Sc. en Entomología — Universidad de San Carlos" value="<?php echo cengi_pp_h($v['titulo'] ?? ''); ?>">
                        <p class="help-block">Requerido para la documentación completa, junto con el CV y la carta de comprobación.</p></div>
                    <div class="form-group"><label for="fi-cat">Código de catedrático</label><input type="text" class="form-control" id="fi-cat" name="codigo_catedratico" maxlength="20" placeholder="CAT-000" value="<?php echo cengi_pp_h($v['codigo_catedratico'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-tarifa">Tarifa base por sesión (Q)</label><input type="number" class="form-control" id="fi-tarifa" name="tarifa_sesion" min="0" step="0.01" value="<?php echo cengi_pp_h($v['tarifa_sesion'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-estado">Estado</label><select class="form-control" id="fi-estado" name="estado"><option value="1">Activo</option><option value="0" <?php echo (int) ($v['estado'] ?? 1) !== 1 ? 'selected' : ''; ?>>Inactivo</option></select></div>
                    <div class="form-group is-full"><label for="fi-notas">Notas</label><textarea class="form-control" id="fi-notas" name="notas" rows="2"><?php echo cengi_pp_h($v['notas'] ?? ''); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="subir-modal" tabindex="-1" role="dialog" aria-labelledby="subir-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" enctype="multipart/form-data" id="subir-form">
            <input type="hidden" name="accion" value="subir"><input type="hidden" name="tab" value="documentos">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker"><?php echo cengi_pp_h($instructor['nombre']); ?></span><h4 class="modal-title" id="subir-title">Subir documento</h4></div>
            <div class="modal-body">
                <?php if ($reabrir === 'subir'): ?><div class="pp-form-msg is-visible is-error"><?php echo $mensaje; ?></div><?php endif; ?>
                <div class="cengi-course-modal-grid">
                    <div class="form-group"><label for="sb-tipo">Tipo de documento</label><select class="form-control" id="sb-tipo" name="tipo">
                        <?php foreach (CENGI_PP_TIPOS_DOCUMENTO as $clave => $texto): ?><option value="<?php echo $clave; ?>" <?php echo ($_POST['tipo'] ?? '') === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="form-group"><label for="sb-archivo">Archivo</label><input type="file" class="form-control" id="sb-archivo" name="archivo" required>
                        <p class="help-block" id="sb-ayuda">PDF, JPG o PNG · máximo 5 MB.</p></div>
                    <div class="form-group" data-carta><label for="sb-emisor">Emitida por</label><input type="text" class="form-control" id="sb-emisor" name="emisor" maxlength="255" placeholder="Universidad o institución"></div>
                    <div class="form-group" data-carta><label for="sb-fecha">Fecha de emisión</label><input type="date" class="form-control" id="sb-fecha" name="fecha_emision" max="<?php echo $hoy; ?>"></div>
                    <div class="form-group is-full" data-otro><label for="sb-desc">Descripción</label><input type="text" class="form-control" id="sb-desc" name="descripcion" maxlength="255" placeholder="Ej. Constancia de colegiado activo"></div>
                </div>
                <p class="pp-sub">Al reemplazar un documento, la versión anterior queda en el historial.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="sb-btn">Subir</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var tipo = document.getElementById('sb-tipo');
    function ajustar() {
        var t = tipo.value, cv = t === 'cv';
        document.getElementById('sb-archivo').accept = cv ? '.pdf,.doc,.docx' : '.pdf,.jpg,.jpeg,.png';
        document.getElementById('sb-ayuda').textContent = (cv ? 'PDF, DOC o DOCX' : 'PDF, JPG o PNG') + ' · máximo 5 MB.';
        document.querySelectorAll('[data-carta]').forEach(function (e) { e.hidden = t !== 'carta' && t !== 'titulo'; });
        document.querySelectorAll('[data-otro]').forEach(function (e) { e.hidden = t !== 'otro'; });
    }
    tipo.addEventListener('change', ajustar);
    $('#subir-modal').on('show.bs.modal', function (e) {
        var t = e.relatedTarget && e.relatedTarget.getAttribute('data-tipo');
        if (t) tipo.value = t;
        ajustar();
    });
    document.getElementById('sb-archivo').addEventListener('change', function () {
        if (this.files[0] && this.files[0].size > <?php echo CENGI_FICHA_MAX_BYTES; ?>) { alert('El archivo supera los 5 MB.'); this.value = ''; }
    });
    document.getElementById('subir-form').addEventListener('submit', function () { var b = document.getElementById('sb-btn'); setTimeout(function () { b.disabled = true; b.textContent = 'Subiendo…'; }, 0); });
    ajustar();
    <?php if ($reabrir === 'datos'): ?>$('#datos-modal').modal('show');<?php elseif ($reabrir === 'subir'): ?>$('#subir-modal').modal('show');<?php endif; ?>
})();
</script>
</body>
</html>
