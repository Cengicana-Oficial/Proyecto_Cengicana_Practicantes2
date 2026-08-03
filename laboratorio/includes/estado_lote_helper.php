<?php

if (!function_exists('labCalcularEstadoLote')) {
    function labCalcularEstadoLote($analisisRequeridos, $analisisIngresados, $analisisAprobados): array
    {
        $requeridos = max(0, (int) $analisisRequeridos);
        $ingresados = max(0, (int) $analisisIngresados);
        $aprobados = max(0, (int) $analisisAprobados);

        if ($ingresados <= 0) {
            return [
                'codigo' => 'pendiente',
                'texto' => 'Pendiente',
                'clase' => 'estado-pendiente',
            ];
        }

        if ($requeridos <= 0 || $ingresados < $requeridos) {
            return [
                'codigo' => 'en_proceso',
                'texto' => 'En proceso',
                'clase' => 'estado-en-proceso',
            ];
        }

        if ($aprobados < $requeridos) {
            return [
                'codigo' => 'revision',
                'texto' => 'En revisión',
                'clase' => 'estado-revision',
            ];
        }

        return [
            'codigo' => 'aprobado',
            'texto' => 'Aprobado',
            'clase' => 'estado-aprobado',
        ];
    }
}
