<?php
require_once "conexion.php";
require_once "menu.php";

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

$tab = $_GET['tab'] ?? 'generar';
if (!in_array($tab, ['generar', 'curso', 'evento'], true)) {
    $tab = 'generar';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if ($accion === 'generar_diploma') {
        $asignacionId = (int) ($_POST['asignacion_id'] ?? 0);
        if ($asignacionId > 0) {
            $stmtExiste = $db->prepare("SELECT id FROM diplomas WHERE tipo = 'curso' AND asignacion_id = ?");
            $stmtExiste->execute([$asignacionId]);
            if ($stmtExiste->fetchColumn()) {
                $mensaje = 'Ya existe un diploma registrado para esta asignacion.';
                $mensajeTipo = 'error';
            } else {
                $codigo = cengi_dip_codigo($db, 'CEN-DIP');
                $stmt = $db->prepare("
                    INSERT INTO diplomas (tipo, asignacion_id, codigo_unico, emitido_en, creado_por)
                    VALUES ('curso', ?, ?, NOW(), ?)
                ");
                $stmt->execute([$asignacionId, $codigo, cengi_usuario_actual_id()]);
                $mensaje = "Diploma generado con codigo {$codigo}. La generacion de PDF con QR de validacion todavia no esta implementada: por ahora solo queda el registro trazable en la base de datos.";
            }
        }
        $tab = 'generar';
    } elseif ($accion === 'carga_masiva_curso') {
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

// Datos para la pestana "Generar diploma"
$asignacionesDisponibles = $db->query("
    SELECT
        a.id AS asignacion_id,
        p.nombre_participantes,
        c.nombre_cursos,
        COALESCE(i.nombre, 'Sin instructor asignado') AS instructor_nombre,
        COALESCE((
            SELECT SUM(cm.horas)
            FROM curso_modulos cm
            WHERE cm.curso_id = c.id
        ), 0) AS horas_academicas,
        c.fin,
        YEAR(COALESCE(c.inicio, c.creado)) AS anio
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN cursos c ON c.id = a.cursos_id
    LEFT JOIN instructores i ON i.id = c.instructor_id
    WHERE a.estado_asignaciones = 1
    ORDER BY anio DESC, c.nombre_cursos, p.nombre_participantes
")->fetchAll(PDO::FETCH_ASSOC);

$diplomasCurso = $db->query("
    SELECT d.*, p.nombre_participantes, c.nombre_cursos
    FROM diplomas d
    INNER JOIN asignaciones a ON a.id = d.asignacion_id
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN cursos c ON c.id = a.cursos_id
    WHERE d.tipo = 'curso'
    ORDER BY d.emitido_en DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Datos para "Diplomas de curso (carga masiva)"
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
        SELECT p.nombre_participantes, d.pdf_path, d.codigo_unico, d.emitido_en
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.cursos_id = ?
        ORDER BY p.nombre_participantes
    ");
    $stmt->execute([$cursoSeleccionadoId]);
    $diplomasDeEsteCurso = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Datos para "Diplomas de evento"
$eventosDisponibles = $db->query("SELECT id, nombre, fecha FROM eventos ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
$eventoSeleccionadoId = (int) ($_POST['evento_id'] ?? $_GET['evento_id'] ?? 0);
$participantesEventoSel = [];
if ($eventoSeleccionadoId > 0) {
    $stmt = $db->prepare("
        SELECT ep.id, ep.nombre_invitado, d.pdf_path, d.codigo_unico
        FROM evento_participantes ep
        LEFT JOIN diplomas d ON d.tipo = 'evento' AND d.evento_participante_id = ep.id
        WHERE ep.evento_id = ?
        ORDER BY ep.nombre_invitado
    ");
    $stmt->execute([$eventoSeleccionadoId]);
    $participantesEventoSel = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas cengi-certification-page">
<?php menu_render(); ?>
<main class="container cengi-cert-page">

    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_dip_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($avisos): ?>
        <div class="alert alert-warning">
            <strong>Avisos de la carga masiva:</strong>
            <ul class="mb-0"><?php foreach ($avisos as $a) { echo '<li>' . cengi_dip_html($a) . '</li>'; } ?></ul>
        </div>
    <?php endif; ?>

    <section class="cengi-cert-section cengi-cert-tabs-section" aria-label="Opciones de certificación">
        <nav class="cengi-tabs">
            <a href="diplomas.php?tab=generar" class="cengi-tab<?php echo $tab === 'generar' ? ' is-active' : ''; ?>">Generar diploma (cursos académicos)</a>
            <a href="diplomas.php?tab=curso" class="cengi-tab<?php echo $tab === 'curso' ? ' is-active' : ''; ?>">Diplomas de curso (carga masiva)</a>
            <a href="diplomas.php?tab=evento" class="cengi-tab<?php echo $tab === 'evento' ? ' is-active' : ''; ?>">Diplomas de evento (carga manual)</a>
        </nav>
    </section>

    <?php if ($tab === 'generar'): ?>
        <div class="cengi-two-col">
            <section class="cengi-cert-section">
                <header class="cengi-cert-section-head">
                    <div><h3>Diseñador de plantilla</h3><div class="hint">Certificación · registro trazable con código único por participante y curso</div></div>
                </header>
                <div class="cengi-cert-section-body">
                    <?php if ($puedeGestionar): ?>
                    <form method="POST" id="diplomaDesignerForm">
                        <input type="hidden" name="accion" value="generar_diploma">
                        <div class="cengi-cert-form-grid">
                            <div class="cengi-cert-field is-full">
                                <label for="diplomaLogoInput">Logo institucional <span class="opt">(solo para la vista previa)</span></label>
                                <div class="cengi-cert-logo-drop" id="diplomaLogoDrop" role="button" tabindex="0" aria-controls="diplomaLogoInput">
                                    <input type="file" id="diplomaLogoInput" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden>
                                    <span class="glyphicon glyphicon-picture" aria-hidden="true"></span>
                                    <span id="diplomaLogoLabel">Arrastra el logo de CENGICAÑA o de tu ingenio aquí</span>
                                </div>
                            </div>
                            <div class="cengi-cert-field is-full">
                                <label for="diplomaAssignment">Participante y curso (edición)</label>
                                <select name="asignacion_id" id="diplomaAssignment" class="form-control" required>
                                <option value="">Selecciona una asignación...</option>
                                <?php foreach ($asignacionesDisponibles as $a): ?>
                                    <option value="<?php echo (int) $a['asignacion_id']; ?>"
                                            data-participant="<?php echo cengi_dip_html($a['nombre_participantes']); ?>"
                                            data-course="<?php echo cengi_dip_html($a['nombre_cursos']); ?>"
                                            data-instructor="<?php echo cengi_dip_html($a['instructor_nombre']); ?>"
                                            data-hours="<?php echo cengi_dip_html(rtrim(rtrim(number_format((float) $a['horas_academicas'], 2, '.', ''), '0'), '.')); ?>">
                                        <?php echo cengi_dip_html($a['nombre_participantes'] . ' — ' . $a['nombre_cursos'] . ' (edición ' . $a['anio'] . ')'); ?>
                                        <?php echo ($a['fin'] && $a['fin'] < date('Y-m-d')) ? ' [histórico]' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="cengi-cert-field">
                                <label for="diplomaCourse">Curso</label>
                                <input type="text" id="diplomaCourse" class="form-control" placeholder="Selecciona una asignación" readonly>
                            </div>
                            <div class="cengi-cert-field">
                                <label for="diplomaHours">Horas académicas</label>
                                <input type="number" id="diplomaHours" class="form-control" min="0" step="0.25" value="0">
                            </div>
                            <div class="cengi-cert-field">
                                <label for="diplomaInstructor">Instructor</label>
                                <input type="text" id="diplomaInstructor" class="form-control" value="Sin instructor asignado">
                            </div>
                            <div class="cengi-cert-field">
                                <label for="diplomaDate">Fecha de emisión</label>
                                <input type="date" id="diplomaDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="cengi-cert-field is-full">
                                <label for="diplomaCode">Código único <span class="opt">(generado automáticamente)</span></label>
                                <input type="text" id="diplomaCode" class="form-control mono" value="CEN-DIP-000000" disabled>
                            </div>
                        </div>
                        <div class="cengi-cert-actions">
                            <button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Generar diploma</button>
                            <button type="button" class="btn btn-default" id="printDiplomaPreview"><span class="glyphicon glyphicon-print" aria-hidden="true"></span> Imprimir vista previa</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <p class="text-muted">No tienes permiso para generar diplomas.</p>
                    <?php endif; ?>
                </div>
            </section>

            <div>
                <section class="cengi-cert-section cengi-preview-section">
                    <header class="cengi-cert-section-head"><h3>Vista previa</h3></header>
                    <div class="cengi-cert-section-body">
                        <div class="cengi-diploma-preview" id="diplomaPreview">
                            <div class="dp-border"></div>
                            <div class="dp-inner">
                                <div class="dp-eyebrow">CENGICAÑA otorga el presente diploma a</div>
                                <img class="dp-logo" id="diplomaPreviewLogo" alt="Logo institucional" hidden>
                                <div class="dp-name" id="diplomaPreviewName">Nombre del participante</div>
                                <div class="dp-course" id="diplomaPreviewCourse">por haber aprobado satisfactoriamente el curso seleccionado.</div>
                            </div>
                            <div class="dp-code mono">CEN-DIP-000000 · código de validación</div>
                        </div>
                    </div>
                </section>
                <section class="cengi-cert-section">
                    <header class="cengi-cert-section-head"><h3>Diplomas emitidos</h3></header>
                    <div class="cengi-cert-section-body is-table">
                        <div class="cengi-cert-table-wrap">
                        <table class="cengi-cert-table">
                            <tbody>
                                <?php if (!$diplomasCurso): ?>
                                    <tr><td colspan="3" class="text-center">Sin diplomas emitidos todavía.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($diplomasCurso as $d): ?>
                                    <tr>
                                        <td class="cengi-cert-person"><strong><?php echo cengi_dip_html($d['nombre_participantes']); ?></strong><small><?php echo cengi_dip_html($d['nombre_cursos']); ?></small></td>
                                        <td class="mono cengi-cert-code"><?php echo cengi_dip_html($d['codigo_unico']); ?></td>
                                        <td class="cengi-cert-action-cell">
                                            <?php if ($d['pdf_path']): ?>
                                                <a href="<?php echo cengi_dip_href($d['pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-default btn-xs">Ver PDF</a>
                                            <?php else: ?>
                                                <span class="text-muted">Sin PDF</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    <?php elseif ($tab === 'curso'): ?>
        <div class="cengi-two-col">
            <section class="cengi-cert-section">
                <header class="cengi-cert-section-head"><div><h3>Carga masiva de diplomas de curso / diplomado</h3><div class="hint">Sube certificados ya elaborados para varios participantes de una edición a la vez</div></div></header>
                <div class="cengi-cert-section-body">
                    <?php if ($puedeGestionar): ?>
                    <form method="POST" enctype="multipart/form-data" id="dipCursoForm">
                        <input type="hidden" name="accion" value="carga_masiva_curso">
                        <div class="cengi-cert-field is-full cengi-cert-field-spaced">
                            <label for="dipCursoSel">1. Selecciona la edición del curso <span class="opt">también sirve para cargar diplomas históricos</span></label>
                            <select name="curso_id" class="form-control" id="dipCursoSel" required>
                                <option value="">Selecciona...</option>
                                <?php foreach ($cursosEdiciones as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $cursoSeleccionadoId === (int) $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo cengi_dip_html($c['nombre_cursos'] . ' — edición ' . $c['anio']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($cursoSeleccionado && $cursoSeleccionado['fin'] && $cursoSeleccionado['fin'] < date('Y-m-d')): ?>
                            <div class="cengi-notice" style="margin-bottom:14px;">
                                <span class="glyphicon glyphicon-alert"></span>
                                <span>Estás cargando diplomas para una edición ya finalizada (datos históricos).</span>
                            </div>
                        <?php endif; ?>
                        <label class="cengi-cert-upload-label" for="dipCursoFiles">2. Sube los PDF de los diplomas</label>
                        <div class="cengi-cert-dropzone" data-file-drop="dipCursoFiles" role="button" tabindex="0">
                            <input type="file" id="dipCursoFiles" name="diplomas[]" accept="application/pdf" multiple hidden required>
                            <span class="glyphicon glyphicon-cloud-upload" aria-hidden="true"></span>
                            <strong>Arrastra varios PDF aquí</strong>
                            <span class="cengi-cert-drop-hint" data-file-label>Un archivo por participante · también puedes seleccionar todos los del curso a la vez</span>
                            <span class="btn btn-primary btn-sm">Seleccionar archivo(s)</span>
                        </div>
                        <div class="cengi-notice cengi-cert-upload-notice"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span><span>El sistema empareja cada PDF por CUI o nombre en el archivo (ej. <span class="mono">2451880732_AnaPerez.pdf</span>).</span></div>
                        <div class="cengi-cert-actions">
                            <button type="submit" class="btn btn-primary">Cargar diplomas</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <p class="text-muted">No tienes permiso para cargar diplomas.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="cengi-cert-section">
                <header class="cengi-cert-section-head"><div><h3>Diplomas de esta edición</h3><div class="hint"><?php echo cengi_dip_html($cursoSeleccionado['nombre_cursos'] ?? 'Selecciona un curso'); ?></div></div></header>
                <div class="cengi-cert-section-body is-table">
                    <div class="cengi-cert-table-wrap">
                    <table class="cengi-cert-table">
                        <thead><tr><th>Participante</th><th>Diploma</th></tr></thead>
                        <tbody>
                            <?php if (!$diplomasDeEsteCurso): ?>
                                <tr><td colspan="2" class="text-center">Selecciona un curso para ver sus diplomas.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($diplomasDeEsteCurso as $d): ?>
                                <tr>
                                    <td><?php echo cengi_dip_html($d['nombre_participantes']); ?></td>
                                    <td>
                                        <?php if ($d['pdf_path']): ?>
                                            <a href="<?php echo cengi_dip_href($d['pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-default btn-xs">Ver PDF</a>
                                        <?php else: ?>
                                            <span class="text-muted">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>
        </div>
    <?php else: ?>
        <div class="cengi-two-col">
            <section class="cengi-cert-section">
                <header class="cengi-cert-section-head"><div><h3>Carga manual de diplomas / constancias de evento</h3><div class="hint">Para eventos técnicos que no llevan evaluación académica · sube el PDF por participante</div></div></header>
                <div class="cengi-cert-section-body">
                    <form method="GET" class="cengi-cert-event-filter">
                        <input type="hidden" name="tab" value="evento">
                        <div class="cengi-cert-field is-full">
                            <label for="dipEventoSel">1. Selecciona el evento</label>
                            <select name="evento_id" id="dipEventoSel" class="form-control" onchange="this.form.submit()">
                                <option value="">Selecciona...</option>
                                <?php foreach ($eventosDisponibles as $e): ?>
                                    <option value="<?php echo (int) $e['id']; ?>" <?php echo $eventoSeleccionadoId === (int) $e['id'] ? 'selected' : ''; ?>>
                                        <?php echo cengi_dip_html($e['nombre'] . ' — ' . $e['fecha']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if ($puedeGestionar && $eventoSeleccionadoId > 0): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="accion" value="subir_diploma_evento">
                        <input type="hidden" name="evento_id" value="<?php echo $eventoSeleccionadoId; ?>">
                        <div class="cengi-cert-field is-full cengi-cert-field-spaced">
                            <label for="dipEventoParticipante">2. Selecciona el participante</label>
                            <select name="evento_participante_id" id="dipEventoParticipante" class="form-control" required>
                                <option value="">Selecciona...</option>
                                <?php foreach ($participantesEventoSel as $ep): ?>
                                    <option value="<?php echo (int) $ep['id']; ?>"><?php echo cengi_dip_html($ep['nombre_invitado']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="cengi-cert-upload-label" for="dipEventoFile">3. Sube el PDF de la constancia</label>
                        <div class="cengi-cert-dropzone" data-file-drop="dipEventoFile" role="button" tabindex="0">
                            <input type="file" id="dipEventoFile" name="diploma" accept="application/pdf" hidden required>
                            <span class="glyphicon glyphicon-cloud-upload" aria-hidden="true"></span>
                            <strong>Arrastra el PDF aquí</strong>
                            <span class="cengi-cert-drop-hint" data-file-label>Un solo archivo por participante</span>
                            <span class="btn btn-primary btn-sm">Seleccionar archivo</span>
                        </div>
                        <div class="cengi-notice cengi-cert-upload-notice"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span><span>El PDF quedará disponible en el perfil de este participante y conservará un código único de validación.</span></div>
                        <div class="cengi-cert-actions">
                            <button type="submit" class="btn btn-primary">Cargar constancia</button>
                        </div>
                    </form>
                    <?php elseif ($eventoSeleccionadoId === 0): ?>
                        <p class="text-muted">Selecciona un evento para continuar.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="cengi-cert-section">
                <header class="cengi-cert-section-head"><h3>Constancias del evento</h3></header>
                <div class="cengi-cert-section-body is-table">
                    <div class="cengi-cert-table-wrap">
                    <table class="cengi-cert-table">
                        <thead><tr><th>Participante</th><th>Constancia</th></tr></thead>
                        <tbody>
                            <?php if (!$participantesEventoSel): ?>
                                <tr><td colspan="2" class="text-center">Selecciona un evento para ver sus constancias.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($participantesEventoSel as $ep): ?>
                                <tr>
                                    <td><?php echo cengi_dip_html($ep['nombre_invitado']); ?></td>
                                    <td>
                                        <?php if ($ep['pdf_path']): ?>
                                            <a href="<?php echo cengi_dip_href($ep['pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-default btn-xs">Ver PDF</a>
                                        <?php else: ?>
                                            <span class="text-muted">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>
</main>
<script>
(function () {
    'use strict';

    var assignment = document.getElementById('diplomaAssignment');
    var courseInput = document.getElementById('diplomaCourse');
    var hoursInput = document.getElementById('diplomaHours');
    var instructorInput = document.getElementById('diplomaInstructor');
    var previewName = document.getElementById('diplomaPreviewName');
    var previewCourse = document.getElementById('diplomaPreviewCourse');

    function renderCourseDescription(course, hours) {
        if (!previewCourse) return;
        previewCourse.textContent = course
            ? 'por haber aprobado satisfactoriamente ' + course + (hours > 0 ? ', con una duración de ' + hours + ' horas académicas.' : '.')
            : 'por haber aprobado satisfactoriamente el curso seleccionado.';
    }

    function updateDiplomaPreview() {
        if (!assignment || !assignment.options.length) return;
        var option = assignment.options[assignment.selectedIndex];
        var participant = option && option.dataset.participant ? option.dataset.participant : 'Nombre del participante';
        var course = option && option.dataset.course ? option.dataset.course : '';
        var hours = option && option.dataset.hours ? parseFloat(option.dataset.hours) : 0;

        if (courseInput) courseInput.value = course;
        if (hoursInput) hoursInput.value = hours || '0';
        if (instructorInput) instructorInput.value = option && option.dataset.instructor ? option.dataset.instructor : 'Sin instructor asignado';
        if (previewName) previewName.textContent = participant;
        renderCourseDescription(course, hours);
    }

    if (assignment) assignment.addEventListener('change', updateDiplomaPreview);
    if (hoursInput) hoursInput.addEventListener('input', function () {
        renderCourseDescription(courseInput ? courseInput.value : '', parseFloat(hoursInput.value || '0'));
    });

    var logoDrop = document.getElementById('diplomaLogoDrop');
    var logoInput = document.getElementById('diplomaLogoInput');
    var logoPreview = document.getElementById('diplomaPreviewLogo');
    var logoLabel = document.getElementById('diplomaLogoLabel');

    function showLogo(file) {
        if (!file || !file.type.match(/^image\//) || !logoPreview) return;
        var reader = new FileReader();
        reader.onload = function (event) {
            logoPreview.src = event.target.result;
            logoPreview.hidden = false;
            if (logoLabel) logoLabel.textContent = file.name;
        };
        reader.readAsDataURL(file);
    }

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

    makeDropTarget(logoDrop, logoInput, function (files) { showLogo(files[0]); });

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

    var printButton = document.getElementById('printDiplomaPreview');
    if (printButton) printButton.addEventListener('click', function () { window.print(); });
}());
</script>
</body>
</html>
