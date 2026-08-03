<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarBrixPol($brix, $pol, $peso_torta, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'cana_brixpol', [
        'brix' => $brix,
        'pol' => $pol,
        'peso_torta' => $peso_torta,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Brix y Pol guardados correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}
