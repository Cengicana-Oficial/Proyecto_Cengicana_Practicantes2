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
        body { margin: 0; background: #F3F5F0; color: #1C2517; font-family: Arial, Helvetica, sans-serif; }
        .val-top { background: #176b3a; color: #fff; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
        .val-top img { height: 44px; background: #fff; border-radius: 8px; padding: 4px 10px; }
        .val-top h1 { margin: 0; font-size: 18px; }
        .val-main { max-width: 1180px; margin: 0 auto; padding: 24px 16px 40px; }
        .val-card { background: #fff; border: 1px solid #E1E5DD; border-radius: 12px; padding: 20px; margin-bottom: 18px; }
        .val-form { display: flex; gap: 8px; flex-wrap: wrap; }
        .val-form input { flex: 1; min-width: 220px; padding: 10px 12px; border: 1px solid #C9D0C4; border-radius: 8px; font: inherit; text-transform: uppercase; }
        .val-btn { padding: 10px 16px; border: 0; border-radius: 8px; background: #176b3a; color: #fff; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .val-ok { border-left: 5px solid #3E7A12; }
        .val-no { border-left: 5px solid #B23223; }
        .val-ok h2 { color: #2F6B12; margin: 0 0 8px; font-size: 20px; }
        .val-no h2 { color: #B23223; margin: 0 0 8px; font-size: 20px; }
        .val-dato { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid #EEF1EA; font-size: 14px; }
        .val-dato:last-child { border-bottom: 0; }
        .val-preview { overflow: hidden; border-radius: 8px; background: #EEF1EA; }
        .val-mono { font-family: 'Courier New', monospace; }
    </style>
</head>
<body>
<header class="val-top"><img src="css/images/cengi.png" alt="CENGICAÑA"><h1>Validación de diplomas y constancias</h1></header>
<main class="val-main">
    <section class="val-card">
        <form class="val-form" method="get">
            <label for="val-codigo" style="width:100%;font-weight:700;font-size:13px;">Código de validación</label>
            <input type="text" id="val-codigo" name="c" value="<?php echo cengi_dip_h($codigo); ?>" placeholder="CEN-CU-2026-0001-XXXX" maxlength="60" required>
            <button type="submit" class="val-btn">Validar</button>
        </form>
    </section>

    <?php if ($codigo !== '' && !$diploma): ?>
        <section class="val-card val-no"><h2>No encontramos este documento</h2>
            <p>No existe un diploma o constancia emitido por CENGICAÑA con el código <b class="val-mono"><?php echo cengi_dip_h($codigo); ?></b>. Revisa que esté escrito igual que en el documento.</p></section>
    <?php elseif ($diploma): ?>
        <section class="val-card val-ok">
            <h2>✓ Documento válido</h2>
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
        </section>
        <?php if ($render): ?>
            <section class="val-card"><div class="val-preview" id="val-preview"></div></section>
        <?php endif; ?>
    <?php endif; ?>
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
