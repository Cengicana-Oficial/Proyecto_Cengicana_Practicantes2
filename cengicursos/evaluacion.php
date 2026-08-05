<?php
require_once __DIR__ . '/conexion.php';

$db = conectar();

$token = trim((string) ($_GET['token'] ?? ''));

$enlace = null;
if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $db->prepare("
        SELECT
            e.id AS enlace_id,
            e.token,
            c.nombre_cursos,
            c.tipo AS modalidad,
            c.inicio,
            c.fin,
            i.nombre AS instructor_nombre,
            i.especialidad AS instructor_especialidad
        FROM enlaces_evaluacion_instructor e
        INNER JOIN cursos c ON c.id = e.curso_id
        INNER JOIN instructores i ON i.id = e.instructor_id
        WHERE e.token = ?
    ");
    $stmt->execute([$token]);
    $enlace = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$enlace) {
    require __DIR__ . '/404.php';
    exit;
}

function cengi_eval_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_eval_fecha($valor)
{
    if (!$valor) {
        return '';
    }
    $tiempo = strtotime((string) $valor);
    return $tiempo ? date('d/m/Y', $tiempo) : '';
}

$resultado = trim((string) ($_GET['resultado'] ?? ''));
$mensajeError = trim((string) ($_GET['mensaje'] ?? ''));

$rangoFechas = cengi_eval_fecha($enlace['inicio']);
if ($enlace['fin'] && $enlace['fin'] !== $enlace['inicio']) {
    $rangoFechas .= ($rangoFechas !== '' ? ' – ' : '') . cengi_eval_fecha($enlace['fin']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluación de instructor | CENGICURSOS</title>
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
            padding: 24px;
            font-family: 'Inter', sans-serif;
            color: var(--cengi-ink);
            background:
                radial-gradient(circle at 15% 20%, rgba(163, 211, 0, 0.25), transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(115, 188, 37, 0.25), transparent 40%),
                linear-gradient(160deg, var(--cengi-primary-deep-2), var(--cengi-primary-deep));
        }

        .cengi-eval-card {
            width: 100%;
            max-width: 560px;
            background: var(--cengi-surface);
            border-radius: 20px;
            padding: 44px 40px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(22, 61, 36, 0.35);
            border-top: 5px solid var(--cengi-primary);
            animation: cengiFadeIn 0.5s ease;
        }

        .cengi-eval-logo {
            height: 56px;
            margin-bottom: 18px;
        }

        .cengi-eval-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 6px;
            color: var(--cengi-primary-deep);
        }

        .cengi-eval-course {
            font-size: 16px;
            font-weight: 600;
            color: var(--cengi-ink);
            margin: 0 0 4px;
        }

        .cengi-eval-instructor {
            font-size: 14px;
            color: var(--cengi-muted);
            margin: 0 0 6px;
        }

        .cengi-eval-meta {
            font-size: 12.5px;
            color: var(--cengi-muted);
            margin: 0 0 26px;
        }

        .cengi-eval-alert {
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13.5px;
            text-align: left;
            margin: 0 0 20px;
        }

        .cengi-eval-alert.is-success {
            background: #f3faec;
            border: 1px solid var(--cengi-border);
            color: var(--cengi-primary-deep);
        }

        .cengi-eval-alert.is-error {
            background: #fdf0ef;
            border: 1px solid #f3c8c4;
            color: #a4342b;
        }

        .cengi-eval-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--cengi-primary-deep);
            margin: 0 0 10px;
            text-align: left;
        }

        .cengi-star-rating {
            display: flex;
            flex-direction: row-reverse;
            justify-content: center;
            gap: 6px;
            margin-bottom: 26px;
        }

        .cengi-star-rating input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .cengi-star-rating label {
            font-size: 42px;
            line-height: 1;
            color: var(--cengi-border);
            cursor: pointer;
            transition: color 0.15s ease, transform 0.15s ease;
        }

        .cengi-star-rating label:hover {
            transform: scale(1.08);
        }

        .cengi-star-rating input:checked ~ label,
        .cengi-star-rating label:hover,
        .cengi-star-rating label:hover ~ label {
            color: var(--cengi-amarillo);
        }

        .cengi-eval-textarea {
            width: 100%;
            min-height: 110px;
            border: 1.5px solid var(--cengi-border);
            border-radius: 12px;
            padding: 12px 14px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: var(--cengi-ink);
            resize: vertical;
            margin-bottom: 26px;
        }

        .cengi-eval-textarea:focus {
            outline: none;
            border-color: var(--cengi-primary);
        }

        .cengi-eval-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 13px 26px;
            border: none;
            border-radius: 12px;
            font-size: 14.5px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, var(--cengi-primary), var(--cengi-primary-strong));
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .cengi-eval-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(115, 188, 37, 0.45);
        }

        @keyframes cengiFadeIn {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 480px) {
            .cengi-eval-card {
                padding: 32px 22px;
            }

            .cengi-star-rating label {
                font-size: 34px;
            }
        }
    </style>
