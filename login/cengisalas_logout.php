<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
cengi_session_start();
cengi_session_destroy();
header('Cache-Control: no-store, private');
header('Location: login.php', true, 303);
exit;
