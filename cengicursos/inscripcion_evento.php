<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/eventos_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = conectar();
$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$evento = null;

if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $db->prepare("
        SELECT id, nombre, tipo, modalidad_pago, costo, fecha, estado, hora, lugar, cupo,
               (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = eventos.id) AS registrados
        FROM eventos
        WHERE token_inscripcion = ?
          AND estado NOT IN ('Finalizado', 'Cancelado')
          AND (fecha IS NULL OR fecha >= CURDATE())
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$eventoPagado = $evento !== null && $evento['modalidad_pago'] === 'Pagado';

$resultado = trim((string) ($_GET['resultado'] ?? ''));
$mensaje = trim((string) ($_GET['mensaje'] ?? ''));
$codigo = trim((string) ($_GET['codigo'] ?? ''));

// Datos que deja guardar_inscripcion_evento.php en la sesion: el nombre para la pantalla
// de confirmacion y, ante un error, lo que la persona ya habia escrito.
$nombreInscrito = (string) ($_SESSION['cengi_ins_evt_nombre'] ?? '');
$previo = $resultado === 'error' && is_array($_SESSION['cengi_ins_evt_previo'] ?? null) ? $_SESSION['cengi_ins_evt_previo'] : [];
unset($_SESSION['cengi_ins_evt_previo']);

// Catalogo de ingenios como sugerencias del campo "Institucion o ingenio" (texto libre).
$ingeniosLista = $evento !== null
    ? $db->query('SELECT nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_COLUMN)
    : [];

// WhatsApp de soporte de pagos: solo se muestra el boton si el numero ya esta configurado.
$whatsappPago = preg_replace('/\D/', '', CENGI_EVENTO_TELEFONO_PAGO);
$whatsappUrl = '';
if ($evento !== null && strlen($whatsappPago) >= 8) {
    $whatsappUrl = 'https://wa.me/' . $whatsappPago . '?text=' . rawurlencode('Hola, quiero pagar mi inscripción al evento ' . $evento['nombre'] . ' desde Guatemala. ¿Me comparten los datos de la cuenta?');
}

function cengi_ins_evt_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Fecha como en el prototipo: "31 jul 2026". */
function cengi_ins_evt_fecha($fecha)
{
    $ts = $fecha ? strtotime((string) $fecha) : false;
    if ($ts === false) {
        return 'Fecha por confirmar';
    }
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('d', $ts) . ' ' . $meses[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function cengi_ins_evt_q($valor)
{
    return 'Q' . number_format(round((float) $valor), 0, '.', ',');
}

function cengi_ins_evt_previo(array $previo, $campo)
{
    return cengi_ins_evt_html(is_string($previo[$campo] ?? null) ? $previo[$campo] : '');
}

// Cupo lleno: el formulario ya no se muestra (guardar_inscripcion_evento.php tambien lo valida).
$cupoLleno = $evento !== null && (int) ($evento['cupo'] ?? 0) > 0 && (int) $evento['registrados'] >= (int) $evento['cupo'];
$mostrarQr = $evento !== null && $codigo !== '' && in_array($resultado, ['ok', 'existente'], true);
$mostrarPendiente = $evento !== null && $resultado === 'pendiente';
$primerNombre = $nombreInscrito !== '' ? explode(' ', $nombreInscrito)[0] : '';
$urlOtraPersona = 'inscripcion_evento.php?' . http_build_query(['token' => $token]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripción a evento | CENGICURSOS</title>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/eventos_publico.css">
</head>
<body>
<div class="ep-hero">
    <div class="ep-wrap">
        <div class="ep-kicker">CENGICURSOS · EVENTOS</div>
        <h1 class="ep-h1">Inscripción a evento</h1>
        <div class="ep-sub"><?php echo cengi_ins_evt_html($evento['nombre'] ?? 'Enlace de inscripción'); ?></div>
    </div>
</div>

<?php if ($evento === null): ?>
<div class="ep-wrap ep-grid" style="grid-template-columns:1fr;">
    <div class="ep-card ep-ok">
        <h2 class="ep-h2">Evento no disponible</h2>
        <p>El enlace no es válido, el evento finalizó o ya no acepta inscripciones.</p>
    </div>
</div>
<?php else: ?>
<div class="ep-wrap ep-grid">
    <div class="ep-card" id="epForm">
        <?php if ($mostrarQr): ?>
            <div class="ep-ok">
                <div class="ep-ok-i">✓</div>
                <h2 class="ep-h2"><?php echo $resultado === 'existente' ? 'Ya estás inscrito' : '¡Listo' . ($primerNombre !== '' ? ', ' . cengi_ins_evt_html($primerNombre) : '') . '!'; ?></h2>
                <p>Este es tu código QR de ingreso a <b><?php echo cengi_ins_evt_html($evento['nombre']); ?></b>. Guarda una captura de esta pantalla.</p>
                <div class="ep-qr"><div id="qrInscripcionEvento" data-codigo="<?php echo cengi_ins_evt_html($codigo); ?>"></div></div>
                <div class="mono" style="text-align:center;font-size:12px;"><?php echo cengi_ins_evt_html($codigo); ?></div>
                <a class="ep-btn is-secundario" href="<?php echo cengi_ins_evt_html($urlOtraPersona); ?>">Inscribir a otra persona</a>
            </div>
        <?php elseif ($mostrarPendiente): ?>
            <div class="ep-ok">
                <div class="ep-ok-i">✓</div>
                <h2 class="ep-h2">¡Inscripción recibida!</h2>
                <p><?php echo $nombreInscrito !== '' ? cengi_ins_evt_html($nombreInscrito) . ', tu' : 'Tu'; ?> lugar en <b><?php echo cengi_ins_evt_html($evento['nombre']); ?></b> quedó registrado.</p>
                <div class="ep-estado"><span class="ok">① Inscripción y pago recibidos</span><span>② Validación del pago</span><span>③ Confirmación y código QR</span></div>
                <p>Administración revisará tu comprobante. Cuando confirmemos tu participación recibirás tu <b>código QR de ingreso</b> por correo.</p>
                <a class="ep-btn is-secundario" href="<?php echo cengi_ins_evt_html($urlOtraPersona); ?>">Inscribir a otra persona</a>
            </div>
        <?php elseif ($cupoLleno): ?>
            <div class="ep-ok">
                <h2 class="ep-h2">Cupo lleno</h2>
                <p>El evento ya no tiene cupo disponible.</p>
            </div>
        <?php else: ?>
            <form action="guardar_inscripcion_evento.php" method="POST" id="formInscripcionEvento" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="token" value="<?php echo cengi_ins_evt_html($token); ?>">
                <div class="ep-hp" aria-hidden="true"><label>Sitio web<input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>
                <h2 class="ep-h2">🪪 Datos del participante</h2>
                <div class="ep-grid2">
                    <div class="ep-f full"><label for="epN">Nombre completo</label><input id="epN" name="nombre" maxlength="255" autocomplete="name" value="<?php echo cengi_ins_evt_previo($previo, 'nombre'); ?>"></div>
                    <div class="ep-f"><label for="epCui">CUI <span>(opcional)</span></label><input id="epCui" name="cui" maxlength="25" inputmode="numeric" value="<?php echo cengi_ins_evt_previo($previo, 'cui'); ?>"></div>
                    <div class="ep-f"><label for="epCorreo">Correo electrónico</label><input id="epCorreo" name="correo" type="email" maxlength="255" autocomplete="email" value="<?php echo cengi_ins_evt_previo($previo, 'correo'); ?>"></div>
                    <div class="ep-f"><label for="epTel">Teléfono / WhatsApp</label><input id="epTel" name="telefono" maxlength="30" autocomplete="tel" value="<?php echo cengi_ins_evt_previo($previo, 'telefono'); ?>"></div>
                    <div class="ep-f"><label for="epIng">Institución o ingenio</label><input id="epIng" name="institucion" maxlength="255" list="epIngList" placeholder="Ej. Ingenio Magdalena, empresa o particular" value="<?php echo cengi_ins_evt_previo($previo, 'institucion'); ?>">
                        <datalist id="epIngList"><?php foreach ($ingeniosLista as $nombreIngenio): ?><option value="<?php echo cengi_ins_evt_html($nombreIngenio); ?>"></option><?php endforeach; ?></datalist></div>
                </div>
                <?php if ($eventoPagado): ?>
                    <h2 class="ep-h2" style="margin-top:22px;">💳 Pago del evento</h2>
                    <div class="ep-f"><label>¿Desde dónde realizará el pago?</label>
                        <div class="ep-radios"><label><input type="radio" name="origen" value="nacional" checked> Guatemala · <b><?php echo cengi_ins_evt_html(cengi_ins_evt_q($evento['costo'])); ?></b></label></div></div>
                    <div class="ep-f" style="margin-top:14px;"><label>Forma de pago</label>
                        <div class="ep-radios"><label><input type="radio" name="metodo" value="deposito" checked> 🏦 Depósito o transferencia</label></div></div>
                    <div id="epDepositoBox">
                        <div class="ep-pago" id="epCuentaBox">
                            <b>¿A qué cuenta deposito?</b>
                            <p>Por seguridad, los datos de la cuenta no se publican aquí. Te los envía <b><?php echo cengi_ins_evt_html(CENGI_EVENTO_SOPORTE_PAGO_NOMBRE); ?></b> (<?php echo cengi_ins_evt_html(CENGI_EVENTO_SOPORTE_PAGO_HORARIO); ?>), y también llegan al correo que registres.</p>
                            <div class="ep-contacto"><?php if ($whatsappUrl !== ''): ?><a class="ep-wa" href="<?php echo cengi_ins_evt_html($whatsappUrl); ?>" target="_blank" rel="noopener">WhatsApp de soporte de pagos</a><?php endif; ?><a class="ep-mail" href="<?php echo cengi_ins_evt_html('mailto:' . CENGI_EVENTO_CORREO_PAGO . '?subject=' . rawurlencode('Datos de pago — ' . $evento['nombre'])); ?>"><?php echo cengi_ins_evt_html(CENGI_EVENTO_CORREO_PAGO); ?></a></div>
                            <p class="ep-seg">Verifica que los datos te lleguen desde un correo @cengicana.org o desde el WhatsApp oficial. CENGICAÑA nunca te pedirá pagar a cuentas personales.</p>
                        </div>
                        <div class="ep-grid2" id="epCompBox" style="margin-top:12px;">
                            <div class="ep-f"><label for="epBoleta">N.° de boleta o transferencia</label><input id="epBoleta" name="pago_boleta" maxlength="60" value="<?php echo cengi_ins_evt_previo($previo, 'pago_boleta'); ?>"></div>
                            <div class="ep-f"><label for="epFecha">Fecha del pago</label><input id="epFecha" name="pago_fecha" type="date" max="<?php echo date('Y-m-d'); ?>" value="<?php echo cengi_ins_evt_previo($previo, 'pago_fecha'); ?>"></div>
                            <div class="ep-f"><label for="epBanco">Banco</label><input id="epBanco" name="pago_banco" maxlength="120" value="<?php echo cengi_ins_evt_previo($previo, 'pago_banco'); ?>"></div>
                            <div class="ep-f"><label for="epMonto">Monto pagado</label><input id="epMonto" name="pago_monto" type="number" min="0" step="0.01" value="<?php echo cengi_ins_evt_previo($previo, 'pago_monto'); ?>"></div>
                            <div class="ep-f full"><label>Comprobante de pago <span>(foto o PDF)</span></label>
                                <div class="pp-doc" id="epComp"></div>
                                <input type="file" id="epCompFile" name="recibo_pago" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" hidden>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="pp-form-msg<?php echo $resultado === 'error' ? ' err' : ''; ?>" id="epMsg" role="alert"><?php
                    if ($resultado === 'error') {
                        echo cengi_ins_evt_html($mensaje !== '' ? $mensaje : 'No fue posible completar la inscripción.');
                    }
                ?></div>
                <button type="submit" class="ep-btn" id="epBtn"><?php echo $eventoPagado ? 'Inscribirme y enviar comprobante' : '▦ Inscribirme y generar QR'; ?></button>
                <div class="ep-legal">Tus datos se usan solo para gestionar tu participación en el evento.</div>
            </form>
        <?php endif; ?>
    </div>
    <div>
        <div class="ep-info" id="epInfo">
            <div class="ep-k"><?php echo cengi_ins_evt_html(mb_strtoupper((string) $evento['tipo'], 'UTF-8')); ?></div>
            <div class="ep-t"><?php echo cengi_ins_evt_html($evento['nombre']); ?></div>
            <div class="ep-row"><span>📅</span><div><div class="ep-l">Fecha</div><b><?php echo cengi_ins_evt_html(cengi_ins_evt_fecha($evento['fecha']) . (trim((string) $evento['hora']) !== '' ? ' · ' . $evento['hora'] : '')); ?></b></div></div>
            <?php if (trim((string) $evento['lugar']) !== ''): ?>
            <div class="ep-row"><span>📍</span><div><div class="ep-l">Lugar</div><b><?php echo cengi_ins_evt_html($evento['lugar']); ?></b></div></div>
            <?php endif; ?>
            <div class="ep-row"><span>🎟️</span><div><div class="ep-l">Acceso</div><b><?php echo $eventoPagado ? 'Pagado · ' . cengi_ins_evt_html(cengi_ins_evt_q($evento['costo'])) : 'Gratuito'; ?></b></div></div>
        </div>
        <div class="ep-aviso" id="epAviso">
            <?php if ($eventoPagado): ?>
                <b>ⓘ Evento pagado</b><p>Adjunta tu comprobante al inscribirte. Tu código QR de ingreso te llegará cuando Administración valide el pago y confirmemos tu participación.</p>
            <?php else: ?>
                <b>ⓘ Evento gratuito</b><p>Al inscribirte recibes de inmediato tu código QR de ingreso.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="js/qrcode-generator.js"></script>
<script>
(function () {
    'use strict';
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    var eventoPagado = <?php echo $eventoPagado ? 'true' : 'false'; ?>;

    function esc(valor) {
        var d = document.createElement('div');
        d.textContent = valor == null ? '' : String(valor);
        return d.innerHTML;
    }

    function hoyCorto() {
        var d = new Date();
        return String(d.getDate()).padStart(2, '0') + ' ' + MESES[d.getMonth()] + ' ' + d.getFullYear();
    }

    function mostrarMsg(html) {
        var m = document.getElementById('epMsg');
        m.className = 'pp-form-msg' + (html ? ' err' : '');
        m.innerHTML = html || '';
    }

    // Caja del comprobante (mismo comportamiento que ppDocBox del prototipo), sobre un
    // <input type="file"> real para que el archivo viaje con el formulario.
    var caja = document.getElementById('epComp');
    var archivo = document.getElementById('epCompFile');
    var TITULO_CAJA = 'Arrastra aquí la foto o PDF de la boleta';

    function pintarCaja() {
        var f = archivo.files && archivo.files[0];
        caja.className = 'pp-doc' + (f ? ' ok' : '');
        var tam = f ? (f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB') : '';
        caja.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="' + (f ? '#3E7A12' : 'var(--tinta-suave)') + '" stroke-width="1.7"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>' +
            '<div><div class="nm">' + (f ? esc(f.name) : TITULO_CAJA) + '</div><div class="meta">' + (f ? esc(tam) + ' · ' + hoyCorto() : 'PDF, JPG o PNG · máximo 5 MB') + '</div></div>' +
            '<div class="acts">' + (f ? '<button type="button" class="btn btn-ghost btn-sm" data-accion="quitar">Quitar</button>' : '') +
            '<button type="button" class="btn btn-outline btn-sm" data-accion="elegir">' + (f ? 'Reemplazar' : 'Seleccionar archivo') + '</button></div>';
    }

    function archivoValido(f) {
        if (!/\.(pdf|jpe?g|png)$/i.test(f.name)) { mostrarMsg('Formato no permitido. Usa PDF, JPG o PNG.'); return false; }
        if (f.size > 5 * 1024 * 1024) { mostrarMsg('El archivo supera los 5 MB.'); return false; }
        return true;
    }

    if (caja && archivo) {
        pintarCaja();
        caja.addEventListener('click', function (ev) {
            var accion = ev.target.getAttribute('data-accion');
            if (accion === 'elegir') { archivo.click(); }
            if (accion === 'quitar') { archivo.value = ''; pintarCaja(); }
        });
        archivo.addEventListener('change', function () {
            var f = archivo.files && archivo.files[0];
            if (f && !archivoValido(f)) { archivo.value = ''; }
            else if (f) { mostrarMsg(''); }
            pintarCaja();
        });
        ['dragenter', 'dragover'].forEach(function (n) { caja.addEventListener(n, function (e) { e.preventDefault(); caja.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (n) { caja.addEventListener(n, function (e) { e.preventDefault(); caja.classList.remove('over'); }); });
        caja.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files[0];
            if (!f || !archivoValido(f)) { return; }
            var dt = new DataTransfer();
            dt.items.add(f);
            archivo.files = dt.files;
            mostrarMsg('');
            pintarCaja();
        });
    }

    // Validacion previa al envio, con los mismos mensajes que ppEpEnviar del prototipo.
    var form = document.getElementById('formInscripcionEvento');
    var boton = document.getElementById('epBtn');
    if (form && boton) {
        form.addEventListener('submit', function (ev) {
            var g = function (id) { return document.getElementById(id); };
            var errs = [];
            if (g('epN').value.trim().replace(/\s+/g, ' ').length < 5) errs.push('Escribe tu nombre completo.');
            if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(g('epCorreo').value.trim())) errs.push('Escribe un correo válido.');
            if (g('epTel').value.replace(/\D/g, '').length < 8) errs.push('Escribe un teléfono o WhatsApp de al menos 8 dígitos.');
            if (eventoPagado) {
                if (!(archivo.files && archivo.files[0])) errs.push('Adjunta la foto o PDF de tu comprobante.');
                if (!g('epBoleta').value.trim()) errs.push('Escribe el número de boleta o transferencia.');
                if (!/^\d{4}-\d{2}-\d{2}$/.test(g('epFecha').value)) errs.push('Indica la fecha del pago.');
                if (!(Number(g('epMonto').value) > 0)) errs.push('Indica el monto pagado.');
            }
            if (errs.length) {
                ev.preventDefault();
                mostrarMsg(errs.map(esc).join('<br>'));
                return;
            }
            mostrarMsg('');
            boton.disabled = true;
            boton.textContent = 'Registrando…';
        });
    }

    var contenedor = document.getElementById('qrInscripcionEvento');
    if (contenedor && typeof qrcode === 'function') {
        var qr = qrcode(0, 'M');
        qr.addData(contenedor.getAttribute('data-codigo'));
        qr.make();
        contenedor.innerHTML = qr.createSvgTag({cellSize: 7, margin: 4, scalable: true});
    }
}());
</script>
</body>
</html>
