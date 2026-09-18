<?php
declare(strict_types=1);

// Modulo Comites, Transferencia y Comunicacion.
// Router por ?view= (mismo patron que sistema_de_solicitudes/index.php).
// Las 13 secciones del menu tienen CRUD real: contactos, reuniones, acuerdos,
// documentos (biblioteca ingenios / interna), correos y envios, tableros,
// informes cuatrimestrales, memoria de resultados (+ comite editorial),
// publicaciones (redes sociales), estadisticas (KPIs agregados de solo
// lectura) y configuracion del catalogo de comites.

session_start();

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/domain.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../login/login.php');
    exit;
}

$user = current_user($menuPdo, $pdo);
if (!$user) {
    header('Location: ../login/login.php');
    exit;
}

if (!user_has_module_access($user, $menuPdo)) {
    header('Location: ../login/Menu.php');
    exit;
}

$view = $_GET['view'] ?? 'dashboard';

// Definicion de las 13 secciones del prototipo (mismo orden/labels que el
// menu lateral del diseno de referencia).
$sections = [
    'dashboard' => ['label' => 'Inicio / Dashboard', 'icon' => 'ti-layout-dashboard', 'group' => 'General'],
    'contactos' => ['label' => 'Directorio de contactos', 'icon' => 'ti-users', 'group' => 'Comites'],
    'reuniones' => ['label' => 'Reuniones', 'icon' => 'ti-calendar-event', 'group' => 'Comites'],
    'acuerdos' => ['label' => 'Acuerdos y seguimiento', 'icon' => 'ti-clipboard-list', 'group' => 'Comites'],
    'documentos' => ['label' => 'Biblioteca documental para ingenios', 'icon' => 'ti-folder', 'group' => 'Documentacion'],
    'biblioteca_interna' => ['label' => 'Biblioteca documental interna', 'icon' => 'ti-folder-star', 'group' => 'Documentacion'],
    'correos' => ['label' => 'Correos y envios', 'icon' => 'ti-mail', 'group' => 'Documentacion'],
    'tableros' => ['label' => 'Tableros', 'icon' => 'ti-chart-bar', 'group' => 'Resultados'],
    'informes' => ['label' => 'Informes cuatrimestrales', 'icon' => 'ti-report', 'group' => 'Resultados'],
    'memorias' => ['label' => 'Memoria de resultados', 'icon' => 'ti-book-2', 'group' => 'Resultados'],
    'publicaciones' => ['label' => 'Redes sociales', 'icon' => 'ti-share', 'group' => 'Comunicacion'],
    'estadisticas' => ['label' => 'Estadisticas', 'icon' => 'ti-chart-pie', 'group' => 'Comunicacion'],
    'config' => ['label' => 'Configuracion', 'icon' => 'ti-settings', 'group' => 'Comunicacion'],
];

if (!array_key_exists($view, $sections)) {
    $view = 'dashboard';
}

// Nombre del recurso de permisos (comites.<recurso>.ver / .gestionar) por vista.
$recursoPorVista = [
    'contactos' => 'contactos',
    'reuniones' => 'reuniones',
    'acuerdos' => 'acuerdos',
    'documentos' => 'documentos',
    'biblioteca_interna' => 'biblioteca_interna',
    'correos' => 'correos',
    'tableros' => 'tableros',
    'informes' => 'informes',
    'memorias' => 'memorias',
    'publicaciones' => 'publicaciones',
    'estadisticas' => 'estadisticas',
    'config' => 'configuracion',
];

// Secciones con CRUD real implementado en esta fase.
$seccionesImplementadas = [
    'contactos', 'reuniones', 'acuerdos', 'documentos', 'biblioteca_interna',
    'correos', 'tableros', 'informes', 'memorias', 'publicaciones', 'estadisticas', 'config',
];

$puedeVerSeccionActual = $view === 'dashboard'
    || !isset($recursoPorVista[$view])
    || !in_array($view, $seccionesImplementadas, true)
    || can_view_resource($user, $recursoPorVista[$view]);
$puedeGestionarSeccionActual = isset($recursoPorVista[$view]) && can_manage_resource($user, $recursoPorVista[$view]);

// Descargas de plantillas (deben salir antes de cualquier output HTML).
if ($view === 'contactos' && ($_GET['accion'] ?? '') === 'plantilla') {
    if (!can_view_resource($user, 'contactos')) {
        header('Location: index.php?view=dashboard');
        exit;
    }
    comites_descargar_plantilla_contactos();
}
if ($view === 'reuniones' && ($_GET['accion'] ?? '') === 'plantilla') {
    if (!can_view_resource($user, 'reuniones')) {
        header('Location: index.php?view=dashboard');
        exit;
    }
    comites_descargar_plantilla_reunion();
}

$comitesTodos = $pdo->query('SELECT id, nombre, activo FROM comites ORDER BY nombre')->fetchAll();
$comitesActivos = array_values(array_filter($comitesTodos, static fn(array $c): bool => (int) $c['activo'] === 1));
$comiteIdsValidos = array_column($comitesTodos, 'id');
$comiteNombrePorId = array_column($comitesTodos, 'nombre', 'id');

