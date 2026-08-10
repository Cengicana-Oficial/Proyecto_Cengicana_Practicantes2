<?php
/**
 * Guard de acceso para el CRUD de Roles (login/roles/*.php).
 *
 * La gestión de roles permite editar los permisos de cualquier rol del
 * sistema (incluyendo, indirectamente, escalar privilegios), por lo que
 * se mantiene restringida a superadmin únicamente, igual que la condición
 * original que cada página repetía antes de este archivo:
 *   empty($_SESSION['es_superadmin']) || (int) $_SESSION['es_superadmin'] !== 1
 *
 * Debe incluirse después de session_start() y antes de cualquier acción
 * sobre la base de datos.
 */

if (empty($_SESSION['es_superadmin']) || (int) $_SESSION['es_superadmin'] !== 1) {
    die("Acceso restringido");
}
