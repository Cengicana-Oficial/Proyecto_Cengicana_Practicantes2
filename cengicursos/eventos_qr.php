<?php
require_once "conexion.php";
require_once "menu.php";
require_once __DIR__ . "/eventos_helpers.php";

cengi_require_ver_eventos();
$db = conectar();
$puedeGestionar = cengi_puede_gestionar_eventos();
$mensaje = '';
$mensajeTipo = 'success';
$eventoReabrirId = (int) ($_GET['evento_id'] ?? 0);
$avisos = [];

const CENGI_EVT_CARGA_MASIVA_MAX_FILAS = 500;
const CENGI_EVT_CARGA_MASIVA_MAX_BYTES = 5 * 1024 * 1024;

function cengi_evt_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_evt_carga_masiva_valor($valor)
{
    if ($valor === null) {
        return '';
    }

    return trim((string) $valor);
}

/* Igual patron que cengi_carga_inscripcion_filas() en carga_inscripcion.php:
   CSV se lee con fgetcsv(); .xls se lee con la libreria PHPExcel vendorizada
   (classes/PHPExcel.php). Devuelve un array de filas [nombre, cui, correo, ingenio]. */
function cengi_evt_carga_masiva_filas($archivoTemporal, $extension)
{
    if ($extension === 'csv') {
        $handle = fopen($archivoTemporal, 'r');
        if ($handle === false) {
            throw new RuntimeException('No fue posible abrir el archivo.');
        }

        $filas = [];
        while (($fila = fgetcsv($handle, 0, ',')) !== false) {
            $filas[] = $fila;
        }
        fclose($handle);

        return $filas;
    }

    require_once __DIR__ . '/classes/PHPExcel.php';
    require_once __DIR__ . '/classes/PHPExcel/IOFactory.php';

    // Ver el comentario equivalente en carga_inscripcion.php: la libreria
    // PHPExcel vendorizada (2014) genera propiedades dinamicas deprecadas
    // desde PHP 8.1+, incluyendo en el singleton PHPExcel_Calculation, cuyo
    // aviso se dispara al destruirse al final del script. Por eso no se
    // restaura error_reporting despues de esta llamada.
    error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR);

    try {
        $libro = PHPExcel_IOFactory::load($archivoTemporal);
    } catch (Throwable $e) {
        throw new RuntimeException('El archivo Excel está dañado o no tiene un formato válido.');
    }

    $hoja = $libro->getActiveSheet();
    $maxFila = $hoja->getHighestRow();
    $filas = [];

    for ($fila = 1; $fila <= $maxFila; $fila++) {
        $filas[] = [
            cengi_evt_carga_masiva_valor($hoja->getCellByColumnAndRow(0, $fila)->getCalculatedValue()),
            cengi_evt_carga_masiva_valor($hoja->getCellByColumnAndRow(1, $fila)->getCalculatedValue()),
            cengi_evt_carga_masiva_valor($hoja->getCellByColumnAndRow(2, $fila)->getCalculatedValue()),
            cengi_evt_carga_masiva_valor($hoja->getCellByColumnAndRow(3, $fila)->getCalculatedValue()),
        ];
    }

    return $filas;
}

function cengi_evt_estado_badge($estado)
{
    $mapa = [
        'Planificado' => 'is-upcoming',
        'En curso' => 'is-active',
        'Finalizado' => 'is-finished',
        'Cancelado' => 'is-rejected',
    ];
    return $mapa[$estado] ?? 'is-neutral';
}

/* Devuelve 'Pagado' o 'Gratuito' para un evento (usa la misma normalizacion que
   cengi_evento_modalidad_pago()). Sirve para decidir si el estado "pagado" por
   participante aplica al registrar/editar. */
function cengi_evt_modalidad_evento(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT modalidad_pago FROM eventos WHERE id = ?');
    $stmt->execute([(int) $eventoId]);
    return cengi_evento_modalidad_pago($stmt->fetchColumn());
}

/* Respuesta JSON usada por el modal de participantes. */
if (($_GET['accion'] ?? '') === 'listar_participantes') {
    header('Content-Type: application/json; charset=UTF-8');
    $eventoId = (int) ($_GET['evento_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, nombre, tipo, modalidad_pago, costo, fecha, estado FROM eventos WHERE id = ?");
    $stmt->execute([$eventoId]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evento) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'El evento no existe.']);
        exit;
    }
    $stmt = $db->prepare("
        SELECT ep.id, ep.nombre_invitado AS nombre, ep.cui_invitado AS cui,
               ep.codigo_qr, ep.ingreso_en, ep.pagado, ep.ingenio_id, ep.recibo_pago,
               ep.telefono_invitado AS telefono, ep.pago_boleta, ep.pago_banco, ep.pago_fecha, ep.pago_monto,
               COALESCE(ip.nombre_ingenios, ie.nombre_ingenios, NULLIF(ep.institucion_invitado, ''), 'Invitado externo') AS ingenio,
               COALESCE(NULLIF(p.correo_participantes, ''), NULLIF(ep.correo_invitado, '')) AS correo
        FROM evento_participantes ep
        LEFT JOIN participantes p ON p.id = ep.participante_id
        LEFT JOIN ingenios ip ON ip.id = p.ingenio_id
        LEFT JOIN ingenios ie ON ie.id = ep.ingenio_id
        WHERE ep.evento_id = ?
        ORDER BY ep.nombre_invitado, ep.id
    ");
    $stmt->execute([$eventoId]);
    // pagado / ingenio_id llegan como string desde PDO; se normalizan a int para el front.
    $participantes = array_map(static function (array $fila) {
        $fila['pagado'] = (int) $fila['pagado'];
        $fila['ingenio_id'] = $fila['ingenio_id'] !== null ? (int) $fila['ingenio_id'] : null;
        return $fila;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode([
        'ok' => true,
        'evento' => $evento,
        'participantes' => $participantes,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Enlace publico para que cada participante se inscriba y obtenga su QR. */
if (($_GET['accion'] ?? '') === 'enlace_inscripcion') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$puedeGestionar) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'mensaje' => 'No tienes permiso para generar este enlace.']);
        exit;
    }

    $eventoId = (int) ($_GET['evento_id'] ?? 0);
    $stmt = $db->prepare('SELECT id, nombre FROM eventos WHERE id = ?');
    $stmt->execute([$eventoId]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evento) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'El evento no existe.']);
        exit;
    }

    try {
        $token = cengi_evento_asegurar_token_inscripcion($db, $eventoId);
        echo json_encode([
            'ok' => true,
            'evento_id' => (int) $evento['id'],
            'evento_nombre' => $evento['nombre'],
            'token' => $token,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('No se pudo generar el enlace de inscripcion del evento ' . $eventoId . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'mensaje' => 'No fue posible generar el enlace.']);
    }
    exit;
}

/* Enlace publico de escaneo QR (cengicursos/escanear_evento.php): genera el token de
   forma perezosa la primera vez que se pide (no al crear el evento), para que eventos
   ya existentes no requieran backfill. Requiere permiso de gestion, igual que
   enviar_gafetes_evento.php: se revisa aqui mismo (no con cengi_require_*) porque este
   es un endpoint JSON, no una pagina con redirect. */
