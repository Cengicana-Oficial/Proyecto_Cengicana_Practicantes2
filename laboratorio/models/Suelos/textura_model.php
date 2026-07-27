<?php

require_once __DIR__ . '/../conexion.php';

function guardarTexturaSuelo(array $data, array $metadata = []): array
{
    $conn = (new Conexion())->conectar();

    $stmt = $conn->prepare(
        "INSERT INTO analisis_textura
        (no_lab, fecha, porcentaje_hr, lectura_1, temp_1, lectura_corregida_1, porcentaje_l_a, lectura_2, temp_2, lectura_corregida_2, total, porcentaje_arcilla, porcentaje_limo, porcentaje_arena, textura)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $ok = $stmt->execute([
        $data['no_lab'] ?? null,
        $data['fecha'] ?? null,
        $data['porcentaje_hr'] ?? 0,
        $data['lectura_1'] ?? 0,
        $data['temp_1'] ?? 0,
        $data['lectura_corregida_1'] ?? 0,
        $data['porcentaje_l_a'] ?? 0,
        $data['lectura_2'] ?? 0,
        $data['temp_2'] ?? 0,
        $data['lectura_corregida_2'] ?? 0,
        $data['total'] ?? 0,
        $data['porcentaje_arcilla'] ?? 0,
        $data['porcentaje_limo'] ?? 0,
        $data['porcentaje_arena'] ?? 0,
        $data['textura'] ?? '',
    ]);
    $id = $ok ? (int) $conn->lastInsertId() : false;

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Textura guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar textura.'];
}
