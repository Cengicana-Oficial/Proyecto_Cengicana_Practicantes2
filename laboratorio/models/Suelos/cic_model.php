<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarCic(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_macros', [
        'cic_blanco' => $data['cic_blanco'] ?? 0,
        'cic_muestra' => $data['cic_muestra'] ?? 0,
        'cic_meq' => $data['cic_meq'] ?? 0,
        'control' => $data['control'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'CIC guardado correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar CIC.'];
}
