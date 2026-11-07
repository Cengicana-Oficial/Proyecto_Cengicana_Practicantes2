<?php
/**
 * validacion_tecnica_controller.php
 * Endpoint AJAX (POST JSON) para aprobar/rechazar un analisis desde la
 * matriz de validacion tecnica (view/validacion_tecnica_view.php).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/validacion_tecnica_model.php';

header('Content-Type: application/json; charset=utf-8');

lab_require_permission('laboratorio.validacion_tecnica.aprobar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$idFormulario = (int) ($payload['id_formulario'] ?? 0);
$accion = trim((string) ($payload['accion'] ?? ''));
$comentario = trim((string) ($payload['comentario'] ?? ''));
$reanalizar = is_array($payload['reanalizar'] ?? null) ? $payload['reanalizar'] : [];
$categoria = trim((string) ($payload['categoria'] ?? ''));

// Hoja completa: todos los formularios (uno por análisis) de un mismo envío.
$idsFormulario = is_array($payload['ids_formulario'] ?? null)
    ? array_values(array_filter(array_map('intval', $payload['ids_formulario']), static fn($id) => $id > 0))
    : [];

if (($idFormulario <= 0 && !$idsFormulario) || !in_array($accion, ['aprobar', 'rechazar'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$usuario = lab_current_user();

// Constancia de validación: aprobar exige que el técnico la haya confirmado en pantalla. Se registra
// aparte en el historial de cada formulario (no en el comentario, que es el motivo de reanálisis
// que recibe el analista): quién, cargo y declaración; la fecha la pone la base.
$constancia = null;
if ($accion === 'aprobar') {
    if (($payload['constancia'] ?? false) !== true) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Confirme la constancia de validación técnica antes de aprobar.']);
        exit;
    }
    $cargo = trim((string) ($usuario['rol'] ?? ''));
    $constancia = 'Constancia de validación técnica: ' . (trim((string) ($usuario['nombre'] ?? '')) ?: 'Usuario sin nombre')
        . ($cargo !== '' ? ' (' . $cargo . ')' : '')
        . ' declara que revisó los datos capturados, los cálculos y los controles, y que los resultados aprobados son conformes.';
}

try {
    $pdo = Conexion::conectar();
    // Una sola transacción: la aprobación y su constancia se guardan juntas o ninguna.
    $pdo->beginTransaction();
    $resultado = $idsFormulario ? lab_validacion_resolver_grupo(
        $pdo,
        $idsFormulario,
        $accion,
        (string) ($usuario['nombre'] ?? ''),
        !empty($usuario['id']) ? (int) $usuario['id'] : null,
        $comentario,
        $reanalizar,
        $categoria,
        // Parámetros de la corrida corregidos por el técnico (p. ej. factor de temperatura CE).
        is_array($payload['encabezado'] ?? null) ? $payload['encabezado'] : [],
        is_array($payload['texturas'] ?? null) ? $payload['texturas'] : []
    ) : lab_validacion_resolver(
        $pdo,
        $idFormulario,
        $accion,
        (string) ($usuario['nombre'] ?? ''),
        !empty($usuario['id']) ? (int) $usuario['id'] : null,
        $comentario,
        $reanalizar,
        $categoria
    );
    if ($constancia !== null) {
        $registrar = $pdo->prepare("INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario) VALUES (?, 'constancia_validacion', NULL, NULL, ?, NOW(), ?)");
        foreach ($idsFormulario ?: [$idFormulario] as $idConst) {
            $registrar->execute([(int) $idConst, (string) ($usuario['nombre'] ?? ''), $constancia]);
        }
    }
    $pdo->commit();

    echo json_encode(['ok' => true, 'data' => $resultado]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    // PDOException extiende RuntimeException: se atrapa antes para no exponer errores de BD.
    error_log('[laboratorio][validacion_tecnica] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la decision.']);
} catch (RuntimeException $e) {
    // Mensajes de negocio: formulario inexistente o ya resuelto.
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[laboratorio][validacion_tecnica] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la decision.']);
} finally {
    // Cualquier error deja todo como estaba (aprobación y constancia).
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
