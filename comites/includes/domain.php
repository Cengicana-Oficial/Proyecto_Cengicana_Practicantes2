<?php
declare(strict_types=1);

// Helpers de dominio (no de autenticacion) para el modulo Comites,
// Transferencia y Comunicacion: subida de archivos, plantillas descargables
// y utilidades de presentacion (badges de estado). Mismo patron de manejo de
// errores de subida que cengicursos/instructores.php.

/**
 * Traduce los codigos de error de subida de PHP (UPLOAD_ERR_*) a un mensaje
 * legible para el usuario.
 */
function comites_mensaje_error_subida(int $codigoError): string
{
    switch ($codigoError) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño máximo permitido para subir.';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subió solo parcialmente. Intenta nuevamente.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'El servidor no pudo procesar el archivo. Contacta al administrador.';
        default:
            return 'No fue posible subir el archivo.';
    }
}

/**
 * Guarda un archivo subido (ayuda memoria, etc.) en uploads/comites/<subcarpeta>/
 * en la raiz del repo, con el mismo manejo de errores que
 * cengicursos/instructores.php. Devuelve ['nombre'=>?, 'ruta'=>?, 'error'=>?].
 * 'ruta' queda relativa desde comites/ (p. ej. "../uploads/comites/reuniones/xxx.pdf"),
 * igual que cv_path en cengicursos/instructores.php.
 */
function comites_guardar_archivo(array $file, string $subcarpeta, array $extensionesPermitidas): array
{
    $resultado = ['nombre' => null, 'ruta' => null, 'error' => null];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $resultado;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $resultado['error'] = comites_mensaje_error_subida((int) $file['error']);
        return $resultado;
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $extensionesPermitidas, true)) {
        $resultado['error'] = 'Formato no permitido. Usa: ' . strtoupper(implode(', ', $extensionesPermitidas)) . '.';
        return $resultado;
    }

    $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $file['name']);
    $directorioDestino = '../uploads/comites/' . $subcarpeta;
    $ruta = $directorioDestino . '/' . $nombreArchivo;

    if (!is_dir($directorioDestino)) {
        $creado = @mkdir($directorioDestino, 0775, true);
        if (!$creado && !is_dir($directorioDestino)) {
            $resultado['error'] = 'El directorio de subida no existe y no pudo crearse en el servidor.';
            error_log(sprintf(
                'comites: no se pudo crear el directorio de subida "%s" (revisar permisos del volumen montado en el host).',
                $directorioDestino
            ));
            return $resultado;
        }
    }

    if (!is_writable($directorioDestino)) {
        $resultado['error'] = 'El directorio de subida no tiene permisos de escritura en el servidor.';
        error_log(sprintf(
            'comites: el directorio de subida "%s" no es escribible por el usuario del servidor web.',
            realpath($directorioDestino) ?: $directorioDestino
        ));
        return $resultado;
    }

    if (move_uploaded_file($file['tmp_name'], $ruta)) {
        $resultado['nombre'] = (string) $file['name'];
        $resultado['ruta'] = $ruta;
    } else {
        $resultado['error'] = 'No fue posible guardar el archivo en el servidor.';
        $ultimoError = error_get_last();
        error_log(sprintf(
            'comites: move_uploaded_file("%s", "%s") fallo. Ultimo error PHP: %s',
            $file['tmp_name'],
            $ruta,
            $ultimoError['message'] ?? 'desconocido'
        ));
    }

    return $resultado;
}

function comites_estado_badge_class(string $estado): string
{
    $map = [
        'Activo' => 'badge-activo',
        'Inactivo' => 'badge-inactivo',
        'Pendiente' => 'badge-pendiente',
        'En proceso' => 'badge-en-proceso',
        'Completado' => 'badge-completado',
        // Documentos / memorias (flujo de revision-aprobacion).
        'En revision' => 'badge-en-proceso',
        'Recibida' => 'badge-pendiente',
        'Aprobado' => 'badge-completado',
        'Aprobada' => 'badge-completado',
        'Rechazado' => 'badge-rechazado',
        'Rechazada' => 'badge-rechazado',
        'Publicado' => 'badge-completado',
        'Con observaciones' => 'badge-pendiente',
        'Satisfactorio' => 'badge-completado',
        'No satisfactorio' => 'badge-rechazado',
        // Publicaciones en redes.
        'Borrador' => 'badge-borrador',
        'Programada' => 'badge-programada',
        'Publicada' => 'badge-publicada',
        'Pausada' => 'badge-pausada',
    ];

    return $map[$estado] ?? 'badge-inactivo';
}

