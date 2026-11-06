<?php
/**
 * Vista de seguimiento de una idea del buzon (index.php?view=idea&id=N).
 *
 * Flujo en 4 pasos (lo lleva una persona: quien gestiona el buzon):
 *   1. Recepcion: leer la propuesta e iniciar el analisis.
 *   2. Analisis: recursos, costo, responsables, cumplimiento, riesgos y oportunidades.
 *   3. Ponderacion: calificar criterios con peso -> puntaje 0-100 y recomendacion.
 *   4. Decision: aprobar para implementacion, rechazar o archivar, con motivo.
 * En cada cambio de estado se puede avisar por correo a quien envio la idea.
 *
 * Usa las variables que prepara index.php: $pdo, $user, $ideaDetail, $ideaRevision,
 * $ideaResponsables, $ideaAportes (historial), $ideaCandidatos.
 */

$ideaId = (int) $ideaDetail['id'];
$canManageIdeas = can_manage_ideas($user);
$canApproveIdeas = can_approve_ideas($user);
$responsableIds = array_map(static fn(array $r): int => (int) $r['id'], $ideaResponsables);
$canEditReview = can_edit_idea_review($user, $responsableIds);
[$avanceLleno, $avanceTotal] = idea_revision_avance($ideaRevision, $ideaResponsables !== []);
$rev = static fn(string $campo): string => (string) ($ideaRevision[$campo] ?? '');
$estado = (string) $ideaDetail['estado'];
$decidida = in_array($estado, ['aprobada', 'rechazada'], true);
$cerrada = $decidida || $estado === 'archivada';
$requiereAnalisis = (int) ($ideaRevision['requiere_analisis'] ?? 0) === 1;
$notas = idea_ponderacion_notas($ideaRevision);
$puntaje = idea_puntaje($notas);
[$recomendacionClave, $recomendacionTexto] = idea_recomendacion($puntaje);
$calificados = count(array_filter($notas, static fn(?int $n): bool => $n !== null));
$correoRemitente = idea_correo_remitente($ideaDetail);
$correosActivos = mail_enabled($pdo);
$notificacionStmt = $pdo->prepare("SELECT destinatario, estado, error, creado_en FROM correo_registro WHERE tipo = 'idea' AND solicitud_codigo = ? ORDER BY id DESC LIMIT 1");
$notificacionStmt->execute(['IDEA-' . $ideaId]);
$ultimaNotificacion = $notificacionStmt->fetch();

$pasos = [
    'recepcion' => ['Recepcion', $estado !== 'nueva', $estado === 'nueva' ? 'Pendiente de iniciar' : 'Analisis iniciado'],
    'analisis' => ['Analisis', $avanceLleno === $avanceTotal, $avanceLleno . ' de ' . $avanceTotal . ' puntos'],
    'ponderacion' => ['Ponderacion', $puntaje !== null, $puntaje !== null ? $puntaje . '/100 · ' . $recomendacionTexto : $calificados . ' de ' . count(IDEA_CRITERIOS) . ' criterios'],
    'decision' => ['Aprobacion de Direccion General', $cerrada, $cerrada ? BUZON_ESTADOS[$estado] : 'Pendiente'],
    'notificacion' => ['Notificacion', false, 'Comunicar el resultado a quien propone'],
];
$pasoActual = 'decision';
if (!$cerrada) {
    foreach ($pasos as $clave => [, $hecho]) {
        if (!$hecho) {
            $pasoActual = $clave;
            break;
        }
    }
}
$numeroPaso = array_flip(array_keys($pasos));

