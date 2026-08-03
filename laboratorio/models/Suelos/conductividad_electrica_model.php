<?php

require_once __DIR__ . '/../conexion.php';

function guardarConductividadElectrica(array $data = [], array $metadata = []): array
{
    $conn = (new Conexion())->conectar();

    $stmt = $conn->prepare("
        INSERT INTO suelo_conductividad
        (
            id_solicitud,
            numero_laboratorio,
            id_lote,
            id_formulario,
            lectura,
            temperatura,
            ce,
            id_encabezado
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $ok = $stmt->execute([
        $metadata['id_solicitud'] ?? null,
        $metadata['numero_laboratorio'] ?? null,
        $metadata['id_lote'] ?? null,
        $metadata['id_formulario'] ?? null,

        $data['LecturaCE'] ?? 0,
        $data['Temperatura'] ?? 0,
        $data['CE'] ?? 0,

        $metadata['id_encabezado'] ?? null,
    ]);

    if ($ok) {
        return [
            'exito'   => true,
            'mensaje' => 'Conductividad eléctrica guardada correctamente.',
            'id'      => (int)$conn->lastInsertId(),
        ];
    }

    return [
        'exito'   => false,
        'mensaje' => 'Error al guardar la conductividad eléctrica.',
    ];
}