/**
 * Descarga la plantilla CSV de ejemplo para carga de contactos (equivalente a
 * exportTemplate() en el prototipo JSX). Termina la ejecucion.
 */
function comites_descargar_plantilla_contactos(): void
{
    $filas = [
        ['Nombre completo', 'Cargo', 'Empresa / ingenio', 'Telefono', 'Correo', 'Comites', 'Estado'],
        ['Ing. Nombre Apellido', 'Cargo', 'Empresa', '5555-0000', 'correo@empresa.com', 'riego;variedades', 'Activo'],
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_directorio_contactos.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para que Excel detecte acentos correctamente.
    foreach ($filas as $fila) {
        fputcsv($out, $fila);
    }
    fclose($out);
    exit;
}

/**
 * Descarga la plantilla de ayuda memoria como documento .doc (HTML), igual
 * que downloadMeetingTemplate() en el prototipo JSX. Termina la ejecucion.
 */
function comites_descargar_plantilla_reunion(): void
{
    $contenido = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>Plantilla Ayuda Memoria CENGICANA</title></head><body>'
        . '<h1>AYUDA MEMORIA DE REUNION</h1>'
        . '<p><b>Comite:</b> ______________________________</p>'
        . '<p><b>Fecha:</b> ______________________________</p>'
        . '<p><b>Hora / lugar:</b> ______________________________</p>'
        . '<h2>1. Participantes</h2><p>__________________________________________________________________</p>'
        . '<h2>2. Temas tratados</h2><p>__________________________________________________________________</p>'
        . '<h2>3. Acuerdos</h2>'
        . '<table border="1" cellpadding="6" cellspacing="0" width="100%">'
        . '<tr><th>No.</th><th>Acuerdo</th><th>Responsable</th><th>Fecha seguimiento</th></tr>'
        . '<tr><td>1</td><td></td><td></td><td></td></tr>'
        . '<tr><td>2</td><td></td><td></td><td></td></tr>'
        . '</table>'
        . '<h2>4. Proximos pasos</h2><p>__________________________________________________________________</p>'
        . '<h2>5. Observaciones</h2><p>__________________________________________________________________</p>'
        . '</body></html>';

    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="Plantilla_Ayuda_Memoria_CENGICANA.doc"');
    echo $contenido;
    exit;
}

/** IDs de comites (array de strings) a los que pertenece un contacto. */
function comites_contacto_comite_ids(PDO $pdo, int $contactoId): array
{
    $stmt = $pdo->prepare('SELECT comite_id FROM contacto_comite WHERE contacto_id = ?');
    $stmt->execute([$contactoId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** IDs de contactos (array de ints) asistentes a una reunion. */
function comites_reunion_asistente_ids(PDO $pdo, int $reunionId): array
{
    $stmt = $pdo->prepare('SELECT contacto_id FROM reunion_asistentes WHERE reunion_id = ?');
    $stmt->execute([$reunionId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Sincroniza la tabla puente contacto_comite con la lista de ids enviada. */
function comites_sync_contacto_comites(PDO $pdo, int $contactoId, array $comiteIds): void
{
    $pdo->prepare('DELETE FROM contacto_comite WHERE contacto_id = ?')->execute([$contactoId]);

    if (!$comiteIds) {
        return;
    }

    $insert = $pdo->prepare('INSERT IGNORE INTO contacto_comite (contacto_id, comite_id) VALUES (?, ?)');
    foreach (array_unique($comiteIds) as $comiteId) {
        $insert->execute([$contactoId, $comiteId]);
    }
}

/** Sincroniza reunion_asistentes con la lista de ids de contacto enviada. */
function comites_sync_reunion_asistentes(PDO $pdo, int $reunionId, array $contactoIds): void
{
    $pdo->prepare('DELETE FROM reunion_asistentes WHERE reunion_id = ?')->execute([$reunionId]);

    if (!$contactoIds) {
        return;
    }

    $insert = $pdo->prepare('INSERT IGNORE INTO reunion_asistentes (reunion_id, contacto_id) VALUES (?, ?)');
    foreach (array_unique($contactoIds) as $contactoId) {
        $insert->execute([$reunionId, (int) $contactoId]);
    }
}

/**
 * Sincroniza los acuerdos ligados a una reunion (reunion_id) con el detalle
 * enviado desde el editor embebido del formulario de reunion: crea, actualiza
 * y elimina filas de `acuerdos` segun corresponda. Equivalente a
 * updateAgreements()/saveItem() en el prototipo JSX.
 *
 * @param array<int,array{id:int,acuerdo:string,responsable:string,plazo:string,fecha_seguimiento:string,avance:int,estado:string,observaciones:string}> $detalle
 */
function comites_sync_reunion_acuerdos(PDO $pdo, int $reunionId, string $comiteId, ?string $fecha, string $responsableReunion, array $detalle): void
{
    $existentes = $pdo->prepare('SELECT id FROM acuerdos WHERE reunion_id = ?');
    $existentes->execute([$reunionId]);
    $existentesIds = array_map('intval', $existentes->fetchAll(PDO::FETCH_COLUMN));

    $estadosValidos = ['Pendiente', 'En proceso', 'Completado'];
    $enviados = [];

    $update = $pdo->prepare(
        'UPDATE acuerdos SET comite_id = ?, fecha = ?, acuerdo = ?, responsable = ?, plazo = ?, fecha_seguimiento = ?, avance = ?, estado = ?, observaciones = ?
         WHERE id = ? AND reunion_id = ?'
    );
    $insert = $pdo->prepare(
        'INSERT INTO acuerdos (comite_id, reunion_id, fecha, acuerdo, responsable, plazo, fecha_seguimiento, avance, estado, observaciones)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($detalle as $fila) {
        $texto = trim((string) ($fila['acuerdo'] ?? ''));
        if ($texto === '') {
            continue;
        }

        $responsable = trim((string) ($fila['responsable'] ?? '')) ?: $responsableReunion;
        $plazo = trim((string) ($fila['plazo'] ?? '')) ?: null;
        $fechaSeguimiento = trim((string) ($fila['fecha_seguimiento'] ?? '')) ?: null;
        $avance = max(0, min(100, (int) ($fila['avance'] ?? 0)));
        $estado = in_array($fila['estado'] ?? '', $estadosValidos, true) ? $fila['estado'] : 'Pendiente';
        $observaciones = trim((string) ($fila['observaciones'] ?? '')) ?: null;
        $rowId = (int) ($fila['id'] ?? 0);

        if ($rowId > 0 && in_array($rowId, $existentesIds, true)) {
            $update->execute([$comiteId, $fecha, $texto, $responsable, $plazo, $fechaSeguimiento, $avance, $estado, $observaciones, $rowId, $reunionId]);
            $enviados[] = $rowId;
        } else {
            $insert->execute([$comiteId, $reunionId, $fecha, $texto, $responsable, $plazo, $fechaSeguimiento, $avance, $estado, $observaciones]);
            $enviados[] = (int) $pdo->lastInsertId();
        }
    }

    $aEliminar = array_diff($existentesIds, $enviados);
    if ($aEliminar) {
        $placeholders = implode(',', array_fill(0, count($aEliminar), '?'));
        $pdo->prepare("DELETE FROM acuerdos WHERE reunion_id = ? AND id IN ({$placeholders})")
            ->execute(array_merge([$reunionId], array_values($aEliminar)));
    }
}

/** Construye el "view model" de una reunion a partir de una fila de BD + sus relaciones. */
function comites_reunion_vm_from_db(PDO $pdo, array $row): array
{
    $acuerdosStmt = $pdo->prepare(
        'SELECT id, acuerdo, responsable, plazo, fecha_seguimiento, avance, estado, observaciones
         FROM acuerdos WHERE reunion_id = ? ORDER BY id'
    );
    $acuerdosStmt->execute([(int) $row['id']]);

    return [
        'id' => (int) $row['id'],
        'comite_id' => (string) ($row['comite_id'] ?? ''),
        'fecha' => $row['fecha'] ?? date('Y-m-d'),
        'realizada' => (int) $row['realizada'],
        'temas' => (string) ($row['temas'] ?? ''),
        'proximos_pasos' => (string) ($row['proximos_pasos'] ?? ''),
        'responsable' => (string) ($row['responsable'] ?? ''),
        'ayuda_memoria_nombre' => $row['ayuda_memoria_nombre'] ?? null,
        'ayuda_memoria_ruta' => $row['ayuda_memoria_ruta'] ?? null,
        'asistentes' => comites_reunion_asistente_ids($pdo, (int) $row['id']),
        'acuerdos' => $acuerdosStmt->fetchAll(),
    ];
}

/** Construye el "view model" de una reunion a partir de un $_POST fallido (para reabrir el modal con lo digitado). */
function comites_reunion_vm_from_post(array $post): array
{
    $acuerdosRaw = is_array($post['acuerdos'] ?? null) ? $post['acuerdos'] : [];
    $acuerdos = [];
    foreach ($acuerdosRaw as $fila) {
        if (!is_array($fila)) {
            continue;
        }
        $acuerdos[] = [
            'id' => (int) ($fila['id'] ?? 0),
            'acuerdo' => (string) ($fila['acuerdo'] ?? ''),
            'responsable' => (string) ($fila['responsable'] ?? ''),
            'plazo' => (string) ($fila['plazo'] ?? ''),
            'fecha_seguimiento' => (string) ($fila['fecha_seguimiento'] ?? ''),
            'avance' => (int) ($fila['avance'] ?? 0),
            'estado' => (string) ($fila['estado'] ?? 'Pendiente'),
            'observaciones' => (string) ($fila['observaciones'] ?? ''),
        ];
    }

    return [
        'id' => (int) ($post['id'] ?? 0),
        'comite_id' => (string) ($post['comite_id'] ?? ''),
        'fecha' => (string) ($post['fecha'] ?? date('Y-m-d')),
        'realizada' => ($post['realizada'] ?? 'realizada') === 'realizada' ? 1 : 0,
        'temas' => (string) ($post['temas'] ?? ''),
        'proximos_pasos' => (string) ($post['proximos_pasos'] ?? ''),
        'responsable' => (string) ($post['responsable'] ?? ''),
        'ayuda_memoria_nombre' => null,
        'ayuda_memoria_ruta' => null,
        'asistentes' => array_map('intval', is_array($post['asistentes'] ?? null) ? $post['asistentes'] : []),
        'acuerdos' => $acuerdos,
    ];
}

// ===================== Documentos (biblioteca ingenios / interna) =====================

/** Registra un cambio de estado en el historial de un documento (equivalente a addHistory() en el prototipo JSX). */
function comites_documento_log_historial(PDO $pdo, int $documentoId, string $estado, string $actor, ?string $observaciones): void
{
    $pdo->prepare(
        'INSERT INTO documento_historial (documento_id, estado, actor, fecha, observaciones) VALUES (?, ?, ?, CURDATE(), ?)'
    )->execute([$documentoId, $estado, $actor, $observaciones]);
}

/** Historial completo (mas reciente primero) de un documento, para mostrarlo en su vista de seguimiento. */
function comites_documento_historial(PDO $pdo, int $documentoId): array
{
    $stmt = $pdo->prepare('SELECT * FROM documento_historial WHERE documento_id = ? ORDER BY creado_en DESC, id DESC');
    $stmt->execute([$documentoId]);

    return $stmt->fetchAll();
}

// ===================== Carpetas de la biblioteca documental =====================
// Sistema de carpetas anidadas (documento_carpetas) para las dos secciones que
// comparten el modelo `documentos`: "documentos" (ambito=ingenios) y
// "biblioteca_interna" (ambito=interna). Cada ambito tiene su propio arbol de
// carpetas (nunca se mezclan), equivalente al sistema de carpetas con parentId
// del prototipo JSX (funcion Documentos, ~linea 319-347).

/**
 * Crea una carpeta dentro de un ambito y, opcionalmente, dentro de una
 * carpeta padre ($parentId = null => carpeta de nivel raiz). Devuelve el id
 * de la carpeta creada.
 */
function comites_carpeta_crear(PDO $pdo, string $ambito, string $nombre, ?int $parentId): int
{
    $pdo->prepare('INSERT INTO documento_carpetas (ambito, nombre, parent_id) VALUES (?, ?, ?)')
        ->execute([$ambito, $nombre, $parentId]);

    return (int) $pdo->lastInsertId();
}

/**
 * Obtiene una carpeta por id, verificando que pertenezca al ambito indicado
 * (para no permitir mezclar carpetas de "documentos" con las de
 * "biblioteca_interna"). Devuelve null si no existe o no coincide el ambito.
 */
function comites_carpeta_obtener(PDO $pdo, int $id, string $ambito): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM documento_carpetas WHERE id = ? AND ambito = ?');
    $stmt->execute([$id, $ambito]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Subcarpetas directas de $parentId (o de nivel raiz si $parentId es null)
 * dentro de un ambito, con el conteo de documentos que contiene cada una.
 * Equivalente a las "tarjetas de carpeta" (FolderButton) del prototipo JSX.
 */
function comites_carpeta_hijas(PDO $pdo, string $ambito, ?int $parentId): array
{
    $condicionPadre = $parentId === null ? 'dc.parent_id IS NULL' : 'dc.parent_id = ?';
    $sql = "SELECT dc.*, (SELECT COUNT(*) FROM documentos d WHERE d.carpeta_id = dc.id) AS total_documentos
            FROM documento_carpetas dc
            WHERE dc.ambito = ? AND {$condicionPadre}
            ORDER BY dc.nombre";

    $params = $parentId === null ? [$ambito] : [$ambito, $parentId];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Ruta (breadcrumb) desde la raiz hasta la carpeta $carpetaId, en orden
 * raiz -> ... -> carpeta actual. Devuelve [] si $carpetaId es null.
 */
function comites_carpeta_breadcrumb(PDO $pdo, ?int $carpetaId): array
{
    $breadcrumb = [];
    if ($carpetaId === null) {
        return $breadcrumb;
    }

    $stmt = $pdo->prepare('SELECT id, nombre, parent_id FROM documento_carpetas WHERE id = ?');

    $currentId = $carpetaId;
    $vueltas = 0; // salvaguarda ante datos corruptos con jerarquias circulares.
    while ($currentId !== null && $vueltas < 50) {
        $stmt->execute([$currentId]);
        $row = $stmt->fetch();
        if (!$row) {
            break;
        }
        array_unshift($breadcrumb, $row);
        $currentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $vueltas++;
    }

    return $breadcrumb;
}

// ===================== Informes cuatrimestrales =====================

/** Agrega un soporte/evidencia adicional (archivo) a un informe cuatrimestral ya guardado. */
function comites_informe_agregar_soporte(PDO $pdo, int $informeId, string $nombre, ?string $ruta): void
{
    $pdo->prepare('INSERT INTO informe_soportes (informe_id, nombre, ruta) VALUES (?, ?, ?)')
        ->execute([$informeId, $nombre, $ruta]);
}

/** Soportes/evidencias registrados para un informe cuatrimestral. */
function comites_informe_soportes(PDO $pdo, int $informeId): array
{
    $stmt = $pdo->prepare('SELECT * FROM informe_soportes WHERE informe_id = ? ORDER BY id DESC');
    $stmt->execute([$informeId]);

    return $stmt->fetchAll();
}

/**
 * Sube y registra en `informe_soportes` cada archivo del input multiple
 * name="soportes[]" de un formulario de informe cuatrimestral. Ignora los
 * slots vacios (UPLOAD_ERR_NO_FILE). Devuelve la lista de mensajes de error
 * de subida (vacia si todo salio bien).
 */
function comites_informe_guardar_soportes_multiples(PDO $pdo, int $informeId, array $filesSoportes): array
{
    $errores = [];
    $nombres = $filesSoportes['name'] ?? [];
    if (!is_array($nombres)) {
        return $errores;
    }

    $total = count($nombres);
    for ($i = 0; $i < $total; $i++) {
        if ((int) ($filesSoportes['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $archivoIndividual = [
            'name' => $filesSoportes['name'][$i] ?? '',
            'type' => $filesSoportes['type'][$i] ?? '',
            'tmp_name' => $filesSoportes['tmp_name'][$i] ?? '',
            'error' => $filesSoportes['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesSoportes['size'][$i] ?? 0,
        ];

        $subido = comites_guardar_archivo($archivoIndividual, 'informes', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']);
        if ($subido['ruta'] !== null) {
            comites_informe_agregar_soporte($pdo, $informeId, (string) $subido['nombre'], $subido['ruta']);
        } elseif ($subido['error'] !== null) {
            $errores[] = $subido['error'];
        }
    }

    return $errores;
}

// ===================== Memoria de resultados / comite editorial =====================

/** Registra una etapa en el track editorial de una memoria (equivalente a addTrack() en el prototipo JSX). */
function comites_memoria_log_track(PDO $pdo, int $memoriaId, string $etapa, ?string $estado, ?string $observaciones): void
{
    $pdo->prepare(
        'INSERT INTO memoria_track (memoria_id, etapa, fecha, estado, observaciones) VALUES (?, ?, CURDATE(), ?, ?)'
    )->execute([$memoriaId, $etapa, $estado, $observaciones]);
}

/** Track editorial completo (mas reciente primero) de una memoria. */
function comites_memoria_track(PDO $pdo, int $memoriaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM memoria_track WHERE memoria_id = ? ORDER BY creado_en DESC, id DESC');
    $stmt->execute([$memoriaId]);

    return $stmt->fetchAll();
}

// ===================== Correos y envios =====================

/**
 * Resuelve la lista de contactos (nombre + email) de un grupo de destinatarios
 * para el compositor de correos: "todos" los contactos activos con correo, o
 * los contactos activos con correo vinculados a un comite especifico.
 *
 * @return array<int,array{nombre:string,email:string}>
 */
function comites_destinatarios_grupo(PDO $pdo, string $grupo, array $comiteIdsValidos): array
{
    if ($grupo === 'todos') {
        return $pdo->query(
            "SELECT nombre, email FROM contactos WHERE estado = 'Activo' AND email IS NOT NULL AND email <> '' ORDER BY nombre"
        )->fetchAll();
    }

    if (in_array($grupo, $comiteIdsValidos, true)) {
        $stmt = $pdo->prepare(
            "SELECT c.nombre, c.email
             FROM contactos c
             INNER JOIN contacto_comite cc ON cc.contacto_id = c.id
             WHERE cc.comite_id = ? AND c.estado = 'Activo' AND c.email IS NOT NULL AND c.email <> ''
             ORDER BY c.nombre"
        );
        $stmt->execute([$grupo]);

        return $stmt->fetchAll();
    }

    return [];
}

/**
 * Carga PHPMailer reutilizando el vendor ya instalado en login/ (el modulo
 * comites/ no tiene composer.json propio y no debe agregar dependencias
 * nuevas). Devuelve false si el autoload no esta disponible en el servidor.
 */
function comites_cargar_phpmailer(): bool
{
    static $cargado = null;
    if ($cargado !== null) {
        return $cargado;
    }

    $autoload = dirname(__DIR__, 2) . '/login/vendor/autoload.php';
    if (!is_file($autoload)) {
        $cargado = false;
        return false;
    }

    require_once $autoload;
    $cargado = class_exists(\PHPMailer\PHPMailer\PHPMailer::class);

    return $cargado;
}

/**
 * Envia un correo HTML via SMTP/PHPMailer, mismo mecanismo (SMTP2GO, host/
 * puerto configurables por variables de entorno MAIL_*) que login/config/correo.php
 * y cengicursos/correo.php. Lee la configuracion con app_env_value() (definida
 * en comites/config/database.php), que ya revisa comites/.env, la raiz del
 * repo y login/.env como fuentes posibles.
 *
 * @return array{ok:bool,error:?string}
 */
function comites_enviar_correo(string $destinatario, string $nombre, string $asunto, string $cuerpoHtml): array
{
    if (!comites_cargar_phpmailer()) {
        return ['ok' => false, 'error' => 'El servicio de correo no esta disponible en este servidor.'];
    }

    $smtpHost = app_env_value('MAIL_HOST', 'mail.smtp2go.com');
    $smtpPort = (int) app_env_value('MAIL_PORT', '2525');
    $smtpUser = app_env_value('MAIL_USERNAME');
    $smtpPassword = app_env_value('MAIL_PASSWORD');
    $fromEmail = app_env_value('MAIL_FROM_ADDRESS');
    $fromName = app_env_value('MAIL_FROM_NAME', 'CENGICANA');

    if ($smtpUser === '' || $smtpPassword === '' || $fromEmail === '') {
        return ['ok' => false, 'error' => 'La configuracion de correo (MAIL_*) esta incompleta en el servidor.'];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($destinatario, $nombre);

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $cuerpoHtml;
        $mail->AltBody = strip_tags($cuerpoHtml);

        $mail->send();

        return ['ok' => true, 'error' => null];
    } catch (\Throwable $e) {
        error_log('comites: error enviando correo a "' . $destinatario . '": ' . $e->getMessage());

        return ['ok' => false, 'error' => 'No fue posible enviar el correo.'];
    }
}
