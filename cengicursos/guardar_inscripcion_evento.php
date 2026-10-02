<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/eventos_helpers.php';

function cengi_inscripcion_evento_redirigir($token, $resultado, $mensaje = '', $codigo = '')
{
    // Ante un error se conservan los datos escritos (excepto el archivo) para que
    // inscripcion_evento.php vuelva a llenar el formulario.
    if ($resultado === 'error') {
        $_SESSION['cengi_ins_evt_previo'] = array_intersect_key($_POST, array_flip(['nombre', 'cui', 'correo', 'telefono', 'institucion', 'pago_boleta', 'pago_fecha', 'pago_banco', 'pago_monto']));
    }
    $parametros = ['token' => $token, 'resultado' => $resultado];
    if ($mensaje !== '') {
        $parametros['mensaje'] = $mensaje;
    }
    if ($codigo !== '') {
        $parametros['codigo'] = $codigo;
    }
    header('Location: inscripcion_evento.php?' . http_build_query($parametros));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inscripcion_evento.php');
    exit;
}

$token = strtolower(trim((string) ($_POST['token'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    cengi_inscripcion_evento_redirigir('', 'error', 'El enlace de inscripción no es válido.');
}

// Campo señuelo: se deja fuera de la vista y debe permanecer vacío.
if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {
    cengi_inscripcion_evento_redirigir($token, 'ok');
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$cui = trim((string) ($_POST['cui'] ?? ''));
$correo = trim((string) ($_POST['correo'] ?? ''));
$telefono = trim((string) ($_POST['telefono'] ?? ''));
$institucion = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['institucion'] ?? '')));
if ($institucion === '') {
    $institucion = 'Particular';
}

if ($nombre === '' || $correo === '') {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Completa tu nombre y correo electrónico.');
}
if (strlen($nombre) > 255 || strlen($cui) > 25 || strlen($correo) > 255 || strlen($telefono) > 30 || strlen($institucion) > 255) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Uno de los datos supera la longitud permitida.');
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'El correo electrónico no es válido.');
}
if (strlen(preg_replace('/\D/', '', $telefono)) < 8) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Escribe un teléfono o WhatsApp de al menos 8 dígitos.');
}

// Nombre para la pantalla de confirmacion ("¡Listo, …!" / "…, tu lugar quedó registrado").
$_SESSION['cengi_ins_evt_nombre'] = $nombre;

$db = conectar();
$stmtEvento = $db->prepare("
    SELECT id, nombre, modalidad_pago, cupo
    FROM eventos
    WHERE token_inscripcion = ?
      AND estado NOT IN ('Finalizado', 'Cancelado')
      AND (fecha IS NULL OR fecha >= CURDATE())
    LIMIT 1
");
$stmtEvento->execute([$token]);
$evento = $stmtEvento->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Este evento ya no está disponible para inscripción.');
}

// Un evento pagado no entrega su codigo QR / gafete de inmediato: la inscripcion
// queda pendiente de verificacion de pago (evento_participantes.pagado = 0, valor por
// defecto) y el gafete solo se envia manualmente desde el panel de administracion
// (cengicursos/enviar_gafetes_evento.php) una vez el organizador confirma el pago con
// el recibo adjunto. Un evento gratuito conserva el flujo original (QR inmediato).
$eventoPagado = $evento['modalidad_pago'] === 'Pagado';

$condicionCui = $cui !== ''
    ? " OR COALESCE(NULLIF(ep.cui_invitado, ''), NULLIF(p.cui_participantes, '')) = ?"
    : '';
$stmtDuplicado = $db->prepare("
    SELECT ep.codigo_qr, ep.pagado
    FROM evento_participantes ep
    LEFT JOIN participantes p ON p.id = ep.participante_id
    WHERE ep.evento_id = ?
      AND (
        LOWER(COALESCE(NULLIF(ep.correo_invitado, ''), NULLIF(p.correo_participantes, ''))) = LOWER(?)
        {$condicionCui}
      )
    LIMIT 1
");
$parametrosDuplicado = [(int) $evento['id'], $correo];
if ($cui !== '') {
    $parametrosDuplicado[] = $cui;
}
$stmtDuplicado->execute($parametrosDuplicado);
$duplicado = $stmtDuplicado->fetch(PDO::FETCH_ASSOC);
if ($duplicado) {
    // Si el evento es pagado y ese registro previo aun no fue verificado, no se le
    // muestra el codigo QR (mismo criterio que una inscripcion nueva pendiente de pago).
    if ($eventoPagado && !(int) $duplicado['pagado']) {
        cengi_inscripcion_evento_redirigir($token, 'pendiente');
    }
    cengi_inscripcion_evento_redirigir($token, 'existente', '', (string) $duplicado['codigo_qr']);
}

// Cupo del evento (opcional, "Editar evento"): se valida despues de la busqueda de
// duplicados para que quien ya estaba inscrito siga viendo su QR.
if ((int) ($evento['cupo'] ?? 0) > 0) {
    $stmtCupo = $db->prepare('SELECT COUNT(*) FROM evento_participantes WHERE evento_id = ?');
    $stmtCupo->execute([(int) $evento['id']]);
    if ((int) $stmtCupo->fetchColumn() >= (int) $evento['cupo']) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'El evento ya no tiene cupo disponible.');
    }
}

// "Institucion o ingenio" es texto libre (como en el prototipo); si coincide con un
// ingenio del catalogo tambien se guarda ingenio_id, con el mismo criterio TRIM que la
// carga masiva (cengi_evt_carga_masiva_filas en eventos_qr.php).
$stmtIngenioTexto = $db->prepare('SELECT id FROM ingenios WHERE TRIM(nombre_ingenios) = TRIM(?) LIMIT 1');
$stmtIngenioTexto->execute([$institucion]);
$ingenioId = (int) ($stmtIngenioTexto->fetchColumn() ?: 0);
$ingenioId = $ingenioId > 0 ? $ingenioId : null;

