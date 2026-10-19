<?php
/**
 * Pagina publica de validacion de diplomas y constancias (enlace del QR y del correo).
 * ?c=<codigo>: muestra si el documento es valido y, si lo genero el diseñador, lo dibuja
 * para verlo, imprimirlo o guardarlo como PDF. Los PDF cargados manualmente se enlazan.
 * Los codigos llevan una parte aleatoria, asi que no se pueden recorrer consecutivamente.
 */
require_once __DIR__ . '/diplomas_helpers.php';

$db = conectar();
$codigo = strtoupper(trim((string) ($_GET['c'] ?? '')));
$diploma = $codigo !== '' ? cengi_dip_por_codigo($db, $codigo) : null;
$render = null;
if ($diploma && !$diploma['pdf_path']) {
    $clave = $diploma['plantilla_clave'] ?: ($diploma['tipo'] === 'evento' ? 'ev:' . $diploma['evento_id'] : 'cu:' . $diploma['cursos_id']);
    $actividad = cengi_dip_actividad($db, $clave);
    if ($actividad) {
        $idParticipante = (int) ($diploma['tipo'] === 'evento' ? $diploma['evento_participante_id'] : $diploma['asignacion_id']);
        $participante = null;
        foreach (cengi_dip_participantes($db, $actividad) as $p) {
            if ($p['id'] === $idParticipante) {
                $participante = $p;
            }
        }
        // Si el participante ya no esta en la actividad, se usan los datos congelados al emitir.
        $participante = $participante ?: ['nombre' => $diploma['nombre'], 'cui' => '', 'institucion' => '', 'rol' => 'participante'];
        $participante['nombre'] = $diploma['nombre_participante'] ?: $participante['nombre'];
        if ($diploma['actividad']) {
            $actividad['actividad'] = $diploma['actividad'];
        }
        $render = ['actividad' => $actividad, 'participante' => $participante, 'plantilla' => cengi_dip_plantilla($db, $actividad),
            'codigo' => $diploma['codigo_unico'], 'url' => cengi_dip_url_validar($diploma['codigo_unico'])];
    }
}
$documento = $diploma ? ($diploma['documento'] ?: ($diploma['tipo'] === 'evento' ? 'Constancia de participación' : 'Diploma')) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Validación de diplomas · CENGICAÑA</title>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <link rel="stylesheet" href="css/diplomas.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/diplomas.css'); ?>">
    <style>
        /* Paleta: verde #73BC25, verde secundario #A3D300, amarillo #FFCC00, naranja #FF6B00, gris #CED2D5. */
        :root { --verde: #73BC25; --verde2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5; --deep: #1f5632; --ink: #1E2A1A; --muted: #55705F; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #F5F6F7; color: var(--ink); font-family: "Inter", "Segoe UI", Arial, Helvetica, sans-serif; }
        .val-franja { height: 6px; background: linear-gradient(90deg, var(--verde) 0 25%, var(--verde2) 25% 50%, var(--amarillo) 50% 75%, var(--naranja) 75% 100%); }
        .val-top { display: flex; align-items: center; gap: 14px; padding: 18px 20px; background: radial-gradient(circle at 95% -40%, rgba(163, 211, 0, .4) 0, rgba(163, 211, 0, 0) 45%), var(--deep); color: #fff; }
        .val-top img { height: 44px; padding: 4px 10px; border-radius: 8px; background: #fff; }
        .val-top h1 { margin: 0; font-size: 18px; }
        .val-top span { display: block; color: var(--verde2); font-size: 12.5px; font-weight: 400; }
        .val-main { max-width: 1180px; margin: 0 auto; padding: 24px 16px 40px; }
        .val-card { margin-bottom: 18px; padding: 20px; border: 1px solid #E4E7E1; border-radius: 14px; background: #fff; box-shadow: 0 6px 20px -14px rgba(31, 86, 50, .4); }
        .val-form { display: flex; flex-wrap: wrap; gap: 8px; }
        .val-form label { width: 100%; font-size: 13px; font-weight: 700; }
        .val-form input { flex: 1; min-width: 220px; padding: 11px 12px; border: 1px solid var(--gris); border-radius: 10px; font: 15px Consolas, 'Courier New', monospace; letter-spacing: .03em; text-transform: uppercase; }
        .val-form input:focus { border-color: var(--verde); outline: 0; box-shadow: 0 0 0 3px rgba(115, 188, 37, .22); }
        .val-btn { display: inline-block; padding: 11px 18px; border: 0; border-radius: 10px; background: var(--deep); color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .val-btn:hover { background: #163d24; }
        .val-resultado { display: flex; gap: 16px; align-items: flex-start; }
        .val-icono { display: inline-flex; flex: 0 0 52px; align-items: center; justify-content: center; height: 52px; border-radius: 50%; color: #fff; font-size: 26px; font-weight: 800; }
        .val-ok { border-left: 6px solid var(--verde); }
        .val-ok .val-icono { background: var(--verde); }
        .val-no { border-left: 6px solid var(--naranja); }
        .val-no .val-icono { background: var(--naranja); }
        .val-ok h2 { margin: 0 0 4px; color: var(--deep); font-size: 21px; }
        .val-no h2 { margin: 0 0 4px; color: #B34E00; font-size: 21px; }
        .val-sub { margin: 0 0 12px; color: var(--muted); font-size: 13.5px; }
        .val-datos { flex: 1; min-width: 0; }
        .val-dato { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #EEF1EA; font-size: 14px; }
        .val-dato span { color: var(--muted); }
        .val-dato b { text-align: right; }
        .val-dato:last-child { border-bottom: 0; }
        .val-preview { overflow: hidden; border-radius: 10px; background: #EEF1EA; }
        .val-mono { font-family: Consolas, 'Courier New', monospace; }
        .val-pie { margin-top: 10px; color: #8A958C; font-size: 12px; text-align: center; }
        @media (max-width: 560px) { .val-resultado { flex-direction: column; } .val-dato { flex-direction: column; gap: 2px; } .val-dato b { text-align: left; } }
    </style>
</head>
<body>
<div class="val-franja"></div>
<header class="val-top"><img src="css/images/cengi.png" alt="CENGICAÑA"><h1>Validación de diplomas y constancias<span>Coordinación de Capacitación · CENGICAÑA</span></h1></header>
<main class="val-main">
    <section class="val-card">
        <form class="val-form" method="get">
            <label for="val-codigo">Código de validación <small style="color:#8A958C;font-weight:400;">(aparece junto al QR del documento)</small></label>
            <input type="text" id="val-codigo" name="c" value="<?php echo cengi_dip_h($codigo); ?>" placeholder="CEN-CU-2026-0001-XXXX" maxlength="60" required>
            <button type="submit" class="val-btn">Validar</button>
        </form>
    </section>

    <?php if ($codigo !== '' && !$diploma): ?>
        <section class="val-card val-no"><div class="val-resultado"><span class="val-icono">!</span><div class="val-datos">
            <h2>No encontramos este documento</h2>
            <p class="val-sub">No existe un diploma o constancia emitido por CENGICAÑA con el código <b class="val-mono"><?php echo cengi_dip_h($codigo); ?></b>. Revisa que esté escrito igual que en el documento (letras, números y guiones).</p>
        </div></div></section>
    <?php elseif ($diploma): ?>
        <section class="val-card val-ok"><div class="val-resultado"><span class="val-icono">✓</span><div class="val-datos">
            <h2>Documento válido</h2>
            <p class="val-sub">Este documento fue emitido por CENGICAÑA y sus datos coinciden con nuestros registros.</p>
            <div class="val-dato"><span>Documento</span><b><?php echo cengi_dip_h($documento); ?></b></div>
            <div class="val-dato"><span>Emitido a</span><b><?php echo cengi_dip_h($diploma['nombre']); ?></b></div>
            <div class="val-dato"><span>Actividad</span><b><?php echo cengi_dip_h($diploma['actividad_nombre']); ?></b></div>
            <div class="val-dato"><span>Fecha de emisión</span><b><?php echo cengi_dip_h(cengi_dip_fecha($diploma['emitido_en'])); ?></b></div>
            <div class="val-dato"><span>Código</span><b class="val-mono"><?php echo cengi_dip_h($diploma['codigo_unico']); ?></b></div>
            <p style="margin:14px 0 0;">
                <?php if ($diploma['pdf_path']): ?>
                    <a class="val-btn" target="_blank" rel="noopener" href="<?php echo cengi_dip_h(cengi_normalizar_url_archivo($diploma['pdf_path'])); ?>">Ver documento (PDF)</a>
                <?php elseif ($render): ?>
                    <button type="button" class="val-btn" id="val-imprimir">Imprimir o guardar como PDF</button>
                <?php endif; ?>
            </p>
        </div></div></section>
        <?php if ($render): ?>
            <section class="val-card"><div class="val-preview" id="val-preview"></div></section>
        <?php endif; ?>
    <?php endif; ?>
    <p class="val-pie">Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar</p>
</main>
<?php if ($render): ?>
<script src="js/qrcode-generator.js"></script>
<script src="js/diplomas.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/diplomas.js'); ?>"></script>
<script>
(function () {
    'use strict';
    var R = <?php echo json_encode($render, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
    var v = CengiDiplomas.datos(R.actividad, R.participante, R.plantilla, R.codigo, R.url);
    var pagina = CengiDiplomas.html(R.plantilla, v);
    var caja = document.getElementById('val-preview');
    function dibujar() {
        var alto = R.plantilla.estilo === 'canva' && R.plantilla.canva ? R.plantilla.canva.h : 794;
        var k = Math.min(1, (caja.clientWidth || 800) / 1123);
        caja.innerHTML = '<div class="dip-escala" style="transform:scale(' + k + ');">' + pagina + '</div>';
        caja.style.height = Math.ceil(alto * k) + 'px';
    }
    dibujar();
    window.addEventListener('resize', dibujar);
    document.getElementById('val-imprimir').addEventListener('click', function () {
        CengiDiplomas.imprimir([pagina], R.plantilla, 'css/diplomas.css');
    });
})();
</script>
<?php endif; ?>
</body>
</html>
