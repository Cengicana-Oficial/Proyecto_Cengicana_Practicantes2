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

// El formulario oficial replica dos ramas casi identicas (Presencial / Virtual),
// pero la modalidad ya se conoce por el curso (cursos.tipo): no se vuelve a preguntar,
// solo cambia el rotulo de sección/pregunta que se muestra.
$modalidad = ($enlace['modalidad'] === 'Virtual') ? 'Virtual' : 'Presencial';

$ingenios = $db->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios ASC')->fetchAll(PDO::FETCH_ASSOC);

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

/**
 * Pregunta tipo Likert de 4 opciones (Excelente/Bueno/Regular/Deficiente), valores 4..1.
 * Mismo texto/estructura para la rama Presencial y Virtual del formulario oficial.
 */
function cengi_eval_likert($name, $pregunta)
{
    $opciones = [4 => 'Excelente', 3 => 'Bueno', 2 => 'Regular', 1 => 'Deficiente'];
    echo '<div class="cengi-eval-likert">';
    echo '<span class="cengi-eval-likert-question">' . cengi_eval_html($pregunta) . ' <span class="cengi-eval-required">*</span></span>';
    echo '<div class="cengi-eval-likert-options">';
    foreach ($opciones as $valor => $texto) {
        $id = 'cengi_' . $name . '_' . $valor;
        echo '<input type="radio" id="' . $id . '" name="' . cengi_eval_html($name) . '" value="' . $valor . '" required>';
        echo '<label for="' . $id . '">' . cengi_eval_html($texto) . '</label>';
    }
    echo '</div></div>';
}

/**
 * Calificacion de 1 a $max estrellas (el formulario oficial usa 10 estrellas para las
 * preguntas de recomendacion, distinto de las 5 estrellas del formulario original).
 */
function cengi_eval_estrellas($name, $max, $labelTexto)
{
    echo '<div class="cengi-eval-field">';
    echo '<label class="cengi-eval-label">' . cengi_eval_html($labelTexto) . ' <span class="cengi-eval-required">*</span></label>';
    echo '<div class="cengi-star-rating cengi-star-rating--' . (int) $max . '">';
    for ($i = (int) $max; $i >= 1; $i--) {
        $id = 'cengi_' . $name . '_' . $i;
        echo '<input type="radio" id="' . $id . '" name="' . cengi_eval_html($name) . '" value="' . $i . '" required>';
        echo '<label for="' . $id . '" title="' . $i . '">★</label>';
    }
    echo '</div></div>';
}

$resultado = trim((string) ($_GET['resultado'] ?? ''));
$mensajeError = trim((string) ($_GET['mensaje'] ?? ''));

$rangoFechas = cengi_eval_fecha($enlace['inicio']);
if ($enlace['fin'] && $enlace['fin'] !== $enlace['inicio']) {
    $rangoFechas .= ($rangoFechas !== '' ? ' – ' : '') . cengi_eval_fecha($enlace['fin']);
}

// Mismas 5 preguntas "Acerca del instructor" y mismas 2 preguntas de "tema y logistica"
// en ambas ramas del formulario oficial (Presencial/Virtual): solo cambia el rotulo de
// la seccion, no el texto de las preguntas ni los campos que se guardan.
$preguntasInstructor = [
    'instructor_lenguaje_claro' => '¿Utilizó lenguaje claro y apropiado de tal forma que le facilitó el aprendizaje motivando el diálogo y la participación?',
    'instructor_material_adecuado' => '¿Utilizó material audiovisual y escrito adecuado de tal forma que la información recibida la considera valiosa?',
    'instructor_conocimiento_tema' => '¿El instructor demostró un conocimiento profundo del tema resolviendo dudas de forma adecuada?',
    'instructor_respeto_participantes' => '¿El instructor respetó el punto de vista de los participantes?',
    'instructor_puntualidad_objetivos' => '¿Inició la capacitación en el horario previsto, definió y presentó objetivos?',
];

$preguntasLogistica = [
    'tema_relevancia_utilidad' => '¿Qué tan relevante y útil fue el tema del curso para su área de trabajo (considere la utilidad, la actualidad y la secuencia lógica)?',
];