// ===================== Manejo de acciones POST =====================
$flashError = '';
$reopenModal = null; // 'contacto' | 'reunion' | 'acuerdo'
$reopenId = 0;
$prefillPost = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    try {
        // ---------- Contactos ----------
        if ($accion === 'contacto_guardar') {
            if (!can_manage_resource($user, 'contactos')) {
                throw new RuntimeException('No tienes permiso para gestionar el directorio de contactos.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $cargo = trim((string) ($_POST['cargo'] ?? ''));
            $empresa = trim((string) ($_POST['empresa'] ?? ''));
            $telefono = trim((string) ($_POST['telefono'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $estado = ($_POST['estado'] ?? 'Activo') === 'Inactivo' ? 'Inactivo' : 'Activo';
            $comiteIds = array_values(array_intersect(
                is_array($_POST['comites'] ?? null) ? $_POST['comites'] : [],
                $comiteIdsValidos
            ));

            if ($nombre === '') {
                throw new RuntimeException('Escribe el nombre del contacto.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE contactos SET nombre = ?, cargo = ?, empresa = ?, telefono = ?, email = ?, estado = ? WHERE id = ?'
                );
                $stmt->execute([$nombre, $cargo ?: null, $empresa ?: null, $telefono ?: null, $email ?: null, $estado, $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO contactos (nombre, cargo, empresa, telefono, email, estado) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$nombre, $cargo ?: null, $empresa ?: null, $telefono ?: null, $email ?: null, $estado]);
                $id = (int) $pdo->lastInsertId();
            }

            comites_sync_contacto_comites($pdo, $id, $comiteIds);

            header('Location: index.php?view=contactos&msg=' . urlencode('Contacto guardado correctamente.'));
            exit;
        }

        if ($accion === 'contacto_eliminar') {
            if (!can_manage_resource($user, 'contactos')) {
                throw new RuntimeException('No tienes permiso para eliminar contactos.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Contacto no valido.');
            }
            $pdo->prepare('DELETE FROM contactos WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=contactos&msg=' . urlencode('Contacto eliminado.'));
            exit;
        }

        // ---------- Reuniones ----------
        if ($accion === 'reunion_guardar') {
            if (!can_manage_resource($user, 'reuniones')) {
                throw new RuntimeException('No tienes permiso para gestionar reuniones.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $comiteId = trim((string) ($_POST['comite_id'] ?? ''));
            $fecha = trim((string) ($_POST['fecha'] ?? '')) ?: null;
            $realizada = ($_POST['realizada'] ?? 'realizada') === 'realizada' ? 1 : 0;
            $temas = trim((string) ($_POST['temas'] ?? ''));
            $proximosPasos = trim((string) ($_POST['proximos_pasos'] ?? ''));
            $responsable = trim((string) ($_POST['responsable'] ?? ''));
            $asistentes = array_map('intval', is_array($_POST['asistentes'] ?? null) ? $_POST['asistentes'] : []);
            $detalleAcuerdos = is_array($_POST['acuerdos'] ?? null) ? $_POST['acuerdos'] : [];
            $quitarArchivo = isset($_POST['quitar_ayuda_memoria']);

            if (!in_array($comiteId, $comiteIdsValidos, true)) {
                throw new RuntimeException('Selecciona un comite valido.');
            }

            $archivo = comites_guardar_archivo($_FILES['ayuda_memoria'] ?? [], 'reuniones', ['pdf', 'doc', 'docx']);
            $avisoArchivo = '';
            if ($archivo['error'] !== null) {
                $avisoArchivo = ' La reunion se guardo, pero el archivo no se subio: ' . $archivo['error'];
            }

            if ($id > 0) {
                $actualStmt = $pdo->prepare('SELECT ayuda_memoria_nombre, ayuda_memoria_ruta FROM reuniones WHERE id = ?');
                $actualStmt->execute([$id]);
                $actual = $actualStmt->fetch();
                if (!$actual) {
                    throw new RuntimeException('La reunion que intentas editar ya no existe.');
                }

                $nombreArchivo = $actual['ayuda_memoria_nombre'];
                $rutaArchivo = $actual['ayuda_memoria_ruta'];
                if ($archivo['ruta'] !== null) {
                    $nombreArchivo = $archivo['nombre'];
                    $rutaArchivo = $archivo['ruta'];
                } elseif ($quitarArchivo) {
                    $nombreArchivo = null;
                    $rutaArchivo = null;
                }

                $stmt = $pdo->prepare(
                    'UPDATE reuniones SET comite_id = ?, fecha = ?, realizada = ?, temas = ?, proximos_pasos = ?, responsable = ?,
                        ayuda_memoria_nombre = ?, ayuda_memoria_ruta = ? WHERE id = ?'
                );
                $stmt->execute([$comiteId, $fecha, $realizada, $temas ?: null, $proximosPasos ?: null, $responsable ?: null, $nombreArchivo, $rutaArchivo, $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO reuniones (comite_id, fecha, realizada, temas, proximos_pasos, responsable, ayuda_memoria_nombre, ayuda_memoria_ruta)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$comiteId, $fecha, $realizada, $temas ?: null, $proximosPasos ?: null, $responsable ?: null, $archivo['nombre'], $archivo['ruta']]);
                $id = (int) $pdo->lastInsertId();
            }

            comites_sync_reunion_asistentes($pdo, $id, $asistentes);
            comites_sync_reunion_acuerdos($pdo, $id, $comiteId, $fecha, $responsable, $detalleAcuerdos);

            header('Location: index.php?view=reuniones&msg=' . urlencode('Reunion guardada correctamente.' . $avisoArchivo));
            exit;
        }

        if ($accion === 'reunion_eliminar') {
            if (!can_manage_resource($user, 'reuniones')) {
                throw new RuntimeException('No tienes permiso para eliminar reuniones.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Reunion no valida.');
            }
            $pdo->prepare('DELETE FROM reuniones WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=reuniones&msg=' . urlencode('Reunion eliminada.'));
            exit;
        }

        // ---------- Acuerdos ----------
        if ($accion === 'acuerdo_guardar') {
            if (!can_manage_resource($user, 'acuerdos')) {
                throw new RuntimeException('No tienes permiso para gestionar acuerdos.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $comiteId = trim((string) ($_POST['comite_id'] ?? ''));
            $fecha = trim((string) ($_POST['fecha'] ?? '')) ?: null;
            $texto = trim((string) ($_POST['acuerdo'] ?? ''));
            $responsable = trim((string) ($_POST['responsable'] ?? ''));
            $plazo = trim((string) ($_POST['plazo'] ?? '')) ?: null;
            $fechaSeguimiento = trim((string) ($_POST['fecha_seguimiento'] ?? '')) ?: null;
            $avance = max(0, min(100, (int) ($_POST['avance'] ?? 0)));
            $estado = in_array($_POST['estado'] ?? '', ['Pendiente', 'En proceso', 'Completado'], true) ? $_POST['estado'] : 'Pendiente';

            if (!in_array($comiteId, $comiteIdsValidos, true)) {
                throw new RuntimeException('Selecciona un comite valido.');
            }
            if ($texto === '') {
                throw new RuntimeException('Describe el acuerdo.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE acuerdos SET comite_id = ?, fecha = ?, acuerdo = ?, responsable = ?, plazo = ?, fecha_seguimiento = ?, avance = ?, estado = ? WHERE id = ?'
                );
                $stmt->execute([$comiteId, $fecha, $texto, $responsable ?: null, $plazo, $fechaSeguimiento, $avance, $estado, $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO acuerdos (comite_id, fecha, acuerdo, responsable, plazo, fecha_seguimiento, avance, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$comiteId, $fecha, $texto, $responsable ?: null, $plazo, $fechaSeguimiento, $avance, $estado]);
            }

            header('Location: index.php?view=acuerdos&msg=' . urlencode('Acuerdo guardado correctamente.'));
            exit;
        }

        if ($accion === 'acuerdo_eliminar') {
            if (!can_manage_resource($user, 'acuerdos')) {
                throw new RuntimeException('No tienes permiso para eliminar acuerdos.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Acuerdo no valido.');
            }
            $pdo->prepare('DELETE FROM acuerdos WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=acuerdos&msg=' . urlencode('Acuerdo eliminado.'));
            exit;
        }

        // ---------- Configuracion (catalogo de comites) ----------
        if ($accion === 'comite_guardar') {
            if (!can_manage_resource($user, 'configuracion')) {
                throw new RuntimeException('No tienes permiso para gestionar la configuracion del modulo.');
            }
            $id = trim((string) ($_POST['id'] ?? ''));
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $activo = isset($_POST['activo']) ? 1 : 0;

            if (!in_array($id, $comiteIdsValidos, true)) {
                throw new RuntimeException('Comite no valido.');
            }
            if ($nombre === '') {
                throw new RuntimeException('Escribe el nombre del comite.');
            }

            $pdo->prepare('UPDATE comites SET nombre = ?, activo = ? WHERE id = ?')->execute([$nombre, $activo, $id]);

            header('Location: index.php?view=config&msg=' . urlencode('Comite actualizado correctamente.'));
            exit;
        }

        // ---------- Documentos (biblioteca ingenios / biblioteca interna) ----------
        if ($accion === 'documento_guardar') {
            $ambito = ($_POST['ambito'] ?? 'ingenios') === 'interna' ? 'interna' : 'ingenios';
            $recurso = $ambito === 'interna' ? 'biblioteca_interna' : 'documentos';
            if (!can_manage_resource($user, $recurso)) {
                throw new RuntimeException('No tienes permiso para gestionar esta biblioteca documental.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $tiposValidos = ['Boletin', 'Memoria', 'Informe', 'Ayudamemoria', 'Presentacion', 'Fotografia', 'Video', 'Otro'];
            $tipo = in_array($_POST['tipo'] ?? '', $tiposValidos, true) ? $_POST['tipo'] : 'Otro';
            $titulo = trim((string) ($_POST['titulo'] ?? ''));
            $fecha = trim((string) ($_POST['fecha'] ?? '')) ?: null;
            $comiteIdDoc = trim((string) ($_POST['comite_id'] ?? ''));
            $comiteIdDoc = in_array($comiteIdDoc, $comiteIdsValidos, true) ? $comiteIdDoc : null;
            $autor = trim((string) ($_POST['autor'] ?? '')) ?: null;
            $enlace = trim((string) ($_POST['enlace'] ?? '')) ?: null;
            $descripcion = trim((string) ($_POST['descripcion'] ?? '')) ?: null;
            $estadosDocValidos = ['En revision', 'Aprobado', 'Con observaciones', 'Rechazado', 'Publicado'];
            $estadoDoc = in_array($_POST['estado'] ?? '', $estadosDocValidos, true) ? $_POST['estado'] : 'En revision';
            $causaRechazo = $estadoDoc === 'Rechazado' ? (trim((string) ($_POST['causa_rechazo'] ?? '')) ?: null) : null;
            $observacionesDoc = trim((string) ($_POST['observaciones'] ?? '')) ?: null;
            $habilitadoEnvio = isset($_POST['habilitado_envio']) ? 1 : 0;

            if ($titulo === '') {
                throw new RuntimeException('Escribe el titulo del documento.');
            }

            // La carpeta (carpeta_id) solo se fija al crear el documento: los
            // documentos se cargan unicamente dentro de una carpeta existente
            // (igual que el prototipo). No se permite mover un documento de
            // carpeta desde este formulario; `carpeta` (texto libre) queda sin
            // usarse en este flujo nuevo, por compatibilidad con datos previos.
            $carpetaIdDoc = (int) ($_POST['carpeta_id'] ?? 0);
            $carpetaIdDoc = $carpetaIdDoc > 0 ? $carpetaIdDoc : null;
            if ($id <= 0) {
                if ($carpetaIdDoc === null || !comites_carpeta_obtener($pdo, $carpetaIdDoc, $ambito)) {
                    throw new RuntimeException('Selecciona o crea una carpeta antes de agregar un documento.');
                }
            }

            $archivoDoc = comites_guardar_archivo(
                $_FILES['archivo'] ?? [],
                'documentos',
                ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png']
            );
            $avisoArchivo = '';
            if ($archivoDoc['error'] !== null) {
                $avisoArchivo = ' El documento se guardo, pero el archivo no se subio: ' . $archivoDoc['error'];
            }

            if ($id > 0) {
                $actualStmt = $pdo->prepare('SELECT ambito, estado, archivo_nombre, archivo_ruta, enlace, version FROM documentos WHERE id = ?');
                $actualStmt->execute([$id]);
                $actualDoc = $actualStmt->fetch();
                if (!$actualDoc) {
                    throw new RuntimeException('El documento que intentas editar ya no existe.');
                }
                if ($actualDoc['ambito'] !== $ambito) {
                    throw new RuntimeException('El ambito del documento no coincide con esta seccion.');
                }

                $nombreArchivoDoc = $actualDoc['archivo_nombre'];
                $rutaArchivoDoc = $actualDoc['archivo_ruta'];
                $version = (int) $actualDoc['version'];
                $huboCambioContenido = false;
                if ($archivoDoc['ruta'] !== null) {
                    $nombreArchivoDoc = $archivoDoc['nombre'];
                    $rutaArchivoDoc = $archivoDoc['ruta'];
                    $huboCambioContenido = true;
                }
                if ($enlace !== $actualDoc['enlace']) {
                    $huboCambioContenido = true;
                }
                if ($huboCambioContenido) {
                    $version++;
                }

                $stmt = $pdo->prepare(
                    'UPDATE documentos SET tipo = ?, titulo = ?, fecha = ?, comite_id = ?, autor = ?, enlace = ?, descripcion = ?,
                        archivo_nombre = ?, archivo_ruta = ?, version = ?, estado = ?, observaciones = ?, causa_rechazo = ?, habilitado_envio = ? WHERE id = ?'
                );
                $stmt->execute([
                    $tipo, $titulo, $fecha, $comiteIdDoc, $autor, $enlace, $descripcion,
                    $nombreArchivoDoc, $rutaArchivoDoc, $version, $estadoDoc, $observacionesDoc, $causaRechazo, $habilitadoEnvio, $id,
                ]);

                if ($estadoDoc !== $actualDoc['estado']) {
                    comites_documento_log_historial($pdo, $id, $estadoDoc, (string) $user['nombre'], $observacionesDoc);
                }
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO documentos (ambito, tipo, carpeta_id, titulo, fecha, comite_id, autor, enlace, descripcion, archivo_nombre, archivo_ruta, version, estado, observaciones, causa_rechazo, habilitado_envio)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $ambito, $tipo, $carpetaIdDoc, $titulo, $fecha, $comiteIdDoc, $autor, $enlace, $descripcion,
                    $archivoDoc['nombre'], $archivoDoc['ruta'], $estadoDoc, $observacionesDoc, $causaRechazo, $habilitadoEnvio,
                ]);
                $id = (int) $pdo->lastInsertId();
                comites_documento_log_historial($pdo, $id, $estadoDoc, (string) $user['nombre'], $observacionesDoc);
            }

            header('Location: index.php?view=' . $recurso . '&msg=' . urlencode('Documento guardado correctamente.' . $avisoArchivo));
            exit;
        }

        if ($accion === 'documento_eliminar') {
            $ambito = ($_POST['ambito'] ?? 'ingenios') === 'interna' ? 'interna' : 'ingenios';
            $recurso = $ambito === 'interna' ? 'biblioteca_interna' : 'documentos';
            if (!can_manage_resource($user, $recurso)) {
                throw new RuntimeException('No tienes permiso para eliminar documentos.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Documento no valido.');
            }
            $pdo->prepare('DELETE FROM documentos WHERE id = ? AND ambito = ?')->execute([$id, $ambito]);

            header('Location: index.php?view=' . $recurso . '&msg=' . urlencode('Documento eliminado.'));
            exit;
        }

        // Revision de un documento (Aprobar / Observar / Rechazar): flujo de un
        // solo nivel de aprobacion. El prototipo JSX asume 3 roles fijos
        // secuenciales (Jefe de Transferencia 1/2, Director General) que no
        // existen en el modelo de permisos real de este sistema (dinamico, por
        // recurso via roles/rol_permiso); aqui cualquier usuario con el permiso
        // "comites.<recurso>.gestionar" puede tomar la decision directamente.
        if ($accion === 'documento_revisar') {
            $ambito = ($_POST['ambito'] ?? 'ingenios') === 'interna' ? 'interna' : 'ingenios';
            $recurso = $ambito === 'interna' ? 'biblioteca_interna' : 'documentos';
            if (!can_manage_resource($user, $recurso)) {
                throw new RuntimeException('No tienes permiso para revisar documentos de esta biblioteca.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Documento no valido.');
            }

            $decisionMap = ['aprobar' => 'Aprobado', 'observar' => 'Con observaciones', 'rechazar' => 'Rechazado'];
            $decision = (string) ($_POST['decision'] ?? '');
            if (!isset($decisionMap[$decision])) {
                throw new RuntimeException('Selecciona una decision valida (aprobar, observar o rechazar).');
            }
            $nuevoEstadoRevision = $decisionMap[$decision];
            $observacionesRevision = trim((string) ($_POST['observaciones'] ?? '')) ?: null;

            if ($nuevoEstadoRevision === 'Rechazado' && $observacionesRevision === null) {
                throw new RuntimeException('Escribe la causa del rechazo en observaciones.');
            }

            $actualStmt = $pdo->prepare('SELECT ambito, estado FROM documentos WHERE id = ?');
            $actualStmt->execute([$id]);
            $actualDocRevision = $actualStmt->fetch();
            if (!$actualDocRevision) {
                throw new RuntimeException('El documento que intentas revisar ya no existe.');
            }
            if ($actualDocRevision['ambito'] !== $ambito) {
                throw new RuntimeException('El ambito del documento no coincide con esta seccion.');
            }

            if ($nuevoEstadoRevision === 'Rechazado') {
                $pdo->prepare('UPDATE documentos SET estado = ?, causa_rechazo = ?, observaciones = ? WHERE id = ?')
                    ->execute([$nuevoEstadoRevision, $observacionesRevision, $observacionesRevision, $id]);
            } else {
                $pdo->prepare('UPDATE documentos SET estado = ?, observaciones = ? WHERE id = ?')
                    ->execute([$nuevoEstadoRevision, $observacionesRevision, $id]);
            }

            comites_documento_log_historial($pdo, $id, $nuevoEstadoRevision, (string) $user['nombre'], $observacionesRevision);

            header('Location: index.php?view=' . $recurso . '&msg=' . urlencode('Documento revisado: ' . $nuevoEstadoRevision . '.'));
            exit;
        }

        // ---------- Carpetas de la biblioteca documental ----------
        if ($accion === 'carpeta_crear') {
            $ambito = ($_POST['ambito'] ?? 'ingenios') === 'interna' ? 'interna' : 'ingenios';
            $recurso = $ambito === 'interna' ? 'biblioteca_interna' : 'documentos';
            if (!can_manage_resource($user, $recurso)) {
                throw new RuntimeException('No tienes permiso para crear carpetas en esta biblioteca.');
            }

            $nombreCarpeta = trim((string) ($_POST['nombre'] ?? ''));
            if ($nombreCarpeta === '') {
                throw new RuntimeException('Escribe el nombre de la carpeta.');
            }

            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $parentId = $parentId > 0 ? $parentId : null;
            if ($parentId !== null && !comites_carpeta_obtener($pdo, $parentId, $ambito)) {
                throw new RuntimeException('La carpeta padre no es valida.');
            }

            comites_carpeta_crear($pdo, $ambito, $nombreCarpeta, $parentId);

            $destino = 'index.php?view=' . $recurso . ($parentId !== null ? '&carpeta=' . $parentId : '')
                . '&msg=' . urlencode('Carpeta creada correctamente.');
            header('Location: ' . $destino);
            exit;
        }

        // ---------- Correos y envios ----------
        if ($accion === 'correo_enviar') {
            if (!can_manage_resource($user, 'correos')) {
                throw new RuntimeException('No tienes permiso para enviar correos.');
            }

            $asuntoCorreo = trim((string) ($_POST['asunto'] ?? ''));
            $cuerpoCorreo = trim((string) ($_POST['cuerpo'] ?? ''));
            $grupoDestino = trim((string) ($_POST['grupo_destino'] ?? 'todos'));
            $documentoIdCorreo = (int) ($_POST['documento_id'] ?? 0);

            if ($asuntoCorreo === '' || $cuerpoCorreo === '') {
                throw new RuntimeException('Escribe el asunto y el cuerpo del correo.');
            }

            $destinatarios = comites_destinatarios_grupo($pdo, $grupoDestino, $comiteIdsValidos);
            if (!$destinatarios) {
                throw new RuntimeException('No hay contactos con correo electronico registrado en el grupo seleccionado.');
            }

            $cuerpoHtmlCorreo = nl2br(e($cuerpoCorreo));
            $enviadosOk = 0;
            foreach ($destinatarios as $destinatario) {
                $resultadoEnvio = comites_enviar_correo((string) $destinatario['email'], (string) $destinatario['nombre'], $asuntoCorreo, $cuerpoHtmlCorreo);
                if ($resultadoEnvio['ok']) {
                    $enviadosOk++;
                }
            }

            $grupoLabel = $grupoDestino === 'todos' ? 'Todos los contactos' : ($comiteNombrePorId[$grupoDestino] ?? $grupoDestino);
            $listaDestinatarios = implode(', ', array_column($destinatarios, 'email'));

            $pdo->prepare(
                'INSERT INTO correos_enviados (documento_id, asunto, cuerpo, destinatarios, grupo_destino, enviado_por) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $documentoIdCorreo > 0 ? $documentoIdCorreo : null, $asuntoCorreo, $cuerpoCorreo, $listaDestinatarios, $grupoLabel, (string) $user['nombre'],
            ]);

            $totalDestinatarios = count($destinatarios);
            $avisoCorreo = $enviadosOk === $totalDestinatarios
                ? "Correo enviado a {$enviadosOk} destinatario(s)."
                : "Correo registrado. Se enviaron {$enviadosOk} de {$totalDestinatarios} correos (revisa la configuracion de correo del servidor para los restantes).";

            header('Location: index.php?view=correos&msg=' . urlencode($avisoCorreo));
            exit;
        }

        // ---------- Tableros ----------
        if ($accion === 'tablero_guardar') {
            if (!can_manage_resource($user, 'tableros')) {
                throw new RuntimeException('No tienes permiso para gestionar tableros.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $nombreTablero = trim((string) ($_POST['nombre'] ?? ''));
            $descripcionTablero = trim((string) ($_POST['descripcion'] ?? '')) ?: null;
            $urlTablero = trim((string) ($_POST['url'] ?? '')) ?: null;
            $tipoTablero = trim((string) ($_POST['tipo'] ?? '')) ?: null;
            $estadoTablero = ($_POST['estado'] ?? 'Activo') === 'Inactivo' ? 'Inactivo' : 'Activo';

            if ($nombreTablero === '') {
                throw new RuntimeException('Escribe el nombre del tablero.');
            }

            if ($id > 0) {
                $pdo->prepare('UPDATE tableros SET nombre = ?, descripcion = ?, url = ?, tipo = ?, estado = ? WHERE id = ?')
                    ->execute([$nombreTablero, $descripcionTablero, $urlTablero, $tipoTablero, $estadoTablero, $id]);
            } else {
                $pdo->prepare('INSERT INTO tableros (nombre, descripcion, url, tipo, estado) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$nombreTablero, $descripcionTablero, $urlTablero, $tipoTablero, $estadoTablero]);
            }

            header('Location: index.php?view=tableros&msg=' . urlencode('Tablero guardado correctamente.'));
            exit;
        }

        if ($accion === 'tablero_eliminar') {
            if (!can_manage_resource($user, 'tableros')) {
                throw new RuntimeException('No tienes permiso para eliminar tableros.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Tablero no valido.');
            }
            $pdo->prepare('DELETE FROM tableros WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=tableros&msg=' . urlencode('Tablero eliminado.'));
            exit;
        }

        // ---------- Informes cuatrimestrales ----------
        if ($accion === 'informe_guardar') {
            if (!can_manage_resource($user, 'informes')) {
                throw new RuntimeException('No tienes permiso para gestionar informes cuatrimestrales.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $periodo = trim((string) ($_POST['periodo'] ?? ''));
            $area = trim((string) ($_POST['area'] ?? '')) ?: null;
            $evaluacionesValidas = ['En revision', 'Satisfactorio', 'Con observaciones', 'No satisfactorio'];
            $evaluacion = in_array($_POST['evaluacion'] ?? '', $evaluacionesValidas, true) ? $_POST['evaluacion'] : 'En revision';
            $observacionesInforme = trim((string) ($_POST['observaciones'] ?? '')) ?: null;
            $fechaInforme = trim((string) ($_POST['fecha'] ?? '')) ?: null;

            if ($periodo === '') {
                throw new RuntimeException('Escribe el periodo del informe (por ejemplo, "I cuatrimestre 2026").');
            }

            $planOperativo = comites_guardar_archivo($_FILES['plan_operativo'] ?? [], 'informes', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);
            $informeArchivo = comites_guardar_archivo($_FILES['informe'] ?? [], 'informes', ['pdf', 'doc', 'docx']);
            $avisoArchivo = '';
            if ($planOperativo['error'] !== null) {
                $avisoArchivo .= ' ' . $planOperativo['error'];
            }
            if ($informeArchivo['error'] !== null) {
                $avisoArchivo .= ' ' . $informeArchivo['error'];
            }

            if ($id > 0) {
                $actualStmt = $pdo->prepare(
                    'SELECT plan_operativo_nombre, plan_operativo_ruta, informe_nombre, informe_ruta FROM informes_cuatrimestrales WHERE id = ?'
                );
                $actualStmt->execute([$id]);
                $actualInforme = $actualStmt->fetch();
                if (!$actualInforme) {
                    throw new RuntimeException('El informe que intentas editar ya no existe.');
                }

                $planNombre = $planOperativo['ruta'] !== null ? $planOperativo['nombre'] : $actualInforme['plan_operativo_nombre'];
                $planRuta = $planOperativo['ruta'] !== null ? $planOperativo['ruta'] : $actualInforme['plan_operativo_ruta'];
                $infNombre = $informeArchivo['ruta'] !== null ? $informeArchivo['nombre'] : $actualInforme['informe_nombre'];
                $infRuta = $informeArchivo['ruta'] !== null ? $informeArchivo['ruta'] : $actualInforme['informe_ruta'];

                $stmt = $pdo->prepare(
                    'UPDATE informes_cuatrimestrales SET periodo = ?, area = ?, plan_operativo_nombre = ?, plan_operativo_ruta = ?,
                        informe_nombre = ?, informe_ruta = ?, evaluacion = ?, observaciones = ?, fecha = ? WHERE id = ?'
                );
                $stmt->execute([$periodo, $area, $planNombre, $planRuta, $infNombre, $infRuta, $evaluacion, $observacionesInforme, $fechaInforme, $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO informes_cuatrimestrales (periodo, area, plan_operativo_nombre, plan_operativo_ruta, informe_nombre, informe_ruta, evaluacion, observaciones, fecha)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $periodo, $area, $planOperativo['nombre'], $planOperativo['ruta'], $informeArchivo['nombre'], $informeArchivo['ruta'],
                    $evaluacion, $observacionesInforme, $fechaInforme,
                ]);
                $id = (int) $pdo->lastInsertId();
            }

            $erroresSoportes = comites_informe_guardar_soportes_multiples($pdo, $id, $_FILES['soportes'] ?? []);
            foreach ($erroresSoportes as $errorSoporte) {
                $avisoArchivo .= ' ' . $errorSoporte;
            }

            $eliminarSoportes = array_map('intval', is_array($_POST['eliminar_soportes'] ?? null) ? $_POST['eliminar_soportes'] : []);
            if ($eliminarSoportes) {
                $placeholders = implode(',', array_fill(0, count($eliminarSoportes), '?'));
                $pdo->prepare("DELETE FROM informe_soportes WHERE informe_id = ? AND id IN ({$placeholders})")
                    ->execute(array_merge([$id], $eliminarSoportes));
            }

            header('Location: index.php?view=informes&msg=' . urlencode('Informe guardado correctamente.' . $avisoArchivo));
            exit;
        }

        if ($accion === 'informe_eliminar') {
            if (!can_manage_resource($user, 'informes')) {
                throw new RuntimeException('No tienes permiso para eliminar informes.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Informe no valido.');
            }
            $pdo->prepare('DELETE FROM informes_cuatrimestrales WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=informes&msg=' . urlencode('Informe eliminado.'));
            exit;
        }

        // ---------- Comite editorial (revisores de la memoria de resultados) ----------
        if ($accion === 'editorial_guardar') {
            if (!can_manage_resource($user, 'memorias')) {
                throw new RuntimeException('No tienes permiso para gestionar el comite editorial.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            $nombreEditorial = trim((string) ($_POST['nombre'] ?? ''));
            $cargoEditorial = trim((string) ($_POST['cargo'] ?? '')) ?: null;
            $emailEditorial = trim((string) ($_POST['email'] ?? '')) ?: null;
            $estadoEditorial = ($_POST['estado'] ?? 'Activo') === 'Inactivo' ? 'Inactivo' : 'Activo';

            if ($nombreEditorial === '') {
                throw new RuntimeException('Escribe el nombre del integrante del comite editorial.');
            }

            if ($id > 0) {
                $pdo->prepare('UPDATE comite_editorial SET nombre = ?, cargo = ?, email = ?, estado = ? WHERE id = ?')
                    ->execute([$nombreEditorial, $cargoEditorial, $emailEditorial, $estadoEditorial, $id]);
            } else {
                $pdo->prepare('INSERT INTO comite_editorial (nombre, cargo, email, estado) VALUES (?, ?, ?, ?)')
                    ->execute([$nombreEditorial, $cargoEditorial, $emailEditorial, $estadoEditorial]);
            }

            header('Location: index.php?view=memorias&msg=' . urlencode('Comite editorial actualizado.'));
            exit;
        }

        if ($accion === 'editorial_eliminar') {
            if (!can_manage_resource($user, 'memorias')) {
                throw new RuntimeException('No tienes permiso para eliminar integrantes del comite editorial.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Registro no valido.');
            }
            $pdo->prepare('UPDATE memorias_resultados SET revisor_id = NULL WHERE revisor_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM comite_editorial WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=memorias&msg=' . urlencode('Integrante eliminado del comite editorial.'));
            exit;
        }

        // ---------- Memoria de resultados ----------
        if ($accion === 'memoria_guardar') {
            if (!can_manage_resource($user, 'memorias')) {
                throw new RuntimeException('No tienes permiso para gestionar la memoria de resultados.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $numeroMemoria = trim((string) ($_POST['numero_memoria'] ?? '')) ?: null;
            $tituloMemoria = trim((string) ($_POST['titulo'] ?? ''));
            $autorMemoria = trim((string) ($_POST['autor'] ?? '')) ?: null;
            $autorEmailMemoria = trim((string) ($_POST['autor_email'] ?? '')) ?: null;
            $fechaMemoria = trim((string) ($_POST['fecha'] ?? '')) ?: null;
            $revisorId = (int) ($_POST['revisor_id'] ?? 0);
            $revisorId = $revisorId > 0 ? $revisorId : null;
            $estadosMemoriaValidos = ['Recibida', 'En revision', 'Aprobada', 'Rechazada', 'Publicada'];
            $estadoMemoria = in_array($_POST['estado'] ?? '', $estadosMemoriaValidos, true) ? $_POST['estado'] : 'Recibida';
            $observacionesRevisor = trim((string) ($_POST['observaciones_revisor'] ?? '')) ?: null;

            if ($tituloMemoria === '') {
                throw new RuntimeException('Escribe el titulo de la memoria.');
            }

            if ($numeroMemoria !== null) {
                $dupStmt = $pdo->prepare('SELECT id FROM memorias_resultados WHERE numero_memoria = ? AND id <> ?');
                $dupStmt->execute([$numeroMemoria, $id]);
                if ($dupStmt->fetchColumn()) {
                    throw new RuntimeException('Ya existe una memoria registrada con ese numero.');
                }
            }

            $archivoMemoria = comites_guardar_archivo($_FILES['archivo'] ?? [], 'memorias', ['pdf', 'doc', 'docx']);
            $avisoArchivo = $archivoMemoria['error'] !== null
                ? ' La memoria se guardo, pero el archivo no se subio: ' . $archivoMemoria['error']
                : '';

            if ($id > 0) {
                $actualStmt = $pdo->prepare('SELECT estado, archivo_nombre, archivo_ruta, version FROM memorias_resultados WHERE id = ?');
                $actualStmt->execute([$id]);
                $actualMemoria = $actualStmt->fetch();
                if (!$actualMemoria) {
                    throw new RuntimeException('La memoria que intentas editar ya no existe.');
                }

                $nombreArchivoMemoria = $actualMemoria['archivo_nombre'];
                $rutaArchivoMemoria = $actualMemoria['archivo_ruta'];
                $versionMemoria = (int) $actualMemoria['version'];
                if ($archivoMemoria['ruta'] !== null) {
                    $nombreArchivoMemoria = $archivoMemoria['nombre'];
                    $rutaArchivoMemoria = $archivoMemoria['ruta'];
                    $versionMemoria++;
                }

                $stmt = $pdo->prepare(
                    'UPDATE memorias_resultados SET numero_memoria = ?, titulo = ?, autor = ?, autor_email = ?, fecha = ?,
                        archivo_nombre = ?, archivo_ruta = ?, version = ?, revisor_id = ?, estado = ?, observaciones_revisor = ? WHERE id = ?'
                );
                $stmt->execute([
                    $numeroMemoria, $tituloMemoria, $autorMemoria, $autorEmailMemoria, $fechaMemoria,
                    $nombreArchivoMemoria, $rutaArchivoMemoria, $versionMemoria, $revisorId, $estadoMemoria, $observacionesRevisor, $id,
                ]);

                if ($estadoMemoria !== $actualMemoria['estado']) {
                    comites_memoria_log_track($pdo, $id, 'Cambio de estado', $estadoMemoria, $observacionesRevisor);
                }
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO memorias_resultados (numero_memoria, titulo, autor, autor_email, fecha, archivo_nombre, archivo_ruta, version, revisor_id, estado, observaciones_revisor)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
                );
                $stmt->execute([
                    $numeroMemoria, $tituloMemoria, $autorMemoria, $autorEmailMemoria, $fechaMemoria,
                    $archivoMemoria['nombre'], $archivoMemoria['ruta'], $revisorId, $estadoMemoria, $observacionesRevisor,
                ]);
                $id = (int) $pdo->lastInsertId();
                comites_memoria_log_track($pdo, $id, 'Recepcion', $estadoMemoria, $observacionesRevisor);
            }

            header('Location: index.php?view=memorias&msg=' . urlencode('Memoria guardada correctamente.' . $avisoArchivo));
            exit;
        }

        if ($accion === 'memoria_eliminar') {
            if (!can_manage_resource($user, 'memorias')) {
                throw new RuntimeException('No tienes permiso para eliminar memorias.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Memoria no valida.');
            }
            $pdo->prepare('DELETE FROM memorias_resultados WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=memorias&msg=' . urlencode('Memoria eliminada.'));
            exit;
        }

        // ---------- Publicaciones (redes sociales) ----------
        if ($accion === 'publicacion_guardar') {
            if (!can_manage_resource($user, 'publicaciones')) {
                throw new RuntimeException('No tienes permiso para gestionar publicaciones.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $fechaPublicacion = trim((string) ($_POST['fecha'] ?? '')) ?: null;
            $plataformasValidas = ['Facebook', 'Instagram', 'LinkedIn', 'X', 'YouTube', 'TikTok', 'Sitio web', 'Otro'];
            $plataforma = in_array($_POST['plataforma'] ?? '', $plataformasValidas, true) ? $_POST['plataforma'] : 'Otro';
            $tipoPublicacion = trim((string) ($_POST['tipo'] ?? '')) ?: null;
            $tituloPublicacion = trim((string) ($_POST['titulo'] ?? ''));
            $copyTexto = trim((string) ($_POST['copy_texto'] ?? '')) ?: null;
            $estadosPublicacionValidos = ['Borrador', 'Programada', 'Publicada', 'Pausada'];
            $estadoPublicacion = in_array($_POST['estado'] ?? '', $estadosPublicacionValidos, true) ? $_POST['estado'] : 'Borrador';
            $responsablePublicacion = trim((string) ($_POST['responsable'] ?? '')) ?: null;
            $urlPublicacion = trim((string) ($_POST['url'] ?? '')) ?: null;

            $metricas = [];
            foreach (['alcance', 'impresiones', 'interacciones', 'reacciones', 'comentarios', 'compartidos', 'clics', 'reproducciones', 'guardados'] as $campoMetrica) {
                $metricas[$campoMetrica] = max(0, (int) ($_POST[$campoMetrica] ?? 0));
            }

            if ($tituloPublicacion === '') {
                throw new RuntimeException('Escribe el titulo de la publicacion.');
            }

            $materialPublicacion = comites_guardar_archivo($_FILES['material'] ?? [], 'publicaciones', ['jpg', 'jpeg', 'png', 'mp4', 'pdf']);
            $avisoArchivo = $materialPublicacion['error'] !== null
                ? ' La publicacion se guardo, pero el material no se subio: ' . $materialPublicacion['error']
                : '';

            if ($id > 0) {
                $actualStmt = $pdo->prepare('SELECT material FROM publicaciones WHERE id = ?');
                $actualStmt->execute([$id]);
                $actualPublicacion = $actualStmt->fetch();
                if (!$actualPublicacion) {
                    throw new RuntimeException('La publicacion que intentas editar ya no existe.');
                }
                $materialRuta = $materialPublicacion['ruta'] !== null ? $materialPublicacion['ruta'] : $actualPublicacion['material'];

                $stmt = $pdo->prepare(
                    'UPDATE publicaciones SET fecha = ?, plataforma = ?, tipo = ?, titulo = ?, copy_texto = ?, material = ?, estado = ?,
                        responsable = ?, url = ?, alcance = ?, impresiones = ?, interacciones = ?, reacciones = ?, comentarios = ?,
                        compartidos = ?, clics = ?, reproducciones = ?, guardados = ? WHERE id = ?'
                );
                $stmt->execute([
                    $fechaPublicacion, $plataforma, $tipoPublicacion, $tituloPublicacion, $copyTexto, $materialRuta, $estadoPublicacion,
                    $responsablePublicacion, $urlPublicacion,
                    $metricas['alcance'], $metricas['impresiones'], $metricas['interacciones'], $metricas['reacciones'], $metricas['comentarios'],
                    $metricas['compartidos'], $metricas['clics'], $metricas['reproducciones'], $metricas['guardados'], $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO publicaciones (fecha, plataforma, tipo, titulo, copy_texto, material, estado, responsable, url,
                        alcance, impresiones, interacciones, reacciones, comentarios, compartidos, clics, reproducciones, guardados)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $fechaPublicacion, $plataforma, $tipoPublicacion, $tituloPublicacion, $copyTexto, $materialPublicacion['ruta'], $estadoPublicacion,
                    $responsablePublicacion, $urlPublicacion,
                    $metricas['alcance'], $metricas['impresiones'], $metricas['interacciones'], $metricas['reacciones'], $metricas['comentarios'],
                    $metricas['compartidos'], $metricas['clics'], $metricas['reproducciones'], $metricas['guardados'],
                ]);
            }

            header('Location: index.php?view=publicaciones&msg=' . urlencode('Publicacion guardada correctamente.' . $avisoArchivo));
            exit;
        }

        if ($accion === 'publicacion_eliminar') {
            if (!can_manage_resource($user, 'publicaciones')) {
                throw new RuntimeException('No tienes permiso para eliminar publicaciones.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Publicacion no valida.');
            }
            $pdo->prepare('DELETE FROM publicaciones WHERE id = ?')->execute([$id]);

            header('Location: index.php?view=publicaciones&msg=' . urlencode('Publicacion eliminada.'));
            exit;
        }
    } catch (RuntimeException $e) {
        $flashError = $e->getMessage();
        if (str_starts_with($accion, 'contacto_')) {
            $reopenModal = 'contacto';
        } elseif (str_starts_with($accion, 'reunion_')) {
            $reopenModal = 'reunion';
        } elseif (str_starts_with($accion, 'acuerdo_')) {
            $reopenModal = 'acuerdo';
        } elseif (str_starts_with($accion, 'comite_')) {
            $reopenModal = 'comite';
            $reopenId = (string) ($_POST['id'] ?? '');
        } elseif ($accion === 'documento_revisar') {
            $reopenModal = 'documento_revisar';
            $reopenId = (int) ($_POST['id'] ?? 0);
        } elseif (str_starts_with($accion, 'carpeta_')) {
            $reopenModal = 'carpeta';
        } elseif (str_starts_with($accion, 'documento_')) {
            $reopenModal = 'documento';
        } elseif (str_starts_with($accion, 'tablero_')) {
            $reopenModal = 'tablero';
        } elseif (str_starts_with($accion, 'informe_')) {
            $reopenModal = 'informe';
        } elseif (str_starts_with($accion, 'memoria_')) {
            $reopenModal = 'memoria';
        } elseif (str_starts_with($accion, 'editorial_')) {
            $reopenModal = 'editorial';
        } elseif (str_starts_with($accion, 'publicacion_')) {
            $reopenModal = 'publicacion';
        }
        $prefillPost = $_POST;
    }
}

$flashMessage = $_GET['msg'] ?? '';

/**
 * Helper generico de consulta: ejecuta un SELECT preparado y devuelve todas
 * las filas. Usado por todas las secciones del modulo (listados de
 * documentos, correos, tableros, informes, memorias, publicaciones,
 * estadisticas, etc.) para evitar repetir el mismo prepare/execute/fetchAll.
 */
function fetch_placeholder_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** Fila del editor de acuerdos embebido en el formulario de reunion. Si $idx es null, se usa como <template> para clonar por JS. */
function render_acuerdo_editor_row(?int $idx, array $fila, bool $puedeGestionar): void
{
    $name = $idx === null ? '__IDX__' : (string) $idx;
    ?>
    <div class="acuerdo-row" data-acuerdo-row>
        <input type="hidden" name="acuerdos[<?= $name ?>][id]" value="<?= (int) ($fila['id'] ?? 0) ?>">
        <div class="acuerdo-row-head">
            <span class="acuerdo-row-title">Acuerdo</span>
            <?php if ($puedeGestionar): ?>
                <button type="button" class="icon-btn danger" data-remove-acuerdo title="Quitar acuerdo"><i class="ti ti-trash"></i></button>
            <?php endif; ?>
        </div>
        <textarea class="form-control" rows="2" name="acuerdos[<?= $name ?>][acuerdo]" placeholder="Describa el acuerdo..." <?= $puedeGestionar ? '' : 'readonly' ?>><?= e((string) ($fila['acuerdo'] ?? '')) ?></textarea>
        <div class="acuerdo-row-grid">
            <input class="form-control" type="text" name="acuerdos[<?= $name ?>][responsable]" placeholder="Responsable" value="<?= e((string) ($fila['responsable'] ?? '')) ?>" <?= $puedeGestionar ? '' : 'readonly' ?>>
            <input class="form-control" type="date" name="acuerdos[<?= $name ?>][plazo]" value="<?= e((string) ($fila['plazo'] ?? '')) ?>" <?= $puedeGestionar ? '' : 'disabled' ?>>
            <input class="form-control" type="date" name="acuerdos[<?= $name ?>][fecha_seguimiento]" value="<?= e((string) ($fila['fecha_seguimiento'] ?? '')) ?>" <?= $puedeGestionar ? '' : 'disabled' ?>>
            <select class="form-control" name="acuerdos[<?= $name ?>][estado]" <?= $puedeGestionar ? '' : 'disabled' ?>>
                <?php foreach (['Pendiente', 'En proceso', 'Completado'] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= ($fila['estado'] ?? 'Pendiente') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acuerdo-row-grid2">
            <label class="form-group">
                <span class="form-label">Avance (%)</span>
                <input class="form-control" type="number" min="0" max="100" name="acuerdos[<?= $name ?>][avance]" value="<?= (int) ($fila['avance'] ?? 0) ?>" <?= $puedeGestionar ? '' : 'readonly' ?>>
            </label>
            <label class="form-group">
                <span class="form-label">Observaciones</span>
                <textarea class="form-control" rows="1" name="acuerdos[<?= $name ?>][observaciones]" <?= $puedeGestionar ? '' : 'readonly' ?>><?= e((string) ($fila['observaciones'] ?? '')) ?></textarea>
            </label>
        </div>
    </div>
    <?php
}

function render_meeting_calendar(array $rows, string $mes, array $comiteNombrePorId): void
{
    [$anio, $mesNum] = array_map('intval', explode('-', $mes));
    $primerDia = mktime(0, 0, 0, $mesNum, 1, $anio);
    $diasEnMes = (int) date('t', $primerDia);
    $diaSemanaInicio = ((int) date('N', $primerDia)) - 1; // 0=lunes

    $porDia = [];
    foreach ($rows as $r) {
        $fechaRow = (string) ($r['fecha'] ?? '');
        if ($fechaRow === '') {
            continue;
        }
        $porDia[$fechaRow][] = $r;
    }

    $mesAnterior = date('Y-m', mktime(0, 0, 0, $mesNum - 1, 1, $anio));
    $mesSiguiente = date('Y-m', mktime(0, 0, 0, $mesNum + 1, 1, $anio));
    $etiquetaMes = ucfirst((string) strftime_es($anio, $mesNum));
    ?>
    <div class="card calendar-card">
        <div class="calendar-toolbar">
            <div>
                <h3>Calendario de reuniones</h3>
                <p class="section-sub" style="margin:0;">Agenda mensual de los comites.</p>
            </div>
            <div class="calendar-nav">
                <a class="btn-outline btn-sm" href="index.php?view=reuniones&modo=calendario&mes=<?= e($mesAnterior) ?>">‹</a>
                <span class="calendar-month-label"><?= e($etiquetaMes) ?></span>
                <a class="btn-outline btn-sm" href="index.php?view=reuniones&modo=calendario&mes=<?= e($mesSiguiente) ?>">›</a>
            </div>
        </div>
        <div class="calendar-grid">
            <?php foreach (['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'] as $d): ?>
                <div class="calendar-headcell"><?= e($d) ?></div>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $diaSemanaInicio; $i++): ?>
                <div class="calendar-cell is-empty"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $diasEnMes; $d++): ?>
                <?php $fechaCelda = sprintf('%s-%02d', $mes, $d); ?>
                <div class="calendar-cell">
                    <div class="calendar-daynum"><?= $d ?></div>
                    <?php foreach ($porDia[$fechaCelda] ?? [] as $r): ?>
                        <a class="calendar-event" href="index.php?view=reuniones&modo=calendario&mes=<?= e($mes) ?>&editar=<?= (int) $r['id'] ?>">
                            <?= e($comiteNombrePorId[$r['comite_id']] ?? 'Comite') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <?php
}

function strftime_es(int $anio, int $mes): string
{
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return ($meses[$mes] ?? '') . ' de ' . $anio;
}

$stats = [
    'contactos' => (int) $pdo->query("SELECT COUNT(*) FROM contactos WHERE estado = 'Activo'")->fetchColumn(),
    'reuniones' => (int) $pdo->query('SELECT COUNT(*) FROM reuniones')->fetchColumn(),
    'acuerdos' => (int) $pdo->query('SELECT COUNT(*) FROM acuerdos')->fetchColumn(),
    'documentos' => (int) $pdo->query("SELECT COUNT(*) FROM documentos WHERE ambito = 'ingenios'")->fetchColumn(),
    'biblioteca_interna' => (int) $pdo->query("SELECT COUNT(*) FROM documentos WHERE ambito = 'interna'")->fetchColumn(),
    'correos' => (int) $pdo->query('SELECT COUNT(*) FROM correos_enviados')->fetchColumn(),
    'tableros' => (int) $pdo->query("SELECT COUNT(*) FROM tableros WHERE estado = 'Activo'")->fetchColumn(),
    'informes' => (int) $pdo->query('SELECT COUNT(*) FROM informes_cuatrimestrales')->fetchColumn(),
    'memorias' => (int) $pdo->query('SELECT COUNT(*) FROM memorias_resultados')->fetchColumn(),
    'publicaciones' => (int) $pdo->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn(),
];
$stats['comites'] = (int) $pdo->query('SELECT COUNT(*) FROM comites WHERE activo = 1')->fetchColumn();
$stats['acuerdos_completados'] = (int) $pdo->query("SELECT COUNT(*) FROM acuerdos WHERE estado = 'Completado'")->fetchColumn();
$stats['reuniones_realizadas'] = (int) $pdo->query('SELECT COUNT(*) FROM reuniones WHERE realizada = 1')->fetchColumn();

$active = static fn(string $name): string => $view === $name ? 'is-active' : '';

$userInitials = '';
foreach (preg_split('/\s+/', trim((string) $user['nombre']), -1, PREG_SPLIT_NO_EMPTY) as $namePart) {
    $userInitials .= mb_strtoupper(mb_substr($namePart, 0, 1, 'UTF-8'), 'UTF-8');
    if (mb_strlen($userInitials, 'UTF-8') >= 2) {
        break;
    }
}
$userInitials = $userInitials !== '' ? $userInitials : 'U';

$currentSection = $sections[$view];
$groups = [];
foreach ($sections as $key => $section) {
    $groups[$section['group']][$key] = $section['label'];
}

// ===================== Datos especificos de cada vista =====================

if ($view === 'contactos' && $puedeVerSeccionActual) {
    $q = trim((string) ($_GET['q'] ?? ''));
    $comiteFiltro = trim((string) ($_GET['comite'] ?? 'todos'));

    $sql = 'SELECT DISTINCT c.* FROM contactos c LEFT JOIN contacto_comite cc ON cc.contacto_id = c.id WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (c.nombre LIKE ? OR c.cargo LIKE ? OR c.empresa LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($comiteFiltro !== 'todos' && in_array($comiteFiltro, $comiteIdsValidos, true)) {
        $sql .= ' AND cc.comite_id = ?';
        $params[] = $comiteFiltro;
    }
    $sql .= ' ORDER BY c.nombre';

    $contactos = fetch_placeholder_rows($pdo, $sql, $params);
    $comitesPorContacto = [];
    if ($contactos) {
        $ids = array_column($contactos, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rel = fetch_placeholder_rows($pdo, "SELECT contacto_id, comite_id FROM contacto_comite WHERE contacto_id IN ({$in})", $ids);
        foreach ($rel as $r) {
            $comitesPorContacto[(int) $r['contacto_id']][] = $r['comite_id'];
        }
    }

    $contactoEditar = null;
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'contacto' && $prefillPost) {
        $contactoEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'nombre' => (string) ($prefillPost['nombre'] ?? ''),
            'cargo' => (string) ($prefillPost['cargo'] ?? ''),
            'empresa' => (string) ($prefillPost['empresa'] ?? ''),
            'telefono' => (string) ($prefillPost['telefono'] ?? ''),
            'email' => (string) ($prefillPost['email'] ?? ''),
            'estado' => (string) ($prefillPost['estado'] ?? 'Activo'),
            'comites' => is_array($prefillPost['comites'] ?? null) ? $prefillPost['comites'] : [],
        ];
        $modalContactosAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM contactos WHERE id = ?');
        $stmt->execute([$editarId]);
        $row = $stmt->fetch();
        if ($row) {
            $contactoEditar = $row;
            $contactoEditar['comites'] = comites_contacto_comite_ids($pdo, $editarId);
        }
        $modalContactosAbierto = (bool) $contactoEditar;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $contactoEditar = ['id' => 0, 'nombre' => '', 'cargo' => '', 'empresa' => '', 'telefono' => '', 'email' => '', 'estado' => 'Activo', 'comites' => []];
        $modalContactosAbierto = true;
    } else {
        $modalContactosAbierto = false;
    }
}

if ($view === 'reuniones' && $puedeVerSeccionActual) {
    $modo = ($_GET['modo'] ?? 'lista') === 'calendario' ? 'calendario' : 'lista';
    $comiteFiltro = trim((string) ($_GET['comite'] ?? 'todos'));
    $mes = trim((string) ($_GET['mes'] ?? '')) ?: date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    $sql = 'SELECT r.*, (SELECT COUNT(*) FROM acuerdos a WHERE a.reunion_id = r.id) AS total_acuerdos,
                   (SELECT COUNT(*) FROM reunion_asistentes ra WHERE ra.reunion_id = r.id) AS total_asistentes
            FROM reuniones r WHERE 1=1';
    $params = [];
    if ($comiteFiltro !== 'todos' && in_array($comiteFiltro, $comiteIdsValidos, true)) {
        $sql .= ' AND r.comite_id = ?';
        $params[] = $comiteFiltro;
    }
    if ($modo === 'calendario') {
        $sql .= ' AND r.fecha BETWEEN ? AND ?';
        $params[] = $mes . '-01';
        $params[] = date('Y-m-t', strtotime($mes . '-01'));
    }
    $sql .= ' ORDER BY r.fecha DESC, r.id DESC';
    $reuniones = fetch_placeholder_rows($pdo, $sql, $params);

    $reunionVM = null;
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'reunion' && $prefillPost) {
        $reunionVM = comites_reunion_vm_from_post($prefillPost);
        $modalReunionesAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM reuniones WHERE id = ?');
        $stmt->execute([$editarId]);
        $row = $stmt->fetch();
        if ($row) {
            $reunionVM = comites_reunion_vm_from_db($pdo, $row);
        }
        $modalReunionesAbierto = (bool) $reunionVM;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $reunionVM = [
            'id' => 0, 'comite_id' => $comitesActivos[0]['id'] ?? '', 'fecha' => date('Y-m-d'), 'realizada' => 1,
            'temas' => '', 'proximos_pasos' => '', 'responsable' => '', 'ayuda_memoria_nombre' => null, 'ayuda_memoria_ruta' => null,
            'asistentes' => [], 'acuerdos' => [],
        ];
        $modalReunionesAbierto = true;
    } else {
        $modalReunionesAbierto = false;
    }

    if ($modalReunionesAbierto && $reunionVM) {
        $contactosDisponibles = fetch_placeholder_rows($pdo, 'SELECT id, nombre, cargo FROM contactos WHERE estado = "Activo" ORDER BY nombre');
        $comitesPorContactoDisp = [];
        if ($contactosDisponibles) {
            $ids = array_column($contactosDisponibles, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rel = fetch_placeholder_rows($pdo, "SELECT contacto_id, comite_id FROM contacto_comite WHERE contacto_id IN ({$in})", $ids);
            foreach ($rel as $r) {
                $comitesPorContactoDisp[(int) $r['contacto_id']][] = $r['comite_id'];
            }
        }
    }
}

if ($view === 'acuerdos' && $puedeVerSeccionActual) {
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT a.*, c.nombre AS comite_nombre FROM acuerdos a LEFT JOIN comites c ON c.id = a.comite_id WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (a.acuerdo LIKE ? OR a.responsable LIKE ? OR c.nombre LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY a.fecha DESC, a.id DESC';
    $acuerdos = fetch_placeholder_rows($pdo, $sql, $params);

    $acuerdoEditar = null;
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'acuerdo' && $prefillPost) {
        $acuerdoEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'comite_id' => (string) ($prefillPost['comite_id'] ?? ''),
            'fecha' => (string) ($prefillPost['fecha'] ?? date('Y-m-d')),
            'acuerdo' => (string) ($prefillPost['acuerdo'] ?? ''),
            'responsable' => (string) ($prefillPost['responsable'] ?? ''),
            'plazo' => (string) ($prefillPost['plazo'] ?? ''),
            'fecha_seguimiento' => (string) ($prefillPost['fecha_seguimiento'] ?? ''),
            'avance' => (int) ($prefillPost['avance'] ?? 0),
            'estado' => (string) ($prefillPost['estado'] ?? 'Pendiente'),
        ];
        $modalAcuerdosAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM acuerdos WHERE id = ?');
        $stmt->execute([$editarId]);
        $row = $stmt->fetch();
        $acuerdoEditar = $row ?: null;
        $modalAcuerdosAbierto = (bool) $acuerdoEditar;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $acuerdoEditar = [
            'id' => 0, 'comite_id' => $comitesActivos[0]['id'] ?? '', 'fecha' => date('Y-m-d'), 'acuerdo' => '',
            'responsable' => '', 'plazo' => '', 'fecha_seguimiento' => '', 'avance' => 0, 'estado' => 'Pendiente',
        ];
        $modalAcuerdosAbierto = true;
    } else {
        $modalAcuerdosAbierto = false;
    }
}

if (($view === 'documentos' || $view === 'biblioteca_interna') && $puedeVerSeccionActual) {
    $ambitoActual = $view === 'biblioteca_interna' ? 'interna' : 'ingenios';
    $recursoActual = $view;
    $puedeGestionarDocumentos = can_manage_resource($user, $recursoActual);

    // Navegacion por carpetas: `carpeta` ausente/invalida = raiz. La raiz solo
    // muestra tarjetas de carpeta (sin tabla de documentos ni "Nuevo
    // documento") hasta entrar a una carpeta, igual que el prototipo JSX.
    $carpetaIdGet = (int) ($_GET['carpeta'] ?? 0);
    $carpetaActual = $carpetaIdGet > 0 ? comites_carpeta_obtener($pdo, $carpetaIdGet, $ambitoActual) : null;
    $carpetaId = $carpetaActual ? (int) $carpetaActual['id'] : null;
    $subcarpetas = comites_carpeta_hijas($pdo, $ambitoActual, $carpetaId);
    $breadcrumbCarpetas = comites_carpeta_breadcrumb($pdo, $carpetaId);

    $q = trim((string) ($_GET['q'] ?? ''));
    $comiteFiltro = trim((string) ($_GET['comite'] ?? 'todos'));

    $documentos = [];
    if ($carpetaId !== null) {
        $sql = 'SELECT d.*, c.nombre AS comite_nombre FROM documentos d LEFT JOIN comites c ON c.id = d.comite_id WHERE d.ambito = ? AND d.carpeta_id = ?';
        $params = [$ambitoActual, $carpetaId];
        if ($q !== '') {
            $sql .= ' AND (d.titulo LIKE ? OR d.autor LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like);
        }
        if ($comiteFiltro !== 'todos' && in_array($comiteFiltro, $comiteIdsValidos, true)) {
            $sql .= ' AND d.comite_id = ?';
            $params[] = $comiteFiltro;
        }
        $sql .= ' ORDER BY d.fecha DESC, d.id DESC';
        $documentos = fetch_placeholder_rows($pdo, $sql, $params);
    }

    // ---- Modal: crear carpeta ----
    $modalCarpetaAbierto = ($reopenModal === 'carpeta' && $prefillPost) || isset($_GET['nueva_carpeta']);
    $carpetaNuevaNombre = ($reopenModal === 'carpeta' && $prefillPost) ? (string) ($prefillPost['nombre'] ?? '') : '';

    // ---- Modal: editar/nuevo documento ----
    $documentoEditar = null;
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'documento' && $prefillPost) {
        $documentoEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'tipo' => (string) ($prefillPost['tipo'] ?? 'Otro'),
            'titulo' => (string) ($prefillPost['titulo'] ?? ''),
            'fecha' => (string) ($prefillPost['fecha'] ?? date('Y-m-d')),
            'comite_id' => (string) ($prefillPost['comite_id'] ?? ''),
            'autor' => (string) ($prefillPost['autor'] ?? ''),
            'enlace' => (string) ($prefillPost['enlace'] ?? ''),
            'descripcion' => (string) ($prefillPost['descripcion'] ?? ''),
            'archivo_nombre' => null,
            'archivo_ruta' => null,
            'estado' => (string) ($prefillPost['estado'] ?? 'En revision'),
            'observaciones' => (string) ($prefillPost['observaciones'] ?? ''),
            'causa_rechazo' => (string) ($prefillPost['causa_rechazo'] ?? ''),
            'habilitado_envio' => isset($prefillPost['habilitado_envio']) ? 1 : 0,
        ];
        $modalDocumentoAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarDocumentos) {
        $stmt = $pdo->prepare('SELECT * FROM documentos WHERE id = ? AND ambito = ?');
        $stmt->execute([$editarId, $ambitoActual]);
        $row = $stmt->fetch();
        if ($row) {
            $documentoEditar = $row;
        }
        $modalDocumentoAbierto = (bool) $documentoEditar;
    } elseif (isset($_GET['nuevo']) && $carpetaId !== null && $puedeGestionarDocumentos) {
        $documentoEditar = [
            'id' => 0, 'tipo' => 'Otro', 'titulo' => '', 'fecha' => date('Y-m-d'),
            'comite_id' => '', 'autor' => '', 'enlace' => '', 'descripcion' => '', 'archivo_nombre' => null, 'archivo_ruta' => null,
            'estado' => 'En revision', 'observaciones' => '', 'causa_rechazo' => '', 'habilitado_envio' => 0,
        ];
        $modalDocumentoAbierto = true;
    } else {
        $modalDocumentoAbierto = false;
    }

    // ---- Vista: ver seguimiento/historial (solo lectura, accion propia) ----
    $documentoVerHistorial = null;
    $historialVerId = (int) ($_GET['historial'] ?? 0);
    if ($historialVerId > 0 && $puedeGestionarDocumentos) {
        $stmt = $pdo->prepare('SELECT id, titulo FROM documentos WHERE id = ? AND ambito = ?');
        $stmt->execute([$historialVerId, $ambitoActual]);
        $documentoVerHistorial = $stmt->fetch() ?: null;
        if ($documentoVerHistorial) {
            $documentoVerHistorial['historial'] = comites_documento_historial($pdo, $historialVerId);
        }
    }

    // ---- Modal: revisar (Aprobar / Observar / Rechazar) ----
    $documentoRevisar = null;
    $revisarId = ($reopenModal === 'documento_revisar' && $reopenId) ? (int) $reopenId : (int) ($_GET['revisar'] ?? 0);
    if ($revisarId > 0 && $puedeGestionarDocumentos) {
        $stmt = $pdo->prepare(
            'SELECT d.*, c.nombre AS comite_nombre FROM documentos d LEFT JOIN comites c ON c.id = d.comite_id WHERE d.id = ? AND d.ambito = ?'
        );
        $stmt->execute([$revisarId, $ambitoActual]);
        $documentoRevisar = $stmt->fetch() ?: null;
    }
}

if ($view === 'correos' && $puedeVerSeccionActual) {
    $correos = fetch_placeholder_rows($pdo, 'SELECT * FROM correos_enviados ORDER BY enviado_en DESC LIMIT 200');
    $documentosParaCorreo = fetch_placeholder_rows(
        $pdo,
        "SELECT id, titulo FROM documentos WHERE habilitado_envio = 1 ORDER BY fecha DESC LIMIT 100"
    );
    // Preselecciona el documento cuando se llega desde el icono "Enviar por
    // correo" de la biblioteca documental (?documento_id=), o al reabrir el
    // formulario tras un error de envio (POST).
    $documentoIdPreseleccionado = (int) ($_POST['documento_id'] ?? $_GET['documento_id'] ?? 0);
}

if ($view === 'tableros' && $puedeVerSeccionActual) {
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM tableros WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (nombre LIKE ? OR tipo LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like);
    }
    $sql .= ' ORDER BY nombre';
    $tableros = fetch_placeholder_rows($pdo, $sql, $params);

    $tableroEditar = null;
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'tablero' && $prefillPost) {
        $tableroEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'nombre' => (string) ($prefillPost['nombre'] ?? ''),
            'descripcion' => (string) ($prefillPost['descripcion'] ?? ''),
            'url' => (string) ($prefillPost['url'] ?? ''),
            'tipo' => (string) ($prefillPost['tipo'] ?? ''),
            'estado' => (string) ($prefillPost['estado'] ?? 'Activo'),
        ];
        $modalTablerosAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM tableros WHERE id = ?');
        $stmt->execute([$editarId]);
        $tableroEditar = $stmt->fetch() ?: null;
        $modalTablerosAbierto = (bool) $tableroEditar;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $tableroEditar = ['id' => 0, 'nombre' => '', 'descripcion' => '', 'url' => '', 'tipo' => '', 'estado' => 'Activo'];
        $modalTablerosAbierto = true;
    } else {
        $modalTablerosAbierto = false;
    }
}

if ($view === 'informes' && $puedeVerSeccionActual) {
    $informes = fetch_placeholder_rows($pdo, 'SELECT * FROM informes_cuatrimestrales ORDER BY fecha DESC, id DESC');

    $informeEditar = null;
    $informeSoportes = [];
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'informe' && $prefillPost) {
        $informeEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'periodo' => (string) ($prefillPost['periodo'] ?? ''),
            'area' => (string) ($prefillPost['area'] ?? ''),
            'evaluacion' => (string) ($prefillPost['evaluacion'] ?? 'En revision'),
            'observaciones' => (string) ($prefillPost['observaciones'] ?? ''),
            'fecha' => (string) ($prefillPost['fecha'] ?? date('Y-m-d')),
            'plan_operativo_nombre' => null, 'plan_operativo_ruta' => null, 'informe_nombre' => null, 'informe_ruta' => null,
        ];
        $modalInformesAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM informes_cuatrimestrales WHERE id = ?');
        $stmt->execute([$editarId]);
        $informeEditar = $stmt->fetch() ?: null;
        if ($informeEditar) {
            $informeSoportes = comites_informe_soportes($pdo, $editarId);
        }
        $modalInformesAbierto = (bool) $informeEditar;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $informeEditar = [
            'id' => 0, 'periodo' => '', 'area' => '', 'evaluacion' => 'En revision', 'observaciones' => '', 'fecha' => date('Y-m-d'),
            'plan_operativo_nombre' => null, 'plan_operativo_ruta' => null, 'informe_nombre' => null, 'informe_ruta' => null,
        ];
        $modalInformesAbierto = true;
    } else {
        $modalInformesAbierto = false;
    }
}

if ($view === 'memorias' && $puedeVerSeccionActual) {
    $memorias = fetch_placeholder_rows(
        $pdo,
        'SELECT m.*, ce.nombre AS revisor_nombre FROM memorias_resultados m LEFT JOIN comite_editorial ce ON ce.id = m.revisor_id ORDER BY m.id DESC'
    );
    $editorialMiembros = fetch_placeholder_rows($pdo, 'SELECT * FROM comite_editorial ORDER BY nombre');
    $editorialActivos = array_values(array_filter($editorialMiembros, static fn(array $r): bool => $r['estado'] === 'Activo'));

    $memoriaEditar = null;
    $memoriaTrack = [];
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'memoria' && $prefillPost) {
        $memoriaEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'numero_memoria' => (string) ($prefillPost['numero_memoria'] ?? ''),
            'titulo' => (string) ($prefillPost['titulo'] ?? ''),
            'autor' => (string) ($prefillPost['autor'] ?? ''),
            'autor_email' => (string) ($prefillPost['autor_email'] ?? ''),
            'fecha' => (string) ($prefillPost['fecha'] ?? date('Y-m-d')),
            'revisor_id' => (int) ($prefillPost['revisor_id'] ?? 0),
            'estado' => (string) ($prefillPost['estado'] ?? 'Recibida'),
            'observaciones_revisor' => (string) ($prefillPost['observaciones_revisor'] ?? ''),
            'archivo_nombre' => null, 'archivo_ruta' => null,
        ];
        $modalMemoriasAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM memorias_resultados WHERE id = ?');
        $stmt->execute([$editarId]);
        $memoriaEditar = $stmt->fetch() ?: null;
        if ($memoriaEditar) {
            $memoriaTrack = comites_memoria_track($pdo, $editarId);
        }
        $modalMemoriasAbierto = (bool) $memoriaEditar;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $memoriaEditar = [
            'id' => 0, 'numero_memoria' => '', 'titulo' => '', 'autor' => '', 'autor_email' => '', 'fecha' => date('Y-m-d'),
            'revisor_id' => 0, 'estado' => 'Recibida', 'observaciones_revisor' => '', 'archivo_nombre' => null, 'archivo_ruta' => null,
        ];
        $modalMemoriasAbierto = true;
    } else {
        $modalMemoriasAbierto = false;
    }

    $editorialEditar = null;
    $editarEditorialId = (int) ($_GET['editar_editorial'] ?? 0);
    if ($reopenModal === 'editorial' && $prefillPost) {
        $editorialEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'nombre' => (string) ($prefillPost['nombre'] ?? ''),
            'cargo' => (string) ($prefillPost['cargo'] ?? ''),
            'email' => (string) ($prefillPost['email'] ?? ''),
            'estado' => (string) ($prefillPost['estado'] ?? 'Activo'),
        ];
        $modalEditorialAbierto = true;
    } elseif ($editarEditorialId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM comite_editorial WHERE id = ?');
        $stmt->execute([$editarEditorialId]);
        $editorialEditar = $stmt->fetch() ?: null;
        $modalEditorialAbierto = (bool) $editorialEditar;
    } elseif (isset($_GET['nuevo_editorial']) && $puedeGestionarSeccionActual) {
        $editorialEditar = ['id' => 0, 'nombre' => '', 'cargo' => '', 'email' => '', 'estado' => 'Activo'];
        $modalEditorialAbierto = true;
    } else {
        $modalEditorialAbierto = false;
    }
}

if ($view === 'publicaciones' && $puedeVerSeccionActual) {
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM publicaciones WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (titulo LIKE ? OR plataforma LIKE ? OR tipo LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY fecha DESC, id DESC';
    $publicaciones = fetch_placeholder_rows($pdo, $sql, $params);

    $publicacionEditar = null;
    $editarId = (int) ($_GET['editar'] ?? 0);
    if ($reopenModal === 'publicacion' && $prefillPost) {
        $publicacionEditar = [
            'id' => (int) ($prefillPost['id'] ?? 0),
            'fecha' => (string) ($prefillPost['fecha'] ?? date('Y-m-d')),
            'plataforma' => (string) ($prefillPost['plataforma'] ?? 'Facebook'),
            'tipo' => (string) ($prefillPost['tipo'] ?? ''),
            'titulo' => (string) ($prefillPost['titulo'] ?? ''),
            'copy_texto' => (string) ($prefillPost['copy_texto'] ?? ''),
            'estado' => (string) ($prefillPost['estado'] ?? 'Borrador'),
            'responsable' => (string) ($prefillPost['responsable'] ?? ''),
            'url' => (string) ($prefillPost['url'] ?? ''),
            'material' => null,
            'alcance' => (int) ($prefillPost['alcance'] ?? 0),
            'impresiones' => (int) ($prefillPost['impresiones'] ?? 0),
            'interacciones' => (int) ($prefillPost['interacciones'] ?? 0),
            'reacciones' => (int) ($prefillPost['reacciones'] ?? 0),
            'comentarios' => (int) ($prefillPost['comentarios'] ?? 0),
            'compartidos' => (int) ($prefillPost['compartidos'] ?? 0),
            'clics' => (int) ($prefillPost['clics'] ?? 0),
            'reproducciones' => (int) ($prefillPost['reproducciones'] ?? 0),
            'guardados' => (int) ($prefillPost['guardados'] ?? 0),
        ];
        $modalPublicacionesAbierto = true;
    } elseif ($editarId > 0 && $puedeGestionarSeccionActual) {
        $stmt = $pdo->prepare('SELECT * FROM publicaciones WHERE id = ?');
        $stmt->execute([$editarId]);
        $publicacionEditar = $stmt->fetch() ?: null;
        $modalPublicacionesAbierto = (bool) $publicacionEditar;
    } elseif (isset($_GET['nuevo']) && $puedeGestionarSeccionActual) {
        $publicacionEditar = [
            'id' => 0, 'fecha' => date('Y-m-d'), 'plataforma' => 'Facebook', 'tipo' => '', 'titulo' => '', 'copy_texto' => '',
            'estado' => 'Borrador', 'responsable' => '', 'url' => '', 'material' => null,
            'alcance' => 0, 'impresiones' => 0, 'interacciones' => 0, 'reacciones' => 0, 'comentarios' => 0,
            'compartidos' => 0, 'clics' => 0, 'reproducciones' => 0, 'guardados' => 0,
        ];
        $modalPublicacionesAbierto = true;
    } else {
        $modalPublicacionesAbierto = false;
    }
}

if ($view === 'estadisticas' && $puedeVerSeccionActual) {
    $kpiAcuerdosPorEstado = fetch_placeholder_rows($pdo, 'SELECT estado, COUNT(*) AS total FROM acuerdos GROUP BY estado');
    $kpiDocumentosPorEstado = fetch_placeholder_rows($pdo, 'SELECT ambito, estado, COUNT(*) AS total FROM documentos GROUP BY ambito, estado ORDER BY ambito, total DESC');
    $kpiPublicacionesPorPlataforma = fetch_placeholder_rows(
        $pdo,
        'SELECT plataforma, COUNT(*) AS total, COALESCE(SUM(alcance),0) AS alcance, COALESCE(SUM(interacciones),0) AS interacciones
         FROM publicaciones GROUP BY plataforma ORDER BY total DESC'
    );
    $kpiReunionesPorComite = fetch_placeholder_rows(
        $pdo,
        'SELECT c.nombre AS comite, COUNT(r.id) AS total
         FROM comites c LEFT JOIN reuniones r ON r.comite_id = c.id
         WHERE c.activo = 1 GROUP BY c.id, c.nombre ORDER BY total DESC'
    );
    $kpiPromedioAvanceAcuerdos = (float) $pdo->query('SELECT COALESCE(AVG(avance),0) FROM acuerdos')->fetchColumn();
    $kpiMemoriasPorEstado = fetch_placeholder_rows($pdo, 'SELECT estado, COUNT(*) AS total FROM memorias_resultados GROUP BY estado');
    $kpiCorreosPorMes = fetch_placeholder_rows(
        $pdo,
        "SELECT DATE_FORMAT(enviado_en, '%Y-%m') AS mes, COUNT(*) AS total FROM correos_enviados GROUP BY mes ORDER BY mes DESC LIMIT 6"
    );
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comites, Transferencia y Comunicacion · CENGICANA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="appSidebar">
        <a class="sidebar-brand" href="index.php?view=dashboard">
            <span class="sidebar-brand-mark"><i class="ti ti-hexagon-letter-c"></i></span>
            <span class="sidebar-brand-copy">
                <strong>CENGICANA</strong>
                <small>Comites, Transferencia y Comunicacion</small>
            </span>
        </a>

        <nav class="sidebar-nav" id="sidebarNav">
            <?php foreach ($groups as $groupLabel => $items): ?>
                <div class="sidebar-nav-group">
                    <div class="sidebar-nav-label"><?= e($groupLabel) ?></div>
                    <?php foreach ($items as $key => $label): ?>
                        <a href="index.php?view=<?= e($key) ?>" class="sidebar-nav-item <?= e($active($key)) ?>">
                            <i class="ti <?= e($sections[$key]['icon']) ?>"></i><span><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <a href="../login/Menu.php" class="sidebar-nav-item sidebar-menu-link">
                <i class="ti ti-home"></i><span>Menu principal</span>
            </a>
            <a href="index.php?logout=1" class="sidebar-nav-item sidebar-logout-link">
                <i class="ti ti-logout-2"></i><span>Cerrar sesion</span>
            </a>
        </div>
    </aside>

    <header class="topbar">
        <button type="button" class="menu-toggle" id="sidebarToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="appSidebar">
            <span></span><span></span><span></span>
        </button>
        <div class="topbar-titles">
            <div class="topbar-title"><?= e($currentSection['label']) ?></div>
            <div class="topbar-sub">Transferencia de Tecnologia · Gestion de informacion</div>
        </div>
        <div class="topbar-right">
            <span class="topbar-pill"><?= e($user['nombre_rol']) ?></span>
            <div class="topbar-userbox">
                <div class="topbar-avatar"><?= e($userInitials) ?></div>
                <div class="topbar-userbox-copy">
                    <div class="topbar-userbox-name"><?= e($user['nombre']) ?></div>
                    <div class="topbar-userbox-role"><?= e($user['nombre_rol']) ?></div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <?php if ($flashMessage !== ''): ?>
            <div class="alert"><i class="ti ti-circle-check"></i> <?= e($flashMessage) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="alert error"><i class="ti ti-alert-circle"></i> <?= e($flashError) ?></div>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title">Panel general</div>
                    <div class="section-sub">Resumen de comites, documentacion y comunicacion.</div>
                </div>
            </div>

            <section class="stats-row">
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-users"></i></div><div><div class="stat-num"><?= $stats['contactos'] ?></div><div class="stat-label">Contactos activos</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-calendar-event"></i></div><div><div class="stat-num"><?= $stats['reuniones'] ?></div><div class="stat-label">Reuniones registradas</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-clipboard-list"></i></div><div><div class="stat-num"><?= $stats['acuerdos'] ?></div><div class="stat-label">Acuerdos en seguimiento</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-affiliate"></i></div><div><div class="stat-num"><?= $stats['comites'] ?></div><div class="stat-label">Comites activos</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-folder"></i></div><div><div class="stat-num"><?= $stats['documentos'] ?></div><div class="stat-label">Documentos · ingenios</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-folder-star"></i></div><div><div class="stat-num"><?= $stats['biblioteca_interna'] ?></div><div class="stat-label">Documentos · biblioteca interna</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-mail"></i></div><div><div class="stat-num"><?= $stats['correos'] ?></div><div class="stat-label">Correos enviados</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-chart-bar"></i></div><div><div class="stat-num"><?= $stats['tableros'] ?></div><div class="stat-label">Tableros activos</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-report"></i></div><div><div class="stat-num"><?= $stats['informes'] ?></div><div class="stat-label">Informes cuatrimestrales</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-book-2"></i></div><div><div class="stat-num"><?= $stats['memorias'] ?></div><div class="stat-label">Articulos de memoria</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-share"></i></div><div><div class="stat-num"><?= $stats['publicaciones'] ?></div><div class="stat-label">Publicaciones en redes</div></div></div>
            </section>

            <div class="dash-grid">
                <div class="quick-card">
                    <a class="quick-link" href="index.php?view=reuniones&nuevo=1">
                        <span class="quick-icon"><i class="ti ti-calendar-event"></i></span>
                        <span>
                            <strong>Registrar reunion</strong>
                            <small>Capture fecha, temas, asistentes y acuerdos.</small>
                        </span>
                    </a>
                    <a class="quick-link" href="index.php?view=documentos">
                        <span class="quick-icon"><i class="ti ti-upload"></i></span>
                        <span>
                            <strong>Agregar documento</strong>
                            <small>Cargue archivos o registre un enlace de boletin.</small>
                        </span>
                    </a>
                </div>
                <div class="control-card">
                    <div class="control-card-title"><i class="ti ti-gauge"></i> Control de gestion</div>
                    <?php
                    $pctAcuerdos = $stats['acuerdos'] > 0 ? (int) round($stats['acuerdos_completados'] / $stats['acuerdos'] * 100) : 0;
                    $pctReuniones = $stats['reuniones'] > 0 ? (int) round($stats['reuniones_realizadas'] / $stats['reuniones'] * 100) : 0;
                    ?>
                    <div class="control-metric">
                        <div class="control-metric-label"><span>Acuerdos completados</span><span><?= $pctAcuerdos ?>%</span></div>
                        <div class="control-bar"><div class="control-bar-fill" style="width:<?= $pctAcuerdos ?>%"></div></div>
                    </div>
                    <div class="control-metric">
                        <div class="control-metric-label"><span>Reuniones realizadas</span><span><?= $pctReuniones ?>%</span></div>
                        <div class="control-bar"><div class="control-bar-fill" style="width:<?= $pctReuniones ?>%"></div></div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <?php if ($view !== 'dashboard'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title"><?= e($currentSection['label']) ?></div>
                    <div class="section-sub">
                        <?php if ($view === 'config'): ?>
                            Catalogo de comites tecnicos utilizado por el resto del modulo.
                        <?php elseif ($view === 'estadisticas'): ?>
                            Indicadores agregados de gestion, documentacion y comunicacion del modulo.
                        <?php else: ?>
                            Alta, edicion y seguimiento en tiempo real.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$puedeVerSeccionActual): ?>
                <div class="placeholder-note">
                    <i class="ti ti-lock"></i>
                    <span>No tienes permiso para ver esta seccion. Solicita acceso al administrador del modulo.</span>
                </div>

            <?php elseif ($view === 'contactos'): ?>
                <div class="section-toolbar">
                    <form class="filters-form" method="get">
                        <input type="hidden" name="view" value="contactos">
                        <div class="search-box">
                            <i class="ti ti-search"></i>
                            <input class="form-control" type="text" name="q" placeholder="Buscar por nombre, cargo o empresa..." value="<?= e($q) ?>">
                        </div>
                        <select class="form-control filter-select" name="comite" onchange="this.form.submit()">
                            <option value="todos" <?= $comiteFiltro === 'todos' ? 'selected' : '' ?>>Todos los comites</option>
                            <?php foreach ($comitesActivos as $c): ?>
                                <option value="<?= e($c['id']) ?>" <?= $comiteFiltro === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Filtrar</button>
                    </form>
                    <div class="toolbar-actions">
                        <a class="btn-outline" href="index.php?view=contactos&accion=plantilla"><i class="ti ti-download"></i> Plantilla</a>
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-primary" href="index.php?view=contactos&nuevo=1"><i class="ti ti-plus"></i> Nuevo contacto</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Nombre</th><th>Cargo</th><th>Empresa / ingenio</th><th>Comites</th><th>Contacto</th><th>Estado</th>
                                <?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$contactos): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay contactos registrados.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($contactos as $c): ?>
                                <tr>
                                    <td><strong><?= e($c['nombre']) ?></strong></td>
                                    <td><?= e($c['cargo'] ?: '—') ?></td>
                                    <td><?= e($c['empresa'] ?: '—') ?></td>
                                    <td>
                                        <div class="chip-list">
                                            <?php foreach ($comitesPorContacto[(int) $c['id']] ?? [] as $cid): ?>
                                                <span class="chip"><?= e($comiteNombrePorId[$cid] ?? $cid) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?= e($c['telefono'] ?: '—') ?><br><span class="text-muted"><?= e($c['email'] ?: '—') ?></span></td>
                                    <td><span class="badge <?= comites_estado_badge_class($c['estado']) ?>"><?= e($c['estado']) ?></span></td>
                                    <?php if ($puedeGestionarSeccionActual): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=contactos&editar=<?= (int) $c['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                            <form method="post" onsubmit="return confirm('¿Eliminar este contacto?');" style="display:inline;">
                                                <input type="hidden" name="accion" value="contacto_eliminar">
                                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($modalContactosAbierto && $contactoEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=contactos">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $contactoEditar['id'] ? 'Editar contacto' : 'Nuevo contacto' ?></h3>
                                <a class="modal-close" href="index.php?view=contactos"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <input type="hidden" name="accion" value="contacto_guardar">
                                <input type="hidden" name="id" value="<?= (int) $contactoEditar['id'] ?>">
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Nombre completo <span class="req">*</span></span>
                                        <input class="form-control" type="text" name="nombre" required value="<?= e($contactoEditar['nombre']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Cargo</span>
                                        <input class="form-control" type="text" name="cargo" value="<?= e($contactoEditar['cargo']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Empresa / ingenio</span>
                                        <input class="form-control" type="text" name="empresa" value="<?= e($contactoEditar['empresa']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Telefono</span>
                                        <input class="form-control" type="text" name="telefono" value="<?= e($contactoEditar['telefono']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Correo</span>
                                        <input class="form-control" type="email" name="email" value="<?= e($contactoEditar['email']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <option value="Activo" <?= $contactoEditar['estado'] === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                            <option value="Inactivo" <?= $contactoEditar['estado'] === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                                        </select></label>
                                </div>
                                <div class="form-group form-full">
                                    <span class="form-label">Comites</span>
                                    <div class="pill-toggle-list">
                                        <?php foreach ($comitesActivos as $c): ?>
                                            <label class="pill-toggle">
                                                <input type="checkbox" name="comites[]" value="<?= e($c['id']) ?>" <?= in_array($c['id'], $contactoEditar['comites'], true) ? 'checked' : '' ?>>
                                                <span><?= e($c['nombre']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=contactos">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'reuniones'): ?>
                <div class="section-toolbar">
                    <form class="filters-form" method="get">
                        <input type="hidden" name="view" value="reuniones">
                        <input type="hidden" name="modo" value="<?= e($modo) ?>">
                        <select class="form-control filter-select" name="comite" onchange="this.form.submit()">
                            <option value="todos" <?= $comiteFiltro === 'todos' ? 'selected' : '' ?>>Todos los comites</option>
                            <?php foreach ($comitesActivos as $c): ?>
                                <option value="<?= e($c['id']) ?>" <?= $comiteFiltro === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a class="btn-outline btn-sm <?= $modo === 'lista' ? 'is-active' : '' ?>" href="index.php?view=reuniones&modo=lista&comite=<?= e($comiteFiltro) ?>">Lista</a>
                        <a class="btn-outline btn-sm <?= $modo === 'calendario' ? 'is-active' : '' ?>" href="index.php?view=reuniones&modo=calendario&comite=<?= e($comiteFiltro) ?>&mes=<?= e($mes) ?>">Calendario</a>
                    </form>
                    <div class="toolbar-actions">
                        <a class="btn-outline" href="index.php?view=reuniones&accion=plantilla"><i class="ti ti-file-text"></i> Plantilla Word</a>
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-primary" href="index.php?view=reuniones&nuevo=1"><i class="ti ti-plus"></i> Registrar reunion</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($modo === 'calendario'): ?>
                    <?php render_meeting_calendar($reuniones, $mes, $comiteNombrePorId); ?>
                <?php else: ?>
                    <div class="table-card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr><th>Comite / fecha</th><th>Temas / proximos pasos</th><th>Asistentes</th><th>Acuerdos</th><th>Responsable</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                <?php if (!$reuniones): ?>
                                    <tr><td colspan="6"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay reuniones registradas.</div></td></tr>
                                <?php endif; ?>
                                <?php foreach ($reuniones as $r): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($comiteNombrePorId[$r['comite_id']] ?? '—') ?></strong>
                                            <div class="text-muted"><?= e((string) $r['fecha']) ?></div>
                                            <span class="badge <?= (int) $r['realizada'] === 1 ? 'badge-completado' : 'badge-pendiente' ?>"><?= (int) $r['realizada'] === 1 ? 'Realizada' : 'Pendiente' ?></span>
                                        </td>
                                        <td class="cell-wide">
                                            <?= e((string) $r['temas']) ?>
                                            <?php if (!empty($r['proximos_pasos'])): ?><div class="text-muted">Proximos: <?= e((string) $r['proximos_pasos']) ?></div><?php endif; ?>
                                        </td>
                                        <td><?= (int) $r['total_asistentes'] ?></td>
                                        <td><?= (int) $r['total_acuerdos'] ?></td>
                                        <td><?= e($r['responsable'] ?: '—') ?></td>
                                        <?php if ($puedeGestionarSeccionActual): ?>
                                            <td class="row-actions">
                                                <a class="icon-btn" href="index.php?view=reuniones&editar=<?= (int) $r['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                                <form method="post" onsubmit="return confirm('¿Eliminar esta reunion?');" style="display:inline;">
                                                    <input type="hidden" name="accion" value="reunion_eliminar">
                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($modalReunionesAbierto && $reunionVM): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=reuniones&modo=<?= e($modo) ?>">
                        <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $reunionVM['id'] ? 'Editar reunion' : 'Registrar reunion' ?></h3>
                                <a class="modal-close" href="index.php?view=reuniones&modo=<?= e($modo) ?>"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body" enctype="multipart/form-data" id="reunionForm">
                                <input type="hidden" name="accion" value="reunion_guardar">
                                <input type="hidden" name="id" value="<?= (int) $reunionVM['id'] ?>">
                                <div class="form-grid form-grid-3">
                                    <label class="form-group"><span class="form-label">Fecha</span>
                                        <input class="form-control" type="date" name="fecha" value="<?= e((string) $reunionVM['fecha']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Comite</span>
                                        <select class="form-control" name="comite_id" id="reunionComiteSelect">
                                            <?php foreach ($comitesActivos as $c): ?>
                                                <option value="<?= e($c['id']) ?>" <?= $reunionVM['comite_id'] === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="realizada">
                                            <option value="realizada" <?= (int) $reunionVM['realizada'] === 1 ? 'selected' : '' ?>>Realizada</option>
                                            <option value="pendiente" <?= (int) $reunionVM['realizada'] === 0 ? 'selected' : '' ?>>Pendiente</option>
                                        </select></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Temas tratados</span>
                                    <textarea class="form-control" rows="3" name="temas"><?= e($reunionVM['temas']) ?></textarea></label>
                                <label class="form-group form-full"><span class="form-label">Proximos pasos</span>
                                    <textarea class="form-control" rows="2" name="proximos_pasos"><?= e($reunionVM['proximos_pasos']) ?></textarea>
                                    <span class="form-hint">Estos pasos quedan registrados para facilitar el seguimiento posterior.</span></label>

                                <label class="form-group form-full"><span class="form-label">Ayuda memoria / documento de la reunion</span>
                                    <input class="form-control" type="file" name="ayuda_memoria" accept=".pdf,.doc,.docx"></label>
                                <?php if (!empty($reunionVM['ayuda_memoria_ruta'])): ?>
                                    <div class="file-current">
                                        <i class="ti ti-file-text"></i>
                                        <a href="<?= e((string) $reunionVM['ayuda_memoria_ruta']) ?>" target="_blank" rel="noopener"><?= e((string) $reunionVM['ayuda_memoria_nombre']) ?></a>
                                        <label class="pill-toggle danger"><input type="checkbox" name="quitar_ayuda_memoria" value="1"><span>Quitar archivo</span></label>
                                    </div>
                                <?php endif; ?>

                                <label class="form-group form-full"><span class="form-label">Responsable principal</span>
                                    <input class="form-control" type="text" name="responsable" id="reunionResponsableInput" value="<?= e($reunionVM['responsable']) ?>"></label>

                                <div class="acuerdos-editor">
                                    <div class="acuerdos-editor-head">
                                        <div>
                                            <h4>Acuerdos de la reunion</h4>
                                            <p class="form-hint">Los acuerdos registrados aqui se crean o actualizan automaticamente en "Acuerdos y seguimiento".</p>
                                        </div>
                                        <button type="button" class="btn-outline btn-sm" id="btnAgregarAcuerdo"><i class="ti ti-plus"></i> Agregar acuerdo</button>
                                    </div>
                                    <div id="acuerdosRows">
                                        <?php if (!$reunionVM['acuerdos']): ?>
                                            <div class="empty-state empty-state-dashed" id="acuerdosEmptyState">Aun no hay acuerdos registrados.</div>
                                        <?php endif; ?>
                                        <?php foreach ($reunionVM['acuerdos'] as $idx => $fila): ?>
                                            <?php render_acuerdo_editor_row($idx, $fila, $puedeGestionarSeccionActual); ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <template id="acuerdoRowTemplate"><?php render_acuerdo_editor_row(null, [], true); ?></template>
                                </div>

                                <label class="form-group form-full"><span class="form-label">Asistentes del comite</span>
                                    <div class="attendee-list" id="attendeeList">
                                        <?php foreach ($contactosDisponibles as $c): ?>
                                            <?php $ids = $comitesPorContactoDisp[(int) $c['id']] ?? []; ?>
                                            <label class="attendee-item" data-comites="<?= e(implode(',', $ids)) ?>">
                                                <input type="checkbox" name="asistentes[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $reunionVM['asistentes'], true) ? 'checked' : '' ?>>
                                                <span><?= e($c['nombre']) ?><small><?= e($c['cargo'] ?: 'Contacto adicional') ?></small></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="form-hint">Primero se muestran los contactos del comite seleccionado. Los adicionales se incorporan a la base de datos.</span>
                                </label>

                                <div class="extra-attendee-box">
                                    <div class="form-label">Agregar asistente adicional</div>
                                    <div class="form-grid form-grid-4">
                                        <input class="form-control" type="text" id="extraNombre" placeholder="Nombre">
                                        <input class="form-control" type="text" id="extraCargo" placeholder="Cargo">
                                        <input class="form-control" type="text" id="extraEmpresa" placeholder="Empresa">
                                        <input class="form-control" type="email" id="extraEmail" placeholder="Correo">
                                    </div>
                                    <button type="button" class="btn-outline btn-sm" id="btnAgregarAsistente" style="margin-top:8px;"><i class="ti ti-plus"></i> Agregar a la base de datos</button>
                                    <div id="extraAsistenteMsg" class="form-hint"></div>
                                </div>

                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=reuniones&modo=<?= e($modo) ?>">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar reunion</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'acuerdos'): ?>
                <div class="section-toolbar">
                    <form class="filters-form" method="get">
                        <input type="hidden" name="view" value="acuerdos">
                        <div class="search-box">
                            <i class="ti ti-search"></i>
                            <input class="form-control" type="text" name="q" placeholder="Buscar acuerdo, responsable o comite..." value="<?= e($q) ?>">
                        </div>
                        <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Buscar</button>
                    </form>
                    <div class="toolbar-actions">
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-primary" href="index.php?view=acuerdos&nuevo=1"><i class="ti ti-plus"></i> Nuevo acuerdo</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr><th>Comite / fecha</th><th>Acuerdo</th><th>Responsable</th><th>Plazo</th><th>Avance</th><th>Estado</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr>
                            </thead>
                            <tbody>
                            <?php if (!$acuerdos): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay acuerdos registrados.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($acuerdos as $a): ?>
                                <tr>
                                    <td><strong><?= e($a['comite_nombre'] ?? '—') ?></strong><div class="text-muted"><?= e((string) $a['fecha']) ?></div></td>
                                    <td class="cell-wide"><?= e($a['acuerdo']) ?></td>
                                    <td><?= e($a['responsable'] ?: '—') ?></td>
                                    <td><?= e((string) ($a['plazo'] ?: '—')) ?><div class="text-muted">Seguimiento: <?= e((string) ($a['fecha_seguimiento'] ?: '—')) ?></div></td>
                                    <td class="cell-progress">
                                        <div class="progress-track"><div class="progress-fill" style="width:<?= (int) $a['avance'] ?>%"></div></div>
                                        <span><?= (int) $a['avance'] ?>%</span>
                                    </td>
                                    <td><span class="badge <?= comites_estado_badge_class($a['estado']) ?>"><?= e($a['estado']) ?></span></td>
                                    <?php if ($puedeGestionarSeccionActual): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=acuerdos&editar=<?= (int) $a['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                            <form method="post" onsubmit="return confirm('¿Eliminar este acuerdo?');" style="display:inline;">
                                                <input type="hidden" name="accion" value="acuerdo_eliminar">
                                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($modalAcuerdosAbierto && $acuerdoEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=acuerdos">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $acuerdoEditar['id'] ? 'Editar acuerdo' : 'Nuevo acuerdo' ?></h3>
                                <a class="modal-close" href="index.php?view=acuerdos"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <input type="hidden" name="accion" value="acuerdo_guardar">
                                <input type="hidden" name="id" value="<?= (int) $acuerdoEditar['id'] ?>">
                                <label class="form-group form-full"><span class="form-label">Comite</span>
                                    <select class="form-control" name="comite_id">
                                        <?php foreach ($comitesActivos as $c): ?>
                                            <option value="<?= e($c['id']) ?>" <?= $acuerdoEditar['comite_id'] === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select></label>
                                <label class="form-group form-full"><span class="form-label">Acuerdo <span class="req">*</span></span>
                                    <textarea class="form-control" rows="3" name="acuerdo" required><?= e($acuerdoEditar['acuerdo']) ?></textarea></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Responsable</span>
                                        <input class="form-control" type="text" name="responsable" value="<?= e($acuerdoEditar['responsable']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Fecha limite</span>
                                        <input class="form-control" type="date" name="plazo" value="<?= e((string) $acuerdoEditar['plazo']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Fecha de seguimiento</span>
                                        <input class="form-control" type="date" name="fecha_seguimiento" value="<?= e((string) ($acuerdoEditar['fecha_seguimiento'] ?? '')) ?>"></label>
                                    <label class="form-group"><span class="form-label">Avance (%)</span>
                                        <input class="form-control" type="number" min="0" max="100" name="avance" value="<?= (int) $acuerdoEditar['avance'] ?>"></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <?php foreach (['Pendiente', 'En proceso', 'Completado'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $acuerdoEditar['estado'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                </div>
                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=acuerdos">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'documentos' || $view === 'biblioteca_interna'): ?>
                <?php $volverHref = 'index.php?view=' . $view . ($carpetaId !== null ? '&carpeta=' . $carpetaId : ''); ?>
                <div class="folder-breadcrumb">
                    <a href="index.php?view=<?= e($view) ?>"><i class="ti ti-folder"></i> Biblioteca</a>
                    <?php foreach ($breadcrumbCarpetas as $bc): ?>
                        <span class="sep">/</span>
                        <a href="index.php?view=<?= e($view) ?>&carpeta=<?= (int) $bc['id'] ?>"><?= e((string) $bc['nombre']) ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if ($puedeGestionarDocumentos): ?>
                    <div class="toolbar-actions" style="margin-bottom:16px;">
                        <a class="btn-outline btn-sm" href="<?= e($volverHref) ?>&nueva_carpeta=1"><i class="ti ti-folder-plus"></i> Nueva carpeta</a>
                        <?php if ($carpetaId !== null): ?>
                            <a class="btn-primary btn-sm" href="<?= e($volverHref) ?>&nuevo=1"><i class="ti ti-plus"></i> Nuevo documento</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($subcarpetas): ?>
                    <div class="folder-grid">
                        <?php foreach ($subcarpetas as $sc): ?>
                            <a class="folder-card" href="index.php?view=<?= e($view) ?>&carpeta=<?= (int) $sc['id'] ?>">
                                <span class="folder-card-icon"><i class="ti ti-folder"></i></span>
                                <span>
                                    <span class="folder-card-name"><?= e((string) $sc['nombre']) ?></span>
                                    <span class="folder-card-count"><?= (int) $sc['total_documentos'] ?> documento(s)</span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($carpetaId === null): ?>
                    <div class="empty-state-dashed" style="margin-bottom:20px;">
                        <i class="ti ti-folder-off"></i> Todavia no hay carpetas creadas en esta biblioteca.
                        <?php if ($puedeGestionarDocumentos): ?> Crea la primera carpeta para comenzar a cargar documentos.<?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($carpetaId !== null): ?>
                    <div class="section-toolbar">
                        <form class="filters-form" method="get">
                            <input type="hidden" name="view" value="<?= e($view) ?>">
                            <input type="hidden" name="carpeta" value="<?= (int) $carpetaId ?>">
                            <div class="search-box">
                                <i class="ti ti-search"></i>
                                <input class="form-control" type="text" name="q" placeholder="Buscar por titulo o autor..." value="<?= e($q) ?>">
                            </div>
                            <select class="form-control filter-select" name="comite" onchange="this.form.submit()">
                                <option value="todos" <?= $comiteFiltro === 'todos' ? 'selected' : '' ?>>Todos los comites</option>
                                <?php foreach ($comitesActivos as $c): ?>
                                    <option value="<?= e($c['id']) ?>" <?= $comiteFiltro === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Filtrar</button>
                        </form>
                    </div>

                    <div class="table-card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Titulo</th><th>Tipo</th><th>Fecha</th><th>Comite</th><th>Autor</th><th>Estado</th><th>Version</th>
                                    <?php if ($puedeGestionarDocumentos): ?><th></th><?php endif; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!$documentos): ?>
                                    <tr><td colspan="8"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay documentos en esta carpeta.</div></td></tr>
                                <?php endif; ?>
                                <?php foreach ($documentos as $d): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($d['titulo']) ?></strong>
                                            <?php if (!empty($d['archivo_ruta'])): ?>
                                                <div class="text-muted"><a href="<?= e((string) $d['archivo_ruta']) ?>" target="_blank" rel="noopener"><i class="ti ti-paperclip"></i> <?= e((string) $d['archivo_nombre']) ?></a></div>
                                            <?php elseif (!empty($d['enlace'])): ?>
                                                <div class="text-muted"><a href="<?= e((string) $d['enlace']) ?>" target="_blank" rel="noopener"><i class="ti ti-link"></i> Enlace externo</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($d['tipo']) ?></td>
                                        <td><?= e((string) ($d['fecha'] ?: '—')) ?></td>
                                        <td><?= e($d['comite_nombre'] ?? '—') ?></td>
                                        <td><?= e($d['autor'] ?: '—') ?></td>
                                        <td><span class="badge <?= comites_estado_badge_class((string) $d['estado']) ?>"><?= e((string) $d['estado']) ?></span></td>
                                        <td>v<?= (int) $d['version'] ?></td>
                                        <?php if ($puedeGestionarDocumentos): ?>
                                            <td class="row-actions">
                                                <a class="icon-btn" href="<?= e($volverHref) ?>&editar=<?= (int) $d['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                                <a class="icon-btn" href="<?= e($volverHref) ?>&revisar=<?= (int) $d['id'] ?>" title="Revisar"><i class="ti ti-clipboard-check"></i></a>
                                                <a class="icon-btn" href="<?= e($volverHref) ?>&historial=<?= (int) $d['id'] ?>" title="Ver seguimiento"><i class="ti ti-history"></i></a>
                                                <?php if (!empty($d['archivo_ruta'])): ?>
                                                    <a class="icon-btn" href="<?= e((string) $d['archivo_ruta']) ?>" target="_blank" rel="noopener" title="Descargar"><i class="ti ti-download"></i></a>
                                                <?php endif; ?>
                                                <?php if ((int) $d['habilitado_envio'] === 1): ?>
                                                    <a class="icon-btn" href="index.php?view=correos&documento_id=<?= (int) $d['id'] ?>" title="Enviar por correo"><i class="ti ti-mail"></i></a>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm('¿Eliminar este documento?');" style="display:inline;">
                                                    <input type="hidden" name="accion" value="documento_eliminar">
                                                    <input type="hidden" name="ambito" value="<?= e($ambitoActual) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                                    <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($modalCarpetaAbierto): ?>
                    <div class="modal-backdrop is-open" data-close-href="<?= e($volverHref) ?>">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3>Nueva carpeta</h3>
                                <a class="modal-close" href="<?= e($volverHref) ?>"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <input type="hidden" name="accion" value="carpeta_crear">
                                <input type="hidden" name="ambito" value="<?= e($ambitoActual) ?>">
                                <input type="hidden" name="parent_id" value="<?= $carpetaId !== null ? (int) $carpetaId : '' ?>">
                                <label class="form-group form-full"><span class="form-label">Nombre de la carpeta <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="nombre" required value="<?= e($carpetaNuevaNombre) ?>" placeholder="Ej. Boletines 2026"></label>
                                <div class="actions">
                                    <a class="btn-outline" href="<?= e($volverHref) ?>">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Crear carpeta</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($modalDocumentoAbierto && $documentoEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="<?= e($volverHref) ?>">
                        <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $documentoEditar['id'] ? 'Editar documento' : 'Nuevo documento' ?></h3>
                                <a class="modal-close" href="<?= e($volverHref) ?>"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="documento_guardar">
                                <input type="hidden" name="ambito" value="<?= e($ambitoActual) ?>">
                                <input type="hidden" name="id" value="<?= (int) $documentoEditar['id'] ?>">
                                <input type="hidden" name="carpeta_id" value="<?= $carpetaId !== null ? (int) $carpetaId : '' ?>">
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Tipo</span>
                                        <select class="form-control" name="tipo">
                                            <?php foreach (['Boletin', 'Memoria', 'Informe', 'Ayudamemoria', 'Presentacion', 'Fotografia', 'Video', 'Otro'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $documentoEditar['tipo'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="form-group"><span class="form-label">Fecha</span>
                                        <input class="form-control" type="date" name="fecha" value="<?= e((string) $documentoEditar['fecha']) ?>"></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Titulo <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="titulo" required value="<?= e((string) $documentoEditar['titulo']) ?>"></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Comite</span>
                                        <select class="form-control" name="comite_id">
                                            <option value="">— Sin comite —</option>
                                            <?php foreach ($comitesActivos as $c): ?>
                                                <option value="<?= e($c['id']) ?>" <?= $documentoEditar['comite_id'] === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="form-group"><span class="form-label">Autor</span>
                                        <input class="form-control" type="text" name="autor" value="<?= e((string) $documentoEditar['autor']) ?>"></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Descripcion</span>
                                    <textarea class="form-control" rows="2" name="descripcion"><?= e((string) $documentoEditar['descripcion']) ?></textarea></label>

                                <label class="form-group form-full"><span class="form-label">Archivo</span>
                                    <input class="form-control" type="file" name="archivo" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png"></label>
                                <?php if (!empty($documentoEditar['archivo_ruta'])): ?>
                                    <div class="file-current"><i class="ti ti-file-text"></i>
                                        <a href="<?= e((string) $documentoEditar['archivo_ruta']) ?>" target="_blank" rel="noopener"><?= e((string) $documentoEditar['archivo_nombre']) ?></a>
                                        <span class="text-muted">v<?= (int) ($documentoEditar['version'] ?? 1) ?></span>
                                    </div>
                                <?php endif; ?>
                                <label class="form-group form-full"><span class="form-label">O enlace externo (boletin, video, repositorio...)</span>
                                    <input class="form-control" type="url" name="enlace" value="<?= e((string) $documentoEditar['enlace']) ?>" placeholder="https://..."></label>

                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <?php foreach (['En revision', 'Aprobado', 'Con observaciones', 'Rechazado', 'Publicado'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $documentoEditar['estado'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="pill-toggle" style="align-self:end;">
                                        <input type="checkbox" name="habilitado_envio" value="1" <?= (int) ($documentoEditar['habilitado_envio'] ?? 0) === 1 ? 'checked' : '' ?>>
                                        <span>Habilitado para envio por correo</span>
                                    </label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Observaciones de la revision</span>
                                    <textarea class="form-control" rows="2" name="observaciones"><?= e((string) $documentoEditar['observaciones']) ?></textarea></label>
                                <label class="form-group form-full"><span class="form-label">Causa de rechazo (si aplica)</span>
                                    <input class="form-control" type="text" name="causa_rechazo" value="<?= e((string) $documentoEditar['causa_rechazo']) ?>"></label>

                                <div class="actions">
                                    <a class="btn-outline" href="<?= e($volverHref) ?>">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($documentoVerHistorial): ?>
                    <div class="modal-backdrop is-open" data-close-href="<?= e($volverHref) ?>">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3>Seguimiento: <?= e((string) $documentoVerHistorial['titulo']) ?></h3>
                                <a class="modal-close" href="<?= e($volverHref) ?>"><i class="ti ti-x"></i></a>
                            </div>
                            <div class="modal-body">
                                <?php if ($documentoVerHistorial['historial']): ?>
                                    <div class="timeline">
                                        <?php foreach ($documentoVerHistorial['historial'] as $h): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-title"><?= e((string) $h['estado']) ?></div>
                                                <div class="timeline-meta"><?= e((string) $h['actor']) ?> · <?= e((string) $h['fecha']) ?></div>
                                                <?php if (!empty($h['observaciones'])): ?><div class="timeline-obs"><?= e((string) $h['observaciones']) ?></div><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay historial para este documento.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($documentoRevisar): ?>
                    <div class="modal-backdrop is-open" data-close-href="<?= e($volverHref) ?>">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3>Revisar documento</h3>
                                <a class="modal-close" href="<?= e($volverHref) ?>"><i class="ti ti-x"></i></a>
                            </div>
                            <div class="modal-body">
                                <div class="form-group form-full">
                                    <span class="form-label">Titulo</span>
                                    <div><?= e((string) $documentoRevisar['titulo']) ?></div>
                                </div>
                                <div class="form-grid">
                                    <div class="form-group"><span class="form-label">Tipo</span><div><?= e((string) $documentoRevisar['tipo']) ?></div></div>
                                    <div class="form-group"><span class="form-label">Comite</span><div><?= e((string) ($documentoRevisar['comite_nombre'] ?? '—')) ?></div></div>
                                    <div class="form-group"><span class="form-label">Autor</span><div><?= e((string) ($documentoRevisar['autor'] ?: '—')) ?></div></div>
                                    <div class="form-group"><span class="form-label">Estado actual</span>
                                        <div><span class="badge <?= comites_estado_badge_class((string) $documentoRevisar['estado']) ?>"><?= e((string) $documentoRevisar['estado']) ?></span></div></div>
                                </div>
                                <?php if (!empty($documentoRevisar['archivo_ruta'])): ?>
                                    <div class="file-current"><i class="ti ti-file-text"></i>
                                        <a href="<?= e((string) $documentoRevisar['archivo_ruta']) ?>" target="_blank" rel="noopener"><?= e((string) $documentoRevisar['archivo_nombre']) ?></a>
                                    </div>
                                <?php elseif (!empty($documentoRevisar['enlace'])): ?>
                                    <div class="file-current"><i class="ti ti-link"></i>
                                        <a href="<?= e((string) $documentoRevisar['enlace']) ?>" target="_blank" rel="noopener">Enlace externo</a>
                                    </div>
                                <?php endif; ?>

                                <form method="post">
                                    <input type="hidden" name="accion" value="documento_revisar">
                                    <input type="hidden" name="ambito" value="<?= e($ambitoActual) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $documentoRevisar['id'] ?>">
                                    <label class="form-group form-full"><span class="form-label">Observaciones (obligatorio si rechazas)</span>
                                        <textarea class="form-control" rows="3" name="observaciones"><?= e((string) ($prefillPost['observaciones'] ?? '')) ?></textarea></label>
                                    <div class="actions">
                                        <button type="submit" class="btn-outline" name="decision" value="observar"><i class="ti ti-message-circle"></i> Observar</button>
                                        <button type="submit" class="btn-danger" name="decision" value="rechazar"><i class="ti ti-x"></i> Rechazar</button>
                                        <button type="submit" class="btn-primary" name="decision" value="aprobar"><i class="ti ti-check"></i> Aprobar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'correos'): ?>
                <?php $puedeGestionarCorreos = can_manage_resource($user, 'correos'); ?>
                <?php if ($puedeGestionarCorreos): ?>
                    <div class="form-card" style="margin-bottom:18px;">
                        <form method="post">
                            <input type="hidden" name="accion" value="correo_enviar">
                            <div class="form-grid">
                                <label class="form-group"><span class="form-label">Grupo de destinatarios</span>
                                    <select class="form-control" name="grupo_destino">
                                        <option value="todos" <?= (($_POST['grupo_destino'] ?? 'todos') === 'todos') ? 'selected' : '' ?>>Todos los contactos</option>
                                        <?php foreach ($comitesActivos as $c): ?>
                                            <option value="<?= e($c['id']) ?>" <?= (($_POST['grupo_destino'] ?? '') === $c['id']) ? 'selected' : '' ?>>Contactos de <?= e($c['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select></label>
                                <label class="form-group"><span class="form-label">Documento relacionado (opcional)</span>
                                    <select class="form-control" name="documento_id">
                                        <option value="0">— Ninguno —</option>
                                        <?php foreach ($documentosParaCorreo as $doc): ?>
                                            <option value="<?= (int) $doc['id'] ?>" <?= ($documentoIdPreseleccionado === (int) $doc['id']) ? 'selected' : '' ?>><?= e((string) $doc['titulo']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="form-hint">Solo se listan documentos marcados como "Habilitado para envio".</span></label>
                            </div>
                            <label class="form-group form-full"><span class="form-label">Asunto <span class="req">*</span></span>
                                <input class="form-control" type="text" name="asunto" required value="<?= e((string) ($_POST['asunto'] ?? '')) ?>"></label>
                            <label class="form-group form-full"><span class="form-label">Mensaje <span class="req">*</span></span>
                                <textarea class="form-control" rows="5" name="cuerpo" required><?= e((string) ($_POST['cuerpo'] ?? '')) ?></textarea></label>
                            <div class="actions">
                                <button type="submit" class="btn-primary"><i class="ti ti-send"></i> Enviar correo</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Asunto</th><th>Grupo destino</th><th>Destinatarios</th><th>Enviado por</th><th>Fecha</th></tr></thead>
                            <tbody>
                            <?php if (!$correos): ?>
                                <tr><td colspan="5"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no se han enviado correos.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($correos as $co): ?>
                                <tr>
                                    <td><strong><?= e((string) $co['asunto']) ?></strong></td>
                                    <td><?= e((string) ($co['grupo_destino'] ?: '—')) ?></td>
                                    <td class="cell-wide text-muted"><?= e((string) ($co['destinatarios'] ?: '—')) ?></td>
                                    <td><?= e((string) ($co['enviado_por'] ?: '—')) ?></td>
                                    <td><?= e((string) $co['enviado_en']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($view === 'tableros'): ?>
                <div class="section-toolbar">
                    <form class="filters-form" method="get">
                        <input type="hidden" name="view" value="tableros">
                        <div class="search-box">
                            <i class="ti ti-search"></i>
                            <input class="form-control" type="text" name="q" placeholder="Buscar tablero..." value="<?= e($q) ?>">
                        </div>
                        <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Buscar</button>
                    </form>
                    <div class="toolbar-actions">
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-primary" href="index.php?view=tableros&nuevo=1"><i class="ti ti-plus"></i> Nuevo tablero</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nombre</th><th>Tipo</th><th>Enlace</th><th>Estado</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php if (!$tableros): ?>
                                <tr><td colspan="5"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay tableros registrados.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($tableros as $t): ?>
                                <tr>
                                    <td><strong><?= e($t['nombre']) ?></strong><?php if (!empty($t['descripcion'])): ?><div class="text-muted"><?= e((string) $t['descripcion']) ?></div><?php endif; ?></td>
                                    <td><?= e((string) ($t['tipo'] ?: '—')) ?></td>
                                    <td><?php if (!empty($t['url'])): ?><a href="<?= e((string) $t['url']) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Abrir</a><?php else: ?>—<?php endif; ?></td>
                                    <td><span class="badge <?= comites_estado_badge_class((string) $t['estado']) ?>"><?= e((string) $t['estado']) ?></span></td>
                                    <?php if ($puedeGestionarSeccionActual): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=tableros&editar=<?= (int) $t['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                            <form method="post" onsubmit="return confirm('¿Eliminar este tablero?');" style="display:inline;">
                                                <input type="hidden" name="accion" value="tablero_eliminar">
                                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                                <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($modalTablerosAbierto && $tableroEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=tableros">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $tableroEditar['id'] ? 'Editar tablero' : 'Nuevo tablero' ?></h3>
                                <a class="modal-close" href="index.php?view=tableros"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <input type="hidden" name="accion" value="tablero_guardar">
                                <input type="hidden" name="id" value="<?= (int) $tableroEditar['id'] ?>">
                                <label class="form-group form-full"><span class="form-label">Nombre <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="nombre" required value="<?= e($tableroEditar['nombre']) ?>"></label>
                                <label class="form-group form-full"><span class="form-label">Descripcion</span>
                                    <textarea class="form-control" rows="2" name="descripcion"><?= e($tableroEditar['descripcion']) ?></textarea></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Enlace embebido (Power BI, Looker, etc.)</span>
                                        <input class="form-control" type="url" name="url" value="<?= e($tableroEditar['url']) ?>" placeholder="https://..."></label>
                                    <label class="form-group"><span class="form-label">Tipo</span>
                                        <input class="form-control" type="text" name="tipo" value="<?= e($tableroEditar['tipo']) ?>" placeholder="Power BI, Looker Studio..."></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <option value="Activo" <?= $tableroEditar['estado'] === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                            <option value="Inactivo" <?= $tableroEditar['estado'] === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                                        </select></label>
                                </div>
                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=tableros">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'informes'): ?>
                <div class="section-toolbar">
                    <div></div>
                    <div class="toolbar-actions">
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-primary" href="index.php?view=informes&nuevo=1"><i class="ti ti-plus"></i> Nuevo informe</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Periodo</th><th>Area</th><th>Plan operativo</th><th>Informe</th><th>Evaluacion</th><th>Fecha</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php if (!$informes): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay informes cuatrimestrales registrados.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($informes as $inf): ?>
                                <tr>
                                    <td><strong><?= e($inf['periodo']) ?></strong></td>
                                    <td><?= e((string) ($inf['area'] ?: '—')) ?></td>
                                    <td><?php if (!empty($inf['plan_operativo_ruta'])): ?><a href="<?= e((string) $inf['plan_operativo_ruta']) ?>" target="_blank" rel="noopener"><i class="ti ti-file-text"></i> Ver</a><?php else: ?>—<?php endif; ?></td>
                                    <td><?php if (!empty($inf['informe_ruta'])): ?><a href="<?= e((string) $inf['informe_ruta']) ?>" target="_blank" rel="noopener"><i class="ti ti-file-text"></i> Ver</a><?php else: ?>—<?php endif; ?></td>
                                    <td><span class="badge <?= comites_estado_badge_class((string) $inf['evaluacion']) ?>"><?= e((string) $inf['evaluacion']) ?></span></td>
                                    <td><?= e((string) ($inf['fecha'] ?: '—')) ?></td>
                                    <?php if ($puedeGestionarSeccionActual): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=informes&editar=<?= (int) $inf['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                            <form method="post" onsubmit="return confirm('¿Eliminar este informe?');" style="display:inline;">
                                                <input type="hidden" name="accion" value="informe_eliminar">
                                                <input type="hidden" name="id" value="<?= (int) $inf['id'] ?>">
                                                <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($modalInformesAbierto && $informeEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=informes">
                        <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $informeEditar['id'] ? 'Editar informe cuatrimestral' : 'Nuevo informe cuatrimestral' ?></h3>
                                <a class="modal-close" href="index.php?view=informes"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="informe_guardar">
                                <input type="hidden" name="id" value="<?= (int) $informeEditar['id'] ?>">
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Periodo <span class="req">*</span></span>
                                        <input class="form-control" type="text" name="periodo" required value="<?= e($informeEditar['periodo']) ?>" placeholder="I cuatrimestre 2026"></label>
                                    <label class="form-group"><span class="form-label">Area</span>
                                        <input class="form-control" type="text" name="area" value="<?= e((string) $informeEditar['area']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Fecha</span>
                                        <input class="form-control" type="date" name="fecha" value="<?= e((string) $informeEditar['fecha']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Evaluacion</span>
                                        <select class="form-control" name="evaluacion">
                                            <?php foreach (['En revision', 'Satisfactorio', 'Con observaciones', 'No satisfactorio'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $informeEditar['evaluacion'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Observaciones</span>
                                    <textarea class="form-control" rows="2" name="observaciones"><?= e((string) $informeEditar['observaciones']) ?></textarea></label>

                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Plan operativo</span>
                                        <input class="form-control" type="file" name="plan_operativo" accept=".pdf,.doc,.docx,.xls,.xlsx"></label>
                                    <label class="form-group"><span class="form-label">Informe</span>
                                        <input class="form-control" type="file" name="informe" accept=".pdf,.doc,.docx"></label>
                                </div>
                                <?php if (!empty($informeEditar['plan_operativo_ruta']) || !empty($informeEditar['informe_ruta'])): ?>
                                    <div class="file-current">
                                        <?php if (!empty($informeEditar['plan_operativo_ruta'])): ?>
                                            <span><i class="ti ti-file-text"></i> <a href="<?= e((string) $informeEditar['plan_operativo_ruta']) ?>" target="_blank" rel="noopener">Plan operativo actual</a></span>
                                        <?php endif; ?>
                                        <?php if (!empty($informeEditar['informe_ruta'])): ?>
                                            <span><i class="ti ti-file-text"></i> <a href="<?= e((string) $informeEditar['informe_ruta']) ?>" target="_blank" rel="noopener">Informe actual</a></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <label class="form-group form-full"><span class="form-label">Soportes / evidencias adicionales</span>
                                    <input class="form-control" type="file" name="soportes[]" multiple></label>
                                <?php if ($informeSoportes): ?>
                                    <div class="form-group form-full">
                                        <span class="form-label">Soportes ya cargados</span>
                                        <div class="pill-toggle-list">
                                            <?php foreach ($informeSoportes as $sop): ?>
                                                <label class="pill-toggle danger">
                                                    <input type="checkbox" name="eliminar_soportes[]" value="<?= (int) $sop['id'] ?>">
                                                    <span><i class="ti ti-file"></i> <?= e((string) $sop['nombre']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <span class="form-hint">Marca los soportes que quieras eliminar al guardar.</span>
                                    </div>
                                <?php endif; ?>

                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=informes">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'memorias'): ?>
                <div class="section-toolbar">
                    <div></div>
                    <div class="toolbar-actions">
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-outline" href="index.php?view=memorias&nuevo_editorial=1"><i class="ti ti-users"></i> Comite editorial</a>
                            <a class="btn-primary" href="index.php?view=memorias&nuevo=1"><i class="ti ti-plus"></i> Nueva memoria</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>No. memoria</th><th>Titulo</th><th>Autor</th><th>Revisor</th><th>Estado</th><th>Version</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php if (!$memorias): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay memorias registradas.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($memorias as $m): ?>
                                <tr>
                                    <td><?= e((string) ($m['numero_memoria'] ?: '—')) ?></td>
                                    <td>
                                        <strong><?= e($m['titulo']) ?></strong>
                                        <?php if (!empty($m['archivo_ruta'])): ?>
                                            <div class="text-muted"><a href="<?= e((string) $m['archivo_ruta']) ?>" target="_blank" rel="noopener"><i class="ti ti-paperclip"></i> <?= e((string) $m['archivo_nombre']) ?></a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($m['autor'] ?: '—')) ?></td>
                                    <td><?= e((string) ($m['revisor_nombre'] ?: '—')) ?></td>
                                    <td><span class="badge <?= comites_estado_badge_class((string) $m['estado']) ?>"><?= e((string) $m['estado']) ?></span></td>
                                    <td>v<?= (int) $m['version'] ?></td>
                                    <?php if ($puedeGestionarSeccionActual): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=memorias&editar=<?= (int) $m['id'] ?>" title="Editar / revisar"><i class="ti ti-edit"></i></a>
                                            <form method="post" onsubmit="return confirm('¿Eliminar esta memoria?');" style="display:inline;">
                                                <input type="hidden" name="accion" value="memoria_eliminar">
                                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                                <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($editorialMiembros): ?>
                    <div class="card" style="padding:16px; margin-top:16px;">
                        <div class="card-title"><i class="ti ti-users"></i> Comite editorial</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Nombre</th><th>Cargo</th><th>Correo</th><th>Estado</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr></thead>
                                <tbody>
                                <?php foreach ($editorialMiembros as $ed): ?>
                                    <tr>
                                        <td><strong><?= e($ed['nombre']) ?></strong></td>
                                        <td><?= e((string) ($ed['cargo'] ?: '—')) ?></td>
                                        <td><?= e((string) ($ed['email'] ?: '—')) ?></td>
                                        <td><span class="badge <?= comites_estado_badge_class((string) $ed['estado']) ?>"><?= e((string) $ed['estado']) ?></span></td>
                                        <?php if ($puedeGestionarSeccionActual): ?>
                                            <td class="row-actions">
                                                <a class="icon-btn" href="index.php?view=memorias&editar_editorial=<?= (int) $ed['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                                <form method="post" onsubmit="return confirm('¿Eliminar este integrante?');" style="display:inline;">
                                                    <input type="hidden" name="accion" value="editorial_eliminar">
                                                    <input type="hidden" name="id" value="<?= (int) $ed['id'] ?>">
                                                    <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($modalMemoriasAbierto && $memoriaEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=memorias">
                        <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $memoriaEditar['id'] ? 'Editar memoria' : 'Nueva memoria' ?></h3>
                                <a class="modal-close" href="index.php?view=memorias"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="memoria_guardar">
                                <input type="hidden" name="id" value="<?= (int) $memoriaEditar['id'] ?>">
                                <div class="form-grid form-grid-3">
                                    <label class="form-group"><span class="form-label">No. de memoria</span>
                                        <input class="form-control" type="text" name="numero_memoria" value="<?= e((string) $memoriaEditar['numero_memoria']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Fecha</span>
                                        <input class="form-control" type="date" name="fecha" value="<?= e((string) $memoriaEditar['fecha']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <?php foreach (['Recibida', 'En revision', 'Aprobada', 'Rechazada', 'Publicada'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $memoriaEditar['estado'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Titulo <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="titulo" required value="<?= e((string) $memoriaEditar['titulo']) ?>"></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Autor</span>
                                        <input class="form-control" type="text" name="autor" value="<?= e((string) $memoriaEditar['autor']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Correo del autor</span>
                                        <input class="form-control" type="email" name="autor_email" value="<?= e((string) $memoriaEditar['autor_email']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Revisor asignado</span>
                                        <select class="form-control" name="revisor_id">
                                            <option value="0">— Sin asignar —</option>
                                            <?php foreach ($editorialActivos as $ed): ?>
                                                <option value="<?= (int) $ed['id'] ?>" <?= (int) ($memoriaEditar['revisor_id'] ?? 0) === (int) $ed['id'] ? 'selected' : '' ?>><?= e($ed['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Archivo de la memoria</span>
                                    <input class="form-control" type="file" name="archivo" accept=".pdf,.doc,.docx"></label>
                                <?php if (!empty($memoriaEditar['archivo_ruta'])): ?>
                                    <div class="file-current"><i class="ti ti-file-text"></i>
                                        <a href="<?= e((string) $memoriaEditar['archivo_ruta']) ?>" target="_blank" rel="noopener"><?= e((string) $memoriaEditar['archivo_nombre']) ?></a>
                                        <span class="text-muted">v<?= (int) ($memoriaEditar['version'] ?? 1) ?></span>
                                    </div>
                                <?php endif; ?>
                                <label class="form-group form-full"><span class="form-label">Observaciones del revisor</span>
                                    <textarea class="form-control" rows="2" name="observaciones_revisor"><?= e((string) $memoriaEditar['observaciones_revisor']) ?></textarea></label>

                                <?php if ($memoriaTrack): ?>
                                    <div class="form-group form-full">
                                        <span class="form-label">Seguimiento editorial</span>
                                        <div class="timeline">
                                            <?php foreach ($memoriaTrack as $tr): ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-title"><?= e((string) $tr['etapa']) ?><?php if (!empty($tr['estado'])): ?> · <?= e((string) $tr['estado']) ?><?php endif; ?></div>
                                                    <div class="timeline-meta"><?= e((string) $tr['fecha']) ?></div>
                                                    <?php if (!empty($tr['observaciones'])): ?><div class="timeline-obs"><?= e((string) $tr['observaciones']) ?></div><?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=memorias">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($modalEditorialAbierto && $editorialEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=memorias">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $editorialEditar['id'] ? 'Editar integrante' : 'Nuevo integrante del comite editorial' ?></h3>
                                <a class="modal-close" href="index.php?view=memorias"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <input type="hidden" name="accion" value="editorial_guardar">
                                <input type="hidden" name="id" value="<?= (int) $editorialEditar['id'] ?>">
                                <label class="form-group form-full"><span class="form-label">Nombre <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="nombre" required value="<?= e($editorialEditar['nombre']) ?>"></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Cargo</span>
                                        <input class="form-control" type="text" name="cargo" value="<?= e($editorialEditar['cargo']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Correo</span>
                                        <input class="form-control" type="email" name="email" value="<?= e($editorialEditar['email']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <option value="Activo" <?= $editorialEditar['estado'] === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                            <option value="Inactivo" <?= $editorialEditar['estado'] === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                                        </select></label>
                                </div>
                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=memorias">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'publicaciones'): ?>
                <div class="section-toolbar">
                    <form class="filters-form" method="get">
                        <input type="hidden" name="view" value="publicaciones">
                        <div class="search-box">
                            <i class="ti ti-search"></i>
                            <input class="form-control" type="text" name="q" placeholder="Buscar por titulo o plataforma..." value="<?= e($q) ?>">
                        </div>
                        <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Buscar</button>
                    </form>
                    <div class="toolbar-actions">
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <a class="btn-primary" href="index.php?view=publicaciones&nuevo=1"><i class="ti ti-plus"></i> Nueva publicacion</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Fecha</th><th>Plataforma / tipo</th><th>Titulo</th><th>Estado</th><th>Alcance</th><th>Interacciones</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php if (!$publicaciones): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i>Todavia no hay publicaciones registradas.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($publicaciones as $p): ?>
                                <tr>
                                    <td><?= e((string) ($p['fecha'] ?: '—')) ?></td>
                                    <td><?= e((string) $p['plataforma']) ?><?php if (!empty($p['tipo'])): ?><div class="text-muted"><?= e((string) $p['tipo']) ?></div><?php endif; ?></td>
                                    <td class="cell-wide">
                                        <strong><?= e($p['titulo']) ?></strong>
                                        <?php if (!empty($p['url'])): ?><div class="text-muted"><a href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Ver publicacion</a></div><?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= comites_estado_badge_class((string) $p['estado']) ?>"><?= e((string) $p['estado']) ?></span></td>
                                    <td><?= (int) $p['alcance'] ?></td>
                                    <td><?= (int) $p['interacciones'] ?></td>
                                    <?php if ($puedeGestionarSeccionActual): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=publicaciones&editar=<?= (int) $p['id'] ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                            <form method="post" onsubmit="return confirm('¿Eliminar esta publicacion?');" style="display:inline;">
                                                <input type="hidden" name="accion" value="publicacion_eliminar">
                                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                <button type="submit" class="icon-btn danger" title="Eliminar"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($modalPublicacionesAbierto && $publicacionEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=publicaciones">
                        <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $publicacionEditar['id'] ? 'Editar publicacion' : 'Nueva publicacion' ?></h3>
                                <a class="modal-close" href="index.php?view=publicaciones"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="publicacion_guardar">
                                <input type="hidden" name="id" value="<?= (int) $publicacionEditar['id'] ?>">
                                <div class="form-grid form-grid-3">
                                    <label class="form-group"><span class="form-label">Fecha</span>
                                        <input class="form-control" type="date" name="fecha" value="<?= e((string) $publicacionEditar['fecha']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Plataforma</span>
                                        <select class="form-control" name="plataforma">
                                            <?php foreach (['Facebook', 'Instagram', 'LinkedIn', 'X', 'YouTube', 'TikTok', 'Sitio web', 'Otro'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $publicacionEditar['plataforma'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="form-group"><span class="form-label">Tipo</span>
                                        <input class="form-control" type="text" name="tipo" value="<?= e((string) $publicacionEditar['tipo']) ?>" placeholder="Foto, video, carrusel..."></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Titulo <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="titulo" required value="<?= e((string) $publicacionEditar['titulo']) ?>"></label>
                                <label class="form-group form-full"><span class="form-label">Texto / copy</span>
                                    <textarea class="form-control" rows="3" name="copy_texto"><?= e((string) $publicacionEditar['copy_texto']) ?></textarea></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">Responsable</span>
                                        <input class="form-control" type="text" name="responsable" value="<?= e((string) $publicacionEditar['responsable']) ?>"></label>
                                    <label class="form-group"><span class="form-label">Enlace publicado</span>
                                        <input class="form-control" type="url" name="url" value="<?= e((string) $publicacionEditar['url']) ?>" placeholder="https://..."></label>
                                    <label class="form-group"><span class="form-label">Estado</span>
                                        <select class="form-control" name="estado">
                                            <?php foreach (['Borrador', 'Programada', 'Publicada', 'Pausada'] as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= $publicacionEditar['estado'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                </div>
                                <label class="form-group form-full"><span class="form-label">Material (imagen/video)</span>
                                    <input class="form-control" type="file" name="material" accept=".jpg,.jpeg,.png,.mp4,.pdf"></label>
                                <?php if (!empty($publicacionEditar['material'])): ?>
                                    <div class="file-current"><i class="ti ti-photo"></i> <a href="<?= e((string) $publicacionEditar['material']) ?>" target="_blank" rel="noopener">Material actual</a></div>
                                <?php endif; ?>

                                <div class="form-group form-full">
                                    <span class="form-label">Metricas</span>
                                    <div class="form-grid form-grid-4">
                                        <?php foreach ([
                                            'alcance' => 'Alcance', 'impresiones' => 'Impresiones', 'interacciones' => 'Interacciones',
                                            'reacciones' => 'Reacciones', 'comentarios' => 'Comentarios', 'compartidos' => 'Compartidos',
                                            'clics' => 'Clics', 'reproducciones' => 'Reproducciones', 'guardados' => 'Guardados',
                                        ] as $campo => $etiqueta): ?>
                                            <label class="form-group"><span class="form-label"><?= e($etiqueta) ?></span>
                                                <input class="form-control" type="number" min="0" name="<?= e($campo) ?>" value="<?= (int) $publicacionEditar[$campo] ?>"></label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=publicaciones">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'estadisticas'): ?>
                <section class="stats-row">
                    <div class="stat-card"><div class="stat-icon"><i class="ti ti-affiliate"></i></div><div><div class="stat-num"><?= $stats['comites'] ?></div><div class="stat-label">Comites activos</div></div></div>
                    <div class="stat-card"><div class="stat-icon"><i class="ti ti-calendar-event"></i></div><div><div class="stat-num"><?= $stats['reuniones_realizadas'] ?>/<?= $stats['reuniones'] ?></div><div class="stat-label">Reuniones realizadas</div></div></div>
                    <div class="stat-card"><div class="stat-icon"><i class="ti ti-percentage"></i></div><div><div class="stat-num"><?= number_format($kpiPromedioAvanceAcuerdos, 0) ?>%</div><div class="stat-label">Avance promedio de acuerdos</div></div></div>
                    <div class="stat-card"><div class="stat-icon"><i class="ti ti-mail"></i></div><div><div class="stat-num"><?= $stats['correos'] ?></div><div class="stat-label">Correos enviados</div></div></div>
                </section>

                <div class="dash-grid">
                    <div class="card" style="padding:16px;">
                        <div class="card-title"><i class="ti ti-clipboard-list"></i> Acuerdos por estado</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Estado</th><th>Total</th></tr></thead>
                                <tbody>
                                <?php if (!$kpiAcuerdosPorEstado): ?><tr><td colspan="2"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                                <?php foreach ($kpiAcuerdosPorEstado as $row): ?>
                                    <tr><td><span class="badge <?= comites_estado_badge_class((string) $row['estado']) ?>"><?= e((string) $row['estado']) ?></span></td><td><?= (int) $row['total'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card" style="padding:16px;">
                        <div class="card-title"><i class="ti ti-calendar-event"></i> Reuniones por comite</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Comite</th><th>Reuniones</th></tr></thead>
                                <tbody>
                                <?php if (!$kpiReunionesPorComite): ?><tr><td colspan="2"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                                <?php foreach ($kpiReunionesPorComite as $row): ?>
                                    <tr><td><?= e((string) $row['comite']) ?></td><td><?= (int) $row['total'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="dash-grid">
                    <div class="card" style="padding:16px;">
                        <div class="card-title"><i class="ti ti-folder"></i> Documentos por estado</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Biblioteca</th><th>Estado</th><th>Total</th></tr></thead>
                                <tbody>
                                <?php if (!$kpiDocumentosPorEstado): ?><tr><td colspan="3"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                                <?php foreach ($kpiDocumentosPorEstado as $row): ?>
                                    <tr>
                                        <td><?= $row['ambito'] === 'interna' ? 'Interna' : 'Ingenios' ?></td>
                                        <td><span class="badge <?= comites_estado_badge_class((string) $row['estado']) ?>"><?= e((string) $row['estado']) ?></span></td>
                                        <td><?= (int) $row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card" style="padding:16px;">
                        <div class="card-title"><i class="ti ti-share"></i> Publicaciones por plataforma</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Plataforma</th><th>Publicaciones</th><th>Alcance</th><th>Interacciones</th></tr></thead>
                                <tbody>
                                <?php if (!$kpiPublicacionesPorPlataforma): ?><tr><td colspan="4"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                                <?php foreach ($kpiPublicacionesPorPlataforma as $row): ?>
                                    <tr><td><?= e((string) $row['plataforma']) ?></td><td><?= (int) $row['total'] ?></td><td><?= (int) $row['alcance'] ?></td><td><?= (int) $row['interacciones'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="dash-grid">
                    <div class="card" style="padding:16px;">
                        <div class="card-title"><i class="ti ti-book-2"></i> Memorias por estado</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Estado</th><th>Total</th></tr></thead>
                                <tbody>
                                <?php if (!$kpiMemoriasPorEstado): ?><tr><td colspan="2"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                                <?php foreach ($kpiMemoriasPorEstado as $row): ?>
                                    <tr><td><span class="badge <?= comites_estado_badge_class((string) $row['estado']) ?>"><?= e((string) $row['estado']) ?></span></td><td><?= (int) $row['total'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card" style="padding:16px;">
                        <div class="card-title"><i class="ti ti-mail"></i> Correos enviados (ultimos 6 meses)</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Mes</th><th>Correos</th></tr></thead>
                                <tbody>
                                <?php if (!$kpiCorreosPorMes): ?><tr><td colspan="2"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                                <?php foreach ($kpiCorreosPorMes as $row): ?>
                                    <tr><td><?= e((string) $row['mes']) ?></td><td><?= (int) $row['total'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($view === 'config'): ?>
                <div class="placeholder-note">
                    <i class="ti ti-info-circle"></i>
                    <span>Edita el nombre visible de cada comite y activa o desactiva su uso en el resto del modulo (formularios, filtros y directorio).</span>
                </div>
                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Clave</th><th>Comite</th><th>Estado</th><?php if (can_manage_resource($user, 'configuracion')): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($comitesTodos as $c): ?>
                                <tr>
                                    <td><code><?= e($c['id']) ?></code></td>
                                    <td><strong><?= e($c['nombre']) ?></strong></td>
                                    <td><span class="badge <?= (int) $c['activo'] === 1 ? 'badge-activo' : 'badge-inactivo' ?>"><?= (int) $c['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                                    <?php if (can_manage_resource($user, 'configuracion')): ?>
                                        <td class="row-actions">
                                            <a class="icon-btn" href="index.php?view=config&editar=<?= e($c['id']) ?>" title="Editar"><i class="ti ti-edit"></i></a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $comiteConfigEditar = null;
                $editarComiteId = trim((string) ($_GET['editar'] ?? ''));
                if ($reopenModal === 'comite' && $prefillPost) {
                    $comiteConfigEditar = [
                        'id' => (string) ($prefillPost['id'] ?? ''),
                        'nombre' => (string) ($prefillPost['nombre'] ?? ''),
                        'activo' => isset($prefillPost['activo']) ? 1 : 0,
                    ];
                } elseif ($editarComiteId !== '' && isset($comiteNombrePorId[$editarComiteId])) {
                    foreach ($comitesTodos as $c) {
                        if ($c['id'] === $editarComiteId) {
                            $comiteConfigEditar = $c;
                            break;
                        }
                    }
                }
                ?>
                <?php if ($comiteConfigEditar && can_manage_resource($user, 'configuracion')): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=config">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3>Editar comite</h3>
                                <a class="modal-close" href="index.php?view=config"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <input type="hidden" name="accion" value="comite_guardar">
                                <input type="hidden" name="id" value="<?= e($comiteConfigEditar['id']) ?>">
                                <label class="form-group form-full"><span class="form-label">Clave</span>
                                    <input class="form-control" type="text" value="<?= e($comiteConfigEditar['id']) ?>" disabled></label>
                                <label class="form-group form-full"><span class="form-label">Nombre <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="nombre" required value="<?= e($comiteConfigEditar['nombre']) ?>"></label>
                                <label class="pill-toggle">
                                    <input type="checkbox" name="activo" value="1" <?= (int) $comiteConfigEditar['activo'] === 1 ? 'checked' : '' ?>>
                                    <span>Comite activo</span>
                                </label>
                                <div class="actions">
                                    <a class="btn-outline" href="index.php?view=config">Cancelar</a>
                                    <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
