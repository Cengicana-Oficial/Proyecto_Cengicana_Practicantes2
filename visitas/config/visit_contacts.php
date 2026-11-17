<?php
/**
 * Contactos agregados manualmente en Visitas (tabla contactos_visita,
 * migración 41_visit_contacts.sql). Si la tabla aún no existe, las funciones
 * devuelven listas vacías para que el panel siga funcionando.
 */
function visitas_contacts_available(PDO $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'contactos_visita'"
        );
        $stmt->execute();
        $available = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        $available = false;
    }
    return $available;
}

/** Contactos activos de un tipo ('interno' o 'externo'), listos para JSON. */
function visitas_contacts_list(PDO $conn, string $type): array
{
    if (!visitas_contacts_available($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT c.id_contacto, c.nombre, c.correo, c.telefono, c.institucion, c.cargo,
                c.id_area, a.nombre_area, c.recibe_notificaciones, c.notas,
                c.creado_por, c.creado_en, c.actualizado_por, c.actualizado_en
         FROM contactos_visita c
         LEFT JOIN areas_interes a ON a.id_area = c.id_area
         WHERE c.tipo = ? AND c.estado = 1
         ORDER BY c.nombre"
    );
    $stmt->execute([$type]);
    return array_map(static fn(array $row): array => [
        'contactId' => (int) $row['id_contacto'],
        'name' => (string) $row['nombre'],
        'email' => (string) ($row['correo'] ?? ''),
        'phone' => (string) ($row['telefono'] ?? ''),
        'institution' => (string) ($row['institucion'] ?? ''),
        'role' => (string) ($row['cargo'] ?? ''),
        'areaId' => $row['id_area'] !== null ? (int) $row['id_area'] : null,
        'area' => (string) ($row['nombre_area'] ?? ''),
        'notify' => (int) $row['recibe_notificaciones'] === 1,
        'notes' => (string) ($row['notas'] ?? ''),
        'updatedBy' => (string) ($row['actualizado_por'] ?? $row['creado_por'] ?? ''),
        'updatedAt' => (string) ($row['actualizado_en'] ?? $row['creado_en'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}
