<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_instructores();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_instructores();
$mensaje = '';
$mensajeTipo = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $especialidad = trim((string) ($_POST['especialidad'] ?? ''));
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));

    $cvPath = null;
    if (isset($_FILES['cv']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
        if (in_array($extension, ['pdf', 'doc', 'docx'], true)) {
            $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $_FILES['cv']['name']);
            $ruta = '../uploads/instructores/' . $nombreArchivo;
            if (move_uploaded_file($_FILES['cv']['tmp_name'], $ruta)) {
                $cvPath = $ruta;
            }
        }
    }

    try {
        if ($accion === 'crear' && $nombre !== '') {
            $stmt = $db->prepare("
                INSERT INTO instructores (nombre, especialidad, correo, telefono, cv_path, estado)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$nombre, $especialidad, $correo, $telefono, $cvPath]);
            $mensaje = 'Instructor registrado correctamente.';
        } elseif ($accion === 'actualizar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0 && $nombre !== '') {
                if ($cvPath !== null) {
                    $stmt = $db->prepare("
                        UPDATE instructores SET nombre = ?, especialidad = ?, correo = ?, telefono = ?, cv_path = ? WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $especialidad, $correo, $telefono, $cvPath, $id]);
                } else {
                    $stmt = $db->prepare("
                        UPDATE instructores SET nombre = ?, especialidad = ?, correo = ?, telefono = ? WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $especialidad, $correo, $telefono, $id]);
                }
                $mensaje = 'Instructor actualizado correctamente.';
            }
        } elseif ($accion === 'toggle_estado') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE instructores SET estado = 1 - estado WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = 'Estado actualizado.';
            }
        }
    } catch (PDOException $e) {
        $mensaje = 'No fue posible guardar: ' . $e->getMessage();
        $mensajeTipo = 'error';
    }
}

$stmt = $db->prepare("
    SELECT
        i.*,
        (SELECT COUNT(*) FROM cursos c WHERE c.instructor_id = i.id) AS total_cursos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM cursos c
            INNER JOIN asignaciones a ON a.cursos_id = c.id
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE c.instructor_id = i.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        ) AS evaluacion_promedio
    FROM instructores i
    ORDER BY total_cursos DESC, i.nombre
");
$stmt->execute();
$instructores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ediciones = $db->query("
    SELECT
        ca.descripcion_categorias_cursos AS categoria,
        c.id AS curso_id,
        c.nombre_cursos,
        c.tipo AS modalidad,
        c.cupo,
        c.inicio,
        c.fin,
        YEAR(COALESCE(c.inicio, c.creado)) AS anio,
        ing.nombre_ingenios AS ingenio,
        i.id AS instructor_id,
        i.nombre AS instructor_nombre,
        i.especialidad AS instructor_especialidad,
        i.cv_path AS instructor_cv,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id) AS total_inscritos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM asignaciones a
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE a.cursos_id = c.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        ) AS evaluacion_promedio
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    INNER JOIN ingenios ing ON ing.id = c.ingenio_id
    LEFT JOIN instructores i ON i.id = c.instructor_id
    ORDER BY c.nombre_cursos, c.inicio DESC
")->fetchAll(PDO::FETCH_ASSOC);

$anios = [];
$nombresCursos = [];
foreach ($ediciones as $fila) {
    if (!empty($fila['anio'])) {
        $anios[(string) $fila['anio']] = true;
    }
    $nombresCursos[(string) $fila['nombre_cursos']] = true;
}
$anios = array_keys($anios);
rsort($anios, SORT_NUMERIC);
$nombresCursos = array_keys($nombresCursos);
sort($nombresCursos, SORT_NATURAL | SORT_FLAG_CASE);

