<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarPhSuelo(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_ph', [
        'ph' => $data['ph'] ?? 0,
        'temperatura' => $data['temperatura'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'pH guardado correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar pH.'];
}
