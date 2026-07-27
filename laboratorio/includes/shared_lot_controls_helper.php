<?php

if (!function_exists('labSharedControlKeyFromNumero')) {
    function labSharedControlKeyFromNumero(string $numeroLaboratorio): ?string
    {
        $numeroLaboratorio = trim($numeroLaboratorio);
        if ($numeroLaboratorio === '') {
            return null;
        }

        if (strpos($numeroLaboratorio, '__shared__:') !== 0) {
            return null;
        }

        $key = substr($numeroLaboratorio, 11);
        return $key !== '' ? $key : null;
    }
}

if (!function_exists('labSharedControlRowsByLote')) {
    function labSharedControlRowsByLote(array $controlNames): array
    {
        $controlLookup = array_fill_keys($controlNames, true);
        $rowCountFields = array_merge(['lote', 'numero_laboratorio'], $controlNames);
        $sharedByLote = [];

        for ($fila = 0, $total = lab_post_row_count($rowCountFields); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($rowCountFields, $fila)) {
                continue;
            }

            $lote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $sharedKey = labSharedControlKeyFromNumero($numeroLaboratorio);
            if ($lote === '' || $sharedKey === null || !isset($controlLookup[$sharedKey])) {
                continue;
            }

            $sharedByLote[$lote] ??= [];
            $sharedByLote[$lote][$sharedKey] = lab_post_float($sharedKey, $fila);
        }

        return $sharedByLote;
    }
}
