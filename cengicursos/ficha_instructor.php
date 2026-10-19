<?php
/**
 * Ficha del instructor: datos de contacto y formacion, documentos de respaldo (CV, carta
 * de comprobacion, titulo y otros), sesiones del Programa de sesiones, cursos, evaluaciones
 * de los participantes (evaluaciones_instructor) y pagos (liquidaciones).
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/instructor_cv_helpers.php';

cengi_require_gestionar_instructores();

$db = conectar();
$hoy = cengi_pp_hoy();
$id = (int) ($_GET['id'] ?? 0);
$mensaje = '';
$mensajeTipo = 'success';
$reabrir = null;
if(empty($_SESSION['cv_gestion_csrf']))$_SESSION['cv_gestion_csrf']=bin2hex(random_bytes(32));

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
// DPI y comprobante (Pago a instructores) se editan también aquí si las columnas existen.
$tienePago = array_key_exists('dpi', $instructor) && array_key_exists('comprobante', $instructor);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $usuario = cengi_pp_usuario_nombre();
    try {
        if(in_array($accion,['cv_enlace','cv_revocar','cv_validar'],true)) {
            if(!hash_equals($_SESSION['cv_gestion_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Recarga la ficha antes de guardar.');
            if($accion==='cv_enlace'){
                $_SESSION['cv_enlace_'.$id]=cengi_cv_generar_enlace($db,$id,$usuario);$codigo='cv_enlace';
            } elseif($accion==='cv_revocar'){
                $db->prepare('UPDATE instructor_cv_enlaces SET cerrado=1 WHERE instructor_id=?')->execute([$id]);unset($_SESSION['cv_enlace_'.$id]);$codigo='cv_revocado';
            } else {
                $temas=implode("\n",array_column(cengi_cv_cursos_asignados($db,$id),'nombre'));
                $db->beginTransaction();$q=$db->prepare('SELECT * FROM instructor_cv_digital WHERE instructor_id=? FOR UPDATE');$q->execute([$id]);$actualCv=$q->fetch(PDO::FETCH_ASSOC);
                if(!$actualCv||$actualCv['estado']!=='enviado'||!hash_equals(hash('sha256',$actualCv['datos']),(string)($_POST['revision']??'')))throw new InvalidArgumentException('El CV cambió o aún no fue enviado. Abre la versión actual antes de validar.');
                $db->prepare("UPDATE instructor_cv_digital SET estado='validado',temas=?,validado_por=?,validado_en=NOW(),actualizado=NOW() WHERE instructor_id=?")->execute([$temas,$usuario,$id]);
                $db->prepare("INSERT INTO instructor_cv_historial(instructor_id,accion,datos,usuario) VALUES(?,'validar',?,?)")->execute([$id,json_encode(['cv'=>json_decode($actualCv['datos'],true),'temas'=>$temas],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$usuario]);
                $db->commit();$codigo='cv_validado';
            }
        } elseif ($accion === 'datos') {
            $v = [
                'nombre' => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? ''))),
                'especialidad' => mb_substr(trim((string) ($_POST['especialidad'] ?? '')), 0, 255, 'UTF-8'),
                'institucion' => mb_substr(trim((string) ($_POST['institucion'] ?? '')), 0, 255, 'UTF-8') ?: null,
                'correo' => mb_strtolower(trim((string) ($_POST['correo'] ?? '')), 'UTF-8'),
                'telefono' => mb_substr(trim((string) ($_POST['telefono'] ?? '')), 0, 50, 'UTF-8'),
                'titulo' => mb_substr(trim((string) ($_POST['titulo'] ?? '')), 0, 255, 'UTF-8') ?: null,
                'codigo_catedratico' => mb_substr(strtoupper(trim((string) ($_POST['codigo_catedratico'] ?? ''))), 0, 20, 'UTF-8') ?: cengi_pp_codigo_instructor($id),
                'tarifa_sesion' => trim((string) ($_POST['tarifa_sesion'] ?? '')) === '' ? null : (float) str_replace(',', '', $_POST['tarifa_sesion']),
                'estado' => !empty($_POST['estado']) ? 1 : 0,
                'notas' => trim((string) ($_POST['notas'] ?? '')) ?: null,
            ];
            $errores = [];
            if ($tienePago) {
                $v['comprobante'] = ($_POST['comprobante'] ?? '') === 'recibo' ? 'recibo' : 'factura';
                $v['dpi'] = preg_replace('/\D/', '', (string) ($_POST['dpi'] ?? ''));
                if ($v['dpi'] !== '' && strlen($v['dpi']) !== 13) {
                    $errores[] = 'El DPI debe tener 13 dígitos.';
                } elseif ($v['dpi'] === '' && $v['comprobante'] === 'recibo') {
                    $errores[] = 'Para cobrar con recibo se necesita el DPI.';
                }
            }
            if (mb_strlen($v['nombre'], 'UTF-8') < 5 || mb_strlen($v['nombre'], 'UTF-8') > 255) {
                $errores[] = 'Escribe el nombre completo.';
            }
            if ($v['correo'] !== '' && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo no es válido.';
            }
            if ($v['tarifa_sesion'] !== null && $v['tarifa_sesion'] < 0) {
                $errores[] = 'La tarifa por sesión debe ser 0 o mayor.';
            }
            foreach (['nombre' => 'nombre', 'correo' => 'correo', 'codigo_catedratico' => 'código de instructor'] as $campo => $texto) {
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
        header('Location: ficha_instructor.php?' . http_build_query(['id' => $id, 'tab' => $_POST['tab'] ?? 'documentos', 'msg' => $codigo, 'digital' => !empty($_POST['digital']) ? 1 : null, 'individual' => !empty($_POST['individual']) ? 1 : null]));
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

$mensajes = ['cv_enlace'=>'Enlace individual generado. Es válido durante 30 días; el enlace anterior deja de funcionar.', 'cv_revocado'=>'Enlace desactivado.', 'cv_validado'=>'CV digital validado por Capacitación.', 'datos' => 'Datos del instructor actualizados.', 'subido' => 'Documento cargado.', 'quitado' => 'Documento retirado (se conserva en el historial).'];
if ($mensaje === '' && isset($mensajes[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h($mensajes[$_GET['msg']]);
}

// ---------------------------------------------------------------- datos de la ficha
$instructor = cengi_ficha_instructor($db, $id);
$doc = cengi_pp_documentacion($db, $id);
$codigoInstructor = cengi_pp_codigo_instructor($id, $instructor['codigo_catedratico']);
$codigoPropio = $codigoInstructor !== cengi_pp_codigo_instructor($id);
$tab = in_array($_GET['tab'] ?? '', ['sesiones', 'cursos', 'evaluaciones', 'pagos', 'documentos'], true) ? $_GET['tab'] : 'sesiones';
$ahora = date('Y-m-d H:i:s');

$stmt = $db->prepare("SELECT s.*, p.nombre AS plan_nombre, p.curso_id AS plan_curso_id, l.numero AS liq_numero FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN liquidaciones l ON l.id = s.liquidacion_id
    WHERE s.instructor_id = ? AND s.estado <> 'cancelada' ORDER BY s.fecha, s.hora_inicio");
$stmt->execute([$id]);
$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Igual que Base de datos e Instructores: impartida = marcada o con su horario ya terminado.
$termino = static function (array $s) use ($ahora) {
    return $s['fecha'] . ' ' . ($s['hora_fin'] ?: '23:59:59') <= $ahora;
};
$proximas = $pasadas = [];
$porPlan = [];
foreach ($sesiones as $s) {
    $hecha = $s['estado'] === 'impartida' || $termino($s);
    if ($hecha) {
        $pasadas[] = $s;
    } else {
        $proximas[] = $s;
    }
    $p = &$porPlan[(int) $s['planificacion_id']];
    $p = $p ?? ['nombre' => $s['plan_nombre'], 'curso_id' => (int) $s['plan_curso_id'], 'impartidas' => 0, 'pendientes' => 0, 'desde' => $s['fecha'], 'hasta' => $s['fecha']];
    $p[$hecha ? 'impartidas' : 'pendientes']++;
    $p['hasta'] = $s['fecha'];
    unset($p);
}
$pasadas = array_reverse($pasadas);
$impartidas = count($pasadas);
$sinMarcar = count(array_filter($pasadas, static function ($s) {
    return $s['estado'] !== 'impartida';
}));
$proxima = $proximas[0] ?? null;

// Cursos: instructor principal, de módulo o con sesiones en el Programa de sesiones (como en Instructores).
$inscritos = '(SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id AND a.estado_asignaciones = 1) AS inscritos';
$stmt = $db->prepare("SELECT c.id, c.codigo_curso, c.nombre_cursos, c.tipo, c.inicio, c.fin, 'Instructor principal' AS rol, $inscritos
    FROM cursos c WHERE c.instructor_id = ?
    UNION
    SELECT c.id, c.codigo_curso, c.nombre_cursos, c.tipo, c.inicio, c.fin, CONCAT('Módulo: ', cm.nombre) AS rol, $inscritos
    FROM curso_modulo_instructores cmi INNER JOIN curso_modulos cm ON cm.id = cmi.curso_modulo_id INNER JOIN cursos c ON c.id = cm.curso_id
    WHERE cmi.instructor_id = ?
    UNION
    SELECT DISTINCT c.id, c.codigo_curso, c.nombre_cursos, c.tipo, c.inicio, c.fin, 'Sesiones del programa' AS rol, $inscritos
    FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id INNER JOIN cursos c ON c.id = p.curso_id
    WHERE s.instructor_id = ? AND s.estado <> 'cancelada'
    ORDER BY inicio DESC");
$stmt->execute([$id, $id, $id]);
$cursos = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cid = (int) $c['id'];
    if (!isset($cursos[$cid])) {
        $c['roles'] = [];
        $c['impartidas'] = $c['pendientes'] = 0;
        $cursos[$cid] = $c;
    }
    $cursos[$cid]['roles'][] = $c['rol'];
}
foreach ($porPlan as $p) {
    if (isset($cursos[$p['curso_id']])) {
        $cursos[$p['curso_id']]['impartidas'] += $p['impartidas'];
        $cursos[$p['curso_id']]['pendientes'] += $p['pendientes'];
    }
}

$stmt = $db->prepare("SELECT ei.*, COALESCE(c.nombre_cursos, ei.curso_nombre) AS curso, ing.nombre_ingenios AS ingenio
    FROM evaluaciones_instructor ei INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
    LEFT JOIN cursos c ON c.id = eei.curso_id LEFT JOIN ingenios ing ON ing.id = ei.ingenio_id
    WHERE eei.instructor_id = ? ORDER BY COALESCE(ei.fecha_curso, DATE(ei.creado)) DESC, ei.id DESC");
$stmt->execute([$id]);
$evaluaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$promedio = static function (array $valores) {
    $valores = array_filter($valores, static function ($x) {
        return $x !== null && $x !== '';
    });
    return $valores ? array_sum($valores) / count($valores) : null;
};
$porCriterio = [];
foreach (CENGI_FICHA_CRITERIOS as $campo => $texto) {
    $porCriterio[$campo] = $promedio(array_column($evaluaciones, $campo));
}
$promedioEval = $promedio($porCriterio);
$promedioRecomienda = $promedio(array_column($evaluaciones, 'recomendaria_instructor'));

$stmt = $db->prepare("SELECT d.*, l.numero, l.desde, l.hasta, l.estado, l.pago_fecha FROM liquidacion_detalle d
    INNER JOIN liquidaciones l ON l.id = d.liquidacion_id WHERE d.tipo = 'instructor' AND d.persona_id = ? ORDER BY l.desde DESC");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPagado = $totalPendiente = 0;
foreach ($pagos as $p) {
    if ($p['estado'] === 'pagada') {
        $totalPagado += (float) $p['total'];
    } elseif ($p['estado'] !== 'anulada') {
        $totalPendiente += (float) $p['total'];
    }
}
// Recibos enviados para firma (pago sin factura).
$recibos = [];
if ($pagos && $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'liquidacion_recibos'")->fetchColumn()) {
    $ids = array_map('intval', array_column($pagos, 'id'));
    $stmt = $db->prepare('SELECT * FROM liquidacion_recibos WHERE detalle_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $recibos = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'detalle_id');
}
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

// Expediente: lo que se necesita para asignarle sesiones y pagarle.
$esRecibo = $tienePago && ($instructor['comprobante'] ?? 'factura') === 'recibo';
$tieneTarifa = $instructor['tarifa_sesion'] !== null && (float) $instructor['tarifa_sesion'] > 0;
$checklist = [
    ['CV', $doc['cv'], 'Documentos', 'subir', 'cv'],
    ['Carta de comprobación', !empty($doc['cv_digital']) || $doc['carta'], 'Documentos', 'subir', 'carta'],
    ['Formación registrada', !empty($doc['cv_digital']) || $doc['titulo'], 'Documentos', 'datos', 'fi-titulo'],
    ['Correo', trim((string) $instructor['correo']) !== '', 'Contacto', 'datos', 'fi-correo'],
    ['Tarifa por sesión', $tieneTarifa, 'Pago', 'datos', 'fi-tarifa'],
];
if (!empty($doc['cv_digital'])) $checklist = array_values(array_filter($checklist, static function($item) { return !in_array($item[0], ['Carta de comprobación', 'Formación registrada'], true); }));
if ($esRecibo) {
    $checklist[] = ['DPI (cobra con recibo)', trim((string) ($instructor['dpi'] ?? '')) !== '', 'Pago', 'datos', 'fi-dpi'];
}
$listos = count(array_filter(array_column($checklist, 1)));

function cengi_ficha_nivel($valor, $escala = 4)
{
    if ($valor === null) {
        return ['Sin datos', 'is-gris'];
    }
    $v = $valor * 4 / $escala;
    return $v >= 3.2 ? ['Excelente', 'is-verde'] : ($v >= 2.4 ? ['Bueno', 'is-amarillo'] : ['Por mejorar', 'is-naranja']);
}

function cengi_ficha_tamano($bytes)
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

$url = static function (array $extra = []) use ($id) {
    return 'ficha_instructor.php?' . http_build_query(['id' => $id] + $extra);
};
[$evalTexto, $evalClase] = cengi_ficha_nivel($promedioEval);
[, $recClase] = cengi_ficha_nivel($promedioRecomienda, 10);
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Ficha · ' . $instructor['nombre']); ?>
<link rel="stylesheet" href="css/instructores_tema.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/instructores_tema.css'); ?>"></head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page fi-page">
    <?php cengi_nav_barra('ficha_instructor.php'); ?>
    <?php cengi_pp_feedback($reabrir ? '' : $mensaje, $mensajeTipo); ?>

    <header class="inst-hero fi-hero<?php echo (int) $instructor['estado'] === 1 ? '' : ' is-inactivo'; ?>">
        <div class="fi-avatar" aria-hidden="true"><?php echo cengi_pp_h(cengi_pp_iniciales($instructor['nombre'])); ?></div>
        <div class="fi-hero-main">
            <small>Ficha del instructor<?php echo (int) $instructor['estado'] === 1 ? '' : ' · inactivo'; ?></small>
            <h1><?php echo cengi_pp_h($instructor['nombre']); ?></h1>
            <p><?php echo cengi_pp_h(($instructor['especialidad'] ?: 'Sin especialidad') . ' · ' . ($instructor['institucion'] ?: 'Institución sin registrar')); ?></p>
            <div class="fi-hero-tags">
                <span class="fi-codigo" title="<?php echo $codigoPropio ? 'Código de catedrático institucional' : 'Código interno'; ?>"><span class="glyphicon glyphicon-tag"></span><?php echo cengi_pp_h($codigoInstructor); ?></span>
                <?php if ($instructor['correo']): ?><a href="mailto:<?php echo cengi_pp_h($instructor['correo']); ?>"><span class="glyphicon glyphicon-envelope"></span><?php echo cengi_pp_h($instructor['correo']); ?></a><?php endif; ?>
                <?php if ($instructor['telefono']): ?><a href="tel:<?php echo cengi_pp_h(preg_replace('/[^\d+]/', '', $instructor['telefono'])); ?>"><span class="glyphicon glyphicon-earphone"></span><?php echo cengi_pp_h($instructor['telefono']); ?></a><?php endif; ?>
                <?php if ($instructor['titulo']): ?><span><span class="glyphicon glyphicon-education"></span><?php echo cengi_pp_h($instructor['titulo']); ?></span><?php endif; ?>
            </div>
        </div>
        <div class="fi-hero-acciones">
            <button type="button" class="fi-btn is-claro" data-toggle="modal" data-target="#datos-modal"><span class="glyphicon glyphicon-pencil"></span> Editar datos</button>
            <button type="button" class="fi-btn" data-toggle="modal" data-target="#subir-modal"><span class="glyphicon glyphicon-upload"></span> Subir documento</button>
        </div>
    </header>

    <section class="inst-kpis" aria-label="Resumen">
        <div class="is-verde"><span class="glyphicon glyphicon-ok-circle"></span><b><?php echo $impartidas; ?></b><em>Sesiones impartidas<?php echo $sinMarcar ? ' · ' . $sinMarcar . ' sin marcar' : ''; ?></em></div>
        <div class="is-amarillo"><span class="glyphicon glyphicon-calendar"></span><b><?php echo count($proximas); ?></b><em><?php echo $proxima ? 'Por realizar · próxima ' . cengi_pp_h(cengi_pp_fecha_corta($proxima['fecha'])) : 'Sesiones por realizar'; ?></em></div>
        <div class="is-verde2"><span class="glyphicon glyphicon-book"></span><b><?php echo count($cursos); ?></b><em>Cursos · <?php echo count($porPlan); ?> plan(es) en el programa</em></div>
        <div class="<?php echo $evalClase; ?>"><span class="glyphicon glyphicon-star"></span><b><?php echo $promedioEval !== null ? number_format($promedioEval, 1) . ' <small>/ 4</small>' : '—'; ?></b><em><?php echo $evaluaciones ? $evalTexto . ' · ' . count($evaluaciones) . ' encuesta(s)' : 'Sin evaluaciones'; ?></em></div>
        <div class="<?php echo $recClase; ?>"><span class="glyphicon glyphicon-thumbs-up"></span><b><?php echo $promedioRecomienda !== null ? number_format($promedioRecomienda, 1) . ' <small>/ 10</small>' : '—'; ?></b><em>Lo recomendarían</em></div>
        <div class="<?php echo $totalPendiente > 0 ? 'is-naranja' : 'is-verde'; ?>"><span class="glyphicon glyphicon-credit-card"></span><b><?php echo cengi_pp_q($totalPagado); ?></b><em>Pagado<?php echo $totalPendiente > 0 ? ' · ' . cengi_pp_q($totalPendiente) . ' en trámite' : ''; ?></em></div>
    </section>

    <section class="fi-expediente<?php echo $listos === count($checklist) ? ' is-completo' : ''; ?>" aria-label="Expediente">
        <div class="fi-exp-titulo">
            <strong><?php echo $listos === count($checklist) ? 'Expediente completo' : 'Expediente: ' . $listos . ' de ' . count($checklist); ?></strong>
            <span class="fi-barra"><span style="width:<?php echo round($listos * 100 / count($checklist)); ?>%"></span></span>
            <small><?php echo $doc['completa'] ? 'Documentación completa para asignarle sesiones.' : ($doc['activo'] ? 'Documentación incompleta' . (count($proximas) ? ': tiene ' . count($proximas) . ' sesión(es) por realizar.' : '.') : 'Instructor inactivo.'); ?></small>
        </div>
        <div class="fi-exp-items">
            <?php foreach ($checklist as [$texto, $ok, $grupo, $modal, $destino]): ?>
                <?php if ($ok): ?>
                    <span class="fi-item is-ok" title="<?php echo $grupo; ?>"><span class="glyphicon glyphicon-ok"></span><?php echo $texto; ?></span>
                <?php else: ?>
                    <button type="button" class="fi-item is-falta" data-toggle="modal" data-target="#<?php echo $modal; ?>-modal" <?php echo $modal === 'subir' ? 'data-tipo="' . $destino . '"' : 'data-foco="' . $destino . '"'; ?> title="<?php echo $grupo; ?>: agregar"><span class="glyphicon glyphicon-plus"></span><?php echo $texto; ?></button>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($tienePago): ?><span class="fi-item is-info" title="Comprobante con el que cobra"><span class="glyphicon glyphicon-file"></span><?php echo CENGI_PP_COMPROBANTES[$esRecibo ? 'recibo' : 'factura']; ?></span><?php endif; ?>
        </div>
    </section>

    <section class="fi-panel">
        <nav class="fi-tabs" role="tablist">
            <?php foreach ([
                'sesiones' => ['Sesiones', count($sesiones), 'calendar'],
                'cursos' => ['Cursos', count($cursos), 'book'],
                'evaluaciones' => ['Evaluaciones', count($evaluaciones), 'star'],
                'pagos' => ['Pagos y tarifas', count($pagos), 'credit-card'],
                'documentos' => ['Documentos', count($vigente) + count(array_filter($documentos, static function ($d) { return $d['tipo'] === 'otro' && $d['vigente']; })), 'folder-open'],
            ] as $clave => [$texto, $n, $icono]): ?>
                <a href="<?php echo cengi_pp_h($url(['tab' => $clave])); ?>" class="<?php echo $tab === $clave ? 'is-active' : ''; ?>" <?php echo $tab === $clave ? 'aria-current="page"' : ''; ?>><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span><?php echo $texto; ?><b><?php echo $n; ?></b></a>
            <?php endforeach; ?>
        </nav>
        <div class="fi-panel-body">
        <?php if ($tab === 'sesiones'): ?>
            <?php if ($proxima): ?>
                <div class="fi-proxima"><span class="glyphicon glyphicon-time"></span>
                    <div><small>Próxima sesión</small><strong><?php echo cengi_pp_h(cengi_pp_fecha_corta($proxima['fecha'])) . ' · ' . cengi_pp_horario($proxima['hora_inicio'], $proxima['hora_fin']); ?></strong>
                        <span><?php echo cengi_pp_h($proxima['plan_nombre'] . ' — ' . $proxima['tema'] . ($proxima['lugar'] ? ' · ' . $proxima['lugar'] : '')); ?></span></div>
                    <a class="fi-btn is-linea" href="programa_sesiones.php?editar=<?php echo (int) $proxima['id']; ?>">Editar sesión</a></div>
            <?php endif; ?>
            <?php if (!$sesiones): ?>
                <p class="fi-vacio">No tiene sesiones en el Programa de sesiones. Se le asignan desde <a href="programa_sesiones.php">Programa de sesiones</a>.</p>
            <?php else: ?>
                <div class="fi-barra-herr">
                    <input type="search" class="fi-buscar" data-buscar="#fi-ses" placeholder="Buscar por curso, tema, lugar o fecha…" aria-label="Buscar sesiones">
                    <a class="fi-btn is-linea" href="programa_sesiones.php?instructor=<?php echo $id; ?>"><span class="glyphicon glyphicon-list"></span> Ver en Programa de sesiones</a>
                </div>
                <div id="fi-ses">
                <?php foreach (['Por realizar' => $proximas, 'Impartidas' => $pasadas] as $titulo => $lista): if (!$lista) { continue; } ?>
                    <h3 class="fi-h"><?php echo $titulo; ?> <b><?php echo count($lista); ?></b><?php echo $titulo === 'Impartidas' && $sinMarcar ? ' <small>' . $sinMarcar . ' con horario terminado sin marcar como impartida</small>' : ''; ?></h3>
                    <div class="fi-lista<?php echo $titulo === 'Impartidas' ? ' is-alta' : ''; ?>">
                        <?php foreach ($lista as $s): $marcada = $s['estado'] === 'impartida'; ?>
                            <div class="fi-fila <?php echo $titulo === 'Impartidas' ? ($marcada ? 'is-hecha' : 'is-sin-marcar') : 'is-proxima'; ?>" data-texto="<?php echo cengi_pp_h(mb_strtolower($s['plan_nombre'] . ' ' . $s['modulo'] . ' ' . $s['tema'] . ' ' . $s['lugar'] . ' ' . $s['modalidad'] . ' ' . cengi_pp_fecha($s['fecha']), 'UTF-8')); ?>">
                                <div class="fi-fecha"><b><?php echo date('d', strtotime($s['fecha'])); ?></b><span><?php echo CENGI_PP_MESES_CORTOS[(int) date('n', strtotime($s['fecha']))] . ' ' . date('Y', strtotime($s['fecha'])); ?></span></div>
                                <div class="fi-fila-main"><strong><?php echo cengi_pp_h($s['plan_nombre']); ?></strong>
                                    <small><?php echo cengi_pp_h(trim($s['modulo'] . ' — ' . $s['tema'], ' —')); ?></small>
                                    <small><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']) . ' · ' . cengi_pp_h($s['modalidad'] . ($s['lugar'] ? ' · ' . $s['lugar'] : '')); ?></small></div>
                                <div class="fi-fila-estado">
                                    <?php if ($titulo === 'Por realizar'): ?><span class="fi-chip is-amarillo">Programada</span>
                                    <?php elseif ($marcada): ?><span class="fi-chip is-verde">Impartida</span>
                                    <?php else: ?><span class="fi-chip is-gris" title="El horario ya terminó; cuenta como impartida">Sin marcar</span><?php endif; ?>
                                    <?php echo $s['liq_numero'] ? '<small class="pp-mono" title="Liquidación">' . cengi_pp_h($s['liq_numero']) . '</small>' : ''; ?>
                                </div>
                                <a class="fi-icono" href="programa_sesiones.php?editar=<?php echo (int) $s['id']; ?>" title="Editar sesión" aria-label="Editar sesión"><span class="glyphicon glyphicon-pencil"></span></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p class="fi-vacio" data-sin-resultados hidden>Ninguna sesión coincide con la búsqueda.</p>
                </div>
            <?php endif; ?>
        <?php elseif ($tab === 'cursos'): ?>
            <?php if (!$cursos && !$porPlan): ?>
                <p class="fi-vacio">No tiene cursos asignados en Gestión de cursos ni sesiones en el Programa de sesiones.</p>
            <?php endif; ?>
            <?php if ($cursos): ?>
                <h3 class="fi-h">Cursos <b><?php echo count($cursos); ?></b></h3>
                <div class="fi-lista">
                    <?php foreach ($cursos as $c): $total = $c['impartidas'] + $c['pendientes']; ?>
                        <div class="fi-fila">
                            <div class="fi-fila-main"><strong><a href="ver_participante_curso.php?id=<?php echo (int) $c['id']; ?>"><?php echo cengi_pp_h($c['nombre_cursos']); ?></a></strong>
                                <small><span class="pp-mono"><?php echo cengi_pp_h($c['codigo_curso']); ?></span> · <?php echo cengi_pp_h($c['tipo']); ?> · <?php echo cengi_pp_fecha($c['inicio']) . ' – ' . cengi_pp_fecha($c['fin']); ?></small>
                                <span class="fi-roles"><?php foreach (array_unique($c['roles']) as $r): ?><span class="fi-chip is-verde2"><?php echo cengi_pp_h($r); ?></span><?php endforeach; ?></span></div>
                            <div class="fi-fila-dato"><b><?php echo (int) $c['inscritos']; ?></b><small>inscritos</small></div>
                            <div class="fi-fila-dato fi-avance"><?php if ($total): ?><b><?php echo $c['impartidas']; ?>/<?php echo $total; ?></b><small>sesiones suyas</small><span class="fi-barra"><span style="width:<?php echo round($c['impartidas'] * 100 / $total); ?>%"></span></span><?php else: ?><small>Sin sesiones en el programa</small><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($porPlan): ?>
                <h3 class="fi-h">En el Programa de sesiones <b><?php echo count($porPlan); ?></b></h3>
                <div class="fi-lista">
                    <?php foreach ($porPlan as $planId => $p): $total = $p['impartidas'] + $p['pendientes']; ?>
                        <div class="fi-fila">
                            <div class="fi-fila-main"><strong><a href="programa_sesiones.php?ver=<?php echo (int) $planId; ?>"><?php echo cengi_pp_h($p['nombre']); ?></a></strong>
                                <small><?php echo cengi_pp_fecha($p['desde']) . ' – ' . cengi_pp_fecha($p['hasta']); ?><?php echo $p['curso_id'] ? '' : ' · <span class="fi-falta">sin curso vinculado en Gestión de cursos</span>'; ?></small></div>
                            <div class="fi-fila-dato fi-avance"><b><?php echo $p['impartidas']; ?>/<?php echo $total; ?></b><small>impartidas · <?php echo $p['pendientes']; ?> por realizar</small><span class="fi-barra"><span style="width:<?php echo round($p['impartidas'] * 100 / $total); ?>%"></span></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif ($tab === 'evaluaciones'): ?>
            <?php if (!$evaluaciones): ?>
                <p class="fi-vacio">Todavía no hay evaluaciones de participantes para este instructor. Se reciben con el enlace de evaluación de cada curso (Instructores → Informe) o con la carga masiva de evaluaciones.</p>
            <?php else: ?>
                <div class="fi-eval-grid">
                    <div class="fi-caja">
                        <h3 class="fi-h">Promedio por criterio <small>Excelente 4 · Bueno 3 · Regular 2 · Deficiente 1</small></h3>
                        <?php foreach (CENGI_FICHA_CRITERIOS as $campo => $texto): $valor = $porCriterio[$campo]; [, $cl] = cengi_ficha_nivel($valor); ?>
                            <div class="fi-crit <?php echo $cl; ?>"><span><?php echo $texto; ?></span><span class="fi-barra"><span style="width:<?php echo $valor !== null ? round($valor / 4 * 100) : 0; ?>%"></span></span><b><?php echo $valor !== null ? number_format($valor, 1) : '—'; ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="fi-caja fi-recomienda <?php echo $recClase; ?>">
                        <small>¿Le gustaría recibir otro curso con este instructor?</small>
                        <strong><?php echo $promedioRecomienda !== null ? number_format($promedioRecomienda, 1) : '—'; ?><span> / 10</span></strong>
                        <span class="fi-barra"><span style="width:<?php echo round((float) $promedioRecomienda * 10); ?>%"></span></span>
                        <small>Basado en <?php echo count($evaluaciones); ?> evaluación(es) en <?php echo count(array_unique(array_column($evaluaciones, 'curso'))); ?> curso(s).</small>
                    </div>
                </div>
                <h3 class="fi-h">Respuestas <b><?php echo count($evaluaciones); ?></b></h3>
                <div class="fi-lista">
                    <?php foreach ($evaluaciones as $e):
                        $prom = $promedio(array_map(static function ($c) use ($e) {
                            return $e[$c];
                        }, array_keys(CENGI_FICHA_CRITERIOS)));
                        [$etiqueta, $clase] = cengi_ficha_nivel($prom);
                        $comentario = trim($e['areas_mejora'] . ($e['capacitaciones_necesarias'] ? ' · Necesidades: ' . $e['capacitaciones_necesarias'] : ''));
                    ?>
                        <div class="fi-fila">
                            <div class="fi-fila-main"><strong><?php echo cengi_pp_h($e['curso']); ?><?php echo $e['modulo_nombre'] ? ' · ' . cengi_pp_h($e['modulo_nombre']) : ''; ?></strong>
                                <small><?php echo cengi_pp_h(($e['cargo'] ?: 'Anónimo') . (($e['ingenio'] ?: $e['ingenio_otro']) ? ' · ' . ($e['ingenio'] ?: $e['ingenio_otro']) : '') . ($e['modalidad'] ? ' · ' . $e['modalidad'] : '')); ?> · <?php echo cengi_pp_fecha($e['fecha_curso'] ?: $e['creado']); ?></small>
                                <?php if ($comentario !== ''): ?><p class="fi-comentario"><?php echo cengi_pp_h($comentario); ?></p><?php endif; ?></div>
                            <div class="fi-fila-estado"><span class="fi-chip <?php echo $clase; ?>"><?php echo $etiqueta . ($prom !== null ? ' · ' . number_format($prom, 1) : ''); ?></span>
                                <?php if ($e['recomendaria_instructor'] !== null): ?><small><?php echo (int) $e['recomendaria_instructor']; ?>/10 lo recomendaría</small><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif ($tab === 'pagos'): ?>
            <div class="fi-datos-pago">
                <div><small>Tarifa base por sesión</small><b><?php echo $tieneTarifa ? cengi_pp_q($instructor['tarifa_sesion'], 2) : '<span class="fi-falta">Sin tarifa</span>'; ?></b></div>
                <?php if ($tienePago): ?>
                    <div><small>Comprobante</small><b><?php echo CENGI_PP_COMPROBANTES[$esRecibo ? 'recibo' : 'factura']; ?></b></div>
                    <div><small>DPI</small><b><?php echo trim((string) $instructor['dpi']) !== '' ? cengi_pp_h(preg_replace('/^(\d{4})(\d{5})(\d{4})$/', '$1 $2 $3', $instructor['dpi'])) : ($esRecibo ? '<span class="fi-falta">Falta (cobra con recibo)</span>' : '—'); ?></b></div>
                <?php endif; ?>
                <div><small>Pagado</small><b><?php echo cengi_pp_q($totalPagado, 2); ?></b></div>
                <div><small>En trámite</small><b><?php echo cengi_pp_q($totalPendiente, 2); ?></b></div>
                <button type="button" class="fi-btn is-linea" data-toggle="modal" data-target="#datos-modal" data-foco="fi-tarifa"><span class="glyphicon glyphicon-pencil"></span> Editar</button>
            </div>
            <?php if ($tarifasCurso): ?>
                <h3 class="fi-h">Tarifas por curso <b><?php echo count($tarifasCurso); ?></b></h3>
                <div class="fi-lista">
                    <?php foreach ($tarifasCurso as $t): ?><div class="fi-fila"><div class="fi-fila-main"><strong><?php echo cengi_pp_h($t['plan_nombre']); ?></strong><small>Año <?php echo cengi_pp_anio_texto($t['anio']); ?><?php echo $t['observaciones'] ? ' · ' . cengi_pp_h($t['observaciones']) : ''; ?></small></div><div class="fi-fila-dato"><b><?php echo cengi_pp_q($t['tarifa'], 2); ?></b><small>por sesión</small></div></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <h3 class="fi-h">Liquidaciones <b><?php echo count($pagos); ?></b></h3>
            <?php if (!$pagos): ?><p class="fi-vacio">Sin liquidaciones. Se generan en <a href="pago_instructores.php">Pago a instructores</a> con las sesiones impartidas.</p><?php endif; ?>
            <div class="fi-lista">
                <?php foreach ($pagos as $p): $r = $recibos[(int) $p['id']] ?? null; $conRecibo = ($p['comprobante'] ?? '') === 'recibo'; ?>
                    <div class="fi-fila">
                        <div class="fi-fila-main"><strong><a class="pp-mono" href="pago_instructores.php?tab=liquidaciones&amp;ver=<?php echo (int) $p['liquidacion_id']; ?>"><?php echo cengi_pp_h($p['numero']); ?></a> · <?php echo cengi_pp_h($p['curso_nombre']); ?></strong>
                            <small><?php echo cengi_pp_fecha($p['desde']) . ' – ' . cengi_pp_fecha($p['hasta']); ?> · <?php echo (int) $p['sesiones']; ?> sesión(es) × <?php echo cengi_pp_q($p['tarifa'], 2); ?></small>
                            <?php if ($tienePago): ?><small><?php echo $conRecibo ? 'Recibo' . (!empty($p['documento_numero']) ? ' N.° ' . cengi_pp_h($p['documento_numero']) : '') : 'Factura'; ?>
                                <?php if ($conRecibo): ?> ·
                                    <?php if ($r && $r['firmado_path']): ?><a href="pago_instructores.php?recibo_firmado=<?php echo (int) $p['id']; ?>" target="_blank" rel="noopener" class="fi-chip is-verde">Firmado <?php echo cengi_pp_fecha($r['firmado_en']); ?></a>
                                    <?php elseif ($r && $r['enviado_en']): ?><span class="fi-chip is-amarillo">Enviado <?php echo cengi_pp_fecha($r['enviado_en']); ?> · pendiente de firma</span>
                                    <?php else: ?><span class="fi-chip is-gris">Sin enviar para firma</span><?php endif; ?>
                                <?php endif; ?></small><?php endif; ?></div>
                        <div class="fi-fila-dato"><b><?php echo cengi_pp_q($p['total'], 2); ?></b><small>con impuesto</small></div>
                        <div class="fi-fila-estado"><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_LIQ, $p['estado']); ?><?php echo $p['pago_fecha'] ? '<small>Pagada ' . cengi_pp_fecha($p['pago_fecha']) . '</small>' : ''; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php $soloDigital = ($_GET['digital'] ?? '') === '1'; $soloIndividual = ($_GET['individual'] ?? '') === '1'; ?>
            <?php if (!$soloIndividual) require __DIR__ . '/instructor_cv_panel.php'; ?>
            <?php if (!$soloDigital): ?>
            <?php if (!empty($doc['cv_digital'])): ?>
            <p class="fi-chip is-verde">CV digital recibido: no es necesario subir el CV en PDF, la carta de formación ni el título o diploma.</p>
            
            <?php endif; ?>
            <?php if (empty($doc['cv_digital']) || ($_GET['individual'] ?? '') === '1'): ?>
            <h3 class="fi-h" id="documentacion-individual">Documentación individual · CV, carta y formación</h3>
            <div class="fi-docs">
            <?php
            $tarjetas = [
                'cv' => $instructor['cv_path'] && $doc['cv'] ? ($vigente['cv'] ?? ['nombre_original' => basename((string) $instructor['cv_path']), 'legado' => true]) : null,
                'carta' => $vigente['carta'] ?? null,
                'titulo' => $vigente['titulo'] ?? null,
            ];
            foreach ($tarjetas as $tipo => $d):
                $requerido = $tipo !== 'titulo' && empty($doc['cv_digital']);
            ?>
                <div class="fi-doc <?php echo $d ? 'is-ok' : ($requerido ? 'is-falta' : ''); ?>">
                    <span class="fi-doc-icono glyphicon glyphicon-<?php echo $d ? 'ok' : ($requerido ? 'exclamation-sign' : 'file'); ?>"></span>
                    <div class="fi-doc-main"><strong><?php echo CENGI_PP_TIPOS_DOCUMENTO[$tipo]; ?><?php echo $requerido ? '' : ' <em>opcional</em>'; ?></strong>
                        <small><?php
                            if (!$d && !empty($doc['cv_digital'])) { echo $tipo === 'cv' ? 'CV digital recibido. El archivo PDF es opcional.' : 'Documento complementario opcional; puede cargarse posteriormente.'; } elseif (!$d) {
                                echo $tipo === 'titulo' ? 'Respaldo del título. La formación se registra en “Editar datos”.' : 'No se ha cargado · requerido para la documentación completa';
                            } elseif (!empty($d['legado'])) {
                                echo cengi_pp_h($d['nombre_original']) . ' · cargado desde Instructores';
                            } else {
                                echo cengi_pp_h($d['nombre_original']) . ' · ' . cengi_ficha_tamano($d['tamano']) . ' · subido ' . cengi_pp_fecha($d['creado']) . ($d['subido_por_nombre'] ? ' por ' . cengi_pp_h($d['subido_por_nombre']) : '')
                                    . ($d['emisor'] ? '<br>Emitido por ' . cengi_pp_h($d['emisor']) . ($d['fecha_emision'] ? ' el ' . cengi_pp_fecha($d['fecha_emision']) : '') : '');
                            }
                        ?></small></div>
                    <div class="fi-doc-acciones">
                        <?php if ($d): ?>
                            <a class="fi-btn is-linea" target="_blank" rel="noopener" href="documento_instructor.php?<?php echo $tipo === 'cv' ? 'cv=' . $id : 'doc=' . (int) $d['id']; ?>"><span class="glyphicon glyphicon-eye-open"></span> Ver</a>
                            <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Retirar este documento? Se conserva en el historial.');">
                                <input type="hidden" name="accion" value="quitar"><input type="hidden" name="tipo" value="<?php echo $tipo; ?>"><input type="hidden" name="doc" value="<?php echo (int) ($d['id'] ?? 0); ?>">
                                <button type="submit" class="fi-btn is-texto">Retirar</button></form>
                        <?php endif; ?>
                        <button type="button" class="fi-btn <?php echo $d ? 'is-linea' : ''; ?>" data-toggle="modal" data-target="#subir-modal" data-tipo="<?php echo $tipo; ?>"><span class="glyphicon glyphicon-upload"></span> <?php echo $d ? 'Reemplazar' : 'Subir'; ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!$soloIndividual): ?><div class="fi-datos-pago">
                <?php if (empty($doc['cv_digital'])): ?><div><small>Formación que lo respalda</small><b><?php echo $instructor['titulo'] ? cengi_pp_h($instructor['titulo']) : '<span class="fi-falta">Sin registrar</span>'; ?></b></div>
                <?php endif; ?><div><small>Estado de la documentación</small><b><?php echo $doc['completa'] ? '<span class="fi-chip is-verde">Completa</span>' : '<span class="fi-chip is-naranja">Falta: ' . cengi_pp_h(implode(', ', $doc['faltan']) ?: 'activarlo') . '</span>'; ?></b></div>
                <?php if (empty($doc['cv_digital']) && !$instructor['titulo']): ?><button type="button" class="fi-btn is-linea" data-toggle="modal" data-target="#datos-modal" data-foco="fi-titulo"><span class="glyphicon glyphicon-education"></span> Registrar formación</button><?php endif; ?>
            </div>
            <?php if ($instructor['notas']): ?><div class="fi-notas"><small>Notas</small><?php echo nl2br(cengi_pp_h($instructor['notas'])); ?></div><?php endif; ?>

            <?php $otros = array_filter($documentos, static function ($d) {
                return $d['tipo'] === 'otro' && $d['vigente'];
            }); ?>
            <h3 class="fi-h">Otros documentos <b><?php echo count($otros); ?></b> <button type="button" class="fi-btn is-texto" data-toggle="modal" data-target="#subir-modal" data-tipo="otro">+ Agregar</button></h3>
            <div class="fi-lista">
                <?php foreach ($otros as $d): ?>
                    <div class="fi-fila"><div class="fi-fila-main"><strong><a target="_blank" rel="noopener" href="documento_instructor.php?doc=<?php echo (int) $d['id']; ?>"><?php echo cengi_pp_h($d['descripcion'] ?: $d['nombre_original']); ?></a></strong><small><?php echo cengi_pp_h($d['nombre_original']) . ' · ' . cengi_ficha_tamano($d['tamano']) . ' · ' . cengi_pp_fecha($d['creado']); ?></small></div>
                        <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Retirar este documento?');"><input type="hidden" name="accion" value="quitar"><input type="hidden" name="doc" value="<?php echo (int) $d['id']; ?>"><button type="submit" class="fi-btn is-texto">Retirar</button></form></div>
                <?php endforeach; ?>
            </div>

            <?php $historial = array_filter($documentos, static function ($d) {
                return !$d['vigente'];
            }); ?>
            <?php if ($historial): ?>
                <details class="fi-historial"><summary>Historial de versiones anteriores (<?php echo count($historial); ?>)</summary>
                    <div class="fi-lista">
                    <?php foreach ($historial as $d): ?>
                        <div class="fi-fila"><div class="fi-fila-main"><strong><a target="_blank" rel="noopener" href="documento_instructor.php?doc=<?php echo (int) $d['id']; ?>"><?php echo cengi_pp_h($d['nombre_original']); ?></a></strong><small><?php echo CENGI_PP_TIPOS_DOCUMENTO[$d['tipo']] ?? cengi_pp_h($d['tipo']); ?> · subido <?php echo cengi_pp_fecha($d['creado']); ?></small></div></div>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
            <?php endif; ?>
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
                <span class="cengi-course-modal-kicker"><?php echo cengi_pp_h($codigoInstructor); ?></span><h4 class="modal-title" id="datos-title">Editar datos del instructor</h4></div>
            <div class="modal-body">
                <?php if ($reabrir === 'datos'): ?><div class="pp-form-msg is-visible is-error"><?php echo $mensaje; ?></div><?php endif; ?>
                <?php $v = $reabrir === 'datos' ? $_POST : $instructor; ?>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full"><label for="fi-nombre">Nombre completo</label><input type="text" class="form-control" id="fi-nombre" name="nombre" maxlength="255" minlength="5" required value="<?php echo cengi_pp_h($v['nombre'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-esp">Especialidad</label><input type="text" class="form-control" id="fi-esp" name="especialidad" maxlength="255" value="<?php echo cengi_pp_h($v['especialidad'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-inst">Institución</label><input type="text" class="form-control" id="fi-inst" name="institucion" maxlength="255" placeholder="CENGICAÑA" value="<?php echo cengi_pp_h($v['institucion'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-correo">Correo</label><input type="email" class="form-control" id="fi-correo" name="correo" maxlength="255" value="<?php echo cengi_pp_h($v['correo'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="fi-tel">Teléfono</label><input type="text" class="form-control" id="fi-tel" name="telefono" maxlength="50" value="<?php echo cengi_pp_h($v['telefono'] ?? ''); ?>"></div>
                    <div class="form-group is-full"><label for="fi-titulo">Formación que lo respalda</label><input type="text" class="form-control" id="fi-titulo" name="titulo" maxlength="255" placeholder="Ej. M.Sc. en Entomología — Universidad de San Carlos" value="<?php echo cengi_pp_h($v['titulo'] ?? ''); ?>">
                        <p class="help-block">Si el instructor ya envió su CV digital, no necesita subir documentos individuales. Esta información adicional es opcional en ese caso.</p></div>
                    <div class="form-group"><label for="fi-cat">Código de instructor</label><input type="text" class="form-control" id="fi-cat" name="codigo_catedratico" maxlength="20" placeholder="<?php echo cengi_pp_h(cengi_pp_codigo_instructor($id)); ?>" value="<?php echo cengi_pp_h($v['codigo_catedratico'] ?? ''); ?>">
                        <p class="help-block">Vacío = código interno <?php echo cengi_pp_h(cengi_pp_codigo_instructor($id)); ?>. Se usa en Programa de sesiones, carga masiva y pago.</p></div>
                    <div class="form-group"><label for="fi-estado">Estado</label><select class="form-control" id="fi-estado" name="estado"><option value="1">Activo</option><option value="0" <?php echo (int) ($v['estado'] ?? 1) !== 1 ? 'selected' : ''; ?>>Inactivo</option></select></div>
                    <div class="form-group"><label for="fi-tarifa">Tarifa base por sesión (Q)</label><input type="number" class="form-control" id="fi-tarifa" name="tarifa_sesion" min="0" step="0.01" value="<?php echo cengi_pp_h($v['tarifa_sesion'] ?? ''); ?>"></div>
                    <?php if ($tienePago): ?>
                        <div class="form-group"><label for="fi-comp">Comprobante con el que cobra</label><select class="form-control" id="fi-comp" name="comprobante">
                            <?php foreach (CENGI_PP_COMPROBANTES as $clave => $texto): ?><option value="<?php echo $clave; ?>" <?php echo ($v['comprobante'] ?? 'factura') === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group"><label for="fi-dpi">DPI</label><input type="text" class="form-control" id="fi-dpi" name="dpi" maxlength="20" inputmode="numeric" pattern="[\d\s-]{13,17}" placeholder="0000 00000 0000" value="<?php echo cengi_pp_h($v['dpi'] ?? ''); ?>">
                            <p class="help-block" id="fi-dpi-ayuda">13 dígitos. Obligatorio si cobra con recibo.</p></div>
                    <?php endif; ?>
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
            <input type="hidden" name="individual" value="<?=!empty($_GET['individual'])?1:0?>"><input type="hidden" name="accion" value="subir"><input type="hidden" name="tab" value="documentos">
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
    // Los botones del expediente abren el formulario en el campo que falta.
    var foco = null;
    $('#datos-modal').on('show.bs.modal', function (e) {
        foco = e.relatedTarget && e.relatedTarget.getAttribute('data-foco');
    }).on('shown.bs.modal', function () {
        var campo = document.getElementById(foco || 'fi-nombre');
        if (campo) { campo.focus(); if (foco) campo.closest('.form-group').classList.add('fi-resaltar'); }
    }).on('hidden.bs.modal', function () {
        document.querySelectorAll('.fi-resaltar').forEach(function (g) { g.classList.remove('fi-resaltar'); });
    });
    // DPI obligatorio cuando cobra con recibo.
    var comp = document.getElementById('fi-comp'), dpi = document.getElementById('fi-dpi');
    function ajustarDpi() { if (comp && dpi) dpi.required = comp.value === 'recibo'; }
    if (comp) comp.addEventListener('change', ajustarDpi);
    ajustarDpi();
    document.getElementById('sb-archivo').addEventListener('change', function () {
        if (this.files[0] && this.files[0].size > <?php echo CENGI_FICHA_MAX_BYTES; ?>) { alert('El archivo supera los 5 MB.'); this.value = ''; }
    });
    document.getElementById('subir-form').addEventListener('submit', function () { var b = document.getElementById('sb-btn'); setTimeout(function () { b.disabled = true; b.textContent = 'Subiendo…'; }, 0); });
    // Búsqueda en la lista de sesiones.
    document.querySelectorAll('[data-buscar]').forEach(function (input) {
        var cont = document.querySelector(input.getAttribute('data-buscar'));
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase(), visibles = 0;
            cont.querySelectorAll('.fi-fila').forEach(function (f) {
                var ok = !q || f.getAttribute('data-texto').indexOf(q) !== -1;
                f.hidden = !ok; if (ok) visibles++;
            });
            cont.querySelector('[data-sin-resultados]').hidden = visibles > 0;
        });
    });
    ajustar();
    <?php if ($reabrir === 'datos' || (!$reabrir && !empty($_GET['editar']))): ?>$('#datos-modal').modal('show');<?php elseif ($reabrir === 'subir'): ?>$('#subir-modal').modal('show');<?php endif; ?>
})();
</script>
</body>
</html>