$siguiente = match (true) {
    $estado === 'aprobada' => ['ti-circle-check', 'La idea fue aprobada para implementacion. Coordina con los responsables de seguimiento.'],
    $estado === 'rechazada' => ['ti-circle-x', 'La idea fue rechazada. El motivo quedo registrado en el paso 4.'],
    $estado === 'archivada' => ['ti-archive', 'La idea esta archivada. Puedes reabrir el analisis desde el paso 4.'],
    !$canEditReview => ['ti-eye', 'Estas consultando el seguimiento. La revision la realiza quien gestiona el buzon de ideas.'],
    $pasoActual === 'recepcion' => ['ti-player-play', 'Lee la propuesta e inicia el analisis.' . ($correoRemitente !== '' ? ' Se avisara a quien la envio.' : '')],
    $pasoActual === 'analisis' => ['ti-checklist', 'Completa el analisis: que se necesita, que se debe cumplir, y sus riesgos y oportunidades.'],
    $pasoActual === 'ponderacion' => ['ti-scale', 'Califica la idea en cada criterio para obtener su puntaje y saber si conviene implementarla.'],
    default => ['ti-gavel', 'La idea ya tiene analisis y puntaje. Toma la decision.'],
};

$gruposFicha = [
    'necesidades' => ['Que se necesita', 'Lo que hace falta para llevar la idea a la practica y quien la acompana.', 'ti-package', ['recursos']],
    'cumplimiento' => ['Que se debe cumplir', 'Obligaciones y condiciones antes o durante la implementacion.', 'ti-shield-check', ['implicaciones_legales', 'implicaciones_reglamentarias', 'implicaciones_organizacionales', 'requisitos']],
    'balance' => ['Riesgos y oportunidades', 'Lo que podria salir mal frente a lo que se puede ganar.', 'ti-scale', ['riesgos', 'oportunidades']],
];

$valorLectura = static fn(string $valor, string $vacio = 'Sin completar'): string => $valor !== ''
    ? nl2br(e($valor))
    : '<span class="idea-empty">' . e($vacio) . '</span>';

/** Casilla "avisar a quien envio la idea" (o el motivo por el que no se puede). */
$campoAviso = static function () use ($correoRemitente, $correosActivos, $ideaDetail): void {
    if ($correoRemitente === '') {
        $motivo = (int) $ideaDetail['anonimo'] === 1 ? 'la idea se envio de forma anonima' : 'no dejo correo';
        echo '<div class="idea-aviso is-off"><i class="ti ti-mail-off"></i>No se puede avisar a quien envio la idea: ' . e($motivo) . '.</div>';

        return;
    }
    if (!$correosActivos) {
        echo '<div class="idea-aviso is-off"><i class="ti ti-mail-off"></i>Los avisos por correo estan apagados (vista Correos).</div>';

        return;
    }
    echo '<label class="idea-aviso"><input type="checkbox" name="notificar" value="1" checked>'
        . '<span><i class="ti ti-mail"></i>Avisar por correo a <strong>' . e($correoRemitente) . '</strong></span></label>';
};

$abrirPaso = static function (string $clave) use ($pasos, $pasoActual, $numeroPaso): void {
    [$titulo, $hecho, $resumen] = $pasos[$clave];
    $n = $numeroPaso[$clave] + 1;
    $abierto = $clave === $pasoActual || !$hecho;
    echo '<details class="idea-step' . ($hecho ? ' is-done' : '') . ($clave === $pasoActual ? ' is-current' : '') . '" id="paso-' . e($clave) . '"' . ($abierto ? ' open' : '') . '>';
    echo '<summary><span class="idea-step-num">' . ($hecho ? '<i class="ti ti-check"></i>' : $n) . '</span>';
    echo '<span class="idea-step-head"><strong>Paso ' . $n . ' · ' . e($titulo) . '</strong><small>' . e($resumen) . '</small></span>';
    echo '<i class="ti ti-chevron-down idea-step-chevron"></i></summary><div class="idea-step-body">';
};
?>
<div class="section-header">
    <div>
        <div class="section-title">Idea #<?= $ideaId ?> · <?= e($ideaDetail['area']) ?><?= $ideaDetail['area_otra'] ? ': ' . e($ideaDetail['area_otra']) : '' ?></div>
        <div class="section-sub"><?= badge_idea_estado($estado) ?> <?= badge_idea_puntaje($puntaje) ?> Recibida el <?= e(date('d/m/Y H:i', strtotime((string) $ideaDetail['creado_en']))) ?></div>
    </div>
    <a class="btn-outline" href="index.php?view=buzon#idea-<?= $ideaId ?>"><i class="ti ti-arrow-left"></i>Volver al buzon</a>
