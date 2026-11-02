<?php
/**
 * Firma electrónica: la aprobación y firma del informe se hace ahora dentro de Informes
 * (lab_informe_firmar en informes_model.php), como en el prototipo. Aquí queda solo la
 * validación de la imagen de firma, compartida por ese flujo.
 */

if (!function_exists('lab_firma_normalizar_png')) {
    function lab_firma_normalizar_png(string $firma): string
    {
        $firma = trim($firma);
        if ($firma === '' || strlen($firma) > 4 * 1024 * 1024) {
            throw new InvalidArgumentException('La firma está vacía o excede el tamaño permitido.');
        }
        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $firma, $coincidencias)) {
            throw new InvalidArgumentException('El formato de la firma no es válido.');
        }

        $binario = base64_decode(preg_replace('/\s+/', '', $coincidencias[1]), true);
        if ($binario === false || strlen($binario) < 16 || substr($binario, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new InvalidArgumentException('La imagen de firma no es un PNG válido.');
        }

        return 'data:image/png;base64,' . base64_encode($binario);
    }
}
