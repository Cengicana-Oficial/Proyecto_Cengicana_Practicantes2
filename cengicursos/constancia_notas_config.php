<?php
// Configuración de la constancia de notas: firmantes (nombre, cargo e imagen de firma), lugar y pie.
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/constancia_notas_helpers.php';
if (!cengi_cn_puede_configurar()) {
    http_response_code(403);
    exit('No tienes permiso para configurar la constancia de notas.');
}
$db = conectar();
$h = 'cengi_cn_h';
$cursoId = (int) ($_GET['curso'] ?? $_POST['curso'] ?? 0);
if (empty($_SESSION['cn_config_csrf'])) {
    $_SESSION['cn_config_csrf'] = bin2hex(random_bytes(16));
}
$cfg = cengi_cn_config($db);
$error = '';
// Texto pegado desde otra fuente con codificación distinta a UTF-8 (p. ej. Windows-1252): se convierte.
$utf8 = static function ($v) {
    $v = (string) $v;
    return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    array_walk_recursive($_POST, static function (&$v) use ($utf8) { $v = $utf8($v); });
    try {
        if (!hash_equals($_SESSION['cn_config_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Recarga la página e inténtalo de nuevo.');
        }
        $firmas = [];
        for ($i = 0; $i < CENGI_CN_MAX_FIRMAS; $i++) {
            $nombre = mb_substr(trim((string) ($_POST['firma_nombre'][$i] ?? '')), 0, 160, 'UTF-8');
            $cargo = mb_substr(trim((string) ($_POST['firma_cargo'][$i] ?? '')), 0, 200, 'UTF-8');
            $imagen = cengi_cn_firma_valida($cfg['firmas'][$i]['imagen'] ?? '');
            $archivo = [
                'error' => $_FILES['firma_imagen']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'tmp_name' => $_FILES['firma_imagen']['tmp_name'][$i] ?? '',
            ];
            if ($archivo['error'] !== UPLOAD_ERR_NO_FILE) {
                $imagen = cengi_cn_firma_desde_archivo($archivo);
            } elseif (!empty($_POST['firma_quitar'][$i])) {
                $imagen = '';
            }
            if ($nombre === '' && $cargo === '') {
                continue; // espacio de firmante vacío
            }
            if ($nombre === '' || $cargo === '') {
                throw new InvalidArgumentException('Cada firmante necesita nombre y cargo (firmante ' . ($i + 1) . ').');
            }
            $firmas[] = ['nombre' => $nombre, 'cargo' => $cargo, 'imagen' => $imagen];
        }
        if (!$firmas) {
            throw new InvalidArgumentException('Agrega al menos un firmante.');
        }
        $lugar = mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 160, 'UTF-8');
        $pie = array_values(array_filter(array_map(static function ($l) { return mb_substr(trim($l), 0, 200, 'UTF-8'); }, preg_split('/\R/u', (string) ($_POST['pie'] ?? ''))), 'strlen'));
        if ($lugar === '') {
            throw new InvalidArgumentException('Indica el lugar que aparece junto a la fecha.');
        }
        cengi_cn_guardar_config($db, ['lugar' => $lugar, 'firmas' => $firmas, 'pie' => array_slice($pie, 0, 6),
            'actualizado_por' => cengi_pp_usuario_nombre(), 'actualizado' => date('Y-m-d H:i:s')]);
        header('Location: constancia_notas_config.php?' . http_build_query(array_filter(['curso' => $cursoId, 'ok' => 1])));
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo guardar la configuración.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Configuración de constancia: ' . $e->getMessage());
        }
    }
}

