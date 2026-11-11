<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_login();
require_once __DIR__ . '/../../config/visit_contacts.php';

header('Content-Type: application/json; charset=utf-8');

function contact_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function contact_text(array $payload, string $key, int $max): string
{
    $value = trim(preg_replace('/\s+/u', ' ', (string) ($payload[$key] ?? '')));
    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException("El campo {$key} no puede superar {$max} caracteres.");
    }
    return $value;
}

if (!can_access_any(['gestionar_solicitudes', 'gestionar_areas'])) {
    contact_respond(['success' => false, 'error' => 'No tienes permiso para gestionar contactos.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    contact_respond(['success' => false, 'error' => 'Método no permitido.'], 405);
}
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    contact_respond(['success' => false, 'error' => 'Solicitud inválida.'], 400);
}
if (empty($_SESSION['visits_csrf']) || !hash_equals((string) $_SESSION['visits_csrf'], (string) ($payload['csrf'] ?? ''))) {
    contact_respond(['success' => false, 'error' => 'La sesión venció. Actualiza la página.'], 419);
}

$action = (string) ($payload['action'] ?? '');
if (!in_array($action, ['save', 'delete'], true)) {
    contact_respond(['success' => false, 'error' => 'Acción no válida.'], 422);
}

try {
    $conn = Conexion::conectar();
    if (!visitas_contacts_available($conn)) {
        throw new RuntimeException('Falta aplicar la migración 41_visit_contacts.sql para guardar contactos.');
    }
    $actor = mb_substr((string) ($_SESSION['nombre'] ?? 'Usuario'), 0, 150);
    $contactId = (int) ($payload['contact_id'] ?? 0);

    if ($action === 'delete') {
        if ($contactId <= 0) {
            throw new InvalidArgumentException('Indica el contacto que deseas quitar.');
        }
        $stmt = $conn->prepare('UPDATE contactos_visita SET estado = 0, actualizado_por = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id_contacto = ? AND estado = 1');
        $stmt->execute([$actor, $contactId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('El contacto ya no existe.');
        }
        contact_respond(['success' => true, 'message' => 'Contacto quitado.']);
    }

    $type = (string) ($payload['type'] ?? '');
    if (!in_array($type, ['interno', 'externo'], true)) {
        throw new InvalidArgumentException('Indica si el contacto es interno o externo.');
    }
    $name = contact_text($payload, 'name', 150);
    $email = mb_strtolower(contact_text($payload, 'email', 150));
    $phone = contact_text($payload, 'phone', 30);
    $institution = contact_text($payload, 'institution', 200);
    $role = contact_text($payload, 'role', 120);
    $notes = contact_text($payload, 'notes', 500);
    $areaId = (int) ($payload['area_id'] ?? 0);
    $notify = !empty($payload['notify']) ? 1 : 0;

    if (mb_strlen($name) < 2) {
        throw new InvalidArgumentException('Escribe el nombre del contacto.');
    }
    if ($email === '' && $phone === '') {
        throw new InvalidArgumentException('Indica al menos un correo o un teléfono.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo electrónico no es válido.');
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
        throw new InvalidArgumentException('El teléfono solo puede tener números, espacios, +, - y paréntesis (mínimo 7).');
    }
    if ($type === 'interno') {
        if ($email === '') {
            throw new InvalidArgumentException('Los contactos internos necesitan correo para recibir notificaciones.');
        }
        $stmt = $conn->prepare('SELECT COUNT(*) FROM areas_interes WHERE id_area = ?');
        $stmt->execute([$areaId]);
        if ($areaId <= 0 || (int) $stmt->fetchColumn() === 0) {
            throw new InvalidArgumentException('Selecciona el área del contacto.');
        }
        $institution = '';
    } else {
        $areaId = 0;
        $notify = 0;
    }

    // Evita duplicados con un mensaje claro (el índice único es la garantía final).
    if ($email !== '') {
        $stmt = $conn->prepare(
            'SELECT id_contacto FROM contactos_visita
             WHERE tipo = ? AND estado = 1 AND lower(correo) = ? AND COALESCE(id_area, 0) = ? AND id_contacto <> ?'
        );
        $stmt->execute([$type, $email, $areaId, $contactId]);
        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException($type === 'interno'
                ? 'Ese correo ya está registrado como contacto de esta área.'
                : 'Ese correo ya está en el directorio. Edita el contacto existente.');
        }
    }

    $values = [
        $name, $email ?: null, $phone ?: null, $institution ?: null, $role ?: null,
        $areaId ?: null, $notify, $notes ?: null,
    ];
    if ($contactId > 0) {
        $stmt = $conn->prepare(
            'UPDATE contactos_visita SET nombre = ?, correo = ?, telefono = ?, institucion = ?, cargo = ?,
                    id_area = ?, recibe_notificaciones = ?, notas = ?, actualizado_por = ?, actualizado_en = CURRENT_TIMESTAMP
             WHERE id_contacto = ? AND tipo = ? AND estado = 1'
        );
        $stmt->execute([...$values, $actor, $contactId, $type]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('El contacto ya no existe.');
        }
        contact_respond(['success' => true, 'contact_id' => $contactId, 'message' => 'Contacto actualizado.']);
    }

    $sql = 'INSERT INTO contactos_visita (nombre, correo, telefono, institucion, cargo, id_area, recibe_notificaciones, notas, tipo, creado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    if (Conexion::driver() === 'pgsql') {
        $stmt = $conn->prepare($sql . ' RETURNING id_contacto');
        $stmt->execute([...$values, $type, $actor]);
        $newId = (int) $stmt->fetchColumn();
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute([...$values, $type, $actor]);
        $newId = (int) $conn->lastInsertId();
    }
    contact_respond(['success' => true, 'contact_id' => $newId, 'message' => 'Contacto agregado.']);
} catch (InvalidArgumentException | RuntimeException $error) {
    contact_respond(['success' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[visitas contactos] ' . $error->getMessage());
    contact_respond(['success' => false, 'error' => 'No se pudo guardar el contacto. Inténtalo de nuevo.'], 500);
}
