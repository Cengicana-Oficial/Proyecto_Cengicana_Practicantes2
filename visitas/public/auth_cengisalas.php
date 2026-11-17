<?php
// Compatibilidad: la autenticación ahora se realiza con la sesión del portal.
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode(['success' => false, 'error' => 'Ingresa a Visitas desde el menú de Módulos.', 'redirect' => '/login/sso_visitas.php']);
