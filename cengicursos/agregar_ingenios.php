<?php
/** Compatibilidad: el alta se hace en la ventana "Nueva institución" de ver_ingenios.php. */
require_once __DIR__ . '/revisar_permisos.php';
cengi_require_gestionar_ingenios();
header('Location: ver_ingenios.php?nuevo=1');
exit;
