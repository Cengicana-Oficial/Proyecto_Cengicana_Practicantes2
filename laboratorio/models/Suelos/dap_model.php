<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarDapSuelo(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_dap', [
        'peso_caja' => $data['peso_caja'] ?? 0,
        'peso_muestra_seca' => $data['peso_muestra_seca'] ?? 0,
        'volumen_final' => $data['volumen_final'] ?? 0,
        'peso_suelo_seco' => $data['peso_suelo_seco'] ?? 0,
        'densidad' => $data['densidad'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'DAP guardado correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar DAP.'];
}
