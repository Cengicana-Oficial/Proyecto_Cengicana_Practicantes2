<?php
// Configuracion de lo que ve el estudiante en su aula (tipo Moodle): secciones visibles,
// anuncio de bienvenida y visibilidad / disponibilidad de cada recurso.
// Esquema: migrations/20261021_aula_vista_estudiante.sql (se completa aqui si falta).

// Secciones que coordinacion puede ocultar. "Resumen" siempre se muestra.
const AULA_SECCIONES_CONFIGURABLES = [
    'sesiones' => 'Sesiones y grabaciones', 'material' => 'Material general (en Sesiones)', 'asistencia' => 'Asistencia',
    'evaluaciones' => 'Evaluaciones (en Tareas y evaluaciones)', 'tareas' => 'Tareas (en Tareas y evaluaciones)', 'notas' => 'Notas por módulo (en Notas y diploma)',
    'instructores' => 'Evaluar al instructor', 'diplomas' => 'Diploma (en Notas y diploma)',
];

/** Fecha legible en espanol: "Lunes 13/10/2026" o, corta, "Lun 13/10". Acepta fecha o fecha y hora. */
function aula_fecha_larga($fecha, $corta = false) {
    $t = strtotime((string) $fecha); if (!$t) return (string) $fecha;
    $dia = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][(int) date('w', $t)];
    return $corta ? mb_substr($dia, 0, 3) . ' ' . date('d/m', $t) : $dia . ' ' . date('d/m/Y', $t);
}

const AULA_ANUNCIOS_DDL = "CREATE TABLE IF NOT EXISTS aula_anuncios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curso_id INT UNSIGNED NOT NULL,
  sesion_id INT UNSIGNED NULL,
  titulo VARCHAR(180) NOT NULL,
  mensaje TEXT NULL,
  enlace_url VARCHAR(2048) NOT NULL DEFAULT '',
  enlace_texto VARCHAR(80) NOT NULL DEFAULT '',
  publicar_desde DATETIME NULL,
  visible TINYINT NOT NULL DEFAULT 1,
  fijado TINYINT NOT NULL DEFAULT 0,
  creado_por INT NULL,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_aula_anuncios_curso (curso_id),
  FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
  FOREIGN KEY (sesion_id) REFERENCES curso_sesiones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

/**
 * Anuncios del curso. $estudiante = true: solo visibles y ya publicados (fijados primero, luego
 * los mas recientes). $sesionId: solo los ligados a esa sesion.
 */
