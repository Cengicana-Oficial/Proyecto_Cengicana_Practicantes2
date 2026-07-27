<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';
$conexion = new Conexion();
$conn = $conexion->conectar();

function guardarFosforo($peso, $abs_blanco, $absorbancia, $ppm_p_sol, $porcentaje_p, $control, array $metadata = [])
{
    global $conn;

    $id = labLegacyInsertAnalysisRow($conn, 'foliar_fosforo', [
        'peso' => $peso,
        'abs_blanco' => $abs_blanco,
        'absorbancia' => $absorbancia,
        'ppm_p_sol' => $ppm_p_sol,
        'porcentaje_p' => $porcentaje_p,
        'control' => $control,
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
        'mensaje' => 'Error al guardar.',
    ];
}

function guardarCurvaFosforo($punto_curva, $absorbancia)
{
    global $conn;

    $stmt = $conn->prepare(
        "INSERT INTO curva_fosforo_fo
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
        "INSERT INTO foliar_fosforo_curva
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
            cf.punto_curva,
            cf.absorbancia
        FROM curva_fosforo_fo cf
        INNER JOIN foliar_fosforo_curva fcf
            ON cf.id_curva = fcf.id_curva_fosforo
        WHERE fcf.id_fosforo = ?
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
            peso,
            ppm_p_sol,
            porcentaje_p,
            control
        FROM foliar_fosforo
        ORDER BY id DESC
    ";

    $resultado = $conn->query($sql);

    return $resultado->fetchAll(PDO::FETCH_ASSOC);
}
?>
