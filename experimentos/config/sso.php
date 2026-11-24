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

    /*
    |--------------------------------------------------------------------------
    | Roles de Experimentos en el portal
    |--------------------------------------------------------------------------
    |
    | Los usuarios se crean UNA sola vez en el portal (login/usuarios) y ahi se
    | les da el modulo Experimentos con uno de estos roles; SIGEC no crea
    | usuarios, solo asigna o cambia el rol (App\Services\RolesPortal). Cada
    | rol del portal (usuarios_menu.roles, ligado al modulo con rol_modulo)
    | equivale a un rol spatie de SIGEC, que es el que define los permisos
    | (database/seeders/RolePermissionSeeder.php). El SSO usa este mismo mapa
    | por nombre. Superadmin es el rol generico del portal y ve todo.
    |
    */
    'roles_portal' => [
        'Encargado del programa' => [
            'rol' => 'encargado',
            'descripcion' => 'Gestiona proyectos y ensayos de sus programas, formularios, muestras e integrantes.',
            'color' => '#FF6B00',
        ],
        'Encargado de proyecto o área' => [
            'rol' => 'experto',
            'descripcion' => 'Conduce los ensayos de sus proyectos: evaluaciones, análisis, reportes y muestras.',
            'color' => '#7B2FBE',
        ],
        'Técnico de proyecto o área' => [
            'rol' => 'investigador',
            'descripcion' => 'Registra evaluaciones, archivos, bitácora y muestras de los ensayos asignados.',
            'color' => '#5e9b1d',
        ],
        'Muestreador' => [
            'rol' => 'muestreador',
            'descripcion' => 'Llena en campo los formularios que se le asignan.',
            'color' => '#00897B',
        ],
        'Usuario de ingenio' => [
            'rol' => 'ingenio',
            'descripcion' => 'Consulta los ensayos y resultados de laboratorio de su ingenio.',
            'color' => '#c4a000',
        ],
    ],

    // A donde va "Cerrar sesion" de SIGEC: el logout del portal, que cierra su sesion y
    // muestra login/login.php. Ruta del mismo host (sin dominio).
    'logout_url' => env('SIGEC_LOGOUT_URL', '/Pruebas/public/admin/logout.php'),

    // A donde va quien entra a SIGEC sin sesion (expirada o cerrada): el login del portal.
    'login_url' => env('SIGEC_LOGIN_URL', '/login/login.php'),

    // Nombre del modulo en usuarios_menu.modulos.
    'modulo_portal' => 'Experimentos',

    /*
    |--------------------------------------------------------------------------
    | Opciones del menu segun Roles y permisos del portal
    |--------------------------------------------------------------------------
    |
    | Cada opcion del menu lateral (y su pagina) se controla con un permiso del
    | portal "sigec.menu.<clave>" que se asigna al rol del usuario EN
    | Experimentos desde login/roles/roles.php. Gate: "menu.<clave>" (ver
    | AuthServiceProvider). Administrador/Superadmin ven todo.
    |  - roles: roles spatie que reciben el permiso por defecto, una sola vez
    |    (migracion 2026_11_24_120000_sembrar_permisos_menu_portal); equivale
    |    a lo que cada rol veia antes del cambio.
    |  - respaldo: abilities spatie que se usan solo si el portal no responde.
    | Las etiquetas visibles en el portal estan en login/config/permisos_roles.php
    | (permisos_sigec_catalogo); mantener ambas listas sincronizadas.
    |
    */
    'menu' => [
        'proyectos' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'ensayos' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'clima' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'variables' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'evaluaciones' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'muestras' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['create_muestra', 'view_lab']],
        'imagenes_geo' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'archivos' => ['roles' => ['encargado', 'experto', 'investigador', 'ingenio'], 'respaldo' => ['menu_general']],
        'formularios' => ['roles' => ['encargado', 'experto', 'muestreador'], 'respaldo' => ['manage_formularios', 'ver_formulario_asignado']],
        'reportes' => ['roles' => ['encargado', 'experto'], 'respaldo' => ['view_reports_menu']],
        'importacion' => ['roles' => ['encargado', 'experto'], 'respaldo' => ['run_analysis']],
    ],

];
