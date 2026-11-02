<?php
$gestionarUsuarios = can_manage_resource($user, 'usuarios');
$csrfUsuarios = '<input type="hidden" name="csrf" value="' . e($_SESSION['usuarios_csrf']) . '">';
$urlUsuarios = static fn(array $extra = []): string => 'index.php?' . http_build_query(array_filter(['view' => 'usuarios', 'q' => $usuariosBusqueda, 'filtro' => $usuariosFiltro, 'rol' => $usuariosRolFiltro ?: ''] + $extra, static fn($v) => $v !== '' && $v !== null));
$programasActivos = array_filter($usuariosProgramas, static fn($p) => (int) $p['activo'] === 1);
?>
<nav class="red-tabs" aria-label="Usuarios y programas">
    <a href="index.php?view=usuarios" class="<?= $usuariosTab === 'usuarios' ? 'is-active' : '' ?>"><i class="ti ti-users"></i> Usuarios</a>
    <a href="index.php?view=usuarios&tab=programas" class="<?= $usuariosTab === 'programas' ? 'is-active' : '' ?>"><i class="ti ti-building"></i> Programas</a>
</nav>

<?php if ($usuariosError !== ''): ?>
    <div class="alert error"><i class="ti ti-alert-circle"></i> <?= e($usuariosError) ?></div>
<?php endif; ?>

