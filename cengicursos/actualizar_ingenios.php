<?php
/**
 * Compatibilidad: el cambio de nombre se hace en ver_ingenios.php (POST accion=editar).
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ver_ingenios.php');
    exit;
}
$_POST['accion'] = 'editar';
require __DIR__ . '/ver_ingenios.php';
