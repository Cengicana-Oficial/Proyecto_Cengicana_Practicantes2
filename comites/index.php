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
require __DIR__ . '/includes/catalogo.php';
require __DIR__ . '/includes/tableros.php';
require __DIR__ . '/includes/redes.php';
require __DIR__ . '/includes/redes-api.php';
require __DIR__ . '/includes/usuarios.php';
require __DIR__ . '/includes/notificaciones.php';
$_SESSION['tableros_csrf'] ??= bin2hex(random_bytes(32));

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
    'comites' => ['label' => 'Comités', 'icon' => 'ti-affiliate', 'group' => 'Comites'],
    'contactos' => ['label' => 'Directorio de contactos', 'icon' => 'ti-users', 'group' => 'Comites'],
    'reuniones' => ['label' => 'Reuniones', 'icon' => 'ti-calendar-event', 'group' => 'Comites'],
    'eventos' => ['label' => 'Eventos de transferencia', 'icon' => 'ti-presentation', 'group' => 'Comites'],
    'acuerdos' => ['label' => 'Seguimiento a próximos pasos', 'icon' => 'ti-clipboard-list', 'group' => 'Comites'],
    'documentos' => ['label' => 'Biblioteca documental para ingenios', 'icon' => 'ti-folder', 'group' => 'Documentacion'],
    'biblioteca_interna' => ['label' => 'Biblioteca documental interna', 'icon' => 'ti-folder-star', 'group' => 'Documentacion'],
    'correos' => ['label' => 'Correos y envios', 'icon' => 'ti-mail', 'group' => 'Documentacion'],
    'tableros' => ['label' => 'Tableros', 'icon' => 'ti-chart-bar', 'group' => 'Resultados'],
    'informes' => ['label' => 'Informes cuatrimestrales', 'icon' => 'ti-report', 'group' => 'Resultados'],
    'memorias' => ['label' => 'Memoria de resultados', 'icon' => 'ti-book-2', 'group' => 'Resultados'],
    'publicaciones' => ['label' => 'Redes sociales', 'icon' => 'ti-share', 'group' => 'Comunicacion'],
    'estadisticas' => ['label' => 'Estadisticas', 'icon' => 'ti-chart-pie', 'group' => 'Comunicacion'],
    'config' => ['label' => 'Configuracion', 'icon' => 'ti-settings', 'group' => 'Administración'],
    'usuarios' => ['label' => 'Usuarios y programas', 'icon' => 'ti-user-cog', 'group' => 'Administración'],
];

if (!array_key_exists($view, $sections)) {
    $view = 'dashboard';
}

// Nombre del recurso de permisos (comites.<recurso>.ver / .gestionar) por vista.
$recursoPorVista = [
    'comites' => 'configuracion',
    'contactos' => 'contactos',
    'reuniones' => 'reuniones',
    'eventos' => 'eventos',
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
    'usuarios' => 'usuarios',
];

// Secciones con CRUD real implementado en esta fase.
$seccionesImplementadas = [
    'eventos',
    'comites',
    'contactos', 'reuniones', 'acuerdos', 'documentos', 'biblioteca_interna',
    'correos', 'tableros', 'informes', 'memorias', 'publicaciones', 'estadisticas', 'config', 'usuarios',
];

$puedeVerSeccionActual = $view === 'dashboard'
    || !isset($recursoPorVista[$view])
    || !in_array($view, $seccionesImplementadas, true)
    || can_view_resource($user, $recursoPorVista[$view]);
$puedeGestionarSeccionActual = isset($recursoPorVista[$view]) && can_manage_resource($user, $recursoPorVista[$view]);