if (($_GET['accion'] ?? '') === 'enlace_escaneo') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$puedeGestionar) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'mensaje' => 'No tienes permiso para generar este enlace.']);
        exit;
    }
    $eventoId = (int) ($_GET['evento_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, nombre, token_escaneo FROM eventos WHERE id = ?");
    $stmt->execute([$eventoId]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evento) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'El evento no existe.']);
        exit;
    }
    $token = $evento['token_escaneo'];
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        $stmtActualizar = $db->prepare("UPDATE eventos SET token_escaneo = ? WHERE id = ?");
        $stmtActualizar->execute([$token, $eventoId]);
    }
    echo json_encode([
        'ok' => true,
        'evento_id' => (int) $evento['id'],
        'evento_nombre' => $evento['nombre'],
        'token' => $token,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['accion'] ?? '') === 'exportar_participantes') {
    if (!$puedeGestionar) {
        http_response_code(403);
        exit('No tienes permiso para exportar este listado.');
    }

    $eventoId = (int) ($_GET['evento_id'] ?? 0);
    if ($eventoId <= 0) {
        http_response_code(400);
        exit('Evento no válido.');
    }

    $stmtEvento = $db->prepare('SELECT id, nombre, modalidad_pago FROM eventos WHERE id = ?');
    $stmtEvento->execute([$eventoId]);
    $evento = $stmtEvento->fetch(PDO::FETCH_ASSOC);
    if (!$evento) {
        http_response_code(404);
        exit('El evento no existe.');
    }

    $stmt = $db->prepare("
        SELECT ep.id, ep.nombre_invitado AS nombre, ep.cui_invitado AS cui,
               COALESCE(NULLIF(p.correo_participantes, ''), NULLIF(ep.correo_invitado, '')) AS correo,
               COALESCE(ip.nombre_ingenios, ie.nombre_ingenios, NULLIF(ep.institucion_invitado, ''), 'Invitado externo') AS ingenio,
               ep.telefono_invitado AS telefono, ep.pago_boleta, ep.pago_banco, ep.pago_fecha, ep.pago_monto,
               ep.codigo_qr, ep.pagado, ep.ingreso_en
        FROM evento_participantes ep
        LEFT JOIN participantes p ON p.id = ep.participante_id
        LEFT JOIN ingenios ip ON ip.id = p.ingenio_id
        LEFT JOIN ingenios ie ON ie.id = ep.ingenio_id
        WHERE ep.evento_id = ?
        ORDER BY ep.nombre_invitado, ep.id
    ");
    $stmt->execute([$eventoId]);
    $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eventoPagado = cengi_evt_modalidad_evento($db, $eventoId) === 'Pagado';
    $encabezados = ['Participante', 'CUI', 'Ingenio', 'Correo', 'Teléfono', 'Código QR', 'Pago', 'Boleta', 'Banco', 'Fecha del pago', 'Monto pagado', 'Ingreso'];
    $filas = [];
    foreach ($participantes as $participante) {
        $filas[] = [
            $participante['nombre'] ?? '',
            $participante['cui'] ?? '',
            $participante['ingenio'] ?? 'Invitado externo',
            $participante['correo'] ?? '',
            $participante['telefono'] ?? '',
            $participante['codigo_qr'] ?? '',
            $eventoPagado ? ((int) ($participante['pagado'] ?? 0) ? 'Pagado' : 'No pagado') : 'No aplica',
            $participante['pago_boleta'] ?? '',
            $participante['pago_banco'] ?? '',
            $participante['pago_fecha'] ?? '',
            $participante['pago_monto'] ?? '',
            $participante['ingreso_en'] ?? 'Sin ingreso',
        ];
    }

    cengi_export_enviar_excel(
        $encabezados,
        $filas,
        'Listado de participantes - ' . $evento['nombre'],
        'participantes-evento-' . $eventoId,
        [32, 18, 26, 28, 22, 18, 18]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    if (($accion === 'crear_evento' || $accion === 'guardar_evento') && $puedeGestionar) {
        // Nuevo evento y "Editar evento" (prototipo SIGEC v29, ppEvGuardarEvento).
        $eventoEditarId = (int) ($_POST['evento_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo = trim((string) ($_POST['tipo'] ?? 'Capacitación'));
        $modalidadPago = cengi_evento_modalidad_pago($_POST['modalidad_pago'] ?? 'Gratuito');
        $costo = $modalidadPago === 'Pagado' ? max(0, (float) ($_POST['costo'] ?? 0)) : 0;
        $fecha = trim((string) ($_POST['fecha'] ?? ''));
        $hora = mb_substr(trim((string) ($_POST['hora'] ?? '')), 0, 40, 'UTF-8');
        $lugar = mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 255, 'UTF-8');
        $cupo = max(0, (int) ($_POST['cupo'] ?? 0));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? strtoupper($_POST['color']) : '#2F6B12';
        $estado = in_array($_POST['estado'] ?? '', CENGI_EVT_ESTADOS, true) ? $_POST['estado'] : 'Planificado';
        $enColaboracion = !empty($_POST['en_colaboracion']) ? 1 : 0;
        $colabModalidad = isset(CENGI_EVT_COLAB_MODALIDADES[(int) ($_POST['colab_modalidad'] ?? 0)]) ? (int) $_POST['colab_modalidad'] : 2;
        $colabFinancia = isset(CENGI_EVT_COLAB_FINANCIA[$_POST['colab_financia'] ?? '']) ? $_POST['colab_financia'] : 'empresa';

        // Empresas aliadas: filas del formulario (aliado_id vacio = nueva), sin nombre se ignoran.
        $aliadosForm = [];
        foreach ((array) ($_POST['aliado_nombre'] ?? []) as $k => $aliadoNombre) {
            $aliadoNombre = mb_substr(trim((string) $aliadoNombre), 0, 255, 'UTF-8');
            if ($aliadoNombre === '') {
                continue;
            }
            $aliadosForm[] = [
                'id' => (int) (($_POST['aliado_id'] ?? [])[$k] ?? 0),
                'nombre' => $aliadoNombre,
                'contacto' => mb_substr(trim((string) (($_POST['aliado_contacto'] ?? [])[$k] ?? '')), 0, 255, 'UTF-8'),
                'correo' => mb_substr(trim((string) (($_POST['aliado_correo'] ?? [])[$k] ?? '')), 0, 255, 'UTF-8'),
            ];
        }

        $errores = [];
        if (mb_strlen($nombre, 'UTF-8') < 3) {
            $errores[] = 'Escribe el nombre del evento.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $errores[] = 'Indica la fecha.';
        }
        if ($lugar === '') {
            $errores[] = 'Indica el lugar.';
        }
        if ($enColaboracion && !$aliadosForm) {
            $errores[] = 'Agrega al menos una empresa aliada o desmarca “Evento en colaboración”.';
        }
        // Si la empresa aliada o CENGICAÑA cubren el costo, el evento es gratuito para los participantes.
        if ($enColaboracion && $colabFinancia !== 'compartido') {
            $modalidadPago = 'Gratuito';
            $costo = 0;
        }
        if ($modalidadPago === 'Pagado' && !($costo > 0)) {
            $errores[] = 'Un evento pagado necesita costo nacional mayor que 0.';
        }

        if ($errores) {
            $mensaje = implode(' ', $errores);
            $mensajeTipo = 'error';
        } else {
            try {
                $db->beginTransaction();
                $valores = [$nombre, $tipo, $modalidadPago, $costo, $fecha, $hora ?: null, $lugar, $cupo > 0 ? $cupo : null, $descripcion !== '' ? $descripcion : null, $color,
                    $enColaboracion, $enColaboracion ? $colabModalidad : null, $enColaboracion ? $colabFinancia : null];
                if ($eventoEditarId > 0) {
                    $stmt = $db->prepare('UPDATE eventos SET nombre = ?, tipo = ?, modalidad_pago = ?, costo = ?, fecha = ?, hora = ?, lugar = ?, cupo = ?, descripcion = ?, color = ?,
                        en_colaboracion = ?, colab_modalidad = ?, colab_financia = ?, estado = ? WHERE id = ?');
                    $stmt->execute(array_merge($valores, [$estado, $eventoEditarId]));
                    $eventoGuardadoId = $eventoEditarId;
                } else {
                    $stmt = $db->prepare("INSERT INTO eventos (nombre, tipo, modalidad_pago, costo, fecha, hora, lugar, cupo, descripcion, color,
                        en_colaboracion, colab_modalidad, colab_financia, estado, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Planificado', ?)");
                    $stmt->execute(array_merge($valores, [cengi_usuario_actual_id()]));
                    $eventoGuardadoId = (int) $db->lastInsertId();
                }

                // Sincroniza las empresas aliadas: actualiza las existentes, agrega las nuevas
                // (cada una con su token de encuesta) y quita las que se eliminaron del formulario.
                $conservar = [];
                $stmtExistentes = $db->prepare('SELECT id FROM evento_aliados WHERE evento_id = ?');
                $stmtExistentes->execute([$eventoGuardadoId]);
                $existentes = array_map('intval', $stmtExistentes->fetchAll(PDO::FETCH_COLUMN));
                $actualizarAliado = $db->prepare('UPDATE evento_aliados SET nombre = ?, contacto = ?, correo = ?, orden = ? WHERE id = ? AND evento_id = ?');
                $insertarAliado = $db->prepare('INSERT INTO evento_aliados (evento_id, nombre, contacto, correo, token, orden) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($enColaboracion ? $aliadosForm : [] as $orden => $a) {
                    if (in_array($a['id'], $existentes, true)) {
                        $actualizarAliado->execute([$a['nombre'], $a['contacto'] ?: null, $a['correo'] ?: null, $orden, $a['id'], $eventoGuardadoId]);
                        $conservar[] = $a['id'];
                    } else {
                        $insertarAliado->execute([$eventoGuardadoId, $a['nombre'], $a['contacto'] ?: null, $a['correo'] ?: null, cengi_evento_token(), $orden]);
                        $conservar[] = (int) $db->lastInsertId();
                    }
                }
                if ($enColaboracion) {
                    $marcadores = $conservar ? implode(',', array_fill(0, count($conservar), '?')) : '0';
                    $db->prepare("DELETE FROM evento_aliados WHERE evento_id = ? AND id NOT IN ({$marcadores})")->execute(array_merge([$eventoGuardadoId], $conservar));
                }
                // Si el evento deja de ser en colaboracion, las empresas y sus respuestas se conservan
                // (ocultas) por si se vuelve a marcar.

                $db->commit();
                $mensaje = $eventoEditarId > 0 ? 'Evento actualizado.' : 'Evento creado — comparte el link de inscripción.';
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('No fue posible guardar el evento: ' . $e->getMessage());
                $mensaje = 'No fue posible guardar el evento.';
                $mensajeTipo = 'error';
            }
        }
    } elseif ($accion === 'registrar_participante' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre_invitado'] ?? ''));
        $cui = trim((string) ($_POST['cui_invitado'] ?? ''));
        $correoInvitado = trim((string) ($_POST['correo_invitado'] ?? ''));
        $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $nombre !== '') {
            // "Pagado" solo tiene sentido si el evento es de modalidad Pagado; para un
            // evento Gratuito se fuerza a 0 aunque el cliente envie el checkbox.
            $modalidadEvento = cengi_evt_modalidad_evento($db, $eventoId);
            $pagado = ($modalidadEvento === 'Pagado' && !empty($_POST['pagado'])) ? 1 : 0;
            $codigo = cengi_evento_generar_codigo_qr($db);
            $stmt = $db->prepare("INSERT INTO evento_participantes (evento_id, participante_id, nombre_invitado, cui_invitado, correo_invitado, ingenio_id, codigo_qr, pagado) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$eventoId, $nombre, $cui, $correoInvitado !== '' ? $correoInvitado : null, $ingenioId > 0 ? $ingenioId : null, $codigo, $pagado]);
            $mensaje = "Participante registrado. Su código QR es {$codigo}.";
        } else {
            $mensaje = 'Escribe el nombre del participante.';
            $mensajeTipo = 'error';
        }
    } elseif ($accion === 'carga_masiva_participantes' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $eventoReabrirId = $eventoId;

        if ($eventoId <= 0) {
            $mensaje = 'Selecciona el evento antes de subir el archivo.';
            $mensajeTipo = 'error';
        } elseif (!isset($_FILES['archivo_masivo']) || !is_uploaded_file($_FILES['archivo_masivo']['tmp_name'])) {
            $mensaje = 'Selecciona un archivo CSV o Excel (.xls) para la carga masiva.';
            $mensajeTipo = 'error';
        } elseif ($_FILES['archivo_masivo']['size'] > CENGI_EVT_CARGA_MASIVA_MAX_BYTES) {
            $mensaje = 'El archivo es demasiado grande (máximo 5 MB).';
            $mensajeTipo = 'error';
        } else {
            $nombreArchivo = $_FILES['archivo_masivo']['name'] ?? '';
            $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));

            if (!in_array($extension, ['csv', 'xls'], true)) {
                $mensaje = 'Solo se permiten archivos CSV o Excel (.xls).';
                $mensajeTipo = 'error';
            } else {
                try {
                    $filas = cengi_evt_carga_masiva_filas($_FILES['archivo_masivo']['tmp_name'], $extension);
                } catch (Throwable $e) {
                    $filas = null;
                    $mensaje = $e->getMessage();
                    $mensajeTipo = 'error';
                }

                if ($filas !== null) {
                    if (count($filas) <= 1) {
                        $mensaje = 'El archivo no contiene datos para importar.';
                        $mensajeTipo = 'error';
                    } else {
                        $filasDatos = array_slice($filas, 1, CENGI_EVT_CARGA_MASIVA_MAX_FILAS);
                        $registrados = 0;

                        // Resolucion del nombre del ingenio contra la tabla ingenios, mismo
                        // patron (cache + TRIM) que cengi_carga_inscripcion en carga_inscripcion.php.
                        $stmtBuscarIngenio = $db->prepare("SELECT id FROM ingenios WHERE TRIM(nombre_ingenios) = TRIM(?) LIMIT 1");
                        $cacheIngenios = [];

                        foreach ($filasDatos as $indice => $fila) {
                            $lineaReal = $indice + 2;
                            $nombreFila = trim((string) ($fila[0] ?? ''));
                            $cuiFila = trim((string) ($fila[1] ?? ''));
                            $correoFila = trim((string) ($fila[2] ?? ''));
                            $ingenioFila = trim((string) ($fila[3] ?? ''));

                            if ($nombreFila === '' && $cuiFila === '' && $correoFila === '' && $ingenioFila === '') {
                                continue;
                            }

                            if ($nombreFila === '') {
                                $avisos[] = "Línea {$lineaReal}: se omitió porque falta el nombre.";
                                continue;
                            }

                            $correoValido = null;
                            if ($correoFila !== '') {
                                if (filter_var($correoFila, FILTER_VALIDATE_EMAIL)) {
                                    $correoValido = $correoFila;
                                } else {
                                    $avisos[] = "Línea {$lineaReal}: el correo \"{$correoFila}\" no es válido; se registró a {$nombreFila} sin correo.";
                                }
                            }

                            $ingenioIdFila = null;
                            if ($ingenioFila !== '') {
                                $claveIngenio = mb_strtolower($ingenioFila);
                                if (array_key_exists($claveIngenio, $cacheIngenios)) {
                                    $ingenioIdFila = $cacheIngenios[$claveIngenio];
                                } else {
                                    $stmtBuscarIngenio->execute([$ingenioFila]);
                                    $encontrado = $stmtBuscarIngenio->fetchColumn();
                                    $ingenioIdFila = $encontrado !== false ? (int) $encontrado : null;
                                    $cacheIngenios[$claveIngenio] = $ingenioIdFila;
                                }
                                if ($ingenioIdFila === null) {
                                    $avisos[] = "Línea {$lineaReal}: el ingenio \"{$ingenioFila}\" no coincide con ningún ingenio registrado; se registró a {$nombreFila} sin ingenio.";
                                }
                            }

                            $codigo = cengi_evento_generar_codigo_qr($db);
                            $stmt = $db->prepare("INSERT INTO evento_participantes (evento_id, participante_id, nombre_invitado, cui_invitado, correo_invitado, ingenio_id, codigo_qr) VALUES (?, NULL, ?, ?, ?, ?, ?)");
                            $stmt->execute([$eventoId, $nombreFila, $cuiFila, $correoValido, $ingenioIdFila, $codigo]);
                            $registrados++;
                        }

                        if ($registrados > 0) {
                            $mensaje = "Carga masiva procesada: {$registrados} participante(s) registrado(s).";
                            $mensajeTipo = 'success';
                        } else {
                            $mensaje = 'No se registró ningún participante. Revisa los avisos.';
                            $mensajeTipo = 'error';
                        }
                    }
                }
            }
        }
    } elseif ($accion === 'marcar_ingreso' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteEventoId = (int) ($_POST['evento_participante_id'] ?? 0);
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $participanteEventoId > 0) {
            $stmt = $db->prepare("UPDATE evento_participantes SET ingreso_en = NOW() WHERE id = ? AND evento_id = ? AND ingreso_en IS NULL");
            $stmt->execute([$participanteEventoId, $eventoId]);
            $mensaje = 'Ingreso registrado correctamente.';
        }
    } elseif ($accion === 'editar_participante' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteEventoId = (int) ($_POST['evento_participante_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre_invitado'] ?? ''));
        $cui = trim((string) ($_POST['cui_invitado'] ?? ''));
        $correoInvitado = trim((string) ($_POST['correo_invitado'] ?? ''));
        $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $participanteEventoId > 0 && $nombre !== '') {
            // "Pagado" solo aplica a eventos de modalidad Pagado; en un evento Gratuito se
            // fuerza a 0 aunque el cliente envie el checkbox.
            $modalidadEvento = cengi_evt_modalidad_evento($db, $eventoId);
            $pagado = ($modalidadEvento === 'Pagado' && !empty($_POST['pagado'])) ? 1 : 0;
            // Mismo criterio de scoping que marcar_ingreso: el "AND evento_id = ?" evita
            // editar un evento_participante_id que en realidad pertenece a otro evento
            // (no se confia en que el id recibido del cliente ya este acotado al evento).
            $stmt = $db->prepare("UPDATE evento_participantes SET nombre_invitado = ?, cui_invitado = ?, correo_invitado = ?, ingenio_id = ?, pagado = ? WHERE id = ? AND evento_id = ?");
            $stmt->execute([$nombre, $cui, $correoInvitado !== '' ? $correoInvitado : null, $ingenioId > 0 ? $ingenioId : null, $pagado, $participanteEventoId, $eventoId]);
            $mensaje = 'Participante actualizado correctamente.';
        } else {
            $mensaje = 'Escribe el nombre del participante.';
            $mensajeTipo = 'error';
        }
    } elseif ($accion === 'marcar_pago' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteEventoId = (int) ($_POST['evento_participante_id'] ?? 0);
        $pagado = !empty($_POST['pagado']) ? 1 : 0;
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $participanteEventoId > 0) {
            // Solo se alterna el estado de pago en eventos de modalidad Pagado; para uno
            // Gratuito la accion no tiene efecto (el control del modal va deshabilitado).
            if (cengi_evt_modalidad_evento($db, $eventoId) === 'Pagado') {
                // Mismo scoping "AND evento_id = ?" que marcar_ingreso/editar_participante.
                $stmt = $db->prepare("UPDATE evento_participantes SET pagado = ? WHERE id = ? AND evento_id = ?");
                $stmt->execute([$pagado, $participanteEventoId, $eventoId]);
                $mensaje = $pagado ? 'Participante marcado como pagado.' : 'Participante marcado como no pagado.';
            } else {
                $mensaje = 'El evento es gratuito: no se registra estado de pago.';
                $mensajeTipo = 'error';
            }
        }
    } elseif ($accion === 'eliminar_participante' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteEventoId = (int) ($_POST['evento_participante_id'] ?? 0);
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $participanteEventoId > 0) {
            try {
                // Borrado fisico del participante del evento junto con sus filas hijas de
                // FK RESTRICT (diplomas.evento_participante_id, declarada sin ON DELETE)
                // dentro de una transaccion, mismo criterio que eliminar_cursos.php y la
                // accion eliminar_evento: si tuviera un diploma emitido se borra aqui en
                // cascada (no se bloquea), para que "quitar al participante" sea siempre
                // posible. El scoping "AND evento_id = ?" es el mismo que usan
                // marcar_ingreso / editar_participante.
                $db->beginTransaction();

                $stmtDiplomas = $db->prepare("
                    DELETE FROM diplomas
                    WHERE evento_participante_id = (
                        SELECT id FROM evento_participantes WHERE id = ? AND evento_id = ?
                    )
                ");
                $stmtDiplomas->execute([$participanteEventoId, $eventoId]);

                $stmtParticipante = $db->prepare("DELETE FROM evento_participantes WHERE id = ? AND evento_id = ?");
                $stmtParticipante->execute([$participanteEventoId, $eventoId]);

                $db->commit();

                if ($stmtParticipante->rowCount() > 0) {
                    $mensaje = 'Participante eliminado del evento.';
                } else {
                    $mensaje = 'El participante ya no existe en este evento.';
                    $mensajeTipo = 'error';
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('No fue posible eliminar el participante ' . $participanteEventoId . ' del evento ' . $eventoId . ': ' . $e->getMessage());
                if (stripos($e->getMessage(), 'foreign key') !== false) {
                    $mensaje = 'El participante tiene registros asociados que impiden eliminarlo.';
                } else {
                    $mensaje = 'No fue posible eliminar al participante.';
                }
                $mensajeTipo = 'error';
            }
        }
    } elseif ($accion === 'eliminar_evento' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        if ($eventoId <= 0) {
            $mensaje = 'No se indicó el evento a eliminar.';
            $mensajeTipo = 'error';
        } else {
            try {
                // Borrado fisico del evento con todas sus filas dependientes dentro de una
                // transaccion, mismo patron que eliminar_cursos.php: primero se borran a
                // mano las filas cuya FK a evento_participantes es RESTRICT
                // (diplomas.evento_participante_id, declarada sin ON DELETE), luego los
                // evento_participantes y por ultimo la fila de eventos. La FK
                // evento_participantes.evento_id ya es ON DELETE CASCADE, pero se borra
                // explicitamente para no depender de ese cascade tras limpiar diplomas.
                $db->beginTransaction();

                $stmtDiplomas = $db->prepare("
                    DELETE FROM diplomas
                    WHERE evento_participante_id IN (
                        SELECT id FROM evento_participantes WHERE evento_id = ?
                    )
                ");
                $stmtDiplomas->execute([$eventoId]);

                $stmtParticipantes = $db->prepare("DELETE FROM evento_participantes WHERE evento_id = ?");
                $stmtParticipantes->execute([$eventoId]);

                $stmtEvento = $db->prepare("DELETE FROM eventos WHERE id = ?");
                $stmtEvento->execute([$eventoId]);

                $db->commit();

                if ($stmtEvento->rowCount() > 0) {
                    $mensaje = 'Evento eliminado correctamente.';
                } else {
                    $mensaje = 'El evento ya no existe.';
                    $mensajeTipo = 'error';
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('No fue posible eliminar el evento ' . $eventoId . ': ' . $e->getMessage());
                if (stripos($e->getMessage(), 'foreign key') !== false) {
                    $mensaje = 'El evento tiene registros asociados que impiden eliminarlo.';
                } else {
                    $mensaje = 'No fue posible eliminar el evento.';
                }
                $mensajeTipo = 'error';
            }
        }
    }
}

$eventos = $db->query("
    SELECT e.*,
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id) AS registrados,
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL) AS ingresos,
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.pagado = 1) AS pagados,
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.pagado = 0 AND ep.recibo_pago IS NOT NULL AND ep.recibo_pago <> '') AS por_validar
    FROM eventos e ORDER BY e.fecha DESC, e.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Datos para "Editar evento" (se pasan al JS de la pagina) y empresas aliadas por evento.
