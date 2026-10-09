<?php
/**
 * Constancia de notas por modulo de un participante (formato de Prototipos/Constanciadenota.pdf)
 * y envio por correo de la constancia y del diploma.
 *
 *   GET  ?asignacion_id=N            PDF de la constancia (inline)
 *   GET  ?asignacion_id=N&descargar=1  PDF como descarga
 *   POST accion=enviar asignacion_id=N [constancia=1] [diploma=1]
 *        Envia al correo del participante; vuelve a participantes.php.
 *
 * Calificacion de cada modulo: post-evaluacion (o pre si el modulo solo lleva pre).
 * Cursos sin modulos: una fila con la nota final del curso. Lugar, firmas y pie se
 * pueden ajustar en eventos_config (clave constancia_notas, JSON).
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/planificacion_helpers.php';

const CENGI_CN_APRUEBA = CENGI_NOTA_APROBACION;
const CENGI_CN_DEFECTO = [
    'lugar' => 'Santa Lucía Cotzumalguapa',
    'firmas' => [
        ['nombre' => 'Ing. Mario Sagastume', 'cargo' => 'Gerente General de Investigación y Desarrollo'],
        ['nombre' => 'Ing. Hector Monterroso', 'cargo' => 'Coordinador del Programa de Transferencia de Tecnología y Capacitación'],
    ],
    'pie' => ['Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar -Cengicaña-', 'Km 92.5 Carretera a Santa Lucía Cotzumalguapa, Escuintla, Guatemala.',
        'PBX: (502) 7828 1000', 'direccion@cengicana.org', 'www.cengicana.org'],
];

$db = conectar();
$asignacionId = (int) ($_POST['asignacion_id'] ?? $_GET['asignacion_id'] ?? 0);

function cengi_cn_h($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_cn_nota($v)
{
    return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') : '—';
}

/** Datos de la constancia: participante, curso, horas, filas [modulo, nota] y nota final. */
function cengi_cn_datos(PDO $db, $asignacionId)
{
    $stmt = $db->prepare("SELECT a.id, a.cursos_id, p.nombre_participantes AS nombre, p.correo_participantes AS correo, p.ingenio_id,
            c.nombre_cursos, c.inicio, c.creado, cc.categoria, ctl.evaluacion, ctl.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(ctl.diploma, '')) AS diploma
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        INNER JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN (SELECT id, descripcion_categorias_cursos AS categoria FROM categorias_cursos) cc ON cc.id = c.categoria_curso_id
        LEFT JOIN control_cursos ctl ON ctl.asignacion_id = a.id
        LEFT JOIN (SELECT asignacion_id, MAX(pdf_path) AS pdf_path FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id) d ON d.asignacion_id = a.id
        WHERE a.id = ?");
    $stmt->execute([(int) $asignacionId]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        return null;
    }
    $stmt = $db->prepare('SELECT cm.id, cm.nombre, cm.horas, cm.acepta_pre, cm.acepta_post, ccm.evaluacion, ccm.posevaluacion
        FROM curso_modulos cm LEFT JOIN control_curso_modulos ccm ON ccm.curso_modulo_id = cm.id AND ccm.asignacion_id = ?
        WHERE cm.curso_id = ? ORDER BY cm.orden, cm.id');
    $stmt->execute([(int) $asignacionId, (int) $a['cursos_id']]);
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filas = [];
    foreach ($modulos as $m) {
        if (empty($m['acepta_pre']) && empty($m['acepta_post'])) {
            continue; // modulo sin evaluacion (p. ej. visita de campo)
        }
        $filas[] = ['modulo' => $m['nombre'], 'nota' => !empty($m['acepta_post']) ? $m['posevaluacion'] : $m['evaluacion']];
    }
    if (!$filas) {
        $filas[] = ['modulo' => $a['nombre_cursos'], 'nota' => is_numeric($a['posevaluacion']) ? $a['posevaluacion'] : $a['evaluacion']];
    }

    $horas = array_sum(array_map('floatval', array_column($modulos, 'horas')));
    if ($horas <= 0) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(s.hora_fin, s.hora_inicio))) / 3600, 0) FROM curso_sesiones s
            INNER JOIN planificacion_cursos pc ON pc.id = s.planificacion_id WHERE pc.curso_id = ? AND s.estado <> 'cancelada'");
        $stmt->execute([(int) $a['cursos_id']]);
        $horas = (float) $stmt->fetchColumn();
    }
    $a['modulos'] = $filas;
    $a['con_modulos'] = (bool) $modulos;
    $a['horas'] = $horas;
    return $a;
}

function cengi_cn_config(PDO $db)
{
    $stmt = $db->prepare("SELECT valor FROM eventos_config WHERE clave = 'constancia_notas'");
    $stmt->execute();
    $guardado = json_decode((string) $stmt->fetchColumn(), true);
    return array_merge(CENGI_CN_DEFECTO, is_array($guardado) ? $guardado : []);
}

