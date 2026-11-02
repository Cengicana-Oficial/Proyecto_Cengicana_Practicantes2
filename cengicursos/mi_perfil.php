<?php
// Mi perfil del estudiante: datos de participante, foto y historial de cursos y diplomas.
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/aula_helpers.php';
require_once __DIR__ . '/estudiante_perfil_helpers.php';
date_default_timezone_set('America/Guatemala');
$usuarioId = cengi_usuario_actual_id();
if (!$usuarioId) {
    header('Location: ../login/');
    exit;
}
$db = conectar();
$h = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
if (empty($_SESSION['perfil_csrf'])) {
    $_SESSION['perfil_csrf'] = bin2hex(random_bytes(16));
}
$yo = cengi_est_participante($db, $usuarioId);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['perfil_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Recarga la página e inténtalo de nuevo.');
        }
        if (!$yo) {
            throw new InvalidArgumentException('Tu usuario no está vinculado a una ficha de participante.');
        }
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'foto') {
            cengi_est_guardar_foto($db, $yo['id'], $_FILES['foto'] ?? []);
            header('Location: mi_perfil.php?ok=foto');
            exit;
        }
        if ($accion === 'quitar_foto') {
            cengi_est_quitar_foto($db, $yo['id']);
            header('Location: mi_perfil.php?ok=quitada');
            exit;
        }
        throw new InvalidArgumentException('Acción no válida.');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo guardar la foto. Intenta nuevamente.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Mi perfil: ' . $e->getMessage());
        }
    }
}

