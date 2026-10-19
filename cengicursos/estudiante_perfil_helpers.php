<?php
/**
 * Perfil del estudiante (mi_perfil.php): sus datos de participante, su foto y su historial
 * de cursos y diplomas. Todo se filtra por el usuario de la sesión: un estudiante solo ve
 * lo suyo (asignaciones.usuarios_id o participantes.usuarios_id).
 * Esquema: migrations/20261103_participantes_foto.sql (se completa aquí si falta).
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/cengi_texto.php';

/** Asegura la columna participantes.foto_path (idempotente). */
function cengi_est_foto_lista(PDO $db)
{
    static $lista = null;
    if ($lista !== null) {
        return $lista;
    }
    try {
        $db->query('SELECT foto_path FROM participantes LIMIT 0');
        return $lista = true;
    } catch (PDOException $e) {
        try {
            $db->exec('ALTER TABLE participantes ADD COLUMN foto_path VARCHAR(255) NULL');
            return $lista = true;
        } catch (PDOException $e2) {
            error_log('Perfil del estudiante (foto_path): ' . $e2->getMessage());
            return $lista = false;
        }
    }
}

/** Ficha del participante del usuario actual (la más reciente activa) o null. */
function cengi_est_participante(PDO $db, $usuarioId)
{
    $foto = cengi_est_foto_lista($db) ? 'p.foto_path' : 'NULL';
    $stmt = $db->prepare("SELECT p.id, p.nombre_participantes AS nombre, p.cui_participantes AS cui, p.puesto_participantes AS puesto,
            p.area_participantes AS area, p.correo_participantes AS correo, p.grado_academico_participantes AS grado,
            p.telefono_participantes AS telefono, $foto AS foto_path, i.nombre_ingenios AS ingenio
        FROM participantes p LEFT JOIN ingenios i ON i.id = p.ingenio_id
        WHERE p.estado_participantes = 1 AND (p.usuarios_id = ? OR EXISTS (SELECT 1 FROM asignaciones a WHERE a.participantes_id = p.id AND a.usuarios_id = ?))
        ORDER BY p.actualizado DESC, p.id DESC LIMIT 1");
    $stmt->execute([(int) $usuarioId, (int) $usuarioId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Historial de cursos del usuario, con nota, asistencia y diploma. */
function cengi_est_historial(PDO $db, $usuarioId)
{
    $stmt = $db->prepare("SELECT a.id AS asignacion_id, c.id AS curso_id, c.codigo_curso, c.nombre_cursos, c.tipo AS modalidad,
            ca.descripcion_categorias_cursos AS categoria, c.inicio, c.fin,
            (SELECT SUM(m.horas) FROM curso_modulos m WHERE m.curso_id = c.id) AS horas,
            cc.asistencia, cc.sesiones_asistidas, cc.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS diploma_pdf, d.codigo_unico AS diploma_codigo, d.emitido_en AS diploma_fecha
        FROM asignaciones a
        JOIN participantes p ON p.id = a.participantes_id
        JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (SELECT asignacion_id, MAX(pdf_path) AS pdf_path, MAX(codigo_unico) AS codigo_unico, MAX(emitido_en) AS emitido_en
                   FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id) d ON d.asignacion_id = a.id
        WHERE a.estado_asignaciones = 1 AND p.estado_participantes = 1 AND (a.usuarios_id = ? OR p.usuarios_id = ?)
        ORDER BY c.inicio IS NULL, c.inicio DESC, c.nombre_cursos");
    $stmt->execute([(int) $usuarioId, (int) $usuarioId]);
    $hoy = date('Y-m-d');
    $filas = [];
    $vistos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (isset($vistos[$f['curso_id']])) {
            continue; // Misma persona con dos fichas en el mismo curso: una sola tarjeta.
        }
        $vistos[$f['curso_id']] = true;
        $fin = $f['fin'] && $f['fin'] !== '0000-00-00' ? $f['fin'] : null;
        $inicio = $f['inicio'] && $f['inicio'] !== '0000-00-00' ? $f['inicio'] : null;
        $f['estado'] = $fin && $fin < $hoy ? 'finalizado' : ($inicio && $inicio > $hoy ? 'proximo' : 'en_curso');
        $f['nota'] = is_numeric($f['posevaluacion']) ? (float) $f['posevaluacion'] : null;
        $f['asistencia_pct'] = is_numeric($f['asistencia']) ? max(0, min(100, (float) $f['asistencia'])) : null;
        $f['aprobado'] = $f['nota'] !== null ? $f['nota'] >= CENGI_NOTA_APROBACION : null;
        $f['tiene_diploma'] = trim((string) $f['diploma_pdf']) !== '' || trim((string) $f['diploma_codigo']) !== '';
        $filas[] = $f;
    }
    return $filas;
}

/** ¿La asignación es del usuario? (para descargar su propio diploma). */
function cengi_est_es_su_asignacion(PDO $db, $asignacionId, $usuarioId)
{
    if ((int) $usuarioId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM asignaciones a JOIN participantes p ON p.id = a.participantes_id
        WHERE a.id = ? AND a.estado_asignaciones = 1 AND (a.usuarios_id = ? OR p.usuarios_id = ?) LIMIT 1');
    $stmt->execute([(int) $asignacionId, (int) $usuarioId, (int) $usuarioId]);
    return (bool) $stmt->fetchColumn();
}

/** Ruta absoluta de la foto si es válida y está dentro de uploads/participantes; si no, null. */
function cengi_est_foto_archivo($ruta)
{
    $url = cengi_normalizar_url_archivo($ruta);
    $base = realpath(__DIR__ . '/../uploads/participantes');
    $path = $url ? realpath(__DIR__ . '/..' . $url) : false;
    return $base && $path && is_file($path) && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 ? $path : null;
}

/**
 * Guarda la foto del participante: valida (JPG/PNG, 5 MB), la reduce a 600 px y la vuelve a
 * codificar como JPEG; así se descartan los metadatos (p. ej. la ubicación GPS de un celular).
 */
function cengi_est_guardar_foto(PDO $db, $participanteId, array $archivo)
{
    if (!cengi_est_foto_lista($db)) {
        throw new RuntimeException('La foto de perfil no está disponible todavía.');
    }
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Selecciona una foto.');
    }
    if (($archivo['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('No se pudo recibir la foto. Usa JPG o PNG de máximo 5 MB.');
    }
    $tmp = $archivo['tmp_name'];
    if (filesize($tmp) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('La foto debe pesar como máximo 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !$info || $info[0] > 8000 || $info[1] > 8000) {
        throw new InvalidArgumentException('Selecciona una foto JPG o PNG válida.');
    }
    $origen = $mime === 'image/png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
    if (!$origen) {
        throw new InvalidArgumentException('No se pudo leer la imagen. Prueba con otra foto.');
    }
    // Respeta la orientación de las fotos de celular antes de descartar los metadatos.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $giro = ['3' => 180, '6' => -90, '8' => 90][(string) ($exif['Orientation'] ?? '')] ?? 0;
        if ($giro) {
            $rotada = imagerotate($origen, $giro, 0);
            if ($rotada) {
                imagedestroy($origen);
                $origen = $rotada;
            }
        }
    }
    $w = imagesx($origen);
    $h = imagesy($origen);
    $escala = min(1, 600 / max($w, $h));
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));
    $destino = imagecreatetruecolor($nw, $nh);
    imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255)); // fondo blanco para PNG con transparencia
    imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($origen);

    $dir = __DIR__ . '/../uploads/participantes';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        imagedestroy($destino);
        throw new RuntimeException('No se pudo preparar la carpeta de fotos.');
    }
    $nombre = (int) $participanteId . '_' . bin2hex(random_bytes(12)) . '.jpg';
    $ok = imagejpeg($destino, $dir . '/' . $nombre, 85);
    imagedestroy($destino);
    if (!$ok) {
        throw new RuntimeException('No se pudo guardar la foto. Intenta nuevamente.');
    }
    $ruta = '/uploads/participantes/' . $nombre;

    $stmt = $db->prepare('SELECT foto_path FROM participantes WHERE id = ?');
    $stmt->execute([(int) $participanteId]);
    $anterior = $stmt->fetchColumn();
    $db->prepare('UPDATE participantes SET foto_path = ? WHERE id = ?')->execute([$ruta, (int) $participanteId]);
    if ($anterior && ($archivoAnterior = cengi_est_foto_archivo($anterior))) {
        @unlink($archivoAnterior);
    }
    return $ruta;
}

function cengi_est_quitar_foto(PDO $db, $participanteId)
{
    if (!cengi_est_foto_lista($db)) {
        return;
    }
    $stmt = $db->prepare('SELECT foto_path FROM participantes WHERE id = ?');
    $stmt->execute([(int) $participanteId]);
    $anterior = $stmt->fetchColumn();
    $db->prepare('UPDATE participantes SET foto_path = NULL WHERE id = ?')->execute([(int) $participanteId]);
    if ($anterior && ($archivo = cengi_est_foto_archivo($anterior))) {
        @unlink($archivo);
    }
}
