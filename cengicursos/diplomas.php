<?php
require_once "conexion.php";
require_once "menu.php";
require_once "diplomas_helpers.php";
require_once "planificacion_helpers.php"; // cengi_pp_kpi()

cengi_require_ver_diplomas();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_diplomas();
$mensaje = '';
$mensajeTipo = 'success';
$avisos = [];

function cengi_dip_codigo(PDO $db, $prefijo)
{
    do {
        $codigo = $prefijo . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("SELECT COUNT(*) FROM diplomas WHERE codigo_unico = ?");
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $codigo;
}

function cengi_dip_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

// Igual que cengi_dip_html(), pero para usarse especificamente en el href de un enlace a
// un archivo subido (pdf_path): normaliza el path legado (ver cengi_normalizar_url_archivo()
// en conexion.php) antes de escaparlo, en vez de imprimir el string de BD tal cual.
function cengi_dip_href($valor)
{
    return htmlspecialchars(cengi_normalizar_url_archivo($valor), ENT_QUOTES, 'UTF-8');
}

function cengi_dip_mover_archivo(array $archivo, $carpeta)
{
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return null;
    }
    $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $archivo['name']);
    // cengi_guardar_archivo_subido() (conexion.php) usa una ruta de filesystem
    // absoluta para escribir y devuelve una URL raiz-absoluta ("/uploads/...")
    // en vez de la ruta relativa fragil que se usaba antes.
    return cengi_guardar_archivo_subido($archivo['tmp_name'], $carpeta, $nombreArchivo);
}

