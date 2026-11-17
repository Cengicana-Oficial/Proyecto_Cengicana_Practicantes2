<?php
function visitas_public_url(string $page): string
{
    // VISITS_PUBLIC_URL acepta el origen, la carpeta del módulo o un formulario.
    $base = rtrim((string) Conexion::setting('VISITS_PUBLIC_URL', ''), '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $base = preg_replace('~/(?:cengisalas|demo-salas)(?:/modules/visitas(?:/public(?:/[^/]+\.php)?)?)?$~', '', $base);
    $base = preg_replace('~/visitas(?:/public(?:/[^/]+\.php)?)?$~', '', $base);
    return $base . '/visitas/public/' . $page;
}
