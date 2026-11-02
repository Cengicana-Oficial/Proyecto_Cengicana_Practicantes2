<?php
/**
 * La corrección de una solicitud directa se hace en la ventana "Corregir" de solicitudes.php
 * (guarda en actualizar_solicitud.php). Se conserva este archivo para enlaces anteriores.
 */
require_once "revisar_permisos.php";

cengi_require_gestor_solicitudes('solicitudes.php');

$id = (int) ($_GET['id'] ?? 0);
header('Location: solicitudes.php' . ($id > 0 ? '?editar=' . $id . '#sol-' . $id : ''));
exit;
