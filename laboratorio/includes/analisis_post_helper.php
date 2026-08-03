<?php

if (!function_exists('lab_post_value')) {
    function lab_post_value(string $name, int $index = 0, $default = '')
    {
        $value = $_POST[$name] ?? $default;

        if (is_array($value)) {
            return array_key_exists($index, $value) ? $value[$index] : $default;
        }

        return $value;
    }
}

if (!function_exists('lab_post_float')) {
    function lab_post_float(string $name, int $index = 0, float $default = 0.0): float
    {
        $value = lab_post_value($name, $index, $default);
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('lab_post_string')) {
    function lab_post_string(string $name, int $index = 0, string $default = ''): string
    {
        return trim((string) lab_post_value($name, $index, $default));
    }
}

if (!function_exists('lab_post_row_count')) {
    function lab_post_row_count(array $fieldNames): int
    {
        $count = 1;

        foreach ($fieldNames as $name) {
            $value = $_POST[$name] ?? null;
            if (is_array($value)) {
                $count = max($count, count($value));
            }
        }

        return $count;
    }
}

if (!function_exists('lab_post_row_has_data')) {
    function lab_post_row_has_data(array $fieldNames, int $index): bool
    {
        foreach ($fieldNames as $name) {
            $postedValue = $_POST[$name] ?? null;

            if (is_array($postedValue)) {
                $value = array_key_exists($index, $postedValue) ? $postedValue[$index] : '';
            } elseif ($index === 0) {
                $value = $postedValue ?? '';
            } else {
                continue;
            }

            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('lab_resultado_multiple')) {
    function lab_resultado_multiple(array $resultados, string $nombre = 'registro'): array
    {
        if (!$resultados) {
            return [
                'exito' => false,
                'mensaje' => 'No se recibieron filas para guardar.',
            ];
        }

        $guardados = 0;
        $errores = [];

        foreach ($resultados as $index => $resultado) {
            if (!empty($resultado['exito'])) {
                $guardados++;
                continue;
            }

            $errores[] = 'Fila ' . ($index + 1) . ': ' . ($resultado['mensaje'] ?? 'No se pudo guardar.');
        }

        if ($errores) {
            return [
                'exito' => false,
                'mensaje' => 'Se guardaron ' . $guardados . ' fila(s), pero hubo errores. ' . implode(' ', $errores),
            ];
        }

        return [
            'exito' => true,
            'mensaje' => ucfirst($nombre) . ': ' . $guardados . ' fila(s) guardada(s) correctamente.',
        ];
    }
}

if (!function_exists('lab_analysis_flash_key')) {
    function lab_analysis_flash_key(): string
    {
        return 'lab_analysis_flash_result';
    }
}

if (!function_exists('lab_analysis_flash_set')) {
    function lab_analysis_flash_set(array $resultado): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[lab_analysis_flash_key()] = $resultado;
    }
}

if (!function_exists('lab_analysis_take_flash')) {
    function lab_analysis_take_flash(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $key = lab_analysis_flash_key();
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return null;
        }

        $resultado = $_SESSION[$key];
        unset($_SESSION[$key]);

        return $resultado;
    }
}

if (!function_exists('lab_analysis_redirect_after_success')) {
    function lab_analysis_redirect_after_success(?array $resultado): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($resultado['exito'])) {
            return;
        }

        lab_analysis_flash_set($resultado);
        $target = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($target === '') {
            $target = (string) ($_SERVER['PHP_SELF'] ?? '');
        }

        header('Location: ' . $target, true, 303);
        exit;
    }
}
