<?php
// Vista de Configuración > Notificaciones por correo (datos en notificaciones-correo-controlador.php).
$ncPuede = can_manage_resource($user, 'configuracion');
$ncTab = ($_GET['tab'] ?? '') === 'historial' ? 'historial' : 'eventos';
$ncGrupos = [];
foreach ($correoCfg as $clave => $c) {
    $ncGrupos[$c['grupo']][$clave] = $c;
}
$ncActivos = count(array_filter($correoCfg, static fn($c) => $c['activo_efectivo']));
$ncSinDestino = array_filter($correoCfg, static fn($c) => $c['activo_efectivo'] && !$c['auto'] && !$c['destinatarios']);
$ncEstados = ['pendiente' => ['En cola', 'pend'], 'enviando' => ['Enviando', 'pend'], 'enviado' => ['Enviado', 'ok'], 'error' => ['Error', 'err']];
$ncDis = $ncPuede ? '' : 'disabled';
?>
<link rel="stylesheet" href="assets/css/notificaciones-correo.css?v=<?= @filemtime(__DIR__ . '/../assets/css/notificaciones-correo.css') ?>">
<section class="nc-page">
    <div class="nc-status">
        <div class="nc-status-item <?= $correoSmtp ? 'ok' : 'warn' ?>">
            <i class="ti <?= $correoSmtp ? 'ti-server-2' : 'ti-server-off' ?>"></i>
            <span><strong>Servidor de correo</strong><small><?= $correoSmtp ? 'SMTP configurado' : 'Sin configurar: los correos esperan en la cola' ?></small></span>
        </div>
        <div class="nc-status-item <?= $correoAjustes['activo'] ? 'ok' : 'off' ?>">
            <i class="ti <?= $correoAjustes['activo'] ? 'ti-mail-check' : 'ti-mail-off' ?>"></i>
            <span><strong><?= $correoAjustes['activo'] ? 'Envío activo' : 'Envío pausado' ?></strong><small><?= $ncActivos ?> de <?= count($correoCfg) ?> eventos activos</small></span>
        </div>
        <div class="nc-status-item <?= $correoAjustes['redirigir'] ? 'warn' : 'ok' ?>">
            <i class="ti <?= $correoAjustes['redirigir'] ? 'ti-flask' : 'ti-users' ?>"></i>
            <span><strong><?= $correoAjustes['redirigir'] ? 'Modo prueba' : 'Destinatarios reales' ?></strong><small><?= $correoAjustes['redirigir'] ? 'Todo se envía a ' . e($correoAjustes['redirigir']) : 'Cada correo llega a su destinatario' ?></small></span>
        </div>
        <div class="nc-status-item <?= $correoConteo['error'] ? 'warn' : 'ok' ?>">
            <i class="ti ti-inbox"></i>
            <span><strong><?= $correoConteo['pendiente'] + $correoConteo['enviando'] ?> en cola · <?= $correoConteo['error'] ?> con error</strong><small><?= $correoConteo['enviado'] ?> enviados en total</small></span>
        </div>
    </div>

    <?php if ($ncSinDestino): ?>
        <div class="nc-alert"><i class="ti ti-alert-triangle"></i> <span><?= count($ncSinDestino) ?> evento(s) activos no tienen destinatarios y no enviarán correos: <?= e(implode(', ', array_column($ncSinDestino, 'nombre'))) ?>.</span></div>
    <?php endif; ?>

    <nav class="nc-tabs">
        <a href="index.php?view=notificaciones" class="<?= $ncTab === 'eventos' ? 'is-active' : '' ?>"><i class="ti ti-bell-cog"></i> Eventos y mensajes</a>
        <a href="index.php?view=notificaciones&tab=historial" class="<?= $ncTab === 'historial' ? 'is-active' : '' ?>"><i class="ti ti-history"></i> Historial de envíos <span class="nc-count"><?= array_sum($correoConteo) ?></span></a>
    </nav>

    <?php if ($ncTab === 'eventos'): ?>
        <form method="post" id="nc-prueba"><input type="hidden" name="accion" value="correo_prueba"><input type="hidden" name="csrf" value="<?= e($_SESSION['correo_csrf']) ?>"></form>
        <form method="post" class="nc-form">
            <input type="hidden" name="accion" value="correo_guardar">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['correo_csrf']) ?>">

            <div class="nc-card nc-general">
                <h3><i class="ti ti-adjustments"></i> Ajustes generales</h3>
                <div class="nc-general-grid">
                    <label class="nc-switch"><input type="checkbox" name="activo_general" value="1" <?= $correoAjustes['activo'] ? 'checked' : '' ?> <?= $ncDis ?>><span></span><b>Enviar notificaciones por correo</b><small>Desactívalo para pausar todos los envíos sin perder la configuración.</small></label>
                    <label class="nc-field">Modo prueba: enviar todo a<input type="email" name="redirigir" value="<?= e($correoAjustes['redirigir']) ?>" placeholder="Vacío = destinatarios reales" <?= $ncDis ?>><small>Útil para revisar los mensajes antes de activarlos para todos.</small></label>
                    <label class="nc-field">Dirección del sistema para los enlaces<input type="url" name="url_base" value="<?= e($correoAjustes['url_base']) ?>" placeholder="https://servidor/comites" <?= $ncDis ?>><small>Se usa en el botón «Abrir en el sistema» de cada correo.</small></label>
                </div>
            </div>

            <?php foreach ($ncGrupos as $grupo => $eventos): ?>
                <section class="nc-group">
                    <h3><?= e($grupo) ?> <span class="nc-count"><?= count(array_filter($eventos, static fn($c) => $c['activo_efectivo'])) ?>/<?= count($eventos) ?></span></h3>
                    <?php foreach ($eventos as $clave => $c): $campo = 'eventos[' . $clave . ']'; ?>
                        <details class="nc-event <?= $c['activo_efectivo'] ? '' : 'is-off' ?>">
                            <summary>
                                <label class="nc-switch" onclick="event.stopPropagation()"><input type="checkbox" name="<?= $campo ?>[activo]" value="1" <?= $c['activo_efectivo'] ? 'checked' : '' ?> <?= $ncDis ?> aria-label="Activar <?= e($c['nombre']) ?>"><span></span></label>
                                <span class="nc-event-main">
                                    <strong><?= e($c['nombre']) ?></strong>
                                    <small><i class="ti ti-bolt"></i> <?= e($c['cuando']) ?></small>
                                </span>
                                <span class="nc-event-meta">
                                    <?php if ($c['dias_efectivo'] !== null): ?><span class="nc-chip time"><i class="ti ti-clock"></i> <?= (int) $c['dias_efectivo'] ?> día(s) antes</span><?php endif; ?>
                                    <span class="nc-chip <?= $c['activo_efectivo'] && !$c['auto'] && !$c['destinatarios'] ? 'warn' : '' ?>"><i class="ti ti-user"></i> <?= e($c['para']) ?><?= !$c['auto'] ? ' · ' . count($c['destinatarios']) : '' ?></span>
                                    <?php if ($c['personalizado']): ?><span class="nc-chip edit"><i class="ti ti-pencil"></i> Personalizado</span><?php endif; ?>
                                    <i class="ti ti-chevron-down nc-caret"></i>
                                </span>
                            </summary>
                            <div class="nc-event-body">
                                <div class="nc-event-grid">
                                    <div class="nc-message">
                                        <label class="nc-field">Asunto<input type="text" name="<?= $campo ?>[asunto]" value="<?= e($c['asunto_efectivo']) ?>" maxlength="250" <?= $ncDis ?>></label>
                                        <label class="nc-field">Mensaje<textarea name="<?= $campo ?>[cuerpo]" rows="7" maxlength="5000" <?= $ncDis ?>><?= e($c['cuerpo_efectivo']) ?></textarea></label>
                                        <div class="nc-vars"><span>Insertar:</span>
                                            <?php foreach (['nombre' => 'Nombre del destinatario'] + $c['vars'] as $var => $desc): ?>
                                                <button type="button" class="nc-var" data-var="{<?= e($var) ?>}" title="<?= e($desc) ?>" <?= $ncDis ?>>{<?= e($var) ?>}</button>
                                            <?php endforeach; ?>
                                        </div>
                                        <small class="nc-help">El correo incluye el encabezado institucional y un botón «Abrir en el sistema». Deja el texto como está para usar el mensaje recomendado.</small>
                                    </div>
                                    <div class="nc-recipients">
                                        <div class="nc-to"><span>Destinatario</span><strong><?= e($c['para']) ?></strong>
                                            <small><?= $c['auto'] ? 'El sistema lo identifica automáticamente.' : 'Este rol no es una persona del registro: escribe sus correos abajo.' ?></small></div>
                                        <label class="nc-field"><?= $c['auto'] ? 'Enviar copia también a' : 'Correos de destinatarios' ?>
                                            <textarea name="<?= $campo ?>[destinatarios]" rows="3" placeholder="correo@cengicana.org, otro@cengicana.org" <?= $ncDis ?>><?= e(implode(', ', $c['destinatarios'])) ?></textarea></label>
                                        <?php if ($c['dias_efectivo'] !== null): ?>
                                            <label class="nc-field">Enviar con anticipación de<span class="nc-days"><input type="number" name="<?= $campo ?>[dias]" value="<?= (int) $c['dias_efectivo'] ?>" min="0" max="60" <?= $ncDis ?>> días</span><small>Se envía una sola vez por plazo, el día que se cumple la anticipación o después.</small></label>
                                        <?php endif; ?>
                                        <?php if ($ncPuede): ?>
                                            <button type="submit" form="nc-prueba" name="evento" value="<?= e($clave) ?>" class="btn-outline nc-test" title="Envía este mensaje con datos de ejemplo a <?= e((string) ($user['correo'] ?? '')) ?>. Guarda primero si hiciste cambios."><i class="ti ti-send"></i> Enviarme una prueba</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($ncPuede): ?>
                <div class="nc-savebar"><span><i class="ti ti-info-circle"></i> Los cambios aplican a los próximos eventos; lo que ya está en la cola no cambia.</span><button type="submit" class="btn-primary"><i class="ti ti-device-floppy"></i> Guardar configuración</button></div>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div class="nc-card">
            <div class="nc-history-head">
                <div class="nc-history-counts">
                    <?php foreach ($ncEstados as $k => [$label, $tono]): if ($k === 'enviando' && !$correoConteo[$k]) continue; ?>
                        <span class="nc-state <?= $tono ?>"><?= e($label) ?> <b><?= $correoConteo[$k] ?></b></span>
                    <?php endforeach; ?>
                </div>
                <?php if ($ncPuede): ?>
                    <form method="post" class="nc-history-actions">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['correo_csrf']) ?>"><input type="hidden" name="tab" value="historial">
                        <button class="btn-primary" name="accion" value="correo_enviar_cola" <?= $correoConteo['pendiente'] ? '' : 'disabled' ?>><i class="ti ti-send"></i> Enviar pendientes</button>
                        <button class="btn-outline" name="accion" value="correo_reintentar" <?= $correoConteo['error'] ? '' : 'disabled' ?>><i class="ti ti-refresh"></i> Reintentar errores</button>
                        <button class="btn-outline" name="accion" value="correo_recordatorios"><i class="ti ti-alarm"></i> Generar recordatorios de hoy</button>
                        <button class="btn-outline nc-danger" name="accion" value="correo_descartar" <?= $correoConteo['pendiente'] + $correoConteo['error'] ? '' : 'disabled' ?> onclick="return confirm('¿Descartar los correos pendientes y con error? No se enviarán.')"><i class="ti ti-trash"></i> Descartar pendientes</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="nc-table">
                    <thead><tr><th>Fecha</th><th>Evento</th><th>Destinatario</th><th>Asunto</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($correoHistorial as $h): [$label, $tono] = $ncEstados[$h['estado']] ?? [$h['estado'], 'pend']; ?>
                        <tr>
                            <td class="nc-nowrap"><?= e(date('d/m/Y H:i', strtotime((string) $h['creado_en']))) ?></td>
                            <td><?= e($correoCfg[$h['evento']]['nombre'] ?? $h['evento']) ?></td>
                            <td><?= e($h['destinatario_original']) ?><?php if ($h['destinatario'] !== $h['destinatario_original']): ?><small>Prueba → <?= e($h['destinatario']) ?></small><?php endif; ?></td>
                            <td><?= e($h['asunto']) ?></td>
                            <td><span class="nc-state <?= $tono ?>"><?= e($label) ?></span><?php if ($h['estado'] === 'enviado' && $h['enviado_en']): ?><small><?= e(date('d/m H:i', strtotime((string) $h['enviado_en']))) ?></small><?php elseif ($h['error']): ?><small class="nc-error"><?= e($h['error']) ?> · intento <?= (int) $h['intentos'] ?>/3</small><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$correoHistorial): ?>
                        <tr><td colspan="5" class="nc-empty"><i class="ti ti-mail-opened"></i> Aún no se han generado notificaciones.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="nc-help">Los correos se envían al terminar la acción que los genera. Si el servidor de correo falla se reintentan hasta 3 veces. Los recordatorios de plazos se generan una vez al día; para enviarlos aunque nadie use el sistema programa <code>php comites/cron/correos.php</code> en el servidor (por ejemplo, cada 15 minutos).</p>
        </div>
    <?php endif; ?>
</section>
<script>
// Inserta la variable en el último campo de texto enfocado del mismo evento.
document.querySelectorAll('.nc-event').forEach(ev => {
    let campo = ev.querySelector('textarea[name$="[cuerpo]"]');
    ev.querySelectorAll('input[name$="[asunto]"], textarea[name$="[cuerpo]"]').forEach(el => el.addEventListener('focus', () => { campo = el; }));
    ev.querySelectorAll('.nc-var').forEach(b => b.addEventListener('click', () => {
        const ini = campo.selectionStart ?? campo.value.length, fin = campo.selectionEnd ?? ini;
        campo.setRangeText(b.dataset.var, ini, fin, 'end');
        campo.focus();
    }));
    ev.querySelector('.nc-switch input')?.addEventListener('change', e => ev.classList.toggle('is-off', !e.target.checked));
});
</script>
