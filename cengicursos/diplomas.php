<?php
require_once "conexion.php";
require_once "menu.php";
require_once "diplomas_helpers.php";

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
if (!in_array($tab, ['disenar', 'curso', 'evento'], true)) {
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

// Datos para el diseñador: actividades y diplomas emitidos (cursos y eventos).
$actividadesDiploma = cengi_dip_actividades($db);
$actividadInicial = (string) ($_GET['actividad'] ?? '');
$diplomasEmitidos = $db->query("
    SELECT d.codigo_unico, d.tipo, d.emitido_en, d.enviado_en, d.pdf_path, d.documento, d.asignacion_id, d.evento_participante_id,
           COALESCE(NULLIF(d.nombre_participante, ''), p.nombre_participantes, ep.nombre_invitado) AS nombre,
           COALESCE(NULLIF(d.actividad, ''), c.nombre_cursos, e.nombre) AS actividad
    FROM diplomas d
    LEFT JOIN asignaciones a ON a.id = d.asignacion_id LEFT JOIN participantes p ON p.id = a.participantes_id LEFT JOIN cursos c ON c.id = a.cursos_id
    LEFT JOIN evento_participantes ep ON ep.id = d.evento_participante_id LEFT JOIN eventos e ON e.id = ep.evento_id
    ORDER BY d.emitido_en DESC
    LIMIT 300
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
        SELECT a.id AS asignacion_id, p.nombre_participantes, d.pdf_path, d.codigo_unico, d.emitido_en
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
<?php $dipV = static function ($f) { return (int) @filemtime(__DIR__ . '/' . $f); }; ?>
<link rel="stylesheet" href="css/planificacion.css?v=<?php echo $dipV('css/planificacion.css'); ?>">
<link rel="stylesheet" href="css/diplomas.css?v=<?php echo $dipV('css/diplomas.css'); ?>">
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
            <a href="diplomas.php?tab=disenar" class="cengi-tab<?php echo $tab === 'disenar' ? ' is-active' : ''; ?>">Diseñar y emitir</a>
            <a href="diplomas.php?tab=curso" class="cengi-tab<?php echo $tab === 'curso' ? ' is-active' : ''; ?>">Cargar PDF de curso (masiva)</a>
            <a href="diplomas.php?tab=evento" class="cengi-tab<?php echo $tab === 'evento' ? ' is-active' : ''; ?>">Cargar PDF de evento</a>
        </nav>
    </section>

    <?php if ($tab === 'disenar'): ?>
        <div class="cengi-feedback is-warning" style="margin-bottom:16px;"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div>
            <div><p>Elige el curso o evento, diseña una sola vez con logos, firmas escaneadas e imagen de fondo (o tu propio diseño de Canva), y el sistema <b>rellena los datos de cada participante</b>: nombre, actividad, fecha, lugar, horas, rol y código. Cada documento lleva un <b>código y QR de validación</b>; se imprime o guarda como PDF (uno por página) o se envía por correo con el enlace para descargarlo.</p></div></div>

        <div class="dip-layout" id="dip-app">
            <div class="dip-panel-ed">
                <details open class="dip-sec"><summary>1 · Actividad y participantes</summary>
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
                    <details open class="dip-sec"><summary>2 · Diseño</summary>
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
                    </details>
                    <details open class="dip-sec" id="dipSecCanva" hidden><summary>3 · Diseño de Canva y campos automáticos</summary>
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
                    <details open class="dip-sec" data-clasico><summary>3 · Logos</summary><div id="dipLogos" class="dip-logos-ed"></div><p class="pp-sub" style="margin-top:6px;">PNG con fondo transparente para mejor resultado.</p></details>
                    <details open class="dip-sec" data-clasico><summary>4 · Textos y campos automáticos</summary>
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
                    <details open class="dip-sec" data-clasico><summary>5 · Firmas</summary>
                        <div class="form-group"><label for="dipNF">Cantidad de firmas</label><select id="dipNF" class="form-control" data-campo="nFirmas" data-numero="1"><option value="0">Sin firmas</option><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></div>
                        <div id="dipFirmas"></div><p class="pp-sub">Escanea la firma en papel blanco y súbela en PNG; se ajusta sola.</p>
                    </details>
                    <details open class="dip-sec" data-clasico><summary>6 · Validación</summary>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" id="dipQR" data-campo="qr"> Incluir código y QR de validación</label>
                    </details>
                    <?php if ($puedeGestionar): ?>
                        <div style="display:flex;gap:8px;margin-top:12px;"><button type="button" class="btn btn-primary btn-sm" id="dipGuardar">Guardar plantilla</button><button type="button" class="btn btn-link btn-sm" id="dipRestablecer">Restablecer diseño</button></div>
                    <?php endif; ?>
                    <p class="pp-sub" id="dipEstado" style="margin-top:6px;"></p>
                </div>
            </div>

            <div>
                <section class="pp-card">
                    <div class="pp-card-head"><div><h3>Vista previa</h3><span class="pp-sub">Con los datos reales del participante seleccionado</span></div>
                        <div style="display:flex;gap:6px;align-items:center;"><button type="button" class="btn btn-default btn-sm" id="dipPrev" aria-label="Anterior">‹</button><span class="pp-sub" id="dipPrevNom" style="min-width:170px;text-align:center;">—</span><button type="button" class="btn btn-default btn-sm" id="dipNext" aria-label="Siguiente">›</button></div></div>
                    <div class="pp-card-body"><div id="dipPreview" class="dip-preview-box"><p class="pp-sub" style="padding:40px;text-align:center;">Elige un curso o evento para empezar.</p></div></div>
                </section>
                <section class="pp-card">
                    <div class="pp-card-head"><div><h3>Participantes</h3><span class="pp-sub">Marca a quiénes emitir; clic en un nombre para verlo en la vista previa</span></div>
                        <?php if ($puedeGestionar): ?><div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="btn btn-default btn-sm" id="dipBtnMail" disabled>Enviar por correo</button><button type="button" class="btn btn-primary btn-sm" id="dipBtnPdf" disabled>Imprimir / guardar PDF</button></div><?php endif; ?></div>
                    <div class="pp-card-body" style="padding-bottom:4px;"><input type="search" class="form-control" id="dipBuscar" placeholder="Buscar participante..."></div>
                    <div class="pp-table-wrap dip-dest-list"><table class="cengi-org-table" style="min-width:520px;"><thead><tr><th style="width:30px;"><input type="checkbox" id="dipSelTodo" aria-label="Seleccionar todos"></th><th>Participante</th><th>Estado</th><th>Documento</th></tr></thead><tbody id="dipDest"><tr><td colspan="4" class="pp-empty">—</td></tr></tbody></table></div>
                </section>
                <section class="pp-card">
                    <div class="pp-card-head"><div><h3>Diplomas emitidos y validación</h3></div>
                        <div style="display:flex;gap:6px;"><input type="text" class="form-control input-sm" id="dipValidar" placeholder="Validar código CEN-..." style="width:210px;"><button type="button" class="btn btn-default btn-sm" id="dipBtnValidar">Validar</button></div></div>
                    <div class="pp-card-body" style="padding-bottom:4px;"><div id="dipValRes"></div><input type="search" class="form-control" id="dipBuscarEm" placeholder="Buscar por código, nombre o actividad..."></div>
                    <div class="pp-table-wrap dip-dest-list"><table class="cengi-org-table" style="min-width:600px;"><thead><tr><th>Código</th><th>Participante</th><th>Actividad</th><th>Emisión</th><th>Estado</th></tr></thead>
                        <tbody id="dipEmitidos">
                            <?php if (!$diplomasEmitidos): ?><tr><td colspan="5" class="pp-empty">Aún no se han emitido diplomas.</td></tr><?php endif; ?>
                            <?php foreach ($diplomasEmitidos as $d): ?>
                                <tr data-buscar="<?php echo cengi_dip_html(cengi_texto_normalizado($d['codigo_unico'] . ' ' . $d['nombre'] . ' ' . $d['actividad'])); ?>">
                                    <td class="pp-mono"><a href="validar_diploma.php?c=<?php echo rawurlencode($d['codigo_unico']); ?>" target="_blank" rel="noopener"><?php echo cengi_dip_html($d['codigo_unico']); ?></a></td>
                                    <td><strong><?php echo cengi_dip_html($d['nombre']); ?></strong></td>
                                    <td><?php echo cengi_dip_html($d['actividad']); ?><span class="pp-sub"><?php echo cengi_dip_html($d['documento'] ?: ($d['tipo'] === 'evento' ? 'Constancia' : 'Diploma')); ?></span></td>
                                    <td class="pp-num"><?php echo $d['emitido_en'] ? date('d/m/Y H:i', strtotime($d['emitido_en'])) : '—'; ?></td>
                                    <td><?php echo $d['pdf_path'] ? '<span class="cengi-status-badge is-neutral"><i></i>PDF cargado</span>' : ($d['enviado_en'] ? '<span class="cengi-status-badge is-active"><i></i>Enviado</span>' : '<span class="cengi-status-badge is-upcoming"><i></i>Emitido</span>'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
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
                                            <a href="descargar_diploma.php?asignacion_id=<?php echo (int) $d['asignacion_id']; ?>" target="_blank" rel="noopener" class="btn btn-default btn-xs">Ver PDF</a>
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
                                            <a href="descargar_diploma.php?evento_participante_id=<?php echo (int) $ep['id']; ?>" target="_blank" rel="noopener" class="btn btn-default btn-xs">Ver PDF</a>
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

}());
</script>
</body>
</html>
