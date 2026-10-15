<?php
require_once "conexion.php";
$conn = Conexion::conectar();
$sql = Conexion::driver() === 'pgsql'
    ? "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name"
    : "SHOW TABLES";
$tables = $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN);
echo "Tablas encontradas: " . implode(", ", $tables) . "\n";