// Participante de ejemplo para la vista previa (del curso de origen, si viene).
$stmt = $db->prepare('SELECT a.id, p.nombre_participantes, c.nombre_cursos FROM asignaciones a JOIN participantes p ON p.id = a.participantes_id JOIN cursos c ON c.id = a.cursos_id
    WHERE a.estado_asignaciones = 1 ' . ($cursoId > 0 ? 'AND a.cursos_id = ? ' : '') . 'ORDER BY (SELECT COUNT(*) FROM control_cursos cc WHERE cc.asignacion_id = a.id AND cc.posevaluacion IS NOT NULL) DESC, a.id DESC LIMIT 1');
$stmt->execute($cursoId > 0 ? [$cursoId] : []);
$ejemplo = $stmt->fetch(PDO::FETCH_ASSOC);
$volver = $cursoId > 0 ? 'ver_participante_curso.php?id=' . $cursoId : 'ver_participante_curso.php';
$slots = array_pad($cfg['firmas'], CENGI_CN_MAX_FIRMAS, ['nombre' => '', 'cargo' => '', 'imagen' => '']);
?>
<!DOCTYPE html>
<html lang="es">
<?php include 'head.php'; ?>
<body class="cengi-canvas cg-tema">
<link rel="stylesheet" href="css/constancia_config.css?v=<?= (int) @filemtime(__DIR__ . '/css/constancia_config.css') ?>">
<?php menu_render(); ?>
<main class="container cnc-page">
<?php cengi_banner(null, null, 'Seguimiento por curso · documentos',
    ($ejemplo ? '<a class="cnc-btn is-claro" href="constancia_notas.php?asignacion_id=' . (int) $ejemplo['id'] . '" target="_blank" rel="noopener"><span class="glyphicon glyphicon-eye-open" aria-hidden="true"></span> Vista previa</a>' : '')
    . '<a class="cnc-btn is-linea-claro" href="' . $h($volver) . '"><span class="glyphicon glyphicon-arrow-left" aria-hidden="true"></span> Volver al seguimiento</a>'); ?>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success" role="status">Configuración guardada. Las próximas constancias ya salen con estas firmas<?= $ejemplo ? ' — <a href="constancia_notas.php?asignacion_id=' . (int) $ejemplo['id'] . '" target="_blank" rel="noopener">ver vista previa</a>' : '' ?>.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="cnc-form">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['cn_config_csrf']) ?>"><input type="hidden" name="curso" value="<?= $cursoId ?>">

    <section class="cnc-card">
        <div class="cnc-head"><h2>Firmantes</h2><span>Hasta <?= CENGI_CN_MAX_FIRMAS ?>. Deja un espacio vacío para no usarlo.</span></div>
        <div class="cnc-firmas">
        <?php foreach ($slots as $i => $f): $img = cengi_cn_firma_valida($f['imagen'] ?? ''); ?>
            <fieldset class="cnc-firma">
                <legend>Firmante <?= $i + 1 ?></legend>
                <div class="cnc-firma-vista" data-cnc-vista="<?= $i ?>">
                    <div class="cnc-firma-img"><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="Firma de <?= $h($f['nombre']) ?>"><?php else: ?><span>Sin firma digital<br><small>Se imprime la línea para firmar a mano</small></span><?php endif; ?></div>
                    <div class="cnc-firma-linea"><b data-cnc-nombre><?= $h($f['nombre'] ?: 'Nombre del firmante') ?></b><small data-cnc-cargo><?= $h($f['cargo'] ?: 'Cargo') ?></small><small>-CENGICAÑA-</small></div>
                </div>
                <label>Nombre<input class="form-control" name="firma_nombre[<?= $i ?>]" maxlength="160" value="<?= $h($f['nombre']) ?>" placeholder="Ing. Nombre Apellido" data-cnc-in-nombre="<?= $i ?>"></label>
                <label>Cargo<input class="form-control" name="firma_cargo[<?= $i ?>]" maxlength="200" value="<?= $h($f['cargo']) ?>" placeholder="Cargo en CENGICAÑA" data-cnc-in-cargo="<?= $i ?>"></label>
                <label class="cnc-archivo"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span> <?= $img !== '' ? 'Reemplazar firma' : 'Subir firma escaneada' ?>
                    <input type="file" name="firma_imagen[<?= $i ?>]" accept="image/png,image/jpeg" data-cnc-in-img="<?= $i ?>"></label>
                <?php if ($img !== ''): ?><label class="cnc-check"><input type="checkbox" name="firma_quitar[<?= $i ?>]" value="1"> Quitar la firma digital</label><?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <p class="cnc-ayuda"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> Firma con tinta oscura en papel blanco, escanéala o tómale una foto bien iluminada y súbela en PNG o JPG (hasta 2 MB). El sistema la recorta a tamaño y vuelve transparente el fondo blanco para que quede limpia sobre la línea.</p>
    </section>

    <section class="cnc-card cnc-dos">
        <label>Lugar <small>aparece como «Lugar y Fecha: …, <?= (int) date('j') ?> de <?= mb_strtolower(CENGI_PP_MESES[(int) date('n')], 'UTF-8') ?> de <?= date('Y') ?>»</small>
            <input class="form-control" name="lugar" maxlength="160" value="<?= $h($cfg['lugar']) ?>" required></label>
        <label>Pie de página <small>una línea por renglón (máximo 6)</small>
            <textarea class="form-control" name="pie" rows="5"><?= $h(implode("\n", $cfg['pie'])) ?></textarea></label>
    </section>

    <div class="cnc-acciones">
        <button class="cnc-btn" type="submit"><span class="glyphicon glyphicon-floppy-disk" aria-hidden="true"></span> Guardar configuración</button>
        <?php if (!empty($cfg['actualizado_por'])): ?><small>Último cambio: <?= $h($cfg['actualizado_por']) ?> · <?= $h(date('d/m/Y H:i', strtotime((string) $cfg['actualizado']))) ?></small><?php endif; ?>
    </div>
</form>
</main>
<script>
(function () {
    // Vista previa en vivo del bloque de firma: nombre, cargo e imagen elegida.
    function q(s) { return document.querySelector(s); }
    document.querySelectorAll('[data-cnc-in-nombre]').forEach(function (el) {
        el.addEventListener('input', function () { q('[data-cnc-vista="' + el.dataset.cncInNombre + '"] [data-cnc-nombre]').textContent = el.value || 'Nombre del firmante'; });
    });
    document.querySelectorAll('[data-cnc-in-cargo]').forEach(function (el) {
        el.addEventListener('input', function () { q('[data-cnc-vista="' + el.dataset.cncInCargo + '"] [data-cnc-cargo]').textContent = el.value || 'Cargo'; });
    });
    document.querySelectorAll('[data-cnc-in-img]').forEach(function (el) {
        el.addEventListener('change', function () {
            var f = el.files[0], caja = q('[data-cnc-vista="' + el.dataset.cncInImg + '"] .cnc-firma-img');
            if (!f) return;
            if (f.size > 2 * 1024 * 1024 || ['image/png', 'image/jpeg'].indexOf(f.type) === -1) { alert('La firma debe ser PNG o JPG de hasta 2 MB.'); el.value = ''; return; }
            caja.innerHTML = '';
            var img = document.createElement('img'); img.src = URL.createObjectURL(f); img.alt = 'Vista previa de la firma'; caja.appendChild(img);
        });
    });
})();
</script>
</body>
</html>