// "Instalaciones" ya se pregunta aparte (solo Presencial, ver $labelCalidadInstalaciones), y no
// tiene sentido para un curso Virtual: esta pregunta se adapta por modalidad en vez de repetir
// "instalaciones" para ambas ramas como antes.
$labelLogisticaEvento = $modalidad === 'Virtual'
    ? 'Califique la logística del evento (considere la calidad de la conexión, las herramientas utilizadas y la coordinación)'
    : 'Califique la logística del evento (considere el equipo de proyección y la coordinación)';

$labelContexto = $modalidad === 'Virtual'
    ? '¿Le gustaría recibir otro curso a través de la misma plataforma de videoconferencia? (10 estrellas representa la mayor calificación)'
    : 'Le gustaría recibir otro curso en estas instalaciones (donde 10 estrellas representa la mayor calificación)';

// Preguntas exclusivas de una sola rama (a diferencia de $preguntasInstructor/$preguntasLogistica,
// no se preguntan en ambas modalidades): manejo de la plataforma virtual por parte del instructor
// (solo Virtual) y calidad de las instalaciones fisicas (solo Presencial).
$labelInstructorManejoPlataforma = '¿El instructor manejó adecuadamente la plataforma virtual (conexión, herramientas, cámara, pantalla compartida, etc.)?';
$labelCalidadInstalaciones = '¿Cómo calificaría las instalaciones donde se impartió la capacitación?';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
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
            max-width: 640px;
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

        .cengi-eval-required {
            color: #a4342b;
        }

        .cengi-eval-section-title {
            margin: 30px 0 16px;
            padding-top: 20px;
            border-top: 1px solid var(--cengi-border);
            font-family: 'Space Grotesk', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--cengi-primary-deep);
            text-align: left;
        }

        .cengi-eval-section-title:first-of-type {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        .cengi-eval-readonly {
            background: #f3faec;
            border: 1px solid var(--cengi-border);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            color: var(--cengi-muted);
            text-align: left;
            margin: 0 0 20px;
        }

        .cengi-eval-readonly div {
            padding: 2px 0;
        }

        .cengi-eval-readonly strong {
            color: var(--cengi-ink);
        }

        .cengi-eval-field {
            text-align: left;
            margin-bottom: 20px;
        }

        .cengi-eval-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--cengi-primary-deep);
            margin: 0 0 10px;
            text-align: left;
        }

        .cengi-eval-select,
        .cengi-eval-input {
            width: 100%;
            border: 1.5px solid var(--cengi-border);
            border-radius: 12px;
            padding: 11px 14px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: var(--cengi-ink);
            background: #fff;
        }

        .cengi-eval-select:focus,
        .cengi-eval-input:focus {
            outline: none;
            border-color: var(--cengi-primary);
        }

        .cengi-eval-likert {
            margin-bottom: 22px;
            text-align: left;
        }

        .cengi-eval-likert-question {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--cengi-ink);
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .cengi-eval-likert-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .cengi-eval-likert-options input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .cengi-eval-likert-options label {
            flex: 1 1 auto;
            min-width: 78px;
            text-align: center;
            padding: 9px 8px;
            border: 1.5px solid var(--cengi-border);
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--cengi-muted);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .cengi-eval-likert-options input:checked + label {
            background: linear-gradient(135deg, var(--cengi-primary), var(--cengi-primary-strong));
            border-color: var(--cengi-primary);
            color: #fff;
        }

        .cengi-star-rating {
            display: flex;
            flex-direction: row-reverse;
            flex-wrap: wrap;
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

        .cengi-star-rating--10 label {
            font-size: 26px;
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

            .cengi-star-rating--10 label {
                font-size: 22px;
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
            <?php echo cengi_eval_html(trim($modalidad . ($rangoFechas !== '' ? ' · ' . $rangoFechas : ''))); ?>
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

        <div class="cengi-eval-section-title">Datos generales</div>
        <div class="cengi-eval-readonly">
            <div><strong>Curso:</strong> <?php echo cengi_eval_html($enlace['nombre_cursos']); ?></div>
            <div><strong>Conferencista:</strong> <?php echo cengi_eval_html($enlace['instructor_nombre']); ?></div>
            <?php if ($rangoFechas !== ''): ?>
                <div><strong>Fecha:</strong> <?php echo cengi_eval_html($rangoFechas); ?></div>
            <?php endif; ?>
            <div><strong>Modalidad:</strong> <?php echo cengi_eval_html($modalidad); ?></div>
        </div>

        <div class="cengi-eval-field">
            <label class="cengi-eval-label" for="cengiIngenio">Ingenio <span class="cengi-eval-required">*</span></label>
            <select class="cengi-eval-select" id="cengiIngenio" name="ingenio_id" required onchange="cengiToggleIngenioOtro(this)">
                <option value="">Selecciona tu ingenio...</option>
                <?php foreach ($ingenios as $ingenioFila): ?>
                    <option value="<?php echo (int) $ingenioFila['id']; ?>"><?php echo cengi_eval_html($ingenioFila['nombre_ingenios']); ?></option>
                <?php endforeach; ?>
                <option value="otro">Otro</option>
            </select>
        </div>

        <div class="cengi-eval-field" id="cengiIngenioOtroWrap" style="display:none;">
            <label class="cengi-eval-label" for="cengiIngenioOtro">Especifica el ingenio u organización</label>
            <input type="text" class="cengi-eval-input" id="cengiIngenioOtro" name="ingenio_otro" maxlength="255" placeholder="Nombre del ingenio u organización">
        </div>

        <div class="cengi-eval-field">
            <label class="cengi-eval-label" for="cengiCargo">Cargo <span class="cengi-eval-required">*</span></label>
            <input type="text" class="cengi-eval-input" id="cengiCargo" name="cargo" maxlength="255" required placeholder="Tu cargo o puesto de trabajo">
        </div>

        <div class="cengi-eval-field">
            <label class="cengi-eval-label" for="cengiSeccion">Sección <span class="cengi-eval-required">*</span></label>
            <input type="text" class="cengi-eval-input" id="cengiSeccion" name="seccion" maxlength="255" required placeholder="Tu sección o área">
        </div>

        <div class="cengi-eval-section-title">Acerca del instructor (<?php echo cengi_eval_html($modalidad); ?>)</div>
        <?php foreach ($preguntasInstructor as $campo => $pregunta): ?>
            <?php cengi_eval_likert($campo, $pregunta); ?>
        <?php endforeach; ?>
        <?php if ($modalidad === 'Virtual'): ?>
            <?php cengi_eval_likert('instructor_manejo_plataforma', $labelInstructorManejoPlataforma); ?>
        <?php endif; ?>
        <?php cengi_eval_estrellas('recomendaria_instructor', 10, '¿Le gustaría recibir otro curso con este instructor? (10 estrellas representa la mayor calificación)'); ?>

        <div class="cengi-eval-section-title">El tema y la logística del evento (<?php echo cengi_eval_html($modalidad); ?>)</div>
        <?php foreach ($preguntasLogistica as $campo => $pregunta): ?>
            <?php cengi_eval_likert($campo, $pregunta); ?>
        <?php endforeach; ?>
        <?php cengi_eval_likert('logistica_evento', $labelLogisticaEvento); ?>
        <?php if ($modalidad === 'Presencial'): ?>
            <?php cengi_eval_likert('calidad_instalaciones', $labelCalidadInstalaciones); ?>
        <?php endif; ?>

        <div class="cengi-eval-section-title">Percepciones finales (<?php echo cengi_eval_html($modalidad); ?>)</div>
        <?php cengi_eval_estrellas('recomendaria_contexto', 10, $labelContexto); ?>

        <label class="cengi-eval-label" for="cengiCapacitaciones">¿Qué otras capacitaciones necesita para desempeñar mejor su trabajo? <span class="cengi-eval-required">*</span></label>
        <textarea class="cengi-eval-textarea" id="cengiCapacitaciones" name="capacitaciones_necesarias" maxlength="2000" required placeholder="Cuéntanos qué otras capacitaciones te servirían..."></textarea>

        <label class="cengi-eval-label" for="cengiAreasMejora">Oportunidades de mejora <span class="cengi-eval-required">*</span></label>
        <textarea class="cengi-eval-textarea" id="cengiAreasMejora" name="areas_mejora" maxlength="2000" required placeholder="¿Qué podría mejorar el instructor o la actividad?"></textarea>

        <button type="submit" class="cengi-eval-btn">Enviar evaluación</button>
    </form>
</div>

<script>
    function cengiToggleIngenioOtro(select) {
        var wrap = document.getElementById('cengiIngenioOtroWrap');
        var input = document.getElementById('cengiIngenioOtro');
        if (select.value === 'otro') {
            wrap.style.display = 'block';
            input.setAttribute('required', 'required');
        } else {
            wrap.style.display = 'none';
            input.removeAttribute('required');
            input.value = '';
        }
    }
</script>

</body>
</html>
