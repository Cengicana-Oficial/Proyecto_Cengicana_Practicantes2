<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarHumedadResidualSuelo(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_humedad_residual', [
        'control' => $data['Control'] ?? 0,
        'peso_caja' => $data['PesoCaja'] ?? 0,
        'peso_muestra_humeda' => $data['PesoMuestraHumedo'] ?? 0,
        'peso_caja_muestra_humeda' => $data['PesoCajaMHumeda'] ?? 0,
        'peso_caja_muestra_seca' => $data['PesoCajaMseca'] ?? 0,
        'humedad_residual' => $data['PorHGrav'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Humedad residual guardada correctamente.',
            'id' => (int) $id
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar humedad residual.'
    ];
}
