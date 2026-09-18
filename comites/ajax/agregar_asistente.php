<?php
declare(strict_types=1);

// Endpoint AJAX usado desde el modal de "Registrar reunion" para agregar un
// asistente nuevo directo a la base de contactos (equivalente a addExtra()
// en el prototipo JSX), sin recargar ni perder el resto del formulario.
// Devuelve JSON: {"ok":true,"contacto":{...}} o {"ok":false,"error":"..."}

session_start();

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function comites_ajax_fail(string $mensaje, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $mensaje]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    comites_ajax_fail('Metodo no permitido.', 405);
}

$user = current_user($menuPdo, $pdo);
if (!$user || !user_has_module_access($user, $menuPdo)) {
    comites_ajax_fail('No autorizado.', 401);
}

if (!can_manage_resource($user, 'reuniones')) {
    comites_ajax_fail('No tienes permiso para agregar asistentes.', 403);
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$cargo = trim((string) ($_POST['cargo'] ?? ''));
$empresa = trim((string) ($_POST['empresa'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$comiteId = trim((string) ($_POST['comite_id'] ?? ''));

if ($nombre === '') {
    comites_ajax_fail('Escribe el nombre del asistente.');
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO contactos (nombre, cargo, empresa, email, estado) VALUES (?, ?, ?, ?, "Activo")'
    );
    $stmt->execute([$nombre, $cargo ?: null, $empresa ?: null, $email ?: null]);
    $contactoId = (int) $pdo->lastInsertId();

    if ($comiteId !== '') {
        $comiteCheck = $pdo->prepare('SELECT COUNT(*) FROM comites WHERE id = ?');
        $comiteCheck->execute([$comiteId]);
        if ((int) $comiteCheck->fetchColumn() > 0) {
            $pdo->prepare('INSERT IGNORE INTO contacto_comite (contacto_id, comite_id) VALUES (?, ?)')
                ->execute([$contactoId, $comiteId]);
        }
    }

    echo json_encode([
        'ok' => true,
        'contacto' => [
            'id' => $contactoId,
            'nombre' => $nombre,
            'cargo' => $cargo,
            'comites' => $comiteId !== '' ? [$comiteId] : [],
        ],
    ]);
} catch (Throwable $e) {
    error_log('comites/ajax/agregar_asistente.php: ' . $e->getMessage());
    comites_ajax_fail('No fue posible guardar el asistente.', 500);
}
