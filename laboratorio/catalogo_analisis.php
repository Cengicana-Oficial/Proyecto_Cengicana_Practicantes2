<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/catalogo_analisis_helper.php';

lab_require_permission('laboratorio.catalogo_analisis.ver');

function catalogoAnalisisE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$mensaje = '';
$errorMensaje = '';
$schemaMensaje = '';
$canEdit = lab_can('laboratorio.analisis.editar');
$canCreateSolicitud = lab_can('laboratorio.solicitudes.crear');
$action = $_POST['action'] ?? '';
$editingId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$msg = trim((string) ($_GET['msg'] ?? ''));

switch ($msg) {
    case 'updated':
        $mensaje = 'El análisis se actualizó correctamente.';
        break;
    case 'deleted':
        $mensaje = 'El análisis se desactivó correctamente.';
        break;
    case 'activated':
        $mensaje = 'El análisis se reactivó correctamente.';
        break;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($action, ['save', 'toggle'], true)) {
        lab_require_permission('laboratorio.analisis.editar');
    }

    try {
        if ($action === 'save') {
            $idTipo = isset($_POST['id_tipo']) ? (int) $_POST['id_tipo'] : 0;
            if ($idTipo <= 0) {
                throw new RuntimeException('Selecciona un análisis existente para editarlo.');
            }

            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $idTipoMuestra = isset($_POST['id_tipo_muestra']) ? (int) $_POST['id_tipo_muestra'] : 0;
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($nombre === '' || $idTipoMuestra <= 0) {
                throw new RuntimeException('Completa el módulo y el nombre antes de guardar.');
            }

            $muestraExiste = false;
            foreach (labCatalogoAnalisisTipoMuestraOptions($conexion) as $opcion) {
                if ((int) $opcion['id_tipo'] === $idTipoMuestra) {
                    $muestraExiste = true;
                    break;
                }
            }

            if (!$muestraExiste) {
                throw new RuntimeException('El módulo seleccionado no existe.');
            }

            labCatalogoAnalisisGuardar($conexion, $idTipo, $idTipoMuestra, $nombre, $activo);
            header('Location: catalogo_analisis.php?msg=updated');
            exit;
        }

        if ($action === 'toggle') {
            $idTipo = isset($_POST['id_tipo']) ? (int) $_POST['id_tipo'] : 0;
            $activo = isset($_POST['activo']) ? (int) $_POST['activo'] : 0;

            if ($idTipo <= 0) {
                throw new RuntimeException('No se encontró el análisis solicitado.');
            }

            if (!labCatalogoAnalisisCambiarEstado($conexion, $idTipo, $activo)) {
                throw new RuntimeException('No se pudo cambiar el estado del análisis.');
            }

            $redir = 'catalogo_analisis.php?msg=' . ($activo === 1 ? 'activated' : 'deleted');
            if ($editingId > 0) {
                $redir .= '&edit_id=' . $editingId;
            }
            header('Location: ' . $redir);
            exit;
        }
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();
    }
}

try {
    labCatalogoAnalisisAsegurarEsquema($conexion);
    $tiposMuestra = labCatalogoAnalisisTipoMuestraOptions($conexion);
    $filas = labCatalogoAnalisisFilas($conexion, false);
    $grupos = labCatalogoAnalisisAgrupar($filas, false);
    $editingRow = $editingId > 0 ? labCatalogoAnalisisObtenerPorId($conexion, $editingId) : null;
} catch (Throwable $e) {
    $schemaMensaje = $e->getMessage();
    $tiposMuestra = [];
    $filas = [];
    $grupos = [];
    $editingRow = null;
}

$totalRegistros = count($filas);
$totalActivos = 0;
$totalInactivos = 0;
foreach ($grupos as $grupo) {
    $totalActivos += (int) ($grupo['activos'] ?? 0);
    $totalInactivos += (int) ($grupo['inactivos'] ?? 0);
}

