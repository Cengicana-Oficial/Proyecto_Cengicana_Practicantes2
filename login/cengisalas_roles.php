<?php
declare(strict_types=1);
require_once __DIR__ . '/config/session.php';
cengi_session_start();
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}
header('Location: roles/roles.php#cengisalas');
exit;
