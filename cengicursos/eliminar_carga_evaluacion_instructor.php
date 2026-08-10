<?php
// Endpoint AJAX para retirar (hard delete) evaluaciones de instructor enviadas por
// error, con la misma granularidad que pide el modal "Historial de cargas" de
// cengicursos/instructores.php:
//  - carga_id: retira TODA una carga masiva (Excel/CSV) de una sola vez -- borra todas
//    las filas de evaluaciones_instructor que apuntan a esa carga y la propia fila de
//    cargas_evaluaciones_instructor.
//  - evaluacion_id: retira UN envio individual del formulario publico con token
//    (guardar_evaluacion_instructor.php). Solo se acepta si esa fila tiene
//    carga_id IS NULL, para que este endpoint no se use para sacar una sola fila de
//    en medio de una carga masiva por atras (no es un caso soportado por el diseno).
//
// Responde JSON {ok, mensaje}, mismo formato de respuesta usado por otros endpoints
// AJAX de este modulo (ver cengicursos/buscar_participantes_ajax.php).
//
// No se aplica cengi_scope_sql()/cengi_ve_todo_por_rol_o_ingenio() aqui porque
// instructores.php no restringe por ingenio en ningun punto de la gestion de
// instructores/evaluaciones (son datos globales del modulo, no por ingenio) -- mismo
// alcance que ya tiene carga_evaluaciones_instructor.php.

require_once 'revisar_permisos.php';
require_once 'conexion.php';

cengi_require_gestionar_instructores('instructores.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$db = conectar();

/**
 * Responde JSON {ok, mensaje} y termina la ejecucion, igual que el resto de este
 * endpoint necesita (no hay una redireccion de por medio: lo consume fetch() desde
 * cengicursos/instructores.php).
 */
function cengi_responder_eliminar_carga_evaluacion(bool $ok, string $mensaje): void
{
    echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cengi_responder_eliminar_carga_evaluacion(false, 'Método no permitido.');
}

$cargaId = (int) ($_POST['carga_id'] ?? 0);
$evaluacionId = (int) ($_POST['evaluacion_id'] ?? 0);

try {
    if ($cargaId > 0) {
        $stmtVerificar = $db->prepare('SELECT id FROM cargas_evaluaciones_instructor WHERE id = ?');
        $stmtVerificar->execute([$cargaId]);
        if (!$stmtVerificar->fetchColumn()) {
            throw new RuntimeException('La carga que intentas retirar ya no existe.');
        }

        $db->beginTransaction();
        // evaluaciones_instructor.carga_id -> cargas_evaluaciones_instructor.id ya tiene
        // ON DELETE CASCADE, pero se borra explicitamente antes de la carga para no
        // depender unicamente del CASCADE (por ejemplo, en un contenedor donde la
        // migracion se haya aplicado solo parcialmente).
        $stmtEliminarEvaluaciones = $db->prepare('DELETE FROM evaluaciones_instructor WHERE carga_id = ?');
        $stmtEliminarEvaluaciones->execute([$cargaId]);
        $stmtEliminarCarga = $db->prepare('DELETE FROM cargas_evaluaciones_instructor WHERE id = ?');
        $stmtEliminarCarga->execute([$cargaId]);
        $db->commit();

        cengi_responder_eliminar_carga_evaluacion(true, 'La carga masiva se retiró correctamente.');
    } elseif ($evaluacionId > 0) {
        $stmtVerificar = $db->prepare('SELECT id FROM evaluaciones_instructor WHERE id = ? AND carga_id IS NULL');
        $stmtVerificar->execute([$evaluacionId]);
        if (!$stmtVerificar->fetchColumn()) {
            throw new RuntimeException('La evaluación individual que intentas retirar ya no existe o no es un envío individual.');
        }

        $stmtEliminar = $db->prepare('DELETE FROM evaluaciones_instructor WHERE id = ? AND carga_id IS NULL');
        $stmtEliminar->execute([$evaluacionId]);

        cengi_responder_eliminar_carga_evaluacion(true, 'La evaluación individual se retiró correctamente.');
    } else {
        throw new RuntimeException('No se recibió una carga o evaluación válida para retirar.');
    }
} catch (RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    cengi_responder_eliminar_carga_evaluacion(false, $e->getMessage());
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error al retirar carga/evaluación de instructor: ' . $e->getMessage());
    cengi_responder_eliminar_carga_evaluacion(false, 'No fue posible retirar el registro seleccionado. Intenta nuevamente.');
}
