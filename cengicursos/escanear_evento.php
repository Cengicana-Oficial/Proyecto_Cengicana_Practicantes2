<?php

/**
 * Enlace publico de escaneo QR en la entrada de un evento (?token=...), pensado para
 * abrirse desde el celular de la persona que controla el ingreso. Sigue exactamente el
 * mismo patron de "acceso publico controlado por posesion del link" que evaluacion.php
 * (token de 128 bits, sin revisar_permisos.php ni sesion iniciada: es intencional, para
 * que alguien sin cuenta en el sistema pueda usarlo).
 *
 * IMPORTANTE (produccion): navigator.mediaDevices.getUserMedia() solo funciona en un
 * contexto seguro (HTTPS) o en localhost/127.0.0.1. En este entorno local
 * (127.0.0.1:8085) funciona igual, pero en produccion esta pagina necesita servirse por
 * HTTPS o el navegador bloqueara el acceso a la camara.
 *
 * El resultado de cada escaneo se procesa en escanear_evento_marcar.php (AJAX/JSON,
 * mismo patron que enviar_gafetes_evento.php).
 */

require_once __DIR__ . '/conexion.php';

$db = conectar();

$token = trim((string) ($_GET['token'] ?? ''));

$evento = null;
if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $db->prepare('SELECT id, nombre, token_escaneo FROM eventos WHERE token_escaneo = ?');
    $stmt->execute([$token]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$evento) {
    require __DIR__ . '/404.php';
    exit;
}