$moduleOrder = ['suelos', 'agua', 'foliares', 'cana', 'miel'];
$sections = [];
foreach ($moduleOrder as $clave) {
    if (isset($grupos[$clave])) {
        $sections[] = $grupos[$clave];
    }
}
foreach ($grupos as $clave => $grupo) {
    if (!in_array($clave, $moduleOrder, true)) {
        $sections[] = $grupo;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de análisis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/button.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --page-bg: #f3f7f0;
            --surface: rgba(255, 255, 255, 0.9);
            --surface-strong: #ffffff;
            --border: #d9e6d7;
            --border-strong: #c7d7c5;
            --text-main: #173025;
            --text-soft: #5c7165;
            --brand: #0d5c39;
            --brand-soft: #e4f4e9;
            --brand-2: #133f2d;
            --danger: #b74330;
            --danger-soft: #ffece8;
            --muted-soft: #eef4ee;
            --shadow: 0 20px 50px rgba(12, 39, 27, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 28px 18px 40px;
            background:
                radial-gradient(circle at top right, rgba(116, 186, 118, 0.18), transparent 30%),
                radial-gradient(circle at top left, rgba(13, 92, 57, 0.09), transparent 28%),
                linear-gradient(180deg, #f8fcf7 0%, var(--page-bg) 100%);
            color: var(--text-main);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .page-shell {
            max-width: 1360px;
            margin: 0 auto;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.8);
            box-shadow: 0 10px 24px rgba(12, 39, 27, 0.05);
            font-weight: 700;
        }

        .hero-card,
        .editor-card,
        .module-card,
        .notice-card,
        .summary-card {
            background: var(--surface);
            border: 1px solid rgba(201, 217, 201, 0.8);
            border-radius: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .hero-card {
            padding: 28px;
            display: grid;
            gap: 22px;
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .hero-top h1 {
            margin: 0;
            font-size: clamp(28px, 4vw, 40px);
            line-height: 1.06;
        }

        .hero-top p {
            margin: 10px 0 0;
            max-width: 780px;
            color: var(--text-soft);
            font-size: 15px;
            line-height: 1.55;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .action-pill {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 12px 16px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: var(--surface-strong);
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(12, 39, 27, 0.05);
        }

        .action-pill.primary {
            background: var(--brand);
            color: #fff;
            border-color: transparent;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .summary-card {
            padding: 18px;
        }

        .summary-card .kicker {
            display: block;
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .summary-card strong {
            display: block;
            margin-top: 8px;
            font-size: 28px;
            line-height: 1;
        }

        .summary-card span:last-child {
            display: block;
            margin-top: 4px;
            color: var(--text-soft);
            font-size: 13px;
        }

        .main-grid {
            display: grid;
            grid-template-columns: minmax(0, 360px) minmax(0, 1fr);
            gap: 18px;
            margin-top: 18px;
            align-items: start;
        }

        .editor-card {
            padding: 22px;
            position: sticky;
            top: 18px;
        }

        .editor-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .editor-head h2,
        .section-head h2 {
            margin: 6px 0 0;
            font-size: 22px;
            line-height: 1.15;
        }

        .editor-copy,
        .section-copy {
            margin: 10px 0 0;
            color: var(--text-soft);
            font-size: 14px;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field label {
            font-weight: 700;
            font-size: 13px;
        }

        .field input,
        .field select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--border-strong);
            border-radius: 14px;
            background: #fff;
            color: var(--text-main);
        }

        .field input:focus,
        .field select:focus,
        .search-input:focus {
            outline: none;
            border-color: rgba(13, 92, 57, 0.6);
            box-shadow: 0 0 0 4px rgba(13, 92, 57, 0.08);
        }

        .check-row {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--text-soft);
        }

        .editor-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 6px;
        }

        .action-pill:hover,
        .row-link:hover {
            transform: translateY(-1px);
        }

        .catalog-panel {
            display: grid;
            gap: 16px;
        }

        .catalog-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .search-shell {
            flex: 1 1 320px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 14px;
            min-height: 48px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid var(--border);
            box-shadow: 0 12px 24px rgba(12, 39, 27, 0.05);
        }

        .search-shell i {
            color: var(--text-soft);
        }

        .search-input {
            width: 100%;
            border: 0;
            background: transparent;
            padding: 12px 0;
        }

        .module-section {
            display: grid;
            gap: 14px;
            padding: 22px;
            background: var(--surface);
            border: 1px solid rgba(201, 217, 201, 0.8);
            border-radius: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .module-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--muted-soft);
            color: var(--brand-2);
            font-size: 12px;
            font-weight: 800;
        }

        .module-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .module-table .center {
            text-align: center;
        }

        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .row-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 36px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            font-weight: 700;
        }

        .row-link {
            color: var(--text-main);
        }

        .row-link.active-row {
            background: var(--brand);
            color: #fff;
            border-color: transparent;
        }

        .module-section.is-hidden {
            display: none;
        }

        .table-row.is-editing {
            background: #eff8ef;
        }

        .footer-note {
            margin-top: 16px;
            color: var(--text-soft);
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 1100px) {
            .main-grid {
                grid-template-columns: 1fr;
            }

            .editor-card {
                position: static;
            }
        }

        @media (max-width: 760px) {
            body {
                padding: 18px 14px 28px;
            }

            .hero-card,
            .editor-card,
            .module-section {
                padding: 18px;
                border-radius: 20px;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
    <link rel="stylesheet" href="styles/tables.css">
</head>
<body>
    <div class="page-shell">
        <a class="back-link" href="index.php">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Volver al inicio</span>
        </a>

        <section class="hero-card">
            <div class="hero-top">
                <div>
                    <span class="eyebrow"><i class="fa-solid fa-flask"></i> Administración del catálogo</span>
                    <h1>Catálogo de tipos de análisis</h1>
                    <p>
                        Revisa los tipos de análisis disponibles por módulo, ajusta el nombre o el módulo cuando sea necesario
                        y desactiva los registros que ya no deban aparecer en la solicitud de análisis.
                    </p>
                </div>

                <div class="hero-actions">
                    <?php if ($canCreateSolicitud): ?>
                        <a class="action-pill primary" href="view/solicitud_formulario.php">
                            <i class="fa-solid fa-clipboard-list"></i>
                            <span>Ir al formulario</span>
                        </a>
                        <a class="action-pill" href="view/menu_solicitud.php">
                            <i class="fa-solid fa-layer-group"></i>
                            <span>Menú de solicitud</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <span class="kicker">Registros</span>
                    <strong><?= (int) $totalRegistros ?></strong>
                    <span>Tipos cargados en la base de datos</span>
                </div>
                <div class="summary-card">
                    <span class="kicker">Activos</span>
                    <strong><?= (int) $totalActivos ?></strong>
                    <span>Visibles en el formulario de solicitud</span>
                </div>
                <div class="summary-card">
                    <span class="kicker">Inactivos</span>
                    <strong><?= (int) $totalInactivos ?></strong>
                    <span>Ocultos del formulario, pero conservados</span>
                </div>
                <div class="summary-card">
                    <span class="kicker">Módulos</span>
                    <strong><?= count($sections) ?></strong>
                    <span>Suelos, aguas, foliares, caña y mieles</span>
                </div>
            </div>
        </section>

        <?php if ($mensaje !== ''): ?>
            <div class="message success"><?= catalogoAnalisisE($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($errorMensaje !== ''): ?>
            <div class="message error"><?= catalogoAnalisisE($errorMensaje) ?></div>
        <?php endif; ?>

        <?php if ($schemaMensaje !== ''): ?>
            <div class="message warning"><?= catalogoAnalisisE($schemaMensaje) ?></div>
        <?php endif; ?>

        <div class="main-grid">
            <aside class="editor-card" id="editor">
                <div class="editor-head">
                    <div>
                        <span class="eyebrow"><i class="fa-solid fa-pen-to-square"></i> Edición</span>
                        <h2>
                            <?= $editingRow ? 'Editar tipo de análisis' : 'Selecciona un análisis para editarlo' ?>
                        </h2>
                        <p class="editor-copy">
                            <?= $editingRow
                                ? 'La desactivación funciona como una eliminación lógica para no romper la trazabilidad de solicitudes previas.'
                                : 'Usa el botón Editar de cualquier fila para cargar el formulario aquí.' ?>
                        </p>
                    </div>
                </div>

                <?php if ($canEdit && $editingRow): ?>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id_tipo" value="<?= (int) $editingRow['id_tipo'] ?>">

                        <div class="field">
                            <label for="tipo-muestra">Módulo</label>
                            <select id="tipo-muestra" name="id_tipo_muestra" required>
                                <?php foreach ($tiposMuestra as $opcion): ?>
                                    <option value="<?= (int) $opcion['id_tipo'] ?>" <?= (int) $opcion['id_tipo'] === (int) $editingRow['id_tipo_muestra'] ? 'selected' : '' ?>>
                                        <?= catalogoAnalisisE($opcion['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="nombre-analisis">Nombre del análisis</label>
                            <input
                                id="nombre-analisis"
                                type="text"
                                name="nombre"
                                maxlength="255"
                                value="<?= catalogoAnalisisE($editingRow['nombre']) ?>"
                                required>
                        </div>

                        <label class="check-row" for="activo-analisis">
                            <input
                                id="activo-analisis"
                                type="checkbox"
                                name="activo"
                                value="1"
                                <?= (int) ($editingRow['activo'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <span>Activo y visible en el formulario</span>
                        </label>

                        <div class="editor-actions">
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-save"></i>
                                <span>Guardar cambios</span>
                            </button>
                            <a class="btn btn-quiet" href="catalogo_analisis.php">
                                <i class="fa-solid fa-ban"></i>
                                <span>Cancelar</span>
                            </a>
                        </div>
                    </form>
                <?php elseif ($canEdit): ?>
                    <div class="notice-card" style="padding:16px; background:#fff; border:1px dashed var(--border); border-radius:18px;">
                        <p style="margin:0 0 10px; color:var(--text-soft); line-height:1.5;">
                            No hay ningún análisis cargado en el editor. Haz clic en <strong>Editar</strong> en cualquiera de las tablas para modificar su nombre, módulo o estado.
                        </p>
                        <p style="margin:0; color:var(--text-soft); line-height:1.5;">
                            La creación de nuevos tipos queda pendiente, tal como pediste.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notice-card" style="padding:16px; background:#fff; border:1px dashed var(--border); border-radius:18px;">
                        <p style="margin:0; color:var(--text-soft); line-height:1.5;">
                            Este usuario solo tiene acceso de lectura al catálogo.
                        </p>
                    </div>
                <?php endif; ?>

                <p class="footer-note">
                    Nota: desactivar un tipo no borra sus referencias históricas; únicamente lo oculta de la solicitud de análisis.
                </p>
            </aside>

            <section class="catalog-panel">
                <div class="catalog-toolbar">
                    <label class="search-shell" for="catalog-search">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input
                            id="catalog-search"
                            class="search-input"
                            type="search"
                            placeholder="Buscar por nombre o módulo..."
                            autocomplete="off">
                    </label>

                    <div class="module-meta">
                        <span class="mini-chip"><strong><?= count($sections) ?></strong> módulos</span>
                        <span class="mini-chip"><strong><?= (int) $totalActivos ?></strong> activos</span>
                        <span class="mini-chip"><strong><?= (int) $totalInactivos ?></strong> inactivos</span>
                    </div>
                </div>

                <?php foreach ($sections as $section): ?>
                    <?php
                        $sectionId = 'module-' . $section['key'];
                        $rows = $section['items'] ?? [];
                        $displayTotal = count($rows);
                        $displayActivos = (int) ($section['activos'] ?? 0);
                        $displayInactivos = (int) ($section['inactivos'] ?? 0);
                    ?>
                    <article class="module-section" data-module-section data-searchable="<?= catalogoAnalisisE($section['label_plural']) ?> <?= catalogoAnalisisE($section['label']) ?>">
                        <div class="section-head">
                            <div>
                                <span class="module-badge">
                                    <i class="fa-solid fa-flask-vial"></i>
                                    <span><?= catalogoAnalisisE($section['label_plural']) ?></span>
                                </span>
                                <h2><?= catalogoAnalisisE($section['label_plural']) ?></h2>
                                <p class="section-copy">
                                    <?= $displayTotal > 0
                                        ? 'Revisa y ajusta los tipos de análisis que pertenecen a este módulo.'
                                        : 'No hay tipos de análisis cargados todavía para este módulo.' ?>
                                </p>
                            </div>

                            <div class="module-meta">
                                <span class="mini-chip"><strong><?= $displayActivos ?></strong> activos</span>
                                <span class="mini-chip"><strong><?= $displayInactivos ?></strong> inactivos</span>
                                <span class="mini-chip"><strong><?= $displayTotal ?></strong> total</span>
                            </div>
                        </div>

                        <div class="module-table-wrap">
                            <table class="module-table">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th style="width:140px">Estado</th>
                                        <th style="width:180px">Módulo</th>
                                        <?php if ($canEdit): ?>
                                            <th style="width:240px">Acciones</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="<?= $canEdit ? 4 : 3 ?>" class="empty-state">
                                                No hay registros en este módulo.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($rows as $row): ?>
                                            <?php
                                                $isActive = (int) ($row['activo'] ?? 1) === 1;
                                                $isEditing = $editingRow && (int) $editingRow['id_tipo'] === (int) $row['id_tipo'];
                                            ?>
                                            <tr class="table-row<?= $isEditing ? ' is-editing' : '' ?>" data-searchable-row="<?= catalogoAnalisisE($row['nombre']) ?> <?= catalogoAnalisisE($section['label_plural']) ?> <?= catalogoAnalisisE($section['label']) ?>">
                                                <td>
                                                    <strong><?= catalogoAnalisisE($row['nombre']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="status-pill<?= $isActive ? '' : ' is-off' ?>">
                                                        <i class="fa-solid <?= $isActive ? 'fa-circle-check' : 'fa-circle-minus' ?>"></i>
                                                        <?= $isActive ? 'Activo' : 'Inactivo' ?>
                                                    </span>
                                                </td>
                                                <td><?= catalogoAnalisisE($section['label_plural']) ?></td>
                                                <?php if ($canEdit): ?>
                                                    <td>
                                                        <div class="row-actions">
                                                            <a class="row-link<?= $isEditing ? ' active-row' : '' ?>" href="?edit_id=<?= (int) $row['id_tipo'] ?>#editor">
                                                                <i class="fa-solid fa-pen"></i>
                                                                <span>Editar</span>
                                                            </a>

                                                            <form method="post" style="display:inline;">
                                                                <input type="hidden" name="action" value="toggle">
                                                                <input type="hidden" name="id_tipo" value="<?= (int) $row['id_tipo'] ?>">
                                                                <input type="hidden" name="activo" value="<?= $isActive ? 0 : 1 ?>">
                                                                <button
                                                                    type="submit"
                                                                    class="row-button<?= $isActive ? ' danger' : ' success' ?>"
                                                                    onclick="return confirm('<?= $isActive ? '¿Deseas desactivar este análisis?' : '¿Deseas reactivar este análisis?' ?>')">
                                                                    <i class="fa-solid <?= $isActive ? 'fa-trash-can' : 'fa-rotate-left' ?>"></i>
                                                                    <span><?= $isActive ? 'Eliminar' : 'Reactivar' ?></span>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
    </div>

    <script>
    (function () {
        const searchInput = document.getElementById('catalog-search');
        const sections = Array.from(document.querySelectorAll('[data-module-section]'));

        function filterCatalog() {
            const query = (searchInput.value || '').trim().toLowerCase();

            sections.forEach((section) => {
                const rows = Array.from(section.querySelectorAll('[data-searchable-row]'));
                let visibleRows = 0;

                rows.forEach((row) => {
                    const text = (row.dataset.searchableRow || '').toLowerCase();
                    const match = !query || text.includes(query);
                    row.hidden = !match;
                    if (match) {
                        visibleRows += 1;
                    }
                });

                section.hidden = query !== '' && visibleRows === 0;
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterCatalog);
            filterCatalog();
        }
    })();
    </script>
</body>
</html>
