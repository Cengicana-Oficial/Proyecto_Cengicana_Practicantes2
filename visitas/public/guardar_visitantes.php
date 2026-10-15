<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/VisitGuestForm.php';
require_once __DIR__ . '/../config/VisitStorage.php';

$token = trim((string) ($_POST['token'] ?? ''));
$redirect = static function (string $token, string $message = '', bool $success = false): never {
    $params = ['token' => $token];
    if ($message !== '') {
        $params[$success ? 'ok' : 'error'] = $message;
    }
    header('Location: registro_visitantes.php?' . http_build_query($params));
    exit;
};

if (!preg_match('/^[a-f0-9]{48}$/i', $token)) {
    http_response_code(400);
    exit('Enlace de formulario inválido.');
}

try {
    $conn = Conexion::conectar();
    $request = VisitGuestForm::find($conn, $token);
    if (!$request) {
        http_response_code(404);
        exit('El formulario no existe o ya venció.');
    }

    if (!empty($request['fecha_completado'])) {
        $redirect($token, 'Los visitantes ya fueron registrados. El formulario está cerrado.');
    }

    $status = strtolower(trim((string) ($request['nombre_estado'] ?? '')));
    if (in_array($status, ['rechazado', 'cancelado', 'cancelada'], true)) {
        $redirect($token, 'La solicitud ya no está activa y no admite cambios.');
    }

    $max = max(1, (int) $request['cantidad_visitantes']);
    $posted = $_POST['visitantes'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    $names = [];
    foreach ($posted as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    if (!$names) {
        $redirect($token, 'Ingresa al menos un nombre de visitante.');
    }
    if (count($names) > $max) {
        $redirect($token, 'El listado no puede superar las ' . $max . ' personas solicitadas.');
    }

    $uploadedPath = null;
    $uploadedName = null;
    $file = $_FILES['listado_visitantes'] ?? null;
    if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$uploadedPath, $uploadedName] = VisitStorage::storeUploadedFile($file, 'visitantes', ['pdf', 'xlsx', 'xls']);
    }

    $conn->beginTransaction();
    $delete = $conn->prepare('delete from solicitud_visitantes where id_solicitud = ?');
    $delete->execute([(int) $request['id_solicitud']]);
    $insert = $conn->prepare(
        'insert into solicitud_visitantes (id_solicitud, nombre_completo, orden) values (?, ?, ?)'
    );
    foreach ($names as $index => $name) {
        $insert->execute([(int) $request['id_solicitud'], $name, $index + 1]);
    }

    if ($uploadedPath !== null) {
        $update = $conn->prepare(
            'update solicitud_formularios_visitantes
             set listado_ruta = ?, listado_nombre = ?, fecha_completado = current_timestamp
             where id_solicitud = ?'
        );
        $update->execute([$uploadedPath, $uploadedName, (int) $request['id_solicitud']]);
    } else {
        $update = $conn->prepare(
            'update solicitud_formularios_visitantes
             set fecha_completado = current_timestamp
             where id_solicitud = ?'
        );
        $update->execute([(int) $request['id_solicitud']]);
    }
    $conn->commit();
    $redirect($token, 'El listado de visitantes se guardó correctamente.', true);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Error al guardar visitantes: ' . $e->getMessage());
    $redirect($token, 'No fue posible guardar el listado. Inténtalo nuevamente.');
}