</div>
<nav class="idea-decision-buttons" aria-label="Acciones de la propuesta">
    <?php if ($canEditReview): ?><a class="btn-outline" href="#paso-analisis">Análisis</a><?php endif; ?>
    <?php if ($canApproveIdeas): ?><a class="btn-outline" href="#paso-decision">Aprobación</a><?php endif; ?>
    <?php if (($canManageIdeas || $canApproveIdeas) && $cerrada && $correoRemitente !== ''): ?><a class="btn-outline" href="#paso-notificacion">Notificación</a><?php endif; ?>
</nav>

<nav class="idea-stepper" aria-label="Pasos del seguimiento">
    <?php foreach ($pasos as $clave => [$titulo, $hecho, $resumen]): ?>
        <?php $clase = $hecho ? 'is-done' : ($clave === $pasoActual ? 'is-current' : ''); ?>
        <a class="idea-step-link <?= $clase ?>" href="#paso-<?= e($clave) ?>" <?= $clave === $pasoActual ? 'aria-current="step"' : '' ?>>
            <span class="idea-step-dot"><?= $hecho ? '<i class="ti ti-check"></i>' : $numeroPaso[$clave] + 1 ?></span>
            <span class="idea-step-text"><strong><?= e($titulo) ?></strong><small><?= e($resumen) ?></small></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="idea-next">
    <i class="ti <?= e($siguiente[0]) ?>"></i>
    <div><strong>Siguiente paso</strong><span><?= e($siguiente[1]) ?></span></div>
</div>

<?php /* ---------------- Paso 1: Recepcion ---------------- */ ?>
<?php $abrirPaso('recepcion'); ?>
    <p class="idea-step-help">La propuesta tal como llego del formulario publico.</p>
    <div class="idea-recepcion">
        <div class="idea-propuesta">
            <dl class="idea-card-body">
                <dt>Situacion o problema</dt>
                <dd><?= nl2br(e($ideaDetail['situacion'])) ?></dd>
                <dt>Propuesta de mejora</dt>
                <dd><?= nl2br(e($ideaDetail['propuesta'])) ?></dd>
                <dt>Beneficio esperado</dt>
                <dd><?= nl2br(e($ideaDetail['beneficio'])) ?></dd>
            </dl>
            <?php if ($ideaDetail['foto_ruta']): ?>
                <a class="idea-photo" href="buzon_foto.php?id=<?= $ideaId ?>" target="_blank" rel="noopener">
                    <img src="buzon_foto.php?id=<?= $ideaId ?>" alt="Foto adjunta a la idea #<?= $ideaId ?>" loading="lazy">
                </a>
            <?php endif; ?>
        </div>
        <div class="idea-remitente">
            <div class="idea-sub-title"><i class="ti ti-user"></i>Quien la envio</div>
            <?php if ((int) $ideaDetail['anonimo'] === 1): ?>
                <p><i class="ti ti-user-off"></i> Envio anonimo</p>
            <?php else: ?>
                <p><strong><?= e($ideaDetail['nombre'] ?: 'Sin nombre') ?></strong></p>
                <?php if ($ideaDetail['correo']): ?>
                    <p><i class="ti ti-mail"></i> <a href="mailto:<?= e($ideaDetail['correo']) ?>"><?= e($ideaDetail['correo']) ?></a></p>
                <?php endif; ?>
            <?php endif; ?>
            <p class="idea-remitente-note">
                <?= $correoRemitente !== ''
                    ? '<i class="ti ti-bell"></i> Recibira un correo en cada etapa: recibida, en analisis y decision.'
                    : '<i class="ti ti-bell-off"></i> No recibira avisos: no hay un correo al cual escribirle.' ?>
            </p>
        </div>
    </div>

    <?php if ($estado === 'nueva' && $canManageIdeas): ?>
        <form method="post" action="index.php?view=idea&id=<?= $ideaId ?>" class="idea-step-actions"><?= csrf_field() ?>
            <input type="hidden" name="action" value="iniciar_analisis_idea">
            <input type="hidden" name="idea_id" value="<?= $ideaId ?>">
            <?php $campoAviso(); ?>
            <button class="btn-primary" type="submit"><i class="ti ti-player-play"></i>Iniciar analisis</button>
        </form>
    <?php endif; ?>