<?php if ($usuariosTab === 'usuarios'): ?>
    <div class="usr-roles">
        <div class="usr-rol"><i class="ti ti-crown"></i><div><strong>Superadministrador</strong><small>Acceso total a todos los módulos. Se asigna desde la plataforma.</small></div></div>
        <div class="usr-rol"><i class="ti ti-building-bank"></i><div><strong>Director</strong><small>Consulta todo el módulo y da la aprobación final de documentos, informes y memoria.</small></div></div>
        <div class="usr-rol"><i class="ti ti-user-star"></i><div><strong>Jefe de programa o área</strong><small>Consulta el módulo, da seguimiento a sus pasos y entrega el informe de su programa. El jefe de <b>Transferencia de Tecnología</b> administra el módulo.</small></div></div>
        <div class="usr-rol"><i class="ti ti-user"></i><div><strong>Colaborador de programa</strong><small>Consulta reuniones, pasos y documentos, y entrega artículos a la memoria.</small></div></div>
    </div>

    <div class="section-toolbar">
        <form class="filters-form" method="get">
            <input type="hidden" name="view" value="usuarios">
            <div class="search-box"><i class="ti ti-search"></i><input class="form-control" type="text" name="q" placeholder="Buscar por nombre, correo o programa..." value="<?= e($usuariosBusqueda) ?>"></div>
            <select class="form-control filter-select" name="filtro" onchange="this.form.submit()" aria-label="Mostrar">
                <option value="con_acceso" <?= $usuariosFiltro === 'con_acceso' ? 'selected' : '' ?>>Con acceso a Comités</option>
                <option value="sin_programa" <?= $usuariosFiltro === 'sin_programa' ? 'selected' : '' ?>>Sin programa asignado</option>
                <option value="todos" <?= $usuariosFiltro === 'todos' ? 'selected' : '' ?>>Todos los usuarios</option>
            </select>
            <select class="form-control filter-select" name="rol" onchange="this.form.submit()" aria-label="Rol">
                <option value="">Todos los roles</option>
                <?php foreach ($usuariosRoles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $usuariosRolFiltro === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['nombre_rol']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Filtrar</button>
        </form>
        <div class="toolbar-actions">
            <span class="text-muted"><?= count($usuariosLista) ?> usuario(s)</span>
            <?php if ($gestionarUsuarios): ?><a class="btn-primary" href="<?= e($urlUsuarios(['agregar' => 1])) ?>"><i class="ti ti-user-plus"></i> Agregar usuario</a><?php endif; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table class="red-tabla">
                <thead><tr><th>Usuario</th><th>Rol</th><th>Programa</th><th>Perfil en Comités</th><th>Acceso</th><th class="acc"><?= $gestionarUsuarios ? 'Acciones' : '' ?></th></tr></thead>
                <tbody>
                <?php if (!$usuariosLista): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="ti ti-users"></i>No hay usuarios con estos filtros.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($usuariosLista as $u): $esSuper = (int) $u['es_superadmin'] === 1; ?>
                    <tr class="<?= (int) $u['id'] === $usuarioResaltado ? 'usr-resaltado' : '' ?>" <?= (int) $u['id'] === $usuarioResaltado ? 'id="usr-resaltado"' : '' ?>>
                        <td><strong><?= e($u['nombre']) ?></strong><div class="text-muted"><?= e((string) $u['correo']) ?></div></td>
                        <td><span class="badge <?= $esSuper ? 'badge-completado' : 'badge-borrador' ?>"><?= e($u['nombre_rol']) ?></span></td>
                        <td><?= $u['programa'] ? e((string) $u['programa']) : '<span class="usr-falta">Sin programa</span>' ?></td>
                        <td><?= $u['perfil'] ? '<span class="usr-perfil"><i class="ti ti-shield-check"></i> ' . e((string) $u['perfil']) . '</span>' : '<span class="text-muted">Según su rol</span>' ?></td>
                        <td><?= $esSuper ? '<span class="badge badge-activo" title="Los superadministradores entran a todos los módulos">Sí (superadmin)</span>' : ((int) $u['acceso'] ? '<span class="badge badge-activo">Sí</span>' : '<span class="badge badge-inactivo" title="No puede entrar a Comités">No</span>') ?></td>
                        <td class="acc">
                            <details class="row-menu">
                                <summary><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary>
                                <div class="row-menu-list" role="menu">
                                    <a href="<?= e($urlUsuarios(['ver' => (int) $u['id']])) ?>"><i class="ti ti-list-check"></i> Ver qué puede hacer</a>
                                    <?php if ($gestionarUsuarios): ?><a href="<?= e($urlUsuarios(['editar' => (int) $u['id']])) ?>"><i class="ti ti-user-cog"></i> Asignar rol y programa</a><?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="red-con-cron"><i class="ti ti-info-circle"></i> Las cuentas nuevas y el rol de Superadministrador se crean en la gestión de usuarios de la plataforma. Los permisos de cada rol se ajustan en Roles de la plataforma, grupo <b>Comités</b>.</p>

    <?php if ($gestionarUsuarios && (isset($_GET['agregar']) || ($reopenModal ?? null) === 'usuario_agregar')):
        $previo = ($reopenModal ?? null) === 'usuario_agregar' ? ($prefillPost ?? []) : ['acceso' => 1, 'enviar_bienvenida' => 1];
        $rolPorDefecto = 0;
        foreach ($usuariosRoles as $r) {
            if ($r['nombre_rol'] === 'Colaborador de programa') {
                $rolPorDefecto = (int) $r['id'];
            }
        }
    ?>
        <div class="modal-backdrop is-open" data-close-href="<?= e($urlUsuarios()) ?>">
            <div class="modal-box" onclick="event.stopPropagation()">
                <div class="modal-head">
                    <h3>Agregar usuario</h3>
                    <a class="modal-close" href="<?= e($urlUsuarios()) ?>"><i class="ti ti-x"></i></a>
                </div>
                <form method="post" class="modal-body" id="usr-agregar">
                    <?= $csrfUsuarios ?>
                    <input type="hidden" name="accion" value="usuario_agregar">
                    <label class="form-group"><span class="form-label">Correo electrónico <span class="req">*</span></span>
                        <input class="form-control" type="email" name="correo" id="usr-correo" required maxlength="150" autocomplete="off" value="<?= e((string) ($previo['correo'] ?? '')) ?>" placeholder="nombre@cengicana.org"></label>
                    <div class="usr-aviso" id="usr-aviso" hidden></div>
                    <label class="form-group" id="usr-nombre-campo"><span class="form-label">Nombre completo <span class="req">*</span></span>
                        <input class="form-control" type="text" name="nombre" id="usr-nombre" maxlength="150" value="<?= e((string) ($previo['nombre'] ?? '')) ?>"></label>
                    <div class="form-grid">
                        <label class="form-group"><span class="form-label">Rol</span>
                            <select class="form-control" name="rol_id" id="usr-rol">
                                <?php foreach ($usuariosRoles as $r): if (!$r['asignable']) continue; ?>
                                    <option value="<?= (int) $r['id'] ?>" <?= (int) ($previo['rol_id'] ?? $rolPorDefecto) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['nombre_rol']) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        <label class="form-group"><span class="form-label">Programa o área</span>
                            <select class="form-control" name="programa_id">
                                <option value="0">Sin programa</option>
                                <?php foreach ($programasActivos as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($previo['programa_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
                            </select></label>
                    </div>
                    <label class="pill-toggle <?= !empty($previo['acceso']) ? 'is-checked' : '' ?>"><input type="checkbox" name="acceso" value="1" <?= !empty($previo['acceso']) ? 'checked' : '' ?>><i class="ti ti-door-enter"></i> Acceso al módulo Comités</label>
                    <label class="pill-toggle <?= !empty($previo['enviar_bienvenida']) ? 'is-checked' : '' ?>" id="usr-bienvenida"><input type="checkbox" name="enviar_bienvenida" value="1" <?= !empty($previo['enviar_bienvenida']) ? 'checked' : '' ?>><i class="ti ti-mail"></i> Enviar correo para crear su contraseña</label>
                    <p class="form-hint">Si el correo ya tiene cuenta, solo se le asignan el rol, el programa y el acceso; su nombre y su contraseña no cambian. El rol aplica en toda la plataforma.</p>
                    <div class="actions">
                        <a class="btn-outline" href="<?= e($urlUsuarios()) ?>">Cancelar</a>
                        <button type="submit" class="btn-primary" id="usr-enviar"><i class="ti ti-user-plus"></i> Agregar usuario</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (() => {
            const correo = document.getElementById('usr-correo'), aviso = document.getElementById('usr-aviso');
            const nombreCampo = document.getElementById('usr-nombre-campo'), nombre = document.getElementById('usr-nombre');
            const bienvenida = document.getElementById('usr-bienvenida'), boton = document.getElementById('usr-enviar');
            const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            let temporizador, ultimo = '';
            const mostrar = (datos) => {
                const existe = !!(datos && datos.existe);
                nombreCampo.hidden = existe;
                nombre.required = !existe;
                bienvenida.hidden = existe;
                boton.innerHTML = existe ? '<i class="ti ti-user-check"></i> Asignar rol y programa' : '<i class="ti ti-user-plus"></i> Agregar usuario';
                aviso.hidden = !existe;
                if (existe) {
                    aviso.className = 'usr-aviso' + (datos.superadmin ? ' is-warn' : '');
                    aviso.innerHTML = `<i class="ti ti-user-check"></i><span>Este correo ya tiene cuenta: <b>${esc(datos.nombre)}</b> · ${esc(datos.rol)}${datos.programa ? ' · ' + esc(datos.programa) : ''}. ${datos.superadmin ? 'Es superadministrador: su rol no se cambia desde aquí.' : 'Solo se le asignarán el rol, el programa y el acceso.'}</span>`;
                }
            };
            const consultar = () => {
                const valor = correo.value.trim().toLowerCase();
                if (valor === ultimo) return;
                ultimo = valor;
                if (!correo.checkValidity() || !valor.includes('@')) { mostrar(null); return; }
                fetch('index.php?view=usuarios&buscar_correo=' + encodeURIComponent(valor))
                    .then((r) => r.json()).then((d) => { if (correo.value.trim().toLowerCase() === valor) mostrar(d); })
                    .catch(() => mostrar(null));
            };
            correo.addEventListener('input', () => { clearTimeout(temporizador); temporizador = setTimeout(consultar, 350); });
            correo.addEventListener('blur', consultar);
            mostrar(null);
            nombre.required = true;
            if (correo.value) consultar();
            correo.focus();
        })();
        </script>
    <?php endif; ?>

    <?php if ($usuarioResaltado): ?>
        <script>document.getElementById('usr-resaltado')?.scrollIntoView({ block: 'center' });</script>
    <?php endif; ?>

    <?php if ($usuarioVer):
        $ef = $usuarioVer['efectivos'];
        $esSuperVer = (int) $usuarioVer['es_superadmin'] === 1;
        $entra = $esSuperVer || (int) $usuarioVer['acceso'];
        $etiquetas = ['gestionar' => ['Gestiona', 'usr-nivel-gestionar', 'ti-pencil'], 'ver' => ['Consulta', 'usr-nivel-ver', 'ti-eye'], 'no' => ['Sin acceso', 'usr-nivel-no', 'ti-lock']];
    ?>
        <div class="modal-backdrop is-open" data-close-href="<?= e($urlUsuarios()) ?>">
            <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                <div class="modal-head">
                    <h3>Qué puede hacer en Comités</h3>
                    <a class="modal-close" href="<?= e($urlUsuarios()) ?>"><i class="ti ti-x"></i></a>
                </div>
                <div class="modal-body">
                    <p class="usr-nombre"><strong><?= e($usuarioVer['nombre']) ?></strong><span class="text-muted"><?= e((string) $usuarioVer['correo']) ?> · <?= e($usuarioVer['nombre_rol']) ?> · <?= e((string) ($usuarioVer['programa'] ?: 'sin programa')) ?></span></p>
                    <div class="usr-estado <?= $entra ? 'is-ok' : 'is-no' ?>">
                        <i class="ti <?= $entra ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i>
                        <span><?= $esSuperVer ? 'Superadministrador: entra y gestiona todo el módulo.' : ($entra ? 'Está incluido: puede entrar al módulo Comités.' : 'No está incluido: no tiene acceso al módulo y no podrá entrar aunque tenga permisos.') ?>
                        <?php if ($ef['por_programa'] && !$esSuperVer): ?><br>Por su programa actúa como <b><?= e($ef['por_programa']) ?></b>.<?php endif; ?></span>
                    </div>
                    <div class="usr-niveles">
                        <?php foreach ($ef['secciones'] as $s): [$txt, $cls, $ico] = $etiquetas[$s['nivel']]; ?>
                            <div class="usr-nivel <?= $cls ?>"><span><?= e($s['nombre']) ?></span><b><i class="ti <?= $ico ?>"></i> <?= $txt ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($ef['especiales']): ?>
                        <h4 class="usr-sub">Revisiones y aprobaciones</h4>
                        <ul class="usr-especiales"><?php foreach ($ef['especiales'] as $esp): ?><li><i class="ti ti-check"></i> <?= e($esp) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <p class="form-hint">Los permisos salen de su rol (Roles de la plataforma, grupo Comités) y de su programa.</p>
                    <div class="actions">
                        <a class="btn-outline" href="<?= e($urlUsuarios()) ?>">Cerrar</a>
                        <?php if ($gestionarUsuarios): ?><a class="btn-primary" href="<?= e($urlUsuarios(['editar' => (int) $usuarioVer['id']])) ?>"><i class="ti ti-user-cog"></i> Asignar rol y programa</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($usuarioEditar && $gestionarUsuarios):
        $esSuper = (int) $usuarioEditar['es_superadmin'] === 1;
        $esYo = (int) $usuarioEditar['id'] === (int) $user['id'];
        $rolBloqueado = $esSuper || $esYo;
    ?>
        <div class="modal-backdrop is-open" data-close-href="<?= e($urlUsuarios()) ?>">
            <div class="modal-box" onclick="event.stopPropagation()">
                <div class="modal-head">
                    <h3>Asignar rol y programa</h3>
                    <a class="modal-close" href="<?= e($urlUsuarios()) ?>"><i class="ti ti-x"></i></a>
                </div>
                <form method="post" class="modal-body">
                    <?= $csrfUsuarios ?>
                    <input type="hidden" name="accion" value="usuario_guardar">
                    <input type="hidden" name="usuario_id" value="<?= (int) $usuarioEditar['id'] ?>">
                    <p class="usr-nombre"><strong><?= e($usuarioEditar['nombre']) ?></strong><span class="text-muted"><?= e((string) $usuarioEditar['correo']) ?></span></p>
                    <label class="form-group"><span class="form-label">Rol en la plataforma</span>
                        <select class="form-control" name="rol_id" <?= $rolBloqueado ? 'disabled' : '' ?>>
                            <?php foreach ($usuariosRoles as $r): if (!$r['asignable'] && (int) $r['id'] !== (int) $usuarioEditar['rol_id']) continue; ?>
                                <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $usuarioEditar['rol_id'] ? 'selected' : '' ?>><?= e($r['nombre_rol']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($rolBloqueado): ?><input type="hidden" name="rol_id" value="<?= (int) $usuarioEditar['rol_id'] ?>"><?php endif; ?>
                        <span class="form-hint"><?= $esSuper ? 'El rol de un superadministrador se cambia en la plataforma.' : ($esYo ? 'No puedes cambiar tu propio rol.' : 'El rol aplica en toda la plataforma, también en Solicitudes internas.') ?></span>
                    </label>
                    <label class="form-group"><span class="form-label">Programa o área</span>
                        <select class="form-control" name="programa_id">
                            <option value="0">Sin programa</option>
                            <?php foreach ($programasActivos as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $usuarioEditar['programa_id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
                        </select>
                        <span class="form-hint">Obligatorio para Jefe de programa. El jefe de Transferencia de Tecnología administra el módulo.</span>
                    </label>
                    <label class="pill-toggle <?= $esSuper || (int) $usuarioEditar['acceso'] ? 'is-checked' : '' ?>">
                        <input type="checkbox" name="acceso" value="1" <?= $esSuper || (int) $usuarioEditar['acceso'] ? 'checked' : '' ?> <?= $esSuper ? 'disabled' : '' ?>>
                        <i class="ti ti-door-enter"></i> Acceso al módulo Comités
                    </label>
                    <div class="actions">
                        <a class="btn-outline" href="<?= e($urlUsuarios()) ?>">Cancelar</a>
                        <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="section-toolbar">
        <span class="text-muted">Los programas son los mismos de Solicitudes internas: un cambio aquí se ve en ambos módulos.</span>
        <?php if ($gestionarUsuarios): ?><a class="btn-primary" href="index.php?view=usuarios&tab=programas&programa=0"><i class="ti ti-plus"></i> Nuevo programa</a><?php endif; ?>
    </div>
    <div class="table-card">
        <div class="table-wrap">
            <table class="red-tabla">
                <thead><tr><th>Programa o área</th><th class="num">Usuarios</th><th>Estado</th><th class="acc"><?= $gestionarUsuarios ? 'Acciones' : '' ?></th></tr></thead>
                <tbody>
                <?php if (!$usuariosProgramas): ?>
                    <tr><td colspan="4"><div class="empty-state"><i class="ti ti-building"></i>Todavía no hay programas.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($usuariosProgramas as $p): ?>
                    <tr class="<?= (int) $p['activo'] ? '' : 'catalogo-inactivo' ?>">
                        <td><strong><?= e($p['nombre']) ?></strong></td>
                        <td class="num"><a href="index.php?view=usuarios&filtro=todos&q=<?= urlencode((string) $p['nombre']) ?>"><?= (int) $p['usuarios'] ?></a></td>
                        <td><span class="badge <?= (int) $p['activo'] ? 'badge-activo' : 'badge-inactivo' ?>"><?= (int) $p['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                        <td class="acc">
                            <?php if ($gestionarUsuarios): ?>
                                <details class="row-menu">
                                    <summary><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary>
                                    <div class="row-menu-list" role="menu">
                                        <a href="index.php?view=usuarios&tab=programas&programa=<?= (int) $p['id'] ?>"><i class="ti ti-pencil"></i> Renombrar</a>
                                        <form method="post"><?= $csrfUsuarios ?><input type="hidden" name="accion" value="programa_estado"><input type="hidden" name="programa_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="<?= (int) $p['activo'] ? 'is-danger' : '' ?>"><i class="ti <?= (int) $p['activo'] ? 'ti-player-pause' : 'ti-player-play' ?>"></i> <?= (int) $p['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($programaEditar !== null && $gestionarUsuarios): ?>
        <div class="modal-backdrop is-open" data-close-href="index.php?view=usuarios&tab=programas">
            <div class="modal-box" onclick="event.stopPropagation()">
                <div class="modal-head">
                    <h3><?= (int) $programaEditar['id'] ? 'Renombrar programa' : 'Nuevo programa' ?></h3>
                    <a class="modal-close" href="index.php?view=usuarios&tab=programas"><i class="ti ti-x"></i></a>
                </div>
                <form method="post" class="modal-body">
                    <?= $csrfUsuarios ?>
                    <input type="hidden" name="accion" value="programa_guardar">
                    <input type="hidden" name="programa_id" value="<?= (int) $programaEditar['id'] ?>">
                    <label class="form-group"><span class="form-label">Nombre del programa o área <span class="req">*</span></span>
                        <input class="form-control" name="nombre" maxlength="120" required value="<?= e((string) $programaEditar['nombre']) ?>" placeholder="Ej. Transferencia de Tecnología"></label>
                    <div class="actions">
                        <a class="btn-outline" href="index.php?view=usuarios&tab=programas">Cancelar</a>
                        <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