/** "del Diplomado de ..." / "del Curso ...": si el nombre no empieza con el tipo de actividad, se antepone. */
function cengi_cn_actividad(array $d)
{
    $nombre = trim((string) $d['nombre_cursos']);
    if (preg_match('/^(diplomado|curso|taller|seminario|programa|capacitaci[oó]n)\b/iu', $nombre)) {
        return $nombre;
    }
    return 'curso ' . $nombre;
}

function cengi_cn_html(array $d, array $cfg)
{
    $h = 'cengi_cn_h';
    $verde = '#2F6B12';
    $actividad = cengi_cn_actividad($d);
    $horas = $d['horas'] > 0 ? rtrim(rtrim(number_format($d['horas'], 1, '.', ''), '0'), '.') : null;
    $filas = '';
    foreach ($d['modulos'] as $f) {
        $filas .= '<tr><td style="border:1px solid #333;padding:4px 8px;">' . ($d['con_modulos'] ? '&bull;&nbsp;&nbsp;' : '') . $h($f['modulo']) . '</td>'
            . '<td style="border:1px solid #333;padding:4px 8px;text-align:center;">' . cengi_cn_nota($f['nota']) . '</td></tr>';
    }
    if ($d['con_modulos']) {
        $filas .= '<tr><td style="border:1px solid #333;padding:5px 8px;font-weight:bold;">Nota final del ' . $h(mb_strtolower(explode(' ', $actividad)[0], 'UTF-8')) . '</td>'
            . '<td style="border:1px solid #333;padding:5px 8px;text-align:center;font-weight:bold;">' . cengi_cn_nota($d['posevaluacion']) . '</td></tr>';
    }
    $guatecana = __DIR__ . '/img/logo-guatecana.png';
    $logos = '<table style="width:100%;border-collapse:collapse;"><tr>'
        . '<td style="width:50%;vertical-align:middle;">' . (is_file($guatecana) ? '<img src="' . $h($guatecana) . '" style="height:80px;">' : '') . '</td>'
        . '<td style="width:50%;vertical-align:middle;text-align:right;"><img src="' . $h(__DIR__ . '/img/logo-comite-capacitacion-pdf.png') . '" style="height:95px;"></td></tr></table>';
    $firmas = '';
    foreach ($cfg['firmas'] as $fi) {
        $firmas .= '<td style="width:50%;vertical-align:top;text-align:center;padding:56px 14px 0;"><div style="border-top:1px solid #222;padding-top:4px;font-weight:bold;font-size:11.5px;line-height:1.35;">'
            . $h($fi['nombre']) . '<br>' . $h($fi['cargo']) . '<br>-CENGICAÑA-</div></td>';
    }
    $fecha = (int) date('j') . ' de ' . mb_strtolower(CENGI_PP_MESES[(int) date('n')], 'UTF-8') . ' de ' . date('Y');
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#111;">'
        . $logos
        . '<div style="text-align:center;font-weight:bold;font-size:13.5px;margin:22px 0 12px;">CONSTANCIA DE NOTAS DEL ' . $h(mb_strtoupper($actividad, 'UTF-8')) . '</div>'
        . '<p style="text-align:justify;line-height:1.55;margin:0 30px 10px;">Por medio de la presente, se hace constar que <b>' . $h($d['nombre']) . '</b>, participó activamente durante el desarrollo '
        . ($d['con_modulos'] ? 'de los módulos del ' : 'del ') . '<i>' . $h($actividad) . '</i>, impartido por <b>CENGICAÑA</b>'
        . ($horas ? ', con una duración de ' . $horas . ' horas de clases' : '') . ', obteniendo ' . ($d['con_modulos'] ? 'las siguientes calificaciones:' : 'la siguiente calificación:') . '</p>'
        . '<table style="width:88%;margin:0 auto;border-collapse:collapse;font-size:12px;">'
        . '<thead><tr><th style="border:1px solid #333;padding:5px 8px;">Módulo</th><th style="border:1px solid #333;padding:5px 8px;width:30%;">Calificación</th></tr></thead><tbody>' . $filas . '</tbody></table>'
        . '<div style="width:88%;margin:3px auto 0;font-size:10.5px;"><b>Nota: Aprobado</b> =&gt; ' . CENGI_CN_APRUEBA . ' y <b>No Aprobado</b> &lt;' . CENGI_CN_APRUEBA . '.</div>'
        . '<p style="text-align:justify;line-height:1.55;margin:18px 30px 10px;">La presente constancia se emite para comunicar el desempeño académico en los módulos cursados, resaltando su interés por el fortalecimiento del conocimiento en el sector azucarero, con la oportunidad de seguir desarrollando sus aptitudes académicas.</p>'
        . '<p style="margin:14px 30px 0;"><b>Lugar y Fecha:</b> ' . $h($cfg['lugar']) . ', ' . $fecha . '.</p>'
        . '<p style="margin:12px 30px 0;font-weight:bold;">FIRMAS:</p>'
        . '<table style="width:92%;margin:0 auto;border-collapse:collapse;"><tr>' . $firmas . '</tr></table>'
        . '</div>'
        . '<div style="position:fixed;left:-40px;right:-40px;bottom:-100px;height:72px;background:#7DBB34;color:#fff;text-align:center;font-size:8.5px;line-height:1.45;padding:8px 0 10px;">'
        . implode('<br>', array_map($h, $cfg['pie'])) . '</div>';
}

