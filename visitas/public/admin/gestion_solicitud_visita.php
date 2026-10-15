<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function status_id(PDO $conn, string $name): int
{
    $stmt = $conn->prepare('SELECT id_estado FROM estados WHERE upper(trim(nombre_estado)) = ? LIMIT 1');
    $stmt->execute([strtoupper($name)]);
    return (int) $stmt->fetchColumn();
}

function update_status(PDO $conn, int $requestId, string $name): void
{
    $statusId = status_id($conn, $name);
    if ($statusId <= 0) {
        throw new RuntimeException('Falta ejecutar la actualización del flujo de visitas.');
    }
    $stmt = $conn->prepare('UPDATE solicitudes SET id_estado = ? WHERE id_solicitud = ?');
    $stmt->execute([$statusId, $requestId]);
}

function ensure_management_row(PDO $conn, int $requestId): void
{
    if (Conexion::driver() === 'pgsql') {
        $stmt = $conn->prepare('INSERT INTO solicitud_gestion_visita (id_solicitud) VALUES (?) ON CONFLICT (id_solicitud) DO NOTHING');
    } else {
        $stmt = $conn->prepare('INSERT IGNORE INTO solicitud_gestion_visita (id_solicitud) VALUES (?)');
    }
    $stmt->execute([$requestId]);
}

function log_visit_event(PDO $conn, int $requestId, string $type, string $description): void
{
    $actor = (string) ($_SESSION['nombre'] ?? $_SESSION['full_name'] ?? $_SESSION['email'] ?? 'Administrador');
    $stmt = $conn->prepare('INSERT INTO solicitud_eventos_visita (id_solicitud, tipo, descripcion, actor) VALUES (?, ?, ?, ?)');
    $stmt->execute([$requestId, $type, $description, $actor]);
}

if (!can_access('gestionar_solicitudes')) {
    respond(['success' => false, 'error' => 'No tienes permiso para gestionar solicitudes.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Metodo no permitido.'], 405);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    respond(['success' => false, 'error' => 'Solicitud invalida.'], 400);
}
if (!hash_equals((string) ($_SESSION['visits_csrf'] ?? ''), (string) ($payload['csrf'] ?? ''))) {
    respond(['success' => false, 'error' => 'La sesion de revision vencio. Actualiza la pagina.'], 419);
}

$action = (string) ($payload['action'] ?? '');
$requestId = (int) ($payload['request_id'] ?? 0);
if ($requestId <= 0) {
    respond(['success' => false, 'error' => 'Solicitud no valida.'], 422);
}

