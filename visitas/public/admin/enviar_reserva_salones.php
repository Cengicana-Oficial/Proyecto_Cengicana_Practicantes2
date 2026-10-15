<?php
require_once("../../config/auth.php");
require_login();

if (!can_access('gestionar_reserva_salones')) {
    die('No tienes permiso para gestionar reservacion de salones.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard_unificado.php?modulo=solicitudes");
    exit;
}

$idSolicitud = (int) ($_POST['id_solicitud'] ?? 0);
$webhookUrl = 'https://default8919034889254419a56a17d218a7c8.0c.environment.api.powerplatform.com:443/powerautomate/automations/direct/cu/09/workflows/bc2d6a1080c74d83a429a5af4d5ef1de/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=H8mgjMi154As1P6BFP2v3kluPjzlW8GMkjpWgQAjlCQ';

if ($idSolicitud <= 0) {
    die('Solicitud no valida.');
}

function valor_form($key)
{
    return trim((string) ($_POST[$key] ?? ''));
}

$payloadData = [
    'id_solicitud' => $idSolicitud,
    'fecha_actividad' => valor_form('rab4170e84bcd4827bd88ada6d4f7ee09'),
    'organizacion' => valor_form('rfd0749f80ff0409a82c14968b7997610'),
    'descripcion' => valor_form('rca7162a3d5764f5781730517d2dff796'),
    'participantes' => (int) valor_form('r857e31b356db48e29a1f5b48a2446fac'),
    'hora_inicio' => valor_form('re115e3255dd7463fbdd2c79eb9873a77'),
    'hora_final' => valor_form('r7359bf7da9aa4917b5e426ba515f11da'),
    'responsable' => valor_form('r3370bfe0775f46a0b3be69f7568f15a4'),
    'correo' => valor_form('r507443a331d24efcb6bdda063311b81b'),
    'telefono' => valor_form('r45b9886cc1aa4042b0b3bb53d0792b68'),
    'nivel_academico' => valor_form('re212c8046ad24e53b120c0691b368572'),
];


$payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$envioExitoso = false;
$detalleEnvio = 'No fue posible confirmar el envio.';
$respuestaHttp = '';
$codigoHttp = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0',
        ],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $respuestaHttp = (string) curl_exec($ch);
    $codigoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        $detalleEnvio = 'Error al intentar enviar al flujo: ' . $curlError;
    } else {
        $envioExitoso = $codigoHttp >= 200 && $codigoHttp < 400;
        $detalleEnvio = $envioExitoso
            ? 'Power Automate respondio al intento de envio.'
            : 'Power Automate rechazo el intento de envio.';
    }
} else {
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0',
            ]),
            'content' => $payload,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ];

    $context = stream_context_create($options);
    $respuestaHttp = (string) @file_get_contents($webhookUrl, false, $context);

    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $codigoHttp = (int) $match[1];
    }

    $envioExitoso = $codigoHttp >= 200 && $codigoHttp < 400;
    $detalleEnvio = $envioExitoso
        ? 'Power Automate respondio al intento de envio.'
        : 'No se pudo confirmar el envio hacia Power Automate.';
}

$respuestaNormalizada = strtolower($respuestaHttp);

if ($envioExitoso && (
    strpos($respuestaNormalizada, 'succeeded') !== false ||
    strpos($respuestaNormalizada, 'success') !== false ||
    strpos($respuestaNormalizada, 'ok') !== false ||
    trim($respuestaNormalizada) === ''
)) {
    $detalleEnvio = 'Su solicitud fue enviada espere a que sea aprobada ¡Gracias!.';
} elseif ($envioExitoso) {
    $detalleEnvio .= ' No fue posible validar mas detalle de la respuesta del flujo.';
}

$debugResumen = substr(trim(strip_tags($respuestaHttp)), 0, 500);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enviando reservacion</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
<style>
body{
    background:#f5f5f5;
    padding:30px;
    font-family: Arial, sans-serif;
}

.panel{
    max-width:760px;
    margin:0 auto;
    box-shadow:0 2px 10px rgba(0,0,0,0.1);
}
</style>
</head>
<body>
<div class="panel panel-primary">
    <div class="panel-heading">
        <h3 class="panel-title">Enviando reservacion de salones</h3>
    </div>
    <div class="panel-body">
        <?php if ($envioExitoso): ?>
            <div class="alert alert-success">
                <strong>Envio exitoso.</strong> <?= htmlspecialchars($detalleEnvio) ?>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <strong>No se pudo completar el envio.</strong> <?= htmlspecialchars($detalleEnvio) ?>
            </div>
        <?php endif; ?>
        <?php if ($debugResumen !== ''): ?>
            <div class="alert alert-info">
                <strong>Respuesta resumida de Microsoft:</strong><br>
                <?= nl2br(htmlspecialchars($debugResumen)) ?>
            </div>
        <?php endif; ?>
        <p>
            <a class="btn btn-default" href="dashboard_unificado.php?modulo=solicitudes">Volver al dashboard</a>
        </p>
    </div>
</div>
</body>
</html>
