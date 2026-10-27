<?php
/**
 * Ejemplo de referencia para el modulo Laboratorio (PHP puro + cURL, sin framework):
 *  - sigec_consultar_muestras(): al escanear la etiqueta QR, trae de SIGEC los datos
 *    de la muestra (ensayo, parcela, tratamiento, repeticion, fecha...) para guardarlos
 *    en la recepcion del Laboratorio.
 *  - sigec_enviar_resultados(): envia a SIGEC el avance o los resultados firmados.
 *
 * Uso desde linea de comandos (prueba):
 *   LAB_API_SECRET=... php enviar_resultados_laboratorio.php consultar SIGEC-01-SU-001-26 [SIGEC-...]
 *   LAB_API_SECRET=... php enviar_resultados_laboratorio.php enviar envio.json
 * SIGEC_API_BASE cambia la direccion (por defecto http://sigec/api/laboratorio).
 */

/**
 * Llamada firmada a la API de SIGEC: X-Lab-Firma = "sha256=" . HMAC-SHA256(timestamp + "\n" + cuerpo, secreto).
 *
 * @return array ['http' => int, 'respuesta' => array|null, 'error' => string|null]
 */
function sigec_api(string $url, array $datos, string $secreto): array
{
    $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();
    // Se firma el cuerpo EXACTO que se envia.
    $firma = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $cuerpo, $secreto);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Lab-Timestamp: ' . $timestamp,
            'X-Lab-Firma: ' . $firma,
        ],
    ]);
    $respuesta = curl_exec($ch);
    $error = $respuesta === false ? curl_error($ch) : null;
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $http, 'respuesta' => $respuesta !== false ? json_decode($respuesta, true) : null, 'error' => $error];
}

/**
 * Datos de una o varias muestras de SIGEC por el ID leido en el QR (hasta 200 por llamada).
 * Ejemplo en la recepcion: $r = sigec_consultar_muestras([$lecturaQr], $base, $secreto);
 * y con $r['respuesta']['muestras'][0]['identificacion'] llenar muestra_identificacion.
 */
function sigec_consultar_muestras(array $ids, string $base, string $secreto): array
{
    return sigec_api(rtrim($base, '/') . '/muestras', ['ids' => array_values($ids)], $secreto);
}

/**
 * @param array $envio ['informe' => [...], 'muestras' => [...]] (ver docs/api-laboratorio-resultados.md)
 */
function sigec_enviar_resultados(array $envio, string $base, string $secreto): array
{
    return sigec_api(rtrim($base, '/') . '/resultados', $envio, $secreto);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $accion = $argv[1] ?? '';
    $secreto = getenv('LAB_API_SECRET') ?: '';
    $base = getenv('SIGEC_API_BASE') ?: 'http://sigec/api/laboratorio';
    if ($secreto === '' || ! in_array($accion, ['consultar', 'enviar'], true) || ! isset($argv[2])) {
        fwrite(STDERR, "Uso: LAB_API_SECRET=... php enviar_resultados_laboratorio.php consultar SIGEC-... [SIGEC-...]\n"
            . "     LAB_API_SECRET=... php enviar_resultados_laboratorio.php enviar envio.json\n");
        exit(2);
    }
    $r = $accion === 'consultar'
        ? sigec_consultar_muestras(array_slice($argv, 2), $base, $secreto)
        : sigec_enviar_resultados(json_decode(file_get_contents($argv[2]), true), $base, $secreto);
    echo 'HTTP ', $r['http'], PHP_EOL, json_encode($r['respuesta'] ?? $r['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($r['http'] === 200 ? 0 : 1);
}
