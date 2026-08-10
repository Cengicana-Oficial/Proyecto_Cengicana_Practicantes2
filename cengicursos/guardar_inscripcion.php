<?php
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/correo.php";

function inscripcion_error($mensaje)
{
    header("Location: inscripcion.php?resultado=error&mensaje=" . rawurlencode($mensaje));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: inscripcion.php");
    exit;
}

$db = conectar();

$nombreParticipante = trim((string) ($_POST['nombre_participante'] ?? ''));
$cuiParticipante = trim((string) ($_POST['cui_participante'] ?? ''));
$puestoParticipante = trim((string) ($_POST['puesto_participante'] ?? ''));
$areaParticipante = trim((string) ($_POST['area_participante'] ?? ''));
$gradoAcademico = trim((string) ($_POST['grado_academico'] ?? ''));
$correo = trim((string) ($_POST['correo'] ?? ''));
$telefono = trim((string) ($_POST['telefono'] ?? ''));
$ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
$tipoPago = trim((string) ($_POST['tipo_pago'] ?? ''));
$cursoId = (int) ($_POST['curso_id'] ?? 0);

if ($nombreParticipante === '' || $cuiParticipante === '' || $puestoParticipante === '' || $areaParticipante === '' || $gradoAcademico === '' || $telefono === '') {
    inscripcion_error('Completa todos los campos obligatorios.');
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    inscripcion_error('El correo electrónico no es válido.');
}

if (!in_array($tipoPago, ['Ingenio', 'Propio'], true)) {
    inscripcion_error('Selecciona un tipo de pago válido.');
}

if ($ingenioId <= 0) {
    inscripcion_error('Selecciona un ingenio.');
}

$stmtIngenio = $db->prepare("SELECT id FROM ingenios WHERE id = ?");
$stmtIngenio->execute([$ingenioId]);
if (!$stmtIngenio->fetch()) {
    inscripcion_error('El ingenio seleccionado no es válido.');
}

if ($cursoId <= 0) {
    inscripcion_error('Selecciona un curso.');
}

$stmtCurso = $db->prepare("SELECT id, nombre_cursos FROM cursos WHERE id = ?");
$stmtCurso->execute([$cursoId]);
$cursoSeleccionado = $stmtCurso->fetch();
if (!$cursoSeleccionado) {
    inscripcion_error('El curso seleccionado no es válido.');
}
$nombreCurso = $cursoSeleccionado['nombre_cursos'] ?? '';

$sql = "
    INSERT INTO solicitudes_inscripcion (
        nombre_participante,
        cui_participante,
        puesto_participante,
        area_participante,
        grado_academico,
        correo,
        telefono,
        ingenio_id,
        curso_id,
        tipo_pago
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $nombreParticipante,
        $cuiParticipante,
        $puestoParticipante,
        $areaParticipante,
        $gradoAcademico,
        $correo,
        $telefono,
        $ingenioId,
        $cursoId,
        $tipoPago,
    ]);
} catch (PDOException $e) {
    error_log("Error al guardar solicitud de inscripcion: " . $e->getMessage());
    inscripcion_error('No fue posible enviar tu solicitud. Intenta más tarde.');
}

try {

    $solicitud = [
        'nombre_participante' => $nombreParticipante,
        'correo' => $correo,
        'nombre_cursos' => $nombreCurso,
    ];

    require __DIR__ . '/correos/plantilla_recibida.php';

    $correoEnviado = cengi_enviar_correo(
        $correo,
        $nombreParticipante,
        'Hemos recibido tu solicitud de inscripción',
        $html
    );

    if (!$correoEnviado) {
        error_log(
            "No se pudo enviar correo de confirmacion de solicitud recibida " .
            "para {$correo}"
        );
    }

} catch (Throwable $mailError) {

    error_log(
        "Error enviando correo de confirmacion de solicitud recibida: " .
        $mailError->getMessage()
    );
}

header("Location: inscripcion.php?resultado=ok");
exit;