function aula_anuncios(PDO $db, $courseId, $estudiante = false, $sesionId = null) {
    if (!$courseId || !aula_vista_ready($db)) return [];
    $where = 'a.curso_id=?'; $params = [(int) $courseId];
    if ($estudiante) { $where .= " AND a.visible=1 AND (a.publicar_desde IS NULL OR a.publicar_desde<=?)"; $params[] = date('Y-m-d H:i:s'); }
    if ($sesionId !== null) { $where .= ' AND a.sesion_id=?'; $params[] = (int) $sesionId; }
    return aula_rows($db, "SELECT a.*,s.tema AS sesion_tema,s.fecha AS sesion_fecha FROM aula_anuncios a LEFT JOIN curso_sesiones s ON s.id=a.sesion_id
        WHERE $where ORDER BY a.fijado DESC,COALESCE(a.publicar_desde,a.creado) DESC,a.id DESC", $params);
}

/** Tarjeta de un anuncio para el estudiante (resumen y sesiones). */
function aula_anuncio_html(array $a) {
    $url = aula_url($a['enlace_url']);
    $esTeams = $url && stripos((string) parse_url($url, PHP_URL_HOST), 'teams.') !== false;
    ob_start(); ?>
    <section class="aula-anuncio<?= (int) $a['fijado'] ? ' is-fijado' : '' ?>" aria-label="Anuncio">
        <span class="glyphicon glyphicon-<?= $esTeams ? 'facetime-video' : 'bullhorn' ?>" aria-hidden="true"></span>
        <div>
            <h2><?= aula_h($a['titulo']) ?><?= (int) $a['fijado'] ? ' <small>· fijado</small>' : '' ?></h2>
            <?php if (trim((string) $a['mensaje']) !== ''): ?><p><?= nl2br(aula_h($a['mensaje'])) ?></p><?php endif; ?>
            <?php if ($url): ?><a class="btn btn-primary btn-sm" href="<?= aula_h($url) ?>" target="_blank" rel="noopener noreferrer"><?= aula_h($a['enlace_texto'] ?: ($esTeams ? 'Entrar a Teams' : 'Abrir enlace')) ?> ↗</a><?php endif; ?>
            <small class="aula-anuncio-meta"><?= date('d/m/Y', strtotime($a['publicar_desde'] ?: $a['creado'])) ?><?= $a['sesion_id'] ? ' · Sesión: ' . aula_h($a['sesion_tema']) : '' ?></small>
        </div>
    </section>
    <?php return ob_get_clean();
}

function aula_vista_ready(PDO $db) {
    static $ready = [];
    $key = spl_object_id($db);
    if (isset($ready[$key])) return $ready[$key];
    try {
        $cols = [];
        foreach ($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('aula_curso_config','aula_recursos')")->fetchAll(PDO::FETCH_NUM) as [$t, $c]) $cols[$t][$c] = true;
        if (empty($cols['aula_curso_config']) || empty($cols['aula_recursos'])) return $ready[$key] = false;
        $faltan = [
            'aula_curso_config' => ['secciones_ocultas' => "VARCHAR(255) NOT NULL DEFAULT ''", 'anuncio_titulo' => "VARCHAR(180) NOT NULL DEFAULT ''", 'anuncio' => 'TEXT NULL'],
            'aula_recursos' => ['visible' => 'TINYINT NOT NULL DEFAULT 1', 'disponible_desde' => 'DATETIME NULL'],
        ];
        foreach ($faltan as $tabla => $defs) foreach ($defs as $col => $def) if (empty($cols[$tabla][$col])) $db->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
        // Anuncios del curso (migrations/20261022_aula_anuncios.sql). Solo se crea si falta: el DDL hace
        // commit implicito y esta funcion tambien se llama dentro de transacciones.
        if (!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_anuncios'")->fetchColumn()) {
            $db->exec(AULA_ANUNCIOS_DDL);
        }
        return $ready[$key] = true;
    } catch (Throwable $e) {
        error_log('Aula vista estudiante: ' . $e->getMessage());
        return $ready[$key] = false;
    }
}

/** ['ocultas' => [claves], 'anuncio_titulo' => '', 'anuncio' => ''] */
function aula_vista_config(PDO $db, $courseId) {
    $base = ['ocultas' => [], 'anuncio_titulo' => '', 'anuncio' => ''];
    if (!$courseId || !aula_vista_ready($db)) return $base;
    $r = aula_rows($db, 'SELECT secciones_ocultas,anuncio_titulo,anuncio FROM aula_curso_config WHERE curso_id=?', [(int) $courseId])[0] ?? null;
    if (!$r) return $base;
    $ocultas = array_values(array_intersect(array_filter(explode(',', (string) $r['secciones_ocultas'])), array_keys(AULA_SECCIONES_CONFIGURABLES)));
    return ['ocultas' => $ocultas, 'anuncio_titulo' => (string) $r['anuncio_titulo'], 'anuncio' => (string) $r['anuncio']];
}

function aula_vista_guardar(PDO $db, $courseId, array $visibles, $titulo, $anuncio) {
    if (!aula_vista_ready($db)) throw new RuntimeException('Aplica la migración 20261021_aula_vista_estudiante.sql.');
    $ocultas = array_values(array_diff(array_keys(AULA_SECCIONES_CONFIGURABLES), $visibles));
    $titulo = mb_substr(trim((string) $titulo), 0, 180);
    $anuncio = trim((string) $anuncio);
    if (mb_strlen($anuncio) > 5000) throw new InvalidArgumentException('El anuncio admite hasta 5000 caracteres.');
    $db->prepare('INSERT INTO aula_curso_config(curso_id,secciones_ocultas,anuncio_titulo,anuncio) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE secciones_ocultas=VALUES(secciones_ocultas),anuncio_titulo=VALUES(anuncio_titulo),anuncio=VALUES(anuncio)')
        ->execute([(int) $courseId, implode(',', $ocultas), $titulo, $anuncio !== '' ? $anuncio : null]);
}

/** El estudiante ve el recurso: visible y, si tiene fecha, ya disponible. */
function aula_recurso_visible(array $r) {
    if (array_key_exists('visible', $r) && !(int) $r['visible']) return false;
    return empty($r['disponible_desde']) || $r['disponible_desde'] <= date('Y-m-d H:i:s');
}

/** Condicion SQL (alias r de aula_recursos) de recurso oculto o aun no disponible para el estudiante. */
function aula_recurso_oculto_sql($alias = 'r') {
    // Hora de Guatemala desde PHP (MySQL puede estar en UTC).
    return "($alias.visible=0 OR ($alias.disponible_desde IS NOT NULL AND $alias.disponible_desde>'" . date('Y-m-d H:i:s') . "'))";
}

/** Texto del estado de un recurso para coordinacion. */
function aula_recurso_estado(array $r) {
    if (!(int) ($r['visible'] ?? 1)) return ['Oculto', 'is-oculto'];
    if (!empty($r['disponible_desde']) && $r['disponible_desde'] > date('Y-m-d H:i:s')) return ['Disponible el ' . date('d/m/Y H:i', strtotime($r['disponible_desde'])), 'is-programado'];
    return ['Visible', 'is-complete'];
}

// Formulario y tarjeta de anuncio para coordinacion (Gestion del aula -> Anuncios y editor por sesion).
    /** Formulario de anuncio (nuevo o edicion). $fijaSesion: sesion preseleccionada (editor por sesion). */
    function aula_anuncio_form(array $sessions,$teamsCurso,array $a=[],$ancla='anuncios',$fijaSesion=0) {
        $id=(int)($a['id']??0);$sid=(int)($a['sesion_id']??$fijaSesion);
        $dt=static function($v){return $v?aula_h(str_replace(' ','T',substr($v,0,16))):'';};
        ob_start(); ?>
        <form method="post" class="aula-form aula-anuncio-form">
            <?= aula_csrf() ?><input type="hidden" name="accion" value="anuncio_guardar"><input type="hidden" name="anuncio" value="<?= $id ?>"><input type="hidden" name="ancla" value="<?= aula_h($ancla) ?>">
            <div class="aula-courses">
                <label class="is-ancho">Título<input name="titulo" maxlength="180" required value="<?= aula_h($a['titulo']??'') ?>" placeholder="Ej. Enlace de Teams para la sesión de hoy"></label>
                <label class="is-ancho">Mensaje<textarea name="mensaje" rows="3" maxlength="5000" placeholder="Indicaciones, recordatorios, cambios de horario..."><?= aula_h($a['mensaje']??'') ?></textarea></label>
                <label class="is-ancho">Enlace (opcional)<input name="enlace_url" type="url" maxlength="2048" value="<?= aula_h($a['enlace_url']??'') ?>" placeholder="https://teams.microsoft.com/l/meetup-join/..."></label>
                <div class="aula-anuncio-teams is-ancho"><?php if($teamsCurso): ?><button type="button" class="btn btn-default btn-xs" data-usar-teams="<?= aula_h($teamsCurso) ?>">Usar el enlace de Teams del curso</button><?php endif; ?>
                    <label class="aula-ed-check"><input type="checkbox" name="guardar_teams" value="1"> Guardar este enlace también como el Teams del curso (o de la sesión elegida)</label></div>
                <label>Texto del botón<input name="enlace_texto" maxlength="80" value="<?= aula_h($a['enlace_texto']??'') ?>" placeholder="Entrar a Teams"></label>
                <label>Sesión<select name="sesion_id"><option value="0">Todo el curso</option><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sid===(int)$s['id']?'selected':'' ?>><?= aula_h(date('d/m',strtotime($s['fecha'])).' · '.$s['tema']) ?></option><?php endforeach; ?></select></label>
                <label>Publicar desde (opcional)<input type="datetime-local" name="publicar_desde" value="<?= $dt($a['publicar_desde']??'') ?>"><small>Vacío = de inmediato.</small></label>
                <label class="aula-ed-check"><input type="checkbox" name="visible" <?= (int)($a['visible']??1)?'checked':'' ?>> Visible para los estudiantes</label>
                <label class="aula-ed-check"><input type="checkbox" name="fijado" <?= (int)($a['fijado']??0)?'checked':'' ?>> Fijar arriba del resumen</label>
            </div>
            <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm"><?= $id?'Guardar cambios':'Publicar anuncio' ?></button></div>
        </form>
        <?php return ob_get_clean();
    }
    /** Anuncio en la lista de coordinacion con edicion y eliminacion. */
    function aula_anuncio_item(array $a,array $sessions,$teamsCurso,$ancla='anuncios') {
        $ahora=date('Y-m-d H:i:s');
        [$estado,$tono]=!(int)$a['visible']?['Oculto','is-oculto']:($a['publicar_desde']&&$a['publicar_desde']>$ahora?['Se publica el '.date('d/m H:i',strtotime($a['publicar_desde'])),'is-programado']:['Publicado','is-complete']);
        ob_start(); ?>
        <article class="aula-ed-recurso aula-anuncio-item">
            <div class="aula-ed-recurso-cab">
                <span class="aula-ed-icono is-tarea"><span class="glyphicon glyphicon-<?= stripos($a['enlace_url'],'teams.')!==false?'facetime-video':'bullhorn' ?>" aria-hidden="true"></span></span>
                <span class="aula-ed-recurso-txt"><strong><?= aula_h($a['titulo']) ?><?= (int)$a['fijado']?' · fijado':'' ?></strong>
                    <small><?= $a['sesion_id']?'Sesión: '.aula_h($a['sesion_tema']):'Todo el curso' ?> · <?= aula_h($a['creado_por_nombre']) ?><?= $a['enlace_url']?' · con enlace':'' ?></small></span>
                <span class="aula-badge <?= $tono ?>"><?= aula_h($estado) ?></span>
            </div>
            <details class="aula-ed-previa"><summary>Ver como lo ve el estudiante</summary><?= aula_anuncio_html($a) ?></details>
            <details class="aula-ed-editar"><summary>Editar</summary>
                <?= aula_anuncio_form($sessions,$teamsCurso,$a,$ancla) ?>
                <form method="post" class="aula-ed-borrar" onsubmit="return confirm('¿Eliminar el anuncio «<?= aula_h(addslashes($a['titulo'])) ?>»?');">
                    <?= aula_csrf() ?><input type="hidden" name="accion" value="anuncio_eliminar"><input type="hidden" name="anuncio" value="<?= (int)$a['id'] ?>"><input type="hidden" name="ancla" value="<?= aula_h($ancla) ?>">
                    <button class="btn btn-link btn-sm aula-ed-peligro"><span class="glyphicon glyphicon-trash" aria-hidden="true"></span> Eliminar anuncio</button>
                </form>
            </details>
        </article>
        <?php return ob_get_clean();
    }

/** Respuestas de evaluacion por instructor en el curso ([instructor_id => n]); [] si la tabla no existe. */
function aula_respuestas_instructor(PDO $db, $courseId) {
    try {
        $r = [];
        foreach (aula_rows($db, 'SELECT l.instructor_id,COUNT(e.id) AS n FROM enlaces_evaluacion_instructor l JOIN evaluaciones_instructor e ON e.enlace_id=l.id WHERE l.curso_id=? GROUP BY l.instructor_id', [(int) $courseId]) as $f) $r[(int) $f['instructor_id']] = (int) $f['n'];
        return $r;
    } catch (Throwable $e) {
        return [];
    }
}

/** Instructores activos del catalogo (para agregar a evaluar); [] si no se puede consultar. */
function aula_instructores_catalogo(PDO $db) {
    try {
        return aula_rows($db, 'SELECT id,nombre FROM instructores WHERE estado=1 ORDER BY nombre');
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------------------------------------------------------- reproductor de grabaciones
/**
 * Reproductor embebido para un enlace de video: YouTube, Vimeo, Google Drive o Loom.
 * Devuelve ['fuente' => 'YouTube', 'embed' => URL del reproductor, 'miniatura' => URL|null] o null si el
 * enlace no es de un servicio que se pueda insertar (se muestra como enlace).
 */
function aula_video_embed($url) {
    $url = aula_url($url);
    if (!$url) return null;
    if ($vimeo = aula_vimeo($url)) {
        return ['fuente' => 'Vimeo', 'embed' => $vimeo . (strpos($vimeo, '?') === false ? '?' : '&') . 'dnt=1', 'miniatura' => null];
    }
    $host = preg_replace('/^(www\.|m\.|music\.)/', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if (in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'], true)) {
        $id = '';
        if ($host === 'youtu.be') $id = trim($path, '/');
        elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) $id = $m[1];
        elseif (isset($q['v']) && is_string($q['v'])) $id = $q['v'];
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return null;
        // Inicio: ?t=90, ?t=1m30s o ?start=90
        $inicio = 0; $t = (string) ($q['t'] ?? $q['start'] ?? '');
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $t, $m) && $t !== '') $inicio = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        return ['fuente' => 'YouTube', 'embed' => 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1' . ($inicio ? '&start=' . $inicio : ''),
            'miniatura' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'];
    }
    if ($host === 'drive.google.com') {
        $id = preg_match('#/file/d/([A-Za-z0-9_-]{10,})#', $path, $m) ? $m[1] : (is_string($q['id'] ?? null) ? $q['id'] : '');
        if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $id)) return null;
        return ['fuente' => 'Google Drive', 'embed' => 'https://drive.google.com/file/d/' . $id . '/preview', 'miniatura' => null];
    }
    if ($host === 'loom.com' && preg_match('#^/(?:share|embed)/([a-f0-9]{20,})#', $path, $m)) {
        return ['fuente' => 'Loom', 'embed' => 'https://www.loom.com/embed/' . $m[1], 'miniatura' => null];
    }
    return null;
}

/**
 * Tarjeta de reproductor (miniatura + reproducir aqui + ampliar) para una grabacion: enlace de video o MP4
 * guardado en el aula. Si el enlace no se puede insertar devuelve '' y se muestra el enlace normal.
 * Requiere js/aula_video.js en la pagina.
 */
function aula_video_html($titulo, $url, $archivoId = null, $mime = '') {
    $titulo = (string) $titulo;
    if ($archivoId && $mime === 'video/mp4') {
        $src = 'aula_archivo.php?id=' . (int) $archivoId;
        ob_start(); ?>
        <div class="aula-player is-mp4">
            <video class="aula-player-video" controls preload="metadata" playsinline src="<?= aula_h($src) ?>#t=0.5" title="<?= aula_h($titulo) ?>"></video>
            <div class="aula-player-barra"><span class="aula-player-fuente">Video MP4</span>
                <button type="button" class="aula-player-btn" data-aula-ampliar data-video="<?= aula_h($src) ?>" data-titulo="<?= aula_h($titulo) ?>"><span class="glyphicon glyphicon-fullscreen" aria-hidden="true"></span> Ampliar</button>
                <a class="aula-player-btn" href="<?= aula_h($src) ?>" target="_blank" rel="noopener">Abrir ↗</a></div>
        </div>
        <?php return ob_get_clean();
    }
    $v = aula_video_embed($url);
    if (!$v) return '';
    ob_start(); ?>
    <div class="aula-player" data-embed="<?= aula_h($v['embed']) ?>" data-titulo="<?= aula_h($titulo) ?>">
        <button type="button" class="aula-player-portada<?= $v['miniatura'] ? '' : ' is-sin-miniatura' ?>" data-aula-play aria-label="Reproducir: <?= aula_h($titulo) ?>"<?= $v['miniatura'] ? ' style="background-image:url(\'' . aula_h($v['miniatura']) . '\')"' : '' ?>>
            <span class="aula-player-play" aria-hidden="true"></span>
            <span class="aula-player-titulo"><?= aula_h($titulo) ?></span>
        </button>
        <div class="aula-player-barra"><span class="aula-player-fuente"><?= aula_h($v['fuente']) ?></span>
            <button type="button" class="aula-player-btn" data-aula-play><span class="glyphicon glyphicon-play" aria-hidden="true"></span> Reproducir aquí</button>
            <button type="button" class="aula-player-btn" data-aula-ampliar><span class="glyphicon glyphicon-fullscreen" aria-hidden="true"></span> Ampliar</button>
            <a class="aula-player-btn" href="<?= aula_h($url) ?>" target="_blank" rel="noopener noreferrer">Abrir en <?= aula_h($v['fuente']) ?> ↗</a></div>
    </div>
    <?php return ob_get_clean();
}

// ---------------------------------------------------------------- asistencia rapida (pestaña Asistencia y editor)
/** Registros de asistencia del curso: [sesion_id][asignacion_id] => fila. */
function aula_asistencia_mapa(PDO $db, $courseId) {
    $m = [];
    foreach (aula_rows($db, 'SELECT r.sesion_id,r.asignacion_id,r.presente,r.registrado,r.origen,r.motivo FROM aula_asistencias r JOIN asignaciones a ON a.id=r.asignacion_id WHERE a.cursos_id=?', [(int) $courseId]) as $r) {
        $m[(int) $r['sesion_id']][(int) $r['asignacion_id']] = $r;
    }
    return $m;
}

/** Asistencia oficial por participante (la que usa Seguimiento): [asignacion_id] => ['asistencia' => %, 'sesiones_asistidas' => n]. */
function aula_asistencia_oficial(PDO $db, $courseId) {
    $p = [];
    foreach (aula_rows($db, 'SELECT cc.asignacion_id,cc.asistencia,cc.sesiones_asistidas FROM control_cursos cc JOIN asignaciones a ON a.id=cc.asignacion_id WHERE a.cursos_id=?', [(int) $courseId]) as $r) {
        $p[(int) $r['asignacion_id']] = $r;
    }
    return $p;
}

/** Boton de estado de asistencia (Presente / Ausente / sin registro) que usa js/aula_asistencia.js. */
function aula_asistencia_boton($sesionId, $asignacionId, $registro, $futura, $compacto = false) {
    $estado = $registro ? ((int) $registro['presente'] ? 'presente' : 'ausente') : 'vacio';
    $texto = ['presente' => $compacto ? '✓' : '✓ Presente', 'ausente' => $compacto ? '✗' : '✗ Ausente', 'vacio' => $compacto ? '·' : 'Sin registro'][$estado];
    $titulo = $futura ? 'Sesión futura' : ($registro ? (((int) $registro['presente'] ? 'Presente' : 'Ausente') . ' · ' . date('d/m H:i', strtotime($registro['registrado'])) . ($registro['origen'] === 'coordinacion' ? ' · por coordinación' : ' · confirmó el estudiante')) : 'Sin registro') . ($futura ? '' : ' — clic para cambiar');
    return '<button type="button" class="aula-asis-btn is-' . $estado . ($compacto ? ' is-compacto' : '') . '" data-asis-celda data-sesion="' . (int) $sesionId . '" data-asignacion="' . (int) $asignacionId . '" data-estado="' . $estado . '"'
        . ($futura ? ' disabled' : '') . ' title="' . aula_h($titulo) . '" aria-label="' . aula_h($titulo) . '">' . $texto . '</button>';
}

/** Estado del registro de asistencia de una sesion para coordinacion: abierta, programada, cerrada o deshabilitada. */
function aula_ventana_estado(array $s, ?array $w, $now = null) {
    $now = $now ?? date('Y-m-d H:i:s');
    $abre = $s['fecha'] . ' ' . $s['hora_inicio']; $cierra = $s['fecha'] . ' ' . $s['hora_fin'];
    if ($cierra <= $abre) $cierra = date('Y-m-d H:i:s', strtotime($cierra . ' +1 day'));
    $manual = $w && !empty($w['horario_manual']);
    if ($manual) { $abre = $w['abre']; $cierra = $w['cierra']; }
    $codigo = $w && $w['codigo_hash'] !== '';
    $rango = aula_fecha_larga($abre, true) . ' ' . substr($abre, 11, 5) . ' → ' . (substr($abre, 0, 10) === substr($cierra, 0, 10) ? '' : aula_fecha_larga($cierra, true) . ' ') . substr($cierra, 11, 5);
    if ($w && !(int) $w['habilitada']) $clave = 'deshabilitada';
    elseif (aula_session_window($s, $w, $now)) $clave = 'abierta';
    elseif ($now < $abre) $clave = 'programada';
    else $clave = 'cerrada';
    $texto = ['deshabilitada' => 'Registro cerrado por coordinación', 'abierta' => 'Abierto ahora · cierra ' . (substr($cierra, 0, 10) === substr($now, 0, 10) ? 'a las ' . substr($cierra, 11, 5) : aula_fecha_larga($cierra, true) . ' ' . substr($cierra, 11, 5)),
        'programada' => 'Se abre ' . aula_fecha_larga($abre, true) . ' ' . substr($abre, 11, 5), 'cerrada' => 'Cerrado desde ' . aula_fecha_larga($cierra, true) . ' ' . substr($cierra, 11, 5)][$clave];
    return ['clave' => $clave, 'texto' => $texto, 'rango' => $rango, 'manual' => $manual, 'codigo' => $codigo, 'abre' => $abre, 'cierra' => $cierra];
}

/** Botones rapidos para abrir, extender o cerrar el registro de asistencia de una sesion (accion ventana_rapida). */
function aula_ventana_panel(array $s, ?array $w, $ancla, $lista = 0) {
    $e = aula_ventana_estado($s, $w);
    $clase = ['abierta' => 'is-complete', 'programada' => 'is-programado', 'cerrada' => 'is-oculto', 'deshabilitada' => 'is-oculto'][$e['clave']];
    $base = aula_csrf() . '<input type="hidden" name="accion" value="ventana_rapida"><input type="hidden" name="sesion" value="' . (int) $s['id'] . '"><input type="hidden" name="ancla" value="' . aula_h($ancla) . '">' . ($lista ? '<input type="hidden" name="lista" value="' . (int) $lista . '">' : '');
    $ref = $e['cierra'] > date('Y-m-d H:i:s') ? $e['cierra'] : date('Y-m-d H:i:s');
    $sugerido = date('Y-m-d\TH:i', strtotime($ref . ' +1 day'));
    ob_start(); ?>
<div class="aula-ventana">
    <div class="aula-ventana-estado"><span class="glyphicon glyphicon-time" aria-hidden="true"></span>
        <span><strong>Registro de asistencia del estudiante</strong> <span class="aula-badge <?= $clase ?>"><?= aula_h($e['texto']) ?></span>
        <small><?= $e['manual'] ? 'Horario especial: ' : 'Según Programa de sesiones: ' ?><?= aula_h($e['rango']) ?><?= $e['codigo'] ? ' · pide código' : ' · sin código' ?></small></span></div>
    <form method="post" class="aula-ventana-form"><?= $base ?>
        <span class="aula-ventana-grupo"><span class="aula-muted">Abrir ahora por</span>
            <button class="btn btn-default btn-sm" name="modo" value="ahora" onclick="this.form.minutos.value=15">15 min</button>
            <button class="btn btn-default btn-sm" name="modo" value="ahora" onclick="this.form.minutos.value=30">30 min</button>
            <button class="btn btn-default btn-sm" name="modo" value="ahora" onclick="this.form.minutos.value=120">2 horas</button>
            <input type="hidden" name="minutos" value="30"></span>
        <span class="aula-ventana-grupo"><label class="aula-muted">Extender hasta<input type="datetime-local" name="hasta" value="<?= aula_h($sugerido) ?>"></label><button class="btn btn-default btn-sm" name="modo" value="hasta">Extender</button></span>
        <span class="aula-ventana-grupo">
            <?php if ($e['manual'] || ($w && !(int) $w['habilitada'])): ?><button class="btn btn-default btn-sm" name="modo" value="auto" title="Vuelve al horario de la sesión">↺ Según horario</button><?php endif; ?>
            <?php if ($e['clave'] !== 'deshabilitada'): ?><button class="btn btn-default btn-sm aula-ventana-cerrar" name="modo" value="cerrar">Cerrar registro</button><?php endif; ?></span>
        <span class="aula-ventana-grupo"><label class="aula-ed-check"><input type="checkbox" name="pedir_codigo" <?= $e['codigo'] ? 'checked' : '' ?>> Pedir código</label><input name="codigo" maxlength="30" autocomplete="off" placeholder="<?= $e['codigo'] ? 'Se conserva el actual' : 'Ej. CANA-2026' ?>" class="aula-ventana-codigo"><button class="btn btn-default btn-sm" name="modo" value="codigo">Guardar código</button></span>
    </form>
</div>
<?php return ob_get_clean();
}
