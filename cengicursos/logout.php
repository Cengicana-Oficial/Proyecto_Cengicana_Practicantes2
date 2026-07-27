<?php
require_once __DIR__ . '/../login/config/session.php';
cengi_session_destroy();
header("Location: ../login/login.php");
exit;
