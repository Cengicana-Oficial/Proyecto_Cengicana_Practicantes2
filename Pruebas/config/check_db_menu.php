<?php
// Script de instalacion: solo por linea de comandos, nunca por HTTP (AUD-2026-001/I-2).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once "conexion.php";
$conn = Conexion::conectarUsuariosMenu();
$tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tablas en MENU: " . implode(", ", $tables) . "\n";
