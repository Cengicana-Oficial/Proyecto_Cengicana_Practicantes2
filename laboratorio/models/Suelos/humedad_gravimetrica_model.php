<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarHumedadGravimetrica(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_humedad_gravimetrica', [
        'no_caja' => $data['NoCaja'] ?? null,
        'peso_caja' => $data['PesoCaja'] ?? 0,
        'peso_caja_muestra_humeda' => $data['PesoCajaMHumeda'] ?? 0,
        'peso_caja_muestra_seca' => $data['PesoCajaMseca'] ?? 0,
        'peso_suelo_humedo' => $data['PesoHumedo'] ?? 0,
        'peso_suelo_seco' => $data['PesoSeco'] ?? 0,
        'h_grav' => $data['PorHGrav'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Humedad gravimétrica guardada correctamente.',
            'id' => (int) $id
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar humedad gravimétrica.'
    ];
}
