<?php
// Lista paginada; se renderiza únicamente el expediente seleccionado.
?>
<div class="est-paginacion">
    <span><?php echo $totalEst ? ($paginaEst - 1) * $porPaginaEst + 1 : 0; ?>–<?php echo min($paginaEst * $porPaginaEst, $totalEst); ?> de <?php echo $totalEst; ?> estudiantes</span>
    <nav aria-label="Páginas de estudiantes">
        <?php if ($paginaEst > 1): ?><a class="ing-btn is-linea" href="<?php echo $h($url(['pagina' => $paginaEst - 1, 'por_pagina' => $porPaginaEst])); ?>">Anterior</a><?php endif; ?>
        <span>Página <?php echo $paginaEst; ?> de <?php echo $paginasEst; ?></span>
        <?php if ($paginaEst < $paginasEst): ?><a class="ing-btn is-linea" href="<?php echo $h($url(['pagina' => $paginaEst + 1, 'por_pagina' => $porPaginaEst])); ?>">Siguiente</a><?php endif; ?>
    </nav>
</div>
<div class="est-lista">
<?php foreach ($lista as $u):
    $uid = (int) $u['id'];
    $cursosTodos = $acceso[$uid] ?? [];
    $cursosU = array_filter($cursosTodos, $cursoEnAnio);
    $fichasU = array_filter($fichas[$uid] ?? [], static function ($f) use ($veTodo, $miIngenio) { return $veTodo || (int) ($f['ingenio_id'] ?? 0) === $miIngenio; });
    $editable = $puedeGestionar && $uid !== $miId;
    $gestionAbierta = (int) ($_GET['ver'] ?? 0) === $uid;
    $formId = static function ($accion) use ($uid, $ocultos, $h) { return '<input type="hidden" name="accion" value="'.$h($accion).'"><input type="hidden" name="usuario_id" value="'.$uid.'">'.$ocultos; };
