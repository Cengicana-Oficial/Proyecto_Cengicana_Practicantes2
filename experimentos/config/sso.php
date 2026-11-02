<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Puente de SSO con el compendio CENGICAÑA (login/)
    |--------------------------------------------------------------------------
    |
    | SIGEC no comparte sesion PHP nativa con login/cengicursos/laboratorio
    | (corre en un contenedor y dominio/puerto distintos), asi que el acceso
    | desde el menu principal (login/Menu.php -> login/sso_sigec.php) llega
    | via un token HMAC-SHA256 de corta duracion en vez de cookie compartida.
    | Ver App\Http\Controllers\Auth\SsoController.
    |
    */

    // Debe coincidir byte a byte con SIGEC_SSO_SECRET en deploy/env/login.env.
    'secret' => env('SIGEC_SSO_SECRET'),

    // Ventana de validez del token, en segundos, desde que login/sso_sigec.php
    // lo firma. Corta a proposito: no hay nonce/lista de un solo uso, la
    // ventana corta es la unica proteccion contra replay (trafico interno,
    // riesgo aceptado — ver comentario en SsoController::entrar()).
    'ttl' => 60,

    // Mapeo rol_id (tabla usuarios_menu.roles) -> nombre de rol spatie de
    // SIGEC (ver database/seeders/RolePermissionSeeder.php). Mantener
    // sincronizado a mano: no hay relacion automatica entre ambos catalogos
    // de roles. Solo Superadmin/Administrador del compendio reciben un rol
    // por defecto aqui (administrador, que ademas hace bypass total de
    // permisos via Gate::before en AuthServiceProvider); cualquier otro
    // rol_id entra sin rol spatie asignado y se asigna a mano desde la UI
    // de Usuarios de SIGEC.
    'role_map' => [
        1 => 'administrador', // Superadmin (usuarios_menu.roles)
        2 => 'administrador', // Administrador (usuarios_menu.roles)
    ],

];
