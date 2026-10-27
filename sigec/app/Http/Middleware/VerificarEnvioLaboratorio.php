<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Protege la API de resultados del modulo Laboratorio:
 * 1. Solo acepta llamadas desde las redes permitidas (por defecto, redes internas).
 * 2. Cada envio va firmado: X-Lab-Firma = "sha256=" . HMAC-SHA256(timestamp + "\n" + cuerpo, secreto).
 * 3. X-Lab-Timestamp no puede tener mas de N segundos de diferencia (evita reenvios).
 * 4. La misma firma no se acepta dos veces dentro de la ventana (anti-repeticion).
 * Todo rechazo queda en la bitacora lab_envios.
 */
class VerificarEnvioLaboratorio
{
    const MAX_BYTES = 2 * 1024 * 1024;

    public function handle(Request $request, Closure $next)
    {
        $cfg = config('laboratorio.api');
        $secreto = (string) ($cfg['secreto'] ?? '');

        if ($secreto === '') {
            return $this->rechazar($request, 503, 'La API de resultados del Laboratorio no esta habilitada en SIGEC.');
        }
        // Lo que entra por el portal publico (modulos-app reenvia /sigec/*) trae cabeceras
        // X-Forwarded-*; el Laboratorio llama directo a http://sigec/ por la red interna.
        // Asi la API no queda alcanzable desde internet aunque el portal sea publico.
        if ($request->headers->has('X-Forwarded-For') || $request->headers->has('X-Forwarded-Host') || $request->headers->has('Forwarded')) {
            return $this->rechazar($request, 403, 'La API solo acepta llamadas internas del modulo Laboratorio.');
        }
        if (! IpUtils::checkIp((string) $request->ip(), $cfg['redes'] ?: ['127.0.0.1/32'])) {
            return $this->rechazar($request, 403, 'Origen no permitido.');
        }
        $cuerpo = $request->getContent();
        if (strlen($cuerpo) > self::MAX_BYTES) {
            return $this->rechazar($request, 413, 'El envio supera el tamano permitido.');
        }

        $ts = (string) $request->header('X-Lab-Timestamp', '');
        $firma = (string) $request->header('X-Lab-Firma', '');
        if (! ctype_digit($ts) || $firma === '') {
            return $this->rechazar($request, 401, 'Falta la firma del envio (X-Lab-Timestamp y X-Lab-Firma).');
        }
        $ventana = max(30, (int) ($cfg['ventana'] ?? 300));
        if (abs(time() - (int) $ts) > $ventana) {
            return $this->rechazar($request, 401, 'El envio esta vencido o el reloj del servidor no coincide.');
        }
        $esperada = 'sha256=' . hash_hmac('sha256', $ts . "\n" . $cuerpo, $secreto);
        if (! hash_equals($esperada, $firma)) {
            return $this->rechazar($request, 401, 'Firma no valida.');
        }
        // Anti-repeticion: un mismo envio firmado solo se procesa una vez.
        if (! Cache::add('lab_api_firma:' . hash('sha256', $firma), 1, $ventana * 2)) {
            return $this->rechazar($request, 409, 'Este envio ya fue recibido.');
        }

        return $next($request);
    }

    protected function rechazar(Request $request, int $http, string $motivo)
    {
        try {
            $datos = json_decode($request->getContent(), true);
            DB::table('lab_envios')->insert([
                'ip' => $request->ip(),
                'informe' => is_array($datos) ? mb_substr((string) ($datos['informe']['codigo'] ?? ''), 0, 60) ?: null : null,
                'respuesta_http' => $http,
                'error' => $motivo,
                'recibido_en' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => false, 'error' => $motivo], $http);
    }
}
