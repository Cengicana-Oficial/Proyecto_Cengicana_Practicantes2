<?php
require_once __DIR__ . '/conexion.php';

$db = conectar();

function evaluacion_error($token, $mensaje)
{
    header('Location: evaluacion.php?token=' . rawurlencode($token) . '&resultado=error&mensaje=' . rawurlencode($mensaje));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: evaluacion.php');
    exit;
}

$token = trim((string) ($_POST['token'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    require __DIR__ . '/404.php';
    exit;
}

// Se vuelve a resolver el enlace completo (no solo el id) porque curso_nombre,
// conferencista, modalidad y fecha_curso se guardan con los valores ya conocidos del
// JOIN, nunca con lo que el participante haya podido enviar en el formulario.
$stmt = $db->prepare("
    SELECT
        e.id AS enlace_id,
        c.nombre_cursos,
        c.tipo AS modalidad,
        c.inicio,
        i.nombre AS instructor_nombre,
        cm.nombre AS modulo_nombre
    FROM enlaces_evaluacion_instructor e
    INNER JOIN cursos c ON c.id = e.curso_id
    INNER JOIN instructores i ON i.id = e.instructor_id
    LEFT JOIN curso_modulos cm ON cm.id = e.curso_modulo_id
    WHERE e.token = ?
");
$stmt->execute([$token]);
$enlace = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$enlace) {
    require __DIR__ . '/404.php';
    exit;
}

$modalidad = ($enlace['modalidad'] === 'Virtual') ? 'Virtual' : 'Presencial';

/**
 * Trunca (no rechaza) un texto libre a $max caracteres, igual que el comportamiento
 * previo de este script para comentario/areas_mejora.
 */
function evaluacion_truncar($valor, $max)
{
    $valor = trim((string) $valor);
    if (mb_strlen($valor) > $max) {
        $valor = mb_substr($valor, 0, $max);
    }
    return $valor;
}

/**
 * Valida un entero requerido dentro de [1, $max] (usado tanto para las preguntas
 * Likert de 4 opciones como para las calificaciones de 1 a 10 estrellas).
 */
function evaluacion_entero_rango($valorCrudo, $max)
{
    $texto = trim((string) $valorCrudo);
    if ($texto === '' || !ctype_digit($texto)) {
        return null;
    }
    $numero = (int) $texto;
    if ($numero < 1 || $numero > $max) {
        return null;
    }
    return $numero;
}

// --- Ingenio: seleccionado por id (FK real) o "otro" con texto libre ---
$ingenioCrudo = trim((string) ($_POST['ingenio_id'] ?? ''));
$ingenioOtro = trim((string) ($_POST['ingenio_otro'] ?? ''));

$ingenioId = null;
if ($ingenioCrudo === '') {
    evaluacion_error($token, 'Selecciona tu ingenio.');
} elseif ($ingenioCrudo === 'otro') {
    if ($ingenioOtro === '') {
        evaluacion_error($token, 'Especifica el nombre de tu ingenio u organización.');
    }
    $ingenioOtro = mb_substr($ingenioOtro, 0, 255);
} elseif (ctype_digit($ingenioCrudo)) {
    $stmtIngenio = $db->prepare('SELECT id FROM ingenios WHERE id = ?');
    $stmtIngenio->execute([(int) $ingenioCrudo]);
    if (!$stmtIngenio->fetch(PDO::FETCH_ASSOC)) {
        evaluacion_error($token, 'El ingenio seleccionado no es válido.');
    }
    $ingenioId = (int) $ingenioCrudo;
    $ingenioOtro = '';
} else {
    evaluacion_error($token, 'El ingenio seleccionado no es válido.');
}

// --- Cargo / Sección ---
$cargo = trim((string) ($_POST['cargo'] ?? ''));
if ($cargo === '') {
    evaluacion_error($token, 'Indica tu cargo.');
}
$cargo = mb_substr($cargo, 0, 255);

$seccion = trim((string) ($_POST['seccion'] ?? ''));
if ($seccion === '') {
    evaluacion_error($token, 'Indica tu sección.');
}
$seccion = mb_substr($seccion, 0, 255);

// --- Preguntas Likert "Acerca del instructor" (1..4), mismo texto en ambas ramas ---
$camposLikertInstructor = [
    'instructor_lenguaje_claro',
    'instructor_material_adecuado',
    'instructor_conocimiento_tema',
    'instructor_respeto_participantes',
    'instructor_puntualidad_objetivos',
];

$valoresLikertInstructor = [];
foreach ($camposLikertInstructor as $campo) {
    $valor = evaluacion_entero_rango($_POST[$campo] ?? '', 4);
    if ($valor === null) {
        evaluacion_error($token, 'Responde todas las preguntas sobre el instructor.');
    }
    $valoresLikertInstructor[$campo] = $valor;
}

// --- ¿El instructor manejó bien la plataforma virtual? (1..4, solo aplica si Virtual) ---
$instructorManejoPlataforma = null;
if ($modalidad === 'Virtual') {
    $instructorManejoPlataforma = evaluacion_entero_rango($_POST['instructor_manejo_plataforma'] ?? '', 4);
    if ($instructorManejoPlataforma === null) {
        evaluacion_error($token, 'Responde si el instructor manejó adecuadamente la plataforma virtual.');
    }
}

// --- ¿Recibiría otro curso con este instructor? (1..10) ---
$recomendariaInstructor = evaluacion_entero_rango($_POST['recomendaria_instructor'] ?? '', 10);
if ($recomendariaInstructor === null) {
    evaluacion_error($token, 'Selecciona una calificación de 1 a 10 estrellas para el instructor.');
}

// --- Preguntas Likert "El tema y la logística del evento" (1..4) ---
$camposLikertLogistica = ['tema_relevancia_utilidad', 'logistica_evento'];
$valoresLikertLogistica = [];
foreach ($camposLikertLogistica as $campo) {
    $valor = evaluacion_entero_rango($_POST[$campo] ?? '', 4);
    if ($valor === null) {
        evaluacion_error($token, 'Responde las preguntas sobre el tema y la logística del evento.');
    }
    $valoresLikertLogistica[$campo] = $valor;
}

// --- ¿Cómo calificaría las instalaciones? (1..4, solo aplica si Presencial) ---
$calidadInstalaciones = null;
if ($modalidad === 'Presencial') {
    $calidadInstalaciones = evaluacion_entero_rango($_POST['calidad_instalaciones'] ?? '', 4);
    if ($calidadInstalaciones === null) {
        evaluacion_error($token, 'Responde cómo calificaría las instalaciones donde se impartió la capacitación.');
    }
}

// --- ¿Recibiría otro curso en el mismo lugar/plataforma? (1..10) ---
$recomendariaContexto = evaluacion_entero_rango($_POST['recomendaria_contexto'] ?? '', 10);
if ($recomendariaContexto === null) {
    evaluacion_error($token, 'Selecciona una calificación de 1 a 10 estrellas para las percepciones finales.');
}

// --- Texto libre final: capacitaciones necesarias + oportunidades de mejora ---
$capacitacionesNecesarias = trim((string) ($_POST['capacitaciones_necesarias'] ?? ''));
if ($capacitacionesNecesarias === '') {
    evaluacion_error($token, 'Indica qué otras capacitaciones necesitas.');
}
$capacitacionesNecesarias = evaluacion_truncar($capacitacionesNecesarias, 2000);

$areasMejora = trim((string) ($_POST['areas_mejora'] ?? ''));
if ($areasMejora === '') {
    evaluacion_error($token, 'Indica las oportunidades de mejora.');
}
$areasMejora = evaluacion_truncar($areasMejora, 2000);

try {
    $stmtInsertar = $db->prepare('
        INSERT INTO evaluaciones_instructor (
            enlace_id,
            ingenio_id,
            ingenio_otro,
            cargo,
            seccion,
            modalidad,
            curso_nombre,
            modulo_nombre,
            conferencista,
            fecha_curso,
            instructor_lenguaje_claro,
            instructor_material_adecuado,
            instructor_conocimiento_tema,
            instructor_respeto_participantes,
            instructor_puntualidad_objetivos,
            instructor_manejo_plataforma,
            recomendaria_instructor,
            tema_relevancia_utilidad,
            logistica_evento,
            calidad_instalaciones,
            recomendaria_contexto,
            capacitaciones_necesarias,
            areas_mejora
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ');
    $stmtInsertar->execute([
        $enlace['enlace_id'],
        $ingenioId,
        $ingenioOtro,
        $cargo,
        $seccion,
        $modalidad,
        $enlace['nombre_cursos'],
        $enlace['modulo_nombre'] ?? '',
        $enlace['instructor_nombre'],
        $enlace['inicio'] ?: null,
        $valoresLikertInstructor['instructor_lenguaje_claro'],
        $valoresLikertInstructor['instructor_material_adecuado'],
        $valoresLikertInstructor['instructor_conocimiento_tema'],
        $valoresLikertInstructor['instructor_respeto_participantes'],
        $valoresLikertInstructor['instructor_puntualidad_objetivos'],
        $instructorManejoPlataforma,
        $recomendariaInstructor,
        $valoresLikertLogistica['tema_relevancia_utilidad'],
        $valoresLikertLogistica['logistica_evento'],
        $calidadInstalaciones,
        $recomendariaContexto,
        $capacitacionesNecesarias,
        $areasMejora,
    ]);
} catch (PDOException $e) {
    error_log('Error al guardar evaluacion de instructor: ' . $e->getMessage());
    evaluacion_error($token, 'No fue posible enviar tu evaluación. Intenta más tarde.');
}

header('Location: evaluacion.php?token=' . rawurlencode($token) . '&resultado=ok');
exit;
