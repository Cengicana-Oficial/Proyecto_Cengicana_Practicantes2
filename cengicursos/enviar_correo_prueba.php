<?php

require_once "revisar_permisos.php";
require_once "correo.php";

$correo = trim($_POST['correo'] ?? '');

if (
    $correo === '' ||
    !filter_var($correo, FILTER_VALIDATE_EMAIL)
) {
    die('Correo electrónico no válido.');
}

try {

    $enviado = cengi_enviar_correo(
        $correo,
        'Usuario de prueba',
        'Prueba de correo CENGICANA',
        '
            <h2>Prueba de correo</h2>

            <p>
                Este correo fue enviado automáticamente
                desde el sistema de CENGICANA.
            </p>

            <p>
                Si estás recibiendo este mensaje,
                Brevo está configurado correctamente.
            </p>
        '
    );

} catch (Throwable $mailError) {

    error_log(
        "Error enviando correo de prueba: " .
        $mailError->getMessage()
    );

    $enviado = false;
}

if ($enviado) {

    header(
        'Location: solicitudes.php?correo=enviado'
    );

} else {

    header(
        'Location: solicitudes.php?correo=error'
    );
}

exit;