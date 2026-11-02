<?php
/**
 * Compatibilidad: el alta de ingenios se hace en ver_ingenios.php (POST accion=crear),
 * que valida nombre y duplicados (sin importar mayúsculas ni tildes).
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ver_ingenios.php');
    exit;
}
$_POST['accion'] = 'crear';
unset($_POST['id']);
require __DIR__ . '/ver_ingenios.php';