?>
<article class="est-card" id="u-<?php echo $uid; ?>">
    <header class="est-card-head">
        <div class="ing-nombre"><span class="ing-avatar" aria-hidden="true"><?php echo $h(cengi_acc_iniciales($u['nombre'])); ?></span><div><strong><?php echo $h($u['nombre']); ?></strong><small><?php echo $h($u['correo']); ?></small><small><?php echo $h($u['nombre_ingenio'] ?: 'Sin ingenio'); ?></small></div></div>
        <div class="est-resumen"><span class="acc-chip-rol">Estudiante</span><span><?php echo count($cursosU); ?> cursos en <?php echo $anioVista === 'todos' ? 'todos los años' : ($anioVista === 'sin' ? 'sin fecha' : $h($anioVista)); ?></span><small><?php echo count($cursosTodos); ?> asignados en total</small></div>
    </header>
    <div class="est-row-action">
        <form method="get" action="ver_usuarios.php#u-<?php echo $uid; ?>">
            <input type="hidden" name="tab" value="estudiantes"><input type="hidden" name="q" value="<?php echo $h($busqueda); ?>"><input type="hidden" name="rol" value="<?php echo $h($filtroRol); ?>"><input type="hidden" name="ingenio" value="<?php echo $filtroIngenio; ?>"><input type="hidden" name="anio" value="<?php echo $h($anioVista); ?>"><input type="hidden" name="pagina" value="<?php echo $paginaEst; ?>"><input type="hidden" name="por_pagina" value="<?php echo $porPaginaEst; ?>">
            <button type="submit" name="ver" value="<?php echo $gestionAbierta ? '' : $uid; ?>" class="ing-btn is-linea" aria-expanded="<?php echo $gestionAbierta ? 'true' : 'false'; ?>" aria-controls="gestion-<?php echo $uid; ?>"><?php echo $gestionAbierta ? 'Cerrar' : ($editable ? 'Gestionar' : 'Ver cursos'); ?></button>
        </form>
    </div>
    <?php if ($gestionAbierta): ?>
    <section class="est-gestion" id="gestion-<?php echo $uid; ?>" aria-label="Gestión de <?php echo $h($u['nombre']); ?>">
        <div class="est-panel-title"><strong><?php echo $h($u['nombre']); ?></strong><span>Cursos · cuenta · contraseña</span></div>
        <?php if ($editable): ?>
        <div class="est-gestion-grid">
            <section class="est-section est-section-cursos"><h3>Cursos asignados · <?php echo $anioVista === 'todos' ? 'todos los años' : $h($anioVista); ?></h3><p>El filtro por año organiza esta pantalla. La fecha de finalización no suspende automáticamente el aula.</p>
                <?php if (!$cursosU): ?><p class="est-vacio">Sin cursos asignados en el año seleccionado.</p><?php endif; ?>
                <?php foreach ($cursosU as $cid => $c): $suspendido = !empty($bloqueosAula[$uid][$cid]); ?>
                <div class="est-curso"><div><strong><?php echo $h($c['nombre_cursos']); ?></strong><a href="ver_participante_curso.php?id=<?php echo (int) $cid; ?>">Ver seguimiento e historial</a></div>
                    <form method="post"><?php echo $formId($suspendido ? 'habilitar_aula' : 'suspender_aula'); ?><input type="hidden" name="curso_id" value="<?php echo (int) $cid; ?>"><button class="ing-btn is-linea" type="submit"><?php echo $suspendido ? 'Habilitar aula' : 'Suspender aula'; ?></button></form>
                </div>
                <?php endforeach; ?>
                <?php if ($fichasU): ?>
                <details class="est-sub"><summary>Agregar un curso</summary>
                    <form method="post" class="est-form"><?php echo $formId('agregar_curso'); ?>
                        <label>Ficha de participante<select name="participante_id" required><?php foreach ($fichasU as $pid => $f): ?><option value="<?php echo (int) $pid; ?>"><?php echo $h($f['nombre_participantes']); ?></option><?php endforeach; ?></select></label>
                        <label>Curso del año seleccionado<select name="curso_id" required><option value="">Selecciona un curso</option><?php foreach ($cursosDisponibles as $cid => $nombre): if (isset($cursosTodos[$cid])) continue; ?><option value="<?php echo (int) $cid; ?>"><?php echo $h($nombre); ?></option><?php endforeach; ?></select></label>
                        <small>Se registra la inscripción; el acceso requiere patrocinio o pago validado.</small><button type="submit" class="ing-btn">Agregar acceso al curso</button>
                    </form>
                </details>
                <?php else: ?><p class="est-vacio">Vincula una ficha de participante para agregar cursos.</p><?php endif; ?>
                <?php if (!empty($candidatas[$uid])): foreach ($candidatas[$uid] as $p): if (!$veTodo && (int) $p['ingenio_id'] !== $miIngenio) continue; ?>
                    <form method="post" class="est-form"><?php echo $formId('vincular_ficha'); ?><input type="hidden" name="participante_id" value="<?php echo (int) $p['id']; ?>"><button class="ing-btn is-linea">Vincular ficha: <?php echo $h($p['nombre_participantes']); ?></button></form>
                <?php endforeach; endif; ?>
                <?php foreach ($ajenas[$uid] ?? [] as $pid => $otras): if (!isset($fichasU[$pid])) continue; ?>
                    <div class="est-alerta"><p><?php echo count($otras); ?> inscripción(es) también están vinculadas a otra cuenta.</p><form method="post" onsubmit="return confirm('¿Corregir el vínculo? La otra cuenta perderá acceso a estos cursos.');"><?php echo $formId('corregir_vinculo'); ?><input type="hidden" name="participante_id" value="<?php echo (int) $pid; ?>"><button class="ing-btn is-linea">Corregir vínculo de cuenta</button></form></div>
                <?php endforeach; ?>
                <?php foreach ($fichasU as $pid => $f): ?><a class="est-ficha-link" href="directorio_participantes.php?ver=<?php echo (int) $pid; ?>">Ficha e historial: <?php echo $h($f['nombre_participantes']); ?> · CUI <?php echo $h($f['cui_participantes']); ?></a><?php endforeach; ?>
            </section>
            <div>
                <section class="est-section"><h3>Datos de la cuenta</h3><form method="post" class="est-form"><?php echo $formId('editar_estudiante'); ?>
                    <label>Nombre para mostrar<input name="nombre" value="<?php echo $h($u['nombre']); ?>" maxlength="100" required></label>
                    <label>Correo electrónico<input type="email" name="correo" value="<?php echo $h($u['correo']); ?>" maxlength="100" required></label>
                    <small>El nuevo correo será el de inicio de sesión y se actualizará en las fichas vinculadas.</small><button type="submit" class="ing-btn">Guardar datos</button>
                </form></section>
                <section class="est-section"><h3>Restablecer contraseña</h3><form method="post" class="est-form est-password"><?php echo $formId('resetear_password'); ?>
                    <label>Nueva contraseña<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
                    <label>Confirmar contraseña<input type="password" name="password_confirmacion" minlength="8" required autocomplete="new-password"></label>
                    <label class="est-check"><input type="checkbox" class="est-show-password"> Mostrar contraseñas</label>
                    <small>Mínimo 8 caracteres, con letras y números. Comunícala al estudiante.</small><button class="ing-btn" type="submit">Restablecer contraseña</button>
                </form></section>
            </div>
            <details class="est-sub est-avanzado"><summary>Opciones avanzadas</summary><p>Retirar una inscripción afecta la participación en el curso. Para cerrar solamente el aula utiliza Suspender aula.</p>
                <?php foreach ($cursosU as $cid => $c): if ((int) $c['up'] !== $uid || !isset($fichasU[(int) $c['pid']])) continue; ?>
                    <form method="post" class="est-curso" onsubmit="return confirm('¿Retirar esta inscripción? Se conservará el historial académico.');"><?php echo $formId('quitar_curso'); ?><input type="hidden" name="participante_id" value="<?php echo (int) $c['pid']; ?>"><input type="hidden" name="curso_id" value="<?php echo (int) $cid; ?>"><span><?php echo $h($c['nombre_cursos']); ?></span><button class="ing-btn is-peligro">Retirar inscripción</button></form>
                <?php endforeach; ?>
                <details class="est-sub"><summary>Rol e ingenio de la cuenta</summary><?php echo $menuAcciones($u); ?></details>
            </details>
        </div>

        <?php else: ?>
        <div class="est-cursos-preview">
            <?php foreach ($cursosU as $cid => $c): ?>
                <div class="est-curso"><strong><?php echo $h($c['nombre_cursos']); ?></strong><span class="est-acceso"><?php echo !empty($bloqueosAula[$uid][$cid]) ? 'Aula suspendida' : 'Aula habilitada'; ?></span></div>
            <?php endforeach; ?>
            <?php if (!$cursosU): ?><p class="est-vacio">Sin cursos asignados en el año seleccionado.</p><?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<div class="est-paginacion">
    <span><?php echo $totalEst ? ($paginaEst - 1) * $porPaginaEst + 1 : 0; ?>–<?php echo min($paginaEst * $porPaginaEst, $totalEst); ?> de <?php echo $totalEst; ?> estudiantes</span>
    <nav aria-label="Páginas de estudiantes">
        <?php if ($paginaEst > 1): ?><a class="ing-btn is-linea" href="<?php echo $h($url(['pagina' => $paginaEst - 1, 'por_pagina' => $porPaginaEst])); ?>">Anterior</a><?php endif; ?>
        <span>Página <?php echo $paginaEst; ?> de <?php echo $paginasEst; ?></span>
        <?php if ($paginaEst < $paginasEst): ?><a class="ing-btn is-linea" href="<?php echo $h($url(['pagina' => $paginaEst + 1, 'por_pagina' => $porPaginaEst])); ?>">Siguiente</a><?php endif; ?>
    </nav>
</div>
<script>
document.querySelectorAll('.est-show-password').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        this.closest('form').querySelectorAll('input[name="password"],input[name="password_confirmacion"]').forEach(function(input) { input.type = toggle.checked ? 'text' : 'password'; });
    });
});
document.querySelectorAll('.est-password').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        var confirmation = form.elements.password_confirmacion;
        confirmation.setCustomValidity(form.elements.password.value === confirmation.value ? '' : 'Las contraseñas no coinciden.');
        if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); }
    });
    form.elements.password_confirmacion.addEventListener('input', function() { this.setCustomValidity(''); });
    form.elements.password.addEventListener('input', function() { form.elements.password_confirmacion.setCustomValidity(''); });
});
</script>