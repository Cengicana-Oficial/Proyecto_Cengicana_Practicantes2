<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/catalogo_muestras_helper.php';

lab_require_module_access();
lab_require_permission('laboratorio.catalogo_muestras.ver');

labCatalogoMuestrasAsegurarEsquema($conexion);

function catalogoMuestrasE(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function catalogoMuestrasFlashLabel(string $msg): string
{
    $map = [
        'saved' => 'Tipo de muestra actualizado correctamente.',
        'activated' => 'Tipo de muestra activado correctamente.',
        'deactivated' => 'Tipo de muestra desactivado correctamente.',
        'pending' => 'La creación de nuevos tipos de muestra todavía está pendiente.',
    ];

    return $map[$msg] ?? '';
}

$message = '';
$editingRow = null;
$query = trim((string) ($_GET['q'] ?? ''));
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    $idTipo = isset($_POST['id_tipo']) ? (int) $_POST['id_tipo'] : 0;

    try {
        if ($action === 'toggle') {
            $nuevoEstado = isset($_POST['activo']) && (int) $_POST['activo'] === 1 ? 1 : 0;
            if ($idTipo <= 0) {
                throw new RuntimeException('No se encontró el tipo de muestra a modificar.');
            }

            labCatalogoMuestrasCambiarEstado($conexion, $idTipo, $nuevoEstado);
            $redir = 'catalogo_muestras.php?msg=' . ($nuevoEstado === 1 ? 'activated' : 'deactivated');
        } elseif ($action === 'create') {
            $redir = 'catalogo_muestras.php?msg=pending';
        } else {
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $activo = isset($_POST['activo']) && (int) $_POST['activo'] === 1 ? 1 : 0;

            labCatalogoMuestrasGuardar($conexion, $idTipo > 0 ? $idTipo : null, $nombre, $activo);
            $redir = 'catalogo_muestras.php?edit=' . $idTipo . '&msg=saved';
        }

        header('Location: ' . $redir);
        exit;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (!empty($_GET['msg'])) {
    $message = catalogoMuestrasFlashLabel((string) $_GET['msg']);
}

$filas = labCatalogoMuestrasFilas($conexion);
if ($query !== '') {
    $filas = array_values(array_filter($filas, static function (array $fila) use ($query): bool {
        $needle = labCatalogoMuestrasNormalizarTexto($query);
        $haystack = implode(' ', [
            (string) ($fila['nombre'] ?? ''),
            (string) ($fila['prefijo'] ?? ''),
            (string) ($fila['label'] ?? ''),
            (string) ($fila['label_plural'] ?? ''),
        ]);

        return strpos(labCatalogoMuestrasNormalizarTexto($haystack), $needle) !== false;
    }));
}

$total = count($filas);
$activos = count(array_filter($filas, static fn(array $fila): bool => (int) ($fila['activo'] ?? 1) === 1));
$inactivos = $total - $activos;
$analisisVinculados = array_sum(array_map(static fn(array $fila): int => (int) ($fila['analisis_activos'] ?? 0), $filas));

if ($editId > 0) {
    $editingRow = labCatalogoMuestrasObtenerPorId($conexion, $editId);
}

$sampleVisuals = [
    'suelos' => ['icon' => 'fa-mountain', 'tone' => 'forest'],
    'agua' => ['icon' => 'fa-droplet', 'tone' => 'water'],
    'foliares' => ['icon' => 'fa-leaf', 'tone' => 'leaf'],
    'cana' => ['icon' => 'fa-tractor', 'tone' => 'cane'],
    'miel' => ['icon' => 'fa-mug-hot', 'tone' => 'honey'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Catálogo de tipos de muestra</title>
    <link rel="stylesheet" href="styles/base.css">
    <link rel="stylesheet" href="css/laboratorio.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg: #f4f7f1;
            --panel: #ffffff;
            --text: #20311f;
            --muted: #617061;
            --primary: #2f641c;
            --primary-2: #3c7a23;
            --border: #dbe5d5;
            --shadow: 0 16px 36px rgba(33, 57, 25, 0.08);
        }
        body {
            margin: 0;
            background: radial-gradient(circle at top left, rgba(69, 120, 38, 0.08), transparent 34%),
                        linear-gradient(180deg, #f9fbf7 0%, var(--bg) 100%);
            color: var(--text);
            font-family: 'Hanken Grotesk', system-ui, sans-serif;
        }
        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px 22px 40px;
        }
        .hero {
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
            align-items: end;
            margin-bottom: 22px;
        }
        .hero-card, .panel, .stats-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }
        .hero-card {
            padding: 28px;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(47, 100, 28, 0.08);
            color: var(--primary);
            font-weight: 700;
            letter-spacing: .02em;
            margin-bottom: 14px;
        }
        .hero h1 {
            margin: 0 0 10px;
            font-size: clamp(2rem, 2.5vw, 3.1rem);
            line-height: 1.05;
        }
        .hero p {
            margin: 0;
            color: var(--muted);
            font-size: 1rem;
            max-width: 60ch;
        }
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .stats-card {
            padding: 18px;
        }
        .stats-card strong {
            display: block;
            font-size: 1.9rem;
            line-height: 1.1;
            color: var(--text);
        }
        .stats-card span {
            color: var(--muted);
            font-size: .95rem;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 14px;
            margin: 20px 0 18px;
            align-items: center;
        }
        .search {
            flex: 1 1 360px;
            display: flex;
            gap: 10px;
            align-items: center;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 10px 12px;
            box-shadow: var(--shadow);
        }
        .search input {
            border: 0;
            outline: none;
            width: 100%;
            background: transparent;
            font: inherit;
            color: var(--text);
        }
        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, .9fr);
            gap: 18px;
        }
        .panel {
            overflow: hidden;
        }
        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
        }
        .panel-header h2 {
            margin: 0;
            font-size: 1.1rem;
        }
        .panel-header small {
            color: var(--muted);
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 11px;
            text-decoration: none;
            color: var(--text);
            background: #fff;
            font-weight: 700;
        }
        .action:hover {
            border-color: var(--primary);
        }
        .side-form {
            padding: 18px;
        }
        .form-grid {
            display: grid;
            gap: 14px;
        }
        .field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }
        .field input, .field select, .field textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 11px 12px;
            font: inherit;
            background: #fff;
            color: var(--text);
            box-sizing: border-box;
        }
        .field input[readonly] {
            background: #f6f9f4;
            color: #526051;
        }
        .meta-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .meta-item {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f8fbf6;
            color: #415041;
        }
        @media (max-width: 1024px) {
            .hero, .layout {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 720px) {
            .page { padding: 18px 12px 28px; }
            .hero-card, .panel, .stats-card { border-radius: 16px; }
            tbody td, thead th { padding-left: 14px; padding-right: 14px; }
        }
    </style>
    <link rel="stylesheet" href="styles/tables.css">
</head>
<body>
    <div class="page">
        <section class="hero">
            <div class="hero-card">
                <div class="eyebrow">
                    <i class="fa-solid fa-vial"></i>
                    Catálogo de tipos de muestra
                </div>
                <h1>Administración de muestras por módulo</h1>
                <p>
                    Aquí puedes revisar los tipos de muestra del laboratorio, editar su nombre visible y activar o
                    desactivar su uso en los formularios de solicitud.
                </p>
                <div class="hero-actions">
                    <a class="btn btn-secondary" href="index.php">
                        <i class="fa-solid fa-arrow-left"></i>
                        Volver al inicio
                    </a>
                    <a class="btn btn-secondary" href="catalogo_analisis.php">
                        <i class="fa-solid fa-table-list"></i>
                        Ir al catálogo de análisis
                    </a>
                    <span class="btn btn-muted" title="Pendiente de implementación">
                        <i class="fa-solid fa-plus"></i>
                        Nuevo tipo de muestra
                    </span>
                </div>
            </div>

            <div class="stats">
                <div class="stats-card">
                    <strong><?= (int) $total ?></strong>
                    <span>Tipos registrados</span>
                </div>
                <div class="stats-card">
                    <strong><?= (int) $activos ?></strong>
                    <span>Tipos activos</span>
                </div>
                <div class="stats-card">
                    <strong><?= (int) $inactivos ?></strong>
                    <span>Tipos inactivos</span>
                </div>
                <div class="stats-card">
                    <strong><?= (int) $analisisVinculados ?></strong>
                    <span>Análisis activos vinculados</span>
                </div>
            </div>
        </section>

        <div class="toolbar">
            <form class="search" method="get">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="text" name="q" value="<?= catalogoMuestrasE($query) ?>" placeholder="Buscar por nombre, prefijo o módulo...">
            </form>
            <a class="btn btn-secondary" href="catalogo_muestras.php">
                <i class="fa-solid fa-rotate-left"></i>
                Limpiar filtro
            </a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="notice <?= str_starts_with($message, 'Error:') ? 'error' : '' ?>">
                <?= catalogoMuestrasE($message) ?>
            </div>
        <?php endif; ?>

        <section class="layout">
            <article class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Tipos disponibles</h2>
                        <small>Las bajas se manejan como desactivación para mantener historial.</small>
                    </div>
                    <small>Actualizar nombre, no crear duplicados</small>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Prefijo</th>
                                <th>Análisis</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filas)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">No hay tipos de muestra para mostrar.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filas as $fila): ?>
                                    <?php
                                        $clave = (string) ($fila['clave'] ?? '');
                                        $visual = $sampleVisuals[$clave] ?? ['icon' => 'fa-vial', 'tone' => 'default'];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= catalogoMuestrasE((string) ($fila['nombre'] ?? '')) ?></strong><br>
                                            <small style="color:#6b7c68"><?= catalogoMuestrasE((string) ($fila['label_plural'] ?? '')) ?></small>
                                        </td>
                                        <td>
                                            <span class="pill pill-on">
                                                <i class="fa-solid fa-tag"></i>
                                                <?= catalogoMuestrasE(strtoupper((string) ($fila['prefijo'] ?? ''))) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= (int) ($fila['analisis_activos'] ?? 0) ?> activos / <?= (int) ($fila['total_analisis'] ?? 0) ?> total
                                        </td>
                                        <td>
                                            <?php if ((int) ($fila['activo'] ?? 1) === 1): ?>
                                                <span class="pill pill-on">
                                                    <i class="fa-solid fa-circle-check"></i>
                                                    Activo
                                                </span>
                                            <?php else: ?>
                                                <span class="pill pill-off">
                                                    <i class="fa-solid fa-circle-xmark"></i>
                                                    Inactivo
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <a class="action" href="catalogo_muestras.php?edit=<?= (int) $fila['id_tipo'] ?>">
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                    Editar
                                                </a>
                                                <form method="post" onsubmit="return confirm('¿Cambiar el estado de este tipo de muestra?');">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id_tipo" value="<?= (int) $fila['id_tipo'] ?>">
                                                    <input type="hidden" name="activo" value="<?= (int) ($fila['activo'] ?? 1) === 1 ? 0 : 1 ?>">
                                                    <button class="action" type="submit">
                                                        <i class="fa-solid fa-power-off"></i>
                                                        <?= (int) ($fila['activo'] ?? 1) === 1 ? 'Desactivar' : 'Activar' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <aside class="panel side-form">
                <div class="panel-header" style="padding:0 0 14px;border-bottom:0">
                    <div>
                        <h2><?= $editingRow ? 'Editar tipo de muestra' : 'Detalle del tipo' ?></h2>
                        <small><?= $editingRow ? 'Solo se edita el nombre visible y el estado.' : 'Selecciona una fila para editar.' ?></small>
                    </div>
                </div>

                <?php if ($editingRow): ?>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="id_tipo" value="<?= (int) $editingRow['id_tipo'] ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="field">
                            <label for="nombre">Nombre visible</label>
                            <input id="nombre" type="text" name="nombre" value="<?= catalogoMuestrasE((string) ($editingRow['nombre'] ?? '')) ?>" required>
                        </div>

                        <div class="field">
                            <label for="prefijo">Prefijo</label>
                            <input id="prefijo" type="text" value="<?= catalogoMuestrasE(strtoupper((string) ($editingRow['prefijo'] ?? ''))) ?>" readonly>
                        </div>

                        <div class="field">
                            <label for="activo">Estado</label>
                            <select id="activo" name="activo">
                                <option value="1" <?= (int) ($editingRow['activo'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option>
                                <option value="0" <?= (int) ($editingRow['activo'] ?? 1) === 0 ? 'selected' : '' ?>>Inactivo</option>
                            </select>
                        </div>

                        <div class="meta-list">
                            <div class="meta-item">
                                <span>Módulo</span>
                                <strong><?= catalogoMuestrasE((string) ($editingRow['label'] ?? '')) ?></strong>
                            </div>
                            <div class="meta-item">
                                <span>Análisis vinculados</span>
                                <strong><?= (int) ($editingRow['analisis_activos'] ?? 0) ?> / <?= (int) ($editingRow['total_analisis'] ?? 0) ?></strong>
                            </div>
                        </div>

                        <div class="hero-actions" style="margin-top:6px">
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-floppy-disk"></i>
                                Guardar cambios
                            </button>
                            <a class="btn btn-secondary" href="catalogo_muestras.php">
                                Cancelar
                            </a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="notice">
                        Selecciona un tipo de muestra de la tabla para editar su nombre o cambiar su estado.
                    </div>
                    <div class="meta-list">
                        <div class="meta-item">
                            <span>Nota</span>
                            <strong>La creación de nuevos tipos queda pendiente</strong>
                        </div>
                        <div class="meta-item">
                            <span>Reflejo en formularios</span>
                            <strong>Se actualiza en solicitudes activas</strong>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>
        </section>
    </div>
</body>
</html>
