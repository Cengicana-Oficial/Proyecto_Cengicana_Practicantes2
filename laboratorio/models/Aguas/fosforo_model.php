<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';
$conexion = new Conexion();
$conn = $conexion->conectar();

function guardarFosforo($abs_blanco, $absorbancia, $ppm_sol, $ppm_p, array $metadata = [])
{
    global $conn;

    $id = labLegacyInsertAnalysisRow($conn, 'agua_fosforo', [
        'abs_blanco' => $abs_blanco,
        'absorbancia' => $absorbancia,
        'ppm_sol' => $ppm_sol,
        'ppm_p' => $ppm_p,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Fosforo guardado correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar el registro.',
    ];
}

function guardarCurvaFosforo($punto_curva, $absorbancia)
{
    global $conn;

    $stmt = $conn->prepare(
        "INSERT INTO curva_fosforo_ag
        (punto_curva, absorbancia)
        VALUES (?, ?)"
    );

    if ($stmt->execute([$punto_curva, $absorbancia])) {
        return (int) $conn->lastInsertId();
    }

    return false;
}

function relacionarFosforoCurva($id_fosforo, $id_curva)
{
    global $conn;

    $stmt = $conn->prepare(
        "INSERT INTO agua_fosforo_curva
        (id_fosforo, id_curva_fosforo)
        VALUES (?, ?)"
    );

    return $stmt->execute([$id_fosforo, $id_curva]);
}

function obtenerCurvaFosforo($id_fosforo)
{
    global $conn;

    $sql = "
        SELECT
            cag.punto_curva,
            cag.absorbancia
        FROM curva_fosforo_ag cag
        INNER JOIN agua_fosforo_curva agc
            ON cag.id_curva = agc.id_curva_fosforo
        WHERE agc.id_fosforo = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_fosforo]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerHistorialFosforo()
{
    global $conn;

    $sql = "
        SELECT
            id,
            abs_blanco,
            absorbancia,
            ppm_sol,
            ppm_p
        FROM agua_fosforo
        ORDER BY id DESC
    ";

    $resultado = $conn->query($sql);

    return $resultado ? $resultado->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
