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
 * Cursos sin modulos: una fila con la nota final del curso. Firmantes, imágenes de firma,
 * lugar y pie se configuran en constancia_notas_config.php (constancia_notas_helpers.php).
 */
require_once __DIR__ . '/constancia_notas_helpers.php';

$db = conectar();
$asignacionId = (int) ($_POST['asignacion_id'] ?? $_GET['asignacion_id'] ?? 0);

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