</head>
<body>

<div class="cengi-eval-card">
    <img class="cengi-eval-logo" src="img/logo-comite-capacitacion.png" alt="CENGICAÑA">
    <h1 class="cengi-eval-title">Evaluación del instructor</h1>
    <p class="cengi-eval-course"><?php echo cengi_eval_html($enlace['nombre_cursos']); ?></p>
    <p class="cengi-eval-instructor">Instructor: <?php echo cengi_eval_html($enlace['instructor_nombre']); ?></p>
    <?php if ($rangoFechas !== '' || $enlace['modalidad']): ?>
        <p class="cengi-eval-meta">
            <?php echo cengi_eval_html(trim(($enlace['modalidad'] ?? '') . ($rangoFechas !== '' ? ' · ' . $rangoFechas : ''))); ?>
        </p>
    <?php endif; ?>

    <?php if ($resultado === 'ok'): ?>
        <div class="cengi-eval-alert is-success" role="status">
            ¡Gracias! Tu evaluación fue registrada correctamente.
        </div>
    <?php elseif ($resultado === 'error'): ?>
        <div class="cengi-eval-alert is-error" role="alert">
            <?php echo cengi_eval_html($mensajeError !== '' ? $mensajeError : 'No fue posible registrar tu evaluación. Intenta nuevamente.'); ?>
        </div>
    <?php endif; ?>

    <form action="guardar_evaluacion_instructor.php" method="POST">
        <input type="hidden" name="token" value="<?php echo cengi_eval_html($enlace['token']); ?>">

        <label class="cengi-eval-label">¿Cómo calificarías al instructor?</label>
        <div class="cengi-star-rating">
            <input type="radio" id="cengiStar5" name="calificacion" value="5" required>
            <label for="cengiStar5" title="5 estrellas">★</label>
            <input type="radio" id="cengiStar4" name="calificacion" value="4">
            <label for="cengiStar4" title="4 estrellas">★</label>
            <input type="radio" id="cengiStar3" name="calificacion" value="3">
            <label for="cengiStar3" title="3 estrellas">★</label>
            <input type="radio" id="cengiStar2" name="calificacion" value="2">
            <label for="cengiStar2" title="2 estrellas">★</label>
            <input type="radio" id="cengiStar1" name="calificacion" value="1">
            <label for="cengiStar1" title="1 estrella">★</label>
        </div>

        <label class="cengi-eval-label" for="cengiComentario">Comentario (opcional)</label>
        <textarea class="cengi-eval-textarea" id="cengiComentario" name="comentario" maxlength="2000" placeholder="Cuéntanos qué te pareció el instructor..."></textarea>

        <label class="cengi-eval-label" for="cengiAreasMejora">Áreas de mejora (opcional)</label>
        <textarea class="cengi-eval-textarea" id="cengiAreasMejora" name="areas_mejora" maxlength="2000" placeholder="¿Qué podría mejorar el instructor?"></textarea>

        <button type="submit" class="cengi-eval-btn">Enviar evaluación</button>
    </form>
</div>

</body>
</html>
