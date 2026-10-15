<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/Conexion.php';
if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Se esperaba MySQL.');
$before = (int)$conn->query('SELECT COUNT(*) FROM Encuestas')->fetchColumn();
$id = 'QA-' . bin2hex(random_bytes(6));
function expect_db_error(PDO $db, string $sql, array $values, int|array $code): void {
    try { $db->prepare($sql)->execute($values); }
    catch (PDOException $e) {
        if (in_array((int)($e->errorInfo[1] ?? 0), (array)$code, true)) return;
        throw $e;
    }
    throw new RuntimeException('No se aplicó una restricción esperada.');
}
$conn->beginTransaction();
try {
    $conn->prepare('INSERT INTO Encuestas (Area,Asesor,Institucion,Nombre_Cliente,Fecha_Solicitud,Tipo_Servicio,Descripcion_Actividad) VALUES (?,?,?,?,?,?,?)')
        ->execute(['Verificación','Prueba técnica','Prueba temporal','Prueba temporal',date('Y-m-d'),'Validación','Registro temporal; se revierte al terminar.']);
    $internalId = $conn->lastInsertId();
    $conn->prepare('UPDATE Encuestas SET ID_Servicio_Tecnico=?,Numero_Participantes=? WHERE ID=?')->execute([$id,3,$internalId]);
    $insertResponse = 'INSERT INTO Respuestas (ID_Servicio_Tecnico,Puntuacion,Comentario,Persona_Respondio,Fecha_Respuesta) VALUES (?,?,?,?,?)';
    expect_db_error($conn, $insertResponse, [$id,6,'Prueba','Prueba',date('Y-m-d')], 3819);
    expect_db_error($conn, $insertResponse, ['NO-' . bin2hex(random_bytes(6)),5,'Prueba','Prueba',date('Y-m-d')], [1216,1452]);
    $conn->prepare($insertResponse)->execute([$id,5,'Prueba','Prueba',date('Y-m-d')]);
    expect_db_error($conn, $insertResponse, [$id,5,'Duplicada','Prueba',date('Y-m-d')], 1062);
    $conn->prepare("UPDATE Encuestas SET Estado='Finalizado',Fecha_Cierre=?,Envio_Encuesta='Si' WHERE ID=?")->execute([date('Y-m-d'),$internalId]);
    $check = $conn->prepare('SELECT e.Estado,e.Numero_Participantes,r.Puntuacion FROM Encuestas e JOIN Respuestas r USING (ID_Servicio_Tecnico) WHERE e.ID=?');
    $check->execute([$internalId]);
    $row = $check->fetch();
    if (!$row || $row['Estado'] !== 'Finalizado' || (int)$row['Puntuacion'] !== 5 || (int)$row['Numero_Participantes'] !== 3) throw new RuntimeException('Falló la lectura del servicio de prueba.');
} finally { if ($conn->inTransaction()) $conn->rollBack(); }
if ((int)$conn->query('SELECT COUNT(*) FROM Encuestas')->fetchColumn() !== $before) throw new RuntimeException('Quedaron registros de prueba.');
echo "PASS: conexión MySQL, registro, edición, respuesta, puntuación válida, relación entre tablas y prevención de duplicados. Sin registros de prueba persistidos.\n";