try {
    $conn = Conexion::conectar();
    $conn->beginTransaction();

    $stmt = $conn->prepare(
        "SELECT s.id_solicitud, s.tipo_visita, upper(trim(e.nombre_estado)) AS estado
         FROM solicitudes s
         INNER JOIN estados e ON e.id_estado = s.id_estado
         WHERE s.id_solicitud = ? FOR UPDATE"
    );
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new RuntimeException('La solicitud ya no existe.');
    }

    $currentStatus = (string) $request['estado'];
    $isPending = in_array($currentStatus, ['PENDIENTE', 'ENVIADO'], true);
    $isOrganizing = $currentStatus === 'ORGANIZANDO';
    $isDirectorReview = $currentStatus === 'REVISION_DIRECTOR';
    $isActive = !in_array($currentStatus, ['RECHAZADO', 'CANCELADO'], true);

    if ($action === 'start_organizing') {
        if ($isPending) {
            update_status($conn, $requestId, 'ORGANIZANDO');
            log_visit_event($conn, $requestId, 'organizando', 'Se inició la organización de la visita.');
        }
    } elseif ($action === 'send_director') {
        if (!$isOrganizing) {
            throw new RuntimeException('La solicitud debe estar en organización antes de enviarla al director.');
        }
        if (($request['tipo_visita'] ?? 'cengicana') !== 'museo_azucar') {
            $areaCount = $conn->prepare('SELECT COUNT(*) FROM solicitud_areas WHERE id_solicitud = ?');
            $areaCount->execute([$requestId]);
            if ((int) $areaCount->fetchColumn() === 0) {
                throw new RuntimeException('Asigna al menos un área antes de enviar la solicitud al director.');
            }
        }
        update_status($conn, $requestId, 'REVISION_DIRECTOR');
        log_visit_event($conn, $requestId, 'revision_director', 'Solicitud enviada a aprobación del director.');
    } elseif ($action === 'notify_areas') {
        if (!$isActive) {
            throw new RuntimeException('La solicitud ya no está activa.');
        }
        ensure_management_row($conn, $requestId);
        $stmt = $conn->prepare('UPDATE solicitud_gestion_visita SET notif_areas_at = CURRENT_TIMESTAMP WHERE id_solicitud = ?');
        $stmt->execute([$requestId]);
        log_visit_event($conn, $requestId, 'notif_areas', 'Se preparó la notificación para las áreas asignadas.');
    } elseif ($action === 'notify_institution') {
        if (!in_array($currentStatus, ['APROBADO', 'NOTIFICADO'], true)) {
            throw new RuntimeException('La confirmación estará disponible cuando el director apruebe la solicitud.');
        }
        ensure_management_row($conn, $requestId);
        $stmt = $conn->prepare('UPDATE solicitud_gestion_visita SET notif_institucion_at = CURRENT_TIMESTAMP WHERE id_solicitud = ?');
        $stmt->execute([$requestId]);
        if ($currentStatus === 'APROBADO') {
            update_status($conn, $requestId, 'NOTIFICADO');
        }
        log_visit_event($conn, $requestId, 'notificado', 'Confirmación preparada para la institución solicitante.');
    } elseif ($action === 'save_contact') {
        ensure_management_row($conn, $requestId);
        $stmt = $conn->prepare('UPDATE solicitud_gestion_visita SET contacto_guardado_at = CURRENT_TIMESTAMP WHERE id_solicitud = ?');
        $stmt->execute([$requestId]);
        log_visit_event($conn, $requestId, 'contacto', 'Solicitante agregado a la base de datos de contactos.');

    } elseif (in_array($action, ['replace_area', 'remove_area', 'add_area'], true)) {
        if (!$isPending && !$isOrganizing) {
            throw new RuntimeException('Las areas quedan bloqueadas despues de tomar una decision.');
        }

        if ($action === 'replace_area') {
            $originalAreaId = (int) ($payload['original_area_id'] ?? 0);
            $newAreaId = (int) ($payload['new_area_id'] ?? 0);
            if ($originalAreaId <= 0 || $newAreaId <= 0) {
                throw new RuntimeException('Selecciona el area que deseas utilizar.');
            }
            $valid = $conn->prepare('SELECT COUNT(*) FROM areas_interes WHERE id_area = ? AND estado = 1');
            $valid->execute([$newAreaId]);
            if ((int) $valid->fetchColumn() === 0) {
                throw new RuntimeException('El area seleccionada ya no esta disponible.');
            }
            $update = $conn->prepare(
                'UPDATE solicitud_areas SET id_area_asignada = ? WHERE id_solicitud = ? AND id_area = ?'
            );
            $update->execute([$newAreaId, $requestId, $originalAreaId]);
            if ($update->rowCount() === 0) {
                throw new RuntimeException('No fue posible localizar el area solicitada.');
            }
            log_visit_event($conn, $requestId, 'area', 'Se reemplazó un área de la visita.');
        } elseif ($action === 'remove_area') {
            $originalAreaId = (int) ($payload['original_area_id'] ?? 0);
            $delete = $conn->prepare('DELETE FROM solicitud_areas WHERE id_solicitud = ? AND id_area = ?');
            $delete->execute([$requestId, $originalAreaId]);
            if ($delete->rowCount() === 0) {
                throw new RuntimeException('El area ya habia sido retirada.');
            }
            log_visit_event($conn, $requestId, 'area', 'Se retiró un área de la visita.');
        } else {
            $newAreaId = (int) ($payload['new_area_id'] ?? 0);
            $valid = $conn->prepare('SELECT COUNT(*) FROM areas_interes WHERE id_area = ? AND estado = 1');
            $valid->execute([$newAreaId]);
            if ($newAreaId <= 0 || (int) $valid->fetchColumn() === 0) {
                throw new RuntimeException('Selecciona un area activa.');
            }
            $exists = $conn->prepare(
                'SELECT COUNT(*) FROM solicitud_areas WHERE id_solicitud = ? AND (id_area = ? OR id_area_asignada = ?)'
            );
            $exists->execute([$requestId, $newAreaId, $newAreaId]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new RuntimeException('Esta area ya forma parte de la solicitud.');
            }
            $insert = $conn->prepare('INSERT INTO solicitud_areas (id_solicitud, id_area) VALUES (?, ?)');
            $insert->execute([$requestId, $newAreaId]);
            log_visit_event($conn, $requestId, 'area', 'Se agregó un área a la visita.');
        }
    } elseif ($action === 'change_status') {
        if (!$isActive || !($isPending || $isOrganizing || $isDirectorReview)) {
            throw new RuntimeException('La decision ya fue registrada y no puede modificarse.');
        }
        $decision = strtolower(trim((string) ($payload['decision'] ?? '')));
        $statusNames = [
            'approve' => 'APROBADO',
            'reject' => 'RECHAZADO',
            'cancel' => 'CANCELADO',
        ];
        if (!isset($statusNames[$decision])) {
            throw new RuntimeException('Decision no valida.');
        }
        if ($decision === 'approve' && ($request['tipo_visita'] ?? 'cengicana') !== 'museo_azucar') {
            $areaCount = $conn->prepare('SELECT COUNT(*) FROM solicitud_areas WHERE id_solicitud = ?');
            $areaCount->execute([$requestId]);
            if ((int) $areaCount->fetchColumn() === 0) {
                throw new RuntimeException('La solicitud debe conservar al menos un area antes de aprobarse.');
            }
        }
        $statusName = $statusNames[$decision];
        update_status($conn, $requestId, $statusName);
        log_visit_event($conn, $requestId, strtolower($statusName), 'Solicitud ' . strtolower($statusName) . ' por el responsable de aprobación.');
    } else {
        throw new RuntimeException('Accion no reconocida.');
    }

    $conn->commit();
    respond(['success' => true]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    respond(['success' => false, 'error' => $error->getMessage()], 422);
}
