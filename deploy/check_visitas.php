<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/visitas', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/vendor/')) $files[] = $file->getPathname();
}
foreach (['Menu.php','sso_visitas.php','sso_cengisalas.php','visitas_roles.php','config/visitas_access.php','config/cengisalas_access.php','config/cengisalas_supabase.php'] as $file) $files[] = $root . '/login/' . $file;
foreach ($files as $file) {
    exec('php -l ' . escapeshellarg($file), $output, $status);
    if ($status !== 0) { echo implode("
", $output); exit(1); }
    $output = [];
}
echo count($files) . " archivos PHP: sintaxis correcta.
";
