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
               ep.codigo_qr, ep.ingreso_en, ep.pagado, ep.ingenio_id,
               COALESCE(ip.nombre_ingenios, ie.nombre_ingenios, 'Invitado externo') AS ingenio,
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    if ($accion === 'crear_evento' && $puedeGestionar) {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo = trim((string) ($_POST['tipo'] ?? 'Capacitación'));
        $modalidadPago = cengi_evento_modalidad_pago($_POST['modalidad_pago'] ?? 'Gratuito');
        $costo = $modalidadPago === 'Pagado' ? max(0, (float) ($_POST['costo'] ?? 0)) : 0;
        $fecha = trim((string) ($_POST['fecha'] ?? '')) ?: null;
        if ($nombre !== '' && ($modalidadPago === 'Gratuito' || $costo > 0)) {
            $stmt = $db->prepare("INSERT INTO eventos (nombre, tipo, modalidad_pago, costo, fecha, estado, creado_por) VALUES (?, ?, ?, ?, ?, 'Planificado', ?)");
            $stmt->execute([$nombre, $tipo, $modalidadPago, $costo, $fecha, cengi_usuario_actual_id()]);
            $mensaje = 'Evento creado correctamente.';
        } else {
            $mensaje = $modalidadPago === 'Pagado' ? 'Ingresa un costo mayor que cero para el evento pagado.' : 'Escribe el nombre del evento.';
            $mensajeTipo = 'error';
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
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL) AS ingresos
    FROM eventos e ORDER BY e.fecha DESC, e.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

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

$ejemploParticipante = $db->query("
    SELECT ep.nombre_invitado AS nombre, ep.codigo_qr, e.nombre AS evento,
           COALESCE(i.nombre_ingenios, 'Invitado externo') AS ingenio
    FROM evento_participantes ep INNER JOIN eventos e ON e.id = ep.evento_id
    LEFT JOIN participantes p ON p.id = ep.participante_id
    LEFT JOIN ingenios i ON i.id = p.ingenio_id
    ORDER BY e.fecha DESC, ep.id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<html lang="es">
<?php include('head.php'); ?>
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

    <div class="cengi-kpi-grid cengi-event-stats" aria-label="Estadísticas de eventos por modalidad de pago">
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-primary);"></div>
            <div class="cengi-kpi-icon"><span class="glyphicon glyphicon-calendar"></span></div>
            <div class="cengi-kpi-val"><?php echo $totalEventos; ?></div>
            <div class="cengi-kpi-label">Eventos registrados</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:#73BC25;"></div>
            <div class="cengi-kpi-icon" style="background:#EAF6DD;color:#326B00;"><span class="glyphicon glyphicon-gift"></span></div>
            <div class="cengi-kpi-val"><?php echo $eventosGratuitos; ?></div>
            <div class="cengi-kpi-label">Eventos gratuitos</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-amarillo);"></div>
            <div class="cengi-kpi-icon" style="background:#FFF6DA;color:#8A6600;"><span class="glyphicon glyphicon-usd"></span></div>
            <div class="cengi-kpi-val"><?php echo $eventosPagados; ?></div>
            <div class="cengi-kpi-label">Eventos pagados</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-naranja);"></div>
            <div class="cengi-kpi-icon" style="background:#FFE9D9;color:#B34E00;"><span class="glyphicon glyphicon-stats"></span></div>
            <div class="cengi-kpi-val"><?php echo $porcentajeGratuitos; ?>%</div>
            <div class="cengi-kpi-label">Proporción de eventos gratuitos</div>
        </div>
    </div>

    <div class="cengi-two-col">
        <div>
            <div class="panel panel-success cengi-event-card">
                <div class="panel-heading cengi-event-heading">
                    <div>
                        <h3 class="panel-title">Eventos con control QR</h3>
                        <small>Seminarios y talleres con registro de ingreso por código QR</small>
                    </div>
                    <?php if ($puedeGestionar): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#evtModal"><span class="glyphicon glyphicon-plus"></span> Nuevo evento</button>
                    <?php endif; ?>
                </div>
                <div class="panel-body cengi-event-table-body">
                    <div class="cengi-table-wrap">
                        <table class="table cengi-events-table">
                            <thead><tr><th>Evento</th><th>Fecha</th><th>Acceso</th><th>Registrados</th><th>Ingresos QR</th><th>% Asistencia</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            <?php if (!$eventos): ?><tr><td colspan="8" class="text-center cengi-empty-cell">No hay eventos registrados todavía.</td></tr><?php endif; ?>
                            <?php foreach ($eventos as $evt): ?>
                                <?php $pct = (int) $evt['registrados'] > 0 ? round(((int) $evt['ingresos'] / (int) $evt['registrados']) * 100) : 0; ?>
                                <tr>
                                    <td><strong><?php echo cengi_evt_html($evt['nombre']); ?></strong><br><small class="text-muted"><?php echo cengi_evt_html($evt['tipo']); ?></small></td>
                                    <td><?php echo cengi_evt_html($evt['fecha'] ?: '—'); ?></td>
                                    <td>
                                        <span class="cengi-payment-badge <?php echo $evt['modalidad_pago'] === 'Pagado' ? 'is-paid' : 'is-free'; ?>">
                                            <?php echo cengi_evt_html($evt['modalidad_pago']); ?>
                                        </span>
                                        <?php if ($evt['modalidad_pago'] === 'Pagado'): ?><small class="cengi-event-cost">Q <?php echo number_format((float) $evt['costo'], 2); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $evt['registrados']; ?></td>
                                    <td><?php echo (int) $evt['ingresos']; ?></td>
                                    <td><div class="cengi-event-progress"><div class="cengi-progress-track"><div class="cengi-progress-fill" style="width:<?php echo (int) $pct; ?>%;"></div></div><span><?php echo (int) $pct; ?>%</span></div></td>
                                    <td><span class="cengi-status-badge <?php echo cengi_evt_estado_badge($evt['estado']); ?>"><i></i><?php echo cengi_evt_html($evt['estado']); ?></span></td>
                                    <td>
                                        <button type="button" class="btn btn-default btn-sm cengi-view-participants" onclick="cengiEvtAbrirParticipantes(<?php echo (int) $evt['id']; ?>)">Ver participantes</button>
                                        <?php if ($puedeGestionar): ?>
                                        <button type="button" class="btn btn-default btn-sm" title="Copiar enlace público de inscripción" onclick="cengiEvtEnlaceInscripcion(<?php echo (int) $evt['id']; ?>)"><span class="glyphicon glyphicon-link"></span></button>
                                        <button type="button" class="btn btn-default btn-sm" title="Enlace de escaneo en la entrada" onclick="cengiEvtEnlaceEscaneo(<?php echo (int) $evt['id']; ?>)"><span class="glyphicon glyphicon-camera"></span></button>
                                        <form method="POST" class="cengi-inline-entry-form" data-evento-nombre="<?php echo cengi_evt_html($evt['nombre']); ?>" onsubmit="return cengiEvtConfirmarEliminarEvento(this);">
                                            <input type="hidden" name="accion" value="eliminar_evento">
                                            <input type="hidden" name="evento_id" value="<?php echo (int) $evt['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Eliminar evento"><span class="glyphicon glyphicon-trash"></span></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel panel-success cengi-event-card">
                <div class="panel-heading"><h3 class="panel-title">Flujo de registro por QR</h3></div>
                <div class="panel-body">
                    <div class="cengi-rail">
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-dot">1</div><div class="cengi-rail-label">Participante se registra</div></div>
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">2</div><div class="cengi-rail-label">Sistema genera QR</div></div>
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">3</div><div class="cengi-rail-label">QR en gafete</div></div>
                        <div class="cengi-rail-step is-now"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">4</div><div class="cengi-rail-label">Ingreso escaneando QR</div></div>
                        <div class="cengi-rail-step"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">5</div><div class="cengi-rail-label">Registro automático</div></div>
                    </div>
                    <div class="cengi-notice cengi-qr-notice"><span class="glyphicon glyphicon-info-sign"></span><span>Cada participante recibe un QR único y escaneable. En “Ver participantes” puedes abrirlo, descargarlo o marcar manualmente su ingreso.</span></div>
                </div>
            </div>
        </div>

        <div class="panel panel-success cengi-event-card">
            <div class="panel-heading"><h3 class="panel-title">Gafete de ejemplo</h3></div>
            <div class="panel-body cengi-example-badge-wrap">
                <div class="cengi-badge-card">
                    <div class="bc-top"><div class="cengi-badge-eyebrow">CENGICAÑA · Evento técnico</div><div class="cengi-badge-event"><?php echo cengi_evt_html($ejemploParticipante['evento'] ?? 'Sin eventos registrados'); ?></div></div>
                    <div class="bc-body">
                        <div class="cengi-badge-name"><?php echo cengi_evt_html($ejemploParticipante['nombre'] ?? 'Nombre del participante'); ?></div>
                        <div class="cengi-badge-company"><?php echo cengi_evt_html($ejemploParticipante['ingenio'] ?? 'Ingenio / institución'); ?></div>
                        <div class="cengi-qr-box" id="cengiQrEjemplo" data-codigo="<?php echo cengi_evt_html($ejemploParticipante['codigo_qr'] ?? 'EVT-' . date('Y') . '-0000'); ?>"></div>
                        <div class="mono cengi-badge-code"><?php echo cengi_evt_html($ejemploParticipante['codigo_qr'] ?? 'EVT-' . date('Y') . '-0000'); ?></div>
                    </div>
                </div>
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
                <div class="cengi-modal-hint">Registro individual de participantes y código QR</div>
            </div>
            <div class="modal-body cengi-participants-modal-body">
                <div class="cengi-participants-toolbar">
                    <input type="search" id="evpBuscar" class="form-control cengi-participant-search" placeholder="Buscar participante, ingenio o código QR…">
                    <button type="button" class="btn btn-default btn-sm" id="evpDescargar"><span class="glyphicon glyphicon-download-alt"></span> Descargar listado</button>
                    <?php if ($puedeGestionar): ?>
                    <button type="button" class="btn btn-default btn-sm" id="evpEnviarGafetes" disabled><span class="glyphicon glyphicon-envelope"></span> Enviar gafete <span id="evpSeleccionCount">(0)</span></button>
                    <button type="button" class="btn btn-default btn-sm" id="evpMostrarCargaMasiva"><span class="glyphicon glyphicon-upload"></span> Carga masiva</button>
                    <button type="button" class="btn btn-primary btn-sm" id="evpMostrarRegistro"><span class="glyphicon glyphicon-plus"></span> Registrar participante</button>
                    <?php endif; ?>
                </div>

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
                            <th>Participante</th><th>Ingenio</th><th>Correo</th><th>Código QR</th><th>Pago</th><th>Ingreso</th><th></th>
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
<div class="modal fade" id="evtModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><form method="POST">
        <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title">Nuevo evento</h4></div>
        <div class="modal-body">
            <input type="hidden" name="accion" value="crear_evento">
            <div class="cengi-form-grid">
                <div class="form-group cengi-form-full"><label class="control-label">Nombre del evento</label><input type="text" name="nombre" class="form-control" required></div>
                <div class="form-group"><label class="control-label">Tipo</label><select name="tipo" class="form-control"><option>Capacitación</option><option>Seminario</option><option>Taller</option><option>Evento técnico</option><option>Feria</option></select></div>
                <div class="form-group"><label class="control-label">Fecha</label><input type="date" name="fecha" class="form-control"></div>
                <div class="form-group"><label class="control-label">Modalidad de acceso</label><select name="modalidad_pago" id="evtModalidadPago" class="form-control"><option value="Gratuito">Gratuito</option><option value="Pagado">Pagado</option></select></div>
                <div class="form-group" id="evtCostoGrupo" style="display:none;"><label class="control-label">Costo (Q)</label><input type="number" name="costo" id="evtCosto" class="form-control" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Guardar</button></div>
    </form></div></div>
