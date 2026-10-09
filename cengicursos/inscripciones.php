<?php
/**
 * Gestion de inscripciones de las convocatorias: cupo de cada curso planificado,
 * inscripciones validadas (por el ingenio o por Administracion), asignadas al curso,
 * en lista de espera y rechazadas. La asignacion es automatica al validarse el pago
 * (cengi_ins_procesar_plan); aqui se ve el avance, se da de baja, se rechaza, se
 * reactiva y, si hace falta, se abre el curso antes de completar el cupo minimo.
 * Las solicitudes directas a cursos sin planificacion siguen en solicitudes.php.
 */
require_once __DIR__ . '/inscripcion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_gestor_solicitudes('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_aprobar_solicitudes() || cengi_puede_rechazar_solicitudes();
$puedeAbrirCurso = cengi_puede_gestionar();
$limitado = cengi_ve_todo_por_rol_o_ingenio() ? null : cengi_ingenio_nombre_actual();
$usuario = cengi_pp_usuario_nombre();
$mensaje = '';
$mensajeTipo = 'success';

const CENGI_INSC_MENSAJES = [
    'baja' => 'Participante dado de baja del curso; se le avisó por correo.',
    'rechazada' => 'Inscripción rechazada; se avisó al participante.',
    'reactivada' => 'Inscripción reactivada.',
    'asignada' => 'Participante asignado al curso.',
    'curso' => 'Curso abierto: ya aparece en Cursos y se asignaron las inscripciones validadas.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $aviso = null;
    try {
        if (!$puedeGestionar) {
            throw new InvalidArgumentException('No tienes permiso para gestionar inscripciones.');
        }
        $db->beginTransaction();
        if ($accion === 'abrir_curso') {
            if (!$puedeAbrirCurso) {
                throw new InvalidArgumentException('Solo quien gestiona cursos puede abrir el curso.');
            }
            $plan = cengi_pp_plan($db, (int) ($_POST['plan'] ?? 0));
            if (!$plan || !cengi_pp_convocado($plan) || $plan['curso_id']) {
                throw new InvalidArgumentException('La convocatoria no está abierta o ya tiene curso.');
            }
            cengi_pp_crear_curso($db, $plan, 'abierto manualmente por ' . $usuario);
            $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'curso', 'plan' => $plan];
            cengi_ins_procesar_plan($db, $plan['id'], $usuario);
            $codigo = 'curso';
            $planId = $plan['id'];
        } else {
            $s = cengi_ins_solicitud($db, (int) ($_POST['id'] ?? 0), true);
            if (!$s || !$s['planificacion_id'] || ($limitado !== null && cengi_texto_normalizado($s['nombre_ingenios']) !== cengi_texto_normalizado($limitado))) {
                throw new InvalidArgumentException('La inscripción no existe o no tienes acceso a ella.');
            }
            $planId = $s['planificacion_id'];
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');
            if ($accion === 'baja' || $accion === 'rechazar') {
                if ($motivo === '') {
                    throw new InvalidArgumentException('Escribe el motivo: se le comunica al participante.');
                }
                if ($s['estado'] === 'Aprobado') {
                    cengi_ins_quitar_asignacion($db, $s);
                }
                $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Rechazado', pago_nota = IF(pago_nota = '', ?, pago_nota) WHERE id_solicitud = ?")
                    ->execute(['Inscripción ' . ($accion === 'baja' ? 'dada de baja' : 'rechazada') . ': ' . $motivo, $s['id_solicitud']]);
                cengi_ins_recalcular_grupo($db, $s['grupo_pago_id']);
                $aviso = [$s, ($accion === 'baja' ? 'Baja del curso: ' : 'Inscripción no aceptada: ') . $s['plan_nombre'],
                    ($accion === 'baja' ? 'Te informamos que fuiste dado de baja de ' : 'Tu inscripción a ') . $s['plan_nombre'] . ($accion === 'baja' ? '' : ' no fue aceptada') . ".\nMotivo: " . $motivo];
                $codigo = $accion === 'baja' ? 'baja' : 'rechazada';
            } elseif ($accion === 'reactivar') {
                if ($s['estado'] !== 'Rechazado') {
                    throw new InvalidArgumentException('Solo se reactivan inscripciones rechazadas.');
                }
                $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Pendiente' WHERE id_solicitud = ?")->execute([$s['id_solicitud']]);
                cengi_ins_recalcular_grupo($db, $s['grupo_pago_id']);
                $codigo = 'reactivada';
            } elseif ($accion === 'asignar') {
                // Desde lista de espera: asigna aunque el curso supere el cupo maximo.
                $plan = cengi_pp_plan($db, $planId);
                if ($s['estado'] !== 'Espera' || !cengi_ins_requisito($s)['ok'] || !$plan['curso_id']) {
                    throw new InvalidArgumentException('Solo se asigna desde la lista de espera una inscripción validada con curso abierto.');
                }
                $s['curso_id'] = $plan['curso_id'];
                cengi_ins_asignar($db, $s, $usuario);
                $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'asignado', 'plan' => $plan, 'solicitud' => $s];
                $codigo = 'asignada';
            } else {
                throw new InvalidArgumentException('Acción no válida.');
            }
            cengi_ins_procesar_plan($db, $planId);
        }
        $db->commit();
        cengi_ins_enviar_cola($db);
        if ($aviso) {
            [$s, $asunto, $texto] = $aviso;
            try {
                cengi_pp_enviar_comunicacion($db, ['categoria' => 'Inscripción', 'origen' => 'sistema', 'planificacion_id' => (int) $s['planificacion_id'],
                    'asunto' => $asunto, 'mensaje' => "Hola {nombre}:\n\n" . $texto . "\n\nCoordinación de Capacitación — CENGICAÑA"],
                    [['nombre' => $s['nombre_participante'], 'correo' => $s['correo']]]);
            } catch (Throwable $e) {
                error_log('Inscripciones: no se pudo avisar: ' . $e->getMessage());
            }
        }
        cengi_pp_redirigir('inscripciones.php', ['plan' => $planId, 'msg' => $codigo]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $GLOBALS['cengi_ins_cola'] = [];
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Inscripciones: ' . $e->getMessage());
            $mensaje = 'No fue posible completar la acción.';
        }
    }
}

