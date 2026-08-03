<?php

require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_aprobar_solicitudes('solicitudes.php');

function cengi_buscar_rol_estudiante(mysqli $conexionUsuarios)
{
    $stmtRol = $conexionUsuarios->prepare("
        SELECT id, nombre_rol
        FROM roles
        WHERE LOWER(nombre_rol) IN ('estudiante', 'alumno', 'participante')
        ORDER BY
            CASE LOWER(nombre_rol)
                WHEN 'estudiante' THEN 1
                WHEN 'alumno' THEN 2
                ELSE 3
            END
        LIMIT 1
    ");
    $stmtRol->execute();
    $resultado = $stmtRol->get_result();

    return $resultado ? $resultado->fetch_assoc() : null;
}

function cengi_buscar_modulo_cursos(mysqli $conexionUsuarios)
{
    $stmtModulo = $conexionUsuarios->prepare("
        SELECT id
        FROM modulos
        WHERE LOWER(nombre) IN ('cursos', 'cengicursos')
        ORDER BY id
        LIMIT 1
    ");
    $stmtModulo->execute();
    $resultado = $stmtModulo->get_result();
    $fila = $resultado ? $resultado->fetch_assoc() : null;

    return (int) ($fila['id'] ?? 0);
}

function cengi_password_temporal_estudiante(array $solicitud)
{
    $cui = preg_replace('/\D+/', '', (string) ($solicitud['cui_participante'] ?? ''));

    if ($cui !== '') {
        return $cui;
    }

    return substr(hash('sha256', (string) ($solicitud['correo'] ?? '') . microtime(true)), 0, 10);
}

function cengi_crear_o_asociar_usuario_estudiante(array $solicitud)
{
    $correo = trim((string) ($solicitud['correo'] ?? ''));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $conexionUsuarios = conectar_usuarios_menu();
    $rolEstudiante = cengi_buscar_rol_estudiante($conexionUsuarios);

    if (!$rolEstudiante) {
        return 0;
    }

    $stmtUsuario = $conexionUsuarios->prepare("
        SELECT id
        FROM usuarios
        WHERE correo = ?
        LIMIT 1
    ");
    $stmtUsuario->bind_param('s', $correo);
    $stmtUsuario->execute();
    $resultadoUsuario = $stmtUsuario->get_result();
    $usuario = $resultadoUsuario ? $resultadoUsuario->fetch_assoc() : null;

    if ($usuario) {
        $usuarioId = (int) $usuario['id'];
    } else {
        $nombre = trim((string) ($solicitud['nombre_participante'] ?? ''));
        $contrasenaHash = password_hash(cengi_password_temporal_estudiante($solicitud), PASSWORD_DEFAULT);
        $rolId = (int) $rolEstudiante['id'];
        $ingenioId = isset($solicitud['ingenio_id']) ? (int) $solicitud['ingenio_id'] : null;
        $esSuperadmin = 0;

        $stmtInsertUsuario = $conexionUsuarios->prepare("
            INSERT INTO usuarios (nombre, correo, contrasena, rol_id, ingenio_id, es_superadmin)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtInsertUsuario->bind_param('sssiii', $nombre, $correo, $contrasenaHash, $rolId, $ingenioId, $esSuperadmin);
        $stmtInsertUsuario->execute();
        $usuarioId = (int) $conexionUsuarios->insert_id;
    }

    $moduloCursosId = cengi_buscar_modulo_cursos($conexionUsuarios);
    if ($moduloCursosId > 0) {
        $stmtVinculo = $conexionUsuarios->prepare("
            INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id)
            VALUES (?, ?)
        ");
        $stmtVinculo->bind_param('ii', $usuarioId, $moduloCursosId);
        $stmtVinculo->execute();
    }

    return $usuarioId;
}

$db = conectar();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: solicitudes.php");
    exit();
}

$db->beginTransaction();

try {

    $sqlSolicitud = "
        SELECT
            s.*,
            i.nombre_ingenios
        FROM solicitudes_inscripcion s
        LEFT JOIN ingenios i ON i.id = s.ingenio_id
        WHERE s.id_solicitud = ?
        LIMIT 1
    ";

    $stmtSolicitud = $db->prepare($sqlSolicitud);
    $stmtSolicitud->execute([$id]);

    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        throw new RuntimeException('Solicitud no encontrada.');
    }

    if (strtolower(trim((string) ($solicitud['estado'] ?? ''))) !== 'pendiente') {
        throw new RuntimeException('La solicitud ya fue gestionada.');
    }

    if (
        !cengi_ve_todo_por_rol_o_ingenio() &&
        cengi_texto_normalizado($solicitud['nombre_ingenios'] ?? '') !== cengi_texto_normalizado(cengi_ingenio_nombre_actual())
    ) {
        throw new RuntimeException('No puedes aprobar solicitudes de otro ingenio.');
    }

    $sqlExiste = "
        SELECT id
        FROM participantes
        WHERE cui_participantes = ?
        LIMIT 1
    ";

    $stmtExiste = $db->prepare($sqlExiste);
    $stmtExiste->execute([
        $solicitud['cui_participante']
    ]);

    $participante = $stmtExiste->fetch(PDO::FETCH_ASSOC);

    if ($participante) {

        $idParticipante = (int)$participante['id'];
        $stmtActivarParticipante = $db->prepare("
            UPDATE participantes
            SET
                ingenio_id = ?,
                nombre_participantes = ?,
                puesto_participantes = ?,
                area_participantes = ?,
                estado_participantes = 1,
                actualizado = NOW()
            WHERE id = ?
        ");
        $stmtActivarParticipante->execute([
            (int)$solicitud['ingenio_id'],
            $solicitud['nombre_participante'],
            $solicitud['puesto_participante'],
            $solicitud['area_participante'],
            $idParticipante
        ]);

    } else {
        $stmtInsert = $db->prepare("
            INSERT INTO participantes (
                ingenio_id,
                usuarios_id,
                cui_participantes,
                nombre_participantes,
                puesto_participantes,
                area_participantes,
                estado_participantes,
                creado
            )
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ");

        $stmtInsert->execute([
            (int)$solicitud['ingenio_id'],
            null,
            $solicitud['cui_participante'],
            $solicitud['nombre_participante'],
            $solicitud['puesto_participante'],
            $solicitud['area_participante']
        ]);

        $idParticipante = (int) $db->lastInsertId();
    }

    $usuarioEstudianteId = cengi_crear_o_asociar_usuario_estudiante($solicitud);

    if ($usuarioEstudianteId > 0) {
        $stmtVincularParticipante = $db->prepare("
            UPDATE participantes
            SET usuarios_id = ?, actualizado = NOW()
            WHERE id = ?
        ");
        $stmtVincularParticipante->execute([
            $usuarioEstudianteId,
            $idParticipante,
        ]);
    }

    $stmtExisteAsignacion = $db->prepare("
        SELECT id
        FROM asignaciones
        WHERE participantes_id = ?
        AND cursos_id = ?
        LIMIT 1
    ");

    $cursoId = (int)$solicitud['curso_id'];

    $stmtExisteAsignacion->execute([
        $idParticipante,
        $cursoId
    ]);

    $asignacion = $stmtExisteAsignacion->fetch(PDO::FETCH_ASSOC);

    if (!$asignacion) {

        $stmtAsignacion = $db->prepare("
            INSERT INTO asignaciones (
                participantes_id,
                usuarios_id,
                cursos_id,
                estado_asignaciones,
                creado
            )
            VALUES (?, ?, ?, 1, NOW())
        ");

        $stmtAsignacion->execute([
            $idParticipante,
            $usuarioEstudianteId > 0 ? $usuarioEstudianteId : null,
            $cursoId
        ]);
    } else {
        $stmtReactivarAsignacion = $db->prepare("
            UPDATE asignaciones
            SET
                usuarios_id = ?,
                estado_asignaciones = 1,
                actualizado = NOW()
            WHERE id = ?
        ");
        $stmtReactivarAsignacion->execute([
            $usuarioEstudianteId > 0 ? $usuarioEstudianteId : null,
            (int)$asignacion['id']
        ]);
    }

    $stmtUpdate = $db->prepare("
        UPDATE solicitudes_inscripcion
        SET estado = 'Aprobado'
        WHERE id_solicitud = ?
    ");

    $stmtUpdate->execute([$id]);

    $db->commit();

} catch (Throwable $e) {

    $db->rollBack();

    header(
        "Location: solicitudes.php?resultado=error&id={$id}&mensaje=" .
        rawurlencode($e->getMessage())
    );
    exit();
}

header("Location: solicitudes.php?resultado=aprobada&id={$id}");
exit();
