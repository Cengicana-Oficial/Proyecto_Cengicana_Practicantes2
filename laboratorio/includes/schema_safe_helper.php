<?php

if (!function_exists('lab_ensure_schema_safe')) {
    function lab_ensure_schema_safe(callable $fn, string $contexto): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            error_log("[laboratorio][schema:{$contexto}] " . $e->getMessage());
            http_response_code(500);
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error de sistema</title></head>'
                . '<body style="font-family:sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem;">'
                . '<h1>No se pudo preparar el modulo de laboratorio</h1>'
                . '<p>Ocurrio un problema al verificar la base de datos. Se registro el detalle para el equipo tecnico; intenta de nuevo en unos minutos.</p>'
                . '</body></html>';
            exit;
        }
    }
}
