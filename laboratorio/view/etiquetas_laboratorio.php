<?php
/**
 * etiquetas_laboratorio.php
 *
 * Etiquetas de laboratorio de una recepción (Recepción → Acciones → Imprimir etiquetas): una por cada
 * muestra ingresada, con su número de laboratorio, lote y tipo, código de barras (Code 128) y, si se
 * elige, código QR. Mismo formato que "Etiquetas del laboratorio" en Identificación de muestras.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/catalogo_analisis_helper.php';

lab_require_module_access();
if (!lab_can_any(['laboratorio.solicitudes.crear', 'laboratorio.lotes.ver'])) {
    lab_forbidden();
}

$idSolicitud = filter_input(INPUT_GET, 'id_solicitud', FILTER_VALIDATE_INT) ?: 0;
$stmt = $conexion->prepare('SELECT s.id_solicitud, s.institucion, s.fecha_ingreso, l.codigo_lote, tm.nombre AS tipo_nombre, tm.prefijo
    FROM solicitud s LEFT JOIN lote l ON l.id_lote = s.id_lote LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo WHERE s.id_solicitud = ?');
$stmt->execute([$idSolicitud]);
$recepcion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$recepcion) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'La recepción solicitada no existe.';
    exit;
}
$stmt = $conexion->prepare("SELECT id_muestra, codigo_lab FROM muestra WHERE id_solicitud = ? AND codigo_lab IS NOT NULL AND codigo_lab <> '' ORDER BY numero_muestra, id_muestra");
$stmt->execute([$idSolicitud]);
$muestras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipo = labCatalogoAnalisisEtiquetaModuloPlural(labCatalogoAnalisisClaveModulo((string) $recepcion['tipo_nombre'], (string) $recepcion['prefijo']));
$datos = [
    'lote' => (string) ($recepcion['codigo_lote'] ?? ''),
    'tipo' => $tipo,
    'cliente' => trim((string) ($recepcion['institucion'] ?? '')),
    'muestras' => array_map(static fn($m) => ['id' => (int) $m['id_muestra'], 'codigo' => (string) $m['codigo_lab']], $muestras),
];
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetas · Lote <?= $e($datos['lote']) ?></title>
    <style>
        :root { --g: #73bc25; --gd: #477817; --ink: #20242a; --muted: #6c7665; --line: #dde5d9; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; color: var(--ink); background: #f4f6f2; }
        .bar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 14px; padding: 14px 22px; background: #fff; border-bottom: 1px solid var(--line); box-shadow: 0 2px 8px #0000000d; }
        .bar h1 { margin: 0; font-size: 16px; }
        .bar p { margin: 2px 0 0; font-size: 12px; color: var(--muted); }
        .bar .grow { flex: 1; min-width: 220px; }
        .bar label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
        .bar input[type=checkbox] { accent-color: var(--g); }
        .btn { padding: 9px 16px; border: 1px solid #cdd9c4; border-radius: 8px; background: #fff; color: #365e25; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
        .btn.primary { background: var(--g); border-color: var(--g); color: #fff; }
        .btn:disabled { opacity: .5; cursor: default; }
        .wrap { padding: 18px 22px 40px; }
        .sel { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .sel label { padding: 6px 9px; border: 1px solid var(--line); border-radius: 6px; background: #fff; font-size: 11.5px; font-family: Consolas, monospace; cursor: pointer; }
        .sel label:has(input:not(:checked)) { opacity: .5; }
        .resumen { font-size: 12.5px; color: var(--muted); margin: 0 0 10px; }
        .sheet { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; max-width: 900px; }
        .label { display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 12px; border: 1px dashed #aebca5; background: #fff; break-inside: avoid; }
        .label > div:first-child { flex: 1; min-width: 0; text-align: center; }
        .label strong { display: block; font-size: 10px; letter-spacing: 1px; }
        .label b { display: block; margin: 5px 0; font: 700 20px Consolas, monospace; overflow-wrap: anywhere; }
        .label small { display: block; font-size: 10px; }
        .label .c128 { display: block; width: 100%; height: 38px; margin-top: 8px; }
        .label .qr { width: 65px; flex-shrink: 0; }
        .label .qr svg { width: 100%; height: auto; }
        .vacio { padding: 30px; text-align: center; color: var(--muted); background: #fff; border: 1px dashed var(--line); border-radius: 10px; }
        @media print {
            @page { size: letter portrait; margin: 10mm; }
            body { background: #fff; }
            .bar, .sel, .resumen, .noprint { display: none !important; }
            .wrap { padding: 0; }
            .sheet { grid-template-columns: repeat(2, 90mm); gap: 4mm 8mm; max-width: none; }
            .label { height: 32mm; padding: 3mm; border: 1px dashed #aaa; }
            .label b { font-size: 16px; }
            .label .c128 { height: 10mm; }
            .label .qr { width: 18mm; }
        }
    </style>
</head>
<body>
    <header class="bar">
        <div class="grow">
            <h1>Etiquetas de laboratorio · Lote <?= $e($datos['lote'] ?: '—') ?></h1>
            <p><?= $e($tipo) ?> · <?= $e($datos['cliente'] ?: 'Sin cliente') ?> · <?= count($muestras) ?> muestra<?= count($muestras) === 1 ? '' : 's' ?> · Solicitud #<?= (int) $recepcion['id_solicitud'] ?></p>
        </div>
        <label><input type="checkbox" id="optBarras" checked> Código de barras</label>
        <label><input type="checkbox" id="optQr"> Código QR</label>
        <button type="button" class="btn" id="btnTodas">Todas / ninguna</button>
        <button type="button" class="btn primary" id="btnImprimir">Imprimir etiquetas</button>
    </header>
    <main class="wrap">
        <?php if (!$muestras): ?>
            <div class="vacio">Esta recepción todavía no tiene números de laboratorio asignados.</div>
        <?php else: ?>
            <div class="sel" id="seleccion"></div>
            <p class="resumen" id="resumen"></p>
            <p class="resumen noprint">Dos etiquetas por fila. Imprima a escala 100 % (sin “ajustar a la página”).</p>
            <div class="sheet" id="hoja"></div>
        <?php endif; ?>
    </main>
    <script type="application/json" id="datos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="../../cengicursos/js/qrcode-generator.js"></script>
    <script src="../js/identificacion_lote.js?v=<?= (int) filemtime(__DIR__ . '/../js/identificacion_lote.js') ?>"></script>
    <script>
    (function () {
        var datos = JSON.parse(document.getElementById('datos').textContent);
        var hoja = document.getElementById('hoja');
        if (!hoja) return;
        var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
        var elegidas = new Set(datos.muestras.map(function (m) { return m.id; }));
        var c128 = window.CengiIdentificacion && window.CengiIdentificacion.code128Svg;
        function render() {
            document.getElementById('seleccion').innerHTML = datos.muestras.map(function (m) {
                return '<label><input type="checkbox" data-id="' + m.id + '"' + (elegidas.has(m.id) ? ' checked' : '') + '> ' + esc(m.codigo) + '</label>';
            }).join('');
            var lista = datos.muestras.filter(function (m) { return elegidas.has(m.id); });
            var barras = document.getElementById('optBarras').checked, qr = document.getElementById('optQr').checked && window.qrcode;
            document.getElementById('resumen').textContent = lista.length + ' etiqueta' + (lista.length === 1 ? '' : 's') + ' seleccionada' + (lista.length === 1 ? '' : 's') + ' · ' + Math.ceil(lista.length / 2) + ' fila' + (Math.ceil(lista.length / 2) === 1 ? '' : 's');
            document.getElementById('btnImprimir').disabled = !lista.length;
            hoja.innerHTML = lista.map(function (m) {
                var q = '';
                if (qr) { var g = window.qrcode(0, 'M'); g.addData(m.codigo); g.make(); q = '<div class="qr">' + g.createSvgTag({cellSize: 2, margin: 4, scalable: true}) + '</div>'; }
                var b = barras && c128 && /^[\x20-\x7e]+$/.test(m.codigo) ? c128(m.codigo, 36) : '';
                return '<article class="label"><div><strong>CENGICAÑA</strong><b>' + esc(m.codigo) + '</b><small>' + esc('Lote ' + datos.lote + ' · ' + datos.tipo) + '</small>' + b + '</div>' + q + '</article>';
            }).join('');
        }
        document.getElementById('seleccion').addEventListener('change', function (e) {
            var id = Number(e.target.dataset.id); if (!id) return;
            if (e.target.checked) elegidas.add(id); else elegidas.delete(id);
            render();
        });
        document.getElementById('btnTodas').addEventListener('click', function () {
            elegidas = elegidas.size === datos.muestras.length ? new Set() : new Set(datos.muestras.map(function (m) { return m.id; }));
            render();
        });
        ['optBarras', 'optQr'].forEach(function (id) { document.getElementById(id).addEventListener('change', render); });
        document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
        render();
    })();
    </script>
</body>
</html>
