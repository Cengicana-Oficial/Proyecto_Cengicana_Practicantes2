<?php
/**
 * Roles y permisos de Cursos. Cada rol del módulo Cursos con lo que puede hacer por área
 * (sin acceso / consultar / gestionar). Los permisos se guardan en la base central
 * (usuarios_menu.rol_permiso) y solo se tocan los de Cursos: los de otros módulos se conservan.
 * Crear, renombrar o eliminar roles y los permisos de otros módulos: administración central
 * (login/roles/roles.php).
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/accesos_helpers.php';

cengi_require_ver_roles();

$m = conectar_usuarios_menu_pdo();
asegurar_tablas_permisos($m);
$esSuper = cengi_es_superadmin();
$puedeGestionar = cengi_puede_gestionar_roles();
$areas = cengi_acc_areas();
$h = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// ---------------------------------------------------------------- guardar permisos de un rol
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_rol') {
    $rolId = (int) ($_POST['rol_id'] ?? 0);
    $rol = null;
    foreach (cengi_acc_roles_cursos($m) as $r) {
        if ($r['id'] === $rolId) {
            $rol = $r;
        }
    }
    $msg = 'error';
    if ($puedeGestionar && $rol && cengi_acc_puede_editar_rol($rol)) {
        $manejados = [];
        $nuevos = [];
        foreach ($areas as $clave => [, , $ver, $gestionar]) {
            $manejados = array_merge($manejados, $ver, $gestionar);
            $nivel = (string) ($_POST['area'][$clave] ?? '');
            if ($nivel === 'gestionar') {
                $nuevos = array_merge($nuevos, $ver, $gestionar);
            } elseif ($nivel === 'parcial') {
                // Personalizado: se conservan exactamente los permisos que ya tenía en esta área.
                $nuevos = array_merge($nuevos, array_intersect(obtener_permisos_rol($m, $rolId), array_merge($ver, $gestionar)));
            } elseif ($nivel === 'ver') {
                $nuevos = array_merge($nuevos, $ver);
            }
        }
        if (!$esSuper) {
            // Los permisos protegidos quedan como estaban: solo el superadministrador los concede o quita.
            $manejados = array_diff($manejados, CENGI_ACC_PROTEGIDOS);
            $nuevos = array_diff($nuevos, CENGI_ACC_PROTEGIDOS);
        }
        $actuales = obtener_permisos_rol($m, $rolId);
        $final = array_values(array_unique(array_merge(array_diff($actuales, $manejados), $nuevos)));
        guardar_permisos_rol($m, $rolId, $final);
        $msg = 'guardado';
    }
    header('Location: roles.php?' . http_build_query(['msg' => $msg, 'rol' => $rolId]) . '#rol-' . $rolId);
    exit;
}

$roles = cengi_acc_roles_cursos($m);
$permisosPorRol = [];
foreach ($roles as $r) {
    $permisosPorRol[$r['id']] = obtener_permisos_rol($m, $r['id']);
}
$abierto = (int) ($_GET['rol'] ?? 0);
$mensajes = [
    'guardado' => ['ok', 'Permisos guardados. Se aplican desde la próxima página que abran los usuarios con ese rol.'],
    'error' => ['error', 'No tienes permiso para cambiar ese rol desde Cursos.'],
];
[$mensajeTipo, $mensaje] = $mensajes[$_GET['msg'] ?? ''] ?? ['', ''];
$css = static function ($archivo) {
    return (int) (@filemtime(__DIR__ . '/css/' . $archivo) ?: 1);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Roles y permisos</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo $css('proyecto.css'); ?>">
    <link rel="stylesheet" href="css/cengi-tema.css?v=<?php echo $css('cengi-tema.css'); ?>">
    <link rel="stylesheet" href="css/ingenios.css?v=<?php echo $css('ingenios.css'); ?>">
    <link rel="stylesheet" href="css/accesos.css?v=<?php echo $css('accesos.css'); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container ing-page acc-page">
    <header class="ing-hero">
        <div>
            <small>Sistema</small>
            <h1>Roles y permisos</h1>
            <p>Qué puede hacer cada rol <b>dentro de Cursos</b>. Para asignar un rol a una persona usa <a href="ver_usuarios.php">Usuarios</a>.
                Crear, renombrar o eliminar roles y los permisos de otros módulos se hacen en la administración central.</p>
        </div>
        <?php if ($esSuper): ?>
            <a class="ing-btn is-claro" href="../login/roles/roles.php"><span class="glyphicon glyphicon-cog"></span> Administración central de roles</a>
        <?php endif; ?>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="ing-aviso <?php echo $mensajeTipo === 'error' ? 'is-error' : 'is-ok'; ?>" role="status">
            <span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'warning-sign' : 'ok'; ?>"></span>
            <div><p><?php echo $h($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <section class="ing-panel">
        <div class="acc-panel-cab">
            <div><b>Roles del módulo Cursos</b><small><?php echo count($roles); ?> roles · despliega uno para ver o ajustar sus permisos</small></div>
            <div class="acc-leyenda"><span class="acc-nivel is-gestionar">Gestiona</span><span class="acc-nivel is-parcial">Personalizado</span><span class="acc-nivel is-ver">Consulta</span><span class="acc-nivel">Sin acceso</span></div>
        </div>
        <div class="acc-roles">
            <?php foreach ($roles as $r):
                $permisos = $permisosPorRol[$r['id']];
                $esSuperRol = cengi_rm_norm($r['nombre_rol']) === 'superadmin';
                $editable = $puedeGestionar && cengi_acc_puede_editar_rol($r);
                $niveles = [];
                foreach ($areas as $clave => $area) {
                    $niveles[$clave] = $esSuperRol ? 'gestionar' : cengi_acc_nivel($area, $permisos);
                }
                $nGest = count(array_keys($niveles, 'gestionar', true)) + count(array_keys($niveles, 'parcial', true));
                $nVer = count(array_keys($niveles, 'ver', true));
                $implicito = cengi_acc_implicitos($r['nombre_rol']);
                $estudiante = cengi_acc_es_estudiante($r['nombre_rol']); ?>
                <details class="acc-rol<?php echo $esSuperRol ? ' is-super' : ''; ?>" id="rol-<?php echo $r['id']; ?>"<?php echo $abierto === $r['id'] ? ' open' : ''; ?>>
                    <summary>
                        <span class="ing-avatar" aria-hidden="true"><?php echo $h(cengi_acc_iniciales($r['nombre_rol'])); ?></span>
                        <span class="acc-rol-nombre"><b><?php echo $h($r['nombre_rol']); ?></b>
                            <small><?php echo $h(cengi_acc_descripcion_rol($r['nombre_rol']) ?: ($r['generico'] ? 'Rol general (sirve en varios módulos).' : 'Rol del módulo Cursos.')); ?></small></span>
                        <span class="acc-rol-tipo <?php echo $r['generico'] ? 'is-general' : ''; ?>"><?php echo $r['generico'] ? 'General' : 'Cursos'; ?></span>
                        <span class="acc-rol-resumen"><?php if ($esSuperRol): ?>Acceso total<?php elseif ($estudiante): ?>Aula virtual<?php else: ?><b><?php echo $nGest; ?></b> gestiona · <b><?php echo $nVer; ?></b> consulta<?php endif; ?></span>
                        <a class="acc-rol-usuarios" href="ver_usuarios.php?rol=<?php echo $r['id']; ?>" title="Ver los usuarios con este rol"><b><?php echo $r['usuarios']; ?></b> usuario(s)</a>
                        <span class="caret" aria-hidden="true"></span>
                    </summary>
                    <div class="acc-rol-cuerpo">
                        <?php if ($esSuperRol): ?>
                            <p class="acc-nota">El superadministrador tiene todos los permisos en todos los módulos; no se ajusta por áreas.</p>
                        <?php elseif ($estudiante): ?>
                            <p class="acc-nota">Los participantes usan el <b>aula virtual</b> y sus cursos; no necesitan permisos del panel de gestión. Su cuenta se crea sola al aprobar su inscripción.</p>
                        <?php else: ?>
                            <?php if ($implicito !== ''): ?><p class="acc-nota is-info"><span class="glyphicon glyphicon-info-sign"></span> <?php echo $h($implicito); ?></p><?php endif; ?>
                            <?php if (!$editable): ?><p class="acc-nota"><?php echo $r['generico'] ? 'Rol general: sus permisos se cambian en la administración central.' : 'Solo lectura: necesitas el permiso para editar roles.'; ?></p><?php endif; ?>
                            <form method="post" class="acc-matriz">
                                <input type="hidden" name="accion" value="guardar_rol"><input type="hidden" name="rol_id" value="<?php echo $r['id']; ?>">
                                <?php foreach ($areas as $clave => [$titulo, $detalle, $ver, $gestionar]):
                                    $protegida = (bool) array_intersect(array_merge($ver, $gestionar), CENGI_ACC_PROTEGIDOS);
                                    $bloqueada = !$editable || ($protegida && !$esSuper && !$ver);
                                    $opciones = ['' => 'Sin acceso'];
                                    if ($ver) {
                                        $opciones['ver'] = 'Consulta';
                                    }
                                    if ($gestionar) {
                                        $opciones['gestionar'] = $ver ? 'Gestiona' : 'Sí';
                                    }
                                    if ($niveles[$clave] === 'parcial') {
                                        $opciones['parcial'] = 'Personalizado';
                                    } ?>
                                    <div class="acc-area<?php echo $niveles[$clave] !== '' ? ' is-' . $niveles[$clave] : ''; ?>">
                                        <div class="acc-area-txt"><b><?php echo $h($titulo); ?></b><small><?php echo $h($detalle); ?><?php echo $niveles[$clave] === 'parcial' ? ' Tiene solo parte de la gestión (asignada en la administración central); “Personalizado” la conserva tal cual.' : ''; ?><?php echo $protegida && !$esSuper ? ' Solo el superadministrador concede la gestión.' : ''; ?></small></div>
                                        <div class="acc-seg" role="radiogroup" aria-label="<?php echo $h($titulo); ?>">
                                            <?php foreach ($opciones as $valor => $texto):
                                                $deshabilitada = $bloqueada || ($protegida && !$esSuper && $valor === 'gestionar'); ?>
                                                <label class="<?php echo $valor === '' ? 'is-no' : 'is-' . $valor; ?><?php echo $deshabilitada ? ' is-bloq' : ''; ?>">
                                                    <input type="radio" name="area[<?php echo $clave; ?>]" value="<?php echo $valor; ?>" <?php echo $niveles[$clave] === $valor ? 'checked' : ''; ?> <?php echo $deshabilitada && $niveles[$clave] !== $valor ? 'disabled' : ''; ?> <?php echo $bloqueada ? 'disabled' : ''; ?>>
                                                    <span><?php echo $texto; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($editable): ?>
                                    <div class="acc-matriz-pie"><small>Solo cambia los permisos de Cursos de este rol; los de otros módulos se conservan.</small>
                                        <button type="submit" class="ing-btn"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar permisos de <?php echo $h($r['nombre_rol']); ?></button></div>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <p class="acc-central"><span class="glyphicon glyphicon-random"></span>
        <?php if ($esSuper): ?>
            ¿Necesitas un rol nuevo, cambiarle el nombre o darle acceso a otros módulos? Hazlo en la <a href="../login/roles/roles.php">administración central de roles</a>: el rol nuevo aparece aquí en cuanto lo marques para el módulo Cursos.
        <?php else: ?>
            Para crear, renombrar o eliminar un rol, o cambiar los permisos de otros módulos, pídelo al administrador del sistema (administración central).
        <?php endif; ?>
    </p>
</main>
<script>
// Un rol abierto a la vez.
document.querySelectorAll('.acc-rol').forEach(function (d) {
    d.addEventListener('toggle', function () {
        if (d.open) document.querySelectorAll('.acc-rol[open]').forEach(function (o) { if (o !== d) o.open = false; });
    });
});
</script>
</body>
</html>
