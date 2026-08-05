<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';

cengi_require_gestionar_ingenios();

$nombre = trim((string) ($_POST['nombre'] ?? ''));

if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 255) {
    header('Location: ver_ingenios.php?' . http_build_query([
        'confirmacion' => 'datos_invalidos',
    ]));
    exit;
}

try {
    $db = conectar();
    $stmt = $db->prepare('
        INSERT INTO ingenios (nombre_ingenios, creado)
        VALUES (?, NOW())
    ');
    $stmt->execute([$nombre]);

    header('Location: ver_ingenios.php?' . http_build_query([
        'confirmacion' => 'creado',
        'nombre' => $nombre,
    ]));
} catch (PDOException $e) {
    $duplicado = (string) $e->getCode() === '23000';
    if (!$duplicado) {
        error_log('No fue posible guardar el ingenio o institución: ' . $e->getMessage());
    }

    header('Location: ver_ingenios.php?' . http_build_query([
        'confirmacion' => $duplicado ? 'duplicado' : 'error',
        'nombre' => $nombre,
    ]));
}

exit;