$aliadosPorEvento = [];
foreach ($db->query('SELECT id, evento_id, nombre, contacto, correo FROM evento_aliados ORDER BY evento_id, orden, id')->fetchAll(PDO::FETCH_ASSOC) as $aliadoFila) {
    $aliadosPorEvento[(int) $aliadoFila['evento_id']][] = ['id' => (int) $aliadoFila['id'], 'nombre' => $aliadoFila['nombre'], 'contacto' => (string) $aliadoFila['contacto'], 'correo' => (string) $aliadoFila['correo']];
}
$eventosEdicion = [];
foreach ($eventos as $eventoFila) {
    $eventosEdicion[(int) $eventoFila['id']] = [
        'id' => (int) $eventoFila['id'], 'nombre' => $eventoFila['nombre'], 'tipo' => $eventoFila['tipo'], 'fecha' => (string) $eventoFila['fecha'],
        'hora' => (string) $eventoFila['hora'], 'lugar' => (string) $eventoFila['lugar'], 'cupo' => $eventoFila['cupo'] !== null ? (int) $eventoFila['cupo'] : '',
        'modalidad_pago' => $eventoFila['modalidad_pago'], 'costo' => (float) $eventoFila['costo'], 'descripcion' => (string) $eventoFila['descripcion'],
        'color' => (string) ($eventoFila['color'] ?: '#2F6B12'), 'estado' => $eventoFila['estado'], 'registrados' => (int) $eventoFila['registrados'],
        'en_colaboracion' => (int) $eventoFila['en_colaboracion'], 'colab_modalidad' => (int) ($eventoFila['colab_modalidad'] ?: 2),
        'colab_financia' => (string) ($eventoFila['colab_financia'] ?: 'empresa'), 'aliados' => $aliadosPorEvento[(int) $eventoFila['id']] ?? [],
    ];
}

// Catalogo de ingenios para el <select> "Ingenio" del formulario de registro
// individual de participantes (opcion vacia = "Invitado externo").
$ingeniosLista = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC);

$estadisticasEventos = $db->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN modalidad_pago = 'Pagado' THEN 1 ELSE 0 END) AS pagados,
      SUM(CASE WHEN modalidad_pago = 'Pagado' THEN 0 ELSE 1 END) AS gratuitos
    FROM eventos
")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'pagados' => 0, 'gratuitos' => 0];
$totalEventos = (int) ($estadisticasEventos['total'] ?? 0);
$eventosPagados = (int) ($estadisticasEventos['pagados'] ?? 0);
$eventosGratuitos = (int) ($estadisticasEventos['gratuitos'] ?? 0);
$porcentajeGratuitos = $totalEventos > 0 ? (int) round(($eventosGratuitos / $totalEventos) * 100) : 0;

