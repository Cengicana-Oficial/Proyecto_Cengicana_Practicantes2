<?php
/**
 * Compatibilidad: eliminar ya no se hace con un enlace. Se confirma en ver_ingenios.php
 * (POST accion=eliminar), que solo lo permite si la institución no está en uso.
 */
require_once __DIR__ . '/revisar_permisos.php';
cengi_require_gestionar_ingenios();
header('Location: ver_ingenios.php' . (!empty($_GET['id']) ? '#ing-' . (int) $_GET['id'] : ''));
exit;
