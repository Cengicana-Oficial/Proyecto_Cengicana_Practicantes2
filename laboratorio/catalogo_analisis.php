<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/catalogo_analisis_helper.php';
require_once __DIR__ . '/includes/shell_sidebar.php';

lab_require_permission('laboratorio.catalogo_analisis.ver');

function catalogoAnalisisE($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function catalogoAnalisisNumeroNullable(string $campo): ?float
{
    $valor = trim((string) ($_POST[$campo] ?? ''));
    if ($valor === '') {
        return null;
    }
    $valor = str_replace(',', '.', $valor);
    if (!is_numeric($valor)) {
        throw new InvalidArgumentException('Los límites deben contener valores numéricos válidos.');
    }
    return (float) $valor;
}

function catalogoAnalisisFormatoNumero($valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    $numero = (float) $valor;
    return rtrim(rtrim(number_format($numero, 6, '.', ''), '0'), '.');
}

function catalogoAnalisisRango(array $fila): string
{
    $minimo = $fila['limite_min'] ?? null;
    $maximo = $fila['limite_max'] ?? null;
    $unidad = trim((string) ($fila['unidad'] ?? ''));
    if ($minimo === null && $maximo === null) {
        return 'Sin límite definido';
    }
    if ($minimo !== null && $maximo !== null) {
        $texto = catalogoAnalisisFormatoNumero($minimo) . ' – ' . catalogoAnalisisFormatoNumero($maximo);
    } elseif ($minimo !== null) {
        $texto = '≥ ' . catalogoAnalisisFormatoNumero($minimo);
    } else {
        $texto = '≤ ' . catalogoAnalisisFormatoNumero($maximo);
    }
    return trim($texto . ' ' . $unidad);
}

function catalogoAnalisisSvg(string $icono): string
{
    $trazos = [
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/>',
        'editar' => '<path d="m4 20 4.2-1 10.6-10.6a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/><path d="m14.5 6.7 2.8 2.8"/>',
        'cerrar' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'guardar' => '<path d="M5 3h12l2 2v16H5zM8 3v6h8V3M8 21v-7h8v7"/>',
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    ];
    return '<svg class="analysis-control-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($trazos[$icono] ?? $trazos['info']) . '</svg>';
}

$canEdit = lab_can('laboratorio.analisis.editar');
$mensaje = '';
$errorMensaje = '';
$schemaMensaje = '';
$msg = trim((string) ($_GET['msg'] ?? ''));
$mensajes = [
    'updated' => 'El análisis se actualizó correctamente.',
    'deleted' => 'El análisis se desactivó correctamente.',
    'activated' => 'El análisis se reactivó correctamente.',
];
$mensaje = $mensajes[$msg] ?? '';

if (empty($_SESSION['catalogo_analisis_csrf'])) {
    $_SESSION['catalogo_analisis_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lab_require_permission('laboratorio.analisis.editar');
    try {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['catalogo_analisis_csrf'], $token)) {
            throw new RuntimeException('La sesión del editor expiró. Recarga la página e inténtalo nuevamente.');
        }

        $idTipo = (int) ($_POST['id_tipo'] ?? 0);
        $idTipoMuestra = (int) ($_POST['id_tipo_muestra'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $activo = isset($_POST['activo']) ? 1 : 0;
        if ($idTipo <= 0 || $idTipoMuestra <= 0 || $nombre === '') {
            throw new RuntimeException('Completa el tipo de muestra y el nombre antes de guardar.');
        }

        $tipoValido = false;
        foreach (labCatalogoAnalisisTipoMuestraOptions($conexion) as $opcion) {
            if ((int) $opcion['id_tipo'] === $idTipoMuestra) {
                $tipoValido = true;
                break;
            }
        }
        if (!$tipoValido) {
            throw new RuntimeException('El tipo de muestra seleccionado no existe.');
        }

        $limiteMin = catalogoAnalisisNumeroNullable('limite_min');
        $limiteMax = catalogoAnalisisNumeroNullable('limite_max');
        if ($limiteMin !== null && $limiteMax !== null && $limiteMin > $limiteMax) {
            throw new RuntimeException('El límite mínimo no puede ser mayor que el límite máximo.');
        }
        $tiempoTexto = trim((string) ($_POST['tiempo_estimado_min'] ?? ''));
        $tiempo = $tiempoTexto === '' ? null : filter_var($tiempoTexto, FILTER_VALIDATE_INT);
        if ($tiempoTexto !== '' && ($tiempo === false || $tiempo < 0)) {
            throw new RuntimeException('El tiempo estimado debe ser un número entero positivo.');
        }

        labCatalogoAnalisisGuardar($conexion, $idTipo, $idTipoMuestra, $nombre, $activo, [
            'metodo' => $_POST['metodo'] ?? '',
            'norma' => $_POST['norma'] ?? '',
            'equipo_default' => $_POST['equipo_default'] ?? '',
            'unidad' => $_POST['unidad'] ?? '',
            'limite_min' => $limiteMin,
            'limite_max' => $limiteMax,
            'tiempo_estimado_min' => $tiempo,
        ]);
        header('Location: catalogo_analisis.php?msg=updated');
        exit;
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();
    }
}

try {
    labCatalogoAnalisisAsegurarEsquema($conexion);
    $tiposMuestra = labCatalogoAnalisisTipoMuestraOptions($conexion);
    $filas = labCatalogoAnalisisFilas($conexion, false);
} catch (Throwable $e) {
    $schemaMensaje = $e->getMessage();
    $tiposMuestra = [];
    $filas = [];
}

$catalogoJs = [];
foreach ($filas as $fila) {
    $catalogoJs[(int) $fila['id_tipo']] = [
        'id_tipo' => (int) $fila['id_tipo'],
        'id_tipo_muestra' => (int) $fila['id_tipo_muestra'],
        'nombre' => (string) $fila['nombre'],
        'activo' => (int) ($fila['activo'] ?? 1) === 1,
        'metodo' => (string) ($fila['metodo'] ?? ''),
        'norma' => (string) ($fila['norma'] ?? ''),
        'equipo_default' => (string) ($fila['equipo_default'] ?? ''),
        'unidad' => (string) ($fila['unidad'] ?? ''),
        'limite_min' => $fila['limite_min'] !== null ? catalogoAnalisisFormatoNumero($fila['limite_min']) : '',
        'limite_max' => $fila['limite_max'] !== null ? catalogoAnalisisFormatoNumero($fila['limite_max']) : '',
        'tiempo_estimado_min' => $fila['tiempo_estimado_min'] !== null ? (int) $fila['tiempo_estimado_min'] : '',
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de análisis</title>
    <link rel="stylesheet" href="styles/catalogo_analisis_template.css?v=<?= filemtime(__DIR__ . '/styles/catalogo_analisis_template.css') ?>">
    <link rel="stylesheet" href="css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('catalogo_analisis.php', 'Control de análisis', 'Catálogo maestro de métodos, equipos y límites'); ?>
    <div class="analysis-control-page">
        <nav class="analysis-control-tabs" aria-label="Secciones del catálogo">
            <button type="button" class="analysis-control-tab is-active">Análisis</button>
            <button type="button" class="analysis-control-tab" disabled title="Catálogo pendiente de integración">Equipos</button>
            <button type="button" class="analysis-control-tab" disabled title="Catálogo pendiente de integración">Métodos analíticos</button>
            <button type="button" class="analysis-control-tab" disabled title="Configuración pendiente de integración">Campos por tipo de muestra</button>
            <button type="button" class="analysis-control-tab" disabled title="Auditoría pendiente de integración">Auditoría</button>
        </nav>

        <?php if ($mensaje !== ''): ?>
            <div class="analysis-control-message is-success"><?= catalogoAnalisisSvg('info') ?><span><?= catalogoAnalisisE($mensaje) ?></span></div>
        <?php endif; ?>
        <?php if ($errorMensaje !== ''): ?>
            <div class="analysis-control-message is-error"><?= catalogoAnalisisSvg('info') ?><span><?= catalogoAnalisisE($errorMensaje) ?></span></div>
        <?php endif; ?>
        <?php if ($schemaMensaje !== ''): ?>
            <div class="analysis-control-message is-warning"><?= catalogoAnalisisSvg('info') ?><span><?= catalogoAnalisisE($schemaMensaje) ?></span></div>
        <?php endif; ?>

        <section class="analysis-control-alert">
            <?= catalogoAnalisisSvg('info') ?>
            <div><strong>Catálogo maestro:</strong> cada análisis vincula método, equipo por defecto, unidad y rango esperado. Estos datos permiten centralizar la configuración técnica sin modificar cada formulario individual.</div>
        </section>

        <section class="analysis-control-card">
            <header class="analysis-control-toolbar">
                <h2>Análisis · Método · Equipo · Límites</h2>
                <div class="analysis-control-filters">
                    <label class="analysis-control-search">
                        <?= catalogoAnalisisSvg('buscar') ?>
                        <span class="sr-only">Buscar análisis</span>
                        <input type="search" id="analysisSearch" placeholder="Buscar análisis, método o equipo…" autocomplete="off">
                    </label>
                    <label>
                        <span class="sr-only">Filtrar por tipo de muestra</span>
                        <select id="analysisTypeFilter">
                            <option value="">Todo tipo de muestra</option>
                            <?php foreach ($tiposMuestra as $tipo): ?>
                                <option value="<?= (int) $tipo['id_tipo'] ?>"><?= catalogoAnalisisE($tipo['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </header>

            <div class="analysis-control-table-wrap">
                <table class="analysis-control-table">
                    <thead><tr><th>Análisis</th><th>Tipo</th><th>Método / Norma</th><th>Equipo por defecto</th><th>Unidad</th><th>Rango esperado</th><th>Tiempo est.</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php if (empty($filas)): ?>
                            <tr class="analysis-control-empty"><td colspan="8">No hay análisis registrados en el catálogo.</td></tr>
                        <?php else: ?>
                            <?php foreach ($filas as $fila): ?>
                                <?php
                                $activo = (int) ($fila['activo'] ?? 1) === 1;
                                $textoBusqueda = mb_strtolower(implode(' ', [
                                    $fila['nombre'] ?? '',
                                    $fila['nombre_muestra'] ?? '',
                                    $fila['metodo'] ?? '',
                                    $fila['norma'] ?? '',
                                    $fila['equipo_default'] ?? '',
                                    $fila['unidad'] ?? '',
                                ]), 'UTF-8');
                                ?>
                                <tr class="analysis-control-row<?= $activo ? '' : ' is-inactive' ?>" data-type="<?= (int) $fila['id_tipo_muestra'] ?>" data-search="<?= catalogoAnalisisE($textoBusqueda) ?>">
                                    <td><strong><?= catalogoAnalisisE($fila['nombre']) ?></strong><?php if (!$activo): ?><small class="analysis-control-inactive">Inactivo</small><?php endif; ?></td>
                                    <td><span class="analysis-control-chip"><?= catalogoAnalisisE($fila['nombre_muestra'] ?: 'Sin tipo') ?></span></td>
                                    <td><?= catalogoAnalisisE($fila['metodo'] ?: 'Sin configurar') ?><small><?= catalogoAnalisisE($fila['norma'] ?: 'Sin norma') ?></small></td>
                                    <td><?= catalogoAnalisisE($fila['equipo_default'] ?: '—') ?></td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE($fila['unidad'] ?: '—') ?></td>
                                    <td class="analysis-control-range<?= ($fila['limite_min'] === null && $fila['limite_max'] === null) ? ' is-empty' : '' ?>"><?= catalogoAnalisisE(catalogoAnalisisRango($fila)) ?></td>
                                    <td class="analysis-control-muted"><?= $fila['tiempo_estimado_min'] !== null ? (int) $fila['tiempo_estimado_min'] . ' min' : '—' ?></td>
                                    <td class="analysis-control-actions">
                                        <?php if ($canEdit): ?>
                                            <button type="button" class="analysis-control-button" data-edit-analysis="<?= (int) $fila['id_tipo'] ?>"><?= catalogoAnalisisSvg('editar') ?> Editar</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="analysis-control-empty" id="analysisFilteredEmpty" hidden><td colspan="8">No hay análisis que coincidan con los filtros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <?php if ($canEdit): ?>
        <div class="analysis-control-modal" id="analysisModal" hidden>
            <div class="analysis-control-dialog" role="dialog" aria-modal="true" aria-labelledby="analysisModalTitle">
                <header class="analysis-control-modal-head">
                    <h2 id="analysisModalTitle">Editar análisis</h2>
                    <button type="button" class="analysis-control-close" data-close-analysis aria-label="Cerrar"><?= catalogoAnalisisSvg('cerrar') ?></button>
                </header>
                <form method="post" id="analysisForm">
                    <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                    <input type="hidden" name="id_tipo" id="analysisId">
                    <div class="analysis-control-modal-body">
                        <div class="analysis-control-form-grid">
                            <label class="analysis-control-field">
                                <span>Tipo de muestra</span>
                                <select name="id_tipo_muestra" id="analysisSampleType" required>
                                    <?php foreach ($tiposMuestra as $tipo): ?>
                                        <option value="<?= (int) $tipo['id_tipo'] ?>"><?= catalogoAnalisisE($tipo['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="analysis-control-field">
                                <span>Nombre del análisis</span>
                                <input type="text" name="nombre" id="analysisName" maxlength="255" required>
                            </label>
                            <label class="analysis-control-field">
                                <span>Método</span>
                                <input type="text" name="metodo" id="analysisMethod" maxlength="255" placeholder="Ej. Espectrofotometría UV-Vis">
                            </label>
                            <label class="analysis-control-field">
                                <span>Norma / referencia</span>
                                <input type="text" name="norma" id="analysisStandard" maxlength="255" placeholder="Ej. ISO 10390">
                            </label>
                            <label class="analysis-control-field">
                                <span>Equipo por defecto</span>
                                <input type="text" name="equipo_default" id="analysisEquipment" maxlength="255" placeholder="Ej. pH-metro de mesa">
                            </label>
                            <label class="analysis-control-field">
                                <span>Unidad</span>
                                <input type="text" name="unidad" id="analysisUnit" maxlength="80" placeholder="Ej. mg/L">
                            </label>
                            <label class="analysis-control-field">
                                <span>Límite mínimo</span>
                                <input type="number" name="limite_min" id="analysisMin" step="any">
                            </label>
                            <label class="analysis-control-field">
                                <span>Límite máximo</span>
                                <input type="number" name="limite_max" id="analysisMax" step="any">
                            </label>
                            <label class="analysis-control-field">
                                <span>Tiempo estimado (min)</span>
                                <input type="number" name="tiempo_estimado_min" id="analysisTime" min="0" step="1">
                            </label>
                            <label class="analysis-control-check">
                                <input type="checkbox" name="activo" id="analysisActive" value="1">
                                <span><strong>Análisis activo</strong><small>Visible en solicitudes y formularios nuevos.</small></span>
                            </label>
                        </div>
                    </div>
                    <footer class="analysis-control-modal-foot">
                        <button type="button" class="analysis-control-button" data-close-analysis>Cancelar</button>
                        <button type="submit" class="analysis-control-button is-primary"><?= catalogoAnalisisSvg('guardar') ?> Guardar cambios</button>
                    </footer>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php lab_shell_content_close(); ?>
<script>
(function () {
    var filter = document.getElementById('analysisTypeFilter');
    var search = document.getElementById('analysisSearch');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.analysis-control-row'));
    var empty = document.getElementById('analysisFilteredEmpty');
    var modal = document.getElementById('analysisModal');
    var catalog = <?= json_encode($catalogoJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function normalize(value) {
        return String(value || '').toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function applyFilters() {
        var type = filter ? filter.value : '';
        var term = normalize(search ? search.value.trim() : '');
        var visible = 0;
        rows.forEach(function (row) {
            var show = (!type || row.dataset.type === type) && (!term || normalize(row.dataset.search).indexOf(term) !== -1);
            row.hidden = !show;
            if (show) visible++;
        });
        if (empty) empty.hidden = visible !== 0;
    }

    function setValue(id, value) {
        var field = document.getElementById(id);
        if (field) field.value = value == null ? '' : value;
    }

    function openEditor(id) {
        if (!modal || !catalog[id]) return;
        var item = catalog[id];
        setValue('analysisId', item.id_tipo);
        setValue('analysisSampleType', item.id_tipo_muestra);
        setValue('analysisName', item.nombre);
        setValue('analysisMethod', item.metodo);
        setValue('analysisStandard', item.norma);
        setValue('analysisEquipment', item.equipo_default);
        setValue('analysisUnit', item.unidad);
        setValue('analysisMin', item.limite_min);
        setValue('analysisMax', item.limite_max);
        setValue('analysisTime', item.tiempo_estimado_min);
        document.getElementById('analysisActive').checked = !!item.activo;
        document.getElementById('analysisModalTitle').textContent = 'Editar análisis — ' + item.nombre;
        modal.hidden = false;
        document.body.classList.add('analysis-control-modal-open');
        document.getElementById('analysisName').focus();
    }

    function closeEditor() {
        if (!modal) return;
        modal.hidden = true;
        document.body.classList.remove('analysis-control-modal-open');
    }

    if (filter) filter.addEventListener('change', applyFilters);
    if (search) search.addEventListener('input', applyFilters);
    document.addEventListener('click', function (event) {
        var edit = event.target.closest('[data-edit-analysis]');
        if (edit) openEditor(edit.dataset.editAnalysis);
        if (event.target.closest('[data-close-analysis]') || event.target === modal) closeEditor();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) closeEditor();
    });
})();
</script>
</body>
</html>
