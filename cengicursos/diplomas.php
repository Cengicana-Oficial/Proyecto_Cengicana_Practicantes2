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

function cengi_dip_mover_archivo(array $archivo, $carpeta)
{
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return null;
    }
    $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $archivo['name']);
    $ruta = "../uploads/{$carpeta}/" . $nombreArchivo;
    return move_uploaded_file($archivo['tmp_name'], $ruta) ? $ruta : null;
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
        c.fin,
        YEAR(COALESCE(c.inicio, c.creado)) AS anio
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN cursos c ON c.id = a.cursos_id
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
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
<?php menu_render(); ?>
<div class="container">

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

    <div class="cengi-tabs">
        <a href="diplomas.php?tab=generar" class="cengi-tab<?php echo $tab === 'generar' ? ' is-active' : ''; ?>">Generar diploma (cursos academicos)</a>
        <a href="diplomas.php?tab=curso" class="cengi-tab<?php echo $tab === 'curso' ? ' is-active' : ''; ?>">Diplomas de curso (carga masiva)</a>
        <a href="diplomas.php?tab=evento" class="cengi-tab<?php echo $tab === 'evento' ? ' is-active' : ''; ?>">Diplomas de evento (carga manual)</a>
    </div>

    <?php if ($tab === 'generar'): ?>
        <div class="cengi-two-col">
            <div class="panel panel-success">
                <div class="panel-heading"><h3 class="panel-title">Generar diploma</h3><small>Registro trazable con codigo unico por participante y curso</small></div>
                <div class="panel-body">
                    <?php if ($puedeGestionar): ?>
                    <form method="POST">
                        <input type="hidden" name="accion" value="generar_diploma">
                        <div class="form-group">
                            <label class="control-label">Participante y curso (edicion)</label>
                            <select name="asignacion_id" class="form-control" required>
                                <option value="">Selecciona una asignacion...</option>
                                <?php foreach ($asignacionesDisponibles as $a): ?>
                                    <option value="<?php echo (int) $a['asignacion_id']; ?>">
                                        <?php echo cengi_dip_html($a['nombre_participantes'] . ' — ' . $a['nombre_cursos'] . ' (edicion ' . $a['anio'] . ')'); ?>
                                        <?php echo ($a['fin'] && $a['fin'] < date('Y-m-d')) ? ' [historico]' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-cloud-upload"></span> Generar diploma</button>
                    </form>
                    <?php else: ?>
                        <p class="text-muted">No tienes permiso para generar diplomas.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="panel panel-success">
                    <div class="panel-heading"><h3 class="panel-title">Vista previa</h3></div>
                    <div class="panel-body">
                        <div class="cengi-diploma-preview">
                            <div class="dp-border"></div>
                            <div class="dp-inner">
                                <div class="dp-eyebrow">CENGICAÑA otorga el presente diploma a</div>
                                <div class="dp-name">Nombre del participante</div>
                                <div class="dp-course">por haber aprobado satisfactoriamente el curso seleccionado.</div>
                            </div>
                            <div class="dp-code mono" style="font-family:'JetBrains Mono',monospace;">CEN-DIP-000000 · sin QR de validacion (pendiente)</div>
                        </div>
                    </div>
                </div>
                <div class="panel panel-success">
                    <div class="panel-heading"><h3 class="panel-title">Diplomas emitidos</h3></div>
                    <div class="panel-body" style="padding:0;">
                        <div class="cengi-table-wrap" style="border:0;border-radius:0;">
                        <table class="table table-striped">
                            <tbody>
                                <?php if (!$diplomasCurso): ?>
                                    <tr><td class="text-center">Sin diplomas emitidos todavia.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($diplomasCurso as $d): ?>
                                    <tr>
                                        <td><strong><?php echo cengi_dip_html($d['nombre_participantes']); ?></strong><br><small class="text-muted"><?php echo cengi_dip_html($d['nombre_cursos']); ?></small></td>
                                        <td class="mono" style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?php echo cengi_dip_html($d['codigo_unico']); ?></td>
                                        <td>
                                            <?php if ($d['pdf_path']): ?>
                                                <a href="<?php echo cengi_dip_html($d['pdf_path']); ?>" target="_blank" class="btn btn-info btn-xs">Ver PDF</a>
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
                </div>
            </div>
        </div>
    <?php elseif ($tab === 'curso'): ?>
        <div class="cengi-two-col">
            <div class="panel panel-success">
                <div class="panel-heading"><h3 class="panel-title">Carga masiva de diplomas de curso / diplomado</h3><small>Sube certificados ya elaborados para varios participantes de una edicion a la vez</small></div>
                <div class="panel-body">
                    <?php if ($puedeGestionar): ?>
                    <form method="POST" enctype="multipart/form-data" id="dipCursoForm">
                        <input type="hidden" name="accion" value="carga_masiva_curso">
                        <div class="form-group">
                            <label class="control-label">1. Selecciona la edicion del curso</label>
                            <select name="curso_id" class="form-control" onchange="document.getElementById('dipCursoVerBtn').href='diplomas.php?tab=curso&curso_id='+this.value;" id="dipCursoSel">
                                <option value="">Selecciona...</option>
                                <?php foreach ($cursosEdiciones as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $cursoSeleccionadoId === (int) $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo cengi_dip_html($c['nombre_cursos'] . ' — edicion ' . $c['anio']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($cursoSeleccionado && $cursoSeleccionado['fin'] && $cursoSeleccionado['fin'] < date('Y-m-d')): ?>
                            <div class="cengi-notice" style="margin-bottom:14px;">
                                <span class="glyphicon glyphicon-alert"></span>
                                <span>Estas cargando diplomas para una edicion ya finalizada (datos historicos).</span>
                            </div>
                        <?php endif; ?>
                        <label style="font-size:12px;font-weight:700;">2. Sube los PDF de los diplomas</label>
                        <div class="cengi-dropzone" style="margin-top:6px;">
                            <input type="file" name="diplomas[]" accept="application/pdf" multiple class="form-control">
                            <div style="font-size:11.5px;color:var(--cengi-muted);margin-top:8px;">Un archivo por participante. El nombre debe incluir el CUI o el nombre del participante (ej. <code>2451880732_AnaPerez.pdf</code>).</div>
                        </div>
                        <div style="margin-top:14px;">
                            <button type="submit" class="btn btn-primary">Cargar diplomas</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <p class="text-muted">No tienes permiso para cargar diplomas.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel panel-success">
                <div class="panel-heading"><h3 class="panel-title">Diplomas de esta edicion</h3><small><?php echo cengi_dip_html($cursoSeleccionado['nombre_cursos'] ?? 'Selecciona un curso'); ?></small></div>
                <div class="panel-body" style="padding:0;">
                    <div class="cengi-table-wrap" style="border:0;border-radius:0;">
                    <table class="table table-striped">
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
                                            <a href="<?php echo cengi_dip_html($d['pdf_path']); ?>" target="_blank" class="btn btn-info btn-xs">Ver PDF</a>
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
            </div>
        </div>
    <?php else: ?>
        <div class="cengi-two-col">
            <div class="panel panel-success">
                <div class="panel-heading"><h3 class="panel-title">Carga manual de diplomas / constancias de evento</h3><small>Para eventos tecnicos sin evaluacion academica</small></div>
                <div class="panel-body">
                    <form method="GET" style="margin-bottom:14px;">
                        <input type="hidden" name="tab" value="evento">
                        <div class="form-group">
                            <label class="control-label">1. Selecciona el evento</label>
                            <select name="evento_id" class="form-control" onchange="this.form.submit()">
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
                        <div class="form-group">
                            <label class="control-label">2. Selecciona el participante</label>
                            <select name="evento_participante_id" class="form-control" required>
                                <option value="">Selecciona...</option>
                                <?php foreach ($participantesEventoSel as $ep): ?>
                                    <option value="<?php echo (int) $ep['id']; ?>"><?php echo cengi_dip_html($ep['nombre_invitado']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label style="font-size:12px;font-weight:700;">3. Sube el PDF de la constancia</label>
                        <div class="cengi-dropzone" style="margin-top:6px;">
                            <input type="file" name="diploma" accept="application/pdf" class="form-control" required>
                        </div>
                        <div style="margin-top:14px;">
                            <button type="submit" class="btn btn-primary">Cargar constancia</button>
                        </div>
                    </form>
                    <?php elseif ($eventoSeleccionadoId === 0): ?>
                        <p class="text-muted">Selecciona un evento para continuar.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel panel-success">
                <div class="panel-heading"><h3 class="panel-title">Constancias del evento</h3></div>
                <div class="panel-body" style="padding:0;">
                    <div class="cengi-table-wrap" style="border:0;border-radius:0;">
                    <table class="table table-striped">
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
                                            <a href="<?php echo cengi_dip_html($ep['pdf_path']); ?>" target="_blank" class="btn btn-info btn-xs">Ver PDF</a>
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
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