if ($mensaje === '' && isset(CENGI_INSC_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_INSC_MENSAJES[$_GET['msg']]);
}

// ---------------------------------------------------------------- datos
$planes = $db->query("SELECT p.*, c.codigo_curso,
        (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.planificacion_id = p.id AND s.estado <> 'Rechazado') AS inscritos
    FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE (p.estado IN ('aprobado', 'reprogramado') AND p.fecha_fin >= CURDATE() - INTERVAL 60 DAY)
       OR EXISTS (SELECT 1 FROM solicitudes_inscripcion s WHERE s.planificacion_id = p.id)
    ORDER BY p.fecha_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
$planFiltro = (int) ($_GET['plan'] ?? 0);
$etapaFiltro = array_key_exists($_GET['etapa'] ?? '', CENGI_INS_ETAPAS) || ($_GET['etapa'] ?? '') === 'todas' ? $_GET['etapa'] : '';
$busqueda = trim((string) ($_GET['q'] ?? ''));
$conteo = cengi_pp_conteo_inscritos($db);

$stmt = $db->query("SELECT s.*, i.nombre_ingenios, p.nombre AS plan_nombre, p.fecha_inicio, g.estado AS grupo_estado, g.orden AS grupo_orden
    FROM solicitudes_inscripcion s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    LEFT JOIN ingenios i ON i.id = s.ingenio_id LEFT JOIN pagos_grupales g ON g.id = s.grupo_pago_id"
    . ($planFiltro ? ' WHERE s.planificacion_id = ' . $planFiltro : '') . ' ORDER BY p.fecha_inicio DESC, s.nombre_participante');
$todas = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function ($s) use ($limitado) {
    return $limitado === null || cengi_texto_normalizado($s['nombre_ingenios'] ?? '') === cengi_texto_normalizado($limitado);
}));
// En esta pantalla se gestiona lo validado; lo que aun no se valida esta en Pagos de inscripcion.
$visibles = ['validado', 'asignado', 'espera', 'rechazado'];
$sinValidar = array_filter($todas, static function ($s) use ($visibles) {
    return !in_array(cengi_ins_etapa($s), $visibles, true);
});
$lista = array_values(array_filter($todas, static function ($s) use ($visibles, $etapaFiltro, $busqueda) {
    $etapa = cengi_ins_etapa($s);
    if ($etapaFiltro === 'todas') {
        $pasa = true;
    } elseif ($etapaFiltro !== '') {
        $pasa = $etapa === $etapaFiltro;
    } else {
        $pasa = in_array($etapa, $visibles, true) && $etapa !== 'rechazado';
    }
    if (!$pasa) {
        return false;
    }
    return $busqueda === '' || strpos(cengi_texto_normalizado($s['nombre_participante'] . ' ' . $s['correo'] . ' ' . $s['cui_participante'] . ' ' . $s['nombre_ingenios'] . ' ' . $s['institucion_otra'] . ' ' . $s['telefono']), cengi_texto_normalizado($busqueda)) !== false;
}));
$cuenta = static function ($etapa) use ($todas) {
    return count(array_filter($todas, static function ($s) use ($etapa) {
        return cengi_ins_etapa($s) === $etapa;
    }));
};
$planSel = null;
foreach ($planes as $p) {
    if ((int) $p['id'] === $planFiltro) {
        $planSel = $p;
    }
}
$solicitudesDirectas = (int) $db->query("SELECT COUNT(*) FROM solicitudes_inscripcion WHERE planificacion_id IS NULL AND estado = 'Pendiente'")->fetchColumn();
$filtros = array_filter(['plan' => $planFiltro ?: '', 'etapa' => $etapaFiltro, 'q' => $busqueda]);
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Inscripciones'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <nav class="pp-subnav">
        <a href="inscripciones.php" class="is-active" aria-current="page"><span class="glyphicon glyphicon-inbox"></span>Inscripciones</a>
        <?php if (cengi_ins_puede_ver_pagos()): ?><a href="pagos_inscripcion.php<?php echo $planFiltro ? '?plan=' . $planFiltro . '&anio=' . (int) ($planSel['anio'] ?? cengi_pp_anio_actual()) : ''; ?>"><span class="glyphicon glyphicon-credit-card"></span>Pagos de inscripción</a><?php endif; ?>
        <a href="solicitudes.php"><span class="glyphicon glyphicon-list-alt"></span>Solicitudes directas a cursos<?php echo $solicitudesDirectas ? ' (' . $solicitudesDirectas . ' pendientes)' : ''; ?></a>
        <a href="inscripcion.php" target="_blank" rel="noopener"><span class="glyphicon glyphicon-new-window"></span>Formulario público</a>
    </nav>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <div class="cengi-feedback is-warning" style="margin-bottom:16px;"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div>
        <div><p>Aquí llegan las inscripciones <b>validadas</b> (por su ingenio o por Administración). Cuando las validadas de una convocatoria llegan al <b>cupo mínimo</b>, el curso pasa a <b>Cursos</b> y se asignan solas; después se asignan directo o, si el curso está lleno, pasan a lista de espera. Cada participante asignado recibe la programación vigente por correo.</p></div></div>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi($cuenta('validado'), 'Validadas esperando cupo', 'Cuentan para abrir el curso', '#8A6600', 'hourglass', '#FFF6DA');
        echo cengi_pp_kpi($cuenta('asignado'), 'Asignadas a cursos', '', '#1f5632', 'ok');
        echo cengi_pp_kpi($cuenta('espera'), 'Lista de espera', 'Curso con el cupo máximo lleno', '#B34E00', 'list', '#FFE9D9');
        echo cengi_pp_kpi(count($sinValidar), 'Aún sin validar', 'Se gestionan en Pagos de inscripción', '#5B6459', 'credit-card', '#EDEFEA');
        ?>
    </div>

    <section class="pp-card">
        <form class="pp-toolbar" method="get">
            <div class="pp-toolbar-group">
                <select name="plan" aria-label="Convocatoria" onchange="this.form.submit()">
                    <option value="">Todas las convocatorias</option>
                    <?php foreach ($planes as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo (int) $p['id'] === $planFiltro ? 'selected' : ''; ?>><?php echo cengi_pp_h($p['nombre'] . ' · ' . ($conteo[$p['id']] ?? 0) . '/' . $p['cupo_minimo'] . ($p['curso_id'] ? ' · curso abierto' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="etapa" aria-label="Etapa" onchange="this.form.submit()">
                    <option value="">Validadas, asignadas y en espera</option>
                    <?php foreach (['validado', 'asignado', 'espera', 'rechazado'] as $e): ?><option value="<?php echo $e; ?>" <?php echo $etapaFiltro === $e ? 'selected' : ''; ?>><?php echo CENGI_INS_ETAPAS[$e][0] . ' (' . $cuenta($e) . ')'; ?></option><?php endforeach; ?>
                    <option value="todas" <?php echo $etapaFiltro === 'todas' ? 'selected' : ''; ?>>Todas, incluidas las sin validar</option>
                </select>
                <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Nombre, CUI, correo, teléfono o ingenio..." aria-label="Buscar" style="min-width:260px;">
            </div>
            <span class="pp-sub"><?php echo count($lista); ?> registro(s)</span>
        </form>
        <?php if ($planSel):
            $n = $conteo[$planSel['id']] ?? 0;
            [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($planSel, $n);
        ?>
            <div class="pp-card-body" style="border-top:1px solid #E4E7E1;">
                <div class="pp-mini-row"><span><b><?php echo cengi_pp_h($planSel['nombre']); ?></b> · inicia <?php echo cengi_pp_fecha($planSel['fecha_inicio']); ?> · <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $planSel['estado']); ?></span>
                    <span><?php echo $planSel['curso_id'] ? '<a class="pp-chip is-ok" href="ver_cursos.php?' . cengi_pp_h(http_build_query(['q' => $planSel['codigo_curso'], 'anio' => 'todos'])) . '">Curso abierto: ' . cengi_pp_h($planSel['codigo_curso']) . '</a>' : '<span class="pp-chip is-yellow">Convocatoria abierta, sin curso aún</span>'; ?></span></div>
                <span class="pp-prog" style="margin-top:8px;"><span class="pp-prog-track"><span style="width:<?php echo min(100, (int) round($n * 100 / max(1, $planSel['cupo_minimo']))); ?>%"></span></span>
                    <span class="pp-num"><?php echo $n . ' / ' . (int) $planSel['cupo_minimo'] . ' mín.' . ($planSel['cupo_maximo'] ? ' · máx. ' . (int) $planSel['cupo_maximo'] : ''); ?></span></span>
                <span class="pp-sub"><?php echo cengi_pp_h($planSel['curso_id'] ? $detalle : 'Faltan ' . max(0, $planSel['cupo_minimo'] - $n) . ' inscripción(es) validada(s) para abrir el curso.'); ?></span>
                <?php if (!$planSel['curso_id'] && cengi_pp_convocado($planSel) && $puedeAbrirCurso && $puedeGestionar): ?>
                    <form method="post" style="margin-top:8px;" onsubmit="return confirm('¿Abrir el curso ahora, sin completar el cupo mínimo? Pasará a Cursos y se asignarán las inscripciones validadas.');">
                        <input type="hidden" name="accion" value="abrir_curso"><input type="hidden" name="plan" value="<?php echo (int) $planSel['id']; ?>">
                        <button type="submit" class="btn btn-default btn-sm">Abrir curso ahora</button></form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($sinValidar && $etapaFiltro !== 'todas'): ?>
        <div class="cengi-feedback is-warning"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-credit-card"></span></div><div><p>
            <b><?php echo count($sinValidar); ?> inscripción(es) todavía no están validadas</b>: <?php echo count(array_filter($sinValidar, static function ($s) {
                return $s['tipo_pago'] === 'Ingenio';
            })); ?> esperan la validación de su ingenio y <?php echo count(array_filter($sinValidar, static function ($s) {
                return $s['tipo_pago'] === 'Propio';
            })); ?> la validación del pago por Administración. Aparecerán aquí en cuanto se validen.
            <?php if (cengi_ins_puede_ver_pagos()): ?><a class="pp-link" href="pagos_inscripcion.php?<?php echo cengi_pp_h(http_build_query(array_filter(['plan' => $planFiltro ?: '', 'anio' => $planSel['anio'] ?? '', 'etapa' => 'por-validar']))); ?>">Ir a Pagos de inscripción</a><?php endif; ?>
        </p></div></div>
    <?php endif; ?>

    <section class="cengi-courses-section"><div class="pp-table-wrap">
        <table class="cengi-org-table" style="min-width:960px;">
            <thead><tr><th>Participante</th><th>Curso</th><th>Cuota</th><th>Quién paga · cobro</th><th>Etapa y responsable</th><th class="is-actions"></th></tr></thead>
            <tbody>
                <?php if (!$lista): ?><tr><td colspan="6" class="pp-empty"><?php echo $busqueda !== '' ? 'Nadie coincide con la búsqueda. Revisa también Pagos de inscripción: allí están quienes aún no se validan.' : 'No hay inscripciones validadas con esos filtros.'; ?></td></tr><?php endif; ?>
                <?php foreach ($lista as $s): $etapa = cengi_ins_etapa($s); ?>
                    <tr class="<?php echo $etapa === 'rechazado' ? 'pp-muted-row' : ''; ?>">
                        <td style="min-width:220px;"><strong><?php echo cengi_pp_h($s['nombre_participante']); ?></strong>
                            <span class="pp-sub"><?php echo cengi_pp_h(($s['nombre_ingenios'] ?: $s['institucion_otra']) . ' · ' . $s['correo']); ?></span>
                            <span class="pp-sub"><?php echo cengi_pp_h('CUI ' . $s['cui_participante'] . ' · Tel. ' . $s['telefono']); ?></span></td>
                        <td><?php echo cengi_pp_h($s['plan_nombre']); ?><span class="pp-sub">inicia <?php echo cengi_pp_fecha_corta($s['fecha_inicio']); ?></span></td>
                        <td style="font-size:12px;"><?php echo cengi_pp_h(CENGI_INS_CUOTAS[$s['cuota']][0] ?? '—'); ?><b style="display:block;"><?php echo cengi_ins_dinero($s['monto'], $s['moneda']); ?></b></td>
                        <td style="font-size:12px;"><?php echo ['Ingenio' => 'Su ingenio', 'Exonerado' => 'Sin costo', 'Propio' => 'Pago propio'][$s['tipo_pago']]; ?>
                            <span class="pp-sub"><?php echo cengi_pp_badge(CENGI_INS_ESTADOS_PAGO, cengi_ins_estado_cobro($s)); ?></span></td>
                        <td style="min-width:200px;"><?php echo cengi_pp_badge(CENGI_INS_ETAPAS, $etapa); ?>
                            <span class="pp-sub"><?php
                                $req = cengi_ins_requisito($s);
                                echo cengi_pp_h($etapa === 'asignado' ? 'Validó: ' . ($s['validacion_ingenio_por'] ?: ($s['pago_verificado_por'] ?: 'sin costo')) . ' · Asignó: ' . $s['asignado_por'] . ' · ' . cengi_pp_fecha($s['asignado_en'])
                                    : ($etapa === 'rechazado' ? $s['pago_nota'] : $req['texto']));
                            ?></span></td>
                        <td class="is-actions"><?php if ($puedeGestionar): ?><div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;min-width:150px;">
                            <?php if ($etapa === 'espera'): ?>
                                <form method="post" class="cengi-inline-form" onsubmit="return confirm('El curso tiene el cupo máximo lleno. ¿Asignar de todos modos?');"><input type="hidden" name="accion" value="asignar"><input type="hidden" name="id" value="<?php echo (int) $s['id_solicitud']; ?>"><button type="submit" class="btn btn-primary btn-sm">Asignar</button></form>
                            <?php endif; ?>
                            <?php if ($etapa === 'asignado'): ?>
                                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#motivo-modal" data-accion="baja" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="<?php echo cengi_pp_h('Dar de baja: ' . $s['nombre_participante']); ?>">Dar de baja</button>
                            <?php elseif ($etapa === 'rechazado'): ?>
                                <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?php echo (int) $s['id_solicitud']; ?>"><button type="submit" class="btn btn-default btn-sm">Reactivar</button></form>
                            <?php else: ?>
                                <button type="button" class="cengi-action-btn is-delete" data-toggle="modal" data-target="#motivo-modal" data-accion="rechazar" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="<?php echo cengi_pp_h('Rechazar inscripción: ' . $s['nombre_participante']); ?>" data-tooltip="Rechazar inscripción" aria-label="Rechazar inscripción"><span class="glyphicon glyphicon-remove"></span></button>
                            <?php endif; ?>
                        </div><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></section>
</main>

<div class="modal fade cengi-course-form-modal pp-modal" id="motivo-modal" tabindex="-1" role="dialog" aria-labelledby="motivo-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
        <input type="hidden" name="accion" id="motivo-accion"><input type="hidden" name="id" id="motivo-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><h4 class="modal-title" id="motivo-title"></h4></div>
        <div class="modal-body"><div class="form-group"><label for="motivo-texto">Motivo (se le comunica al participante)</label><input type="text" class="form-control" id="motivo-texto" name="motivo" maxlength="500" required></div>
            <p class="pp-sub">Si el curso tiene lista de espera, entra la primera persona validada.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Confirmar</button></div>
    </form></div></div>
</div>
<script>
$('#motivo-modal').on('show.bs.modal', function (e) {
    var t = e.relatedTarget;
    document.getElementById('motivo-accion').value = t.getAttribute('data-accion');
    document.getElementById('motivo-id').value = t.getAttribute('data-id');
    document.getElementById('motivo-title').textContent = t.getAttribute('data-titulo');
    document.getElementById('motivo-texto').value = '';
});
</script>
</body>
</html>