// Sincronizacion automatica de redes por API: la pide la pagina en segundo
// plano cuando vence el intervalo. Se libera la sesion para no bloquear la
// navegacion del usuario mientras se consultan las APIs.
$_SESSION['redes_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['usuarios_csrf'] ??= bin2hex(random_bytes(32));
// Campanita: abrir un aviso lo marca como leido; "Marcar todas" marca los que se ven.
$_SESSION['notif_csrf'] ??= bin2hex(random_bytes(32));
if (isset($_GET['notif'], $_GET['ir'])) {
    notif_marcar($pdo, $user, [(string) $_GET['notif']]);
    $destino = (string) $_GET['ir'];
    header('Location: ' . (preg_match('/^index\.php\?[\w=&%.\-]*$/', $destino) ? $destino : 'index.php'));
    exit;
}
if (($_POST['accion'] ?? '') === 'notif_marcar_todas' && hash_equals($_SESSION['notif_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    notif_marcar($pdo, $user, array_column(notif_calcular($pdo, $user), 'clave'));
    $volver = (string) ($_POST['volver'] ?? '');
    header('Location: ' . (preg_match('/^\/[\w\/.\-]*index\.php(\?[\w=&%.\-+]*)?$/', $volver) ? $volver : 'index.php'));
    exit;
}

// Consulta rapida para "Agregar usuario": avisa si el correo ya tiene cuenta.
if ($view === 'usuarios' && isset($_GET['buscar_correo'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!can_manage_resource($user, 'usuarios')) {
        http_response_code(403);
        echo '{}';
        exit;
    }
    $correoBuscado = trim((string) $_GET['buscar_correo']);
    try {
        $encontrado = filter_var($correoBuscado, FILTER_VALIDATE_EMAIL) ? usuarios_buscar_correo($menuPdo, $correoBuscado) : null;
    } catch (PDOException $e) {
        $encontrado = null;
    }
    echo json_encode($encontrado ? ['existe' => true, 'nombre' => $encontrado['nombre'], 'rol' => $encontrado['nombre_rol'], 'programa' => $encontrado['programa'], 'superadmin' => (int) $encontrado['es_superadmin'] === 1] : ['existe' => false], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($view === 'publicaciones' && isset($_POST['redes_auto']) && $puedeVerSeccionActual) {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($_SESSION['redes_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    session_write_close();
    ignore_user_abort(true);
    $resultadosRedes = redes_api_sincronizar_pendientes($pdo);
    echo json_encode(['ok' => true, 'actualizado' => (bool) $resultadosRedes, 'resultados' => $resultadosRedes], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($view === 'acuerdos' || ($_SERVER['REQUEST_METHOD']==='POST' && str_starts_with((string)($_POST['accion'] ?? ''), 'acuerdo_'))) { require __DIR__ . '/includes/pasos-controlador.php'; }
if ($view === 'informes' || ($_SERVER['REQUEST_METHOD']==='POST' && str_starts_with((string)($_POST['accion'] ?? ''), 'informe_'))) { require __DIR__ . '/includes/informes-controlador.php'; }
if ($view === 'memorias' || ($_SERVER['REQUEST_METHOD']==='POST' && (str_starts_with((string)($_POST['accion'] ?? ''), 'memoria_') || str_starts_with((string)($_POST['accion'] ?? ''), 'editorial_')))) { require __DIR__ . '/includes/memorias-controlador.php'; }
if ($view === 'biblioteca_interna' || ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['accion'] ?? '', ['documento_guardar','documento_eliminar','documento_revisar','carpeta_crear'], true) && ($_POST['ambito'] ?? '')==='interna')) { require __DIR__ . '/includes/interna-controlador.php'; }
if ($view === 'documentos' || ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['accion'] ?? '', ['documento_guardar','documento_eliminar','documento_revisar','carpeta_crear'], true) && ($_POST['ambito'] ?? 'ingenios')!=='interna')) { require __DIR__ . '/includes/biblioteca-controlador.php'; }
if ($view === 'contactos') { require __DIR__ . '/includes/directorio-controlador.php'; }
if (in_array($view, ['reuniones', 'eventos'], true)) { require __DIR__ . '/includes/agenda-controlador.php'; }

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

if ($view === 'comites') {
    require_once __DIR__ . '/includes/directorio.php';
    directorio_preparar($pdo);
    $_SESSION['catalogo_csrf'] ??= bin2hex(random_bytes(32));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string) ($_POST['accion'] ?? ''), 'grupo_')) {
        try {
            if (!can_manage_resource($user, 'configuracion')) {
                throw new RuntimeException('No tienes permiso para gestionar comités.');
            }
            if (!hash_equals($_SESSION['catalogo_csrf'], (string) ($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('La sesión del formulario expiró. Recarga la página.');
            }
            catalogo_accion($pdo, $_POST, $_FILES);
            $destino = 'index.php?view=comites&msg=' . urlencode('Cambios guardados correctamente.');
            if (str_starts_with($_POST['accion'], 'grupo_integrante')) {
                $destino .= '&integrantes=' . urlencode((string) $_POST['id']);
            }
            header('Location: ' . $destino);
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flashError = $error instanceof PDOException ? 'No se pudo guardar el cambio. Revisa los datos e inténtalo de nuevo.' : $error->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    try {
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
            if ($documentoIdCorreo > 0) {
                $bibCheck=$pdo->prepare('SELECT ambito FROM documentos WHERE id=?');
                $bibCheck->execute([$documentoIdCorreo]);
                if (in_array($bibCheck->fetchColumn(), ['ingenios','interna'], true)) throw new RuntimeException('Para compartir este documento usa Enviar por correo desde la biblioteca para ingenios; allí se verifica la aprobación y la versión.');
            }

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
        if (in_array($accion, ['tablero_guardar','tablero_eliminar'], true) && !hash_equals($_SESSION['tableros_csrf'], (string)($_POST['csrf_tableros'] ?? ''))) { throw new RuntimeException('El formulario expiró. Recarga la página.'); }
        if ($accion === 'tablero_guardar') {
            if (!can_manage_resource($user, 'tableros')) {
                throw new RuntimeException('No tienes permiso para gestionar tableros.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $nombreTablero = trim((string) ($_POST['nombre'] ?? ''));
            $descripcionTablero = trim((string) ($_POST['descripcion'] ?? '')) ?: null;
            $urlTablero = tableros_url((string) ($_POST['url'] ?? ''));
            $tipoTablero = trim((string) ($_POST['tipo'] ?? '')) ?: null;
            $estadoTablero = ($_POST['estado'] ?? 'Activo') === 'Inactivo' ? 'Inactivo' : 'Activo';

            if ($nombreTablero === '' || mb_strlen($nombreTablero)>180 || mb_strlen((string)$tipoTablero)>80) {
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
            $plataformasValidas = array_keys(redes_plataformas());
            $plataforma = in_array($_POST['plataforma'] ?? '', $plataformasValidas, true) ? $_POST['plataforma'] : 'Otro';
            $tipoPublicacion = trim((string) ($_POST['tipo'] ?? '')) ?: null;
            $tituloPublicacion = trim((string) ($_POST['titulo'] ?? ''));
            $copyTexto = trim((string) ($_POST['copy_texto'] ?? '')) ?: null;
            $estadosPublicacionValidos = ['Borrador', 'Programada', 'Publicada', 'Pausada'];
            $estadoPublicacion = in_array($_POST['estado'] ?? '', $estadosPublicacionValidos, true) ? $_POST['estado'] : 'Borrador';
            $responsablePublicacion = trim((string) ($_POST['responsable'] ?? '')) ?: null;
            $urlPublicacion = trim((string) ($_POST['url'] ?? '')) ?: null;
            $destinosPublicacion = $plataforma === 'WhatsApp' ? (trim((string) ($_POST['destinos'] ?? '')) ?: null) : null;

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
                        responsable = ?, url = ?, destinos = ?, alcance = ?, impresiones = ?, interacciones = ?, reacciones = ?, comentarios = ?,
                        compartidos = ?, clics = ?, reproducciones = ?, guardados = ? WHERE id = ?'
                );
                $stmt->execute([
                    $fechaPublicacion, $plataforma, $tipoPublicacion, $tituloPublicacion, $copyTexto, $materialRuta, $estadoPublicacion,
                    $responsablePublicacion, $urlPublicacion, $destinosPublicacion,
                    $metricas['alcance'], $metricas['impresiones'], $metricas['interacciones'], $metricas['reacciones'], $metricas['comentarios'],
                    $metricas['compartidos'], $metricas['clics'], $metricas['reproducciones'], $metricas['guardados'], $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO publicaciones (fecha, plataforma, tipo, titulo, copy_texto, material, estado, responsable, url, destinos,
                        alcance, impresiones, interacciones, reacciones, comentarios, compartidos, clics, reproducciones, guardados)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $fechaPublicacion, $plataforma, $tipoPublicacion, $tituloPublicacion, $copyTexto, $materialPublicacion['ruta'], $estadoPublicacion,
                    $responsablePublicacion, $urlPublicacion, $destinosPublicacion,
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

        // ---------- Usuarios y programas ----------
        if (in_array($accion, ['usuario_guardar', 'usuario_agregar', 'programa_guardar', 'programa_estado'], true)) {
            if (!can_manage_resource($user, 'usuarios')) {
                throw new RuntimeException('No tienes permiso para administrar usuarios y programas.');
            }
            if (!hash_equals($_SESSION['usuarios_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('El formulario expiró. Recarga la página.');
            }
            usuarios_preparar_programas($menuPdo);
            $mensaje = match ($accion) {
                'usuario_guardar' => usuarios_guardar($menuPdo, $user, $_POST),
                'usuario_agregar' => usuarios_agregar($menuPdo, $user, $_POST),
                'programa_guardar' => usuarios_programa_guardar($menuPdo, $_POST),
                'programa_estado' => usuarios_programa_estado($menuPdo, (int) ($_POST['programa_id'] ?? 0)),
            };
            if (in_array($accion, ['usuario_agregar', 'usuario_guardar'], true)) {
                // Lleva a la persona en la lista, resaltada, con la confirmacion de como quedo.
                // Por sesion y no por la URL: el mensaje puede llevar el enlace para crear la contrasena.
                $idAsignado = $accion === 'usuario_guardar'
                    ? (int) ($_POST['usuario_id'] ?? 0)
                    : (int) (usuarios_buscar_correo($menuPdo, (string) ($_POST['correo'] ?? ''))['id'] ?? 0);
                $confirmacion = usuarios_confirmacion($menuPdo, $idAsignado);
                $_SESSION['usuarios_flash'] = $mensaje . ($confirmacion ? ' ' . $confirmacion['texto'] : '');
                header('Location: index.php?' . http_build_query(['view' => 'usuarios', 'filtro' => 'todos', 'q' => $confirmacion['correo'] ?? '', 'resaltar' => $idAsignado]));
                exit;
            }
            header('Location: index.php?view=usuarios' . ($accion === 'usuario_guardar' ? '' : '&tab=programas') . '&msg=' . urlencode($mensaje));
            exit;
        }

        // ---------- Conexion directa por API (Meta, LinkedIn, Google Analytics) ----------
        if (in_array($accion, ['conexion_guardar', 'conexion_probar', 'conexion_sincronizar', 'conexion_desconectar'], true)) {
            if (!can_manage_resource($user, 'publicaciones')) {
                throw new RuntimeException('No tienes permiso para configurar las conexiones.');
            }
            if (!hash_equals($_SESSION['redes_csrf'], (string) ($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('El formulario expiró. Recarga la página.');
            }
            $proveedorApi = (string) ($_POST['proveedor'] ?? '');
            if (!isset(redes_api_proveedores()[$proveedorApi])) {
                throw new RuntimeException('Servicio no válido.');
            }
            try {
                $mensaje = match ($accion) {
                    'conexion_guardar' => redes_api_guardar($pdo, $proveedorApi, $_POST, $_FILES),
                    'conexion_probar' => redes_api_probar($pdo, $proveedorApi),
                    'conexion_sincronizar' => redes_api_sincronizar($pdo, $proveedorApi, true) ?? 'Ya hay una sincronización en curso; intenta en un momento.',
                    'conexion_desconectar' => redes_api_desconectar($pdo, $proveedorApi),
                };
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new RuntimeException('No se pudo completar la operación con ' . redes_api_proveedores()[$proveedorApi]['nombre'] . ': ' . $e->getMessage());
            }
            header('Location: index.php?view=publicaciones&tab=conexiones&msg=' . urlencode($mensaje) . '#conexion-' . $proveedorApi);
            exit;
        }

        // ---------- Importacion de estadisticas y sitio web (Google Analytics) ----------
        if (in_array($accion, ['publicacion_importar', 'web_importar', 'web_guardar', 'web_eliminar'], true)) {
            if (!can_manage_resource($user, 'publicaciones')) {
                throw new RuntimeException('No tienes permiso para gestionar redes sociales.');
            }
            try {
                if ($accion === 'publicacion_importar') {
                    $mensaje = redes_importar_publicaciones($pdo, (string) ($_POST['plataforma'] ?? ''), $_FILES['archivo'] ?? []);
                } elseif ($accion === 'web_importar') {
                    $mensaje = redes_importar_web($pdo, $_FILES['archivo'] ?? []);
                } elseif ($accion === 'web_guardar') {
                    $mensaje = redes_guardar_web($pdo, $_POST);
                } else {
                    $pdo->prepare('DELETE FROM sitio_web_metricas WHERE periodo = ?')->execute([(string) ($_POST['periodo'] ?? '')]);
                    $mensaje = 'Mes eliminado.';
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new RuntimeException('No se pudo leer el archivo. Verifica que sea la exportación original en CSV o Excel.');
            }
            $tabWeb = str_starts_with($accion, 'web_') ? '&tab=web' : '';
            header('Location: index.php?view=publicaciones' . $tabWeb . '&msg=' . urlencode($mensaje));
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
        } elseif ($accion === 'usuario_agregar') {
            $reopenModal = 'usuario_agregar';
        } elseif (str_starts_with($accion, 'publicacion_') && $accion !== 'publicacion_importar') {
            $reopenModal = 'publicacion';
        }
        $prefillPost = $_POST;
    }
}

$flashMessage = $_GET['msg'] ?? '';
if (isset($_SESSION['usuarios_flash'])) {
    $flashMessage = (string) $_SESSION['usuarios_flash'];
    unset($_SESSION['usuarios_flash']);
}

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

// Datos del Inicio: agenda proxima, alertas y avance. Cada bloque solo se
// consulta si el usuario puede ver la seccion de origen.
$dash = ['reuniones' => [], 'eventos' => [], 'acuerdos_vencidos' => 0, 'acuerdos_por_vencer' => [], 'docs_revision' => 0, 'avance_acuerdos' => 0, 'eventos_realizados' => 0, 'eventos_total' => 0];
if ($view === 'dashboard') {
    if (can_view_resource($user, 'reuniones')) {
        $stmt = $pdo->query(
            "SELECT r.id, COALESCE(r.plan_fecha, r.fecha) AS fecha, r.plan_temas, r.temas, c.nombre AS comite
             FROM reuniones r LEFT JOIN comites c ON c.id = r.comite_id
             WHERE r.realizada = 0 AND r.cancelada = 0 AND COALESCE(r.plan_fecha, r.fecha) >= CURDATE()
             ORDER BY fecha ASC LIMIT 5"
        );
        $dash['reuniones'] = $stmt->fetchAll();
    }
    if (can_view_resource($user, 'eventos')) {
        $dash['eventos'] = $pdo->query(
            "SELECT id, nombre, COALESCE(plan_fecha, fecha) AS fecha, modalidad, lugar
             FROM eventos_transferencia
             WHERE realizada = 0 AND cancelada = 0 AND COALESCE(plan_fecha, fecha) >= CURDATE()
             ORDER BY fecha ASC LIMIT 5"
        )->fetchAll();
        $fila = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(realizada), 0) AS realizados FROM eventos_transferencia WHERE cancelada = 0')->fetch();
        $dash['eventos_total'] = (int) ($fila['total'] ?? 0);
        $dash['eventos_realizados'] = (int) ($fila['realizados'] ?? 0);
    }
    if (can_view_resource($user, 'acuerdos')) {
        $dash['acuerdos_vencidos'] = (int) $pdo->query("SELECT COUNT(*) FROM acuerdos WHERE estado IN ('Pendiente','En proceso') AND plazo IS NOT NULL AND plazo < CURDATE()")->fetchColumn();
        $dash['acuerdos_por_vencer'] = $pdo->query(
            "SELECT a.id, a.acuerdo, a.responsable, a.plazo, a.avance, c.nombre AS comite
             FROM acuerdos a LEFT JOIN comites c ON c.id = a.comite_id
             WHERE a.estado IN ('Pendiente','En proceso') AND a.plazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
             ORDER BY a.plazo ASC LIMIT 5"
        )->fetchAll();
        $dash['avance_acuerdos'] = (int) round((float) $pdo->query("SELECT COALESCE(AVG(avance), 0) FROM acuerdos WHERE estado <> 'Cancelado'")->fetchColumn());
    }
    if (can_view_resource($user, 'documentos') || can_view_resource($user, 'biblioteca_interna')) {
        $dash['docs_revision'] = (int) $pdo->query("SELECT COUNT(*) FROM documentos WHERE estado = 'En revision'")->fetchColumn();
    }
}

function dash_fecha(?string $fecha): string
{
    if (!$fecha) {
        return 'Por definir';
    }
    $ts = strtotime($fecha);
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return $ts ? date('j', $ts) . ' ' . $meses[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) : 'Por definir';
}

function dash_dias(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }
    $dias = (int) floor((strtotime($fecha) - strtotime(date('Y-m-d'))) / 86400);
    return $dias === 0 ? 'Hoy' : ($dias === 1 ? 'Mañana' : 'En ' . $dias . ' días');
}

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

if ($view === 'correos' && $puedeVerSeccionActual) {
    $correos = fetch_placeholder_rows($pdo, 'SELECT * FROM correos_enviados ORDER BY enviado_en DESC LIMIT 200');
    $documentosParaCorreo = fetch_placeholder_rows(
        $pdo,
        "SELECT id, titulo FROM documentos WHERE habilitado_envio = 1 AND ambito = 'ingenios' ORDER BY fecha DESC LIMIT 100"
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

if ($view === 'publicaciones' && $puedeVerSeccionActual) {
    $redesTab = in_array($_GET['tab'] ?? '', ['web', 'conexiones'], true) ? (string) $_GET['tab'] : 'publicaciones';
    if ($redesTab === 'conexiones' && !$puedeGestionarSeccionActual) {
        $redesTab = 'publicaciones';
    }
    $redesConexiones = redes_api_conexiones($pdo);
    $redesAutoPendiente = redes_api_hay_pendientes($redesConexiones);
    $q = trim((string) ($_GET['q'] ?? ''));
    $filtroPlataforma = array_key_exists($_GET['plataforma'] ?? '', redes_plataformas()) ? (string) $_GET['plataforma'] : '';
    $filtroMes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['mes'] ?? '')) ? (string) $_GET['mes'] : '';
    $where = ' WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (titulo LIKE ? OR plataforma LIKE ? OR tipo LIKE ? OR destinos LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($filtroPlataforma !== '') {
        $where .= ' AND plataforma = ?';
        $params[] = $filtroPlataforma;
    }
    if ($filtroMes !== '') {
        $where .= " AND DATE_FORMAT(fecha, '%Y-%m') = ?";
        $params[] = $filtroMes;
    }
    $publicaciones = fetch_placeholder_rows($pdo, 'SELECT * FROM publicaciones' . $where . ' ORDER BY fecha DESC, id DESC', $params);
    // Resumen por plataforma con los mismos filtros (excepto la plataforma, para poder comparar).
    $paramsResumen = $params;
    $whereResumen = $where;
    if ($filtroPlataforma !== '') {
        $whereResumen = str_replace(' AND plataforma = ?', '', $whereResumen);
        array_splice($paramsResumen, array_search($filtroPlataforma, $paramsResumen, true), 1);
    }
    $resumenRedes = fetch_placeholder_rows(
        $pdo,
        'SELECT plataforma, COUNT(*) AS total, COALESCE(SUM(alcance),0) AS alcance, COALESCE(SUM(interacciones),0) AS interacciones,
                COALESCE(SUM(clics),0) AS clics, COALESCE(SUM(compartidos),0) AS compartidos
         FROM publicaciones' . $whereResumen . ' GROUP BY plataforma ORDER BY total DESC',
        $paramsResumen
    );
    $webMetricas = fetch_placeholder_rows($pdo, 'SELECT * FROM sitio_web_metricas ORDER BY periodo DESC LIMIT 36');
    $webEditar = null;
    if ($redesTab === 'web' && $puedeGestionarSeccionActual && isset($_GET['web_mes'])) {
        $webEditar = ['periodo' => date('Y-m', strtotime('first day of last month')), 'usuarios' => 0, 'nuevos_usuarios' => 0, 'sesiones' => 0, 'vistas' => 0, 'duracion_seg' => 0, 'rebote' => 0, 'sesiones_redes' => 0, 'sesiones_whatsapp' => 0, 'notas' => ''];
        foreach ($webMetricas as $fila) {
            if ($fila['periodo'] === $_GET['web_mes']) {
                $webEditar = $fila;
            }
        }
    }
    $modalImportar = $puedeGestionarSeccionActual ? (string) ($_GET['importar'] ?? '') : '';

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
            'destinos' => (string) ($prefillPost['destinos'] ?? ''),
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
            'estado' => 'Borrador', 'responsable' => '', 'url' => '', 'destinos' => '', 'material' => null,
            'alcance' => 0, 'impresiones' => 0, 'interacciones' => 0, 'reacciones' => 0, 'comentarios' => 0,
            'compartidos' => 0, 'clics' => 0, 'reproducciones' => 0, 'guardados' => 0,
        ];
        $modalPublicacionesAbierto = true;
    } else {
        $modalPublicacionesAbierto = false;
    }
}

if ($view === 'usuarios' && $puedeVerSeccionActual) {
    $usuariosTab = ($_GET['tab'] ?? '') === 'programas' ? 'programas' : 'usuarios';
    $usuariosError = '';
    try {
        usuarios_preparar_programas($menuPdo);
        $usuariosBusqueda = trim((string) ($_GET['q'] ?? ''));
        $usuariosFiltro = in_array($_GET['filtro'] ?? '', ['con_acceso', 'sin_programa', 'todos'], true) ? (string) $_GET['filtro'] : 'con_acceso';
        $usuariosRolFiltro = (int) ($_GET['rol'] ?? 0);
        $usuariosLista = usuarios_listado($menuPdo, $usuariosBusqueda, $usuariosFiltro, $usuariosRolFiltro);
        $usuariosRoles = usuarios_roles($menuPdo);
        $usuariosProgramas = usuarios_programas($menuPdo);
    } catch (PDOException $e) {
        $usuariosError = 'No se pudo leer la lista de programas de Solicitudes internas: ' . $e->getMessage();
        $usuariosLista = $usuariosRoles = $usuariosProgramas = [];
        $usuariosBusqueda = '';
        $usuariosFiltro = 'con_acceso';
        $usuariosRolFiltro = 0;
    }
    $usuarioEditar = null;
    $usuarioVer = null;
    foreach ($usuariosLista as $fila) {
        if ((int) $fila['id'] === (int) ($_GET['editar'] ?? 0)) {
            $usuarioEditar = $fila;
        }
        if ((int) $fila['id'] === (int) ($_GET['ver'] ?? 0)) {
            $usuarioVer = $fila + ['efectivos' => usuarios_permisos_efectivos($menuPdo, $fila)];
        }
    }
    $usuarioResaltado = (int) ($_GET['resaltar'] ?? 0);
    $programaEditar = null;
    if (isset($_GET['programa'])) {
        $programaEditar = ['id' => 0, 'nombre' => ''];
        foreach ($usuariosProgramas as $p) {
            if ((int) $p['id'] === (int) $_GET['programa']) {
                $programaEditar = $p;
            }
        }
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
$notificaciones = notif_obtener($pdo, $user);
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
    <link rel="stylesheet" href="assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/assets/css/styles.css') ?>">
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
            <div class="notif" id="notif">
                <button type="button" class="notif-toggle" id="notifToggle" aria-haspopup="true" aria-expanded="false" aria-controls="notifPanel"
                        aria-label="Notificaciones<?= $notificaciones['sin_leer'] ? ': ' . (int) $notificaciones['sin_leer'] . ' sin leer' : '' ?>">
                    <i class="ti ti-bell"></i>
                    <?php if ($notificaciones['sin_leer'] > 0): ?>
                        <span class="notif-badge"><?= $notificaciones['sin_leer'] > 9 ? '9+' : (int) $notificaciones['sin_leer'] ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-panel" id="notifPanel" hidden>
                    <div class="notif-head">
                        <div>
                            <strong>Notificaciones</strong>
                            <span><?= $notificaciones['sin_leer'] ? (int) $notificaciones['sin_leer'] . ' sin leer' : 'Estás al día' ?></span>
                        </div>
                        <?php if ($notificaciones['sin_leer'] > 0): ?>
                            <form method="post">
                                <input type="hidden" name="accion" value="notif_marcar_todas">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['notif_csrf']) ?>">
                                <input type="hidden" name="volver" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '')) ?>">
                                <button type="submit" class="notif-mark"><i class="ti ti-checks"></i>Marcar todas como leídas</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!$notificaciones['items']): ?>
                        <div class="notif-empty">
                            <i class="ti ti-bell-off"></i>
                            <span>No tienes pendientes ni avisos recientes.</span>
                        </div>
                    <?php else: ?>
                        <ul class="notif-list">
                            <?php foreach ($notificaciones['items'] as $aviso): ?>
                                <li>
                                    <a class="notif-item<?= $aviso['leida'] ? '' : ' is-unread' ?>" href="index.php?<?= e(http_build_query(['notif' => $aviso['clave'], 'ir' => $aviso['url']])) ?>">
                                        <span class="notif-icon tone-<?= e($aviso['tono']) ?>"><i class="ti <?= e($aviso['icon']) ?>"></i></span>
                                        <span class="notif-body">
                                            <span class="notif-text"><?= e($aviso['texto']) ?></span>
                                            <?php if ($aviso['detalle'] !== ''): ?><span class="notif-obs"><?= e($aviso['detalle']) ?></span><?php endif; ?>
                                            <span class="notif-time"><?= e(notif_hace($aviso['fecha'])) ?></span>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <span class="topbar-pill"><?= e($user['perfil_comites'] ?? $user['nombre_rol']) ?><?= !empty($user['programa']) ? ' · ' . e($user['programa']) : '' ?></span>
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
            <?php
            $pctAcuerdos = $stats['acuerdos'] > 0 ? (int) round($stats['acuerdos_completados'] / $stats['acuerdos'] * 100) : 0;
            $pctReuniones = $stats['reuniones'] > 0 ? (int) round($stats['reuniones_realizadas'] / $stats['reuniones'] * 100) : 0;
            $pctEventos = $dash['eventos_total'] > 0 ? (int) round($dash['eventos_realizados'] / $dash['eventos_total'] * 100) : 0;
            $primerNombre = preg_split('/\s+/', trim((string) $user['nombre']))[0] ?? '';
            $diasSemana = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
            $mesesLargos = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            $hoyTexto = $diasSemana[(int) date('w')] . ', ' . date('j') . ' de ' . $mesesLargos[(int) date('n') - 1] . ' de ' . date('Y');

            // Flujo de trabajo del modulo: cada paso enlaza a sus secciones (solo las visibles para el usuario).
            $flujo = [
                ['icon' => 'ti-affiliate', 'titulo' => 'Organice los comités', 'texto' => 'Registre los comités técnicos y su directorio de contactos de ingenios e invitados.', 'vistas' => ['comites', 'contactos']],
                ['icon' => 'ti-calendar-event', 'titulo' => 'Planifique y documente', 'texto' => 'Programe reuniones y eventos de transferencia; registre asistentes, temas y ayuda memoria.', 'vistas' => ['reuniones', 'eventos']],
                ['icon' => 'ti-clipboard-list', 'titulo' => 'Dé seguimiento', 'texto' => 'Cada acuerdo queda con responsable, plazo y porcentaje de avance hasta completarse.', 'vistas' => ['acuerdos']],
                ['icon' => 'ti-folder', 'titulo' => 'Comparta conocimiento', 'texto' => 'Publique documentos aprobados en las bibliotecas y envíelos por correo a los contactos.', 'vistas' => ['documentos', 'biblioteca_interna', 'correos']],
                ['icon' => 'ti-report', 'titulo' => 'Reporte resultados', 'texto' => 'Consolide informes cuatrimestrales, la memoria de resultados y los tableros de indicadores.', 'vistas' => ['informes', 'memorias', 'tableros']],
                ['icon' => 'ti-share', 'titulo' => 'Comunique y mida', 'texto' => 'Registre publicaciones en redes sociales y revise las estadísticas del módulo.', 'vistas' => ['publicaciones', 'estadisticas']],
            ];
            $puedeVer = static fn(string $v): bool => !isset($recursoPorVista[$v]) || can_view_resource($user, $recursoPorVista[$v]);

            $alertas = [];
            if ($dash['acuerdos_vencidos'] > 0) {
                $alertas[] = ['tipo' => 'danger', 'icon' => 'ti-alert-triangle', 'texto' => $dash['acuerdos_vencidos'] . ($dash['acuerdos_vencidos'] === 1 ? ' acuerdo vencido' : ' acuerdos vencidos') . ' sin completar', 'url' => 'index.php?view=acuerdos'];
            }
            if (count($dash['acuerdos_por_vencer']) > 0) {
                $alertas[] = ['tipo' => 'warning', 'icon' => 'ti-clock', 'texto' => count($dash['acuerdos_por_vencer']) . ' acuerdo(s) vencen en los próximos 14 días', 'url' => 'index.php?view=acuerdos'];
            }
            if ($dash['docs_revision'] > 0) {
                $alertas[] = ['tipo' => 'info', 'icon' => 'ti-file-search', 'texto' => $dash['docs_revision'] . ' documento(s) esperan revisión', 'url' => 'index.php?view=' . (can_view_resource($user, 'documentos') ? 'documentos' : 'biblioteca_interna')];
            }
            ?>

            <section class="dash-hero">
                <div class="dash-hero-copy">
                    <span class="dash-hero-date"><i class="ti ti-calendar"></i> <?= e(ucfirst($hoyTexto)) ?></span>
                    <h1>Hola, <?= e($primerNombre) ?></h1>
                    <p>
                        Este módulo centraliza el trabajo de <strong>Transferencia de Tecnología</strong> de CENGICAÑA:
                        la gestión de los comités técnicos con los ingenios, el seguimiento de sus acuerdos,
                        la documentación que se comparte y la comunicación de resultados.
                    </p>
                </div>
                <div class="dash-hero-kpis">
                    <div><strong><?= $stats['comites'] ?></strong><span>Comités activos</span></div>
                    <div><strong><?= $stats['contactos'] ?></strong><span>Contactos</span></div>
                    <div><strong><?= $stats['reuniones'] ?></strong><span>Reuniones</span></div>
                    <div><strong><?= $stats['acuerdos'] ?></strong><span>Acuerdos</span></div>
                </div>
            </section>

            <?php if ($alertas): ?>
                <section class="dash-alerts">
                    <?php foreach ($alertas as $a): ?>
                        <a class="dash-alert dash-alert-<?= e($a['tipo']) ?>" href="<?= e($a['url']) ?>">
                            <i class="ti <?= e($a['icon']) ?>"></i><span><?= e($a['texto']) ?></span><i class="ti ti-chevron-right"></i>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <div class="dash-columns">
                <section class="dash-panel">
                    <div class="dash-panel-head">
                        <h2><i class="ti ti-calendar-time"></i> Próximas actividades</h2>
                        <?php if ($puedeVer('reuniones')): ?><a href="index.php?view=reuniones">Ver agenda</a><?php endif; ?>
                    </div>
                    <?php
                    $agenda = [];
                    foreach ($dash['reuniones'] as $r) {
                        $agenda[] = ['fecha' => $r['fecha'], 'tipo' => 'Reunión', 'titulo' => $r['comite'] ?: 'Reunión de comité', 'detalle' => trim((string) ($r['plan_temas'] ?: $r['temas'])), 'url' => 'index.php?view=reuniones'];
                    }
                    foreach ($dash['eventos'] as $ev) {
                        $agenda[] = ['fecha' => $ev['fecha'], 'tipo' => 'Evento', 'titulo' => $ev['nombre'], 'detalle' => trim($ev['modalidad'] . ($ev['lugar'] ? ' · ' . $ev['lugar'] : '')), 'url' => 'index.php?view=eventos'];
                    }
                    usort($agenda, static fn(array $a, array $b): int => strcmp((string) $a['fecha'], (string) $b['fecha']));
                    $agenda = array_slice($agenda, 0, 6);
                    ?>
                    <?php if (!$agenda): ?>
                        <div class="empty-state"><i class="ti ti-calendar-off"></i>No hay reuniones ni eventos programados próximamente.</div>
                    <?php else: ?>
                        <ul class="dash-list">
                            <?php foreach ($agenda as $item): $ts = strtotime((string) $item['fecha']); ?>
                                <li>
                                    <a href="<?= e($item['url']) ?>">
                                        <span class="dash-date"><strong><?= date('j', $ts) ?></strong><small><?= e(mb_substr($mesesLargos[(int) date('n', $ts) - 1], 0, 3)) ?></small></span>
                                        <span class="dash-list-body">
                                            <span class="dash-tag <?= $item['tipo'] === 'Evento' ? 'is-event' : '' ?>"><?= e($item['tipo']) ?></span>
                                            <strong><?= e($item['titulo']) ?></strong>
                                            <?php if ($item['detalle'] !== ''): ?><small><?= e(mb_strimwidth($item['detalle'], 0, 90, '…')) ?></small><?php endif; ?>
                                        </span>
                                        <span class="dash-when"><?= e(dash_dias($item['fecha'])) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="dash-panel">
                    <div class="dash-panel-head">
                        <h2><i class="ti ti-gauge"></i> Avance de la gestión</h2>
                    </div>
                    <div class="dash-meter">
                        <div class="dash-meter-label"><span>Acuerdos completados</span><strong><?= $pctAcuerdos ?>%</strong></div>
                        <div class="progress-track"><div class="progress-fill" style="width:<?= $pctAcuerdos ?>%"></div></div>
                        <small><?= $stats['acuerdos_completados'] ?> de <?= $stats['acuerdos'] ?> acuerdos</small>
                    </div>
                    <div class="dash-meter">
                        <div class="dash-meter-label"><span>Avance promedio de acuerdos</span><strong><?= $dash['avance_acuerdos'] ?>%</strong></div>
                        <div class="progress-track"><div class="progress-fill is-secondary" style="width:<?= $dash['avance_acuerdos'] ?>%"></div></div>
                        <small>Promedio del porcentaje reportado (sin cancelados)</small>
                    </div>
                    <div class="dash-meter">
                        <div class="dash-meter-label"><span>Reuniones realizadas</span><strong><?= $pctReuniones ?>%</strong></div>
                        <div class="progress-track"><div class="progress-fill is-accent" style="width:<?= $pctReuniones ?>%"></div></div>
                        <small><?= $stats['reuniones_realizadas'] ?> de <?= $stats['reuniones'] ?> reuniones registradas</small>
                    </div>
                    <?php if ($dash['eventos_total'] > 0): ?>
                        <div class="dash-meter">
                            <div class="dash-meter-label"><span>Eventos de transferencia realizados</span><strong><?= $pctEventos ?>%</strong></div>
                            <div class="progress-track"><div class="progress-fill is-orange" style="width:<?= $pctEventos ?>%"></div></div>
                            <small><?= $dash['eventos_realizados'] ?> de <?= $dash['eventos_total'] ?> eventos (sin cancelados)</small>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <?php if ($dash['acuerdos_por_vencer']): ?>
                <section class="dash-panel dash-panel-full">
                    <div class="dash-panel-head">
                        <h2><i class="ti ti-hourglass"></i> Acuerdos por vencer (14 días)</h2>
                        <a href="index.php?view=acuerdos">Ver seguimiento</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Acuerdo</th><th>Comité</th><th>Responsable</th><th>Plazo</th><th>Avance</th></tr></thead>
                            <tbody>
                                <?php foreach ($dash['acuerdos_por_vencer'] as $a): ?>
                                    <tr>
                                        <td class="cell-wide"><?= e(mb_strimwidth((string) $a['acuerdo'], 0, 110, '…')) ?></td>
                                        <td><?= e($a['comite'] ?: '—') ?></td>
                                        <td><?= e($a['responsable'] ?: '—') ?></td>
                                        <td><?= e(dash_fecha($a['plazo'])) ?><br><small class="text-muted"><?= e(dash_dias($a['plazo'])) ?></small></td>
                                        <td><div class="cell-progress"><div class="progress-track"><div class="progress-fill" style="width:<?= (int) $a['avance'] ?>%"></div></div><span><?= (int) $a['avance'] ?>%</span></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dash-panel dash-panel-full">
                <div class="dash-panel-head">
                    <h2><i class="ti ti-route"></i> ¿Cómo funciona el módulo?</h2>
                </div>
                <ol class="dash-flow">
                    <?php foreach ($flujo as $i => $paso): $vistas = array_values(array_filter($paso['vistas'], $puedeVer)); ?>
                        <li>
                            <span class="dash-flow-num"><?= $i + 1 ?></span>
                            <div>
                                <strong><i class="ti <?= e($paso['icon']) ?>"></i> <?= e($paso['titulo']) ?></strong>
                                <p><?= e($paso['texto']) ?></p>
                                <?php if ($vistas): ?>
                                    <div class="dash-flow-links">
                                        <?php foreach ($vistas as $v): ?><a href="index.php?view=<?= e($v) ?>"><?= e($sections[$v]['label']) ?></a><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <section class="dash-panel dash-panel-full">
                <div class="dash-panel-head">
                    <h2><i class="ti ti-database"></i> Información registrada</h2>
                </div>
                <div class="dash-figures">
                    <?php
                    $cifras = [
                        ['documentos', 'ti-folder', $stats['documentos'], 'Documentos para ingenios'],
                        ['biblioteca_interna', 'ti-folder-star', $stats['biblioteca_interna'], 'Documentos internos'],
                        ['correos', 'ti-mail', $stats['correos'], 'Correos enviados'],
                        ['tableros', 'ti-chart-bar', $stats['tableros'], 'Tableros activos'],
                        ['informes', 'ti-report', $stats['informes'], 'Informes cuatrimestrales'],
                        ['memorias', 'ti-book-2', $stats['memorias'], 'Artículos de memoria'],
                        ['publicaciones', 'ti-share', $stats['publicaciones'], 'Publicaciones en redes'],
                    ];
                    foreach ($cifras as [$v, $icon, $num, $label]):
                        $tag = $puedeVer($v) ? 'a' : 'div';
                    ?>
                        <<?= $tag ?> class="dash-figure"<?= $tag === 'a' ? ' href="index.php?view=' . e($v) . '"' : '' ?>>
                            <span class="quick-icon"><i class="ti <?= e($icon) ?>"></i></span>
                            <span><strong><?= (int) $num ?></strong><small><?= e($label) ?></small></span>
                        </<?= $tag ?>>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if (can_manage_resource($user, 'reuniones') || can_manage_resource($user, 'documentos') || can_manage_resource($user, 'acuerdos')): ?>
                <section class="dash-panel dash-panel-full">
                    <div class="dash-panel-head"><h2><i class="ti ti-bolt"></i> Accesos rápidos</h2></div>
                    <div class="quick-card dash-quick">
                        <?php if (can_manage_resource($user, 'reuniones')): ?>
                            <a class="quick-link" href="index.php?view=reuniones&nuevo=1">
                                <span class="quick-icon"><i class="ti ti-calendar-plus"></i></span>
                                <span><strong>Registrar reunión</strong><small>Capture fecha, temas, asistentes y acuerdos.</small></span>
                            </a>
                        <?php endif; ?>
                        <?php if (can_manage_resource($user, 'acuerdos')): ?>
                            <a class="quick-link" href="index.php?view=acuerdos">
                                <span class="quick-icon"><i class="ti ti-checklist"></i></span>
                                <span><strong>Actualizar acuerdos</strong><small>Reporte avance y estado de los próximos pasos.</small></span>
                            </a>
                        <?php endif; ?>
                        <?php if (can_manage_resource($user, 'documentos')): ?>
                            <a class="quick-link" href="index.php?view=documentos">
                                <span class="quick-icon"><i class="ti ti-upload"></i></span>
                                <span><strong>Agregar documento</strong><small>Cargue archivos o registre un enlace de boletín.</small></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($view !== 'dashboard'): ?>
            <?php if (!$puedeVerSeccionActual): ?>
                <div class="placeholder-note">
                    <i class="ti ti-lock"></i>
                    <span>No tienes permiso para ver esta seccion. Solicita acceso al administrador del modulo.</span>
                </div>

            <?php elseif ($view === 'comites'): ?>
                <?php require __DIR__ . '/includes/catalogo-vista.php'; ?>
            <?php elseif ($view === 'contactos'): ?>
                <?php require __DIR__ . '/includes/directorio-vista.php'; ?>
            <?php elseif (in_array($view, ['reuniones', 'eventos'], true)): ?>
                <?php require __DIR__ . '/includes/agenda-vista.php'; ?>
            <?php elseif ($view === 'acuerdos'): ?>
                <?php require __DIR__ . '/includes/pasos-vista.php'; ?>
            <?php elseif ($view === 'documentos'): ?>
                <?php require __DIR__ . '/includes/biblioteca-vista.php'; ?>
            <?php elseif ($view === 'biblioteca_interna'): ?>
                <?php require __DIR__ . '/includes/biblioteca-vista.php'; ?>
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

                <?php require __DIR__ . '/includes/tableros-tarjetas.php'; ?>

                <?php if ($modalTablerosAbierto && $tableroEditar): ?>
                    <div class="modal-backdrop is-open" data-close-href="index.php?view=tableros">
                        <div class="modal-box" onclick="event.stopPropagation()">
                            <div class="modal-head">
                                <h3><?= $tableroEditar['id'] ? 'Editar tablero' : 'Nuevo tablero' ?></h3>
                                <a class="modal-close" href="index.php?view=tableros"><i class="ti ti-x"></i></a>
                            </div>
                            <form method="post" class="modal-body">
                                <p class="text-muted">Pega la URL de inserción del proveedor, no el código HTML completo. En Power BI: Archivo → Insertar informe → Sitio web o portal. Cada usuario conserva sus permisos de acceso al informe.</p>
                                <input type="hidden" name="accion" value="tablero_guardar">
                                <input type="hidden" name="csrf_tableros" value="<?= e($_SESSION['tableros_csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $tableroEditar['id'] ?>">
                                <label class="form-group form-full"><span class="form-label">Nombre <span class="req">*</span></span>
                                    <input class="form-control" type="text" name="nombre" required value="<?= e($tableroEditar['nombre']) ?>"></label>
                                <label class="form-group form-full"><span class="form-label">Descripcion</span>
                                    <textarea class="form-control" rows="2" name="descripcion"><?= e($tableroEditar['descripcion']) ?></textarea></label>
                                <div class="form-grid">
                                    <label class="form-group"><span class="form-label">URL de inserción (Power BI, Looker, etc.)</span>
                                        <input class="form-control" type="url" name="url" required maxlength="500" value="<?= e($tableroEditar['url']) ?>" placeholder="https://..."></label>
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
                <?php require __DIR__ . '/includes/informes-vista.php'; ?>
            <?php elseif ($view === 'memorias'): ?>
                <?php require __DIR__ . '/includes/memorias-vista.php'; ?>
            <?php elseif ($view === 'publicaciones'): ?>
                <?php
                $plataformasRedes = redes_plataformas();
                $chipPlataforma = static function (string $nombre) use ($plataformasRedes): string {
                    $p = $plataformasRedes[$nombre] ?? $plataformasRedes['Otro'];
                    return '<span class="red-chip" style="--red:' . e($p['color']) . '"><i class="ti ' . e($p['icon']) . '"></i> ' . e($nombre) . '</span>';
                };
                $urlFiltros = static fn(array $extra = []): string => 'index.php?' . http_build_query(array_filter(['view' => 'publicaciones', 'q' => $q, 'plataforma' => $filtroPlataforma, 'mes' => $filtroMes] + $extra, static fn($v) => $v !== '' && $v !== null));
                ?>
                <nav class="red-tabs" aria-label="Secciones de redes sociales">
                    <a href="index.php?view=publicaciones" class="<?= $redesTab === 'publicaciones' ? 'is-active' : '' ?>"><i class="ti ti-share"></i> Publicaciones y WhatsApp</a>
                    <a href="index.php?view=publicaciones&tab=web" class="<?= $redesTab === 'web' ? 'is-active' : '' ?>"><i class="ti ti-world"></i> Sitio web (Google Analytics)</a>
                    <?php if ($puedeGestionarSeccionActual): ?><a href="index.php?view=publicaciones&tab=conexiones" class="<?= $redesTab === 'conexiones' ? 'is-active' : '' ?>"><i class="ti ti-plug-connected"></i> Conexiones</a><?php endif; ?>
                </nav>

                <?php
                $conexionesActivas = array_filter($redesConexiones, static fn($c) => $c['activo']);
                $relevantes = $redesTab === 'web' ? ['ga4'] : ($redesTab === 'publicaciones' ? ['meta', 'linkedin'] : []);
                $estadoVisible = array_intersect_key($conexionesActivas, array_flip($relevantes));
                ?>
                <?php if ($estadoVisible): ?>
                    <div class="red-sync" id="red-sync" <?= $redesAutoPendiente ? 'data-auto="1"' : '' ?> data-csrf="<?= e($_SESSION['redes_csrf']) ?>">
                        <i class="ti ti-refresh"></i>
                        <span class="red-sync-items">
                            <?php foreach ($estadoVisible as $p => $c): $prov = redes_api_proveedores()[$p]; ?>
                                <span class="<?= $c['ultimo_estado'] === 'error' ? 'is-error' : '' ?>" title="<?= e($c['ultimo_mensaje']) ?>"><i class="ti <?= e($prov['icon']) ?>"></i> <?= e($prov['nombre']) ?>: <?= $c['ultimo_estado'] === 'error' ? 'error en la última sincronización' : 'actualizado ' . e(redes_api_hace($c['minutos'])) ?></span>
                            <?php endforeach; ?>
                        </span>
                        <span class="red-sync-estado" id="red-sync-estado" hidden><i class="ti ti-loader-2"></i> Sincronizando en segundo plano…</span>
                    </div>
                <?php endif; ?>

                <?php if ($redesTab === 'publicaciones'): ?>
                    <?php
                    $totalesRedes = ['total' => 0, 'alcance' => 0, 'interacciones' => 0, 'clics' => 0];
                    foreach ($resumenRedes as $r) {
                        foreach ($totalesRedes as $k => $v) {
                            $totalesRedes[$k] += (int) $r[$k];
                        }
                    }
                    ?>
                    <section class="red-resumen">
                        <a class="red-card red-card-total <?= $filtroPlataforma === '' ? 'is-active' : '' ?>" href="<?= e($urlFiltros(['plataforma' => ''])) ?>">
                            <span class="red-card-head"><i class="ti ti-chart-donut"></i> Todas</span>
                            <strong><?= redes_formato($totalesRedes['total']) ?></strong><small>publicaciones</small>
                            <span class="red-card-meta"><?= redes_formato($totalesRedes['alcance']) ?> alcance · <?= redes_formato($totalesRedes['interacciones']) ?> interacciones</span>
                        </a>
                        <?php foreach ($resumenRedes as $r): $p = $plataformasRedes[$r['plataforma']] ?? $plataformasRedes['Otro']; $esWa = $r['plataforma'] === 'WhatsApp'; ?>
                            <a class="red-card <?= $filtroPlataforma === $r['plataforma'] ? 'is-active' : '' ?>" style="--red:<?= e($p['color']) ?>" href="<?= e($urlFiltros(['plataforma' => $r['plataforma']])) ?>">
                                <span class="red-card-head"><i class="ti <?= e($p['icon']) ?>"></i> <?= e((string) $r['plataforma']) ?></span>
                                <strong><?= redes_formato((int) $r['total']) ?></strong><small><?= $esWa ? 'envíos' : 'publicaciones' ?></small>
                                <span class="red-card-meta">
                                    <?php if ($esWa): ?>
                                        <?= redes_formato((int) $r['alcance']) ?> personas · <?= redes_formato((int) $r['compartidos']) ?> reenvíos
                                    <?php else: ?>
                                        <?= redes_formato((int) $r['alcance']) ?> alcance · <?= redes_formato((int) $r['interacciones']) ?> interacciones
                                    <?php endif; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </section>

                    <div class="section-toolbar">
                        <form class="filters-form" method="get">
                            <input type="hidden" name="view" value="publicaciones">
                            <div class="search-box">
                                <i class="ti ti-search"></i>
                                <input class="form-control" type="text" name="q" placeholder="Buscar por título, tipo o grupo..." value="<?= e($q) ?>">
                            </div>
                            <select class="form-control filter-select" name="plataforma" aria-label="Plataforma" onchange="this.form.submit()">
                                <option value="">Todas las plataformas</option>
                                <?php foreach (array_keys($plataformasRedes) as $opt): ?><option value="<?= e($opt) ?>" <?= $filtroPlataforma === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control filter-select" type="month" name="mes" value="<?= e($filtroMes) ?>" aria-label="Mes" onchange="this.form.submit()">
                            <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Filtrar</button>
                            <?php if ($q !== '' || $filtroPlataforma !== '' || $filtroMes !== ''): ?><a class="btn-outline btn-sm" href="index.php?view=publicaciones">Limpiar</a><?php endif; ?>
                        </form>
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <div class="toolbar-actions">
                                <a class="btn-outline" href="<?= e($urlFiltros(['importar' => 'redes'])) ?>"><i class="ti ti-file-import"></i> Importar estadísticas</a>
                                <a class="btn-outline" href="index.php?view=publicaciones&nuevo=1&plataforma_nueva=WhatsApp"><i class="ti ti-brand-whatsapp"></i> Registrar envío</a>
                                <a class="btn-primary" href="index.php?view=publicaciones&nuevo=1"><i class="ti ti-plus"></i> Nueva publicación</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="table-card">
                        <div class="table-wrap">
                            <table class="red-tabla">
                                <thead><tr><th>Fecha</th><th>Publicación</th><th>Estado</th><th class="num">Alcance</th><th class="num">Interacciones</th><th class="num">Clics</th><th class="acc"><?= $puedeGestionarSeccionActual ? 'Acciones' : '' ?></th></tr></thead>
                                <tbody>
                                <?php if (!$publicaciones): ?>
                                    <tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i><?= ($q !== '' || $filtroPlataforma !== '' || $filtroMes !== '') ? 'No hay publicaciones con estos filtros.' : 'Todavía no hay publicaciones registradas. Regístralas a mano o importa las estadísticas exportadas de cada red.' ?></div></td></tr>
                                <?php endif; ?>
                                <?php foreach ($publicaciones as $p): $esWa = $p['plataforma'] === 'WhatsApp'; ?>
                                    <tr>
                                        <td class="red-fecha"><?= $p['fecha'] ? e(date('d/m/Y', strtotime((string) $p['fecha']))) : '—' ?></td>
                                        <td class="cell-wide">
                                            <div class="red-pub">
                                                <?= $chipPlataforma((string) $p['plataforma']) ?><?php if (!empty($p['tipo'])): ?><span class="text-muted"><?= e((string) $p['tipo']) ?></span><?php endif; ?><?php if (($p['origen'] ?? '') === 'importado'): ?><span class="red-origen" title="Métricas importadas del archivo exportado"><i class="ti ti-file-import"></i> Importado</span><?php endif; ?>
                                            </div>
                                            <strong><?= e($p['titulo']) ?></strong>
                                            <?php if ($esWa && !empty($p['destinos'])): ?><div class="text-muted red-destinos"><i class="ti ti-users-group"></i> <?= e(mb_strimwidth((string) $p['destinos'], 0, 120, '…')) ?></div><?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= comites_estado_badge_class((string) $p['estado']) ?>"><?= e((string) $p['estado']) ?></span></td>
                                        <td class="num"><?= redes_formato((int) $p['alcance']) ?><?php if ($esWa): ?><small>personas</small><?php endif; ?></td>
                                        <td class="num"><?= redes_formato((int) $p['interacciones']) ?><?php if ($esWa && ((int) $p['compartidos'] || (int) $p['comentarios'])): ?><small><?= (int) $p['compartidos'] ?> reenv. · <?= (int) $p['comentarios'] ?> resp.</small><?php endif; ?></td>
                                        <td class="num"><?= redes_formato((int) $p['clics']) ?></td>
                                        <td class="acc">
                                            <?php if ($puedeGestionarSeccionActual || !empty($p['url'])): ?>
                                            <details class="row-menu">
                                                <summary><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary>
                                                <div class="row-menu-list" role="menu">
                                                    <?php if (!empty($p['url'])): ?><a href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="ti ti-external-link"></i> Ver publicación</a><?php endif; ?>
                                                    <?php if (!empty($p['material'])): ?><a href="<?= e((string) $p['material']) ?>" target="_blank" rel="noopener"><i class="ti ti-photo"></i> Ver material</a><?php endif; ?>
                                                    <?php if ($puedeGestionarSeccionActual): ?>
                                                        <a href="<?= e($urlFiltros(['editar' => (int) $p['id']])) ?>"><i class="ti ti-edit"></i> Editar y actualizar métricas</a>
                                                        <form method="post" onsubmit="return confirm('¿Eliminar esta publicación?');">
                                                            <input type="hidden" name="accion" value="publicacion_eliminar">
                                                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                            <button type="submit" class="is-danger"><i class="ti ti-trash"></i> Eliminar</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($modalImportar === 'redes'): ?>
                        <div class="modal-backdrop is-open" data-close-href="<?= e($urlFiltros()) ?>">
                            <div class="modal-box" onclick="event.stopPropagation()">
                                <div class="modal-head">
                                    <h3>Importar estadísticas de publicaciones</h3>
                                    <a class="modal-close" href="<?= e($urlFiltros()) ?>"><i class="ti ti-x"></i></a>
                                </div>
                                <form method="post" class="modal-body" enctype="multipart/form-data">
                                    <input type="hidden" name="accion" value="publicacion_importar">
                                    <label class="form-group"><span class="form-label">Plataforma del archivo <span class="req">*</span></span>
                                        <select class="form-control" name="plataforma" required>
                                            <?php foreach (redes_plataformas_importables() as $opt): ?><option value="<?= e($opt) ?>" <?= $filtroPlataforma === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
                                        </select></label>
                                    <label class="form-group"><span class="form-label">Archivo exportado (CSV, XLSX o XLS) <span class="req">*</span></span>
                                        <input class="form-control" type="file" name="archivo" accept=".csv,.xlsx,.xls" required></label>
                                    <div class="red-ayuda">
                                        <strong><i class="ti ti-info-circle"></i> ¿De dónde se descarga?</strong>
                                        <ul>
                                            <li><b>Facebook / Instagram:</b> Meta Business Suite → Estadísticas → Contenido → <i>Exportar datos</i> (CSV).</li>
                                            <li><b>LinkedIn:</b> Página → Análisis → Contenido → <i>Exportar</i> (XLS).</li>
                                            <li><b>X, YouTube, TikTok:</b> la exportación de estadísticas de contenido de cada plataforma.</li>
                                        </ul>
                                        <p>Cada fila actualiza la publicación con el mismo enlace (o la misma fecha y título). Las que no existan se crean como publicadas.</p>
                                    </div>
                                    <div class="actions">
                                        <a class="btn-outline" href="<?= e($urlFiltros()) ?>">Cancelar</a>
                                        <button type="submit" class="btn-primary"><i class="ti ti-file-import"></i> Importar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($modalPublicacionesAbierto && $publicacionEditar): ?>
                        <?php
                        if (!$publicacionEditar['id'] && isset($_GET['plataforma_nueva']) && isset($plataformasRedes[$_GET['plataforma_nueva']])) {
                            $publicacionEditar['plataforma'] = (string) $_GET['plataforma_nueva'];
                            $publicacionEditar['estado'] = 'Publicada';
                        }
                        $esWaForm = $publicacionEditar['plataforma'] === 'WhatsApp';
                        ?>
                        <div class="modal-backdrop is-open" data-close-href="<?= e($urlFiltros()) ?>">
                            <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                                <div class="modal-head">
                                    <h3><?= $publicacionEditar['id'] ? 'Editar publicación' : ($esWaForm ? 'Registrar envío por WhatsApp' : 'Nueva publicación') ?></h3>
                                    <a class="modal-close" href="<?= e($urlFiltros()) ?>"><i class="ti ti-x"></i></a>
                                </div>
                                <form method="post" class="modal-body" enctype="multipart/form-data" id="red-form">
                                    <input type="hidden" name="accion" value="publicacion_guardar">
                                    <input type="hidden" name="id" value="<?= (int) $publicacionEditar['id'] ?>">
                                    <div class="form-grid form-grid-3">
                                        <label class="form-group"><span class="form-label">Fecha</span>
                                            <input class="form-control" type="date" name="fecha" value="<?= e((string) $publicacionEditar['fecha']) ?>"></label>
                                        <label class="form-group"><span class="form-label">Plataforma</span>
                                            <select class="form-control" name="plataforma" id="red-plataforma">
                                                <?php foreach (array_keys($plataformasRedes) as $opt): ?>
                                                    <option value="<?= e($opt) ?>" <?= $publicacionEditar['plataforma'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select></label>
                                        <label class="form-group"><span class="form-label">Tipo</span>
                                            <input class="form-control" type="text" name="tipo" value="<?= e((string) $publicacionEditar['tipo']) ?>" placeholder="Foto, video, boletín, invitación..."></label>
                                    </div>
                                    <label class="form-group form-full"><span class="form-label">Título <span class="req">*</span></span>
                                        <input class="form-control" type="text" name="titulo" required value="<?= e((string) $publicacionEditar['titulo']) ?>"></label>
                                    <label class="form-group form-full" data-solo-whatsapp <?= $esWaForm ? '' : 'hidden' ?>><span class="form-label">Grupos, listas o contactos a los que se envió</span>
                                        <textarea class="form-control" rows="2" name="destinos" placeholder="Ej. Grupo Técnicos de campo; Lista de difusión Ingenios; Comité de Riego"><?= e((string) ($publicacionEditar['destinos'] ?? '')) ?></textarea>
                                        <span class="form-hint">Separe los grupos con punto y coma.</span></label>
                                    <label class="form-group form-full"><span class="form-label">Texto / mensaje</span>
                                        <textarea class="form-control" rows="3" name="copy_texto"><?= e((string) $publicacionEditar['copy_texto']) ?></textarea></label>
                                    <div class="form-grid form-grid-3">
                                        <label class="form-group"><span class="form-label">Responsable</span>
                                            <input class="form-control" type="text" name="responsable" value="<?= e((string) $publicacionEditar['responsable']) ?>"></label>
                                        <label class="form-group"><span class="form-label">Enlace publicado o compartido</span>
                                            <input class="form-control" type="url" name="url" value="<?= e((string) $publicacionEditar['url']) ?>" placeholder="https://..."></label>
                                        <label class="form-group"><span class="form-label">Estado</span>
                                            <select class="form-control" name="estado">
                                                <?php foreach (['Borrador', 'Programada', 'Publicada', 'Pausada'] as $opt): ?>
                                                    <option value="<?= e($opt) ?>" <?= $publicacionEditar['estado'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select></label>
                                    </div>
                                    <label class="form-group form-full"><span class="form-label">Material (imagen, video o PDF)</span>
                                        <input class="form-control" type="file" name="material" accept=".jpg,.jpeg,.png,.mp4,.pdf"></label>
                                    <?php if (!empty($publicacionEditar['material'])): ?>
                                        <div class="file-current"><i class="ti ti-photo"></i> <a href="<?= e((string) $publicacionEditar['material']) ?>" target="_blank" rel="noopener">Material actual</a></div>
                                    <?php endif; ?>

                                    <div class="form-group form-full red-metricas">
                                        <span class="form-label">Métricas</span>
                                        <div class="form-grid form-grid-4">
                                            <?php
                                            $metricasForm = $plataformasRedes[$publicacionEditar['plataforma']]['metricas'] ?? $plataformasRedes['Otro']['metricas'];
                                            foreach (['alcance', 'impresiones', 'interacciones', 'reacciones', 'comentarios', 'compartidos', 'clics', 'reproducciones', 'guardados'] as $campo):
                                            ?>
                                                <label class="form-group" data-metrica="<?= e($campo) ?>" <?= isset($metricasForm[$campo]) ? '' : 'hidden' ?>><span class="form-label"><?= e($metricasForm[$campo] ?? ucfirst($campo)) ?></span>
                                                    <input class="form-control" type="number" min="0" name="<?= e($campo) ?>" value="<?= (int) $publicacionEditar[$campo] ?>"></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <script type="application/json" id="red-metricas-config"><?= json_encode(array_map(static fn($p) => $p['metricas'], $plataformasRedes), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>

                                    <div class="actions">
                                        <a class="btn-outline" href="<?= e($urlFiltros()) ?>">Cancelar</a>
                                        <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($redesTab === 'web'): ?>
                    <?php
                    $webActual = $webMetricas[0] ?? null;
                    $webAnterior = $webMetricas[1] ?? null;
                    $variacion = static function (string $campo) use ($webActual, $webAnterior): string {
                        if (!$webActual || !$webAnterior || (float) $webAnterior[$campo] == 0.0) {
                            return '';
                        }
                        $pct = (int) round(((float) $webActual[$campo] - (float) $webAnterior[$campo]) / (float) $webAnterior[$campo] * 100);
                        return '<span class="red-var ' . ($pct >= 0 ? 'is-up' : 'is-down') . '"><i class="ti ti-arrow-' . ($pct >= 0 ? 'up' : 'down') . '-right"></i> ' . abs($pct) . '% vs. mes anterior</span>';
                    };
                    $mesTexto = static function (string $periodo): string {
                        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
                        return ucfirst($meses[(int) substr($periodo, 5, 2) - 1] ?? '') . ' ' . substr($periodo, 0, 4);
                    };
                    $maxVistas = max(1, ...array_map(static fn($w) => (int) $w['vistas'], $webMetricas ?: [['vistas' => 1]]));
                    ?>
                    <div class="section-toolbar">
                        <div class="red-web-titulo">
                            <?php if ($webActual): ?>Último mes registrado: <strong><?= e($mesTexto((string) $webActual['periodo'])) ?></strong><?php else: ?>Sin datos del sitio web todavía.<?php endif; ?>
                        </div>
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <div class="toolbar-actions">
                                <a class="btn-outline" href="index.php?view=publicaciones&tab=web&importar=web"><i class="ti ti-file-import"></i> Importar de Google Analytics</a>
                                <a class="btn-primary" href="index.php?view=publicaciones&tab=web&web_mes=nuevo"><i class="ti ti-plus"></i> Registrar mes</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($webActual): ?>
                        <section class="red-resumen red-resumen-web">
                            <div class="red-card"><span class="red-card-head"><i class="ti ti-users"></i> Usuarios</span><strong><?= redes_formato((int) $webActual['usuarios']) ?></strong><small><?= redes_formato((int) $webActual['nuevos_usuarios']) ?> nuevos</small><?= $variacion('usuarios') ?></div>
                            <div class="red-card"><span class="red-card-head"><i class="ti ti-click"></i> Sesiones</span><strong><?= redes_formato((int) $webActual['sesiones']) ?></strong><small>visitas al sitio</small><?= $variacion('sesiones') ?></div>
                            <div class="red-card"><span class="red-card-head"><i class="ti ti-eye"></i> Vistas de página</span><strong><?= redes_formato((int) $webActual['vistas']) ?></strong><small><?= e(redes_duracion((int) $webActual['duracion_seg'])) ?> tiempo medio</small><?= $variacion('vistas') ?></div>
                            <div class="red-card" style="--red:#25d366"><span class="red-card-head"><i class="ti ti-share"></i> Desde redes sociales</span><strong><?= redes_formato((int) $webActual['sesiones_redes']) ?></strong><small><?= (int) $webActual['sesiones'] ? round((int) $webActual['sesiones_redes'] / (int) $webActual['sesiones'] * 100) : 0 ?>% de las sesiones · <?= redes_formato((int) $webActual['sesiones_whatsapp']) ?> de WhatsApp</small><?= $variacion('sesiones_redes') ?></div>
                        </section>
                    <?php endif; ?>

                    <div class="table-card">
                        <div class="table-wrap">
                            <table class="red-tabla">
                                <thead><tr><th>Mes</th><th class="num">Usuarios</th><th class="num">Sesiones</th><th>Vistas de página</th><th class="num">Tiempo medio</th><th class="num">Rebote</th><th class="num">Desde redes</th><th class="num">WhatsApp</th><th class="acc"><?= $puedeGestionarSeccionActual ? 'Acciones' : '' ?></th></tr></thead>
                                <tbody>
                                <?php if (!$webMetricas): ?>
                                    <tr><td colspan="9"><div class="empty-state"><i class="ti ti-world"></i>Registra el primer mes o importa el informe exportado de Google Analytics.</div></td></tr>
                                <?php endif; ?>
                                <?php foreach ($webMetricas as $w): ?>
                                    <tr>
                                        <td><strong><?= e($mesTexto((string) $w['periodo'])) ?></strong><?php if (!empty($w['notas'])): ?><div class="text-muted"><?= e(mb_strimwidth((string) $w['notas'], 0, 80, '…')) ?></div><?php endif; ?><div class="red-fuente"><?= e((string) ($w['fuente'] ?? '')) ?></div></td>
                                        <td class="num"><?= redes_formato((int) $w['usuarios']) ?></td>
                                        <td class="num"><?= redes_formato((int) $w['sesiones']) ?></td>
                                        <td><div class="cell-progress"><div class="progress-track"><div class="progress-fill" style="width:<?= (int) round((int) $w['vistas'] / $maxVistas * 100) ?>%"></div></div><span><?= redes_formato((int) $w['vistas']) ?></span></div></td>
                                        <td class="num"><?= e(redes_duracion((int) $w['duracion_seg'])) ?></td>
                                        <td class="num"><?= number_format((float) $w['rebote'], 1) ?>%</td>
                                        <td class="num"><?= redes_formato((int) $w['sesiones_redes']) ?></td>
                                        <td class="num"><?= redes_formato((int) $w['sesiones_whatsapp']) ?></td>
                                        <td class="acc">
                                            <?php if ($puedeGestionarSeccionActual): ?>
                                            <details class="row-menu">
                                                <summary><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary>
                                                <div class="row-menu-list" role="menu">
                                                    <a href="index.php?view=publicaciones&tab=web&web_mes=<?= e((string) $w['periodo']) ?>"><i class="ti ti-edit"></i> Editar</a>
                                                    <form method="post" onsubmit="return confirm('¿Eliminar los datos de este mes?');">
                                                        <input type="hidden" name="accion" value="web_eliminar">
                                                        <input type="hidden" name="periodo" value="<?= e((string) $w['periodo']) ?>">
                                                        <button type="submit" class="is-danger"><i class="ti ti-trash"></i> Eliminar</button>
                                                    </form>
                                                </div>
                                            </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($modalImportar === 'web'): ?>
                        <div class="modal-backdrop is-open" data-close-href="index.php?view=publicaciones&tab=web">
                            <div class="modal-box" onclick="event.stopPropagation()">
                                <div class="modal-head">
                                    <h3>Importar desde Google Analytics</h3>
                                    <a class="modal-close" href="index.php?view=publicaciones&tab=web"><i class="ti ti-x"></i></a>
                                </div>
                                <form method="post" class="modal-body" enctype="multipart/form-data">
                                    <input type="hidden" name="accion" value="web_importar">
                                    <label class="form-group"><span class="form-label">Informe exportado (CSV, XLSX o XLS) <span class="req">*</span></span>
                                        <input class="form-control" type="file" name="archivo" accept=".csv,.xlsx,.xls" required></label>
                                    <div class="red-ayuda">
                                        <strong><i class="ti ti-info-circle"></i> Cómo exportarlo de Google Analytics 4</strong>
                                        <ol>
                                            <li>Abre <i>Informes → Adquisición → Adquisición de tráfico</i>.</li>
                                            <li>Elige el rango de fechas y agrega la dimensión secundaria <b>Año y mes</b> (o <b>Fecha</b>).</li>
                                            <li>Usa <i>Compartir este informe → Descargar archivo → CSV</i>.</li>
                                        </ol>
                                        <p>Se agrupa por mes: usuarios, sesiones, vistas, tiempo medio y rebote. Si el informe trae el canal o la fuente de la sesión, también se calculan las visitas desde redes sociales y desde WhatsApp. Los meses que ya existen se reemplazan.</p>
                                    </div>
                                    <div class="actions">
                                        <a class="btn-outline" href="index.php?view=publicaciones&tab=web">Cancelar</a>
                                        <button type="submit" class="btn-primary"><i class="ti ti-file-import"></i> Importar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($webEditar): ?>
                        <div class="modal-backdrop is-open" data-close-href="index.php?view=publicaciones&tab=web">
                            <div class="modal-box modal-wide" onclick="event.stopPropagation()">
                                <div class="modal-head">
                                    <h3><?= isset($webEditar['id']) ? 'Editar ' . e($mesTexto((string) $webEditar['periodo'])) : 'Registrar mes del sitio web' ?></h3>
                                    <a class="modal-close" href="index.php?view=publicaciones&tab=web"><i class="ti ti-x"></i></a>
                                </div>
                                <form method="post" class="modal-body">
                                    <input type="hidden" name="accion" value="web_guardar">
                                    <div class="form-grid form-grid-4">
                                        <label class="form-group"><span class="form-label">Mes <span class="req">*</span></span>
                                            <input class="form-control" type="month" name="periodo" required value="<?= e((string) $webEditar['periodo']) ?>" <?= isset($webEditar['id']) ? 'readonly' : '' ?>></label>
                                        <?php foreach (['usuarios' => 'Usuarios activos', 'nuevos_usuarios' => 'Usuarios nuevos', 'sesiones' => 'Sesiones', 'vistas' => 'Vistas de página', 'sesiones_redes' => 'Sesiones desde redes sociales', 'sesiones_whatsapp' => 'Sesiones desde WhatsApp'] as $campo => $etiqueta): ?>
                                            <label class="form-group"><span class="form-label"><?= e($etiqueta) ?></span>
                                                <input class="form-control" type="number" min="0" name="<?= e($campo) ?>" value="<?= (int) $webEditar[$campo] ?>"></label>
                                        <?php endforeach; ?>
                                        <label class="form-group"><span class="form-label">Rebote (%)</span>
                                            <input class="form-control" type="number" min="0" max="100" step="0.01" name="rebote" value="<?= e((string) (float) $webEditar['rebote']) ?>"></label>
                                    </div>
                                    <div class="form-grid form-grid-4">
                                        <label class="form-group"><span class="form-label">Tiempo medio (min)</span>
                                            <input class="form-control" type="number" min="0" name="duracion_min" value="<?= intdiv((int) $webEditar['duracion_seg'], 60) ?>"></label>
                                        <label class="form-group"><span class="form-label">Tiempo medio (seg)</span>
                                            <input class="form-control" type="number" min="0" max="59" name="duracion_s" value="<?= (int) $webEditar['duracion_seg'] % 60 ?>"></label>
                                    </div>
                                    <label class="form-group form-full"><span class="form-label">Notas</span>
                                        <textarea class="form-control" rows="2" name="notas" placeholder="Ej. Campaña del boletín de zafra, publicación del informe anual..."><?= e((string) ($webEditar['notas'] ?? '')) ?></textarea></label>
                                    <p class="form-hint">Los datos están en Google Analytics 4 → Informes → Adquisición → Adquisición de tráfico (seleccionando el mes).</p>
                                    <div class="actions">
                                        <a class="btn-outline" href="index.php?view=publicaciones&tab=web">Cancelar</a>
                                        <button type="submit" class="btn-primary"><i class="ti ti-check"></i> Guardar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php elseif ($redesTab === 'conexiones'): ?>
                    <p class="red-con-intro">Con la conexión directa, las métricas se actualizan solas cada cierto número de horas al abrir esta sección (o con una tarea programada en el servidor). Las credenciales se guardan cifradas y nunca se vuelven a mostrar completas.</p>
                    <div class="red-con-grid">
                        <?php foreach (redes_api_proveedores() as $p => $prov): $c = $redesConexiones[$p]; $cfg = $c['config']; $sec = $c['secreto']; ?>
                            <section class="red-con" id="conexion-<?= e($p) ?>" style="--red:<?= e($prov['color']) ?>">
                                <header class="red-con-head">
                                    <span class="red-con-icon"><i class="ti <?= e($prov['icon']) ?>"></i></span>
                                    <div>
                                        <h3><?= e($prov['nombre']) ?></h3>
                                        <?php if (!$c['activo']): ?>
                                            <span class="red-con-estado">Sin conectar</span>
                                        <?php elseif ($c['ultimo_estado'] === 'error'): ?>
                                            <span class="red-con-estado is-error"><i class="ti ti-alert-triangle"></i> Error <?= e(redes_api_hace($c['minutos'])) ?></span>
                                        <?php else: ?>
                                            <span class="red-con-estado is-ok"><i class="ti ti-circle-check"></i> Conectado · <?= $c['ultimo_sync'] ? 'sincronizado ' . e(redes_api_hace($c['minutos'])) : 'aún sin sincronizar' ?></span>
                                        <?php endif; ?>
                                    </div>
                                </header>
                                <?php if ($c['activo'] && $c['ultimo_mensaje'] !== ''): ?>
                                    <p class="red-con-msg <?= $c['ultimo_estado'] === 'error' ? 'is-error' : '' ?>"><?= e($c['ultimo_mensaje']) ?></p>
                                <?php endif; ?>

                                <form method="post" enctype="multipart/form-data" class="red-con-form">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['redes_csrf']) ?>">
                                    <input type="hidden" name="proveedor" value="<?= e($p) ?>">
                                    <?php if ($p === 'meta'): ?>
                                        <label class="form-group"><span class="form-label">ID de la página de Facebook <span class="req">*</span></span>
                                            <input class="form-control" name="page_id" inputmode="numeric" value="<?= e((string) ($cfg['page_id'] ?? '')) ?>" placeholder="Ej. 104512345678901"></label>
                                        <label class="form-group"><span class="form-label">Token de acceso de la página <span class="req">*</span></span>
                                            <input class="form-control" type="password" name="token" autocomplete="off" placeholder="<?= !empty($sec['token']) ? e(redes_api_mascara($sec['token'])) . ' (guardado; deja vacío para conservarlo)' : 'EAAG...' ?>"></label>
                                        <div class="form-grid">
                                            <label class="form-group"><span class="form-label">ID de Instagram (opcional)</span>
                                                <input class="form-control" name="ig_user_id" inputmode="numeric" value="<?= e((string) ($cfg['ig_user_id'] ?? '')) ?>" placeholder="Se detecta solo"></label>
                                            <label class="form-group"><span class="form-label">Versión de la API</span>
                                                <input class="form-control" name="version" value="<?= e((string) ($cfg['version'] ?? 'v23.0')) ?>"></label>
                                        </div>
                                    <?php elseif ($p === 'linkedin'): ?>
                                        <label class="form-group"><span class="form-label">ID de la organización (página) <span class="req">*</span></span>
                                            <input class="form-control" name="org_id" inputmode="numeric" value="<?= e((string) ($cfg['org_id'] ?? '')) ?>" placeholder="Número de linkedin.com/company/12345678/admin"></label>
                                        <label class="form-group"><span class="form-label">Token de acceso <span class="req">*</span></span>
                                            <input class="form-control" type="password" name="access_token" autocomplete="off" placeholder="<?= !empty($sec['access_token']) ? e(redes_api_mascara($sec['access_token'])) . ' (guardado)' : 'AQV...' ?>">
                                            <?php if (!empty($cfg['token_expira'])): ?><span class="form-hint">Vence el <?= e(date('d/m/Y', strtotime($cfg['token_expira']))) ?><?= !empty($sec['refresh_token']) ? ' · se renueva solo' : '' ?>.</span><?php endif; ?></label>
                                        <details class="red-con-mas" <?= !empty($sec['refresh_token']) ? 'open' : '' ?>>
                                            <summary>Renovación automática del token (opcional)</summary>
                                            <div class="form-grid">
                                                <label class="form-group"><span class="form-label">Client ID</span>
                                                    <input class="form-control" name="client_id" value="<?= e((string) ($cfg['client_id'] ?? '')) ?>"></label>
                                                <label class="form-group"><span class="form-label">Client secret</span>
                                                    <input class="form-control" type="password" name="client_secret" autocomplete="off" placeholder="<?= !empty($sec['client_secret']) ? e(redes_api_mascara($sec['client_secret'])) . ' (guardado)' : '' ?>"></label>
                                            </div>
                                            <label class="form-group"><span class="form-label">Refresh token</span>
                                                <input class="form-control" type="password" name="refresh_token" autocomplete="off" placeholder="<?= !empty($sec['refresh_token']) ? e(redes_api_mascara($sec['refresh_token'])) . ' (guardado)' : '' ?>"></label>
                                            <label class="form-group"><span class="form-label">Versión de la API (AAAAMM)</span>
                                                <input class="form-control" name="version" value="<?= e((string) ($cfg['version'] ?? '')) ?>" placeholder="Automática: <?= e(redes_linkedin_version([])) ?>"></label>
                                        </details>
                                    <?php else: ?>
                                        <label class="form-group"><span class="form-label">ID de la propiedad de GA4 <span class="req">*</span></span>
                                            <input class="form-control" name="property_id" inputmode="numeric" value="<?= e((string) ($cfg['property_id'] ?? '')) ?>" placeholder="Ej. 412345678"></label>
                                        <label class="form-group"><span class="form-label">Clave JSON de la cuenta de servicio <?= empty($sec['service_account']) ? '<span class="req">*</span>' : '' ?></span>
                                            <input class="form-control" type="file" name="service_account_file" accept=".json,application/json">
                                            <span class="form-hint"><?= !empty($cfg['client_email']) ? 'Cuenta guardada: ' . e((string) $cfg['client_email']) . '. Carga otro archivo solo para reemplazarla.' : 'El archivo .json que descargas al crear la clave.' ?></span></label>
                                    <?php endif; ?>
                                    <label class="form-group"><span class="form-label">Actualizar cada</span>
                                        <select class="form-control" name="intervalo">
                                            <?php foreach ([1 => '1 hora', 3 => '3 horas', 6 => '6 horas', 12 => '12 horas', 24 => '24 horas', 168 => '1 semana'] as $h => $txt): ?><option value="<?= $h ?>" <?= (int) ($cfg['intervalo'] ?? 6) === $h ? 'selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?>
                                        </select></label>
                                    <div class="red-con-acciones">
                                        <button type="submit" name="accion" value="conexion_guardar" class="btn-primary btn-sm"><i class="ti ti-device-floppy"></i> Guardar</button>
                                        <?php if ($c['activo']): ?>
                                            <button type="submit" name="accion" value="conexion_probar" class="btn-outline btn-sm" formnovalidate><i class="ti ti-plug-connected"></i> Probar</button>
                                            <button type="submit" name="accion" value="conexion_sincronizar" class="btn-outline btn-sm" formnovalidate data-sync-btn><i class="ti ti-refresh"></i> Sincronizar ahora</button>
                                            <button type="submit" name="accion" value="conexion_desconectar" class="btn-danger btn-sm" formnovalidate onclick="return confirm('¿Eliminar esta conexión? Los datos ya sincronizados se conservan.')"><i class="ti ti-plug-x"></i> Desconectar</button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <details class="red-ayuda red-con-guia">
                                    <summary><i class="ti ti-help-circle"></i> Cómo obtener las credenciales</summary>
                                    <?php if ($p === 'meta'): ?>
                                        <ol>
                                            <li>En <b>developers.facebook.com</b> crea una app de tipo <i>Empresa</i> y vincúlala al portafolio comercial de CENGICAÑA.</li>
                                            <li>En <b>business.facebook.com → Configuración → Usuarios del sistema</b>, crea un usuario del sistema y asígnale la página (y la cuenta de Instagram).</li>
                                            <li>Genera un token para la app con los permisos <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>read_insights</code>, <code>instagram_basic</code> e <code>instagram_manage_insights</code>. Los tokens de usuario del sistema no vencen.</li>
                                            <li>El ID de la página está en la página de Facebook → <i>Información → Transparencia de la página</i>.</li>
                                        </ol>
                                        <p>Se sincronizan las publicaciones de los últimos 90 días de Facebook y de Instagram.</p>
                                    <?php elseif ($p === 'linkedin'): ?>
                                        <ol>
                                            <li>En <b>linkedin.com/developers</b> crea una app asociada a la página de CENGICAÑA.</li>
                                            <li>Solicita el producto <b>Community Management API</b>. LinkedIn revisa la solicitud, y la aprobación puede tardar varias semanas.</li>
                                            <li>Con la app aprobada, genera un token con el permiso <code>r_organization_social</code> en <i>Herramientas → OAuth Token Generator</i>. Un administrador de la página debe autorizarlo.</li>
                                            <li>El token dura 60 días. Si LinkedIn entrega también un <i>refresh token</i>, agrégalo junto con el Client ID y el Client secret para que se renueve solo.</li>
                                        </ol>
                                        <p>Se sincronizan las últimas 50 publicaciones de la página.</p>
                                    <?php else: ?>
                                        <ol>
                                            <li>En <b>console.cloud.google.com</b> crea un proyecto y habilita la <b>Google Analytics Data API</b>.</li>
                                            <li>En <i>IAM → Cuentas de servicio</i> crea una cuenta y descarga una clave en formato <b>JSON</b>.</li>
                                            <li>En Google Analytics: <i>Administrar → Gestión del acceso a la propiedad</i>, agrega el correo de la cuenta de servicio con el rol <b>Lector</b>.</li>
                                            <li>El ID de la propiedad está en <i>Administrar → Detalles de la propiedad</i>.</li>
                                        </ol>
                                        <p>Se sincronizan los últimos 13 meses: usuarios, sesiones, vistas, tiempo medio, rebote y visitas desde redes y WhatsApp.</p>
                                    <?php endif; ?>
                                </details>
                            </section>
                        <?php endforeach; ?>
                    </div>
                    <p class="red-con-cron"><i class="ti ti-clock"></i> Para que se actualice aunque nadie abra la sección, programa en el servidor: <code>php comites/cron/redes-sync.php</code> (por ejemplo, cada hora).</p>
                <?php endif; ?>

            <?php elseif ($view === 'usuarios'): ?>
                <?php require __DIR__ . '/includes/usuarios-vista.php'; ?>

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
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
