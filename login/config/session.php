<?php

function cengi_session_start($ttlSeconds = 28800)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $ttlSeconds = (int) $ttlSeconds;

    ini_set('session.gc_maxlifetime', (string) $ttlSeconds);
    ini_set('session.cookie_lifetime', (string) $ttlSeconds);

    session_set_cookie_params([
        'lifetime' => $ttlSeconds,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function cengi_session_destroy()
{
    if (session_status() === PHP_SESSION_NONE) {
        cengi_session_start();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