function cengi_esc_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de ingreso | CENGICURSOS</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --cengi-primary: #73BC25;
            --cengi-primary-strong: #5e9b1d;
            --cengi-primary-deep: #1f5632;
            --cengi-primary-deep-2: #163d24;
            --cengi-secondary: #A3D300;
            --cengi-amarillo: #FFCC00;
            --cengi-ink: #294033;
            --cengi-muted: #55705f;
            --cengi-surface: #ffffff;
            --cengi-border: #dbe8dc;
            --cengi-danger: #a4342b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            font-family: 'Inter', sans-serif;
            color: var(--cengi-ink);
            background:
                radial-gradient(circle at 15% 20%, rgba(163, 211, 0, 0.25), transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(115, 188, 37, 0.25), transparent 40%),
                linear-gradient(160deg, var(--cengi-primary-deep-2), var(--cengi-primary-deep));
        }

        .cengi-scan-card {
            width: 100%;
            max-width: 440px;
            background: var(--cengi-surface);
            border-radius: 20px;
            padding: 32px 26px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(22, 61, 36, 0.35);
            border-top: 5px solid var(--cengi-primary);
        }

        .cengi-scan-logo {
            height: 48px;
            margin-bottom: 14px;
        }

        .cengi-scan-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 4px;
            color: var(--cengi-primary-deep);
        }

        .cengi-scan-event {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--cengi-muted);
            margin: 0 0 18px;
        }

        .cengi-scan-camera-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 16px;
            overflow: hidden;
            background: #0f2417;
            margin-bottom: 16px;
        }

        .cengi-scan-camera-wrap video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cengi-scan-frame {
            position: absolute;
            inset: 12%;
            border: 3px solid rgba(255, 255, 255, 0.75);
            border-radius: 18px;
            pointer-events: none;
            box-shadow: 0 0 0 999px rgba(0, 0, 0, 0.28);
        }

        .cengi-scan-status {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--cengi-muted);
            margin-bottom: 12px;
            min-height: 18px;
        }

        .cengi-scan-status.is-error {
            color: var(--cengi-danger);
        }

        .cengi-scan-result {
            border-radius: 14px;
            padding: 16px 18px;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .cengi-scan-result small {
            display: block;
            margin-top: 4px;
            font-size: 12.5px;
            font-weight: 500;
            opacity: 0.85;
        }

        .cengi-scan-result.is-success {
            background: #f3faec;
            border: 1px solid var(--cengi-border);
            color: var(--cengi-primary-deep);
        }

        .cengi-scan-result.is-warning {
            background: #fff9e8;
            border: 1px solid #f2dd9a;
            color: #8a6d1b;
        }

        .cengi-scan-result.is-error {
            background: #fdf0ef;
            border: 1px solid #f3c8c4;
            color: var(--cengi-danger);
        }

        .cengi-scan-retry-btn {
            width: 100%;
            margin-top: 4px;
            padding: 11px 18px;
            border: none;
            border-radius: 12px;
            background: var(--cengi-primary);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .cengi-scan-retry-btn:active {
            background: var(--cengi-primary-strong);
        }

        .cengi-scan-help {
            font-size: 12.5px;
            color: var(--cengi-muted);
            margin: 10px 0 0;
            line-height: 1.5;
            text-align: left;
        }

        @keyframes cengiFadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .cengi-scan-card {
            animation: cengiFadeIn 0.5s ease;
        }
    </style>
</head>
<body>

<div class="cengi-scan-card">
    <img class="cengi-scan-logo" src="img/logo-comite-capacitacion.png" alt="CENGICAÑA">
    <h1 class="cengi-scan-title">Control de ingreso</h1>
    <p class="cengi-scan-event"><?php echo cengi_esc_html($evento['nombre']); ?></p>

    <div class="cengi-scan-camera-wrap">
        <video id="cengiScanVideo" playsinline autoplay muted></video>
        <div class="cengi-scan-frame"></div>
    </div>
    <canvas id="cengiScanCanvas" style="display:none;"></canvas>

    <div id="cengiScanEstado" class="cengi-scan-status">Solicitando acceso a la cámara…</div>
    <div id="cengiScanResultado" class="cengi-scan-result" style="display:none;"></div>
    <button type="button" id="cengiScanReintentar" class="cengi-scan-retry-btn" style="display:none;">Reintentar acceso a la cámara</button>
</div>

<script src="js/jsQR.js"></script>
<script>
(function () {
    'use strict';

    var token = <?php echo json_encode($evento['token_escaneo'], JSON_UNESCAPED_SLASHES); ?>;
    var video = document.getElementById('cengiScanVideo');
    var canvas = document.getElementById('cengiScanCanvas');
    var ctx = canvas.getContext('2d', {willReadFrequently: true});
    var estadoEl = document.getElementById('cengiScanEstado');
    var resultadoEl = document.getElementById('cengiScanResultado');
    var reintentarBtn = document.getElementById('cengiScanReintentar');
    var ESTADO_ESCANEANDO = 'Apunta la cámara al código QR del gafete…';
    var pausado = false;

    function escapeHtml(valor) {
        var div = document.createElement('div');
        div.textContent = valor == null ? '' : String(valor);
        return div.innerHTML;
    }

    function mostrarResultado(tipo, titulo, detalle) {
        resultadoEl.className = 'cengi-scan-result is-' + tipo;
        resultadoEl.innerHTML = escapeHtml(titulo) + (detalle ? '<small>' + escapeHtml(detalle) + '</small>' : '');
        resultadoEl.style.display = 'block';
    }

    function ocultarResultado() {
        resultadoEl.style.display = 'none';
        resultadoEl.innerHTML = '';
    }

    // Pausa el loop de escaneo ~2 segundos tras un escaneo exitoso, para no
    // reprocesar el mismo QR en bucle mientras la persona todavia tiene el celular
    // apuntando al gafete, y luego lo reanuda automaticamente.
    function enviarCodigo(codigo) {
        pausado = true;
        estadoEl.textContent = 'Verificando código…';

        fetch('escanear_evento_marcar.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({token: token, codigo_qr: codigo})
        })
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (datos) {
                if (datos && datos.ok) {
                    if (datos.ya_ingreso) {
                        mostrarResultado('warning', '⚠ ' + datos.nombre, datos.mensaje);
                    } else {
                        mostrarResultado('success', '✓ ' + datos.nombre + ' — ingreso registrado', datos.mensaje);
                    }
                } else {
                    mostrarResultado('error', (datos && datos.mensaje) || 'No fue posible procesar el código.', '');
                }
            })
            .catch(function () {
                mostrarResultado('error', 'Error de conexión. Intenta nuevamente.', '');
            })
            .finally(function () {
                window.setTimeout(function () {
                    ocultarResultado();
                    estadoEl.textContent = ESTADO_ESCANEANDO;
                    pausado = false;
                }, 2000);
            });
    }

    function tick() {
        window.requestAnimationFrame(tick);
        if (pausado || !video.videoWidth || video.readyState !== video.HAVE_ENOUGH_DATA) return;

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        var imageData;
        try {
            imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        } catch (e) {
            return;
        }

        var codigo = window.jsQR && window.jsQR(imageData.data, imageData.width, imageData.height, {inversionAttempts: 'dontInvert'});
        if (codigo && codigo.data) {
            enviarCodigo(codigo.data);
        }
    }

    // Si el navegador ya tiene guardada una decision de "Bloquear" camara para este
    // origen (de una prueba anterior), getUserMedia() rechaza de inmediato con
    // NotAllowedError SIN volver a mostrar el popup nativo: ningun sitio puede forzar
    // ese popup una vez que el usuario ya decidio. Lo unico que se puede hacer desde
    // aqui es detectar ese caso y explicar como resetear el permiso a mano, mas dar un
    // boton para reintentar sin recargar toda la pagina una vez arreglado.
    function solicitarCamara() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            estadoEl.textContent = 'Este navegador no soporta acceso a la cámara.';
            estadoEl.className = 'cengi-scan-status is-error';
            return;
        }

        estadoEl.textContent = 'Solicitando acceso a la cámara…';
        estadoEl.className = 'cengi-scan-status';
        reintentarBtn.style.display = 'none';

        navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false})
            .then(function (stream) {
                video.srcObject = stream;
                video.play();
                estadoEl.textContent = ESTADO_ESCANEANDO;
                window.requestAnimationFrame(tick);
            })
            .catch(function (error) {
                estadoEl.className = 'cengi-scan-status is-error';
                if (error && error.name === 'NotAllowedError') {
                    estadoEl.innerHTML = 'El navegador tiene bloqueado el acceso a la cámara para este sitio.'
                        + '<p class="cengi-scan-help">Toca el ícono de candado o de información junto a la dirección del sitio, '
                        + 'entra a "Permisos" y cambia Cámara a "Permitir". Luego presiona reintentar.</p>';
                } else if (error && error.name === 'NotFoundError') {
                    estadoEl.textContent = 'No se detectó ninguna cámara en este dispositivo.';
                } else {
                    estadoEl.textContent = 'No fue posible acceder a la cámara. Revisa los permisos del navegador.';
                }
                reintentarBtn.style.display = 'block';
            });
    }

    reintentarBtn.addEventListener('click', solicitarCamara);
    solicitarCamara();
}());
</script>

</body>
</html>
