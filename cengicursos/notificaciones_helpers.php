<?php
/**
 * Campana de notificaciones del topbar: pendientes que esperan una acción del usuario.
 * Cada aviso solo se calcula si el rol puede actuar sobre él, y con el mismo alcance
 * por ingenio que usa la página a la que lleva.
 */
require_once __DIR__ . '/revisar_permisos.php';

/** Lista de avisos: [['icono', 'titulo', 'detalle', 'href', n, 'tono'], ...] (solo con n > 0). */
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
    $contar = static function ($sql, array $params = []) use ($db) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0; // Tabla o columna aún no migrada: el aviso simplemente no aparece.
        }
    };
    $agregar = static function ($n, $icono, $titulo, $detalle, $href, $tono = 'verde') use (&$avisos) {
        if ($n > 0) {
            $avisos[] = [$icono, $titulo, $detalle, $href, $n, $tono];
        }
    };

    $puedeValidarPagos = !cengi_es_gestor_ingenio() && ($esAdmin || cengi_tiene_permiso('validar_pagos_cengi'));

    if (cengi_puede_ver_solicitudes()) {
        $p = [];
        $agregar($contar("SELECT COUNT(*) FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON s.ingenio_id = i.id
            WHERE s.planificacion_id IS NOT NULL AND s.estado = 'Pendiente'" . $alcance($p), $p),
            'inbox', 'Inscripciones pendientes', 'Convocatorias por revisar', 'inscripciones.php');
        $p = [];
        $agregar($contar("SELECT COUNT(*) FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON s.ingenio_id = i.id
            WHERE s.planificacion_id IS NULL AND s.estado = 'Pendiente'" . $alcance($p), $p),
            'list-alt', 'Solicitudes directas', 'Esperan aprobación', 'solicitudes.php');
    }

    if ($puedeValidarPagos) {
        $agregar($contar("SELECT COUNT(*) FROM solicitudes_inscripcion WHERE tipo_pago = 'Propio' AND pago_estado = 'en revisión'
            AND estado <> 'Rechazado' AND COALESCE(comprobante_ruta, '') <> ''"),
            'check', 'Boletas por validar', 'Pago propio de inscripción', 'inscripciones.php?vista=pagos', 'naranja');
    }

    // Validación del ingenio: el usuario de ingenio ve solo los suyos; el administrador, todos.
    if ($esAdmin || cengi_es_ingenio()) {
        $p = [];
        $agregar($contar("SELECT COUNT(*) FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON s.ingenio_id = i.id
            WHERE s.tipo_pago = 'Ingenio' AND s.validacion_ingenio = 'pendiente' AND s.estado = 'Pendiente'" . $alcance($p), $p),
            'briefcase', 'Validación del ingenio', 'Inscritos que paga el ingenio', 'inscripciones.php?vista=pagos&tab=grupos', 'naranja');
    }

    if ($esAdmin || cengi_tiene_permiso('validar_pagos_cengi')) {
        $agregar($contar("SELECT COUNT(*) FROM evento_participantes WHERE pago_estado = 'por_validar'"),
            'qrcode', 'Comprobantes de eventos', 'Depósitos y transferencias por validar', 'eventos_qr.php?tab=pagos', 'naranja');
    }

    if (cengi_tiene_permiso('firmar_liquidaciones_cengi')) {
        $agregar($contar("SELECT COUNT(*) FROM liquidaciones WHERE estado IN ('revision', 'enviada') AND firmada_en IS NULL"),
            'pencil', 'Liquidaciones por firmar', 'Pago a instructores', 'pago_instructores.php?tab=liquidaciones', 'amarillo');
    }

    if (cengi_puede_gestionar()) {
        $agregar($contar("SELECT COUNT(*) FROM planificacion_cambios WHERE notificacion = 'pendiente'"),
            'envelope', 'Cambios sin notificar', 'Avisar a los inscritos', 'comunicaciones.php', 'amarillo');
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
    $total = array_sum(array_column($avisos, 4));
    $h = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $etiqueta = $total > 0 ? $total . ' pendiente' . ($total === 1 ? '' : 's') : 'Sin pendientes';
    ?>
    <div class="cengi-notif" id="cengiNotif">
        <button type="button" class="cengi-notif-btn<?php echo $total > 0 ? ' has-avisos' : ''; ?>" id="cengiNotifBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="cengiNotifPanel"
                aria-label="Notificaciones: <?php echo $h($etiqueta); ?>" title="Notificaciones">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php if ($total > 0): ?><span class="cengi-notif-n"><?php echo $total > 99 ? '99+' : $total; ?></span><?php endif; ?>
        </button>
        <div class="cengi-notif-panel" id="cengiNotifPanel" role="menu" hidden>
            <div class="cengi-notif-head">
                <b>Notificaciones</b>
                <span><?php echo $h($etiqueta); ?></span>
            </div>
            <?php if ($avisos): ?>
                <ul class="cengi-notif-lista">
                    <?php foreach ($avisos as [$icono, $titulo, $detalle, $href, $n, $tono]): ?>
                        <li>
                            <a href="<?php echo $h($href); ?>" class="cengi-notif-item is-<?php echo $h($tono); ?>" role="menuitem">
                                <span class="cengi-notif-ico"><span class="glyphicon glyphicon-<?php echo $h($icono); ?>"></span></span>
                                <span class="cengi-notif-txt"><b><?php echo $h($titulo); ?></b><small><?php echo $h($detalle); ?></small></span>
                                <span class="cengi-notif-cant"><?php echo (int) $n; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
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
