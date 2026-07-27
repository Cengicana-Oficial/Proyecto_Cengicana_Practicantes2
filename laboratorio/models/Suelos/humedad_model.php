<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarHumedadSuelo(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_humedad', [
        'humedad' => $data['humedad'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Humedad guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar humedad.'];
}
