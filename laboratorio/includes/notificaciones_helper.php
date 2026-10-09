<?php
/**
 * Campanita de notificaciones del shell: lo que le toca atender a cada usuario según sus
 * permisos (roles de Laboratorio). Se calcula en el momento a partir del flujo de trabajo, sin
 * tabla propia: una notificación desaparece sola cuando la tarea se atiende.
 *
 *  - Analista / técnico que captura: análisis asignados por capturar y análisis devueltos.
 *  - Quien planifica (kanban.gestionar): análisis sin analista asignado.
 *  - Quien valida (validacion_tecnica.aprobar): análisis en validación técnica; informes devueltos.
 *  - Quien firma (firma.gestionar): informes listos para aprobar y firmar.
 *  - Quien entrega reportes (documentos.ver + solicitudes.crear o documentos.gestionar):
 *    informes firmados que aún no se han enviado por correo al cliente.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workflow_trabajo_helper.php';

const LAB_NOTIF_CACHE_SEGUNDOS = 60;

function lab_notificaciones(PDO $pdo, bool $fresco = false): array
{
    $user = lab_current_user();
    $cacheKey = 'lab_notif_' . md5((string) ($user['id'] ?? '') . '|' . ($user['nombre'] ?? ''));
    $cache = $_SESSION[$cacheKey] ?? null;
    if (!$fresco && is_array($cache) && ($cache['t'] ?? 0) > time() - LAB_NOTIF_CACHE_SEGUNDOS) {
        return $cache['items'];
    }

    $items = [];
    $agregar = static function (string $id, string $titulo, string $detalle, int $cantidad, string $href, string $tono = 'info') use (&$items): void {
        if ($cantidad > 0) {
            $items[] = compact('id', 'titulo', 'detalle', 'cantidad', 'href', 'tono');
        }
    };

    $captura = lab_can('laboratorio.formularios_pendientes.ver') || lab_is_technician();
    $planifica = lab_can('laboratorio.kanban.gestionar');
    $valida = lab_can('laboratorio.validacion_tecnica.aprobar');
    $firma = lab_can('laboratorio.firma.gestionar');
    $entrega = lab_can('laboratorio.documentos.ver') && (lab_can('laboratorio.solicitudes.crear') || lab_can('laboratorio.documentos.gestionar'));

    $tareas = [];
    if ($captura || $planifica || $valida || $firma) {
        try {
            $tareas = wfTareas($pdo);
        } catch (Throwable $e) {
            error_log('[laboratorio][notificaciones] tareas: ' . $e->getMessage());
        }
    }

    // Tareas del analista actual (asignadas a su usuario, o por nombre en asignaciones antiguas).
    $esMia = static fn(array $t): bool => (!empty($user['id']) && (int) ($t['usuario_id'] ?? 0) === (int) $user['id'])
        || (empty($t['usuario_id']) && !empty($user['nombre']) && ($t['analista'] ?? '') === $user['nombre']);

    if ($captura) {
        $porCapturar = $devueltos = 0;
        $lotes = [];
        foreach ($tareas as $t) {
            if (!$esMia($t)) continue;
            $estado = $t['estado']['key'] ?? '';
            if (in_array($estado, ['pendiente', 'proceso'], true)) { $porCapturar++; $lotes[$t['codigo_lote']] = true; }
            if (in_array($estado, ['rechazado', 'repetido'], true)) $devueltos++;
        }
        $agregar('captura', 'Análisis por capturar', 'Asignados a usted en ' . count($lotes) . ' lote(s)', $porCapturar, 'view/solicitudes_pendientes_tecnico.php');
        $agregar('devueltos', 'Análisis devueltos para repetir', 'Rechazados o marcados para repetir en validación técnica', $devueltos, 'view/solicitudes_pendientes_tecnico.php', 'alerta');
    }

    if ($planifica) {
        $sinAsignar = 0;
        $lotes = [];
        foreach ($tareas as $t) {
            if (($t['estado']['key'] ?? '') === 'pendiente' && empty($t['usuario_id']) && trim((string) ($t['analista'] ?? '')) === '') {
                $sinAsignar++;
                $lotes[$t['codigo_lote']] = true;
            }
        }
        $agregar('planificar', 'Análisis sin analista asignado', count($lotes) . ' lote(s) por planificar', $sinAsignar, 'view/kanban_view.php');
    }

    if ($valida) {
        $enValidacion = 0;
        foreach ($tareas as $t) {
            if (($t['estado']['key'] ?? '') === 'validacion') $enValidacion++;
        }
        $agregar('validacion', 'Análisis por validar', 'Capturados y esperando validación técnica', $enValidacion, 'view/validacion_tecnica_view.php');
    }

    if ($valida || $firma) {
        try {
            $informes = [];
            foreach ($pdo->query('SELECT id_lote, estado FROM lab_informe') as $fila) {
                $informes[(int) $fila['id_lote']] = (string) $fila['estado'];
            }
            if ($valida) {
                $agregar('informes_devueltos', 'Informes devueltos por el jefe', 'Revise el motivo en Validación técnica',
                    count(array_filter($informes, static fn($e) => $e === 'DEVUELTO')), 'view/validacion_tecnica_view.php', 'alerta');
            }
            if ($firma) {
                // Lote listo: todos sus análisis validados y su informe aún sin firmar.
                $lotes = [];
                foreach ($tareas as $t) {
                    $id = (int) $t['id_lote'];
                    $lotes[$id] = ($lotes[$id] ?? true) && ($t['estado']['key'] ?? '') === 'validado';
                }
                $listos = count(array_filter($lotes, static fn($ok, $id) => $ok && ($informes[$id] ?? '') !== 'FIRMADO', ARRAY_FILTER_USE_BOTH));
                $agregar('firmar', 'Informes por aprobar y firmar', 'Lotes con todos sus análisis validados', $listos, 'view/informes_view.php');
            }
        } catch (Throwable $e) {
            error_log('[laboratorio][notificaciones] informes: ' . $e->getMessage());
        }
    }

    if ($entrega) {
        try {
            $porEnviar = (int) $pdo->query("
                SELECT COUNT(*) FROM lab_documento d
                WHERE d.tipo_documento = 'informe'
                  AND NOT EXISTS (SELECT 1 FROM lab_documento_historial h
                                  WHERE h.id_documento = d.id_documento AND h.cambios LIKE 'Enviado por correo%')
            ")->fetchColumn();
            $agregar('reportes', 'Reportes firmados por enviar', 'Informes aprobados que aún no se envían al cliente', $porEnviar, 'view/documentos_view.php');
        } catch (Throwable $e) {
            error_log('[laboratorio][notificaciones] reportes: ' . $e->getMessage());
        }
    }

    $_SESSION[$cacheKey] = ['t' => time(), 'items' => $items];
    return $items;
}
