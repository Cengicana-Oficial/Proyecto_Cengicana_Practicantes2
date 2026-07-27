<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';
$conexion = new Conexion();
$conn = $conexion->conectar();

function guardarBoroFoliar($peso_muestra, $abs_blanco, $absorbancia, $ppm_b_solucion, $ppm_b_muestra, array $metadata = [])
{
    global $conn;

    $id = labLegacyInsertAnalysisRow($conn, 'foliar_boro', [
        'peso_muestra' => $peso_muestra,
        'abs_blanco' => $abs_blanco,
        'absorbancia' => $absorbancia,
        'ppm_b_solucion' => $ppm_b_solucion,
        'ppm_b_muestra' => $ppm_b_muestra,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Boro foliar guardado correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}

function guardarCurvaBoroFoliar($punto_curva, $absorbancia)
{
    global $conn;

    $stmt = $conn->prepare(
        "INSERT INTO curva_boro
        (punto_curva, absorbancia)
        VALUES (?, ?)"
    );

    if ($stmt->execute([$punto_curva, $absorbancia])) {
        return (int) $conn->lastInsertId();
    }

    return false;
}

function relacionarBoroCurvaFoliar($id_boro, $id_curva)
{
    global $conn;

    $stmt = $conn->prepare(
        "INSERT INTO foliar_boro_curva
        (id_boro, id_curva_boro)
        VALUES (?, ?)"
    );

    return $stmt->execute([$id_boro, $id_curva]);
}