function cengi_cn_pdf(array $d, array $cfg)
{
    require_once __DIR__ . '/vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', __DIR__);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml('<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:40px 40px 100px;}</style></head><body>' . cengi_cn_html($d, $cfg) . '</body></html>', 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** Contenido del diploma del participante (archivo local en /uploads), o null. */
function cengi_cn_diploma_bytes($ruta)
{
    $url = cengi_normalizar_url_archivo($ruta);
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return null;
    }
    $base = realpath(__DIR__ . '/../uploads');
    $archivo = realpath(__DIR__ . '/..' . $url);
    if (!$base || !$archivo || strpos($archivo, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($archivo)) {
        return null;
    }
    $bytes = file_get_contents($archivo);
    return $bytes === false ? null : $bytes;
}

// ---------------------------------------------------------------- acceso
$datos = $asignacionId > 0 ? cengi_cn_datos($db, $asignacionId) : null;
if (!$datos) {
    http_response_code(404);
    exit('La asignación no existe.');
}
$ingenioActual = cengi_ingenio_id_actual();
$puedeVer = (cengi_puede_ver_participantes() || cengi_puede_calificar() || cengi_puede_ver_diplomas())
    && (cengi_ve_todo_por_rol_o_ingenio() || (int) $datos['ingenio_id'] === $ingenioActual);
if (!$puedeVer) {
    http_response_code(403);
    exit('No tienes permiso para ver esta constancia.');
}
$cfg = cengi_cn_config($db);
$nombreBase = 'Constancia_notas_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $datos['nombre'] . ' ' . $datos['nombre_cursos']) ?: 'participante');

// ---------------------------------------------------------------- envio por correo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar') {
    $volver = 'participantes.php?curso_id=' . (int) $datos['cursos_id'];
    if (!(cengi_puede_gestionar() || cengi_puede_subir_diploma())) {
        header('Location: ' . $volver . '&error=permiso_correo');
        exit;
    }
    $correo = trim((string) $datos['correo']);
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        header('Location: ' . $volver . '&error=sin_correo');
        exit;
    }
    $adjuntos = [];
    $incluye = [];
    if (!empty($_POST['constancia'])) {
        $adjuntos[] = ['nombre' => trim($nombreBase, '_') . '.pdf', 'contenido' => cengi_cn_pdf($datos, $cfg), 'tipo' => 'application/pdf'];
        $incluye[] = 'tu constancia de notas';
    }
    if (!empty($_POST['diploma'])) {
        $bytes = $datos['diploma'] ? cengi_cn_diploma_bytes($datos['diploma']) : null;
        if ($bytes === null) {
            header('Location: ' . $volver . '&error=sin_diploma');
            exit;
        }
        $adjuntos[] = ['nombre' => cengi_diploma_nombre_archivo($datos['nombre_cursos'], date('Y', strtotime($datos['inicio'] ?: $datos['creado'])), $datos['nombre']), 'contenido' => $bytes, 'tipo' => 'application/pdf'];
        $incluye[] = 'tu diploma';
    }
    if (!$adjuntos) {
        header('Location: ' . $volver . '&error=sin_adjuntos');
        exit;
    }
    require_once __DIR__ . '/correo.php';
    $asunto = (count($incluye) === 2 ? 'Diploma y constancia de notas' : (in_array('tu diploma', $incluye, true) ? 'Diploma' : 'Constancia de notas')) . ': ' . $datos['nombre_cursos'];
    $mensaje = 'Hola ' . $datos['nombre'] . ":\n\nTe felicitamos por tu participación en " . $datos['nombre_cursos'] . '. Adjuntamos ' . implode(' y ', $incluye) . ".\n\n"
        . (is_numeric($datos['posevaluacion']) ? 'Nota final: ' . cengi_cn_nota($datos['posevaluacion']) . ".\n\n" : '') . 'Coordinación de Capacitación — CENGICAÑA';
    $ok = false;
    try {
        $GLOBALS['cengi_correo_categoria'] = 'Diplomas';
        $ok = cengi_enviar_correo($correo, $datos['nombre'], $asunto, cengi_pp_correo_html($asunto, $mensaje), $adjuntos);
    } catch (Throwable $e) {
        error_log('Constancia de notas: no se pudo enviar a ' . $correo . ': ' . $e->getMessage());
    }
    header('Location: ' . $volver . ($ok ? '&mensaje=correo_enviado' : '&error=correo'));
    exit;
}

// ---------------------------------------------------------------- PDF
$pdf = cengi_cn_pdf($datos, $cfg);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . trim($nombreBase, '_') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
