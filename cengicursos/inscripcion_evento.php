<?php
require_once __DIR__ . '/conexion.php';

$db = conectar();
$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$evento = null;

if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $db->prepare("
        SELECT id, nombre, tipo, modalidad_pago, costo, fecha, estado
        FROM eventos
        WHERE token_inscripcion = ?
          AND estado NOT IN ('Finalizado', 'Cancelado')
          AND (fecha IS NULL OR fecha >= CURDATE())
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$resultado = trim((string) ($_GET['resultado'] ?? ''));
$mensaje = trim((string) ($_GET['mensaje'] ?? ''));
$codigo = trim((string) ($_GET['codigo'] ?? ''));

function cengi_ins_evt_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_ins_evt_fecha($fecha)
{
    if (!$fecha) {
        return 'Fecha por confirmar';
    }
    return date('d/m/Y', strtotime($fecha));
}
?>
<!DOCTYPE html>
<html class="light" lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripción a evento | CENGICURSOS</title>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/inscripcion.css">
    <script>
        tailwind.config = {theme: {extend: {colors: {primary: '#03251d', secondary: '#326b00', background: '#f8f9ff', outline: '#c1c8c4'}, fontFamily: {body: ['Montserrat']}}}};
    </script>
</head>
<body class="bg-background font-body text-gray-800 overflow-x-hidden">
<section class="hero-section relative">
    <img src="css/images/formulario.jpeg" class="absolute inset-0 w-full h-full object-cover" alt="">
    <div class="hero-overlay"></div>
    <div class="relative z-10 max-w-6xl mx-auto px-4 md:px-10 h-full flex items-center">
        <div class="text-white max-w-3xl">
            <p class="uppercase tracking-widest text-sm font-semibold text-white/80 mb-3">CENGICURSOS · Eventos</p>
            <h1 class="text-4xl md:text-6xl font-extrabold mb-4">Inscripción a evento</h1>
            <p class="text-lg md:text-xl text-white/90"><?php echo cengi_ins_evt_html($evento['nombre'] ?? 'Enlace de inscripción'); ?></p>
        </div>
    </div>
</section>

<main class="max-w-6xl mx-auto px-4 md:px-10 -mt-16 relative z-20 pb-10">
    <?php if ($resultado === 'ok' || $resultado === 'existente'): ?>
        <div class="cengi-alert is-success" role="status">
            <span class="material-symbols-outlined">check_circle</span>
            <p><?php echo $resultado === 'existente' ? 'Ya estabas inscrito en este evento. Este es tu código QR.' : 'Tu inscripción fue registrada correctamente. Conserva tu código QR para el ingreso.'; ?></p>
        </div>
    <?php elseif ($resultado === 'error'): ?>
        <div class="cengi-alert is-error" role="alert">
            <span class="material-symbols-outlined">error</span>
            <p><?php echo cengi_ins_evt_html($mensaje !== '' ? $mensaje : 'No fue posible completar la inscripción.'); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($evento === null): ?>
        <div class="bg-white border border-outline rounded-2xl shadow-lg p-8 text-center">
            <span class="material-symbols-outlined text-5xl text-gray-400">event_busy</span>
            <h2 class="text-2xl font-bold text-primary mt-3">Evento no disponible</h2>
            <p class="text-gray-500 mt-2">El enlace no es válido, el evento finalizó o ya no acepta inscripciones.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <section class="lg:col-span-8 bg-white border border-outline rounded-2xl shadow-lg p-6 md:p-8">
                <?php if ($codigo !== '' && in_array($resultado, ['ok', 'existente'], true)): ?>
                    <div class="text-center py-3">
                        <h2 class="text-2xl font-bold text-primary">Tu acceso está listo</h2>
                        <div id="qrInscripcionEvento" data-codigo="<?php echo cengi_ins_evt_html($codigo); ?>" class="mx-auto my-5 w-56 h-56 border border-gray-200 rounded-2xl p-3 flex items-center justify-center"></div>
                        <p class="font-mono font-bold text-primary tracking-wide"><?php echo cengi_ins_evt_html($codigo); ?></p>
                        <p class="text-sm text-gray-500 mt-3">Puedes guardar una captura de esta pantalla.</p>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-3 mb-8">
                        <span class="material-symbols-outlined text-primary text-3xl">assignment_ind</span>
                        <h2 class="text-3xl font-bold text-primary">Datos del participante</h2>
                    </div>
                    <form action="guardar_inscripcion_evento.php" method="POST" id="formInscripcionEvento">
                        <input type="hidden" name="token" value="<?php echo cengi_ins_evt_html($token); ?>">
                        <div class="hidden" aria-hidden="true"><label>Sitio web<input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>
                        <div>
                            <label class="label-form" for="evtNombre">Nombre completo</label>
                            <input id="evtNombre" type="text" name="nombre" class="input-form" maxlength="255" autocomplete="name" required>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">
                            <div>
                                <label class="label-form" for="evtCui">CUI <span class="text-gray-400 font-normal">(opcional)</span></label>
                                <input id="evtCui" type="text" name="cui" class="input-form" maxlength="25" inputmode="numeric">
                            </div>
                            <div>
                                <label class="label-form" for="evtCorreo">Correo electrónico</label>
                                <input id="evtCorreo" type="email" name="correo" class="input-form" maxlength="255" autocomplete="email" required>
                            </div>
                        </div>
                        <div class="mt-8">
                            <button type="submit" id="btnInscripcionEvento" class="btn-submit"><span class="material-symbols-outlined">qr_code_2</span>Inscribirme y generar QR</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <aside class="lg:col-span-4 flex flex-col gap-6">
                <div class="sidebar-card-primary">
                    <p class="uppercase tracking-widest text-xs text-white/70 mb-2"><?php echo cengi_ins_evt_html($evento['tipo']); ?></p>
                    <h2 class="text-2xl font-bold mb-5"><?php echo cengi_ins_evt_html($evento['nombre']); ?></h2>
                    <div class="space-y-4 text-sm">
                        <div class="flex gap-3"><span class="material-symbols-outlined">calendar_month</span><div><span class="block text-white/70 text-xs">Fecha</span><strong><?php echo cengi_ins_evt_html(cengi_ins_evt_fecha($evento['fecha'])); ?></strong></div></div>
                        <div class="flex gap-3"><span class="material-symbols-outlined"><?php echo $evento['modalidad_pago'] === 'Pagado' ? 'payments' : 'redeem'; ?></span><div><span class="block text-white/70 text-xs">Acceso</span><strong><?php echo cengi_ins_evt_html($evento['modalidad_pago']); ?><?php echo $evento['modalidad_pago'] === 'Pagado' ? ' · Q ' . number_format((float) $evento['costo'], 2) : ''; ?></strong></div></div>
                    </div>
                </div>
                <?php if ($evento['modalidad_pago'] === 'Pagado'): ?>
                    <div class="bg-white border border-outline rounded-2xl shadow-md p-5">
                        <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-secondary">info</span><h3 class="font-bold text-primary">Evento pagado</h3></div>
                        <p class="text-sm text-gray-500">Esta inscripción reserva tu registro. El equipo organizador te comunicará las instrucciones de pago.</p>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</main>

<script src="js/qrcode-generator.js"></script>
<script>
(function () {
    'use strict';
    var form = document.getElementById('formInscripcionEvento');
    var boton = document.getElementById('btnInscripcionEvento');
    if (form && boton) {
        form.addEventListener('submit', function () {
            boton.disabled = true;
            boton.innerHTML = '<span class="material-symbols-outlined animate-spin">sync</span> Registrando…';
        });
    }
    var contenedor = document.getElementById('qrInscripcionEvento');
    if (contenedor && typeof qrcode === 'function') {
        var qr = qrcode(0, 'M');
        qr.addData(contenedor.getAttribute('data-codigo'));
        qr.make();
        contenedor.innerHTML = qr.createSvgTag({cellSize: 7, margin: 12, scalable: true});
        var svg = contenedor.querySelector('svg');
        if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; }
    }
}());
</script>
</body>
</html>

