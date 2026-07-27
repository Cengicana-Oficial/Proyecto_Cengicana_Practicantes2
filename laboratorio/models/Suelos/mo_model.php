<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarMo(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'MO_Porcentaje', [
        'peso_muestra' => $data['peso_muestra'] ?? 0,
        'sulfato_ferroso_consumido' => $data['sulfato_ferroso_consumido'] ?? 0,
        'porcentaje_carbono_organico' => $data['porcentaje_carbono_organico'] ?? 0,
        'porcentaje_materia_organica' => $data['porcentaje_materia_organica'] ?? 0,
        'm1_dicromato' => $data['m1_dicromato'] ?? 0,
        'm2_dicromato' => $data['m2_dicromato'] ?? 0,
        'val_solucion_ferroso' => $data['val_solucion_ferroso'] ?? 0,
        'normalidad_sulfato_ferroso' => $data['normalidad_sulfato_ferroso'] ?? 0,
        'ml_util_sulfato_ferroso1N' => $data['ml_util_sulfato_ferroso1N'] ?? 0,
        'dicromato_potasio' => $data['dicromato_potasio'] ?? 0,
        'dicromato_consumido' => $data['dicromato_consumido'] ?? 0,
        'blanco_sulfato_ferroso' => $data['blanco_sulfato_ferroso'] ?? 0,
        'blanco_sulfato_ferroso_2' => $data['blanco_sulfato_ferroso_2'] ?? 0,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Materia organica guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar materia organica.'];
}
