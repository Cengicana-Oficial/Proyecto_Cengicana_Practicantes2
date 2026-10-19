<?php
/** El llamador mantiene la transacción para proteger comprobación y borrado. */
function cengi_instructor_eliminar(PDO $db, int $id): void
{
    if (!$db->inTransaction()) throw new LogicException('Se requiere una transacción.');
    $stmt = $db->prepare('SELECT id FROM instructores WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    if ($id < 1 || !$stmt->fetchColumn()) throw new InvalidArgumentException('El instructor ya no existe.');

    // Incluye relaciones sin clave foránea y las que utilicen otro nombre de columna.
    $relaciones = $db->query("SELECT c.TABLE_NAME AS tabla, c.COLUMN_NAME AS columna
        FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND c.COLUMN_NAME='instructor_id'
        UNION SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='instructores'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($relaciones as $r) {
        $tabla = '`' . str_replace('`', '``', $r['tabla']) . '`';
        $columna = '`' . str_replace('`', '``', $r['columna']) . '`';
        $stmt = $db->prepare("SELECT $columna FROM $tabla WHERE $columna = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException('No se puede eliminar: el instructor tiene asignaciones, documentos u otros registros vinculados. Puedes desactivarlo para conservar su historial.');
        }
    }
    $db->prepare('DELETE FROM instructores WHERE id = ?')->execute([$id]);
}
