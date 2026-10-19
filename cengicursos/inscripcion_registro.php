<?php
require_once __DIR__ . '/inscripcion_ingreso.php';

/** Un registro por persona y curso. Bloquea la persona para serializar altas concurrentes.
 * No considera que una asignación previa sea evidencia de pago. El llamador abre transacción.
 */
function cengi_ins_registrar_persona(PDO $db, $personaId, $cursoId, $tipo = 'Ingenio', $cuota = 'asociado')
{
    $q = $db->prepare('SELECT * FROM participantes WHERE id = ? FOR UPDATE');
    $q->execute([(int) $personaId]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new InvalidArgumentException('Participante no encontrado.');
    if (trim((string) $p['cui_participantes']) === '') {
        $p['cui_participantes'] = cengi_cui_nd_generar($db);
        $db->prepare('UPDATE participantes SET cui_participantes=? WHERE id=?')->execute([$p['cui_participantes'],$personaId]);
    }
    $q = $db->prepare('SELECT id FROM cursos WHERE id = ? FOR UPDATE');
    $q->execute([(int) $cursoId]);
    if (!$q->fetchColumn()) throw new InvalidArgumentException('Curso no encontrado.');
    $q = $db->prepare("SELECT id_solicitud FROM solicitudes_inscripcion WHERE curso_id = ? AND cui_participante = ? AND estado <> 'Rechazado' ORDER BY id_solicitud LIMIT 1 FOR UPDATE");
    $q->execute([(int) $cursoId, $p['cui_participantes']]);
    if ($id = $q->fetchColumn()) return (int) $id;
    $q = $db->prepare("SELECT * FROM planificacion_cursos WHERE curso_id = ? AND estado <> 'cancelado' ORDER BY id DESC LIMIT 1");
    $q->execute([(int) $cursoId]);
    $plan = $q->fetch(PDO::FETCH_ASSOC);
    $cuota = isset(CENGI_INS_CUOTAS[$cuota]) ? $cuota : 'asociado';
    $tipo = in_array($tipo, ['Ingenio', 'Propio', 'Exonerado'], true) ? $tipo : 'Ingenio';
    [$monto, $moneda] = $plan ? cengi_ins_monto($plan, $cuota) : [null, 'GTQ'];
    // Sin tarifa conocida no se supone gratuidad.
    if ($plan && $monto <= 0) $tipo = 'Exonerado';
    if ($tipo === 'Exonerado') $monto = 0;
    $grupo = $plan && $tipo === 'Ingenio' ? cengi_ins_grupo_abierto($db, $plan['id'], $p['ingenio_id']) : null;
    $db->prepare("INSERT INTO solicitudes_inscripcion
        (nombre_participante,cui_participante,puesto_participante,area_participante,grado_academico,correo,telefono,ingenio_id,curso_id,planificacion_id,tipo_pago,cuota,monto,moneda,pago_estado,validacion_ingenio,grupo_pago_id,como_se_entero)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$p['nombre_participantes'],$p['cui_participantes'],$p['puesto_participantes'],$p['area_participantes'],$p['grado_academico_participantes'],$p['correo_participantes'],$p['telefono_participantes'],$p['ingenio_id'],$cursoId,$plan['id'] ?? null,$tipo,$cuota,$monto,$moneda,$tipo === 'Exonerado' ? 'exonerado' : 'pendiente',$tipo === 'Ingenio' ? 'pendiente' : null,$grupo,'Registro interno por curso']);
    $id = (int) $db->lastInsertId();
    $db->prepare("UPDATE solicitudes_inscripcion SET origen_inscripcion='registro_interno' WHERE id_solicitud=?")->execute([$id]);
    cengi_ins_normalizar_ingreso($db, $id, 'registro_interno');
    // Conserva las asignaciones históricas; nunca inventa validación ni pago.
    $q = $db->prepare('SELECT id FROM asignaciones WHERE participantes_id = ? AND cursos_id = ? AND estado_asignaciones = 1');
    $q->execute([$personaId,$cursoId]);
    if ($q->fetchColumn()) $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Aprobado', asignado_por = 'Asignación previa; cobro por revisar' WHERE id_solicitud = ?")->execute([$id]);
    return $id;
}

/** Altas nuevas siempre esperan su validación; solo las gratuitas pueden ingresar ya. */
function cengi_ins_alta_curso(PDO $db, $personaId, $cursoId)
{
    $id = cengi_ins_registrar_persona($db, $personaId, $cursoId);
    $s = cengi_ins_solicitud($db, $id, true);
    if ($s['estado'] !== 'Aprobado' && cengi_ins_admitida($s)) {
        if ($s['planificacion_id']) cengi_ins_procesar_plan($db, $s['planificacion_id']);
        else cengi_ins_asignar($db, $s, cengi_pp_usuario_nombre());
    }
    return $id;
}