</div>
<?php endif; ?>
<script src="js/qrcode-generator.js"></script>
<script>
(function () {
    'use strict';
    var eventoActual = null;
    var participantes = [];
    var qrActual = '';
    var puedeGestionar = <?php echo $puedeGestionar ? 'true' : 'false'; ?>;
    var seleccionados = {};

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

    function renderParticipantes() {
        var busqueda = ($('#evpBuscar').val() || '').toLowerCase().trim();
        var filtrados = participantes.filter(function (p) {
            return !busqueda || [p.nombre, p.ingenio, p.codigo_qr, p.cui, p.correo].join(' ').toLowerCase().indexOf(busqueda) !== -1;
        });
        $('#evpCargando').hide();
        $('#evpVacio').toggle(filtrados.length === 0).text(participantes.length === 0
            ? 'Todavía no hay participantes registrados en este evento.'
            : 'No se encontraron participantes con esa búsqueda.');
        $('#evpTablaWrap').toggle(filtrados.length > 0);

        $('#tablaEventoParticipantes').html(filtrados.map(function (p) {
            var indice = participantes.indexOf(p);
            var ingreso = p.ingreso_en
                ? '<span class="cengi-status-badge is-active"><i></i>Ingresó</span><small class="cengi-entry-time">' + escapeHtml(p.ingreso_en) + '</small>'
                : '<span class="cengi-status-badge is-neutral"><i></i>Sin ingreso</span>';
            var esEventoPagado = !!(eventoActual && eventoActual.modalidad_pago === 'Pagado');
            var pagoBadge = !esEventoPagado
                ? '<span class="cengi-status-badge is-neutral" title="El evento es gratuito"><i></i>No aplica</span>'
                : (Number(p.pagado)
                    ? '<span class="cengi-status-badge is-active"><i></i>Pagado</span>'
                    : '<span class="cengi-status-badge is-rejected"><i></i>No pagado</span>');
            var pagoToggle = (esEventoPagado && puedeGestionar)
                ? '<form method="POST" class="cengi-inline-entry-form"><input type="hidden" name="accion" value="marcar_pago"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><input type="hidden" name="pagado" value="' + (Number(p.pagado) ? 0 : 1) + '"><button type="submit" class="btn btn-default btn-xs">' + (Number(p.pagado) ? 'Marcar no pagado' : 'Marcar pagado') + '</button></form>'
                : '';
            var marcar = (!p.ingreso_en && puedeGestionar)
                ? '<form method="POST" class="cengi-inline-entry-form"><input type="hidden" name="accion" value="marcar_ingreso"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><button type="submit" class="btn btn-success btn-xs">Marcar ingreso</button></form>'
                : '';
            var check = puedeGestionar
                ? '<td class="cengi-td-check"><input type="checkbox" class="cengi-participant-check" data-id="' + Number(p.id) + '"' + (seleccionados[p.id] ? ' checked' : '') + '></td>'
                : '';
            var editar = puedeGestionar
                ? '<button type="button" class="cengi-action-btn is-edit" title="Editar participante" onclick="cengiEvtEditarParticipante(' + indice + ')"><span class="glyphicon glyphicon-pencil"></span></button>'
                : '';
            var eliminar = puedeGestionar
                ? '<form method="POST" class="cengi-inline-entry-form" data-participante-nombre="' + escapeHtml(p.nombre) + '" onsubmit="return cengiEvtConfirmarEliminarParticipante(this);"><input type="hidden" name="accion" value="eliminar_participante"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><button type="submit" class="cengi-action-btn is-delete" title="Eliminar participante"><span class="glyphicon glyphicon-trash"></span></button></form>'
                : '';
            return '<tr>' + check +
                '<td class="cengi-td-participante"><div class="cengi-person-cell"><span class="cengi-avatar-sm">' + escapeHtml(iniciales(p.nombre)) + '</span><span><strong>' + escapeHtml(p.nombre) + '</strong>' + (p.cui ? '<small>CUI ' + escapeHtml(p.cui) + '</small>' : '') + '</span></div></td>' +
                '<td class="cengi-td-ingenio">' + escapeHtml(p.ingenio) + '</td>' +
                '<td class="cengi-td-correo">' + (p.correo ? escapeHtml(p.correo) : '<span class="text-muted">Sin correo</span>') + '</td>' +
                '<td class="cengi-td-qr"><div class="cengi-person-qr"><span class="cengi-mini-qr" data-codigo="' + escapeHtml(p.codigo_qr) + '"></span><span class="mono">' + escapeHtml(p.codigo_qr) + '</span></div></td>' +
                '<td class="cengi-td-pago">' + pagoBadge + pagoToggle + '</td>' +
                '<td class="cengi-td-ingreso">' + ingreso + marcar + '</td>' +
                '<td class="cengi-td-acciones"><button type="button" class="cengi-action-btn is-view" title="Ver QR y gafete" onclick="cengiEvtVerQr(' + indice + ')"><span class="glyphicon glyphicon-qrcode"></span></button>' + editar + eliminar + '</td></tr>';
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
    $('#evpMostrarRegistro').on('click', function () { $('#evpCargaMasivaForm').slideUp(150); resetearFormularioRegistro(); $('#evpRegistroForm').slideDown(150); });
    $('#evpCancelarRegistro').on('click', function () { $('#evpRegistroForm').slideUp(150); resetearFormularioRegistro(); });
    $('#evpMostrarCargaMasiva').on('click', function () { $('#evpRegistroForm').slideUp(150); resetearFormularioRegistro(); $('#evpCargaMasivaForm').slideDown(150); });
    $('#evpCancelarCargaMasiva').on('click', function () { $('#evpCargaMasivaForm').slideUp(150); });

    $(document).on('change', '#evpSeleccionarTodos', function () {
        var marcar = this.checked;
        var busqueda = ($('#evpBuscar').val() || '').toLowerCase().trim();
        var filtrados = participantes.filter(function (p) {
            return !busqueda || [p.nombre, p.ingenio, p.codigo_qr, p.cui, p.correo].join(' ').toLowerCase().indexOf(busqueda) !== -1;
        });
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
        var eventoPagado = !!(eventoActual && eventoActual.modalidad_pago === 'Pagado');
        var filas = [['Participante', 'CUI', 'Ingenio', 'Correo', 'Código QR', 'Pago', 'Ingreso']].concat(participantes.map(function (p) {
            var pago = eventoPagado ? (Number(p.pagado) ? 'Pagado' : 'No pagado') : 'No aplica';
            return [p.nombre, p.cui || '', p.ingenio, p.correo || '', p.codigo_qr, pago, p.ingreso_en || 'Sin ingreso'];
        }));
        var csv = '\uFEFF' + filas.map(function (fila) {
            return fila.map(function (dato) { return '"' + String(dato).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\r\n');
        var enlace = document.createElement('a');
        enlace.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
        enlace.download = 'participantes-evento-' + eventoActual.id + '.csv';
        enlace.click();
        URL.revokeObjectURL(enlace.href);
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

    $('#evtModalidadPago').on('change', function () {
        var esPagado = this.value === 'Pagado';
        $('#evtCostoGrupo').toggle(esPagado);
        $('#evtCosto').prop('required', esPagado);
        if (!esPagado) $('#evtCosto').val('');
    });

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
