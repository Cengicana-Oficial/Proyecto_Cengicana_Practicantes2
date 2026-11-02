<?php
// «Organizaciones» se unificó con «Ingenios e instituciones» (2026-10): tipo y contacto viven ahora
// en el catálogo de ingenios (ver_ingenios.php). Se conserva esta dirección para enlaces y favoritos.
// La tabla organizaciones queda como respaldo (migrations/20261104_ingenios_unifica_organizaciones.sql).
$q = trim((string) ($_GET['q'] ?? ''));
header('Location: ver_ingenios.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''), true, 302);
exit;
