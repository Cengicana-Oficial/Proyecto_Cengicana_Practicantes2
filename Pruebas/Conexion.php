<?php
// Local credentials live in the private Docker mount, never in source code.
$privateDirectory = getenv('TECH_STORAGE_DIR') ?: __DIR__ . '/.docker/private';
$configFile = $privateDirectory . '/database.json';
if (!is_file($configFile)) {
    throw new RuntimeException('Falta configurar la base local. Ejecuta Pruebas/.docker/init-db.ps1.');
}
$config = json_decode(file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
if (empty($config['dsn']) || empty($config['user']) || !isset($config['password'])) {
    throw new RuntimeException('La configuración de la base local está incompleta.');
}
try {
    $conn = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_TIMEOUT => 10,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    throw new RuntimeException('No se pudo conectar con la base local de Servicios Técnicos.', 0, $e);
}