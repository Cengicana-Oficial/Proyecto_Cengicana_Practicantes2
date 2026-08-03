<?php
require_once __DIR__ . '/../conexion.php';

require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarCloruros($ml_muestra, $ml_agno3_blanco, $ml_agno3_muestra, $normalidad_agno3, $cloruros_mgl, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'agua_cloruros', [
        'ml_muestra' => $ml_muestra,
        'ml_agno3_blanco' => $ml_agno3_blanco,
        'ml_agno3_muestra' => $ml_agno3_muestra,
        'normalidad_agno3' => $normalidad_agno3,
        'cloruros_mgl' => $cloruros_mgl,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Cloruros guardados correctamente.', 'id' => $id];
function clorurosObtenerIdLote(PDO $conn, string $codigoLote): ?int
{
    $codigoLote = trim($codigoLote);
    if ($codigoLote === '') {
        return null;
    }

    $stmt = $conn->prepare("SELECT id_lote FROM lote WHERE codigo_lote = ? LIMIT 1");
    $stmt->execute([$codigoLote]);
    $idLote = $stmt->fetchColumn();

    return $idLote !== false ? (int) $idLote : null;
}

function clorurosObtenerIdSolicitud(PDO $conn, ?int $idLote): ?int
{
    if (!$idLote) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id_solicitud FROM solicitud WHERE id_lote = ? ORDER BY id_solicitud DESC LIMIT 1");
    $stmt->execute([$idLote]);
    $idSolicitud = $stmt->fetchColumn();

    return $idSolicitud !== false ? (int) $idSolicitud : null;
}

function clorurosResolverNumeroLaboratorio(PDO $conn, int $idLote, string $numeroLaboratorio): ?int
{
    $numeroLaboratorio = trim($numeroLaboratorio);
    if ($numeroLaboratorio === '') {
        return null;
    }

    if (is_numeric($numeroLaboratorio)) {
        return (int) $numeroLaboratorio;
    }

    if (preg_match('/^[A-Z]-([0-9]+)-[0-9]{2}-[0-9]{2}$/i', $numeroLaboratorio, $matches)) {
        return (int) $matches[1];
    }

    $stmt = $conn->prepare("
        SELECT m.numero_muestra
          FROM muestra m
          INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
         WHERE s.id_lote = ?
           AND (m.codigo_lab = ? OR CAST(m.numero_muestra AS CHAR) = ?)
         ORDER BY s.id_solicitud DESC
         LIMIT 1
    ");
    $stmt->execute([$idLote, $numeroLaboratorio, $numeroLaboratorio]);
    $numero = $stmt->fetchColumn();

    return $numero !== false ? (int) $numero : null;
}

function clorurosCrearFormulario(PDO $conn, int $idEstado, string $fecha, string $analista): int
{
    $stmt = $conn->prepare("
        INSERT INTO formulario (id_estado, id_rango, id_tipo_analisis, fecha, analista)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $idEstado,
        null,
        null,
        $fecha,
        $analista,
    ]);

    return (int) $conn->lastInsertId();
}

function guardarCloruros(
    PDO $conn,
    ?int $idSolicitud,
    ?int $numeroLaboratorio,
    ?int $idLote,
    int $idFormulario,
    $ml_muestra,
    $ml_agno3_blanco,
    $ml_agno3_muestra,
    $normalidad_agno3,
    $cloruros_mgl
): bool {
    $stmt = $conn->prepare(
        "INSERT INTO agua_cloruros
            (id_solicitud, numero_laboratorio, id_lote, id_formulario,
             ml_muestra, ml_agno3_blanco, ml_agno3_muestra, normalidad_agno3, cloruros_mgl)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    return $stmt->execute([
        $idSolicitud,
        $numeroLaboratorio,
        $idLote,
        $idFormulario,
        $ml_muestra,
        $ml_agno3_blanco,
        $ml_agno3_muestra,
        $normalidad_agno3,
        $cloruros_mgl,
    ]);

    if ($stmt->execute([$ml_muestra, $ml_agno3_blanco, $ml_agno3_muestra, $normalidad_agno3, $cloruros_mgl])) {
        return ["exito" => true, "mensaje" => "Cloruros guardados correctamente.", "id" => (int) $conn->lastInsertId()];
    } else {
        return ["exito" => false, "mensaje" => "Error al guardar."];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
    }
}