$historial = $yo ? cengi_est_historial($db, $usuarioId) : [];
// Cursos con aula disponible para el estudiante (respeta los bloqueos de acceso).
$conAula = [];
foreach (aula_courses($db) as $c) {
    $conAula[(int) $c['id']] = true;
}
$cuenta = static function ($estado) use ($historial) {
    return count(array_filter($historial, static function ($f) use ($estado) { return $f['estado'] === $estado; }));
};
$diplomas = array_values(array_filter($historial, static function ($f) { return $f['tiene_diploma']; }));
// Aprobado solo cuenta al finalizar el curso: a mitad de curso la nota todavía puede cambiar.
$aprobados = count(array_filter($historial, static function ($f) { return $f['aprobado'] === true && $f['estado'] === 'finalizado'; }));
$horas = array_sum(array_map(static function ($f) { return $f['estado'] === 'finalizado' ? (float) $f['horas'] : 0; }, $historial));
$fecha = static function ($v) { return $v && $v !== '0000-00-00' ? date('d/m/Y', strtotime((string) $v)) : '—'; };
$num = static function ($n) { return rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); };
$iniciales = '';
foreach (array_slice(preg_split('/\s+/', trim((string) ($yo['nombre'] ?? $_SESSION['usuario'] ?? ''))), 0, 2) as $parte) {
    if ($parte !== '') {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1));
    }
}
$tieneFoto = $yo && !empty($yo['foto_path']) && cengi_est_foto_archivo($yo['foto_path']);
$estados = ['en_curso' => ['En curso', 'is-curso'], 'proximo' => ['Próximo', 'is-proximo'], 'finalizado' => ['Finalizado', 'is-fin']];
$enlaceDiploma = static function ($f) {
    if (trim((string) $f['diploma_pdf']) !== '') {
        return ['descargar_diploma.php?asignacion_id=' . (int) $f['asignacion_id'], 'Descargar diploma'];
    }
    return ['validar_diploma.php?c=' . rawurlencode((string) $f['diploma_codigo']), 'Ver diploma'];
};
$avisos = ['foto' => 'Tu foto se actualizó.', 'quitada' => 'Quitaste tu foto de perfil.'];
$v = static function ($archivo) { return (int) (@filemtime(__DIR__ . '/' . $archivo) ?: 1); };
?>
<!DOCTYPE html>
<html lang="es">
<?php include 'head.php'; ?>
<body class="cengi-canvas cg-tema">
<link rel="stylesheet" href="css/mi_perfil.css?v=<?= $v('css/mi_perfil.css') ?>">
<?php menu_render(true); ?>
<main class="container mp-page">
    <?php cengi_banner('Mi perfil', 'Tu información, tu foto y tu historial de capacitación', 'Mi aprendizaje'); ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <?php if (isset($avisos[$_GET['ok'] ?? ''])): ?><div class="alert alert-success" role="status"><?= $h($avisos[$_GET['ok']]) ?></div><?php endif; ?>

    <?php if (!$yo): ?>
    <section class="mp-card mp-vacio">
        <span class="glyphicon glyphicon-user" aria-hidden="true"></span>
        <h2>Tu usuario todavía no está vinculado a una ficha de participante</h2>
        <p>Cuando Capacitación te inscriba en un curso, aquí verás tus datos, tus cursos y tus diplomas.</p>
    </section>
    <?php else: ?>

    <section class="mp-card mp-perfil">
        <div class="mp-foto-col">
            <div class="mp-foto" data-mp-foto>
                <?php if ($tieneFoto): ?><img src="mi_perfil_foto.php?v=<?= $h(substr(md5((string) $yo['foto_path']), 0, 8)) ?>" alt="Tu foto de perfil"><?php else: ?><span><?= $h($iniciales ?: 'Yo') ?></span><?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" data-mp-form>
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['perfil_csrf']) ?>"><input type="hidden" name="accion" value="foto">
                <label class="mp-btn"><span class="glyphicon glyphicon-camera" aria-hidden="true"></span> <?= $tieneFoto ? 'Cambiar foto' : 'Agregar foto' ?>
                    <input type="file" name="foto" accept="image/jpeg,image/png" data-mp-input hidden></label>
            </form>
            <?php if ($tieneFoto): ?>
            <form method="post" onsubmit="return confirm('¿Quitar tu foto de perfil?');">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['perfil_csrf']) ?>"><input type="hidden" name="accion" value="quitar_foto">
                <button class="mp-link" type="submit">Quitar foto</button>
            </form>
            <?php endif; ?>
            <small class="mp-ayuda">JPG o PNG · máximo 5 MB</small>
        </div>
        <div class="mp-datos">
            <h2><?= $h($yo['nombre']) ?></h2>
            <p class="mp-cargo"><?= $h(implode(' · ', array_unique(array_filter([$yo['puesto'], $yo['area']])))) ?: 'Participante' ?></p>
            <?php if ($yo['ingenio']): ?><p class="mp-ingenio"><span class="glyphicon glyphicon-briefcase" aria-hidden="true"></span> <?= $h($yo['ingenio']) ?></p><?php endif; ?>
            <dl class="mp-ficha">
                <div><dt>Correo</dt><dd><?= $h($yo['correo'] ?: '—') ?></dd></div>
                <div><dt>Teléfono</dt><dd><?= $h($yo['telefono'] ?: '—') ?></dd></div>
                <div><dt>Grado académico</dt><dd><?= $h($yo['grado'] ?: '—') ?></dd></div>
                <div><dt>Documento</dt><dd><?= $h($yo['cui'] ? '•••• ' . mb_substr(preg_replace('/\s+/', '', $yo['cui']), -4) : '—') ?></dd></div>
            </dl>
            <p class="mp-nota"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> ¿Algún dato no es correcto? Avísale a Capacitación para corregirlo; así tus diplomas salen con la información correcta.</p>
        </div>
    </section>

    <div class="mp-kpis">
        <div class="mp-kpi"><b><?= count($historial) ?></b><span>Cursos</span></div>
        <div class="mp-kpi is-verde2"><b><?= $cuenta('en_curso') ?></b><span>En curso</span></div>
        <div class="mp-kpi is-gris"><b><?= $cuenta('finalizado') ?></b><span>Finalizados</span></div>
        <div class="mp-kpi is-verde"><b><?= $aprobados ?></b><span>Aprobados</span></div>
        <div class="mp-kpi is-naranja"><b><?= count($diplomas) ?></b><span>Diplomas</span></div>
        <div class="mp-kpi is-amarillo"><b><?= $num($horas) ?></b><span>Horas de capacitación</span></div>
    </div>

    <?php if ($diplomas): ?>
    <section class="mp-card">
        <div class="mp-head"><h2><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span> Mis diplomas</h2><span><?= count($diplomas) ?></span></div>
        <div class="mp-diplomas">
            <?php foreach ($diplomas as $f): [$url, $texto] = $enlaceDiploma($f); ?>
            <article class="mp-diploma">
                <span class="mp-sello" aria-hidden="true"><span class="glyphicon glyphicon-certificate"></span></span>
                <div>
                    <strong><?= $h($f['nombre_cursos']) ?></strong>
                    <small><?= $h(implode(' · ', array_filter([$f['categoria'], $f['diploma_fecha'] ? 'Emitido el ' . $fecha($f['diploma_fecha']) : ($f['fin'] ? 'Finalizó el ' . $fecha($f['fin']) : '')]))) ?></small>
                    <div class="mp-acciones">
                        <a class="mp-btn is-sm" href="<?= $h($url) ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> <?= $h($texto) ?></a>
                        <?php if ($f['diploma_codigo']): ?><a class="mp-link" href="validar_diploma.php?c=<?= $h(rawurlencode($f['diploma_codigo'])) ?>" target="_blank" rel="noopener">Verificar · <?= $h($f['diploma_codigo']) ?></a><?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="mp-card">
        <div class="mp-head"><h2><span class="glyphicon glyphicon-education" aria-hidden="true"></span> Historial de cursos</h2>
            <?php if ($historial): ?>
            <nav class="mp-filtros" aria-label="Filtrar cursos">
                <button type="button" class="is-active" data-mp-filtro="todos">Todos <b><?= count($historial) ?></b></button>
                <?php foreach ($estados as $k => [$t]): if (!$cuenta($k)) continue; ?><button type="button" data-mp-filtro="<?= $k ?>"><?= $h($t) ?> <b><?= $cuenta($k) ?></b></button><?php endforeach; ?>
                <?php if ($diplomas): ?><button type="button" data-mp-filtro="diploma">Con diploma <b><?= count($diplomas) ?></b></button><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php if (!$historial): ?>
            <div class="mp-vacio"><span class="glyphicon glyphicon-education" aria-hidden="true"></span><p>Todavía no tienes cursos registrados.</p></div>
        <?php else: ?>
        <div class="mp-cursos">
            <?php foreach ($historial as $f): [$estadoTxt, $estadoCls] = $estados[$f['estado']]; ?>
            <article class="mp-curso <?= $estadoCls ?>" data-estado="<?= $f['estado'] ?>" data-diploma="<?= $f['tiene_diploma'] ? '1' : '0' ?>">
                <div class="mp-curso-top">
                    <span class="mp-estado <?= $estadoCls ?>"><?= $h($estadoTxt) ?></span>
                    <?php if ($f['aprobado'] === true && $f['estado'] === 'finalizado'): ?><span class="mp-res is-ok">✓ Aprobado</span><?php elseif ($f['aprobado'] === false && $f['estado'] === 'finalizado'): ?><span class="mp-res is-no">No aprobado</span><?php endif; ?>
                    <?php if ($f['tiene_diploma']): ?><span class="mp-res is-dip"><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span> Diploma</span><?php endif; ?>
                </div>
                <h3><?= $h($f['nombre_cursos']) ?></h3>
                <p class="mp-sub"><?= $h(implode(' · ', array_filter([$f['codigo_curso'], $f['categoria'], $f['modalidad']]))) ?></p>
                <ul class="mp-meta">
                    <li><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?= $h($fecha($f['inicio'])) ?> – <?= $h($fecha($f['fin'])) ?></li>
                    <?php if ((float) $f['horas'] > 0): ?><li><span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?= $h($num($f['horas'])) ?> h</li><?php endif; ?>
                </ul>
                <div class="mp-indicadores">
                    <div><span>Asistencia</span>
                        <?php if ($f['asistencia_pct'] !== null): ?><b><?= $h($num($f['asistencia_pct'])) ?> %</b><span class="mp-barra"><i style="width:<?= (int) round($f['asistencia_pct']) ?>%"></i></span>
                        <?php else: ?><b class="mp-muted"><?= $f['estado'] === 'proximo' ? 'Aún no inicia' : 'Sin registro' ?></b><?php endif; ?></div>
                    <div><span>Nota final</span><b class="<?= $f['aprobado'] === true ? 'is-ok' : ($f['aprobado'] === false ? 'is-no' : 'mp-muted') ?>"><?= $f['nota'] !== null ? $h($num($f['nota'])) . ' pts' : 'Sin nota' ?></b></div>
                </div>
                <div class="mp-acciones">
                    <?php if (isset($conAula[(int) $f['curso_id']]) && $f['estado'] !== 'proximo'): ?><a class="mp-btn is-sm" href="mi_aula.php?curso=<?= (int) $f['curso_id'] ?>"><span class="glyphicon glyphicon-blackboard" aria-hidden="true"></span> Entrar al aula</a><?php endif; ?>
                    <?php if ($f['tiene_diploma']): [$url, $texto] = $enlaceDiploma($f); ?><a class="mp-btn is-sm is-linea" href="<?= $h($url) ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> <?= $h($texto) ?></a><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="mp-nota">Se aprueba con <?= CENGI_NOTA_APROBACION ?> puntos o más en la evaluación final. Las horas de capacitación suman los cursos finalizados.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<script>
