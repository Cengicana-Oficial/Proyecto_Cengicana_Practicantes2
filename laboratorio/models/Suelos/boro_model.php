<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';
$conexion = new Conexion();
$conn = $conexion->conectar();

function guardarBoro($abs_blanco, $absorbancia, $ppm_b, $control, array $metadata = [])
{
    global $conn;
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_boro', [
        'abs_blanco' => $abs_blanco,
        'absorbancia' => $absorbancia,
        'ppm_b' => $ppm_b,
        'control' => $control,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Boro guardado correctamente.',
            'id_boro' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}

function guardarCurvaBoro($punto_curva, $absorbancia)
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

function relacionarBoroCurva($id_boro, $id_curva)
{
    global $conn;

    $stmt = $conn->prepare(
        "INSERT INTO suelo_boro_curva
        (id_boro, id_curva_boro)
        VALUES (?, ?)"
    );

    return $stmt->execute([$id_boro, $id_curva]);
}

function obtenerCurvaBoro($id_boro)
{
    global $conn;

    $sql = "
        SELECT
            cb.punto_curva,
            cb.absorbancia
        FROM curva_boro cb
        INNER JOIN suelo_boro_curva bc
            ON cb.id_curva = bc.id_curva_boro
        WHERE bc.id_boro = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_boro]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerHistorialBoro()
{
    global $conn;

    $sql = "
        SELECT
            id,
            abs_blanco,
            absorbancia,
            ppm_b,
            control
        FROM suelo_boro
        ORDER BY id DESC
    ";

    $resultado = $conn->query($sql);

    return $resultado->fetchAll(PDO::FETCH_ASSOC);
}
?>