</div></details>

<?php /* ---------------- Paso 2: Analisis ---------------- */ ?>
<?php $abrirPaso('analisis'); ?>
    <p class="idea-step-help">Responde cada bloque con lo que se sabe de la idea. Los bloques llenos se marcan en verde.</p>
    <div class="idea-progress">
        <span class="idea-progress-bar"><span style="width:<?= (int) round($avanceLleno * 100 / $avanceTotal) ?>%"></span></span>
        <?= $avanceLleno ?> de <?= $avanceTotal ?> puntos completos
        <?php if ($ideaRevision && $ideaRevision['actualizado_por_nombre']): ?>
            <span class="timeline-meta">· ultima edicion: <?= e($ideaRevision['actualizado_por_nombre']) ?>, <?= e(date('d/m/Y H:i', strtotime((string) $ideaRevision['actualizado_en']))) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($canEditReview): ?>
        <form method="post" action="index.php?view=idea&id=<?= $ideaId ?>"><?= csrf_field() ?>
            <input type="hidden" name="action" value="guardar_revision_idea">
            <input type="hidden" name="idea_id" value="<?= $ideaId ?>">
    <?php else: ?>
        <div>
    <?php endif; ?>

    <?php $letra = 'A'; ?>
    <?php foreach ($gruposFicha as $grupo => [$grupoTitulo, $grupoAyuda, $grupoIcono, $grupoCampos]): ?>
        <fieldset class="idea-group">
            <legend><span class="idea-group-letter"><?= $letra++ ?></span><i class="ti <?= e($grupoIcono) ?>"></i><?= e($grupoTitulo) ?></legend>
            <p class="idea-group-help"><?= e($grupoAyuda) ?></p>
            <div class="idea-group-fields">
                <?php foreach ($grupoCampos as $campo): ?>
                    <?php [$titulo, $ayuda, $icono] = IDEA_REVISION_CAMPOS[$campo]; ?>
                    <div class="form-group idea-field <?= $rev($campo) !== '' ? 'is-filled' : '' ?>">
                        <label class="form-label" for="rev-<?= e($campo) ?>"><i class="ti <?= e($icono) ?>"></i> <?= e($titulo) ?></label>
                        <?php if ($canEditReview): ?>
                            <textarea class="form-control" id="rev-<?= e($campo) ?>" name="<?= e($campo) ?>" rows="3" maxlength="<?= IDEA_TEXTO_MAX ?>" placeholder="<?= e($ayuda) ?>"><?= e($rev($campo)) ?></textarea>
                        <?php else: ?>
                            <div class="detail-value"><?= $valorLectura($rev($campo)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($grupo === 'necesidades'): ?>
                    <div class="form-group idea-field form-full <?= $rev('costo_estimado') !== '' || $rev('costo_detalle') !== '' ? 'is-filled' : '' ?>">
                        <?php include __DIR__ . '/idea-costos.php'; ?>
                        <label class="form-label" for="rev-costo_estimado"><i class="ti ti-coin"></i> Costo de implementacion</label>
                        <?php if ($canEditReview): ?>
                            <div class="idea-money"><span>Q</span><input class="form-control" id="rev-costo_estimado" name="costo_estimado" inputmode="decimal" placeholder="0.00" value="<?= e($rev('costo_estimado')) ?>"></div>
                            <textarea class="form-control" name="costo_detalle" rows="2" maxlength="<?= IDEA_TEXTO_MAX ?>" placeholder="Desglose: compras, horas de trabajo, servicios..."><?= e($rev('costo_detalle')) ?></textarea>
                        <?php else: ?>
                            <div class="detail-value">
                                <?= $rev('costo_estimado') !== '' ? '<strong>Q ' . e(number_format((float) $rev('costo_estimado'), 2)) . '</strong>' : '<span class="idea-empty">Sin estimar</span>' ?>
                                <?php if ($rev('costo_detalle') !== ''): ?><br><?= nl2br(e($rev('costo_detalle'))) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group idea-field form-full <?= $ideaResponsables ? 'is-filled' : '' ?>">
                        <span class="form-label"><i class="ti ti-users"></i> Responsables de seguimiento</span>
                        <?php if ($canManageIdeas): ?>
                            <input type="hidden" name="responsables_enviados" value="1">
                            <input class="form-control" type="search" placeholder="Buscar usuario..." oninput="filterIdeaResponsables(this)" aria-label="Buscar usuario">
                            <div class="idea-responsables-list">
                                <?php foreach ($ideaCandidatos as $candidato): ?>
                                    <label class="permission-option">
                                        <input type="checkbox" name="responsables[]" value="<?= (int) $candidato['id'] ?>" <?= in_array((int) $candidato['id'], $responsableIds, true) ? 'checked' : '' ?>>
                                        <span><?= e($candidato['nombre']) ?><?php if ($candidato['programa']): ?> <small><?= e($candidato['programa']) ?></small><?php endif; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($ideaResponsables): ?>
                            <div class="detail-value"><?= e(implode(', ', array_column($ideaResponsables, 'nombre'))) ?></div>
                        <?php else: ?>
                            <div class="detail-value"><span class="idea-empty">Sin asignar</span></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </fieldset>
    <?php endforeach; ?>

    <fieldset class="idea-group">
        <legend><span class="idea-group-letter"><?= $letra ?></span><i class="ti ti-microscope"></i>Conclusion del analisis</legend>
        <p class="idea-group-help">¿Hay informacion suficiente para decidir o falta estudiar algo mas (prueba piloto, cotizaciones, consulta legal...)?</p>
        <?php if ($canEditReview): ?>
            <div class="idea-choice">
                <label><input type="radio" name="requiere_analisis" value="" <?= !$requiereAnalisis ? 'checked' : '' ?>> <span><i class="ti ti-circle-check"></i>Lista para ponderar</span></label>
                <label><input type="radio" name="requiere_analisis" value="1" <?= $requiereAnalisis ? 'checked' : '' ?>> <span><i class="ti ti-microscope"></i>Requiere analisis adicional</span></label>
            </div>
            <textarea class="form-control" name="analisis_detalle" rows="2" maxlength="<?= IDEA_TEXTO_MAX ?>" placeholder="Si falta algo: que analisis, quien lo hace y para cuando."><?= e($rev('analisis_detalle')) ?></textarea>
        <?php else: ?>
            <div class="detail-value">
                <strong><?= $requiereAnalisis ? 'Requiere analisis adicional' : 'Lista para ponderar' ?></strong>
                <?php if ($rev('analisis_detalle') !== ''): ?><br><?= nl2br(e($rev('analisis_detalle'))) ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </fieldset>

    <?php if ($canEditReview): ?>
            <div class="actions">
                <button class="btn-primary" type="submit"><i class="ti ti-device-floppy"></i>Guardar analisis</button>
            </div>
        </form>
    <?php else: ?>
        </div>
    <?php endif; ?>
</div></details>

<?php /* ---------------- Paso 3: Ponderacion ---------------- */ ?>
<?php $abrirPaso('ponderacion'); ?>
    <p class="idea-step-help">
        Califica la idea del 1 al 5 en cada criterio. Cada criterio pesa distinto; el resultado es un puntaje de 0 a 100 que permite
        comparar las ideas entre si. Desde <?= IDEA_PUNTAJE_RECOMENDADA ?> puntos la idea se recomienda para implementacion;
        entre <?= IDEA_PUNTAJE_RESERVAS ?> y <?= IDEA_PUNTAJE_RECOMENDADA - 1 ?>, con reservas.
    </p>

    <?php if ($canEditReview): ?>
        <form method="post" action="index.php?view=idea&id=<?= $ideaId ?>" id="form-ponderacion"><?= csrf_field() ?>
            <input type="hidden" name="action" value="guardar_ponderacion_idea">
            <input type="hidden" name="idea_id" value="<?= $ideaId ?>">
    <?php else: ?>
        <div>
    <?php endif; ?>

    <div class="idea-criterios">
        <?php foreach (IDEA_CRITERIOS as $criterio => [$cTitulo, $cPregunta, $cPeso, $cEtiquetas]): ?>
            <div class="idea-criterio" data-peso="<?= (int) $cPeso ?>">
                <div class="idea-criterio-head">
                    <strong><?= e($cTitulo) ?></strong>
                    <span class="idea-peso"><?= (int) $cPeso ?>%</span>
                </div>
                <p><?= e($cPregunta) ?></p>
                <div class="idea-escala" role="radiogroup" aria-label="<?= e($cTitulo) ?>">
                    <?php foreach ($cEtiquetas as $i => $etiqueta): ?>
                        <?php $valor = $i + 1; ?>
                        <label>
                            <input type="radio" name="pond_<?= e($criterio) ?>" value="<?= $valor ?>" <?= $notas[$criterio] === $valor ? 'checked' : '' ?> <?= $canEditReview ? '' : 'disabled' ?>>
                            <span><b><?= $valor ?></b><small><?= e($etiqueta) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="idea-score-live is-<?= e($recomendacionClave) ?>" id="puntaje-vivo"
         data-alta="<?= IDEA_PUNTAJE_RECOMENDADA ?>" data-media="<?= IDEA_PUNTAJE_RESERVAS ?>">
        <div class="idea-score-num"><strong><?= $puntaje !== null ? e((string) $puntaje) : '—' ?></strong><span>/100</span></div>
        <div class="idea-score-meter"><span style="width:<?= (int) round($puntaje ?? 0) ?>%"></span></div>
        <div class="idea-score-label"><?= e($recomendacionTexto) ?></div>
    </div>

    <div class="form-group" style="margin-top:12px">
        <label class="form-label" for="pond-comentario">Comentario de la ponderacion</label>
        <?php if ($canEditReview): ?>
            <textarea class="form-control" id="pond-comentario" name="pond_comentario" rows="2" maxlength="<?= IDEA_TEXTO_MAX ?>" placeholder="Justifica las calificaciones mas importantes."><?= e($rev('pond_comentario')) ?></textarea>
        <?php else: ?>
            <div class="detail-value"><?= $valorLectura($rev('pond_comentario'), 'Sin comentario') ?></div>
        <?php endif; ?>
    </div>

    <?php if ($canEditReview): ?>
            <div class="actions">
                <button class="btn-primary" type="submit"><i class="ti ti-device-floppy"></i>Guardar ponderacion</button>
            </div>
        </form>
    <?php else: ?>
        </div>
    <?php endif; ?>
</div></details>

<?php /* ---------------- Paso 4: Decision ---------------- */ ?>
<?php $abrirPaso('decision'); ?>
    <p class="idea-step-help">Con el analisis y el puntaje a la vista se decide si la idea pasa a implementacion. El motivo se guarda y se incluye en el aviso a quien la envio.</p>

    <?php
    $cumplimientoLleno = count(array_filter(
        ['implicaciones_legales', 'implicaciones_reglamentarias', 'implicaciones_organizacionales', 'requisitos'],
        static fn(string $c): bool => $rev($c) !== ''
    ));
    $pendientes = [];
    if ($avanceLleno < $avanceTotal) {
        $pendientes[] = 'El analisis tiene ' . ($avanceTotal - $avanceLleno) . ' punto(s) sin completar.';
    }
    if ($puntaje === null) {
        $pendientes[] = 'Falta completar la ponderacion (' . $calificados . ' de ' . count(IDEA_CRITERIOS) . ' criterios).';
    }
    if ($requiereAnalisis) {
        $pendientes[] = 'Se indico que requiere analisis adicional' . ($rev('analisis_detalle') !== '' ? ': ' . $rev('analisis_detalle') : '.');
    }
    ?>
    <div class="idea-resumen">
        <div class="idea-resumen-score is-<?= e($recomendacionClave) ?>"><span>Puntaje</span><strong><?= $puntaje !== null ? e((string) $puntaje) . '/100' : '—' ?></strong><small><?= e($recomendacionTexto) ?></small></div>
        <div><span>Costo estimado</span><strong><?= $rev('costo_estimado') !== '' ? 'Q ' . e(number_format((float) $rev('costo_estimado'), 2)) : '—' ?></strong></div>
        <div><span>Cumplimiento</span><strong><?= $cumplimientoLleno ?>/4 revisados</strong></div>
        <div><span>Responsables</span><strong><?= $ideaResponsables ? count($ideaResponsables) : 'Sin asignar' ?></strong></div>
    </div>

    <?php if ($cerrada && $ideaRevision && $ideaRevision['decidido_en']): ?>
        <div class="idea-veredicto is-<?= e($estado) ?>">
            <i class="ti <?= $estado === 'aprobada' ? 'ti-circle-check' : 'ti-circle-x' ?>"></i>
            <div>
                <strong><?= e(BUZON_ESTADOS[$estado]) ?></strong>
                <span class="timeline-meta"><?= e($ideaRevision['decidido_por_nombre'] ?: 'Usuario') ?> · <?= e(date('d/m/Y H:i', strtotime((string) $ideaRevision['decidido_en']))) ?></span>
                <?php if ($ideaRevision['decision_motivo']): ?><p><?= nl2br(e($ideaRevision['decision_motivo'])) ?></p><?php endif; ?>
            </div>
        </div>
    <?php elseif ($pendientes && $canApproveIdeas && !$cerrada): ?>
        <div class="readonly-note idea-pendientes">
            <i class="ti ti-alert-triangle"></i>
            <div>Antes de decidir, ten en cuenta:<ul><?php foreach ($pendientes as $pendiente): ?><li><?= e($pendiente) ?></li><?php endforeach; ?></ul></div>
        </div>
    <?php endif; ?>

    <?php if ($canApproveIdeas): ?>
        <form method="post" action="index.php?view=idea&id=<?= $ideaId ?>" class="idea-decision-form"><?= csrf_field() ?>
            <input type="hidden" name="action" value="decidir_idea">
            <input type="hidden" name="idea_id" value="<?= $ideaId ?>">
            <label class="form-label" for="decision-motivo"><?= $cerrada ? 'Cambiar la decision: motivo' : 'Motivo o acuerdo' ?></label>
            <textarea class="form-control" id="decision-motivo" name="motivo" rows="3" maxlength="<?= IDEA_TEXTO_MAX ?>" required placeholder="Motivo obligatorio para aprobar, rechazar o archivar. Se incluira en la notificacion."></textarea>
            <div class="idea-decision-buttons">
                <?php if ($estado !== 'aprobada'): ?>
                    <button class="btn-primary" type="submit" name="estado" value="aprobada"><i class="ti ti-circle-check"></i>Aprobar para implementacion</button>
                <?php endif; ?>
                <?php if ($estado !== 'rechazada'): ?>
                    <button class="btn-outline idea-btn-reject" type="submit" name="estado" value="rechazada"><i class="ti ti-circle-x"></i>Rechazar</button>
                <?php endif; ?>
                <?php if ($cerrada): ?>
                    <button class="btn-outline" type="submit" name="estado" value="en_analisis"><i class="ti ti-arrow-back-up"></i>Reabrir analisis</button>
                <?php endif; ?>
                <?php if ($estado !== 'archivada'): ?>
                    <button class="btn-outline" type="submit" name="estado" value="archivada"><i class="ti ti-archive"></i>Archivar</button>
                <?php endif; ?>
            </div>
        </form>
    <?php elseif (!$cerrada): ?>
        <div class="idea-empty">La decision la toma Direccion General con base en el analisis y el puntaje.</div>
    <?php endif; ?>
</div></details>

<?php include __DIR__ . '/idea-notificacion.php'; ?>

<?php if ($ideaAportes): ?>
    <details class="idea-historial">
        <summary><i class="ti ti-history"></i>Historial de la idea (<?= count($ideaAportes) ?>)</summary>
        <div class="timeline">
            <?php foreach ($ideaAportes as $evento): ?>
                <div class="timeline-item">
                    <div class="timeline-title"><?= (int) $evento['usuario_id'] === 0 ? 'Sistema' : e($evento['nombre']) ?></div>
                    <div class="timeline-meta"><?= e(date('d/m/Y H:i', strtotime((string) $evento['creado_en']))) ?></div>
                    <div class="timeline-obs"><?= nl2br(e($evento['texto'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>