// Resumen de cobros de eventos pagados (KPIs "Comprobantes por validar" y "Cobrado en eventos").
// Se deriva de evento_participantes.pagado / recibo_pago y eventos.costo; el esquema no guarda
// montos por participante, asi que el importe se calcula como costo del evento x participantes.
$resumenCobros = $db->query("
    SELECT
      COALESCE(SUM(CASE WHEN ep.pagado = 0 AND ep.recibo_pago IS NOT NULL AND ep.recibo_pago <> '' THEN 1 ELSE 0 END), 0) AS por_validar,
      COALESCE(SUM(CASE WHEN ep.pagado = 1 THEN e.costo ELSE 0 END), 0) AS cobrado,
      COALESCE(SUM(CASE WHEN ep.pagado = 0 THEN e.costo ELSE 0 END), 0) AS pendiente
    FROM evento_participantes ep
    INNER JOIN eventos e ON e.id = ep.evento_id
    WHERE e.modalidad_pago = 'Pagado'
")->fetch(PDO::FETCH_ASSOC) ?: ['por_validar' => 0, 'cobrado' => 0, 'pendiente' => 0];
$comprobantesPorValidar = (int) $resumenCobros['por_validar'];
$cobradoEventos = (float) $resumenCobros['cobrado'];
$pendienteEventos = (float) $resumenCobros['pendiente'];
$eventosProximos = 0;
$participantesConQr = 0;
$ingresosRegistrados = 0;
foreach ($eventos as $eventoFila) {
    if (!in_array($eventoFila['estado'], ['Finalizado', 'Cancelado'], true)) {
        $eventosProximos++;
    }
    $participantesConQr += (int) $eventoFila['registrados'];
    $ingresosRegistrados += (int) $eventoFila['ingresos'];
}

/* Fecha corta como en el prototipo: "31 jul 2026". */
function cengi_evt_fecha_corta($fecha)
{
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $ts = $fecha ? strtotime((string) $fecha) : false;
    if ($ts === false) {
        return '—';
    }
    return date('d', $ts) . ' ' . $meses[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/* Icono SVG de trazo (mismos paths del prototipo) para los botones de accion. */
function cengi_evt_icono($paths)
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $paths . '</svg>';
}

function cengi_evt_fmt_q($valor)
{
    return 'Q' . number_format((float) $valor, 0, '.', ',');
}
?>
<html lang="es">
<?php include('head.php'); ?>
<style>
/* Control QR de eventos: estilos de la vista del prototipo SIGEC v29 (aviso, KPIs con detalle,
   barra de filtros, tabla con botones de icono y resumen del modal de participantes). Van aqui
   porque dependen solo de esta pagina; las variables (--cengi-*) vienen de css/proyecto.css. */
.cengi-eventos-qr-page .cengi-ev-intro { margin-bottom: 16px; }
.cengi-eventos-qr-page .cengi-ev-intro > svg { width: 15px; height: 15px; flex: none; margin-top: 1px; }
.cengi-eventos-qr-page .cengi-kpi-icon svg { width: 16px; height: 16px; }
.cengi-eventos-qr-page .cengi-kpi-delta { margin-top: 8px; font-size: 11px; font-weight: 500; color: #4B5A45; }

.cengi-eventos-qr-page .cengi-ev-section {
    margin-bottom: 18px;
    border: 1px solid var(--cengi-border);
    border-radius: var(--cengi-radius);
    background: var(--cengi-surface);
    box-shadow: var(--cengi-shadow);
    overflow: hidden;
}
.cengi-eventos-qr-page .cengi-ev-section-body { padding: 14px 18px; }
.cengi-eventos-qr-page .cengi-ev-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.cengi-eventos-qr-page .cengi-ev-toolbar-group { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.cengi-eventos-qr-page .cengi-ev-new svg { width: 14px; height: 14px; stroke-width: 2.2; vertical-align: -2px; }
.cengi-eventos-qr-page .cengi-ev-search,
.cengi-eventos-qr-page .cengi-ev-filter {
    width: auto;
    height: auto;
    padding: 8px 10px;
    border: 1px solid var(--cengi-border);
    border-radius: 8px;
    box-shadow: none;
    background-color: #fff;
    color: var(--cengi-ink);
    font-size: 12.5px;
}
.cengi-eventos-qr-page .cengi-ev-search {
    min-width: 300px;
    padding-left: 34px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23A3AAA0' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='M21 21l-4.35-4.35'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 10px center;
}
.cengi-eventos-qr-page .cengi-participants-toolbar .cengi-ev-filter { flex: 0 0 auto; }

.cengi-eventos-qr-page .cengi-events-table { font-size: 12.5px; }
.cengi-eventos-qr-page .cengi-events-table > thead > tr > th {
    padding: 10px 12px;
    border-bottom: 1px solid var(--cengi-border);
    background: #FAFBF8;
    color: #4B5A45;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
    white-space: nowrap;
}
.cengi-eventos-qr-page .cengi-events-table > tbody > tr > td { padding: 11px 12px; border-top: 0; border-bottom: 1px solid var(--cengi-border); }
.cengi-eventos-qr-page .cengi-events-table > tbody > tr.cengi-ev-row:hover { background: #FAFBF6; }
.cengi-eventos-qr-page .cengi-ev-name { min-width: 150px; font-weight: 700; }
.cengi-eventos-qr-page .cengi-ev-link {
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    text-decoration: underline;
    text-decoration-color: #CFD5CB;
    text-underline-offset: 3px;
    cursor: pointer;
}
.cengi-eventos-qr-page .cengi-ev-link:hover { color: #3E7A12; text-decoration-color: var(--cengi-primary); }
.cengi-eventos-qr-page .cengi-ev-sub { margin-top: 2px; color: #4B5A45; font-size: 10.5px; font-weight: 400; line-height: 1.35; }
.cengi-eventos-qr-page .cengi-ev-num { white-space: nowrap; font-variant-numeric: tabular-nums; }
.cengi-eventos-qr-page .cengi-ev-asistencia { font-size: 12px; }
.cengi-eventos-qr-page .cengi-ev-progress { width: 90px; height: 6px; margin-top: 4px; }
.cengi-eventos-qr-page .cengi-ev-empty { padding: 24px !important; color: #4B5A45; text-align: center; }
.cengi-eventos-qr-page .cengi-ev-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 3px;
    padding: 2px 7px;
    border-radius: 100px;
    background: #FFE9D9;
    color: #B34E00;
    font-size: 10px;
    font-weight: 700;
}
.cengi-eventos-qr-page .cengi-status-badge.is-waiting { border-color: #F6D3B8; background: #FFE9D9; color: #B34E00; }

.cengi-eventos-qr-page .cengi-ev-acc { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 5px; width: 106px; }
.cengi-eventos-qr-page .cengi-ev-acc-form { display: contents; }
.cengi-eventos-qr-page .cengi-ev-acc-col { display: flex; flex-direction: column; gap: 5px; }
.cengi-eventos-qr-page .cengi-ev-icon-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 31px;
    height: 31px;
    min-width: 31px;
    padding: 0;
    border: 1px solid var(--cengi-border);
    border-radius: 7px;
    background: #fff;
    color: #4B5A45;
    cursor: pointer;
}
.cengi-eventos-qr-page .cengi-ev-icon-btn:hover,
.cengi-eventos-qr-page .cengi-ev-icon-btn:focus { background: #F2F4EF; color: var(--cengi-ink); }
.cengi-eventos-qr-page .cengi-ev-icon-btn svg { width: 14px; height: 14px; }
.cengi-eventos-qr-page .cengi-ev-icon-btn.is-danger { color: #B23223; }
.cengi-eventos-qr-page .cengi-ev-icon-btn.is-solid { border-color: #2F6B12; background: #2F6B12; color: #fff; }
.cengi-eventos-qr-page .cengi-ev-icon-btn.is-solid:hover,
.cengi-eventos-qr-page .cengi-ev-icon-btn.is-solid:focus { background: #3E7A12; color: #fff; }

/* Modal de participantes */
.cengi-eventos-qr-page .cengi-ev-strip { display: flex; flex-wrap: wrap; gap: 10px; padding: 0 22px 12px; }
.cengi-eventos-qr-page .cengi-ev-strip:empty { display: none; }
.cengi-eventos-qr-page .cengi-ev-mini-stat {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 130px;
    padding: 10px 16px;
    border: 1px solid var(--cengi-border);
    border-radius: 9px;
    background: #fff;
}
.cengi-eventos-qr-page .cengi-ev-mini-stat .ms-label { color: #4B5A45; font-size: 11.5px; }
.cengi-eventos-qr-page .cengi-ev-mini-stat .ms-val { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 700; color: var(--cengi-ink); }
.cengi-eventos-qr-page .cengi-event-participants-table .cengi-avatar-sm {
    width: 28px;
    height: 28px;
    background: #CED2D5;
    color: #4B5A45;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 10.5px;
}
.cengi-eventos-qr-page .cengi-event-participants-table .cengi-person-cell strong { font-size: 12.5px; font-weight: 600; }
.cengi-eventos-qr-page .cengi-event-participants-table .cengi-person-cell small { font-size: 10.5px; line-height: 1.35; }
.cengi-eventos-qr-page .cengi-event-participants-table > thead > tr > th { font-size: 10.5px; letter-spacing: .05em; text-transform: uppercase; color: #4B5A45; white-space: nowrap; }
.cengi-eventos-qr-page .cengi-td-ingenio { font-size: 12px; }
.cengi-eventos-qr-page .cengi-td-pago { min-width: 160px; }
.cengi-eventos-qr-page .cengi-mini-qr { cursor: pointer; }
.cengi-eventos-qr-page .cengi-ev-cell-action { margin-top: 5px; }
.cengi-eventos-qr-page .cengi-ev-cell-action .cengi-inline-entry-form { margin-top: 0; }
.cengi-eventos-qr-page .cengi-ev-linkbtn {
    padding: 0;
    border: 0;
    background: none;
    color: #3E7A12;
    font-size: 11.5px;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
    cursor: pointer;
}
.cengi-eventos-qr-page .cengi-ev-linkbtn:hover { color: var(--cengi-primary-deep); }

@media (max-width: 767px) {
    .cengi-eventos-qr-page .cengi-ev-search { min-width: 0; flex: 1 1 200px; }
    .cengi-eventos-qr-page .cengi-ev-toolbar-group { flex: 1 1 100%; }
    .cengi-eventos-qr-page .cengi-td-pago { order: 3; flex: 1 1 auto; min-width: 0; padding-left: 39px !important; }
    .cengi-eventos-qr-page .cengi-ev-acc-col { flex-direction: row; }
    .cengi-eventos-qr-page .cengi-ev-strip { padding: 0 14px 12px; }
}
/* Pestañas, Editar evento, Difusión, Encuestas e informe (prototipo SIGEC v29: .tabs, .ee-*, .df-*, .inf-*). */
.cengi-eventos-qr-page .cengi-ev-tabs { display: flex; gap: 4px; padding: 2px 18px 0; border-bottom: 1px solid var(--cengi-border); }
.cengi-eventos-qr-page .cengi-ev-tab { margin-right: 18px; padding: 11px 4px; border: 0; border-bottom: 2px solid transparent; background: none; color: #4B5A45; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.cengi-eventos-qr-page .cengi-ev-tab.active { color: #3E7A12; border-bottom-color: var(--cengi-primary); }
.cengi-eventos-qr-page .cengi-ev-section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--cengi-border); }
.cengi-eventos-qr-page .cengi-ev-section-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--cengi-ink); }
.cengi-eventos-qr-page .cengi-ev-hint { margin-top: 3px; color: #4B5A45; font-size: 11.5px; }
.cengi-eventos-qr-page .cengi-ev-opt { color: #8B9488; font-weight: 400; }
.cengi-eventos-qr-page .cengi-ev-check { display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; }
.cengi-eventos-qr-page .cengi-ev-check input { margin: 0; }
.cengi-eventos-qr-page .cengi-ev-color { height: 38px; padding: 3px; }
.cengi-eventos-qr-page .cengi-ev-form-msg { display: none; margin-top: 14px; padding: 10px 13px; border-radius: 9px; font-size: 11.5px; line-height: 1.5; }
.cengi-eventos-qr-page .cengi-ev-form-msg.is-err { display: block; border: 1px solid #F3C6C0; background: #FBE3E0; color: #8E2419; }
.cengi-eventos-qr-page .cengi-ev-form-msg.is-warn { border: 1px solid #F4E5AC; background: #FFF6DA; color: #7A5D00; }
.cengi-eventos-qr-page .cengi-ev-modal-xl { width: 1100px; max-width: calc(100% - 20px); }
.cengi-eventos-qr-page .cengi-ev-modal-informe { width: 900px; max-width: calc(100% - 20px); }
/* Con la barra lateral visible, los modales anchos se centran en el espacio a su derecha. */
@media (min-width: 1025px) {
    .cengi-eventos-qr-page .cengi-ev-modal-xl { --ancho: min(1100px, calc(100vw - var(--cengi-sidebar-width) - 48px)); }
    .cengi-eventos-qr-page .cengi-ev-modal-informe { --ancho: min(900px, calc(100vw - var(--cengi-sidebar-width) - 48px)); }
    .cengi-eventos-qr-page .cengi-ev-modal-xl,
    .cengi-eventos-qr-page .cengi-ev-modal-informe { width: var(--ancho); max-width: none; margin-left: calc(var(--cengi-sidebar-width) + (100vw - var(--cengi-sidebar-width) - var(--ancho)) / 2); }
}
.cengi-eventos-qr-page .cengi-ev-chip-ok { padding: 4px 9px; border-radius: 100px; background: #EAF6DD; color: #3E7A12; font-size: 11px; font-weight: 600; white-space: nowrap; }
.cengi-eventos-qr-page .cengi-ev-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 100px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.cengi-eventos-qr-page .cengi-ev-badge i { width: 6px; height: 6px; border-radius: 50%; }
.cengi-eventos-qr-page .cengi-ev-badge.b-activo { background: #EAF6DD; color: #3E7A12; } .cengi-eventos-qr-page .cengi-ev-badge.b-activo i { background: #73BC25; }
.cengi-eventos-qr-page .cengi-ev-badge.b-planificacion { background: #FFF6DA; color: #8A6600; } .cengi-eventos-qr-page .cengi-ev-badge.b-planificacion i { background: #FFCC00; }
.cengi-eventos-qr-page .cengi-ev-badge.b-espera { background: #FFE9D9; color: #B34E00; } .cengi-eventos-qr-page .cengi-ev-badge.b-espera i { background: #FF6B00; }
.cengi-eventos-qr-page .evf-colab { display: grid; grid-template-columns: 1.3fr 1fr 1.2fr auto; gap: 6px; margin-bottom: 6px; align-items: center; }
.cengi-eventos-qr-page .ee-card { margin-bottom: 14px; padding: 14px 16px; border: 1px solid var(--cengi-border); border-radius: 12px; background: #fff; }
.cengi-eventos-qr-page .ee-h { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px; font-size: 13px; }
.cengi-eventos-qr-page .ee-fila { display: flex; justify-content: space-between; gap: 14px; padding: 10px 0; border-top: 1px solid var(--cengi-border); font-size: 12.5px; }
.cengi-eventos-qr-page .ee-acc { display: flex; flex: none; flex-direction: column; align-items: flex-end; gap: 6px; }
.cengi-eventos-qr-page .ee-link { display: flex; gap: 6px; margin-top: 6px; }
.cengi-eventos-qr-page .ee-link input { flex: 1; min-width: 0; padding: 6px 8px; border: 1px solid var(--cengi-border); border-radius: 6px; background: #FAFBF8; font-size: 11px; }
.cengi-eventos-qr-page .ca-acc { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.cengi-eventos-qr-page .ca-sub { margin: 12px 0 4px; color: #4B5A45; font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
.cengi-eventos-qr-page .ca-firma { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 6px; padding: 6px 10px; border: 1px solid #CFE9B3; border-radius: 8px; background: #EAF6DD; font-size: 12px; }
.cengi-eventos-qr-page .pp-doc { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 14px; border: 1.5px dashed var(--cengi-border); border-radius: 9px; font-size: 12px; }
.cengi-eventos-qr-page .pp-doc.over { border-color: var(--cengi-primary); background: #F4FBEC; }
.cengi-eventos-qr-page .pp-doc .nm { font-weight: 600; }
.cengi-eventos-qr-page .pp-doc .meta { color: #4B5A45; font-size: 11px; }
.cengi-eventos-qr-page .pp-doc .acts { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.cengi-eventos-qr-page .df-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
.cengi-eventos-qr-page .df-kpis > div { padding: 9px 12px; border: 1px solid var(--cengi-border); border-radius: 10px; background: #fff; }
.cengi-eventos-qr-page .df-kpis span { display: block; color: #4B5A45; font-size: 10.5px; }
.cengi-eventos-qr-page .df-kpis b { font-size: 17px; }
.cengi-eventos-qr-page .df-2 { display: grid; grid-template-columns: .9fr 1.1fr; align-items: start; gap: 14px; }
.cengi-eventos-qr-page .df-flyer { display: flex; align-items: center; justify-content: center; min-height: 120px; overflow: hidden; border: 1px solid var(--cengi-border); border-radius: 10px; background: #F4F6F1; }
.cengi-eventos-qr-page .df-flyer img { display: block; max-width: 100%; max-height: 260px; object-fit: contain; }
.cengi-eventos-qr-page .df-bit { display: flex; flex-direction: column; margin-left: 78px; border-left: 2px solid var(--cengi-border); }
.cengi-eventos-qr-page .df-bit-f { position: relative; display: flex; align-items: flex-start; gap: 12px; padding: 7px 0 7px 14px; font-size: 12.5px; }
.cengi-eventos-qr-page .df-bit-f::before { content: ""; position: absolute; left: -6px; top: 12px; width: 10px; height: 10px; border: 2px solid #fff; border-radius: 50%; background: var(--cengi-primary); }
.cengi-eventos-qr-page .df-bit-d { position: absolute; left: -74px; top: 8px; width: 56px; color: #4B5A45; font-size: 11px; font-weight: 600; text-align: right; }
.cengi-eventos-qr-page .inf-doc { max-width: 800px; margin: 0 auto; padding: 26px 30px; border: 1px solid #E1E5DD; background: #fff; color: #1C2517; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
.cengi-eventos-qr-page .inf-head { display: flex; justify-content: space-between; gap: 16px; padding-bottom: 12px; border-bottom: 3px solid; }
.cengi-eventos-qr-page .inf-k { color: #5B6459; font-size: 10px; font-weight: 700; letter-spacing: .14em; }
.cengi-eventos-qr-page .inf-t { margin-top: 3px; font-size: 22px; font-weight: 800; }
.cengi-eventos-qr-page .inf-s { margin-top: 3px; color: #5B6459; font-size: 11.5px; }
.cengi-eventos-qr-page .inf-sec { margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #E1E5DD; color: #0F2A1C; font-size: 13px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.cengi-eventos-qr-page .inf-ul { margin: 0; padding-left: 18px; line-height: 1.6; }
.cengi-eventos-qr-page .inf-ul li { margin-bottom: 3px; }
.cengi-eventos-qr-page .inf-kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-top: 12px; }
.cengi-eventos-qr-page .inf-kpi { padding: 8px 10px; border: 1px solid #E1E5DD; border-radius: 8px; }
.cengi-eventos-qr-page .inf-kpi .v { font-size: 18px; font-weight: 800; }
.cengi-eventos-qr-page .inf-kpi .l { color: #5B6459; font-size: 10px; }
.cengi-eventos-qr-page .inf-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
.cengi-eventos-qr-page .inf-sub { margin-bottom: 8px; color: #5B6459; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.cengi-eventos-qr-page .inf-dist { display: grid; grid-template-columns: 34px 1fr 24px; align-items: center; gap: 8px; margin-bottom: 5px; font-size: 11.5px; }
.cengi-eventos-qr-page .inf-dist b { text-align: right; }
.cengi-eventos-qr-page .inf-muted { color: #6B7468; font-size: 11px; }
.cengi-eventos-qr-page .inf-tema { margin-bottom: 8px; }
.cengi-eventos-qr-page .inf-tema-h { display: flex; justify-content: space-between; font-size: 12px; }
.cengi-eventos-qr-page .inf-tema-h span { padding: 0 8px; border-radius: 100px; background: #EDF0EA; font-weight: 700; }
.cengi-eventos-qr-page .inf-cita { margin: 3px 0 0 8px; padding-left: 6px; border-left: 2px solid #DDE3D8; color: #3A4236; font-size: 11px; font-style: italic; }
.cengi-eventos-qr-page .inf-grp { margin-bottom: 10px; }
.cengi-eventos-qr-page .inf-grp-h { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 12px; }
.cengi-eventos-qr-page .inf-item { display: flex; align-items: center; gap: 8px; padding: 3px 0; font-size: 11.5px; }
.cengi-eventos-qr-page .inf-item span { flex: 1; }
.cengi-eventos-qr-page .inf-item b { min-width: 26px; text-align: right; }
.cengi-eventos-qr-page .inf-pie { margin-top: 18px; padding-top: 6px; border-top: 1px solid #E1E5DD; color: #8B9488; font-size: 10px; text-align: right; }
.cengi-ev-toast { position: fixed; z-index: 2000; right: 20px; bottom: 20px; max-width: 360px; padding: 11px 16px; border-radius: 10px; background: #1E2A1A; color: #fff; font-size: 13px; box-shadow: 0 8px 24px rgba(0,0,0,.18); opacity: 0; transform: translateY(8px); transition: opacity .2s, transform .2s; }
.cengi-ev-toast.is-visible { opacity: 1; transform: none; }
.cengi-ev-toast.is-error { background: #8E2419; }
@media (max-width: 767px) {
    .cengi-eventos-qr-page .df-2, .cengi-eventos-qr-page .inf-2 { grid-template-columns: 1fr; }
    .cengi-eventos-qr-page .df-kpis { grid-template-columns: 1fr 1fr; }
    .cengi-eventos-qr-page .inf-kpis { grid-template-columns: repeat(2, 1fr); }
    .cengi-eventos-qr-page .evf-colab { grid-template-columns: 1fr auto; }
    .cengi-eventos-qr-page .ee-fila { flex-direction: column; }
    .cengi-eventos-qr-page .ee-acc { align-items: flex-start; }
}

/* ===== Responsive de la pagina ===== */
/* El contenedor general usa width:100% + margen de la barra lateral y se desbordaba
   horizontalmente; con width:auto ocupa solo el espacio a la derecha de la barra. */
.cengi-eventos-qr-page .container { width: auto; }
.cengi-eventos-qr-page .cengi-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.cengi-eventos-qr-page .cengi-ev-tabs { overflow-x: auto; white-space: nowrap; scrollbar-width: none; }
.cengi-eventos-qr-page .cengi-ev-tab { flex: none; }

/* Anchos medianos: los encabezados y la asistencia pueden partirse en dos lineas para que
   la tabla quepa sin desplazamiento horizontal. */
@media (min-width: 940.02px) and (max-width: 1440px) {
    .cengi-eventos-qr-page .cengi-events-table > thead > tr > th { white-space: normal; vertical-align: bottom; }
    .cengi-eventos-qr-page .cengi-events-table .cengi-ev-asistencia { white-space: normal; }
    .cengi-eventos-qr-page .cengi-events-table .cengi-ev-name { min-width: 130px; }
    .cengi-eventos-qr-page .cengi-events-table > tbody > tr > td { padding-left: 9px; padding-right: 9px; }
}

@media (max-width: 1024px) {
    .cengi-eventos-qr-page .cengi-topbar { padding: 8px 18px; }
    .cengi-eventos-qr-page .container { padding: 20px 18px 48px; }
}

@media (max-width: 900px) {
    /* Encabezado: el titulo ya no se aplasta palabra por palabra. */
    .cengi-eventos-qr-page .cengi-topbar { gap: 10px; padding: 8px 14px; }
    .cengi-eventos-qr-page .cengi-topbar-title { font-size: 16px; line-height: 1.2; }
    .cengi-eventos-qr-page .cengi-topbar-sub { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .cengi-eventos-qr-page .cengi-topbar-right .cengi-pill,
    .cengi-eventos-qr-page .cengi-userbox-u1,
    .cengi-eventos-qr-page .cengi-userbox-u2 { display: none; }
    .cengi-eventos-qr-page .container { padding: 14px 12px 40px; }
    .cengi-eventos-qr-page .cengi-ev-intro { font-size: 12px; }

    /* Indicadores en dos columnas */
    .cengi-eventos-qr-page .cengi-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .cengi-eventos-qr-page .cengi-kpi { padding: 12px 12px 14px; }
    .cengi-eventos-qr-page .cengi-kpi-icon { width: 30px; height: 30px; margin-bottom: 8px; }
    .cengi-eventos-qr-page .cengi-kpi-val { font-size: 22px; }
    .cengi-eventos-qr-page .cengi-kpi-label { font-size: 11.5px; line-height: 1.3; }
    .cengi-eventos-qr-page .cengi-kpi-delta { font-size: 10.5px; }

    /* Barra: buscador a todo el ancho; filtro y "Nuevo evento" en la misma fila */
    .cengi-eventos-qr-page .cengi-ev-tabs { padding: 2px 12px 0; }
    .cengi-eventos-qr-page .cengi-ev-tab { margin-right: 12px; }
    .cengi-eventos-qr-page .cengi-ev-section-body { padding: 12px; }
    .cengi-eventos-qr-page .cengi-ev-toolbar-group { display: contents; }
    .cengi-eventos-qr-page .cengi-ev-search { flex: 1 1 100%; min-width: 0; }
    .cengi-eventos-qr-page .cengi-ev-toolbar .cengi-ev-filter { flex: 1 1 auto; min-width: 0; }
    .cengi-eventos-qr-page .cengi-ev-new { flex: 0 0 auto; }
    .cengi-eventos-qr-page .cengi-ev-section-head { flex-direction: column; padding: 12px; }

    /* Modales: ocupan el ancho de la pantalla */
    .cengi-eventos-qr-page .cengi-ev-modal .modal-dialog { width: auto; margin: 8px; }
    .cengi-eventos-qr-page .cengi-ev-modal .modal-body { padding: 14px; }
    .cengi-eventos-qr-page .cengi-ev-modal .modal-footer { display: flex; flex-wrap: wrap; gap: 8px; }
    .cengi-eventos-qr-page .cengi-ev-modal .modal-footer > .btn { flex: 1 1 auto; margin: 0; }
    .cengi-eventos-qr-page .cengi-ev-modal .cengi-form-grid { grid-template-columns: 1fr; }
    .cengi-eventos-qr-page .ee-card { padding: 12px; }
    .cengi-eventos-qr-page .ee-h { flex-wrap: wrap; }
    .cengi-eventos-qr-page .df-bit { margin-left: 64px; }
    .cengi-eventos-qr-page .df-bit-d { left: -62px; width: 48px; }
    .cengi-eventos-qr-page #eiBodyInf { padding: 8px; }
    .cengi-eventos-qr-page .inf-doc { padding: 16px 14px; }
    .cengi-eventos-qr-page .inf-head { flex-direction: column-reverse; gap: 8px; }
    .cengi-eventos-qr-page .inf-head img { align-self: flex-start; height: 34px !important; }
    .cengi-eventos-qr-page .inf-t { font-size: 18px; }
    .cengi-eventos-qr-page .inf-item { flex-wrap: wrap; }

    /* Modal de participantes: resumen compacto y tarjetas sin bordes sobrantes */
    .cengi-eventos-qr-page .cengi-ev-strip { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; padding: 0 12px 10px; }
    .cengi-eventos-qr-page .cengi-ev-mini-stat { min-width: 0; padding: 7px 9px; }
    .cengi-eventos-qr-page .cengi-ev-mini-stat .ms-label { font-size: 10.5px; }
    .cengi-eventos-qr-page .cengi-ev-mini-stat .ms-val { font-size: 15px; }
    .cengi-eventos-qr-page .cengi-event-participants-table > tbody > tr > td { border-top: 0 !important; border-bottom: 0 !important; }
    .cengi-eventos-qr-page .cengi-event-participants-table .cengi-td-qr { border-top: 1px dashed #e4e9e1 !important; }
    .cengi-eventos-qr-page .cengi-event-participants-table .cengi-td-participante { flex: 1 1 calc(100% - 52px); }
}

/* Tablas de eventos y de evaluacion como tarjetas: en telefono/tablet y tambien cuando la
   barra lateral esta visible pero el espacio restante no alcanza para la tabla completa. */
@media (max-width: 940px), (min-width: 1024.02px) and (max-width: 1200px) {
    .cengi-eventos-qr-page .cengi-events-table,
    .cengi-eventos-qr-page .cengi-events-table > tbody { display: block; width: 100%; }
    .cengi-eventos-qr-page .cengi-events-table > thead { display: none; }
    .cengi-eventos-qr-page .cengi-events-table > tbody > tr { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px 12px; padding: 14px; border-bottom: 1px solid var(--cengi-border); }
    .cengi-eventos-qr-page .cengi-events-table > tbody > tr:last-child { border-bottom: 0; }
    .cengi-eventos-qr-page .cengi-events-table > tbody > tr[style*="display: none"] { display: none !important; }
    .cengi-eventos-qr-page .cengi-events-table > tbody > tr > td { display: block; min-width: 0; padding: 0 !important; border: 0 !important; }
    .cengi-eventos-qr-page .cengi-events-table > tbody > tr > td[colspan] { grid-column: 1 / -1; padding: 16px 0 !important; }
    .cengi-eventos-qr-page .cengi-events-table .cengi-ev-name { min-width: 0; font-size: 14px; }
    /* Tarjeta de evento: nombre y estado / fecha y acceso / registrados y pagos / asistencia / acciones */
    .cengi-eventos-qr-page #tablaEventos > tr.cengi-ev-row { grid-template-areas: "nombre estado" "fecha acceso" "reg pagos" "asist asist" "acc acc"; }
    .cengi-eventos-qr-page #tablaEventos .cengi-ev-name { grid-area: nombre; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-estado { grid-area: estado; justify-self: end; align-self: start; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-fecha { grid-area: fecha; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-acceso { grid-area: acceso; justify-self: end; text-align: right; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-dato[data-label="Registrados"] { grid-area: reg; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-dato[data-label="Pagos"] { grid-area: pagos; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-asist { grid-area: asist; }
    .cengi-eventos-qr-page #tablaEventos .ev-td-acc { grid-area: acc; }
    .cengi-eventos-qr-page .cengi-events-table .ev-td-dato::before { content: attr(data-label); display: block; margin-bottom: 3px; color: #4B5A45; font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; }
    .cengi-eventos-qr-page .cengi-events-table .ev-td-asist { grid-column: 1 / -1; }
    .cengi-eventos-qr-page .cengi-events-table .cengi-ev-progress { width: 100%; }
    .cengi-eventos-qr-page .cengi-events-table .ev-td-acc { grid-column: 1 / -1; padding-top: 10px !important; border-top: 1px dashed var(--cengi-border) !important; }
    .cengi-eventos-qr-page .cengi-events-table .cengi-ev-acc { width: auto; justify-content: flex-start; gap: 8px; }
    .cengi-eventos-qr-page .cengi-events-table .cengi-ev-icon-btn { width: 38px; height: 38px; min-width: 38px; }
    .cengi-eventos-qr-page .cengi-eval-table .cengi-ev-name { grid-column: 1 / -1; }
    .cengi-eventos-qr-page .cengi-eval-table .ev-td-acc { display: flex !important; gap: 8px; text-align: left !important; }
    .cengi-eventos-qr-page .cengi-eval-table .ev-td-acc .btn { flex: 1; }

}

@media (max-width: 360px) {
    .cengi-eventos-qr-page .cengi-kpi-grid { grid-template-columns: 1fr; }
}
</style>
<body class="cengi-canvas cengi-eventos-qr-page">
<?php menu_render(); ?>
<div class="container">
    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'warning-sign' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_evt_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($avisos): ?>
        <div class="alert alert-warning">
            <strong>Avisos de la carga masiva:</strong>
            <ul class="mb-0"><?php foreach ($avisos as $a) { echo '<li>' . cengi_evt_html($a) . '</li>'; } ?></ul>
        </div>
    <?php endif; ?>

    <div class="cengi-notice cengi-ev-intro">
        <?php echo cengi_evt_icono('<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>'); ?>
        <div><b>Evento gratuito:</b> al inscribirse se genera el QR de ingreso. <b>Evento pagado:</b> la persona se inscribe y adjunta su comprobante (se abre con “Ver recibo” en <b>Ver participantes</b>) → el equipo <b>marca el pago como recibido</b> y el QR de ingreso queda listo para el gafete y el registro en la entrada.</div>
    </div>

    <div class="cengi-kpi-grid cengi-event-stats" id="evKpis" aria-label="Indicadores de eventos">
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-primary);"></div>
            <div class="cengi-kpi-icon" style="background:#EAF6DD;color:var(--cengi-primary);"><?php echo cengi_evt_icono('<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v5M16 2v5"/>'); ?></div>
            <div class="cengi-kpi-val"><?php echo (int) $eventosProximos; ?></div>
            <div class="cengi-kpi-label">Eventos próximos o en curso</div>
            <div class="cengi-kpi-delta"><?php echo (int) $eventosPagados; ?> pagados · <?php echo (int) $eventosGratuitos; ?> gratuitos</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:<?php echo $comprobantesPorValidar > 0 ? 'var(--cengi-naranja)' : '#CED2D5'; ?>;"></div>
            <div class="cengi-kpi-icon" style="background:<?php echo $comprobantesPorValidar > 0 ? '#FFE9D9' : '#EDEFEA'; ?>;color:<?php echo $comprobantesPorValidar > 0 ? 'var(--cengi-naranja)' : '#5B6459'; ?>;"><?php echo cengi_evt_icono('<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>'); ?></div>
            <div class="cengi-kpi-val"><?php echo (int) $comprobantesPorValidar; ?></div>
            <div class="cengi-kpi-label">Comprobantes por validar</div>
            <div class="cengi-kpi-delta">Depósitos y transferencias</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:#3E7A12;"></div>
            <div class="cengi-kpi-icon" style="background:#EAF6DD;color:#3E7A12;"><?php echo cengi_evt_icono('<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>'); ?></div>
            <div class="cengi-kpi-val"><?php echo cengi_evt_html(cengi_evt_fmt_q($cobradoEventos)); ?></div>
            <div class="cengi-kpi-label">Cobrado en eventos</div>
            <div class="cengi-kpi-delta">Pendiente: <?php echo cengi_evt_html(cengi_evt_fmt_q($pendienteEventos)); ?></div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:#3E7A12;"></div>
            <div class="cengi-kpi-icon" style="background:#EAF6DD;color:#3E7A12;"><?php echo cengi_evt_icono('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM18 18h3v3"/>'); ?></div>
            <div class="cengi-kpi-val"><?php echo (int) $participantesConQr; ?></div>
            <div class="cengi-kpi-label">Participantes con QR</div>
            <div class="cengi-kpi-delta"><?php echo (int) $ingresosRegistrados; ?> ingresos registrados</div>
        </div>
    </div>

    <div class="cengi-ev-section">
        <div class="cengi-ev-tabs" role="tablist">
            <button type="button" class="cengi-ev-tab active" data-evtab="eventos" role="tab">Eventos</button>
            <button type="button" class="cengi-ev-tab" data-evtab="evaluacion" role="tab">Evaluación de eventos</button>
        </div>
        <div class="cengi-ev-section-body">
            <div class="cengi-ev-toolbar">
                <div class="cengi-ev-toolbar-group">
                    <input type="search" class="form-control cengi-ev-search" id="evBuscar" placeholder="Buscar evento por nombre o tipo..." aria-label="Buscar evento">
                    <select class="form-control cengi-ev-filter" id="evEstadoF" aria-label="Estado">
                        <option value="">Todos los estados</option>
                        <option value="Planificado">Planificado</option>
                        <option value="En curso">En curso</option>
                        <option value="Finalizado">Finalizado</option>
                        <option value="Cancelado">Cancelado</option>
                    </select>
                </div>
                <?php if ($puedeGestionar): ?>
                    <button type="button" class="btn btn-primary btn-sm cengi-ev-new" onclick="cengiEvtAbrirEvento()"><?php echo cengi_evt_icono('<path d="M12 5v14M5 12h14"/>'); ?> Nuevo evento</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="cengi-ev-section" id="evPanelEventos">
        <div class="cengi-table-wrap">
            <table class="table cengi-events-table">
                <thead><tr><th>Evento</th><th>Fecha</th><th>Acceso</th><th>Registrados</th><th>Pagos</th><th>Ingresos QR / asistencia</th><th>Estado</th><th></th></tr></thead>
                <tbody id="tablaEventos">
                <?php if (!$eventos): ?><tr><td colspan="8" class="cengi-ev-empty">No hay eventos registrados todavía.</td></tr><?php endif; ?>
                <?php foreach ($eventos as $evt): ?>
                    <?php
                    $registradosEvt = (int) $evt['registrados'];
                    $ingresosEvt = (int) $evt['ingresos'];
                    $pct = $registradosEvt > 0 ? (int) round(($ingresosEvt / $registradosEvt) * 100) : 0;
                    $esPagadoEvt = $evt['modalidad_pago'] === 'Pagado';
                    $aliadosEvt = $evt['en_colaboracion'] ? ($aliadosPorEvento[(int) $evt['id']] ?? []) : [];
                    ?>
                    <tr class="cengi-ev-row" data-estado="<?php echo cengi_evt_html($evt['estado']); ?>" data-buscar="<?php echo cengi_evt_html(mb_strtolower($evt['nombre'] . ' ' . $evt['tipo'] . ' ' . $evt['lugar'], 'UTF-8')); ?>">
                        <td class="cengi-ev-name"><button type="button" class="cengi-ev-link" onclick="cengiEvtAbrirParticipantes(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_html($evt['nombre']); ?></button><div class="cengi-ev-sub"><?php echo cengi_evt_html($evt['tipo'] . (trim((string) $evt['lugar']) !== '' ? ' · ' . $evt['lugar'] : '')); ?></div></td>
                        <td class="cengi-ev-num ev-td-fecha"><?php echo cengi_evt_html(cengi_evt_fecha_corta($evt['fecha'])); ?><?php if (trim((string) $evt['hora']) !== ''): ?><div class="cengi-ev-sub"><?php echo cengi_evt_html($evt['hora']); ?></div><?php endif; ?></td>
                        <td class="ev-td-acceso">
                            <?php if ($aliadosEvt): ?>
                                <div style="margin-bottom:3px;"><span class="cengi-status-badge is-active"><i></i>Colaboración</span></div>
                                <div class="cengi-ev-sub"><?php echo cengi_evt_html($aliadosEvt[0]['nombre']); ?> · Modalidad <?php echo (int) $evt['colab_modalidad']; ?></div>
                                <?php if ($evt['colab_financia'] !== 'compartido'): ?><div class="cengi-ev-sub">Gratis para participantes</div><?php endif; ?>
                            <?php endif; ?>
                            <?php if (!$aliadosEvt || $evt['colab_financia'] === 'compartido'): ?>
                            <span class="cengi-status-badge <?php echo $esPagadoEvt ? 'is-waiting' : 'is-active'; ?>"><i></i><?php echo cengi_evt_html($evt['modalidad_pago']); ?></span>
                            <?php if ($esPagadoEvt): ?><div class="cengi-ev-sub"><?php echo cengi_evt_html(cengi_evt_fmt_q($evt['costo'])); ?></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="cengi-ev-num ev-td-dato" data-label="Registrados"><strong><?php echo $registradosEvt; ?></strong><span class="cengi-ev-sub"> / <?php echo $evt['cupo'] ? (int) $evt['cupo'] : '—'; ?></span></td>
                        <td class="cengi-ev-num ev-td-dato" data-label="Pagos">
                            <?php if ($esPagadoEvt): ?>
                                <?php echo (int) $evt['pagados']; ?> pagados
                                <?php if ((int) $evt['por_validar'] > 0): ?><div><span class="cengi-ev-chip"><?php echo (int) $evt['por_validar']; ?> por validar</span></div><?php endif; ?>
                            <?php else: ?>
                                <span class="cengi-ev-sub">No aplica</span>
                            <?php endif; ?>
                        </td>
                        <td class="ev-td-dato ev-td-asist" data-label="Ingresos QR / asistencia">
                            <div class="cengi-ev-num cengi-ev-asistencia"><b><?php echo $ingresosEvt; ?></b> de <?php echo $registradosEvt; ?> registrados · <?php echo $pct; ?>%</div>
                            <div class="cengi-progress-track cengi-ev-progress"><div class="cengi-progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                        </td>
                        <td class="ev-td-estado"><span class="cengi-status-badge <?php echo cengi_evt_estado_badge($evt['estado']); ?>"><i></i><?php echo cengi_evt_html($evt['estado']); ?></span></td>
                        <td class="ev-td-acc">
                            <div class="cengi-ev-acc">
                                <?php if ($puedeGestionar): ?>
                                <button type="button" class="cengi-ev-icon-btn" title="Link de inscripción" aria-label="Link de inscripción" onclick="cengiEvtEnlaceInscripcion(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_icono('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'); ?></button>
                                <button type="button" class="cengi-ev-icon-btn" title="Enlace de escaneo en la entrada" aria-label="Enlace de escaneo en la entrada" onclick="cengiEvtEnlaceEscaneo(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_icono('<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>'); ?></button>
                                <button type="button" class="cengi-ev-icon-btn" title="Difusión: material y canales" aria-label="Difusión: material y canales" onclick="cengiEvtDifusion(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_icono('<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15 9a4 4 0 0 1 0 6M18 6a8 8 0 0 1 0 12"/>'); ?></button>
                                <?php endif; ?>
                                <button type="button" class="cengi-ev-icon-btn" title="Encuestas y evaluación" aria-label="Encuestas y evaluación" onclick="cengiEvtEncuestas(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_icono('<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>'); ?></button>
                                <button type="button" class="cengi-ev-icon-btn" title="Ver participantes" aria-label="Ver participantes" onclick="cengiEvtAbrirParticipantes(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_icono('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></button>
                                <?php if ($puedeGestionar): ?>
                                <button type="button" class="cengi-ev-icon-btn" title="Editar evento" aria-label="Editar evento" onclick="cengiEvtAbrirEvento(<?php echo (int) $evt['id']; ?>)"><?php echo cengi_evt_icono('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'); ?></button>
                                <form method="POST" class="cengi-ev-acc-form" data-evento-nombre="<?php echo cengi_evt_html($evt['nombre']); ?>" onsubmit="return cengiEvtConfirmarEliminarEvento(this);">
                                    <input type="hidden" name="accion" value="eliminar_evento">
                                    <input type="hidden" name="evento_id" value="<?php echo (int) $evt['id']; ?>">
                                    <button type="submit" class="cengi-ev-icon-btn is-danger" title="Eliminar evento" aria-label="Eliminar evento"><?php echo cengi_evt_icono('<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>'); ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr id="evSinResultados" style="display:none;"><td colspan="8" class="cengi-ev-empty">No hay eventos con esos filtros.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="evPanelEvaluacion" style="display:none;">
        <div class="cengi-kpi-grid cengi-event-stats" id="evEvalKpis"></div>
        <div class="cengi-ev-section">
            <div class="cengi-ev-section-head">
                <div><h3>Evaluación de eventos</h3><div class="cengi-ev-hint">Percepción de participantes y evaluación de las empresas aliadas en eventos de colaboración. Abre el informe para ver temas, fortalezas y recomendaciones.</div></div>
                <a class="btn btn-default btn-sm" href="eventos_gestion.php?accion=exportar_consolidado">Descargar consolidado</a>
            </div>
            <div class="cengi-table-wrap">
                <table class="table cengi-events-table cengi-eval-table">
                    <thead><tr><th>Evento</th><th>Asistentes</th><th>Percepción de participantes</th><th>Tema de mejora principal</th><th>Colaboración (empresas aliadas)</th><th></th></tr></thead>
                    <tbody id="tablaEvEval"><tr><td colspan="6" class="cengi-ev-empty">Cargando…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="modalEventoParticipantes" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog cengi-event-participants-dialog">
        <div class="modal-content">
            <div class="modal-header cengi-participants-modal-head">
                <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title" id="evpTitulo">Participantes del evento</h4>
                <div class="cengi-modal-hint" id="evpSub">Registro de participantes, pagos y código QR</div>
            </div>
            <div class="modal-body cengi-participants-modal-body">
                <div class="cengi-participants-toolbar">
                    <input type="search" id="evpBuscar" class="form-control cengi-participant-search" placeholder="Buscar participante, CUI, correo, institución o código QR...">
                    <select id="evpFiltro" class="form-control cengi-ev-filter" aria-label="Filtro"></select>
                    <button type="button" class="btn btn-default btn-sm" id="evpDescargar">Descargar listado</button>
                    <?php if ($puedeGestionar): ?>
                    <button type="button" class="btn btn-default btn-sm" id="evpEnviarGafetes" disabled>Enviar gafete <span id="evpSeleccionCount">(0)</span></button>
                    <button type="button" class="btn btn-default btn-sm" id="evpMostrarCargaMasiva">Carga masiva</button>
                    <button type="button" class="btn btn-primary btn-sm" id="evpMostrarRegistro">+ Registrar participante</button>
                    <?php endif; ?>
                </div>
                <div class="cengi-ev-strip" id="evpResumen"></div>

                <?php if ($puedeGestionar): ?>
                <div id="evpGafetesFeedback" style="display:none;"></div>
                <?php endif; ?>

                <?php if ($puedeGestionar): ?>
                <form method="POST" id="evpRegistroForm" class="cengi-event-register-form" style="display:none;">
                    <input type="hidden" name="accion" id="evpRegistroAccion" value="registrar_participante">
                    <input type="hidden" name="evento_id" id="evpRegistroEventoId" value="0">
                    <input type="hidden" name="evento_participante_id" id="evpRegistroParticipanteId" value="">
                    <div class="cengi-form-grid">
                        <div class="form-group"><label class="control-label">Nombre completo</label><input type="text" name="nombre_invitado" id="evpRegistroNombre" class="form-control"></div>
                        <div class="form-group"><label class="control-label">CUI</label><input type="text" name="cui_invitado" id="evpRegistroCui" class="form-control"></div>
                        <div class="form-group"><label class="control-label">Correo electrónico</label><input type="email" name="correo_invitado" id="evpRegistroCorreo" class="form-control" placeholder="Para el envío del gafete"></div>
                        <div class="form-group">
                            <label class="control-label">Ingenio</label>
                            <select name="ingenio_id" id="evpRegistroIngenio" class="form-control">
                                <option value="">Invitado externo</option>
                                <?php foreach ($ingeniosLista as $ing): ?>
                                    <option value="<?php echo (int) $ing['id']; ?>"><?php echo cengi_evt_html($ing['nombre_ingenios']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Pago</label>
                            <div class="checkbox" style="margin-top:4px;">
                                <label><input type="checkbox" name="pagado" id="evpRegistroPagado" value="1"> Pagado</label>
                            </div>
                            <p class="help-block" id="evpRegistroPagadoAyuda" style="display:none;">Solo disponible en eventos de acceso pagado.</p>
                        </div>
                        <div class="form-group cengi-form-full cengi-register-actions"><button type="button" class="btn btn-default btn-sm" id="evpCancelarRegistro">Cancelar</button><button type="submit" class="btn btn-success btn-sm" id="evpRegistroSubmitBtn">Generar QR y registrar</button></div>
                    </div>
                </form>

                <form method="POST" enctype="multipart/form-data" id="evpCargaMasivaForm" class="cengi-event-register-form" style="display:none;">
                    <input type="hidden" name="accion" value="carga_masiva_participantes">
                    <input type="hidden" name="evento_id" id="evpCargaMasivaEventoId" value="0">
                    <div class="cengi-form-grid">
                        <div class="form-group cengi-form-full">
                            <label class="control-label">Archivo CSV o Excel (.xls)</label>
                            <input type="file" name="archivo_masivo" class="form-control" accept=".csv,.xls" required>
                            <p class="help-block">Columnas en este orden: Nombre (obligatorio), CUI (opcional), Correo (opcional), Ingenio (opcional; debe coincidir con un ingenio registrado, si no se deja sin ingenio). <a href="plantilla_carga_masiva_eventos.php" download>Descargar plantilla Excel</a>.</p>
                        </div>
                        <div class="form-group cengi-form-full cengi-register-actions"><button type="button" class="btn btn-default btn-sm" id="evpCancelarCargaMasiva">Cancelar</button><button type="submit" class="btn btn-success btn-sm">Cargar participantes</button></div>
                    </div>
                </form>
                <?php endif; ?>

                <div id="evpCargando" class="cengi-modal-loading"><span class="glyphicon glyphicon-refresh"></span> Cargando participantes…</div>
                <div class="cengi-table-wrap" id="evpTablaWrap" style="display:none;">
                    <table class="table cengi-event-participants-table">
                        <thead><tr>
                            <?php if ($puedeGestionar): ?><th class="cengi-participant-check-col"><input type="checkbox" id="evpSeleccionarTodos" title="Seleccionar todos"></th><?php endif; ?>
                            <th>Participante</th><th>Institución</th><th>Pago</th><th>Código QR</th><th>Ingreso</th><th></th>
                        </tr></thead>
                        <tbody id="tablaEventoParticipantes"></tbody>
                    </table>
                </div>
                <div id="evpVacio" class="cengi-empty-participants" style="display:none;">Todavía no hay participantes registrados en este evento.</div>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="modalQrParticipante" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog cengi-qr-detail-dialog">
        <div class="modal-content">
            <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title">QR del participante</h4></div>
            <div class="modal-body cengi-qr-detail-body">
                <div class="cengi-badge-card cengi-individual-badge">
                    <div class="bc-top"><div class="cengi-badge-eyebrow">CENGICAÑA · Evento técnico</div><div class="cengi-badge-event" id="qrDetalleEvento"></div></div>
                    <div class="bc-body"><div class="cengi-badge-name" id="qrDetalleNombre"></div><div class="cengi-badge-company" id="qrDetalleIngenio"></div><div class="cengi-qr-box cengi-qr-box-large" id="qrDetalleImagen"></div><div class="mono cengi-badge-code" id="qrDetalleCodigo"></div></div>
                </div>
                <p class="cengi-qr-help">Al escanear este QR se obtiene el código único asignado a esta persona.</p>
            </div>
            <div class="modal-footer cengi-qr-detail-actions"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button><button type="button" class="btn btn-primary" id="qrDescargar"><span class="glyphicon glyphicon-download-alt"></span> Descargar QR</button></div>
        </div>
    </div>
</div>

<?php if ($puedeGestionar): ?>
<div class="modal fade" id="modalEnlaceInscripcion" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title">Enlace público de inscripción</h4></div>
            <div class="modal-body">
                <p><strong id="eivNombreEvento">Cargando…</strong></p>
                <div class="form-group">
                    <label class="control-label">URL para compartir con los participantes</label>
                    <input type="text" id="eivUrl" class="form-control" readonly onclick="this.select();">
                </div>
                <div class="cengi-notice cengi-qr-notice"><span class="glyphicon glyphicon-info-sign"></span><span>Quien tenga este enlace podrá completar el formulario y recibirá un código QR único.</span></div>
                <span id="eivCopiarFeedback" class="text-success" style="display:none;"></span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-primary" id="eivCopiar"><span class="glyphicon glyphicon-copy"></span> Copiar enlace</button>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="modalEnlaceEscaneo" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title">Enlace de escaneo en la entrada</h4></div>
            <div class="modal-body">
                <p><strong id="eevNombreEvento">Cargando…</strong></p>
                <div class="form-group">
                    <label class="control-label">URL para abrir en el celular de la puerta</label>
                    <input type="text" id="eevUrl" class="form-control" readonly onclick="this.select();">
                </div>
                <div class="cengi-notice cengi-qr-notice"><span class="glyphicon glyphicon-warning-sign"></span><span>No compartas este enlace públicamente: cualquiera con el link puede marcar asistencia en este evento.</span></div>
                <span id="eevCopiarFeedback" class="text-success" style="display:none;"></span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-primary" id="eevCopiar"><span class="glyphicon glyphicon-copy"></span> Copiar enlace</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-ev-modal" id="evtModal" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="evfTitulo">
    <div class="modal-dialog"><div class="modal-content"><form method="POST" id="evfForm" novalidate>
        <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="Cerrar">&times;</button><h4 class="modal-title" id="evfTitulo">Nuevo evento</h4><div class="cengi-modal-hint">Datos que ve el participante en el link de inscripción</div></div>
        <div class="modal-body">
            <input type="hidden" name="accion" value="guardar_evento">
            <input type="hidden" name="evento_id" id="evfId" value="">
            <div class="cengi-form-grid">
                <div class="form-group cengi-form-full"><label class="control-label" for="evfNombre">Nombre del evento</label><input type="text" name="nombre" id="evfNombre" class="form-control" maxlength="255" placeholder="Ej. DRONTECH"></div>
                <div class="form-group"><label class="control-label" for="evfTipo">Tipo</label><select name="tipo" id="evfTipo" class="form-control"><?php foreach (CENGI_EVT_TIPOS as $tipoOpcion): ?><option><?php echo cengi_evt_html($tipoOpcion); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="control-label" for="evfFecha">Fecha</label><input type="date" name="fecha" id="evfFecha" class="form-control"></div>
                <div class="form-group"><label class="control-label" for="evfHora">Horario</label><input type="text" name="hora" id="evfHora" class="form-control" maxlength="40" placeholder="8:00–13:00"></div>
                <div class="form-group"><label class="control-label" for="evfLugar">Lugar</label><input type="text" name="lugar" id="evfLugar" class="form-control" maxlength="255" placeholder="Auditorio CENGICAÑA"></div>
                <div class="form-group"><label class="control-label" for="evtModalidadPago">Acceso</label><select name="modalidad_pago" id="evtModalidadPago" class="form-control"><option value="Gratuito">Gratuito</option><option value="Pagado">Pagado</option></select></div>
                <div class="form-group"><label class="control-label" for="evfCupo">Cupo</label><input type="number" name="cupo" id="evfCupo" class="form-control" min="1" placeholder="150"></div>
                <div class="form-group" id="evtCostoGrupo" style="display:none;"><label class="control-label" for="evtCosto">Costo nacional (Q)</label><input type="number" name="costo" id="evtCosto" class="form-control" min="0" step="0.01" inputmode="decimal" placeholder="300"></div>
                <div class="form-group" id="evfEstadoGrupo" style="display:none;"><label class="control-label" for="evfEstado">Estado</label><select name="estado" id="evfEstado" class="form-control"><?php foreach (CENGI_EVT_ESTADOS as $estadoOpcion): ?><option><?php echo cengi_evt_html($estadoOpcion); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="control-label" for="evfColor">Color del evento</label><input type="color" name="color" id="evfColor" class="form-control cengi-ev-color" value="#2F6B12"></div>
                <div class="form-group cengi-form-full">
                    <label class="cengi-ev-check" style="font-weight:600;"><input type="checkbox" name="en_colaboracion" id="evfColab" value="1"> Evento en colaboración con empresas aliadas</label>
                    <div id="evfColabBox" style="margin-top:8px;">
                        <div class="cengi-form-grid" style="margin-bottom:8px;">
                            <div class="form-group"><label class="control-label" for="evfColabMod">Modalidad de colaboración</label><select name="colab_modalidad" id="evfColabMod" class="form-control"><?php foreach (CENGI_EVT_COLAB_MODALIDADES as $modNum => $modTexto): ?><option value="<?php echo (int) $modNum; ?>"><?php echo (int) $modNum . ' · ' . cengi_evt_html($modTexto); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="control-label" for="evfColabFin">¿Quién cubre el costo?</label><select name="colab_financia" id="evfColabFin" class="form-control"><?php foreach (CENGI_EVT_COLAB_FINANCIA as $finClave => $finTexto): ?><option value="<?php echo cengi_evt_html($finClave); ?>"><?php echo cengi_evt_html($finTexto); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group cengi-form-full"><div class="cengi-ev-sub" id="evfColabNota"></div></div>
                        </div>
                        <div id="evfColabLista"></div>
                        <button type="button" class="btn btn-default btn-sm" id="evfColabAgregar">+ Agregar empresa aliada</button>
                        <div class="cengi-ev-sub" style="margin-top:4px;">A cada empresa se le genera un enlace para la encuesta de colaboración.</div>
                    </div>
                </div>
                <div class="form-group cengi-form-full"><label class="control-label" for="evfDesc">Descripción corta</label><textarea name="descripcion" id="evfDesc" class="form-control" rows="3" placeholder="Lo que verá el participante en el formulario"></textarea></div>
            </div>
            <div class="cengi-ev-form-msg" id="evfMsg" role="alert"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Guardar evento</button></div>
    </form></div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-ev-modal" id="modalDifusion" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="dfTitulo">
    <div class="modal-dialog cengi-ev-modal-xl"><div class="modal-content">
        <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="Cerrar">&times;</button><h4 class="modal-title" id="dfTitulo">Difusión</h4><div class="cengi-modal-hint" id="dfSub"></div></div>
        <div class="modal-body" id="dfBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal">Listo</button></div>
    </div></div>
</div>

<div class="modal fade cengi-ev-modal" id="modalEvEncuestas" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="eeTitulo">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="Cerrar">&times;</button><h4 class="modal-title" id="eeTitulo">Encuestas</h4><div class="cengi-modal-hint" id="eeSub"></div></div>
        <div class="modal-body" id="eeBody"></div>
        <div class="modal-footer" id="eeFoot"></div>
    </div></div>
</div>

<div class="modal fade cengi-ev-modal" id="modalEvInforme" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="eiTituloInf">
    <div class="modal-dialog cengi-ev-modal-informe"><div class="modal-content">
        <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="Cerrar">&times;</button><h4 class="modal-title" id="eiTituloInf">Informe</h4></div>
        <div class="modal-body" id="eiBodyInf" style="background:#F4F6F1;"></div>
        <div class="modal-footer"><a class="btn btn-default" id="eiDescargar" href="#">Descargar respuestas</a><button type="button" class="btn btn-primary" id="eiImprimir">Imprimir / guardar PDF</button></div>
    </div></div>
</div>
<script src="js/qrcode-generator.js"></script>
<script>
window.CENGI_EVT = <?php echo json_encode([
    'puedeGestionar' => $puedeGestionar,
    'eventos' => $eventosEdicion,
    'colabItems' => CENGI_EVT_COLAB_ITEMS,
    'colabGrupos' => CENGI_EVT_COLAB_GRUPOS,
    'canales' => CENGI_EVT_DIF_CANALES,
    'usuario' => (string) ($_SESSION['usuario'] ?? ''),
    'hoy' => date('Y-m-d'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="js/eventos_gestion.js"></script>
<script>
(function () {
    'use strict';
    var eventoActual = null;
    var participantes = [];
    var qrActual = '';
    var puedeGestionar = <?php echo $puedeGestionar ? 'true' : 'false'; ?>;
    var seleccionados = {};
    var ICONO_QR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3z"/></svg>';
    var ICONO_EDITAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
    var ICONO_ELIMINAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>';

    function escapeHtml(valor) {
        return $('<div>').text(valor == null ? '' : String(valor)).html();
    }

    function iniciales(nombre) {
        var partes = String(nombre || '').trim().split(/\s+/).filter(Boolean);
        return (partes[0] ? partes[0].charAt(0) : '') + (partes[1] ? partes[1].charAt(0) : '');
    }

    function crearQr(codigo, contenedor, grande) {
        var elemento = typeof contenedor === 'string' ? document.getElementById(contenedor) : contenedor;
        if (!elemento) return;
        elemento.innerHTML = '';
        if (typeof qrcode !== 'function') {
            elemento.textContent = codigo;
            return;
        }
        var qr = qrcode(0, 'M');
        qr.addData(String(codigo), 'Byte');
        qr.make();
        elemento.innerHTML = qr.createSvgTag({cellSize: grande ? 6 : 3, margin: grande ? 16 : 8, scalable: true});
        var svg = elemento.querySelector('svg');
        if (svg) svg.setAttribute('aria-label', 'Código QR ' + codigo);
    }

    function actualizarEstadoSeleccion(filtrados) {
        var total = 0;
        for (var k in seleccionados) { if (seleccionados[k]) total++; }
        $('#evpSeleccionCount').text('(' + total + ')');
        $('#evpEnviarGafetes').prop('disabled', total === 0);

        if (!filtrados) return;
        var visiblesSeleccionados = filtrados.filter(function (p) { return !!seleccionados[p.id]; }).length;
        var todos = document.getElementById('evpSeleccionarTodos');
        if (todos) {
            todos.checked = filtrados.length > 0 && visiblesSeleccionados === filtrados.length;
            todos.indeterminate = visiblesSeleccionados > 0 && visiblesSeleccionados < filtrados.length;
        }
    }

    var MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    function fechaCorta(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        if (!m) return '—';
        return m[3] + ' ' + MESES_CORTOS[Number(m[2]) - 1] + ' ' + m[1];
    }

    function formatoQ(valor) {
        return 'Q' + Math.round(Number(valor) || 0).toLocaleString('es-GT');
    }

    function esEventoPagadoActual() {
        return !!(eventoActual && eventoActual.modalidad_pago === 'Pagado');
    }

    // Pago de un participante: 'pagado' | 'por-validar' (subio recibo, aun sin marcar pagado) | 'no-pagado'.
    function estadoPagoParticipante(p) {
        if (Number(p.pagado)) return 'pagado';
        return p.recibo_pago ? 'por-validar' : 'no-pagado';
    }

    function encabezadoEvento() {
        if (!eventoActual) return;
        var acceso = esEventoPagadoActual() ? 'Pagado ' + formatoQ(eventoActual.costo) : 'Gratuito';
        $('#evpSub').text([eventoActual.tipo, fechaCorta(eventoActual.fecha), acceso, 'Registro individual de participantes y código QR'].filter(Boolean).join(' · '));
        var opciones = '<option value="todos">Todos</option>';
        if (esEventoPagadoActual()) {
            opciones += '<option value="no-pagado">No pagado</option><option value="por-validar">Comprobante por validar</option><option value="pagado">Pagado</option>';
        }
        opciones += '<option value="ingreso">Con ingreso</option><option value="sin-ingreso">Sin ingreso</option>';
        $('#evpFiltro').html(opciones);
    }

    function participantesFiltrados() {
        var busqueda = ($('#evpBuscar').val() || '').toLowerCase().trim();
        var filtro = $('#evpFiltro').val() || 'todos';
        return participantes.filter(function (p) {
            var pasa = filtro === 'todos'
                || (filtro === 'ingreso' && !!p.ingreso_en)
                || (filtro === 'sin-ingreso' && !p.ingreso_en)
                || estadoPagoParticipante(p) === filtro;
            return pasa && (!busqueda || [p.nombre, p.ingenio, p.codigo_qr, p.cui, p.correo, p.telefono, p.pago_boleta].join(' ').toLowerCase().indexOf(busqueda) !== -1);
        });
    }

    function renderResumen() {
        var esPagado = esEventoPagadoActual();
        var porValidar = 0, pagados = 0, noPagados = 0, ingresos = 0;
        participantes.forEach(function (p) {
            var e = estadoPagoParticipante(p);
            if (e === 'pagado') pagados++; else if (e === 'por-validar') porValidar++; else noPagados++;
            if (p.ingreso_en) ingresos++;
        });
        var stats = [['Registrados', participantes.length, '']];
        if (esPagado) {
            stats.push(['Por validar', porValidar, porValidar ? '#B34E00' : '']);
            stats.push(['Pagados', pagados, '#3E7A12']);
            stats.push(['No pagados', noPagados, noPagados ? '#B23223' : '']);
        }
        stats.push(['Con QR', participantes.length, '']);
        stats.push(['Ingresos', ingresos, '#3E7A12']);
        $('#evpResumen').html(stats.map(function (s) {
            return '<div class="cengi-ev-mini-stat"><span class="ms-label">' + escapeHtml(s[0]) + '</span><span class="ms-val"' + (s[2] ? ' style="color:' + s[2] + ';"' : '') + '>' + escapeHtml(s[1]) + '</span></div>';
        }).join(''));
    }

    function renderParticipantes() {
        var busqueda = ($('#evpBuscar').val() || '').trim();
        var filtrados = participantesFiltrados();
        $('#evpCargando').hide();
        $('#evpVacio').toggle(filtrados.length === 0).text(participantes.length === 0
            ? 'Aún no hay participantes. Comparte el link de inscripción o registra uno.'
            : (busqueda ? 'Nadie coincide con “' + busqueda + '”.' : 'No hay participantes con ese filtro.'));
        $('#evpTablaWrap').toggle(filtrados.length > 0);
        renderResumen();

        var esEventoPagado = esEventoPagadoActual();
        $('#tablaEventoParticipantes').html(filtrados.map(function (p) {
            var indice = participantes.indexOf(p);
            var estadoPago = estadoPagoParticipante(p);
            var ingreso = p.ingreso_en
                ? '<span class="cengi-status-badge is-active"><i></i>Ingresó</span><small class="cengi-entry-time">' + escapeHtml(p.ingreso_en) + '</small>'
                : '<span class="cengi-status-badge is-finished"><i></i>Sin ingreso</span>';
            var pagoBadge = !esEventoPagado
                ? '<span class="cengi-status-badge is-finished" title="El evento es gratuito"><i></i>Gratuito</span>'
                : (estadoPago === 'pagado'
                    ? '<span class="cengi-status-badge is-active"><i></i>Pagado</span>'
                    : (estadoPago === 'por-validar'
                        ? '<span class="cengi-status-badge is-waiting"><i></i>Comprobante por validar</span>'
                        : '<span class="cengi-status-badge is-rejected"><i></i>No pagado</span>'));
            var pagoAccion = '';
            if (esEventoPagado && puedeGestionar) {
                var formPago = function (valor, clase, texto) {
                    return '<div class="cengi-ev-cell-action"><form method="POST" class="cengi-inline-entry-form"><input type="hidden" name="accion" value="marcar_pago"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><input type="hidden" name="pagado" value="' + valor + '"><button type="submit" class="' + clase + '">' + texto + '</button></form></div>';
                };
                pagoAccion = estadoPago === 'pagado'
                    ? formPago(0, 'cengi-ev-linkbtn', 'Marcar no pagado')
                    : (estadoPago === 'por-validar'
                        ? formPago(1, 'btn btn-primary btn-sm', 'Validar pago')
                        : formPago(1, 'btn btn-default btn-sm', 'Marcar pagado'));
            }
            // Enlace al recibo de pago subido por el participante en el formulario publico
            // (cengicursos/inscripcion_evento.php); solo existe para eventos pagados.
            // Datos del comprobante que la persona escribio en el formulario publico.
            var datosPago = (esEventoPagado && (p.pago_boleta || p.pago_monto))
                ? '<div class="cengi-ev-sub">' + [p.pago_boleta ? 'Boleta ' + escapeHtml(p.pago_boleta) : '', p.pago_monto ? escapeHtml(formatoQ(p.pago_monto)) : '', p.pago_fecha ? escapeHtml(fechaCorta(p.pago_fecha)) : '', p.pago_banco ? escapeHtml(p.pago_banco) : ''].filter(Boolean).join(' · ') + '</div>'
                : '';
            var reciboEnlace = (esEventoPagado && p.recibo_pago)
                ? '<div class="cengi-ev-cell-action"><a href="' + escapeHtml(p.recibo_pago) + '" target="_blank" rel="noopener" class="cengi-ev-linkbtn">Ver recibo</a></div>'
                : '';
            var marcar = (!p.ingreso_en && puedeGestionar)
                ? '<form method="POST" class="cengi-inline-entry-form"><input type="hidden" name="accion" value="marcar_ingreso"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><button type="submit" class="btn btn-primary btn-sm" title="Registrar ingreso">Marcar ingreso</button></form>'
                : '';
            var check = puedeGestionar
                ? '<td class="cengi-td-check"><input type="checkbox" class="cengi-participant-check" data-id="' + Number(p.id) + '"' + (seleccionados[p.id] ? ' checked' : '') + ' aria-label="Seleccionar"></td>'
                : '';
            var editar = puedeGestionar
                ? '<button type="button" class="cengi-ev-icon-btn" title="Editar" aria-label="Editar participante" onclick="cengiEvtEditarParticipante(' + indice + ')">' + ICONO_EDITAR + '</button>'
                : '';
            var eliminar = puedeGestionar
                ? '<form method="POST" class="cengi-ev-acc-form" data-participante-nombre="' + escapeHtml(p.nombre) + '" onsubmit="return cengiEvtConfirmarEliminarParticipante(this);"><input type="hidden" name="accion" value="eliminar_participante"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><button type="submit" class="cengi-ev-icon-btn is-danger" title="Eliminar" aria-label="Eliminar participante">' + ICONO_ELIMINAR + '</button></form>'
                : '';
            return '<tr>' + check +
                '<td class="cengi-td-participante"><div class="cengi-person-cell"><span class="cengi-avatar-sm">' + escapeHtml(iniciales(p.nombre)) + '</span><span><strong>' + escapeHtml(p.nombre) + '</strong><small>' + (p.cui ? 'CUI ' + escapeHtml(p.cui) : 'Sin CUI') + '</small><small>' + (p.correo ? escapeHtml(p.correo) : 'Sin correo') + '</small>' + (p.telefono ? '<small>Tel. ' + escapeHtml(p.telefono) + '</small>' : '') + '</span></div></td>' +
                '<td class="cengi-td-ingenio">' + escapeHtml(p.ingenio) + '</td>' +
                '<td class="cengi-td-pago">' + pagoBadge + datosPago + pagoAccion + reciboEnlace + '</td>' +
                '<td class="cengi-td-qr"><div class="cengi-person-qr"><span class="cengi-mini-qr" data-codigo="' + escapeHtml(p.codigo_qr) + '" onclick="cengiEvtVerQr(' + indice + ')"></span><span class="mono">' + escapeHtml(p.codigo_qr) + '</span></div></td>' +
                '<td class="cengi-td-ingreso">' + ingreso + marcar + '</td>' +
                '<td class="cengi-td-acciones"><div class="cengi-ev-acc-col"><button type="button" class="cengi-ev-icon-btn is-solid" title="Ver gafete" aria-label="Ver gafete" onclick="cengiEvtVerQr(' + indice + ')">' + ICONO_QR + '</button>' + editar + eliminar + '</div></td></tr>';
        }).join(''));
        $('#tablaEventoParticipantes .cengi-mini-qr').each(function () { crearQr($(this).attr('data-codigo'), this, false); });

        if (puedeGestionar) {
            $('#tablaEventoParticipantes .cengi-participant-check').on('change', function () {
                var id = $(this).data('id');
                seleccionados[id] = this.checked;
                actualizarEstadoSeleccion(filtrados);
            });
            actualizarEstadoSeleccion(filtrados);
        }
    }

    function resetearFormularioRegistro() {
        $('#evpRegistroForm')[0] && $('#evpRegistroForm')[0].reset();
        $('#evpRegistroAccion').val('registrar_participante');
        $('#evpRegistroParticipanteId').val('');
        $('#evpRegistroIngenio').val('');
        $('#evpRegistroPagado').prop('checked', false);
        $('#evpRegistroSubmitBtn').text('Generar QR y registrar');
        sincronizarControlPagoRegistro();
    }

    // El checkbox "Pagado" del formulario de registro solo se habilita cuando el evento
    // actual es de acceso pagado (eventoActual.modalidad_pago, que llega en el JSON de
    // listar_participantes). En eventos gratuitos queda deshabilitado y sin marcar.
    function sincronizarControlPagoRegistro() {
        var esEventoPagado = !!(eventoActual && eventoActual.modalidad_pago === 'Pagado');
        $('#evpRegistroPagado').prop('disabled', !esEventoPagado);
        if (!esEventoPagado) $('#evpRegistroPagado').prop('checked', false);
        $('#evpRegistroPagadoAyuda').toggle(!esEventoPagado);
    }

    // Confirmacion antes del borrado fisico de un evento (accion POST eliminar_evento).
    window.cengiEvtConfirmarEliminarEvento = function (form) {
        var nombre = form ? (form.getAttribute('data-evento-nombre') || '') : '';
        return window.confirm('¿Eliminar el evento "' + nombre + '" y todos sus participantes? Esta acción no se puede deshacer.');
    };

    // Confirmacion antes del borrado fisico de un participante del evento
    // (accion POST eliminar_participante).
    window.cengiEvtConfirmarEliminarParticipante = function (form) {
        var nombre = form ? (form.getAttribute('data-participante-nombre') || '') : '';
        return window.confirm('¿Eliminar a "' + nombre + '" de este evento? Esta acción no se puede deshacer.');
    };

    window.cengiEvtAbrirParticipantes = function (eventoId) {
        eventoActual = {id: Number(eventoId)};
        participantes = [];
        seleccionados = {};
        $('#evpTitulo').text('Participantes del evento');
        $('#evpSub').text('Registro de participantes, pagos y código QR');
        $('#evpResumen').empty();
        $('#evpFiltro').html('<option value="todos">Todos</option>');
        $('#evpBuscar').val('');
        $('#evpRegistroEventoId').val(eventoId);
        $('#evpCargaMasivaEventoId').val(eventoId);
        resetearFormularioRegistro();
        $('#evpRegistroForm').hide();
        $('#evpCargaMasivaForm').hide();
        $('#evpTablaWrap, #evpVacio').hide();
        $('#evpGafetesFeedback').hide().empty();
        $('#evpSeleccionCount').text('(0)');
        $('#evpEnviarGafetes').prop('disabled', true);
        $('#evpCargando').show();
        $('#modalEventoParticipantes').modal('show');
        $.getJSON('eventos_qr.php', {accion: 'listar_participantes', evento_id: eventoId})
            .done(function (respuesta) {
                if (!respuesta.ok) return;
                eventoActual = respuesta.evento;
                participantes = respuesta.participantes || [];
                $('#evpTitulo').text(eventoActual.nombre);
                sincronizarControlPagoRegistro();
                encabezadoEvento();
                renderParticipantes();
            })
            .fail(function () {
                $('#evpCargando').hide();
                $('#evpVacio').text('No fue posible cargar los participantes. Intenta nuevamente.').show();
            });
    };

    window.cengiEvtVerQr = function (indice) {
        var p = participantes[indice];
        if (!p || !eventoActual) return;
        qrActual = p.codigo_qr;
        $('#qrDetalleEvento').text(eventoActual.nombre || 'Evento técnico');
        $('#qrDetalleNombre').text(p.nombre);
        $('#qrDetalleIngenio').text(p.ingenio);
        $('#qrDetalleCodigo').text(p.codigo_qr);
        crearQr(p.codigo_qr, 'qrDetalleImagen', true);
        $('#modalQrParticipante').modal('show');
    };

    window.cengiEvtEditarParticipante = function (indice) {
        var p = participantes[indice];
        if (!p || !eventoActual || !puedeGestionar) return;
        $('#evpCargaMasivaForm').slideUp(150);
        $('#evpRegistroAccion').val('editar_participante');
        $('#evpRegistroParticipanteId').val(p.id);
        $('#evpRegistroNombre').val(p.nombre || '');
        $('#evpRegistroCui').val(p.cui || '');
        $('#evpRegistroCorreo').val(p.correo || '');
        $('#evpRegistroIngenio').val(p.ingenio_id ? String(p.ingenio_id) : '');
        sincronizarControlPagoRegistro();
        $('#evpRegistroPagado').prop('checked', !$('#evpRegistroPagado').prop('disabled') && !!Number(p.pagado));
        $('#evpRegistroSubmitBtn').text('Guardar cambios');
        $('#evpRegistroForm').slideDown(150);
        var campo = document.getElementById('evpRegistroForm');
        if (campo && campo.scrollIntoView) campo.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    };

    $('#evpBuscar').on('input', renderParticipantes);
    $('#evpFiltro').on('change', renderParticipantes);

    // Filtros de la tabla de eventos (busqueda por nombre/tipo y estado), en el cliente.
    function filtrarEventos() {
        var texto = ($('#evBuscar').val() || '').toLowerCase().trim();
        var estado = $('#evEstadoF').val() || '';
        var visibles = 0;
        $('#tablaEventos tr.cengi-ev-row').each(function () {
            var $fila = $(this);
            var coincide = (!estado || $fila.attr('data-estado') === estado)
                && (!texto || ($fila.attr('data-buscar') || '').indexOf(texto) !== -1);
            $fila.toggle(coincide);
            if (coincide) visibles++;
        });
        $('#evSinResultados').toggle($('#tablaEventos tr.cengi-ev-row').length > 0 && visibles === 0);
    }
    $('#evBuscar').on('input', filtrarEventos);
    $('#evEstadoF').on('change', filtrarEventos);
    $('#evpMostrarRegistro').on('click', function () { $('#evpCargaMasivaForm').slideUp(150); resetearFormularioRegistro(); $('#evpRegistroForm').slideDown(150); });
    $('#evpCancelarRegistro').on('click', function () { $('#evpRegistroForm').slideUp(150); resetearFormularioRegistro(); });
    $('#evpMostrarCargaMasiva').on('click', function () { $('#evpRegistroForm').slideUp(150); resetearFormularioRegistro(); $('#evpCargaMasivaForm').slideDown(150); });
    $('#evpCancelarCargaMasiva').on('click', function () { $('#evpCargaMasivaForm').slideUp(150); });

    $(document).on('change', '#evpSeleccionarTodos', function () {
        var marcar = this.checked;
        var filtrados = participantesFiltrados();
        filtrados.forEach(function (p) { seleccionados[p.id] = marcar; });
        renderParticipantes();
    });

    function mostrarFeedbackGafetes(esError, mensajeHtml) {
        var $feedback = $('#evpGafetesFeedback');
        $feedback.attr('class', 'cengi-feedback' + (esError ? ' is-error' : ''))
            .html('<div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' + (esError ? 'warning-sign' : 'ok') + '"></span></div><div>' + mensajeHtml + '</div>')
            .show();
    }

    $('#evpEnviarGafetes').on('click', function () {
        var ids = Object.keys(seleccionados).filter(function (id) { return seleccionados[id]; });
        if (!ids.length || !eventoActual) return;

        var $boton = $(this);
        $boton.prop('disabled', true);
        $('#evpGafetesFeedback').hide().empty();

        $.post('enviar_gafetes_evento.php', {
            evento_id: Number(eventoActual.id),
            participante_ids: ids
        }, null, 'json')
            .done(function (respuesta) {
                if (!respuesta || !respuesta.ok) {
                    mostrarFeedbackGafetes(true, '<p>' + escapeHtml((respuesta && respuesta.mensaje) || 'No fue posible enviar los gafetes.') + '</p>');
                    actualizarEstadoSeleccion();
                    return;
                }
                var partes = [];
                partes.push('<p>Gafetes enviados: <strong>' + respuesta.enviados + '</strong> de ' + respuesta.total_seleccionados + ' seleccionado(s).</p>');
                if (respuesta.sin_correo && respuesta.sin_correo.length) {
                    partes.push('<p>Sin correo registrado (no se les pudo enviar): ' + escapeHtml(respuesta.sin_correo.join(', ')) + '.</p>');
                }
                if (respuesta.fallidos && respuesta.fallidos.length) {
                    partes.push('<p>Fallo el envío para: ' + escapeHtml(respuesta.fallidos.join(', ')) + '.</p>');
                }
                var huboProblemas = (respuesta.sin_correo && respuesta.sin_correo.length) || (respuesta.fallidos && respuesta.fallidos.length);
                mostrarFeedbackGafetes(huboProblemas && respuesta.enviados === 0, partes.join(''));
                // Se limpia la seleccion tras un envio exitoso (aunque haya
                // habido avisos de "sin correo"/fallidos individuales), para
                // que el usuario pueda ver de un vistazo que ya se proceso el
                // lote actual. Si la peticion entera fallo (catch de arriba),
                // la seleccion se conserva para poder reintentar sin re-marcar.
                seleccionados = {};
                renderParticipantes();
            })
            .fail(function () {
                mostrarFeedbackGafetes(true, '<p>Ocurrió un error al enviar los gafetes. Intenta nuevamente.</p>');
                actualizarEstadoSeleccion();
            });
    });

    $('#evpDescargar').on('click', function () {
        if (!eventoActual) return;
        var url = 'eventos_qr.php?accion=exportar_participantes&evento_id=' + encodeURIComponent(String(eventoActual.id));
        var enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = 'participantes-evento-' + eventoActual.id + '.xls';
        document.body.appendChild(enlace);
        enlace.click();
        document.body.removeChild(enlace);
    });

    $('#qrDescargar').on('click', function () {
        var svg = document.querySelector('#qrDetalleImagen svg');
        if (!svg || !qrActual) return;
        var contenido = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>\n' + new XMLSerializer().serializeToString(svg);
        var enlace = document.createElement('a');
        enlace.href = URL.createObjectURL(new Blob([contenido], {type: 'image/svg+xml;charset=utf-8'}));
        enlace.download = 'QR-' + qrActual.replace(/[^A-Za-z0-9_-]/g, '-') + '.svg';
        enlace.click();
        URL.revokeObjectURL(enlace.href);
    });

    var ejemplo = document.getElementById('cengiQrEjemplo');
    if (ejemplo) crearQr(ejemplo.getAttribute('data-codigo'), ejemplo, true);


    // Enlace publico de escaneo QR (cengicursos/escanear_evento.php): el token se
    // resuelve/genera siempre en el servidor (accion=enlace_escaneo), nunca en el
    // cliente, mismo criterio que copyEvaluationLink() en instructores.php.
    if (puedeGestionar) {
        window.cengiEvtEnlaceInscripcion = function (eventoId) {
            $('#eivNombreEvento').text('Cargando…');
            $('#eivUrl').val('');
            $('#eivCopiarFeedback').hide();
            $('#modalEnlaceInscripcion').modal('show');
            $.getJSON('eventos_qr.php', {accion: 'enlace_inscripcion', evento_id: eventoId})
                .done(function (respuesta) {
                    if (!respuesta || !respuesta.ok) {
                        $('#eivNombreEvento').text((respuesta && respuesta.mensaje) || 'No fue posible generar el enlace.');
                        return;
                    }
                    $('#eivNombreEvento').text(respuesta.evento_nombre);
                    $('#eivUrl').val(window.location.origin + '/cengicursos/inscripcion_evento.php?token=' + encodeURIComponent(respuesta.token));
                })
                .fail(function () {
                    $('#eivNombreEvento').text('No fue posible generar el enlace. Intenta nuevamente.');
                });
        };

        $('#eivCopiar').on('click', function () {
            var url = $('#eivUrl').val();
            if (!url) return;
            function mostrarCopiado() {
                $('#eivCopiarFeedback').text('¡Copiado!').show();
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(mostrarCopiado, function () {
                    window.prompt('Copia el enlace de inscripción:', url);
                });
            } else {
                window.prompt('Copia el enlace de inscripción:', url);
            }
        });

        window.cengiEvtEnlaceEscaneo = function (eventoId) {
            $('#eevNombreEvento').text('Cargando…');
            $('#eevUrl').val('');
            $('#eevCopiarFeedback').hide();
            $('#modalEnlaceEscaneo').modal('show');
            $.getJSON('eventos_qr.php', {accion: 'enlace_escaneo', evento_id: eventoId})
                .done(function (respuesta) {
                    if (!respuesta || !respuesta.ok) {
                        $('#eevNombreEvento').text((respuesta && respuesta.mensaje) || 'No fue posible generar el enlace.');
                        return;
                    }
                    $('#eevNombreEvento').text(respuesta.evento_nombre);
                    $('#eevUrl').val(window.location.origin + '/cengicursos/escanear_evento.php?token=' + encodeURIComponent(respuesta.token));
                })
                .fail(function () {
                    $('#eevNombreEvento').text('No fue posible generar el enlace. Intenta nuevamente.');
                });
        };

        $('#eevCopiar').on('click', function () {
            var url = $('#eevUrl').val();
            if (!url) return;
            function mostrarCopiado() {
                $('#eevCopiarFeedback').text('¡Copiado!').show();
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(mostrarCopiado, function () {
                    window.prompt('Copia el enlace de escaneo:', url);
                });
            } else {
                window.prompt('Copia el enlace de escaneo:', url);
            }
        });
    }

    <?php if ($eventoReabrirId > 0): ?>cengiEvtAbrirParticipantes(<?php echo (int) $eventoReabrirId; ?>);<?php endif; ?>
}());
</script>
</body>
</html>
