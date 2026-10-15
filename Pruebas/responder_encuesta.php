<?php
require __DIR__ . '/bootstrap.php';
require_page_user();
header('Location: index.php?view=respond&id=' . rawurlencode((string)($_GET['id'] ?? '')));
exit;
