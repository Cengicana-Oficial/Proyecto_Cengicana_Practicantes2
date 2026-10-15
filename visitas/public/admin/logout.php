<?php
require_once __DIR__ . '/../../config/session_namespace.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION = [];
session_destroy();
header('Location: ../../../login/cengisalas_logout.php');
exit;