$reciboPago = null;
$pagoBoleta = null;
$pagoBanco = null;
$pagoFecha = null;
$pagoMonto = null;

if ($eventoPagado) {
    $pagoBoleta = trim((string) ($_POST['pago_boleta'] ?? ''));
    $pagoBanco = trim((string) ($_POST['pago_banco'] ?? ''));
    $pagoFecha = trim((string) ($_POST['pago_fecha'] ?? ''));
    $pagoMontoTexto = trim((string) ($_POST['pago_monto'] ?? ''));

    if ($pagoBoleta === '') {
        cengi_inscripcion_evento_redirigir($token, 'error', 'Escribe el número de boleta o transferencia.');
    }
    $fechaPagoObj = DateTime::createFromFormat('!Y-m-d', $pagoFecha);
    if (!$fechaPagoObj || $fechaPagoObj->format('Y-m-d') !== $pagoFecha) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'Indica la fecha del pago.');
    }
    if (!is_numeric($pagoMontoTexto) || (float) $pagoMontoTexto <= 0 || (float) $pagoMontoTexto >= 100000000) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'Indica el monto pagado.');
    }
    if (strlen($pagoBoleta) > 60 || strlen($pagoBanco) > 120) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'Uno de los datos supera la longitud permitida.');
    }
    $pagoBanco = $pagoBanco !== '' ? $pagoBanco : null;
    $pagoMonto = round((float) $pagoMontoTexto, 2);

    $archivoRecibo = $_FILES['recibo_pago'] ?? null;
    if (!$archivoRecibo || !is_uploaded_file((string) ($archivoRecibo['tmp_name'] ?? ''))) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'Adjunta la foto o PDF de tu comprobante.');
    }
    if ((int) $archivoRecibo['error'] !== UPLOAD_ERR_OK) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'No fue posible subir el recibo de pago. Intenta nuevamente.');
    }
    if ((int) $archivoRecibo['size'] > 5 * 1024 * 1024) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'El recibo de pago supera el tamaño máximo permitido (5 MB).');
    }

    $extensionRecibo = strtolower(pathinfo((string) $archivoRecibo['name'], PATHINFO_EXTENSION));
    $mimeRecibo = (new finfo(FILEINFO_MIME_TYPE))->file($archivoRecibo['tmp_name']);
    // El tipo real del archivo se valida con finfo (contenido), no solo con la
    // extension del nombre recibido del cliente, para evitar subir un archivo
    // ejecutable disfrazado con extension .pdf/.jpg/.png.
    $tiposPermitidos = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
    if (!isset($tiposPermitidos[$extensionRecibo]) || $tiposPermitidos[$extensionRecibo] !== $mimeRecibo) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'El recibo debe ser un archivo PDF, JPG o PNG válido.');
    }

    // Nombre de archivo aleatorio (no el nombre original del cliente), guardado por
    // cengi_guardar_archivo_subido() (conexion.php) fuera del arbol ejecutable de PHP,
    // en uploads/recibos_evento/ (mismo patron que diplomas/diplomas_evento).
    $nombreArchivoRecibo = bin2hex(random_bytes(16)) . '.' . $extensionRecibo;
    $reciboPago = cengi_guardar_archivo_subido($archivoRecibo['tmp_name'], 'recibos_evento', $nombreArchivoRecibo);
    if ($reciboPago === null) {
        cengi_inscripcion_evento_redirigir($token, 'error', 'No fue posible guardar el recibo de pago. Intenta nuevamente.');
    }
}

$participanteId = null;
if ($cui !== '') {
    $stmtParticipante = $db->prepare('SELECT id FROM participantes WHERE cui_participantes = ? LIMIT 1');
    $stmtParticipante->execute([$cui]);
    $participanteId = $stmtParticipante->fetchColumn() ?: null;
}
if ($participanteId === null) {
    $stmtParticipante = $db->prepare("SELECT id FROM participantes WHERE correo_participantes <> '' AND LOWER(correo_participantes) = LOWER(?) LIMIT 1");
    $stmtParticipante->execute([$correo]);
    $participanteId = $stmtParticipante->fetchColumn() ?: null;
}

try {
    // El codigo QR se genera siempre (codigo_qr es NOT NULL/UNIQUE), incluso para un
    // evento pagado: se guarda como identificador interno del participante, pero no se
    // muestra en el redirect ni se envia gafete hasta que el pago quede verificado
    // (evento_participantes.pagado = 1, panel de administracion).
    $codigo = cengi_evento_generar_codigo_qr($db);
    $stmt = $db->prepare("
        INSERT INTO evento_participantes
          (evento_id, participante_id, nombre_invitado, cui_invitado, correo_invitado, telefono_invitado,
           institucion_invitado, ingenio_id, codigo_qr, recibo_pago, pago_boleta, pago_banco, pago_fecha, pago_monto)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int) $evento['id'], $participanteId, $nombre, $cui, $correo, $telefono,
        $institucion, $ingenioId, $codigo, $reciboPago, $pagoBoleta, $pagoBanco, $pagoFecha, $pagoMonto,
    ]);
} catch (Throwable $e) {
    error_log('Error en inscripción pública al evento ' . $evento['id'] . ': ' . $e->getMessage());
    cengi_inscripcion_evento_redirigir($token, 'error', 'No fue posible completar la inscripción. Intenta nuevamente.');
}

if ($eventoPagado) {
    cengi_inscripcion_evento_redirigir($token, 'pendiente');
}

cengi_inscripcion_evento_redirigir($token, 'ok', '', $codigo);

