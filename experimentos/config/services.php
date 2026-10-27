<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | API agromet del ICC (ver App\Services\IccClima): GET /api/agromet/datosdiarios
    | con fecha_inicial / fecha_final devuelve los datos diarios de TODAS las
    | estaciones; SIGEC los guarda y filtra por estacion y fechas.
    | ICC_API_URL = servidor (p. ej. https://servidor.icc.org.gt).
    | Autenticacion: bearer | header | basic | query | none.
    */
    'icc' => [
        'url' => env('ICC_API_URL'),
        'auth' => env('ICC_API_AUTH', 'bearer'),
        'token' => env('ICC_API_TOKEN'),
        'header' => env('ICC_API_HEADER', 'X-API-Key'),
        'query_param' => env('ICC_API_QUERY_PARAM', 'api_key'),
        'usuario' => env('ICC_API_USUARIO'),
        'clave' => env('ICC_API_CLAVE'),
        'ruta_datos' => env('ICC_API_RUTA_DATOS', '/api/agromet/datosdiarios?fecha_inicial={desde}&fecha_final={hasta}'),
        'formato_fecha' => env('ICC_API_FORMATO_FECHA', 'Y-m-d'),
        // Unidad de "vientoavg" (verificado con la ETo del ejemplo: km/h).
        'viento_unidad' => env('ICC_API_VIENTO_UNIDAD', 'kmh'),
        // Dias por solicitud (cada dia trae todas las estaciones): rangos mayores se parten.
        'dias_por_consulta' => (int) env('ICC_API_DIAS_POR_CONSULTA', 31),
        'timeout' => (int) env('ICC_API_TIMEOUT', 30),
        'verificar_ssl' => filter_var(env('ICC_API_VERIFICAR_SSL', true), FILTER_VALIDATE_BOOLEAN),
        // Minutos antes de volver a consultar los ultimos dias (datos aun incompletos).
        'refresco_min' => (int) env('ICC_API_REFRESCO_MIN', 60),
    ],

];
