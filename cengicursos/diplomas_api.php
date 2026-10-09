<?php
/**
 * API JSON del diseñador de diplomas (diplomas.php):
 *   GET  ?actividad=cu:<id>|ev:<id>  -> actividad, plantilla y destinatarios
 *   GET  ?validar=<codigo>           -> datos del documento emitido
 *   POST accion=guardar   (clave, plantilla)            -> guarda el diseño
 *   POST accion=emitir    (clave, plantilla, ids[])     -> guarda y asigna codigos (para imprimir)
 *   POST accion=enviar    (clave, plantilla, ids[])     -> emite y envia por correo el enlace al diploma
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/diplomas_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function cengi_dip_responder($datos, $codigo = 200)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!cengi_puede_ver_diplomas()) {
    cengi_dip_responder(['ok' => false, 'error' => 'No tienes acceso a Certificación.'], 403);
}

$db = conectar();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['validar'])) {
            $d = cengi_dip_por_codigo($db, $_GET['validar']);
            cengi_dip_responder($d ? ['ok' => true, 'valido' => true, 'codigo' => $d['codigo_unico'], 'nombre' => $d['nombre'], 'actividad' => $d['actividad_nombre'],
                'documento' => $d['documento'] ?: ($d['tipo'] === 'evento' ? 'Constancia de participación' : 'Diploma'), 'emitido' => $d['emitido_en'],
                'enviado' => $d['enviado_en'], 'pdf' => (bool) $d['pdf_path'], 'url' => cengi_dip_url_validar($d['codigo_unico'])] : ['ok' => true, 'valido' => false]);
        }
        $actividad = cengi_dip_actividad($db, $_GET['actividad'] ?? '');
        if (!$actividad) {
            cengi_dip_responder(['ok' => false, 'error' => 'La actividad no existe.'], 404);
        }
        cengi_dip_responder(['ok' => true, 'actividad' => $actividad, 'plantilla' => cengi_dip_plantilla($db, $actividad), 'defecto' => cengi_dip_plantilla_defecto($actividad),
            'participantes' => cengi_dip_participantes($db, $actividad), 'urlBase' => cengi_dip_url_validar('')]);
    }

    if (!cengi_puede_gestionar_diplomas()) {
        cengi_dip_responder(['ok' => false, 'error' => 'No tienes permiso para emitir diplomas.'], 403);
    }
    $accion = (string) ($_POST['accion'] ?? '');
    $actividad = cengi_dip_actividad($db, $_POST['clave'] ?? '');
    if (!$actividad) {
        throw new InvalidArgumentException('La actividad no existe.');
    }
    $plantilla = cengi_dip_validar_plantilla($_POST['plantilla'] ?? '');
    $usuario = trim((string) ($_SESSION['usuario'] ?? '')) ?: 'Sistema';

    $db->beginTransaction();
    cengi_dip_guardar_plantilla($db, $actividad['clave'], $plantilla, $usuario);
    if ($accion === 'guardar') {
        $db->commit();
        cengi_dip_responder(['ok' => true]);
    }
    if (!in_array($accion, ['emitir', 'enviar'], true)) {
        throw new InvalidArgumentException('Acción no válida.');
    }
    if (($plantilla['estilo'] ?? '') === 'canva' && empty($plantilla['canva']['fondo'])) {
        throw new InvalidArgumentException('Primero sube el diseño de Canva.');
    }
    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    if (!$ids) {
        throw new InvalidArgumentException('Marca al menos un participante.');
    }
    $codigos = cengi_dip_emitir($db, $actividad, $ids, $plantilla, cengi_usuario_actual_id());
    $db->commit();

    $resultado = ['ok' => true, 'codigos' => $codigos, 'urls' => array_map('cengi_dip_url_validar', $codigos)];
    if ($accion === 'enviar') {
        require_once __DIR__ . '/planificacion_helpers.php';
        $participantes = [];
        foreach (cengi_dip_participantes($db, $actividad) as $p) {
            $participantes[$p['id']] = $p;
        }
        $documento = mb_strtolower((string) ($plantilla['documento'] ?? 'diploma'), 'UTF-8');
        $enviados = 0;
        $sinCorreo = [];
        foreach ($codigos as $id => $codigo) {
            $p = $participantes[$id];
            if (!filter_var($p['correo'], FILTER_VALIDATE_EMAIL)) {
                $sinCorreo[] = $p['nombre'];
                continue;
            }
            [, $ok] = cengi_pp_enviar_comunicacion($db, [
                'categoria' => 'Diplomas', 'origen' => 'sistema', 'referencia' => null,
                'asunto' => 'Tu ' . $documento . ': ' . $actividad['actividad'],
                'mensaje' => "Hola {nombre}:\n\nYa está disponible tu {$documento} de {$actividad['actividad']}.\n\nPuedes verlo, imprimirlo o guardarlo como PDF en:\n"
                    . cengi_dip_url_validar($codigo) . "\n\nCódigo de validación: {$codigo}\n\nCoordinación de Capacitación — CENGICAÑA",
            ], [['nombre' => $p['nombre'], 'correo' => $p['correo']]]);
            if ($ok > 0) {
                $enviados++;
                $db->prepare("UPDATE diplomas SET enviado_en = NOW() WHERE codigo_unico = ?")->execute([$codigo]);
            }
        }
        $resultado += ['enviados' => $enviados, 'sinCorreo' => $sinCorreo, 'fallidos' => count($codigos) - $enviados - count($sinCorreo)];
    }
    cengi_dip_responder($resultado);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($e instanceof InvalidArgumentException) {
        cengi_dip_responder(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    error_log('diplomas_api: ' . $e->getMessage());
    cengi_dip_responder(['ok' => false, 'error' => 'No fue posible completar la acción.'], 500);
}
