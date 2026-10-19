<?php
/** Compatibilidad: el cambio de nombre se hace en la ventana "Editar" de ver_ingenios.php. */
require_once __DIR__ . '/revisar_permisos.php';
cengi_require_gestionar_ingenios();
$id = (int) ($_GET['id'] ?? 0);
header('Location: ver_ingenios.php' . ($id > 0 ? '?editar=' . $id . '#ing-' . $id : ''));
exit;
