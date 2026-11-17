<?php
declare(strict_types=1);
// Enlaces antiguos conservan una sola entrada al módulo independiente.
$module = (string) ($_GET['module'] ?? '');
if (!in_array($module, ['salas', 'visitas'], true)) { http_response_code(404); exit('Módulo desconocido.'); }
header('Location: ' . ($module === 'visitas' ? 'sso_visitas.php' : '../cengisalas/index.html?panel=1'), true, 303);
exit;
