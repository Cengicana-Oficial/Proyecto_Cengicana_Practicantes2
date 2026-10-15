<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/VisitStorage.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function file_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!can_access('gestionar_solicitudes')) {
    file_response(['success' => false, 'error' => 'No tienes permiso para gestionar solicitudes.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_response(['success' => false, 'error' => 'Método no permitido.'], 405);
}
if (!hash_equals((string) ($_SESSION['visits_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
    file_response(['success' => false, 'error' => 'La sesión venció. Actualiza la página.'], 419);
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
if ($requestId <= 0) {
    file_response(['success' => false, 'error' => 'Solicitud no válida.'], 422);
}

$baseDir = dirname(__DIR__, 2) . '/uploads/gestion_visitas/' . $requestId;
$actor = (string) ($_SESSION['nombre'] ?? $_SESSION['full_name'] ?? $_SESSION['email'] ?? 'Administrador');

try {
    $conn = Conexion::conectar();
    $exists = $conn->prepare('SELECT COUNT(*) FROM solicitudes WHERE id_solicitud = ?');
    $exists->execute([$requestId]);
    if ((int) $exists->fetchColumn() === 0) {
        throw new RuntimeException('La solicitud ya no existe.');
    }

    $conn->beginTransaction();
    if (Conexion::driver() === 'pgsql') {
        $ensure = $conn->prepare('INSERT INTO solicitud_gestion_visita (id_solicitud) VALUES (?) ON CONFLICT (id_solicitud) DO NOTHING');
    } else {
        $ensure = $conn->prepare('INSERT IGNORE INTO solicitud_gestion_visita (id_solicitud) VALUES (?)');
    }
    $ensure->execute([$requestId]);

    if ($action === 'upload_agenda') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona un archivo de agenda válido.');
        }
        if ((int) $file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('La agenda no puede superar 10 MB.');
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
            throw new RuntimeException('La agenda debe ser PDF, Word o Excel.');
        }
        if (Conexion::driver() === 'pgsql') {
            [$relative, $stored] = VisitStorage::storeUploadedFile(
                $file,
                'agendas',
                ['pdf', 'doc', 'docx', 'xls', 'xlsx']
            );
        } else {
            if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
                throw new RuntimeException('No fue posible preparar la carpeta de archivos.');
            }
            $stored = 'agenda_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $target = $baseDir . '/' . $stored;
            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                throw new RuntimeException('No fue posible guardar la agenda.');
            }
            $relative = $requestId . '/' . $stored;
        }
        $old = $conn->prepare('SELECT agenda_ruta FROM solicitud_gestion_visita WHERE id_solicitud = ?');
        $old->execute([$requestId]);
        $oldPath = (string) $old->fetchColumn();
        $update = $conn->prepare('UPDATE solicitud_gestion_visita SET agenda_nombre = ?, agenda_ruta = ?, updated_at = CURRENT_TIMESTAMP WHERE id_solicitud = ?');
        $update->execute([(string) $file['name'], $relative, $requestId]);
        $event = $conn->prepare('INSERT INTO solicitud_eventos_visita (id_solicitud, tipo, descripcion, actor) VALUES (?, ?, ?, ?)');
        $event->execute([$requestId, 'agenda', 'Agenda cargada: ' . (string) $file['name'], $actor]);
        if ($oldPath !== '') {
            if (Conexion::driver() === 'pgsql') {
                VisitStorage::deleteObject($oldPath);
            } else {
                $oldFile = dirname(__DIR__, 2) . '/uploads/gestion_visitas/' . str_replace(['..', '\\'], '', $oldPath);
                if (is_file($oldFile)) @unlink($oldFile);
            }
        }
    } elseif ($action === 'delete_agenda') {
        $old = $conn->prepare('SELECT agenda_ruta FROM solicitud_gestion_visita WHERE id_solicitud = ?');
        $old->execute([$requestId]);
        $oldPath = (string) $old->fetchColumn();
        $update = $conn->prepare('UPDATE solicitud_gestion_visita SET agenda_nombre = NULL, agenda_ruta = NULL, updated_at = CURRENT_TIMESTAMP WHERE id_solicitud = ?');
        $update->execute([$requestId]);
        if ($oldPath !== '') {
            if (Conexion::driver() === 'pgsql') {
                VisitStorage::deleteObject($oldPath);
            } else {
                $oldFile = dirname(__DIR__, 2) . '/uploads/gestion_visitas/' . str_replace(['..', '\\'], '', $oldPath);
                if (is_file($oldFile)) @unlink($oldFile);
            }
        }
    } elseif ($action === 'upload_photos') {
        $files = $_FILES['files'] ?? null;
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            throw new RuntimeException('Selecciona al menos una fotografía.');
        }
        $insert = $conn->prepare('INSERT INTO solicitud_fotos_visita (id_solicitud, nombre_archivo, ruta_archivo) VALUES (?, ?, ?)');
        $saved = 0;
        foreach ($files['name'] as $index => $name) {
            if ((int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            if ((int) ($files['size'][$index] ?? 0) > 8 * 1024 * 1024) continue;
            $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            if (Conexion::driver() === 'pgsql') {
                $singleFile = [
                    'name' => $name,
                    'type' => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index],
                    'error' => $files['error'][$index],
                    'size' => $files['size'][$index],
                ];
                try {
                    [$storedPath] = VisitStorage::storeUploadedFile($singleFile, 'fotos', ['jpg', 'jpeg', 'png', 'webp']);
                } catch (Throwable $fileError) {
                    continue;
                }
            } else {
                if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
                    throw new RuntimeException('No fue posible preparar la carpeta de archivos.');
                }
                $stored = 'foto_' . bin2hex(random_bytes(8)) . '.' . $extension;
                if (!move_uploaded_file((string) $files['tmp_name'][$index], $baseDir . '/' . $stored)) continue;
                $storedPath = $requestId . '/' . $stored;
            }
            $insert->execute([$requestId, (string) $name, $storedPath]);
            $saved++;
        }
        if ($saved === 0) {
            throw new RuntimeException('No se guardó ninguna imagen válida (JPG, PNG o WEBP, máximo 8 MB).');
        }
        $event = $conn->prepare('INSERT INTO solicitud_eventos_visita (id_solicitud, tipo, descripcion, actor) VALUES (?, ?, ?, ?)');
        $event->execute([$requestId, 'fotos', $saved . ($saved === 1 ? ' fotografía agregada.' : ' fotografías agregadas.'), $actor]);
    } elseif ($action === 'delete_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $find = $conn->prepare('SELECT ruta_archivo FROM solicitud_fotos_visita WHERE id_foto = ? AND id_solicitud = ?');
        $find->execute([$photoId, $requestId]);
        $path = (string) $find->fetchColumn();
        if ($path === '') throw new RuntimeException('La fotografía ya no existe.');
        $delete = $conn->prepare('DELETE FROM solicitud_fotos_visita WHERE id_foto = ? AND id_solicitud = ?');
        $delete->execute([$photoId, $requestId]);
        if (Conexion::driver() === 'pgsql') {
            VisitStorage::deleteObject($path);
        } else {
            $storedFile = dirname(__DIR__, 2) . '/uploads/gestion_visitas/' . str_replace(['..', '\\'], '', $path);
            if (is_file($storedFile)) @unlink($storedFile);
        }
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }

    $conn->commit();
    file_response(['success' => true]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) $conn->rollBack();
    file_response(['success' => false, 'error' => $error->getMessage()], 422);
}
