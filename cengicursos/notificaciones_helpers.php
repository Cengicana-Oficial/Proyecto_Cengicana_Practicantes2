<?php
/**
 * Campana de notificaciones del topbar: pendientes que esperan una acción del usuario.
 * Cada aviso solo se calcula si el rol puede actuar sobre él, y con el mismo alcance
 * por ingenio (y los mismos criterios) que la página a la que lleva.
 *
 * Funciona como lista de tareas: un aviso desaparece cuando el pendiente se resuelve.
 * Para no insistir con lo ya revisado, el número rojo cuenta solo lo NUEVO desde la última
 * vez que el usuario abrió la campana (los identificadores vistos se guardan en el navegador);
 * con pendientes ya vistos, la campana muestra solo un punto discreto.
 */
require_once __DIR__ . '/revisar_permisos.php';

/**
 * Avisos con pendientes: [['clave','icono','titulo','detalle','href','n','tono','ids'], ...].
 * "n" es lo que se muestra; "ids" identifica cada pendiente para saber cuáles son nuevos.
 */
function cengi_notificaciones()
{
    static $avisos = null;
    if ($avisos !== null) {
        return $avisos;
    }
    $avisos = [];
    if (cengi_es_estudiante()) {
        return $avisos;
    }
    try {
        $db = conectar();
    } catch (Throwable $e) {
        return $avisos;
    }

    $esAdmin = cengi_es_admin();
    $verTodo = cengi_ve_todo_por_rol_o_ingenio();
    $ingenio = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
    // Alcance por ingenio (mismo criterio que solicitudes.php).
    $alcance = static function (&$params) use ($verTodo, $ingenio) {
        if ($verTodo) {
            return '';
        }
        $params[] = $ingenio;
        return ' AND ' . cengi_sql_texto_normalizado('i.nombre_ingenios') . ' = ?';
    };
    // Identificadores de los pendientes (la consulta devuelve una columna).
    $ids = static function ($sql, array $params = []) use ($db) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return []; // Tabla o columna aún no migrada: el aviso simplemente no aparece.
        }
    };
    $agregar = static function ($clave, array $lista, $icono, $titulo, $detalle, $href, $tono = 'verde', $n = null) use (&$avisos) {
        if ($lista) {
            $avisos[] = ['clave' => $clave, 'icono' => $icono, 'titulo' => $titulo, 'detalle' => $detalle, 'href' => $href,
                'n' => $n ?? count($lista), 'tono' => $tono, 'ids' => array_slice($lista, 0, 300)];
        }
    };

    $puedeValidarPagos = !cengi_es_gestor_ingenio() && ($esAdmin || cengi_tiene_permiso('validar_pagos_cengi'));

    if (cengi_puede_ver_solicitudes()) {
        // Interesados por revisar: sin contar a quienes ya aparecen en "Boletas por validar" o en
        // "Validación del ingenio" (antes se contaban dos veces).
        $p = [];
        $agregar('interesados', $ids("SELECT s.id_solicitud FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON s.ingenio_id = i.id
            WHERE s.planificacion_id IS NOT NULL AND s.estado = 'Pendiente'
              AND NOT (s.tipo_pago = 'Ingenio' AND COALESCE(s.validacion_ingenio, '') = 'pendiente')
              AND NOT (s.tipo_pago = 'Propio' AND s.pago_estado = 'en revisión' AND COALESCE(s.comprobante_ruta, '') <> '')" . $alcance($p), $p),
            'inbox', 'Interesados por revisar', 'Convocatorias: validación o asignación pendiente', 'inscripciones.php?vista=interesados');
        $p = [];
        $agregar('directas', $ids("SELECT s.id_solicitud FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON s.ingenio_id = i.id
            WHERE s.planificacion_id IS NULL AND s.estado = 'Pendiente'" . $alcance($p), $p),
            'list-alt', 'Solicitudes directas', 'Esperan aprobación', 'solicitudes.php');
    }

    if ($puedeValidarPagos) {
        $agregar('boletas', $ids("SELECT id_solicitud FROM solicitudes_inscripcion WHERE tipo_pago = 'Propio' AND pago_estado = 'en revisión'
            AND estado <> 'Rechazado' AND COALESCE(comprobante_ruta, '') <> ''"),
            'check', 'Boletas por validar', 'Pago propio de inscripción', 'inscripciones.php?vista=pagos', 'naranja');
    }

    // Validación del ingenio: el usuario de ingenio ve solo los suyos; el administrador, todos.
    // Mismo criterio que "Ingenios y proformas": incluye a los ya asignados que siguen sin validar.
    if ($esAdmin || cengi_es_ingenio()) {
        $p = [];
        $agregar('ingenio', $ids("SELECT s.id_solicitud FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON s.ingenio_id = i.id
            WHERE s.tipo_pago = 'Ingenio' AND s.validacion_ingenio = 'pendiente' AND s.estado <> 'Rechazado' AND s.planificacion_id IS NOT NULL" . $alcance($p), $p),
            'briefcase', 'Validación del ingenio', 'Inscritos que paga el ingenio', 'inscripciones.php?vista=pagos&tab=grupos', 'naranja');
    }

    if ($esAdmin || cengi_tiene_permiso('validar_pagos_cengi')) {
        $agregar('eventos', $ids("SELECT id FROM evento_participantes WHERE pago_estado = 'por_validar'"),
            'qrcode', 'Comprobantes de eventos', 'Depósitos y transferencias por validar', 'eventos_qr.php?tab=pagos', 'naranja');
    }

    if (cengi_tiene_permiso('firmar_liquidaciones_cengi')) {
        $agregar('liquidaciones', $ids("SELECT id FROM liquidaciones WHERE estado IN ('revision', 'enviada') AND firmada_en IS NULL"),
            'pencil', 'Liquidaciones por firmar', 'Pago a instructores', 'pago_instructores.php?tab=liquidaciones', 'amarillo');
    }

    if (cengi_puede_gestionar()) {
        // Se muestra cuántos CURSOS tienen cambios por avisar (como "Por avisar" en Comunicaciones);
        // cada cambio cuenta como novedad para el número rojo.
        $cambios = $ids("SELECT CONCAT(planificacion_id, ':', id) FROM planificacion_cambios WHERE notificacion = 'pendiente'");
        $cursos = count(array_unique(array_map(static function ($c) { return strtok($c, ':'); }, $cambios)));
        $agregar('cambios', $cambios, 'envelope', 'Cambios sin notificar', $cursos === 1 ? '1 curso: avisar a los inscritos' : $cursos . ' cursos: avisar a los inscritos',
            'comunicaciones.php?tab=avisar', 'amarillo', $cursos);
    }

    return $avisos;
}

/** Botón de campana con su panel desplegable (va en .cengi-topbar-right). */
function cengi_notificaciones_render()
{
    if (cengi_es_estudiante()) {
        return;
    }
    $avisos = cengi_notificaciones();
    $total = array_sum(array_column($avisos, 'n'));
    $h = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $etiqueta = $total > 0 ? $total . ' pendiente' . ($total === 1 ? '' : 's') : 'Sin pendientes';
    $usuario = (int) (function_exists('cengi_usuario_actual_id') ? cengi_usuario_actual_id() : 0);
    ?>
    <div class="cengi-notif" id="cengiNotif" data-usuario="<?php echo $usuario; ?>">
        <button type="button" class="cengi-notif-btn<?php echo $total > 0 ? ' has-avisos' : ''; ?>" id="cengiNotifBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="cengiNotifPanel"
                aria-label="Notificaciones: <?php echo $h($etiqueta); ?>" title="Notificaciones">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php /* Sin JavaScript se muestra el total; con JavaScript, solo lo nuevo (menu.php). */ ?>
            <?php if ($total > 0): ?><span class="cengi-notif-n" id="cengiNotifN"><?php echo $total > 99 ? '99+' : $total; ?></span><?php endif; ?>
        </button>
        <div class="cengi-notif-panel" id="cengiNotifPanel" role="menu" hidden>
            <div class="cengi-notif-head">
                <b>Notificaciones</b>
                <span id="cengiNotifEtiqueta"><?php echo $h($etiqueta); ?></span>
            </div>
            <?php if ($avisos): ?>
                <ul class="cengi-notif-lista">
                    <?php foreach ($avisos as $a): ?>
                        <li>
                            <a href="<?php echo $h($a['href']); ?>" class="cengi-notif-item is-<?php echo $h($a['tono']); ?>" role="menuitem"
                               data-clave="<?php echo $h($a['clave']); ?>" data-ids="<?php echo $h(implode(',', $a['ids'])); ?>">
                                <span class="cengi-notif-ico"><span class="glyphicon glyphicon-<?php echo $h($a['icono']); ?>"></span></span>
                                <span class="cengi-notif-txt"><b><?php echo $h($a['titulo']); ?> <em class="cengi-notif-nuevo" hidden></em></b><small><?php echo $h($a['detalle']); ?></small></span>
                                <span class="cengi-notif-cant"><?php echo (int) $a['n']; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="cengi-notif-pie">Los avisos se quitan solos al resolver el pendiente. El número rojo indica solo lo nuevo desde la última vez que abriste la campana.</p>
            <?php else: ?>
                <div class="cengi-notif-vacio">
                    <span class="glyphicon glyphicon-ok-circle"></span>
                    <p>Todo al día. No tienes pendientes por atender.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
