<?php
/**
 * Constancia de notas (formato de Prototipos/Constanciadenota.pdf): datos, HTML y PDF.
 * Lo usan constancia_notas.php (PDF y envío) y constancia_notas_config.php (firmantes,
 * imágenes de firma, lugar y pie). La configuración se guarda en eventos_config
 * (clave constancia_notas, JSON); las firmas como imágenes data: (no en una carpeta pública).
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/planificacion_helpers.php';

const CENGI_CN_APRUEBA = CENGI_NOTA_APROBACION;
const CENGI_CN_MAX_FIRMAS = 3;
const CENGI_CN_DEFECTO = [
    'lugar' => 'Santa Lucía Cotzumalguapa',
    'firmas' => [
        ['nombre' => 'Ing. Mario Sagastume', 'cargo' => 'Gerente General de Investigación y Desarrollo', 'imagen' => ''],
        ['nombre' => 'Ing. Hector Monterroso', 'cargo' => 'Coordinador del Programa de Transferencia de Tecnología y Capacitación', 'imagen' => ''],
    ],
    'pie' => ['Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar -Cengicaña-', 'Km 92.5 Carretera a Santa Lucía Cotzumalguapa, Escuintla, Guatemala.',
        'PBX: (502) 7828 1000', 'direccion@cengicana.org', 'www.cengicana.org'],
];

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
    $cfg = array_merge(CENGI_CN_DEFECTO, is_array($guardado) ? $guardado : []);
    foreach ($cfg['firmas'] as &$f) {
        $f += ['nombre' => '', 'cargo' => '', 'imagen' => ''];
    }
    unset($f);
    return $cfg;
}

function cengi_cn_guardar_config(PDO $db, array $cfg)
{
    $db->prepare("INSERT INTO eventos_config (clave, valor) VALUES ('constancia_notas', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

/**
 * Imagen de firma subida (PNG o JPG, hasta 2 MB) → PNG data: de máximo 600 × 220 px, con el
 * fondo claro del escaneo vuelto transparente para que la firma quede limpia sobre la línea.
 */
function cengi_cn_firma_desde_archivo(array $archivo)
{
    if (($archivo['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('No se pudo recibir la imagen de la firma.');
    }
    $tmp = $archivo['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    if (!in_array($mime, ['image/png', 'image/jpeg'], true) || !$info || filesize($tmp) > 2 * 1024 * 1024 || $info[0] > 6000 || $info[1] > 6000) {
        throw new InvalidArgumentException('La firma debe ser una imagen PNG o JPG de hasta 2 MB.');
    }
    $src = $mime === 'image/png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
    if (!$src) {
        throw new InvalidArgumentException('No se pudo leer la imagen de la firma.');
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $escala = min(1, 600 / $w, 220 / $h);
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    // Fondo claro → transparente (papel blanco o gris claro del escaneo).
    imagealphablending($out, false);
    $transparente = imagecolorallocatealpha($out, 255, 255, 255, 127);
    for ($y = 0; $y < $nh; $y++) {
        for ($x = 0; $x < $nw; $x++) {
            $c = imagecolorat($out, $x, $y);
            $a = ($c >> 24) & 0x7F;
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            if ($a < 127 && $r > 215 && $g > 215 && $b > 215) {
                imagesetpixel($out, $x, $y, $transparente);
            }
        }
    }
    ob_start();
    imagepng($out, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($out);
    return 'data:image/png;base64,' . base64_encode($png);
}

/** Valida una imagen data: ya guardada (o devuelve ''). */
function cengi_cn_firma_valida($imagen)
{
    $imagen = (string) $imagen;
    return preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $imagen) && strlen($imagen) < 1024 * 1024 ? $imagen : '';
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
    // Firmas: la imagen escaneada (si la hay) va sobre la línea; sin imagen queda el espacio para firmar a mano.
    $lista = array_values(array_filter($cfg['firmas'], static function ($f) {
        return trim((string) ($f['nombre'] ?? '')) !== '' || trim((string) ($f['cargo'] ?? '')) !== '';
    }));
    $ancho = $lista ? floor(100 / count($lista)) : 50;
    $firmas = '';
    foreach ($lista as $fi) {
        $img = cengi_cn_firma_valida($fi['imagen'] ?? '');
        $firmas .= '<td style="width:' . $ancho . '%;vertical-align:top;text-align:center;padding:0 12px;">'
            . '<div style="height:62px;text-align:center;">' . ($img !== '' ? '<img src="' . $h($img) . '" style="max-height:60px;max-width:190px;">' : '') . '</div>'
            . '<div style="border-top:1px solid #222;padding-top:4px;font-weight:bold;font-size:11.5px;line-height:1.35;">'
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
        . '<table style="width:92%;margin:28px auto 0;border-collapse:collapse;"><tr>' . $firmas . '</tr></table>'
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

/** Quién configura la constancia: administración o quien gestiona diplomas. */
function cengi_cn_puede_configurar()
{
    return cengi_puede_gestionar_diplomas();
}