(function () {
    // Foto: valida en el navegador, muestra la vista previa y envía el formulario.
    var input = document.querySelector('[data-mp-input]'), form = document.querySelector('[data-mp-form]'), caja = document.querySelector('[data-mp-foto]');
    if (input && form) {
        input.addEventListener('change', function () {
            var f = this.files[0];
            if (!f) return;
            if (f.size > 5 * 1024 * 1024 || ['image/jpeg', 'image/png'].indexOf(f.type) === -1) { alert('Selecciona una foto JPG o PNG de máximo 5 MB.'); this.value = ''; return; }
            if (caja) { caja.innerHTML = ''; var img = document.createElement('img'); img.src = URL.createObjectURL(f); img.alt = 'Vista previa'; caja.appendChild(img); caja.classList.add('is-subiendo'); }
            form.submit();
        });
    }
    // Filtros del historial.
    var botones = document.querySelectorAll('[data-mp-filtro]');
    Array.prototype.forEach.call(botones, function (b) {
        b.addEventListener('click', function () {
            var f = b.getAttribute('data-mp-filtro');
            Array.prototype.forEach.call(botones, function (x) { x.classList.toggle('is-active', x === b); });
            Array.prototype.forEach.call(document.querySelectorAll('.mp-curso'), function (c) {
                c.hidden = !(f === 'todos' || (f === 'diploma' ? c.getAttribute('data-diploma') === '1' : c.getAttribute('data-estado') === f));
            });
        });
    });
})();
</script>
</body>
</html>