// "disenar": diseñador y emision masiva con codigo y QR (js/diplomas.js, diplomas_api.php).
// "curso" / "evento": carga de PDF ya elaborados fuera del sistema.
$tab = $_GET['tab'] ?? 'disenar';
if (!in_array($tab, ['disenar', 'emitidos', 'curso', 'evento'], true)) {
    $tab = 'disenar';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if ($accion === 'carga_masiva_curso') {
        $cursoId = (int) ($_POST['curso_id'] ?? 0);
        $tab = 'curso';

        if ($cursoId > 0 && !empty($_FILES['diplomas']['name'][0])) {
            $stmtAsign = $db->prepare("
                SELECT a.id AS asignacion_id, p.cui_participantes, p.nombre_participantes
                FROM asignaciones a
                INNER JOIN participantes p ON p.id = a.participantes_id
                WHERE a.cursos_id = ?
            ");
            $stmtAsign->execute([$cursoId]);
            $asignados = $stmtAsign->fetchAll(PDO::FETCH_ASSOC);

            $total = count($_FILES['diplomas']['name']);
            for ($i = 0; $i < $total; $i++) {
                if ($_FILES['diplomas']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $nombreOriginal = $_FILES['diplomas']['name'][$i];
                preg_match_all('/\d{6,}/', $nombreOriginal, $digitos);
                $digitosArchivo = $digitos[0] ?? [];

                $coincidencia = null;
                foreach ($asignados as $asig) {
                    $cuiLimpio = preg_replace('/\D/', '', (string) $asig['cui_participantes']);
                    if ($cuiLimpio !== '' && in_array($cuiLimpio, $digitosArchivo, true)) {
                        $coincidencia = $asig;
                        break;
                    }
                }

                if (!$coincidencia) {
                    $nombreNormalizado = cengi_texto_normalizado($nombreOriginal);
                    foreach ($asignados as $asig) {
                        $nombreParticipanteNorm = cengi_texto_normalizado($asig['nombre_participantes']);
                        if ($nombreParticipanteNorm !== '' && strpos($nombreNormalizado, $nombreParticipanteNorm) !== false) {
                            $coincidencia = $asig;
                            break;
                        }
                    }
                }

                if (!$coincidencia) {
                    $avisos[] = "No se pudo emparejar el archivo \"{$nombreOriginal}\" con ningun participante de este curso (por CUI o nombre en el nombre del archivo).";
                    continue;
                }

                $archivoInfo = [
                    'name' => $nombreOriginal,
                    'tmp_name' => $_FILES['diplomas']['tmp_name'][$i],
                ];
                $ruta = cengi_dip_mover_archivo($archivoInfo, 'diplomas_curso');

                if (!$ruta) {
                    $avisos[] = "El archivo \"{$nombreOriginal}\" no es un PDF valido o no se pudo guardar.";
                    continue;
                }

                $stmtExiste = $db->prepare("SELECT id FROM diplomas WHERE tipo = 'curso' AND asignacion_id = ?");
                $stmtExiste->execute([$coincidencia['asignacion_id']]);
                $existente = $stmtExiste->fetchColumn();

                if ($existente) {
                    $stmt = $db->prepare("UPDATE diplomas SET pdf_path = ?, emitido_en = NOW() WHERE id = ?");
                    $stmt->execute([$ruta, $existente]);
                } else {
                    $codigo = cengi_dip_codigo($db, 'CEN-DIP');
                    $stmt = $db->prepare("
                        INSERT INTO diplomas (tipo, asignacion_id, codigo_unico, pdf_path, emitido_en, creado_por)
                        VALUES ('curso', ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$coincidencia['asignacion_id'], $codigo, $ruta, cengi_usuario_actual_id()]);
                }
            }

            $mensaje = 'Carga masiva procesada. Revisa los avisos si algun archivo no se pudo emparejar.';
        } else {
            $mensaje = 'Selecciona el curso y al menos un archivo PDF.';
            $mensajeTipo = 'error';
        }
    } elseif ($accion === 'subir_diploma_evento') {
        $eventoParticipanteId = (int) ($_POST['evento_participante_id'] ?? 0);
        $tab = 'evento';

        if ($eventoParticipanteId > 0 && isset($_FILES['diploma']) && $_FILES['diploma']['error'] === UPLOAD_ERR_OK) {
            $ruta = cengi_dip_mover_archivo($_FILES['diploma'], 'diplomas_evento');

            if ($ruta) {
                $stmtExiste = $db->prepare("SELECT id FROM diplomas WHERE tipo = 'evento' AND evento_participante_id = ?");
                $stmtExiste->execute([$eventoParticipanteId]);
                $existente = $stmtExiste->fetchColumn();

                if ($existente) {
                    $stmt = $db->prepare("UPDATE diplomas SET pdf_path = ?, emitido_en = NOW() WHERE id = ?");
                    $stmt->execute([$ruta, $existente]);
                } else {
                    $codigo = cengi_dip_codigo($db, 'CEN-EVT');
                    $stmt = $db->prepare("
                        INSERT INTO diplomas (tipo, evento_participante_id, codigo_unico, pdf_path, emitido_en, creado_por)
                        VALUES ('evento', ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$eventoParticipanteId, $codigo, $ruta, cengi_usuario_actual_id()]);
                }
                $mensaje = 'Constancia cargada correctamente.';
            } else {
                $mensaje = 'El archivo debe ser un PDF valido.';
                $mensajeTipo = 'error';
            }
        } else {
            $mensaje = 'Selecciona el participante y un archivo PDF.';
            $mensajeTipo = 'error';
        }
    }
}

// ---------------------------------------------------------------- resumen (pestañas e indicadores)
$hoy = date('Y-m-d');
$resumenDip = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(YEAR(emitido_en) = YEAR(CURDATE())), 0) AS anio,
        COALESCE(SUM(enviado_en IS NOT NULL), 0) AS enviados, COALESCE(SUM(pdf_path IS NOT NULL AND pdf_path <> ''), 0) AS pdfs,
        COALESCE(SUM(tipo = 'curso'), 0) AS cursos, COALESCE(SUM(tipo = 'evento'), 0) AS eventos
    FROM diplomas")->fetch(PDO::FETCH_ASSOC);
// Cursos que terminaron en el último año y tienen participantes sin diploma: trabajo pendiente.
$cursosPendientes = $db->query("SELECT c.id, c.nombre_cursos, c.fin, COUNT(a.id) AS asignados, COUNT(d.id) AS con_diploma
    FROM cursos c
    INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
    LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
    WHERE c.fin < CURDATE() AND c.fin >= CURDATE() - INTERVAL 1 YEAR
    GROUP BY c.id, c.nombre_cursos, c.fin HAVING con_diploma < asignados ORDER BY c.fin DESC")->fetchAll(PDO::FETCH_ASSOC);
$sinDiploma = array_sum(array_map(static function ($c) {
    return (int) $c['asignados'] - (int) $c['con_diploma'];
}, $cursosPendientes));

// ---------------------------------------------------------------- diseñador
$actividadesDiploma = cengi_dip_actividades($db);
$actividadInicial = (string) ($_GET['actividad'] ?? '');

// ---------------------------------------------------------------- emitidos (filtros y paginación en el servidor)
$emTipo = in_array($_GET['tipo'] ?? '', ['curso', 'evento'], true) ? $_GET['tipo'] : '';
$emEstado = in_array($_GET['estado'] ?? '', ['emitido', 'enviado', 'pdf'], true) ? $_GET['estado'] : '';
$emAnio = (int) ($_GET['anio'] ?? 0);
$emBusqueda = trim((string) ($_GET['q'] ?? ''));
$emPagina = max(1, (int) ($_GET['p'] ?? 1));
$emPorPagina = 50;
$emitidosTotal = 0;
$diplomasEmitidos = [];
$aniosEmitidos = [];
if ($tab === 'emitidos') {
    $nombreSql = "COALESCE(NULLIF(d.nombre_participante, ''), p.nombre_participantes, ep.nombre_invitado)";
    $actividadSql = "COALESCE(NULLIF(d.actividad, ''), c.nombre_cursos, e.nombre)";
    $desde = "FROM diplomas d
        LEFT JOIN asignaciones a ON a.id = d.asignacion_id LEFT JOIN participantes p ON p.id = a.participantes_id LEFT JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN evento_participantes ep ON ep.id = d.evento_participante_id LEFT JOIN eventos e ON e.id = ep.evento_id";
    $donde = ['1 = 1'];
    $params = [];
    if ($emTipo !== '') {
        $donde[] = 'd.tipo = ?';
        $params[] = $emTipo;
    }
    if ($emEstado === 'pdf') {
        $donde[] = "d.pdf_path IS NOT NULL AND d.pdf_path <> ''";
    } elseif ($emEstado === 'enviado') {
        $donde[] = "d.enviado_en IS NOT NULL AND (d.pdf_path IS NULL OR d.pdf_path = '')";
    } elseif ($emEstado === 'emitido') {
        $donde[] = "d.enviado_en IS NULL AND (d.pdf_path IS NULL OR d.pdf_path = '')";
    }
    if ($emAnio > 0) {
        $donde[] = 'YEAR(d.emitido_en) = ?';
        $params[] = $emAnio;
    }
    if ($emBusqueda !== '') {
        $donde[] = "(d.codigo_unico LIKE ? OR $nombreSql LIKE ? OR $actividadSql LIKE ?)";
        array_push($params, '%' . $emBusqueda . '%', '%' . $emBusqueda . '%', '%' . $emBusqueda . '%');
    }
    $where = ' WHERE ' . implode(' AND ', $donde);
    $stmt = $db->prepare("SELECT COUNT(*) $desde $where");
    $stmt->execute($params);
    $emitidosTotal = (int) $stmt->fetchColumn();
    $emPaginas = max(1, (int) ceil($emitidosTotal / $emPorPagina));
    $emPagina = min($emPagina, $emPaginas);
    $exportarEmitidos = ($_GET['exportar'] ?? '') === 'excel';
    $stmt = $db->prepare("SELECT d.codigo_unico, d.tipo, d.emitido_en, d.enviado_en, d.pdf_path, d.documento, d.asignacion_id, d.evento_participante_id,
            $nombreSql AS nombre, $actividadSql AS actividad
        $desde $where ORDER BY d.emitido_en DESC, d.id DESC LIMIT " . ($exportarEmitidos ? 5000 : $emPorPagina . ' OFFSET ' . (($emPagina - 1) * $emPorPagina)));
    $stmt->execute($params);
    $diplomasEmitidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $aniosEmitidos = array_map('intval', $db->query('SELECT DISTINCT YEAR(emitido_en) FROM diplomas WHERE emitido_en IS NOT NULL ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN));

    if ($exportarEmitidos) {
        require_once __DIR__ . '/cobranza_helpers.php';
        cengi_cob_excel('Diplomas emitidos', count($diplomasEmitidos) . ' documento(s)' . ($emBusqueda !== '' ? ' · búsqueda: ' . $emBusqueda : ''), [
            ['Código', 22, 'texto'], ['Participante', 30, 'texto'], ['Actividad', 34, 'texto'], ['Documento', 22, 'texto'], ['Tipo', 9, 'texto'],
            ['Emisión', 12, 'fecha'], ['Enviado por correo', 13, 'fecha'], ['Estado', 13, 'texto'], ['Enlace de validación', 48, 'texto'],
        ], array_map(static function ($d) {
            return [$d['codigo_unico'], $d['nombre'], $d['actividad'], $d['documento'] ?: ($d['tipo'] === 'evento' ? 'Constancia' : 'Diploma'), $d['tipo'] === 'evento' ? 'Evento' : 'Curso',
                $d['emitido_en'], $d['enviado_en'], $d['pdf_path'] ? 'PDF cargado' : ($d['enviado_en'] ? 'Enviado' : 'Emitido'), cengi_dip_url_validar($d['codigo_unico'])];
        }, $diplomasEmitidos), 'Diplomas emitidos');
    }
}
$urlEmitidos = static function (array $cambios) use ($emTipo, $emEstado, $emAnio, $emBusqueda) {
    return 'diplomas.php?' . http_build_query(array_filter(array_merge(['tab' => 'emitidos', 'tipo' => $emTipo, 'estado' => $emEstado, 'anio' => $emAnio ?: '', 'q' => $emBusqueda], $cambios), static function ($v) {
        return $v !== '' && $v !== null && $v !== 0;
    }));
};

// ---------------------------------------------------------------- carga de PDF de curso
$cursosEdiciones = $db->query("
    SELECT id, nombre_cursos, inicio, fin, YEAR(COALESCE(inicio, creado)) AS anio
    FROM cursos
    ORDER BY anio DESC, nombre_cursos
")->fetchAll(PDO::FETCH_ASSOC);

$cursoSeleccionadoId = (int) ($_POST['curso_id'] ?? $_GET['curso_id'] ?? 0);
$cursoSeleccionado = null;
$diplomasDeEsteCurso = [];
if ($cursoSeleccionadoId > 0) {
    foreach ($cursosEdiciones as $c) {
        if ((int) $c['id'] === $cursoSeleccionadoId) {
            $cursoSeleccionado = $c;
            break;
        }
    }
    $stmt = $db->prepare("
        SELECT a.id AS asignacion_id, p.nombre_participantes, p.cui_participantes, d.pdf_path, d.codigo_unico, d.emitido_en, d.enviado_en
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.cursos_id = ?
        ORDER BY p.nombre_participantes
    ");
    $stmt->execute([$cursoSeleccionadoId]);
    $diplomasDeEsteCurso = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$conDocCurso = count(array_filter($diplomasDeEsteCurso, static function ($d) {
    return (bool) $d['codigo_unico'];
}));

// ---------------------------------------------------------------- carga de PDF de evento
$eventosDisponibles = $db->query("SELECT id, nombre, fecha FROM eventos ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
$eventoSeleccionadoId = (int) ($_POST['evento_id'] ?? $_GET['evento_id'] ?? 0);
$participantesEventoSel = [];
if ($eventoSeleccionadoId > 0) {
    $stmt = $db->prepare("
        SELECT ep.id, ep.nombre_invitado, d.pdf_path, d.codigo_unico, d.emitido_en, d.enviado_en
        FROM evento_participantes ep
        LEFT JOIN diplomas d ON d.tipo = 'evento' AND d.evento_participante_id = ep.id
        WHERE ep.evento_id = ?
        ORDER BY ep.nombre_invitado
    ");
    $stmt->execute([$eventoSeleccionadoId]);
    $participantesEventoSel = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$conDocEvento = count(array_filter($participantesEventoSel, static function ($d) {
    return (bool) $d['codigo_unico'];
}));

/** Etiqueta de estado de un documento: PDF cargado, enviado o emitido (o pendiente). */
function cengi_dip_estado_html($pdf, $enviado, $codigo)
{
    if (!$codigo) {
        return '<span class="cengi-status-badge is-neutral"><i></i>Pendiente</span>';
    }
    if ($pdf) {
        return '<span class="cengi-status-badge is-running"><i></i>PDF cargado</span>';
    }
    return $enviado ? '<span class="cengi-status-badge is-active"><i></i>Enviado ' . date('d/m/Y', strtotime($enviado)) . '</span>'
        : '<span class="cengi-status-badge is-upcoming"><i></i>Emitido</span>';
}
?>
<!doctype html>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas cengi-certification-page cg-tema">
<?php $dipV = static function ($f) { return (int) @filemtime(__DIR__ . '/' . $f); }; ?>
<link rel="stylesheet" href="css/planificacion.css?v=<?php echo $dipV('css/planificacion.css'); ?>">
<link rel="stylesheet" href="css/diplomas.css?v=<?php echo $dipV('css/diplomas.css'); ?>">
<?php menu_render(); ?>
<main class="container cengi-cert-page dip-pagina">
    <?php cengi_banner(); ?>

    <nav class="pp-subnav" aria-label="Certificación">
        <?php foreach ([
            'disenar' => ['Diseñar y emitir', 'pencil', ''],
            'emitidos' => ['Emitidos y validación', 'certificate', (int) $resumenDip['total']],
            'curso' => ['Cargar PDF de curso', 'education', ''],
            'evento' => ['Cargar PDF de evento', 'calendar', ''],
        ] as $clave => [$texto, $icono, $n]): ?>
            <a href="diplomas.php?tab=<?php echo $clave; ?>"<?php echo $tab === $clave ? ' class="is-active" aria-current="page"' : ''; ?>><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span><?php echo $texto; ?><?php echo $n !== '' ? ' <b class="dip-nav-n">' . $n . '</b>' : ''; ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_dip_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($avisos): ?>
        <div class="cengi-feedback is-warning">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-warning-sign"></span></div>
            <div><p><b>Avisos de la carga masiva (<?php echo count($avisos); ?>):</b></p><ul class="dip-avisos"><?php foreach ($avisos as $a) { echo '<li>' . cengi_dip_html($a) . '</li>'; } ?></ul></div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'disenar'): ?>
        <details class="dip-ayuda">
            <summary><span class="glyphicon glyphicon-info-sign"></span>¿Cómo funciona el diseñador?
                <?php if ($cursosPendientes): ?><a class="dip-ayuda-pend" href="diplomas.php?tab=emitidos#pendientes"><span class="glyphicon glyphicon-time"></span><?php echo count($cursosPendientes); ?> curso(s) finalizado(s) con <?php echo $sinDiploma; ?> participante(s) sin diploma</a><?php endif; ?></summary>
            <ol>
                <li class="is-verde"><b>Elige la actividad</b><span>Un curso o evento y a quiénes se emite (aprobados, asistentes...).</span></li>
                <li class="is-verde2"><b>Diseña una vez</b><span>Logos, firmas escaneadas, imagen de fondo o tu diseño de Canva.</span></li>
                <li class="is-amarillo"><b>Revisa la vista previa</b><span>Con los datos reales de cada participante: nombre, horas, fecha y código.</span></li>
                <li class="is-naranja"><b>Emite</b><span>Imprime o guarda en PDF (uno por página) o envía por correo; cada documento lleva código y QR de validación.</span></li>
            </ol>
        </details>

        <div class="dip-layout" id="dip-app">
            <div class="dip-panel-ed">
                <details open class="dip-sec"><summary><span class="dip-paso">1</span>Actividad y participantes</summary>
                    <div class="form-group"><label for="dipAct">Curso o evento</label>
                        <select id="dipAct" class="form-control">
                            <option value="">Selecciona...</option>
                            <?php foreach ($actividadesDiploma as $grupo => $lista): if (!$lista) { continue; } ?>
                                <optgroup label="<?php echo cengi_dip_html($grupo); ?>">
                                    <?php foreach ($lista as $a): ?><option value="<?php echo cengi_dip_html($a['clave']); ?>" <?php echo $actividadInicial === $a['clave'] ? 'selected' : ''; ?>><?php echo cengi_dip_html($a['texto']); ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label for="dipFiltro">¿A quiénes se emite?</label><select id="dipFiltro" class="form-control"></select></div>
                </details>
                <div id="dip-editor" hidden>
                    <details open class="dip-sec"><summary><span class="dip-paso">2</span>Diseño</summary>
                        <div class="dip-estilos">
                            <button type="button" data-estilo="institucional"><span class="dip-mini dm-inst"></span>Institucional</button>
                            <button type="button" data-estilo="moderno"><span class="dip-mini dm-mod"></span>Moderno</button>
                            <button type="button" data-estilo="clasico"><span class="dip-mini dm-cla"></span>Clásico</button>
                            <button type="button" data-estilo="canva"><span class="dip-mini dm-canva">Canva</span>Diseño propio</button>
                        </div>
                        <div class="form-group" style="margin-top:12px;"><label for="dipPapel">Tamaño de papel para imprimir</label>
                            <select id="dipPapel" class="form-control" data-campo="papel"><option value="carta">Carta horizontal (Guatemala)</option><option value="a4">A4 horizontal</option></select></div>
                        <div class="dip-grid2" id="dipDisenoOpts">
                            <div class="form-group"><label for="dipColor">Color principal</label><input type="color" id="dipColor" class="form-control" data-campo="color" style="height:36px;padding:3px;"></div>
                            <div class="form-group"><label for="dipFondo">Imagen de fondo</label><select id="dipFondo" class="form-control" data-campo="fondo"><option value="ninguno">Sin imagen</option><option value="propia">Subir una imagen</option></select></div>
                            <div class="form-group is-full" id="dipFondoSubir"><label class="btn btn-default btn-sm" style="cursor:pointer;">Subir imagen de fondo<input type="file" accept="image/png,image/jpeg,image/webp" hidden data-imagen="fondoImg" data-max="1600"></label></div>
                            <div class="form-group is-full" id="dipFondoOpW"><label for="dipFondoOp">Intensidad de la imagen de fondo</label><input type="range" min="5" max="60" id="dipFondoOp" data-campo="fondoOp" data-numero="1"></div>
                        </div>
                        <div class="dip-paleta"><span class="pp-sub">Colores de la marca:</span>
                            <?php foreach (['#1f5632' => 'Verde oscuro', '#73BC25' => 'Verde principal', '#A3D300' => 'Verde secundario', '#FFCC00' => 'Amarillo', '#FF6B00' => 'Naranja'] as $hex => $nombreColor): ?>
                                <button type="button" data-color-marca="<?php echo $hex; ?>" style="background:<?php echo $hex; ?>;" title="<?php echo $nombreColor . ' ' . $hex; ?>" aria-label="Usar <?php echo $nombreColor; ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <details open class="dip-sec" id="dipSecCanva" hidden><summary><span class="dip-paso">3</span>Diseño de Canva y campos automáticos</summary>
                        <div class="form-group"><label>Diseño exportado de Canva <small>(PNG o JPG, carta o A4 horizontal)</small></label>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <img id="dipCanvaMini" alt="" style="height:48px;border-radius:4px;border:1px solid #E4E7E1;" hidden>
                                <label class="btn btn-primary btn-sm" style="cursor:pointer;margin:0;"><span id="dipCanvaBtn">Seleccionar archivo</span><input type="file" accept="image/png,image/jpeg,image/webp" hidden id="dipCanvaFile"></label>
                                <span class="pp-sub" id="dipCanvaNombre"></span>
                            </div></div>
                        <details style="margin-bottom:10px;"><summary class="pp-sub" style="cursor:pointer;display:list-item;">¿Cómo exportarlo desde Canva?</summary><ol class="pp-sub" style="padding-left:18px;">
                            <li>Diseña el diploma en tamaño carta u A4 horizontal con logos, firmas y textos fijos.</li>
                            <li>Deja vacío el espacio del nombre (y de fecha o código si quieres que el sistema los escriba).</li>
                            <li>En Canva: Compartir → Descargar → PNG, y súbelo aquí.</li>
                            <li>Arrastra cada campo a su lugar sobre la vista previa y ajusta letra, tamaño y color.</li></ol></details>
                        <label>Campos que se llenan solos</label>
                        <div id="dipCampos" class="dpc-chips"></div>
                        <div style="display:flex;gap:6px;margin:8px 0 12px;"><button type="button" class="btn btn-default btn-sm" data-agregar="texto">+ Campo de texto</button><button type="button" class="btn btn-default btn-sm" data-agregar="qr">+ QR de validación</button></div>
                        <div id="dipCampoEd" style="border-top:1px solid #E4E7E1;padding-top:10px;"></div>
                    </details>
                    <details open class="dip-sec" data-clasico><summary><span class="dip-paso">3</span>Logos</summary><div id="dipLogos" class="dip-logos-ed"></div><p class="pp-sub" style="margin-top:6px;">PNG con fondo transparente para mejor resultado.</p></details>
                    <details open class="dip-sec" data-clasico><summary><span class="dip-paso">4</span>Textos y campos automáticos</summary>
                        <div class="dip-grid2">
                            <div class="form-group"><label for="dipDocumento">Tipo de documento</label><select id="dipDocumento" class="form-control" data-campo="documento"><option>Diploma</option><option>Certificado</option><option>Constancia de participación</option><option>Reconocimiento</option></select></div>
                            <div class="form-group"><label for="dipHoras">Horas</label><input id="dipHoras" class="form-control" data-campo="horas" placeholder="Ej. 40"></div>
                            <div class="form-group is-full"><label for="dipIntro">Encabezado</label><input id="dipIntro" class="form-control" data-campo="intro"></div>
                            <div class="form-group is-full"><label for="dipCuerpo">Texto</label><textarea id="dipCuerpo" class="form-control" rows="3" data-campo="cuerpo"></textarea>
                                <div class="dip-campos" id="dipCamposCuerpo"></div></div>
                            <div class="form-group"><label for="dipLF">Lugar y fecha</label><input id="dipLF" class="form-control" data-campo="lugarFecha"></div>
                            <div class="form-group"><label for="dipEmision">Fecha de emisión</label><input type="date" id="dipEmision" class="form-control" data-campo="emision"></div>
                        </div>
                    </details>
                    <details open class="dip-sec" data-clasico><summary><span class="dip-paso">5</span>Firmas</summary>
                        <div class="form-group"><label for="dipNF">Cantidad de firmas</label><select id="dipNF" class="form-control" data-campo="nFirmas" data-numero="1"><option value="0">Sin firmas</option><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></div>
                        <div id="dipFirmas"></div><p class="pp-sub">Escanea la firma en papel blanco y súbela en PNG; se ajusta sola.</p>
                    </details>
                    <details open class="dip-sec" data-clasico><summary><span class="dip-paso">6</span>Código y QR de validación</summary>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" id="dipQR" data-campo="qr"> Incluir código y QR de validación</label>
                        <p class="pp-sub" style="margin-top:6px;">El QR va abajo a la derecha y el código abajo a la izquierda. También puedes escribir <b>{codigo}</b> en el texto. En el diseño propio (Canva) usa “+ QR de validación” y arrástralo a donde quieras.</p>
                    </details>
                    <?php if ($puedeGestionar): ?>
                        <div class="dip-guardar"><button type="button" class="btn btn-primary btn-sm" id="dipGuardar"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar plantilla</button><button type="button" class="btn btn-link btn-sm" id="dipRestablecer">Restablecer diseño</button></div>
                    <?php endif; ?>
                    <p class="pp-sub" id="dipEstado" style="margin-top:6px;"></p>
                </div>
            </div>

            <div class="dip-col-derecha">
                <section class="pp-card dip-card-previa">
                    <div class="pp-card-head"><div><h3>Vista previa</h3><span class="pp-sub">Con los datos reales del participante seleccionado</span></div>
                        <div class="dip-navegar"><button type="button" class="dip-nav-btn" id="dipPrev" aria-label="Anterior"><span class="glyphicon glyphicon-chevron-left"></span></button><span class="dip-nav-nombre" id="dipPrevNom">—</span><button type="button" class="dip-nav-btn" id="dipNext" aria-label="Siguiente"><span class="glyphicon glyphicon-chevron-right"></span></button></div></div>
                    <div class="pp-card-body"><div id="dipQrUrl"></div><div id="dipPreview" class="dip-preview-box"><div class="dip-previa-vacia"><span class="glyphicon glyphicon-certificate"></span><b>Elige un curso o evento para empezar</b><span>La vista previa usa los datos reales de cada participante.</span></div></div></div>
                </section>
                <section class="pp-card">
                    <div class="pp-card-head"><div><h3>Participantes</h3><span class="pp-sub">Marca a quiénes emitir; clic en un nombre para verlo en la vista previa</span></div>
                        <?php if ($puedeGestionar): ?><div class="dip-acciones-emitir"><button type="button" class="btn btn-default btn-sm" id="dipBtnMail" disabled><span class="glyphicon glyphicon-envelope"></span> Enviar por correo</button><button type="button" class="btn btn-primary btn-sm" id="dipBtnPdf" disabled><span class="glyphicon glyphicon-print"></span> Imprimir / guardar PDF</button></div><?php endif; ?></div>
                    <div class="pp-card-body dip-buscar-fila"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" class="form-control" id="dipBuscar" placeholder="Buscar participante..."></div>
                    <div class="pp-table-wrap dip-dest-list"><table class="cengi-org-table" style="min-width:520px;"><thead><tr><th style="width:30px;"><input type="checkbox" id="dipSelTodo" aria-label="Seleccionar todos"></th><th>Participante</th><th>Estado</th><th>Documento</th></tr></thead><tbody id="dipDest"><tr><td colspan="4" class="pp-empty">—</td></tr></tbody></table></div>
                </section>
            </div>
        </div>

    <?php elseif ($tab === 'emitidos'): ?>
        <div class="cengi-kpi-grid">
            <?php
            echo cengi_pp_kpi((int) $resumenDip['total'], 'Documentos emitidos', (int) $resumenDip['cursos'] . ' de cursos · ' . (int) $resumenDip['eventos'] . ' de eventos', '#1f5632', 'certificate');
            echo cengi_pp_kpi((int) $resumenDip['anio'], 'Emitidos este año', date('Y'), '#3E7A12', 'calendar', '#F4FAD9');
            echo cengi_pp_kpi((int) $resumenDip['enviados'], 'Enviados por correo', (int) $resumenDip['pdfs'] . ' con PDF cargado', '#8A6600', 'envelope', '#FFF6DA');
            echo cengi_pp_kpi($sinDiploma, 'Participantes sin diploma', count($cursosPendientes) . ' curso(s) finalizado(s) en el último año', $sinDiploma ? '#B34E00' : '#5B6459', 'time', $sinDiploma ? '#FFE9D9' : '#EDEFEA');
            ?>
        </div>

        <div class="dip-dos-col">
            <section class="pp-card dip-validar-card">
                <div class="pp-card-head"><div><h3>Validar un código</h3><span class="pp-sub">El mismo resultado que ve quien escanea el QR del documento</span></div></div>
                <div class="pp-card-body">
                    <div class="dip-validar-form"><span class="glyphicon glyphicon-qrcode" aria-hidden="true"></span><input type="text" class="form-control" id="dipValidar" placeholder="CEN-..." maxlength="60" autocomplete="off"><button type="button" class="btn btn-primary" id="dipBtnValidar">Validar</button></div>
                    <div id="dipValRes"></div>
                </div>
            </section>
            <section class="pp-card dip-pend-card" id="pendientes">
                <div class="pp-card-head"><div><h3>Cursos finalizados sin diplomas completos</h3><span class="pp-sub">Último año · participantes asignados sin documento (incluye a quienes no aprobaron)</span></div></div>
                <div class="pp-card-body">
                    <?php if (!$cursosPendientes): ?><p class="dip-todo-ok"><span class="glyphicon glyphicon-ok-circle"></span>Todos los cursos finalizados tienen sus diplomas.</p><?php endif; ?>
                    <?php foreach (array_slice($cursosPendientes, 0, 6) as $c): $pct = (int) round($c['con_diploma'] * 100 / max(1, $c['asignados'])); ?>
                        <div class="dip-pend">
                            <div><b><?php echo cengi_dip_html($c['nombre_cursos']); ?></b><span class="pp-sub">Finalizó el <?php echo date('d/m/Y', strtotime($c['fin'])); ?> · <?php echo (int) $c['con_diploma'] . ' de ' . (int) $c['asignados']; ?> con diploma</span>
                                <span class="dip-barra"><span style="width:<?php echo $pct; ?>%"></span></span></div>
                            <?php if ($puedeGestionar): ?><a class="btn btn-default btn-sm" href="diplomas.php?tab=disenar&amp;actividad=cu:<?php echo (int) $c['id']; ?>">Emitir</a><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($cursosPendientes) > 6): ?><p class="pp-sub">Y <?php echo count($cursosPendientes) - 6; ?> curso(s) más.</p><?php endif; ?>
                </div>
            </section>
        </div>

        <section class="pp-card">
            <form class="dip-filtros" method="get">
                <input type="hidden" name="tab" value="emitidos">
                <label class="dip-campo"><span>Tipo</span><select name="tipo" onchange="this.form.submit()"><option value="">Todos</option><option value="curso" <?php echo $emTipo === 'curso' ? 'selected' : ''; ?>>Cursos y diplomados</option><option value="evento" <?php echo $emTipo === 'evento' ? 'selected' : ''; ?>>Eventos</option></select></label>
                <label class="dip-campo"><span>Estado</span><select name="estado" onchange="this.form.submit()"><option value="">Todos</option><option value="emitido" <?php echo $emEstado === 'emitido' ? 'selected' : ''; ?>>Emitido (sin enviar)</option><option value="enviado" <?php echo $emEstado === 'enviado' ? 'selected' : ''; ?>>Enviado por correo</option><option value="pdf" <?php echo $emEstado === 'pdf' ? 'selected' : ''; ?>>PDF cargado</option></select></label>
                <label class="dip-campo"><span>Año</span><select name="anio" onchange="this.form.submit()"><option value="">Todos</option><?php foreach ($aniosEmitidos as $z): ?><option value="<?php echo $z; ?>" <?php echo $emAnio === $z ? 'selected' : ''; ?>><?php echo $z; ?></option><?php endforeach; ?></select></label>
                <label class="dip-campo is-buscar"><span>Buscar</span><span class="dip-buscar"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" name="q" value="<?php echo cengi_dip_html($emBusqueda); ?>" placeholder="Código, participante o actividad..."></span></label>
                <div class="dip-filtros-fin">
                    <span class="dip-total"><b><?php echo $emitidosTotal; ?></b> documento(s)</span>
                    <?php if ($emTipo !== '' || $emEstado !== '' || $emAnio || $emBusqueda !== ''): ?><a class="dip-limpiar" href="diplomas.php?tab=emitidos">Quitar filtros</a><?php endif; ?>
                    <a class="btn btn-default btn-sm" href="<?php echo cengi_dip_html($urlEmitidos(['exportar' => 'excel'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Excel</a>
                </div>
            </form>
            <div class="pp-table-wrap"><table class="cengi-org-table dip-tabla-emitidos" style="min-width:820px;">
                <thead><tr><th>Código</th><th>Participante</th><th>Actividad</th><th>Emisión</th><th>Estado</th><th class="is-actions"></th></tr></thead>
                <tbody>
                    <?php if (!$diplomasEmitidos): ?><tr><td colspan="6"><div class="dip-vacio"><span class="glyphicon glyphicon-certificate"></span><b><?php echo $resumenDip['total'] ? 'Ningún documento coincide con los filtros' : 'Aún no se han emitido diplomas'; ?></b><span><?php echo $resumenDip['total'] ? 'Prueba con otro año, estado o búsqueda.' : 'Empieza en “Diseñar y emitir”.'; ?></span></div></td></tr><?php endif; ?>
                    <?php foreach ($diplomasEmitidos as $d): ?>
                        <tr class="dip-fila is-<?php echo $d['tipo'] === 'evento' ? 'evento' : 'curso'; ?>">
                            <td><a class="dip-codigo" href="validar_diploma.php?c=<?php echo rawurlencode($d['codigo_unico']); ?>" target="_blank" rel="noopener"><?php echo cengi_dip_html($d['codigo_unico']); ?></a></td>
                            <td><strong><?php echo cengi_dip_html($d['nombre']); ?></strong></td>
                            <td><?php echo cengi_dip_html($d['actividad']); ?><span class="pp-sub"><span class="dip-tipo"><?php echo $d['tipo'] === 'evento' ? 'Evento' : 'Curso'; ?></span> <?php echo cengi_dip_html($d['documento'] ?: ($d['tipo'] === 'evento' ? 'Constancia' : 'Diploma')); ?></span></td>
                            <td class="pp-num"><?php echo $d['emitido_en'] ? date('d/m/Y', strtotime($d['emitido_en'])) : '—'; ?><span class="pp-sub"><?php echo $d['emitido_en'] ? date('H:i', strtotime($d['emitido_en'])) : ''; ?></span></td>
                            <td><?php echo cengi_dip_estado_html($d['pdf_path'], $d['enviado_en'], $d['codigo_unico']); ?></td>
                            <td class="is-actions"><div class="dip-fila-acciones">
                                <a class="cengi-action-btn is-view" href="validar_diploma.php?c=<?php echo rawurlencode($d['codigo_unico']); ?>" target="_blank" rel="noopener" data-tooltip="Ver documento" aria-label="Ver documento"><span class="glyphicon glyphicon-eye-open"></span></a>
                                <button type="button" class="cengi-action-btn is-copy-link" data-copiar="<?php echo cengi_dip_html(cengi_dip_url_validar($d['codigo_unico'])); ?>" data-tooltip="Copiar enlace de validación" aria-label="Copiar enlace de validación"><span class="glyphicon glyphicon-link"></span></button>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($emPaginas > 1): ?>
                <nav class="dip-paginas" aria-label="Páginas">
                    <a class="<?php echo $emPagina <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo cengi_dip_html($urlEmitidos(['p' => max(1, $emPagina - 1)])); ?>"><span class="glyphicon glyphicon-chevron-left"></span> Anterior</a>
                    <span>Página <b><?php echo $emPagina; ?></b> de <?php echo $emPaginas; ?></span>
                    <a class="<?php echo $emPagina >= $emPaginas ? 'is-disabled' : ''; ?>" href="<?php echo cengi_dip_html($urlEmitidos(['p' => min($emPaginas, $emPagina + 1)])); ?>">Siguiente <span class="glyphicon glyphicon-chevron-right"></span></a>
                </nav>
            <?php endif; ?>
        </section>

    <?php elseif ($tab === 'curso'): ?>
        <div class="dip-carga">
            <section class="pp-card">
                <div class="pp-card-head"><div><h3>Carga masiva de diplomas de curso / diplomado</h3><span class="pp-sub">Sube certificados ya elaborados fuera del sistema para varios participantes a la vez</span></div></div>
                <div class="pp-card-body">
                    <?php if ($puedeGestionar): ?>
                    <form method="POST" enctype="multipart/form-data" id="dipCursoForm">
                        <input type="hidden" name="accion" value="carga_masiva_curso">
                        <label class="dip-paso-label" for="dipCursoSel"><span class="dip-paso">1</span>Edición del curso <small>también sirve para diplomas históricos</small></label>
                        <select name="curso_id" class="form-control" id="dipCursoSel" required>
                            <option value="">Selecciona...</option>
                            <?php foreach ($cursosEdiciones as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo $cursoSeleccionadoId === (int) $c['id'] ? 'selected' : ''; ?>><?php echo cengi_dip_html($c['nombre_cursos'] . ' — edición ' . $c['anio']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($cursoSeleccionado && $cursoSeleccionado['fin'] && $cursoSeleccionado['fin'] < $hoy): ?>
                            <div class="dip-nota is-historico"><span class="glyphicon glyphicon-time"></span>Edición finalizada el <?php echo date('d/m/Y', strtotime($cursoSeleccionado['fin'])); ?>: estás cargando datos históricos.</div>
                        <?php endif; ?>
                        <label class="dip-paso-label" for="dipCursoFiles"><span class="dip-paso">2</span>PDF de los diplomas</label>
                        <div class="cengi-cert-dropzone dip-drop" data-file-drop="dipCursoFiles" role="button" tabindex="0">
                            <input type="file" id="dipCursoFiles" name="diplomas[]" accept="application/pdf" multiple hidden required>
                            <span class="glyphicon glyphicon-cloud-upload" aria-hidden="true"></span>
                            <strong>Arrastra varios PDF aquí</strong>
                            <span class="cengi-cert-drop-hint" data-file-label>Un archivo por participante · puedes seleccionar todos los del curso a la vez</span>
                            <span class="btn btn-default btn-sm">Seleccionar archivo(s)</span>
                        </div>
                        <div class="dip-nota"><span class="glyphicon glyphicon-info-sign"></span>Cada PDF se empareja por el CUI o el nombre en el nombre del archivo (ej. <span class="pp-mono">2451880732_AnaPerez.pdf</span>).</div>
                        <div class="dip-form-fin"><button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-upload"></span> Cargar diplomas</button></div>
                    </form>
                    <?php else: ?>
                        <p class="pp-sub">No tienes permiso para cargar diplomas.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="pp-card">
                <div class="pp-card-head"><div><h3>Participantes de la edición</h3><span class="pp-sub"><?php echo cengi_dip_html($cursoSeleccionado['nombre_cursos'] ?? 'Selecciona un curso'); ?></span></div>
                    <?php if ($cursoSeleccionado && $puedeGestionar): ?><a class="btn btn-default btn-sm" href="diplomas.php?tab=disenar&amp;actividad=cu:<?php echo (int) $cursoSeleccionado['id']; ?>"><span class="glyphicon glyphicon-pencil"></span> Diseñar y emitir</a><?php endif; ?></div>
                <?php if ($diplomasDeEsteCurso): $pctCurso = (int) round($conDocCurso * 100 / count($diplomasDeEsteCurso)); ?>
                    <div class="dip-avance"><span><b><?php echo $conDocCurso; ?></b> de <?php echo count($diplomasDeEsteCurso); ?> con diploma</span><span class="dip-barra"><span style="width:<?php echo $pctCurso; ?>%"></span></span><b><?php echo $pctCurso; ?>%</b></div>
                    <div class="pp-card-body dip-buscar-fila"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" class="form-control" data-filtrar="dipTablaCurso" placeholder="Buscar participante o CUI..."></div>
                <?php endif; ?>
                <div class="pp-table-wrap dip-dest-list"><table class="cengi-org-table" id="dipTablaCurso">
                    <thead><tr><th>Participante</th><th>Diploma</th><th class="is-actions"></th></tr></thead>
                    <tbody>
                        <?php if (!$diplomasDeEsteCurso): ?><tr><td colspan="3"><div class="dip-vacio"><span class="glyphicon glyphicon-education"></span><b><?php echo $cursoSeleccionado ? 'La edición no tiene participantes' : 'Selecciona una edición'; ?></b><span>Aquí verás quién tiene diploma y quién está pendiente.</span></div></td></tr><?php endif; ?>
                        <?php foreach ($diplomasDeEsteCurso as $d): ?>
                            <tr data-buscar="<?php echo cengi_dip_html(cengi_texto_normalizado($d['nombre_participantes'] . ' ' . $d['cui_participantes'])); ?>">
                                <td><strong><?php echo cengi_dip_html($d['nombre_participantes']); ?></strong><span class="pp-sub">CUI <?php echo cengi_dip_html($d['cui_participantes']); ?></span></td>
                                <td><?php echo cengi_dip_estado_html($d['pdf_path'], $d['enviado_en'], $d['codigo_unico']); ?><?php echo $d['codigo_unico'] ? '<span class="pp-sub pp-mono">' . cengi_dip_html($d['codigo_unico']) . '</span>' : ''; ?></td>
                                <td class="is-actions"><?php if ($d['pdf_path']): ?><a href="descargar_diploma.php?asignacion_id=<?php echo (int) $d['asignacion_id']; ?>" target="_blank" rel="noopener" class="cengi-action-btn is-view" data-tooltip="Ver PDF" aria-label="Ver PDF"><span class="glyphicon glyphicon-file"></span></a><?php elseif ($d['codigo_unico']): ?><a href="validar_diploma.php?c=<?php echo rawurlencode($d['codigo_unico']); ?>" target="_blank" rel="noopener" class="cengi-action-btn is-view" data-tooltip="Ver documento" aria-label="Ver documento"><span class="glyphicon glyphicon-eye-open"></span></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        </div>

    <?php else: ?>
        <div class="dip-carga">
            <section class="pp-card">
                <div class="pp-card-head"><div><h3>Constancias de evento</h3><span class="pp-sub">Para eventos técnicos sin evaluación académica · sube el PDF de cada participante</span></div></div>
                <div class="pp-card-body">
                    <form method="GET">
                        <input type="hidden" name="tab" value="evento">
                        <label class="dip-paso-label" for="dipEventoSel"><span class="dip-paso">1</span>Evento</label>
                        <select name="evento_id" id="dipEventoSel" class="form-control" onchange="this.form.submit()">
                            <option value="">Selecciona...</option>
                            <?php foreach ($eventosDisponibles as $e): ?>
                                <option value="<?php echo (int) $e['id']; ?>" <?php echo $eventoSeleccionadoId === (int) $e['id'] ? 'selected' : ''; ?>><?php echo cengi_dip_html($e['nombre'] . ' — ' . ($e['fecha'] ? date('d/m/Y', strtotime($e['fecha'])) : 'sin fecha')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <?php if ($puedeGestionar && $eventoSeleccionadoId > 0): ?>
                    <form method="POST" enctype="multipart/form-data" id="dipEventoForm">
                        <input type="hidden" name="accion" value="subir_diploma_evento">
                        <input type="hidden" name="evento_id" value="<?php echo $eventoSeleccionadoId; ?>">
                        <label class="dip-paso-label" for="dipEventoParticipante"><span class="dip-paso">2</span>Participante <small>o usa “Subir PDF” en la lista</small></label>
                        <select name="evento_participante_id" id="dipEventoParticipante" class="form-control" required>
                            <option value="">Selecciona...</option>
                            <?php foreach ($participantesEventoSel as $ep): ?>
                                <option value="<?php echo (int) $ep['id']; ?>"><?php echo cengi_dip_html($ep['nombre_invitado'] . ($ep['codigo_unico'] ? ' (ya tiene constancia)' : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="dip-paso-label" for="dipEventoFile"><span class="dip-paso">3</span>PDF de la constancia</label>
                        <div class="cengi-cert-dropzone dip-drop" data-file-drop="dipEventoFile" role="button" tabindex="0">
                            <input type="file" id="dipEventoFile" name="diploma" accept="application/pdf" hidden required>
                            <span class="glyphicon glyphicon-cloud-upload" aria-hidden="true"></span>
                            <strong>Arrastra el PDF aquí</strong>
                            <span class="cengi-cert-drop-hint" data-file-label>Un solo archivo por participante</span>
                            <span class="btn btn-default btn-sm">Seleccionar archivo</span>
                        </div>
                        <div class="dip-nota"><span class="glyphicon glyphicon-info-sign"></span>El PDF queda en el perfil del participante con un código único de validación. Si ya tenía constancia, se reemplaza.</div>
                        <div class="dip-form-fin"><button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-upload"></span> Cargar constancia</button></div>
                    </form>
                    <?php elseif ($eventoSeleccionadoId === 0): ?>
                        <p class="pp-sub" style="margin-top:12px;">Selecciona un evento para continuar.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="pp-card">
                <div class="pp-card-head"><div><h3>Participantes del evento</h3><span class="pp-sub"><?php echo $participantesEventoSel ? count($participantesEventoSel) . ' registrado(s)' : 'Selecciona un evento'; ?></span></div>
                    <?php if ($eventoSeleccionadoId > 0 && $puedeGestionar): ?><a class="btn btn-default btn-sm" href="diplomas.php?tab=disenar&amp;actividad=ev:<?php echo $eventoSeleccionadoId; ?>"><span class="glyphicon glyphicon-pencil"></span> Diseñar y emitir</a><?php endif; ?></div>
                <?php if ($participantesEventoSel): $pctEvento = (int) round($conDocEvento * 100 / count($participantesEventoSel)); ?>
                    <div class="dip-avance"><span><b><?php echo $conDocEvento; ?></b> de <?php echo count($participantesEventoSel); ?> con constancia</span><span class="dip-barra"><span style="width:<?php echo $pctEvento; ?>%"></span></span><b><?php echo $pctEvento; ?>%</b></div>
                    <div class="pp-card-body dip-buscar-fila"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" class="form-control" data-filtrar="dipTablaEvento" placeholder="Buscar participante..."></div>
                <?php endif; ?>
                <div class="pp-table-wrap dip-dest-list"><table class="cengi-org-table" id="dipTablaEvento">
                    <thead><tr><th>Participante</th><th>Constancia</th><th class="is-actions"></th></tr></thead>
                    <tbody>
                        <?php if (!$participantesEventoSel): ?><tr><td colspan="3"><div class="dip-vacio"><span class="glyphicon glyphicon-calendar"></span><b><?php echo $eventoSeleccionadoId ? 'El evento no tiene participantes' : 'Selecciona un evento'; ?></b><span>Aquí verás quién tiene constancia y quién está pendiente.</span></div></td></tr><?php endif; ?>
                        <?php foreach ($participantesEventoSel as $ep): ?>
                            <tr data-buscar="<?php echo cengi_dip_html(cengi_texto_normalizado($ep['nombre_invitado'])); ?>">
                                <td><strong><?php echo cengi_dip_html($ep['nombre_invitado']); ?></strong></td>
                                <td><?php echo cengi_dip_estado_html($ep['pdf_path'], $ep['enviado_en'], $ep['codigo_unico']); ?></td>
                                <td class="is-actions"><div class="dip-fila-acciones">
                                    <?php if ($ep['pdf_path']): ?><a href="descargar_diploma.php?evento_participante_id=<?php echo (int) $ep['id']; ?>" target="_blank" rel="noopener" class="cengi-action-btn is-view" data-tooltip="Ver PDF" aria-label="Ver PDF"><span class="glyphicon glyphicon-file"></span></a><?php endif; ?>
                                    <?php if ($puedeGestionar): ?><button type="button" class="btn btn-default btn-xs" data-subir-para="<?php echo (int) $ep['id']; ?>"><span class="glyphicon glyphicon-upload"></span> <?php echo $ep['pdf_path'] ? 'Reemplazar' : 'Subir PDF'; ?></button><?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        </div>
    <?php endif; ?>
</main>
<?php if ($tab === 'disenar'): ?>
<script src="js/qrcode-generator.js"></script>
<script src="js/diplomas.js?v=<?php echo $dipV('js/diplomas.js'); ?>"></script>
<script>window.CENGI_DIP_CONFIG = { puedeGestionar: <?php echo $puedeGestionar ? 'true' : 'false'; ?>, notaAprobacion: <?php echo CENGI_NOTA_APROBACION; ?> };</script>
<script src="js/diplomas_disenador.js?v=<?php echo $dipV('js/diplomas_disenador.js'); ?>"></script>
<?php endif; ?>
<script>
(function () {
    'use strict';
    function makeDropTarget(dropzone, input, onFiles) {
        if (!dropzone || !input) return;
        dropzone.addEventListener('click', function (event) {
            if (event.target !== input) input.click();
        });
        dropzone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });
        ['dragenter', 'dragover'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', function (event) {
            if (!event.dataTransfer.files.length) return;
            try { input.files = event.dataTransfer.files; } catch (error) { /* Navegador sin asignación programática. */ }
            onFiles(event.dataTransfer.files);
        });
        input.addEventListener('change', function () { onFiles(input.files); });
    }


    document.querySelectorAll('[data-file-drop]').forEach(function (dropzone) {
        var input = document.getElementById(dropzone.getAttribute('data-file-drop'));
        var label = dropzone.querySelector('[data-file-label]');
        makeDropTarget(dropzone, input, function (files) {
            if (!label || !files.length) return;
            label.textContent = files.length === 1 ? files[0].name : files.length + ' archivos seleccionados';
        });
    });

    var courseEdition = document.getElementById('dipCursoSel');
    if (courseEdition) {
        courseEdition.addEventListener('change', function () {
            if (courseEdition.value) {
                window.location.href = 'diplomas.php?tab=curso&curso_id=' + encodeURIComponent(courseEdition.value);
            }
        });
    }

    function normal(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, ''); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // Búsqueda instantánea en las listas de participantes (curso y evento).
    document.querySelectorAll('[data-filtrar]').forEach(function (input) {
        var tabla = document.getElementById(input.getAttribute('data-filtrar'));
        input.addEventListener('input', function () {
            var q = normal(input.value);
            tabla.querySelectorAll('tbody tr[data-buscar]').forEach(function (tr) { tr.hidden = q !== '' && tr.getAttribute('data-buscar').indexOf(q) === -1; });
        });
    });

    // Copiar el enlace de validación de un documento.
    document.querySelectorAll('[data-copiar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var url = b.getAttribute('data-copiar'), html = b.innerHTML;
            var listo = function () { b.innerHTML = '<span class="glyphicon glyphicon-ok"></span>'; setTimeout(function () { b.innerHTML = html; }, 1500); };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url).then(listo, function () { window.prompt('Copia el enlace:', url); }); }
            else { window.prompt('Copia el enlace:', url); }
        });
    });

    // "Subir PDF" en la lista del evento: elige al participante y lleva al formulario.
    document.querySelectorAll('[data-subir-para]').forEach(function (b) {
        b.addEventListener('click', function () {
            var sel = document.getElementById('dipEventoParticipante');
            if (!sel) return;
            sel.value = b.getAttribute('data-subir-para');
            var zona = document.querySelector('[data-file-drop="dipEventoFile"]');
            document.getElementById('dipEventoForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (zona) { zona.classList.add('is-resaltado'); setTimeout(function () { zona.classList.remove('is-resaltado'); }, 1600); zona.focus({ preventScroll: true }); }
        });
    });

    // Colores de la marca en el diseñador: aplican el color principal.
    document.querySelectorAll('[data-color-marca]').forEach(function (b) {
        b.addEventListener('click', function () {
            var color = document.getElementById('dipColor');
            if (!color) return;
            color.value = b.getAttribute('data-color-marca');
            color.dispatchEvent(new Event('input', { bubbles: true }));
            color.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    // Validar un código en la pestaña "Emitidos y validación" (el diseñador no se carga ahí).
    var campoValidar = document.getElementById('dipValidar');
    if (campoValidar && !window.CENGI_DIP_CONFIG) {
        var fecha = function (iso) { if (!iso) return ''; var d = new Date(String(iso).replace(' ', 'T')); return isNaN(d) ? iso : d.toLocaleDateString('es-GT'); };
        var validar = function () {
            var codigo = campoValidar.value.trim().toUpperCase(), res = document.getElementById('dipValRes');
            if (!codigo) { campoValidar.focus(); return; }
            res.innerHTML = '<p class="pp-sub">Validando...</p>';
            fetch('diplomas_api.php?validar=' + encodeURIComponent(codigo), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    res.innerHTML = j.valido
                        ? '<div class="dip-valido ok"><span class="glyphicon glyphicon-ok-circle"></span><div><b>Documento válido</b> · ' + esc(j.documento) + '<br>Emitido a <b>' + esc(j.nombre) + '</b> · ' + esc(j.actividad)
                          + '<br><span class="pp-sub">Emitido ' + fecha(j.emitido) + (j.enviado ? ' · enviado ' + fecha(j.enviado) : '') + ' · <a href="' + esc(j.url) + '" target="_blank" rel="noopener">Ver documento</a></span></div></div>'
                        : '<div class="dip-valido no"><span class="glyphicon glyphicon-remove-circle"></span><div>No existe un documento emitido con el código <b>' + esc(codigo) + '</b>.</div></div>';
                })
                .catch(function () { res.innerHTML = '<div class="dip-valido no">No fue posible validar. Intenta de nuevo.</div>'; });
        };
        document.getElementById('dipBtnValidar').addEventListener('click', validar);
        campoValidar.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); validar(); } });
    }

}());
</script>
</body>
</html>