function cengi_inst_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<?php include('head.php'); ?>
<style>
.cengi-instructors-page {
    --inst-ink: #1E2A1A;
    --inst-muted: #4B5A45;
    --inst-line: #E4E7E1;
    --inst-paper: #F6F7F3;
    --inst-green: #73BC25;
    --inst-green-dark: #3E7A12;
    color: var(--inst-ink);
}
.cengi-instructors-page * { box-sizing: border-box; }
.cengi-instructors-page .inst-section {
    margin-bottom: 18px;
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--inst-line);
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(30,42,26,.06), 0 4px 16px rgba(30,42,26,.05);
}
.cengi-instructors-page .inst-section-body { padding: 18px; }
.cengi-instructors-page .inst-filter-row,
.cengi-instructors-page .inst-filter-group,
.cengi-instructors-page .inst-action-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.cengi-instructors-page .inst-filter-row { justify-content: space-between; }
.cengi-instructors-page .inst-search {
    min-width: 210px;
    height: 35px;
    padding: 8px 12px 8px 34px;
    border: 1px solid var(--inst-line);
    border-radius: 8px;
    background: #fff url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%23A3AAA0%22 stroke-width=%222%22%3E%3Ccircle cx=%2211%22 cy=%2211%22 r=%228%22/%3E%3Cpath d=%22M21 21l-4.35-4.35%22/%3E%3C/svg%3E') no-repeat 10px center;
    color: var(--inst-ink);
    font: 12.5px 'Inter', sans-serif;
    outline: none;
}
.cengi-instructors-page .inst-search:focus,
.cengi-instructors-page .inst-filter:focus,
.cengi-inst-modal .inst-field input:focus,
.cengi-inst-modal .inst-field select:focus {
    border-color: var(--cengi-primary);
    box-shadow: 0 0 0 2px rgba(163,211,0,.3);
}
.cengi-instructors-page .inst-filter {
    height: 35px;
    padding: 7px 10px;
    border: 1px solid var(--inst-line);
    border-radius: 8px;
    background: #fff;
    color: var(--inst-ink);
    font: 12.5px 'Inter', sans-serif;
    outline: none;
}
.cengi-instructors-page .inst-btn,
.cengi-inst-modal .inst-btn {
    min-height: 34px;
    padding: 7px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 0;
    border-radius: 8px;
    font: 600 12px 'Inter', sans-serif;
    line-height: 1.2;
    text-decoration: none;
    cursor: pointer;
    transition: filter .12s ease, background-color .12s ease, transform .05s ease;
}
.cengi-instructors-page .inst-btn:active,
.cengi-inst-modal .inst-btn:active { transform: translateY(1px); }
.cengi-instructors-page .inst-btn svg,
.cengi-inst-modal .inst-btn svg { width: 14px; height: 14px; }
.cengi-instructors-page .inst-btn-primary,
.cengi-inst-modal .inst-btn-primary { background: var(--inst-green, #73BC25); color: #fff; }
.cengi-instructors-page .inst-btn-yellow { background: #FFCC00; color: #4A3900; }
.cengi-instructors-page .inst-btn-outline,
.cengi-inst-modal .inst-btn-outline { border: 1px solid var(--inst-line, #E4E7E1); background: #fff; color: var(--inst-ink, #1E2A1A); }
.cengi-instructors-page .inst-btn-ghost { background: transparent; color: var(--inst-muted); }
.cengi-instructors-page .inst-btn:hover,
.cengi-inst-modal .inst-btn:hover { filter: brightness(1.04); text-decoration: none; }
.cengi-instructors-page .inst-btn-sm { min-height: 29px; padding: 6px 10px; font-size: 11.5px; }
.cengi-instructors-page .inst-switch-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.cengi-instructors-page .inst-tabs { display: flex; gap: 4px; }
.cengi-instructors-page .inst-tab {
    margin-right: 18px;
    padding: 11px 4px;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--inst-muted);
    font: 600 12.5px 'Inter', sans-serif;
    cursor: pointer;
}
.cengi-instructors-page .inst-tab.is-active { color: var(--inst-green-dark); border-bottom-color: var(--inst-green); }
.cengi-instructors-page .inst-view-toggle {
    display: flex;
    gap: 2px;
    padding: 2px;
    border: 1px solid var(--inst-line);
    border-radius: 8px;
    background: var(--inst-paper);
}
.cengi-instructors-page .inst-view-btn { min-height: 28px; padding: 5px 8px; border-radius: 6px; }
.cengi-instructors-page .inst-view-btn.is-active { background: var(--inst-ink); color: #fff; }
.cengi-instructors-page .inst-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.cengi-instructors-page .inst-card { margin: 0; }
.cengi-instructors-page .inst-card .inst-section-body { padding: 18px; }
.cengi-instructors-page .inst-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.cengi-instructors-page .inst-name-cell { display: flex; align-items: center; gap: 9px; min-width: 0; }
.cengi-instructors-page .inst-avatar {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #CED2D5;
    color: #4B5A45;
    font: 700 12px 'Space Grotesk', sans-serif;
}
.cengi-instructors-page .inst-name { overflow: hidden; color: var(--inst-ink); font-size: 13.5px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.cengi-instructors-page .inst-sub { margin-top: 1px; color: var(--inst-muted); font-size: 11px; line-height: 1.35; }
.cengi-instructors-page .inst-card-tools { display: flex; gap: 4px; }
.cengi-instructors-page .inst-icon-btn,
.cengi-inst-modal .inst-icon-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--inst-line, #E4E7E1);
    border-radius: 7px;
    background: #fff;
    color: var(--inst-muted, #4B5A45);
    cursor: pointer;
}
.cengi-instructors-page .inst-icon-btn:hover,
.cengi-inst-modal .inst-icon-btn:hover { background: #F2F4EF; color: var(--inst-ink, #1E2A1A); }
.cengi-instructors-page .inst-stats { display: flex; gap: 20px; margin-top: 14px; }
.cengi-instructors-page .inst-stat-value,
.cengi-inst-modal .inst-stat-value { font: 700 17px 'Space Grotesk', sans-serif; }
.cengi-instructors-page .inst-stat-value.is-green,
.cengi-inst-modal .inst-stat-value.is-green { color: var(--inst-green-dark, #3E7A12); }
.cengi-instructors-page .inst-stat-label,
.cengi-inst-modal .inst-stat-label { margin-top: 2px; color: var(--inst-muted, #4B5A45); font-size: 11.5px; }
.cengi-instructors-page .inst-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
.cengi-instructors-page .inst-chip,
.cengi-inst-modal .inst-chip {
    padding: 4px 9px;
    display: inline-flex;
    align-items: center;
    border: 0;
    border-radius: 100px;
    background: #EEF1EC;
    color: var(--inst-muted, #4B5A45);
    font-size: 11px;
    font-weight: 600;
    line-height: 1.25;
}
.cengi-instructors-page button.inst-chip { cursor: pointer; }
.cengi-instructors-page .inst-chip.is-cv,
.cengi-inst-modal .inst-chip.is-cv { background: #EAF6DD; color: #3E7A12; }
.cengi-instructors-page .inst-chip.is-missing,
.cengi-inst-modal .inst-chip.is-missing { background: #FFF6DA; color: #8A6600; }
.cengi-instructors-page .inst-card-actions { display: flex; gap: 8px; margin-top: 14px; }
.cengi-instructors-page .inst-card-actions .inst-btn:first-child { flex: 1; }
.cengi-instructors-page .inst-cv-preview {
    display: none;
    margin: 14px -18px -18px;
    padding: 14px 18px;
    border-top: 1px dashed var(--inst-line);
    background: #FAFBF7;
}
.cengi-instructors-page .inst-cv-preview.is-open { display: block; }
.cengi-instructors-page .inst-cv-row { display: flex; gap: 12px; align-items: flex-start; }
.cengi-instructors-page .inst-cv-doc {
    width: 60px;
    height: 78px;
    flex: 0 0 auto;
    position: relative;
    border: 1px solid var(--inst-line);
    border-radius: 6px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(30,42,26,.06), 0 4px 16px rgba(30,42,26,.05);
}
.cengi-instructors-page .inst-cv-doc::before { content: ""; position: absolute; top: 10px; left: 9px; right: 9px; height: 5px; border-radius: 2px; background: #E4E7E1; }
.cengi-instructors-page .inst-cv-doc::after { content: "PDF"; position: absolute; bottom: 7px; left: 9px; color: #B23223; font-size: 8px; font-weight: 700; }
.cengi-instructors-page .inst-list-wrap { overflow: hidden; }
.cengi-instructors-page .inst-list-row { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-bottom: 1px solid var(--inst-line); }
.cengi-instructors-page .inst-list-row:last-child { border-bottom: 0; }
.cengi-instructors-page .inst-list-row:hover { background: #FAFBF7; }
.cengi-instructors-page .inst-list-main { flex: 1; min-width: 190px; }
.cengi-instructors-page .inst-list-stat { min-width: 90px; text-align: center; }
.cengi-instructors-page .inst-empty { padding: 34px; text-align: center; color: var(--inst-muted); font-size: 12.5px; }
.cengi-instructors-page .inst-report-head { padding: 16px 18px; border-bottom: 1px solid var(--inst-line); }
.cengi-instructors-page .inst-report-head h3 { margin: 0; font: 600 14.5px 'Space Grotesk', sans-serif; }
.cengi-instructors-page .inst-hint { margin-top: 2px; color: var(--inst-muted); font-size: 11.5px; }
.cengi-instructors-page .inst-report-group { border-bottom: 1px solid var(--inst-line); }
.cengi-instructors-page .inst-report-group:last-child { border-bottom: 0; }
.cengi-instructors-page .inst-report-group-head { display: flex; justify-content: space-between; gap: 12px; padding: 13px 18px; background: #FAFBF7; font-size: 12.5px; font-weight: 700; }
.cengi-instructors-page .inst-report-group-head small { color: var(--inst-muted); font-size: 11px; font-weight: 500; }
.cengi-instructors-page .inst-table-scroll { overflow-x: auto; }
.cengi-instructors-page .inst-table,
.cengi-inst-modal .inst-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.cengi-instructors-page .inst-table th,
.cengi-inst-modal .inst-table th { padding: 10px 12px; border-bottom: 1px solid var(--inst-line, #E4E7E1); background: #FAFBF8; color: var(--inst-muted, #4B5A45); font-size: 10.5px; font-weight: 600; letter-spacing: .05em; text-align: left; text-transform: uppercase; white-space: nowrap; }
.cengi-instructors-page .inst-table td,
.cengi-inst-modal .inst-table td { padding: 11px 12px; border-bottom: 1px solid var(--inst-line, #E4E7E1); vertical-align: middle; }
.cengi-instructors-page .inst-table tr:last-child td,
.cengi-inst-modal .inst-table tr:last-child td { border-bottom: 0; }
.cengi-instructors-page .inst-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 100px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.cengi-instructors-page .inst-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.cengi-instructors-page .inst-badge.is-active { background: #EAF6DD; color: #3E7A12; }
.cengi-instructors-page .inst-badge.is-finished { background: #EDEFEA; color: #5B6459; }
.cengi-instructors-page .inst-badge.is-planned { background: #FFF6DA; color: #8A6600; }
@media (max-width: 1120px) {
    .cengi-instructors-page .inst-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cengi-instructors-page .inst-filter-row { align-items: flex-start; }
}
@media (max-width: 760px) {
    .cengi-instructors-page .inst-grid { grid-template-columns: 1fr; }
    .cengi-instructors-page .inst-filter-group, .cengi-instructors-page .inst-action-group { width: 100%; }
    .cengi-instructors-page .inst-search, .cengi-instructors-page .inst-filter { width: 100%; }
    .cengi-instructors-page .inst-action-group .inst-btn { flex: 1; }
    .cengi-instructors-page .inst-tabs { overflow-x: auto; width: 100%; }
    .cengi-instructors-page .inst-tab { flex: 0 0 auto; }
    .cengi-instructors-page .inst-list-row { align-items: flex-start; flex-wrap: wrap; }
    .cengi-instructors-page .inst-list-stat { min-width: 76px; text-align: left; }
}
</style>
<body class="cengi-canvas">
<?php menu_render(); ?>
<main class="container cengi-instructors-page">
    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_inst_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <section class="inst-section">
        <div class="inst-section-body">
            <div class="inst-filter-row">
                <div class="inst-filter-group">
                    <input class="inst-search" id="instructoresBuscar" type="search" placeholder="Buscar instructor o especialidad..." autocomplete="off">
                    <select class="inst-filter" id="instructoresAnio" aria-label="Filtrar por año">
                        <option value="todos">Todos los años</option>
                        <?php foreach ($anios as $anio): ?><option value="<?php echo cengi_inst_html($anio); ?>"><?php echo cengi_inst_html($anio); ?></option><?php endforeach; ?>
                    </select>
                    <select class="inst-filter" id="instructoresCurso" aria-label="Filtrar por curso">
                        <option value="todos">Todos los cursos / diplomados</option>
                        <?php foreach ($nombresCursos as $nombreCurso): ?><option value="<?php echo cengi_inst_html($nombreCurso); ?>"><?php echo cengi_inst_html($nombreCurso); ?></option><?php endforeach; ?>
                    </select>
                    <select class="inst-filter" id="instructoresOrden" aria-label="Ordenar instructores">
                        <option value="cursos_desc">Ordenar por: más cursos impartidos</option>
                        <option value="cursos_asc">Ordenar por: menos cursos impartidos</option>
                        <option value="eval_desc">Ordenar por: mejor evaluación</option>
                        <option value="nombre">Ordenar por: nombre (A–Z)</option>
                    </select>
                </div>
                <?php if ($puedeGestionar): ?>
                    <div class="inst-action-group">
                        <button class="inst-btn inst-btn-yellow inst-btn-sm" type="button" data-inst-open="modalCargaEvaluaciones">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                            Carga masiva de evaluaciones
                        </button>
                        <button class="inst-btn inst-btn-primary" type="button" id="nuevoInstructorBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                            Nuevo instructor
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="inst-switch-row">
        <div class="inst-tabs" role="tablist">
            <button class="inst-tab is-active" type="button" data-inst-tab="directorio">Directorio de instructores</button>
            <button class="inst-tab" type="button" data-inst-tab="informe">Informe general por curso y diplomado</button>
        </div>
        <div class="inst-view-toggle" id="instViewToggle">
            <button class="inst-btn inst-btn-ghost inst-btn-sm inst-view-btn is-active" type="button" data-inst-view="grid">▦ Tarjetas</button>
            <button class="inst-btn inst-btn-ghost inst-btn-sm inst-view-btn" type="button" data-inst-view="lista">☰ Lista</button>
        </div>
    </div>

    <section data-inst-panel="directorio">
        <div class="inst-grid" id="gridInstructores"></div>
    </section>
    <section data-inst-panel="informe" hidden>
        <div class="inst-section">
            <div class="inst-report-head">
                <h3>Informe de instructores por curso y diplomado</h3>
                <div class="inst-hint" id="informeSub">Agrupado por curso/diplomado · todos los años</div>
            </div>
            <div id="informeInstructores"></div>
        </div>
    </section>
</main>

<style>
.cengi-inst-modal {
    --inst-ink: #1E2A1A;
    --inst-muted: #4B5A45;
    --inst-line: #E4E7E1;
    --inst-green: #73BC25;
    --inst-green-dark: #3E7A12;
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(20,26,15,.45);
    color: var(--inst-ink);
    font-family: 'Inter', sans-serif;
}
.cengi-inst-modal.is-open { display: flex; }
.cengi-inst-modal .inst-modal-card {
    width: 480px;
    max-width: 92vw;
    max-height: 90vh;
    overflow-y: auto;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.cengi-inst-modal .inst-modal-card.is-wide { width: 700px; }
.cengi-inst-modal .inst-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 18px 22px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-modal-head h3 { margin: 0; font: 600 15px 'Space Grotesk', sans-serif; }
.cengi-inst-modal .inst-modal-sub { margin-top: 2px; color: var(--inst-muted); font-size: 11.5px; }
.cengi-inst-modal .inst-modal-body { padding: 22px; }
.cengi-inst-modal .inst-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px; border-top: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
.cengi-inst-modal .inst-field { display: flex; flex-direction: column; gap: 5px; }
.cengi-inst-modal .inst-field.is-full { grid-column: 1 / -1; }
.cengi-inst-modal .inst-field label { margin: 0; color: var(--inst-ink); font-size: 12px; font-weight: 600; }
.cengi-inst-modal .inst-optional { color: var(--inst-muted); font-size: 11px; font-weight: 400; }
.cengi-inst-modal .inst-field input,
.cengi-inst-modal .inst-field select { width: 100%; height: 37px; padding: 8px 11px; border: 1px solid var(--inst-line); border-radius: 8px; background: #fff; color: var(--inst-ink); font: 12.5px 'Inter', sans-serif; outline: none; }
.cengi-inst-modal .inst-dropzone { padding: 20px 16px; border: 1.5px dashed var(--inst-line); border-radius: 9px; color: var(--inst-muted); font-size: 11.5px; text-align: center; cursor: pointer; }
.cengi-inst-modal .inst-dropzone:hover { border-color: var(--inst-green); background: #FAFDF6; }
.cengi-inst-modal .inst-dropzone svg { width: 20px; height: 20px; margin-bottom: 5px; }
.cengi-inst-modal .inst-notice { display: flex; gap: 8px; margin-bottom: 16px; padding: 11px 14px; border: 1px solid #F4E5AC; border-radius: 9px; background: #FFF6DA; color: #7A5D00; font-size: 11.5px; line-height: 1.5; }
.cengi-inst-modal .inst-notice svg { width: 15px; height: 15px; flex: 0 0 auto; margin-top: 1px; }
.cengi-inst-modal .inst-profile-head { display: flex; align-items: center; gap: 14px; padding: 20px 22px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-profile-avatar { width: 52px; height: 52px; flex: 0 0 52px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--inst-green); color: #fff; font: 700 17px 'Space Grotesk', sans-serif; }
.cengi-inst-modal .inst-profile-copy { flex: 1; min-width: 0; }
.cengi-inst-modal .inst-profile-name { font: 700 16px 'Space Grotesk', sans-serif; }
.cengi-inst-modal .inst-profile-sub { margin-top: 2px; color: var(--inst-muted); font-size: 12px; }
.cengi-inst-modal .inst-profile-stats { display: flex; gap: 22px; padding: 16px 22px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-detail-tabs { display: flex; gap: 4px; padding: 0 18px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-detail-tab { margin-right: 18px; padding: 11px 4px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--inst-muted); font-size: 12.5px; font-weight: 600; cursor: pointer; }
.cengi-inst-modal .inst-detail-tab.is-active { border-bottom-color: var(--inst-green); color: var(--inst-green-dark); }
.cengi-inst-modal .inst-detail-panel { padding: 18px 22px; }
.cengi-inst-modal .inst-detail-empty { padding: 28px 10px; color: var(--inst-muted); font-size: 12.5px; text-align: center; }
.cengi-inst-modal .inst-eval-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.cengi-inst-modal .inst-eval-box { padding: 14px; border: 1px solid var(--inst-line); border-radius: 9px; }
.cengi-inst-modal .inst-progress { width: 100%; height: 7px; margin-top: 8px; overflow: hidden; border-radius: 100px; background: #EDEFEA; }
.cengi-inst-modal .inst-progress span { display: block; height: 100%; border-radius: inherit; background: #FFCC00; }
body.inst-modal-open { overflow: hidden; }
@media (max-width: 620px) {
    .cengi-inst-modal { padding: 10px; }
    .cengi-inst-modal .inst-form-grid, .cengi-inst-modal .inst-eval-summary { grid-template-columns: 1fr; }
    .cengi-inst-modal .inst-profile-stats { flex-wrap: wrap; }
}
</style>

<?php if ($puedeGestionar): ?>
<div class="cengi-inst-modal" id="modalInstructor" aria-hidden="true">
    <div class="inst-modal-card" role="dialog" aria-modal="true" aria-labelledby="instModalTitulo">
        <form method="POST" enctype="multipart/form-data" id="instForm">
            <div class="inst-modal-head">
                <h3 id="instModalTitulo">Nuevo instructor</h3>
                <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
            </div>
            <div class="inst-modal-body">
                <input type="hidden" name="accion" id="instAccion" value="crear">
                <input type="hidden" name="id" id="instId" value="">
                <div class="inst-form-grid">
                    <div class="inst-field is-full">
                        <label for="instNombre">Nombre</label>
                        <input type="text" name="nombre" id="instNombre" placeholder="Ej. Ing. Rodolfo Melgar" required>
                    </div>
                    <div class="inst-field">
                        <label for="instEspecialidad">Especialidad</label>
                        <input type="text" name="especialidad" id="instEspecialidad" placeholder="Agronomía / Riego / Fitosanidad">
                    </div>
                    <div class="inst-field">
                        <label for="instTelefono">Teléfono</label>
                        <input type="text" name="telefono" id="instTelefono" placeholder="+502 0000-0000">
                    </div>
                    <div class="inst-field is-full">
                        <label for="instCorreo">Correo</label>
                        <input type="email" name="correo" id="instCorreo" placeholder="instructor@ejemplo.com">
                    </div>
                    <div class="inst-field is-full">
                        <label for="instCv">Hoja de vida (CV) <span class="inst-optional">PDF, DOC o DOCX</span></label>
                        <label class="inst-dropzone" for="instCv">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                            <div id="instCvText">Arrastra el CV o haz clic para seleccionar</div>
                        </label>
                        <input type="file" name="cv" id="instCv" accept=".pdf,.doc,.docx" hidden>
                    </div>
                </div>
            </div>
            <div class="inst-modal-footer">
                <button class="inst-btn inst-btn-outline" type="button" data-inst-close>Cancelar</button>
                <button class="inst-btn inst-btn-primary" type="submit">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="cengi-inst-modal" id="modalCargaEvaluaciones" aria-hidden="true">
    <div class="inst-modal-card is-wide" role="dialog" aria-modal="true" aria-labelledby="cargaEvalTitulo">
        <div class="inst-modal-head">
            <div>
                <h3 id="cargaEvalTitulo">Carga masiva de evaluaciones de instructor</h3>
                <div class="inst-modal-sub">Sube las boletas de evaluación recolectadas en papel o formulario externo</div>
            </div>
            <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
        </div>
        <div class="inst-modal-body">
            <div class="inst-form-grid" style="margin-bottom:14px;">
                <div class="inst-field is-full">
                    <label for="cargaEdicion">Edición del curso / diplomado <span class="inst-optional">indica el año correcto — es clave para datos históricos</span></label>
                    <select id="cargaEdicion"></select>
                </div>
            </div>
            <div class="inst-notice">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                <span>No selecciones un solo instructor: un diplomado puede tener varios módulos con distintos instructores. El CSV debe incluir una columna <b>Instructor</b> para identificar a quién califica cada boleta.</span>
            </div>
            <div class="inst-field">
                <label for="evaluacionesCsv">Sube el archivo CSV con las respuestas</label>
                <label class="inst-dropzone" for="evaluacionesCsv" style="padding:28px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                    <div style="color:var(--inst-ink);font-size:13px;font-weight:600;" id="evaluacionesCsvNombre">Arrastra tu archivo .csv aquí</div>
                    <div style="margin-top:3px;">Columnas: <b>Instructor</b>, Ingenio, Cargo, Modalidad, P1..P5, P6_Estrellas, Necesidades y Mejoras</div>
                </label>
                <input type="file" id="evaluacionesCsv" accept=".csv,text/csv" hidden>
            </div>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:14px;">
                <button class="inst-btn inst-btn-outline" type="button" id="descargarPlantillaCsv">Descargar plantilla CSV</button>
                <button class="inst-btn inst-btn-primary" type="button" id="seleccionarEvaluacionesCsv">Seleccionar archivo</button>
            </div>
            <div class="inst-notice" id="cargaEvaluacionesAviso" style="display:none;margin:18px 0 0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                <span>El archivo quedó seleccionado. El procesamiento masivo de evaluaciones aún no está habilitado en esta versión.</span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="cengi-inst-modal" id="modalInstructorDetalle" aria-hidden="true">
    <div class="inst-modal-card is-wide" role="dialog" aria-modal="true" aria-labelledby="instDetName">
        <div class="inst-profile-head">
            <div class="inst-profile-avatar" id="instDetAv">IN</div>
            <div class="inst-profile-copy">
                <div class="inst-profile-name" id="instDetName">Instructor</div>
                <div class="inst-profile-sub" id="instDetSub">Especialidad</div>
                <div style="margin-top:6px;" id="instDetCv"></div>
            </div>
            <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
        </div>
        <div class="inst-profile-stats">
            <div><div class="inst-stat-value" id="instDetCursos">0</div><div class="inst-stat-label">Cursos impartidos</div></div>
            <div><div class="inst-stat-value is-green" id="instDetEval">—</div><div class="inst-stat-label">Evaluación promedio</div></div>
            <div><div class="inst-stat-value" id="instDetEstado">Activo</div><div class="inst-stat-label">Estado del instructor</div></div>
        </div>
        <div class="inst-detail-tabs">
            <button class="inst-detail-tab is-active" type="button" data-detail-tab="cursos">Cursos impartidos</button>
            <button class="inst-detail-tab" type="button" data-detail-tab="evaluaciones">Evaluaciones recibidas</button>
        </div>
        <div class="inst-detail-panel" data-detail-panel="cursos" id="instDetHistorial"></div>
        <div class="inst-detail-panel" data-detail-panel="evaluaciones" id="instDetEvaluaciones" hidden></div>
        <div class="inst-modal-footer">
            <button class="inst-btn inst-btn-outline" type="button" id="imprimirInformeInstructor">Descargar informe PDF</button>
            <button class="inst-btn inst-btn-primary" type="button" data-inst-close>Cerrar</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var instructores = <?php echo json_encode($instructores, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var ediciones = <?php echo json_encode($ediciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var puedeGestionar = <?php echo $puedeGestionar ? 'true' : 'false'; ?>;
    var instView = 'grid';
    var activeTab = 'directorio';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function normalize(value) {
        var text = String(value == null ? '' : value).toLowerCase();
        return text.normalize ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : text;
    }
    function initials(name) {
        var cleaned = String(name || '').replace(/\b(Ing|Inga|Dr|Dra|Lic|Licda)\.?\s*/gi, '').trim();
        return cleaned.split(/\s+/).filter(Boolean).map(function (word) { return word.charAt(0); }).slice(0, 2).join('').toUpperCase() || 'IN';
    }
    function evaluationValue(value) {
        if (value === null || value === undefined || value === '') return '—';
        var number = Number(value);
        return Number.isFinite(number) ? number.toFixed(1) : '—';
    }
    function cvName(path) {
        if (!path) return '';
        var parts = String(path).split(/[\\/]/);
        return (parts[parts.length - 1] || 'Hoja de vida').replace(/^\d+_/, '');
    }
    function instructorEditions(id) {
        return ediciones.filter(function (course) { return Number(course.instructor_id) === Number(id); });
    }
    function institutionLabel(courseList) {
        var names = [];
        courseList.forEach(function (course) {
            var name = String(course.ingenio || '').trim();
            if (name && names.indexOf(name) === -1) names.push(name);
        });
        if (!names.length) return 'Sin institución asociada';
        return names.length === 1 ? names[0] : 'Varios ingenios';
    }
    function selectedFilters() {
        return {
            query: normalize(document.getElementById('instructoresBuscar').value).trim(),
            year: document.getElementById('instructoresAnio').value,
            course: document.getElementById('instructoresCurso').value,
            order: document.getElementById('instructoresOrden').value
        };
    }
    function filteredEditions(courseList, filters) {
        return courseList.filter(function (course) {
            return (filters.year === 'todos' || String(course.anio) === filters.year) &&
                (filters.course === 'todos' || String(course.nombre_cursos) === filters.course);
        });
    }
    function getFilteredInstructors() {
        var filters = selectedFilters();
        var filtersActive = filters.year !== 'todos' || filters.course !== 'todos';
        var result = instructores.map(function (instructor) {
            var allEditions = instructorEditions(instructor.id);
            return { instructor: instructor, allEditions: allEditions, matchingEditions: filteredEditions(allEditions, filters) };
        });
        if (filtersActive) result = result.filter(function (item) { return item.matchingEditions.length > 0; });
        if (filters.query) {
            result = result.filter(function (item) {
                return normalize(item.instructor.nombre).indexOf(filters.query) !== -1 || normalize(item.instructor.especialidad).indexOf(filters.query) !== -1;
            });
        }
        result.sort(function (left, right) {
            var a = left.instructor, b = right.instructor;
            if (filters.order === 'cursos_asc') return Number(a.total_cursos || 0) - Number(b.total_cursos || 0);
            if (filters.order === 'eval_desc') return Number(b.evaluacion_promedio || -1) - Number(a.evaluacion_promedio || -1);
            if (filters.order === 'nombre') return String(a.nombre).localeCompare(String(b.nombre), 'es');
            return Number(b.total_cursos || 0) - Number(a.total_cursos || 0);
        });
        return { items: result, filters: filters, filtersActive: filtersActive };
    }
    function managementTools(instructor) {
        if (!puedeGestionar) return '';
        var nextLabel = Number(instructor.estado) === 1 ? 'Desactivar' : 'Activar';
        var icon = Number(instructor.estado) === 1 ? 'glyphicon-eye-close' : 'glyphicon-eye-open';
        return '<div class="inst-card-tools"><button class="inst-icon-btn" type="button" data-edit-instructor="' + Number(instructor.id) + '" title="Editar instructor"><span class="glyphicon glyphicon-pencil"></span></button>' +
            '<form method="POST" style="margin:0;"><input type="hidden" name="accion" value="toggle_estado"><input type="hidden" name="id" value="' + Number(instructor.id) + '">' +
            '<button class="inst-icon-btn" type="submit" title="' + nextLabel + ' instructor"><span class="glyphicon ' + icon + '"></span></button></form></div>';
    }
    function cvChip(instructor, previewId) {
        return instructor.cv_path ? '<button class="inst-chip is-cv" type="button" data-cv-preview="' + previewId + '">📄 Ver CV</button>' : '<span class="inst-chip is-missing">⚠ Sin CV</span>';
    }
    function cvPreview(instructor, previewId, isList) {
        if (!instructor.cv_path) return '';
        var path = escapeHtml(instructor.cv_path);
        return '<div class="inst-cv-preview" id="' + previewId + '"' + (isList ? ' style="margin:0;padding-left:60px;"' : '') + '><div class="inst-cv-row"><div class="inst-cv-doc"></div>' +
            '<div style="flex:1;min-width:0;"><div style="margin-bottom:6px;font-size:11.5px;font-weight:600;overflow-wrap:anywhere;">' + escapeHtml(cvName(instructor.cv_path)) + '</div>' +
            '<div style="display:flex;gap:6px;flex-wrap:wrap;"><a class="inst-btn inst-btn-primary inst-btn-sm" href="' + path + '" target="_blank" rel="noopener">Abrir documento</a>' +
            '<a class="inst-btn inst-btn-outline inst-btn-sm" href="' + path + '" download>Descargar</a></div></div></div></div>';
    }
    function renderGridItem(item, filtersActive) {
        var instructor = item.instructor;
        var count = filtersActive ? item.matchingEditions.length : Number(instructor.total_cursos || 0);
        var previewId = 'cvPreviewGrid' + Number(instructor.id);
        return '<article class="inst-section inst-card"><div class="inst-section-body"><div class="inst-card-head"><div class="inst-name-cell">' +
            '<div class="inst-avatar">' + escapeHtml(initials(instructor.nombre)) + '</div><div style="min-width:0;"><div class="inst-name">' + escapeHtml(instructor.nombre) + '</div>' +
            '<div class="inst-sub">' + escapeHtml(instructor.especialidad || 'Sin especialidad registrada') + '</div></div></div>' + managementTools(instructor) + '</div>' +
            '<div class="inst-stats"><div><div class="inst-stat-value">' + count + '</div><div class="inst-stat-label">' + (filtersActive ? 'Ediciones (filtro)' : 'Cursos impartidos') + '</div></div>' +
            '<div><div class="inst-stat-value is-green">' + evaluationValue(instructor.evaluacion_promedio) + '</div><div class="inst-stat-label">Evaluación promedio</div></div></div>' +
            '<div class="inst-chips"><span class="inst-chip">' + escapeHtml(institutionLabel(item.allEditions)) + '</span>' + cvChip(instructor, previewId) + '</div>' +
            '<div class="inst-card-actions"><button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="cursos">Ver historial</button>' +
            '<button class="inst-btn inst-btn-ghost inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="evaluaciones">Informe</button></div>' +
            cvPreview(instructor, previewId, false) + '</div></article>';
    }
    function renderListItem(item, filtersActive) {
        var instructor = item.instructor;
        var count = filtersActive ? item.matchingEditions.length : Number(instructor.total_cursos || 0);
        var previewId = 'cvPreviewList' + Number(instructor.id);
        return '<div class="inst-list-row"><div class="inst-avatar">' + escapeHtml(initials(instructor.nombre)) + '</div>' +
            '<div class="inst-list-main"><div class="inst-name">' + escapeHtml(instructor.nombre) + '</div><div class="inst-sub">' + escapeHtml(instructor.especialidad || 'Sin especialidad') + ' · ' + escapeHtml(institutionLabel(item.allEditions)) + '</div></div>' +
            '<div class="inst-list-stat"><div class="inst-stat-value">' + count + '</div><div class="inst-stat-label">' + (filtersActive ? 'Ediciones' : 'Cursos') + '</div></div>' +
            '<div class="inst-list-stat"><div class="inst-stat-value is-green">' + evaluationValue(instructor.evaluacion_promedio) + '</div><div class="inst-stat-label">Evaluación</div></div>' +
            '<div style="min-width:118px;">' + cvChip(instructor, previewId) + '</div><div style="display:flex;gap:6px;align-items:center;">' +
            '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="cursos">Historial</button>' +
            '<button class="inst-btn inst-btn-ghost inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="evaluaciones">Informe</button>' + managementTools(instructor) + '</div></div>' +
            cvPreview(instructor, previewId, true);
    }
    function renderInstructors() {
        var result = getFilteredInstructors();
        var container = document.getElementById('gridInstructores');
        if (!result.items.length) {
            container.className = '';
            container.innerHTML = '<div class="inst-section"><div class="inst-empty">No hay instructores que coincidan con estos filtros.</div></div>';
            return;
        }
        if (instView === 'grid') {
            container.className = 'inst-grid';
            container.innerHTML = result.items.map(function (item) { return renderGridItem(item, result.filtersActive); }).join('');
        } else {
            container.className = '';
            container.innerHTML = '<div class="inst-section inst-list-wrap">' + result.items.map(function (item) { return renderListItem(item, result.filtersActive); }).join('') + '</div>';
        }
    }
    function courseStatus(course) {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var start = course.inicio ? new Date(course.inicio + 'T00:00:00') : null;
        var end = course.fin ? new Date(course.fin + 'T00:00:00') : null;
        if (end && end < today) return { label: 'Finalizado', className: 'is-finished' };
        if ((!start || start <= today) && (!end || end >= today)) return { label: 'Activo', className: 'is-active' };
        return { label: 'Planificación', className: 'is-planned' };
    }
    function renderReport() {
        var filters = selectedFilters();
        var reportCourses = filteredEditions(ediciones, filters);
        var subtitle = 'Agrupado por curso/diplomado · ' + (filters.year === 'todos' ? 'todos los años' : 'año ' + filters.year);
        if (filters.course !== 'todos') subtitle += ' · ' + filters.course;
        document.getElementById('informeSub').textContent = subtitle;
        var groups = {};
        reportCourses.forEach(function (course) {
            var key = String(course.nombre_cursos || 'Curso sin nombre');
            if (!groups[key]) groups[key] = [];
            groups[key].push(course);
        });
        var names = Object.keys(groups).sort(function (a, b) { return a.localeCompare(b, 'es'); });
        var container = document.getElementById('informeInstructores');
        if (!names.length) {
            container.innerHTML = '<div class="inst-empty">No hay ediciones registradas para este filtro.</div>';
            return;
        }
        container.innerHTML = names.map(function (name) {
            var rows = groups[name].slice().sort(function (a, b) { return Number(b.anio || 0) - Number(a.anio || 0); });
            var category = rows[0] ? rows[0].categoria : '';
            return '<div class="inst-report-group"><div class="inst-report-group-head"><span>' + escapeHtml(name) + '</span><small>' + escapeHtml(category) + ' · ' + rows.length + ' edición(es)</small></div>' +
                '<div class="inst-table-scroll"><table class="inst-table"><thead><tr><th>Año</th><th>Instructor</th><th>Especialidad</th><th>Estado</th><th>Evaluación prom.</th><th>Cupo</th><th>CV</th></tr></thead><tbody>' +
                rows.map(function (course) {
                    var status = courseStatus(course);
                    var cv = course.instructor_cv ? '<a class="inst-chip is-cv" href="' + escapeHtml(course.instructor_cv) + '" target="_blank" rel="noopener">📄 Ver CV</a>' : '<span class="inst-chip is-missing">⚠ Sin CV</span>';
                    return '<tr><td style="font-family:JetBrains Mono,monospace;font-size:11px;">' + escapeHtml(course.anio || '—') + '</td><td style="font-weight:600;">' + escapeHtml(course.instructor_nombre || 'Sin asignar') + '</td>' +
                        '<td>' + escapeHtml(course.instructor_especialidad || '—') + '</td><td><span class="inst-badge ' + status.className + '">' + status.label + '</span></td>' +
                        '<td>' + evaluationValue(course.evaluacion_promedio) + '</td><td>' + escapeHtml(course.cupo || course.total_inscritos || '—') + '</td><td>' + cv + '</td></tr>';
                }).join('') + '</tbody></table></div></div>';
        }).join('');
    }
    function renderAll() {
        renderInstructors();
        if (activeTab === 'informe') renderReport();
    }
    function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('inst-modal-open');
        var focusable = modal.querySelector('input:not([type="hidden"]), select, button');
        if (focusable) window.setTimeout(function () { focusable.focus(); }, 20);
    }
    function closeModal(modal) {
        if (typeof modal === 'string') modal = document.getElementById(modal);
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.cengi-inst-modal.is-open')) document.body.classList.remove('inst-modal-open');
    }
    function newInstructor() {
        var form = document.getElementById('instForm');
        if (!form) return;
        form.reset();
        document.getElementById('instModalTitulo').textContent = 'Nuevo instructor';
        document.getElementById('instAccion').value = 'crear';
        document.getElementById('instId').value = '';
        document.getElementById('instCvText').textContent = 'Arrastra el CV o haz clic para seleccionar';
        openModal('modalInstructor');
    }
    function editInstructor(id) {
        var instructor = instructores.find(function (item) { return Number(item.id) === Number(id); });
        if (!instructor) return;
        document.getElementById('instForm').reset();
        document.getElementById('instModalTitulo').textContent = 'Editar instructor';
        document.getElementById('instAccion').value = 'actualizar';
        document.getElementById('instId').value = instructor.id;
        document.getElementById('instNombre').value = instructor.nombre || '';
        document.getElementById('instEspecialidad').value = instructor.especialidad || '';
        document.getElementById('instCorreo').value = instructor.correo || '';
        document.getElementById('instTelefono').value = instructor.telefono || '';
        document.getElementById('instCvText').textContent = instructor.cv_path ? 'CV actual: ' + cvName(instructor.cv_path) : 'Arrastra el CV o haz clic para seleccionar';
        openModal('modalInstructor');
    }
    function formatDate(dateValue) {
        if (!dateValue) return 'Sin fecha';
        return new Date(dateValue + 'T00:00:00').toLocaleDateString('es-GT', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    function openInstructorDetail(id, targetTab) {
        var instructor = instructores.find(function (item) { return Number(item.id) === Number(id); });
        if (!instructor) return;
        var courses = instructorEditions(id);
        document.getElementById('instDetAv').textContent = initials(instructor.nombre);
        document.getElementById('instDetName').textContent = instructor.nombre || 'Instructor';
        document.getElementById('instDetSub').textContent = (instructor.especialidad || 'Sin especialidad') + ' · ' + institutionLabel(courses);
        document.getElementById('instDetCursos').textContent = Number(instructor.total_cursos || 0);
        document.getElementById('instDetEval').textContent = evaluationValue(instructor.evaluacion_promedio);
        document.getElementById('instDetEstado').textContent = Number(instructor.estado) === 1 ? 'Activo' : 'Inactivo';
        document.getElementById('instDetCv').innerHTML = instructor.cv_path ?
            '<a class="inst-chip is-cv" href="' + escapeHtml(instructor.cv_path) + '" target="_blank" rel="noopener">📄 ' + escapeHtml(cvName(instructor.cv_path)) + '</a>' :
            '<span class="inst-chip is-missing">⚠ Sin CV cargado</span>';
        var history = document.getElementById('instDetHistorial');
        if (!courses.length) {
            history.innerHTML = '<div class="inst-detail-empty">Este instructor todavía no tiene cursos asociados.</div>';
        } else {
            history.innerHTML = '<div style="overflow-x:auto;"><table class="inst-table"><thead><tr><th>Curso</th><th>Ingenio</th><th>Fecha</th><th>Modalidad</th><th>Participantes</th></tr></thead><tbody>' +
                courses.map(function (course) {
                    var range = formatDate(course.inicio) + (course.fin && course.fin !== course.inicio ? ' – ' + formatDate(course.fin) : '');
                    return '<tr><td style="font-weight:600;">' + escapeHtml(course.nombre_cursos) + '</td><td>' + escapeHtml(course.ingenio || '—') + '</td><td>' + escapeHtml(range) + '</td>' +
                        '<td>' + escapeHtml(course.modalidad || '—') + '</td><td>' + Number(course.total_inscritos || 0) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        var rawEvaluation = Number(instructor.evaluacion_promedio);
        var percentage = Number.isFinite(rawEvaluation) ? Math.max(0, Math.min(100, rawEvaluation <= 5 ? rawEvaluation * 20 : rawEvaluation)) : 0;
        document.getElementById('instDetEvaluaciones').innerHTML = '<div class="inst-eval-summary"><div class="inst-eval-box"><div class="inst-stat-label">Evaluación promedio registrada</div>' +
            '<div class="inst-stat-value is-green" style="font-size:26px;margin-top:6px;">' + evaluationValue(instructor.evaluacion_promedio) + '</div><div class="inst-progress"><span style="width:' + percentage + '%"></span></div></div>' +
            '<div class="inst-eval-box"><div class="inst-stat-label">Cursos con datos de evaluación</div><div class="inst-stat-value" style="font-size:26px;margin-top:6px;">' +
            courses.filter(function (course) { return course.evaluacion_promedio !== null; }).length + '</div><div class="inst-stat-label" style="margin-top:10px;">Calculado con las evaluaciones finales disponibles.</div></div></div>';
        switchDetailTab(targetTab === 'evaluaciones' ? 'evaluaciones' : 'cursos');
        openModal('modalInstructorDetalle');
    }
    function switchDetailTab(tab) {
        document.querySelectorAll('[data-detail-tab]').forEach(function (button) { button.classList.toggle('is-active', button.getAttribute('data-detail-tab') === tab); });
        document.querySelectorAll('[data-detail-panel]').forEach(function (panel) { panel.hidden = panel.getAttribute('data-detail-panel') !== tab; });
    }
    function populateLoadEditionSelect() {
        var select = document.getElementById('cargaEdicion');
        if (!select) return;
        if (!ediciones.length) {
            select.innerHTML = '<option value="">No hay ediciones registradas</option>';
            return;
        }
        select.innerHTML = ediciones.map(function (course) {
            return '<option value="' + Number(course.curso_id) + '">' + escapeHtml((course.anio || 'Sin año') + ' · ' + course.nombre_cursos + ' · ' + course.ingenio) + '</option>';
        }).join('');
    }

    ['instructoresBuscar', 'instructoresAnio', 'instructoresCurso', 'instructoresOrden'].forEach(function (id) {
        var element = document.getElementById(id);
        if (element) element.addEventListener(id === 'instructoresBuscar' ? 'input' : 'change', renderAll);
    });
    document.querySelectorAll('[data-inst-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            instView = button.getAttribute('data-inst-view');
            document.querySelectorAll('[data-inst-view]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
            renderInstructors();
        });
    });
    document.querySelectorAll('[data-inst-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activeTab = button.getAttribute('data-inst-tab');
            document.querySelectorAll('[data-inst-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
            document.querySelectorAll('[data-inst-panel]').forEach(function (panel) { panel.hidden = panel.getAttribute('data-inst-panel') !== activeTab; });
            document.getElementById('instViewToggle').style.display = activeTab === 'directorio' ? 'flex' : 'none';
            if (activeTab === 'informe') renderReport();
        });
    });
    document.addEventListener('click', function (event) {
        var previewButton = event.target.closest('[data-cv-preview]');
        if (previewButton) {
            var preview = document.getElementById(previewButton.getAttribute('data-cv-preview'));
            if (preview) preview.classList.toggle('is-open');
            return;
        }
        var detailButton = event.target.closest('[data-detail-instructor]');
        if (detailButton) { openInstructorDetail(detailButton.getAttribute('data-detail-instructor'), detailButton.getAttribute('data-detail-target')); return; }
        var editButton = event.target.closest('[data-edit-instructor]');
        if (editButton) { editInstructor(editButton.getAttribute('data-edit-instructor')); return; }
        var openButton = event.target.closest('[data-inst-open]');
        if (openButton) { openModal(openButton.getAttribute('data-inst-open')); return; }
        var closeButton = event.target.closest('[data-inst-close]');
        if (closeButton) closeModal(closeButton.closest('.cengi-inst-modal'));
    });
    document.querySelectorAll('.cengi-inst-modal').forEach(function (modal) {
        modal.addEventListener('mousedown', function (event) { if (event.target === modal) closeModal(modal); });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var open = document.querySelector('.cengi-inst-modal.is-open');
            if (open) closeModal(open);
        }
    });
    document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
        button.addEventListener('click', function () { switchDetailTab(button.getAttribute('data-detail-tab')); });
    });
    var newButton = document.getElementById('nuevoInstructorBtn');
    if (newButton) newButton.addEventListener('click', newInstructor);
    var cvInput = document.getElementById('instCv');
    if (cvInput) cvInput.addEventListener('change', function () {
        document.getElementById('instCvText').textContent = cvInput.files && cvInput.files[0] ? 'Archivo seleccionado: ' + cvInput.files[0].name : 'Arrastra el CV o haz clic para seleccionar';
    });
    var csvInput = document.getElementById('evaluacionesCsv');
    var csvPicker = document.getElementById('seleccionarEvaluacionesCsv');
    if (csvPicker && csvInput) csvPicker.addEventListener('click', function () { csvInput.click(); });
    if (csvInput) csvInput.addEventListener('change', function () {
        if (csvInput.files && csvInput.files[0]) {
            document.getElementById('evaluacionesCsvNombre').textContent = 'Archivo seleccionado: ' + csvInput.files[0].name;
            document.getElementById('cargaEvaluacionesAviso').style.display = 'flex';
        }
    });
    var templateButton = document.getElementById('descargarPlantillaCsv');
    if (templateButton) templateButton.addEventListener('click', function () {
        var header = 'Instructor,Ingenio,Cargo,Modalidad,P1,P2,P3,P4,P5,P6_Estrellas,Necesidades,Mejoras\r\n';
        var url = URL.createObjectURL(new Blob(['\uFEFF' + header], { type: 'text/csv;charset=utf-8' }));
        var link = document.createElement('a');
        link.href = url;
        link.download = 'plantilla_evaluaciones_instructores.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });
    var printButton = document.getElementById('imprimirInformeInstructor');
    if (printButton) printButton.addEventListener('click', function () { window.print(); });

    populateLoadEditionSelect();
    renderInstructors();
})();
</script>
</body>
</html>
