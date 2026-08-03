<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarHumedad($no_bandeja, $peso_bandeja, $peso_muestra, $peso_bandeja_seca, $peso_bandeja_humedad, $porcentaje_humedad, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'cana_humedad', [
        'no_bandeja' => $no_bandeja,
        'peso_bandeja' => $peso_bandeja,
        'peso_muestra' => $peso_muestra,
        'peso_bandeja_seca' => $peso_bandeja_seca,
        'peso_bandeja_humedad' => $peso_bandeja_humedad,
        'porcentaje_humedad' => $porcentaje_humedad,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'El porcentaje de humedad se guardo correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
