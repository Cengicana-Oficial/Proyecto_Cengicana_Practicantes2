<?php
require __DIR__ . '/bootstrap.php';
require_page_user();
header('Location: index.php?view=newservice&id=' . rawurlencode((string)($_GET['id'] ?? '')));
exit;
