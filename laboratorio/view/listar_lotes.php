<?php
require_once __DIR__ . '/../includes/auth.php';

lab_require_permission('laboratorio.lotes.ver');

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/solicitud_formulario_helpers.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../includes/muestra_identificacion_helper.php';

lab_ensure_schema_safe(fn() => asegurarColumnasFirmasSolicitud($conexion), 'solicitud_firmas');
lab_ensure_schema_safe(fn() => labMuestraIdentificacionEnsureSchema($conexion), 'muestra_identificacion');

if (empty($_SESSION['muestra_identificacion_csrf'])) {
    $_SESSION['muestra_identificacion_csrf'] = bin2hex(random_bytes(32));
}

function ident_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ident_svg(string $name): string
{
    $paths = [
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM19 14h2M14 19h2M19 19h2v2h-2z"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'barcode' => '<path d="M3 5v14M7 5v14M10 5v14M14 5v14M16 5v14M20 5v14M18 5v14"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'warn' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'save' => '<path d="M5 4h12l2 2v14H5zM8 4v6h8V4M8 16h8"/>',
    ];

    return '<svg class="ident-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['info']) . '</svg>';
}

function ident_estado(array $muestra): string
{
    if (empty($muestra['id_identificacion'])) {
        return 'Recibida · pendiente de identificación';
    }
    $requeridos = (int) ($muestra['analisis_requeridos'] ?? 0);
    $ingresados = (int) ($muestra['analisis_ingresados'] ?? 0);
    if ($requeridos > 0 && $ingresados >= $requeridos) {
        return 'Identificada · análisis completos';
    }
    if ($ingresados > 0) {
        return 'Identificada · análisis en curso';
    }
    return 'Identificada · pendiente de análisis';
}

function ident_tipo_muestra_clave(array $registro): string
{
    $tipo = trim((string) ($registro['tipo_muestra'] ?? ''));
    $normalizado = function_exists('mb_strtolower') ? mb_strtolower($tipo, 'UTF-8') : strtolower($tipo);
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizado);
        if ($ascii !== false) {
            $normalizado = strtolower($ascii);
        }
    }

    if (strpos($normalizado, 'foliar') !== false) {
        return 'foliar';
    }
    if (strpos($normalizado, 'suelo') !== false) {
        return 'suelo';
    }
    if (strpos($normalizado, 'agua') !== false) {
        return 'aguas';
    }
    if (strpos($normalizado, 'cana') !== false) {
        return 'cana';
    }

    $codigo = strtoupper(trim((string) ($registro['codigo_lab'] ?? '')));
    $prefijo = substr($codigo, 0, 1);
    return ['F' => 'foliar', 'S' => 'suelo', 'A' => 'aguas', 'C' => 'cana'][$prefijo] ?? 'otro';
}

$usuarioActual = lab_current_user();
$nombreUsuario = trim((string) ($usuarioActual['nombre'] ?? '')) ?: 'Usuario de laboratorio';
$postError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['muestra_identificacion_csrf'], $token)) {
        $postError = 'La sesión del formulario venció. Recargue la página e inténtelo nuevamente.';
    } else {
        try {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'crear') {
                $creada = labMuestraIdentificacionCrear(
                    $conexion,
                    (string) ($_POST['codigo_nuevo'] ?? ''),
                    (int) ($_POST['id_solicitud'] ?? 0),
                    (string) ($_POST['cultivo_nuevo'] ?? ''),
                    $nombreUsuario
                );
                $codigoCreado = (string) ($creada['codigo_lab'] ?? '');
                $_SESSION['muestra_identificacion_flash'] = ['tipo' => 'info', 'mensaje' => 'Identificación creada. Complete el formulario y guarde sus datos.'];
                header('Location: listar_lotes.php?muestra=' . rawurlencode($codigoCreado));
                exit;
            }

            if ($accion === 'guardar') {
                $codigoGuardado = labMuestraIdentificacionGuardar(
                    $conexion,
                    (int) ($_POST['id_muestra'] ?? 0),
                    $_POST,
                    $nombreUsuario
                );
                $_SESSION['muestra_identificacion_flash'] = ['tipo' => 'ok', 'mensaje' => 'Identificación guardada correctamente para ' . $codigoGuardado . '.'];
                header('Location: listar_lotes.php?muestra=' . rawurlencode($codigoGuardado));
                exit;
            }

            throw new InvalidArgumentException('La acción solicitada no es válida.');
        } catch (Throwable $e) {
            $postError = $e->getMessage();
        }
    }
}

$flash = $_SESSION['muestra_identificacion_flash'] ?? null;
unset($_SESSION['muestra_identificacion_flash']);

$codigoBusqueda = labMuestraIdentificacionCodigo((string) ($_GET['muestra'] ?? $_GET['buscar'] ?? ''));
$muestraActual = $codigoBusqueda !== '' ? labMuestraIdentificacionBuscar($conexion, $codigoBusqueda) : null;
$solicitudes = labMuestraIdentificacionSolicitudes($conexion);
$baseIdentificaciones = labMuestraIdentificacionBase($conexion);
$tiposFiltro = [
    'foliar' => ['label' => 'Foliar', 'prefix' => 'F'],
    'suelo' => ['label' => 'Suelo', 'prefix' => 'S'],
    'aguas' => ['label' => 'Aguas', 'prefix' => 'A'],
    'cana' => ['label' => 'Caña', 'prefix' => 'C'],
];

$cultivos = ['Caña de azúcar', 'Maíz', 'Café', 'Banano', 'Palma africana', 'Hortalizas', 'Otro'];
$cultivoActual = trim((string) ($muestraActual['cultivo'] ?? ''));
if ($cultivoActual !== '' && !in_array($cultivoActual, $cultivos, true)) {
    array_unshift($cultivos, $cultivoActual);
}

$tomadores = [];
foreach ([$muestraActual['tomado_por'] ?? '', $muestraActual['responsable_envio'] ?? '', $muestraActual['ingresado_por'] ?? '', $muestraActual['recibido_por'] ?? '', $nombreUsuario] as $tomador) {
    $tomador = trim((string) $tomador);
    if ($tomador !== '') {
        $tomadores[$tomador] = $tomador;
    }
}

$fechaMuestreo = (string) ($muestraActual['fecha_muestreo'] ?? '');
if ($fechaMuestreo === '' && !empty($muestraActual['fecha_muestreo_solicitud'])) {
    $fechaMuestreo = (string) $muestraActual['fecha_muestreo_solicitud'] . ' 00:00:00';
}
$fechaMuestreoInput = $fechaMuestreo !== '' ? date('Y-m-d\TH:i', strtotime($fechaMuestreo)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identificación de muestra</title>
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
    <link rel="stylesheet" href="../css/listar_lotes.css?v=<?= (int) filemtime(__DIR__ . '/../css/listar_lotes.css') ?>">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('listar_lotes.php', 'Identificación de muestra', 'Registro único mediante QR, código de barras o captura manual'); ?>
<main class="sample-ident-page" data-ident-page>
    <p class="ident-intro">
        Registro único de identificación de muestras — un solo formulario por pestañas, válido para cualquier tipo de muestra. Identifícala escaneando el <strong>QR</strong> o <strong>código de barras</strong> impreso en la boleta; si no cuentas con lector, <strong>tabula el código manualmente</strong>. La identificación queda enlazada al lote y al informe de resultados.
    </p>

    <section class="ident-card ident-step-one" aria-labelledby="identificarTitulo">
        <h2 id="identificarTitulo">1. Identificar muestra</h2>
        <p>Elige cómo identificar la muestra — ambos caminos consultan y guardan en la misma base de identificación.</p>

        <div class="ident-segment" role="tablist" aria-label="Modo de identificación">
            <button type="button" class="is-active" data-ident-mode="manual" role="tab" aria-selected="true"><?= ident_svg('edit') ?> Ingreso manual</button>
            <button type="button" data-ident-mode="scanner" role="tab" aria-selected="false"><?= ident_svg('qr') ?> Escanear QR / código de barras</button>
        </div>

        <div data-ident-panel="manual">
            <form method="GET" class="ident-search-form">
                <label class="ident-field">
                    <span>Código de muestra</span>
                    <input type="text" name="muestra" value="<?= ident_e($codigoBusqueda) ?>" placeholder="Ej. S001-07-26" autocomplete="off" autofocus>
                </label>
                <button class="ident-btn ident-btn--primary" type="submit"><?= ident_svg('search') ?> Buscar</button>
            </form>
            <p class="ident-help">Tabula el código impreso en la boleta y presiona “Buscar”; si no existe, podrás crear una nueva identificación.</p>
        </div>

        <div data-ident-panel="scanner" hidden>
            <div class="ident-scanner-actions">
                <button class="ident-btn ident-btn--dark" type="button" data-open-scanner><?= ident_svg('camera') ?> Escanear con cámara</button>
                <button class="ident-btn ident-btn--ghost" type="button" data-focus-reader><?= ident_svg('qr') ?> Escanear QR</button>
                <button class="ident-btn ident-btn--ghost" type="button" data-focus-reader><?= ident_svg('barcode') ?> Leer código de barras</button>
            </div>
            <p class="ident-help">Si la cámara o el lector fallan, cambia a “Ingreso manual”; no se pierde información.</p>
        </div>

        <?php if ($postError !== ''): ?>
            <div class="ident-alert ident-alert--warn"><?= ident_svg('warn') ?><div><?= ident_e($postError) ?></div></div>
        <?php elseif (is_array($flash)): ?>
            <div class="ident-alert ident-alert--<?= ($flash['tipo'] ?? '') === 'ok' ? 'ok' : 'info' ?>"><?= ident_svg('info') ?><div><?= ident_e($flash['mensaje'] ?? '') ?></div></div>
        <?php elseif ($codigoBusqueda !== '' && $muestraActual): ?>
            <div class="ident-alert ident-alert--info"><?= ident_svg('info') ?><div>Identificación existente cargada: <strong><?= ident_e($muestraActual['codigo_lab']) ?></strong> · Lote <?= ident_e($muestraActual['codigo_lote']) ?> · <?= ident_e($muestraActual['cliente'] ?: 'Sin cliente') ?></div></div>
        <?php elseif ($codigoBusqueda !== ''): ?>
            <div class="ident-alert ident-alert--warn"><?= ident_svg('warn') ?><div>No existe ninguna muestra con el código <strong><?= ident_e($codigoBusqueda) ?></strong>. Puedes crearla a continuación.</div></div>
        <?php endif; ?>

        <?php if ($codigoBusqueda !== '' && !$muestraActual): ?>
            <form method="POST" class="ident-new-sample" data-new-sample-form>
                <input type="hidden" name="csrf_token" value="<?= ident_e($_SESSION['muestra_identificacion_csrf']) ?>">
                <input type="hidden" name="accion" value="crear">
                <p><strong>Ingreso manual de una muestra nueva</strong> — completa estos datos mínimos; el resto se llena en el formulario de identificación.</p>
                <div class="ident-form-grid">
                    <label class="ident-field"><span>Código de la nueva muestra</span><input name="codigo_nuevo" value="<?= ident_e($codigoBusqueda) ?>" required></label>
                    <label class="ident-field"><span>Lote donde se registrará</span>
                        <select name="id_solicitud" data-request-select required>
                            <option value="">Seleccione un lote…</option>
                            <?php foreach ($solicitudes as $solicitud): ?>
                                <option value="<?= (int) $solicitud['id_solicitud'] ?>" data-type="<?= ident_e($solicitud['tipo_muestra']) ?>">
                                    <?= ident_e($solicitud['codigo_lote']) ?> · <?= ident_e($solicitud['cliente']) ?> (<?= ident_e($solicitud['tipo_muestra']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ident-field"><span>Tipo de muestra</span><input data-request-type value="Seleccione un lote" disabled></label>
                    <label class="ident-field"><span>Cultivo</span>
                        <select name="cultivo_nuevo"><?php foreach ($cultivos as $cultivo): ?><option><?= ident_e($cultivo) ?></option><?php endforeach; ?></select>
                    </label>
                </div>
                <button class="ident-btn ident-btn--primary" type="submit">Crear identificación y continuar</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($muestraActual): ?>
        <section class="ident-card ident-form-card" id="formulario-identificacion">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= ident_e($_SESSION['muestra_identificacion_csrf']) ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id_muestra" value="<?= (int) $muestraActual['id_muestra'] ?>">

                <header class="ident-form-head">
                    <div>
                        <h2>2. Formulario de identificación</h2>
                        <strong><?= ident_e($muestraActual['codigo_lab']) ?> · <?= ident_e($muestraActual['tipo_muestra']) ?> · <?= ident_e($cultivoActual ?: 'Sin cultivo') ?></strong>
                        <p><?= ident_e(ident_estado($muestraActual)) ?></p>
                    </div>
                    <a class="ident-btn ident-btn--ghost ident-btn--small" href="trazabilidad_view.php?id_lote=<?= (int) $muestraActual['id_lote'] ?>"><?= ident_svg('clock') ?> Ver trazabilidad completa</a>
                </header>

                <div class="ident-tabs" role="tablist" aria-label="Secciones de identificación">
                    <button type="button" class="is-active" data-ident-tab="general" aria-selected="true">Información general</button>
                    <button type="button" data-ident-tab="ubicacion" aria-selected="false">Ubicación</button>
                    <button type="button" data-ident-tab="muestreo" aria-selected="false">Muestreo</button>
                    <button type="button" data-ident-tab="fisica" aria-selected="false">Información física</button>
                    <button type="button" data-ident-tab="observaciones" aria-selected="false">Observaciones</button>
                </div>

                <div class="ident-tab-content">
                    <section class="ident-tab-panel is-active" data-ident-tab-panel="general">
                        <div class="ident-form-grid">
                            <label class="ident-field"><span>Código de muestra</span><input value="<?= ident_e($muestraActual['codigo_lab']) ?>" disabled></label>
                            <label class="ident-field"><span>Tipo de muestra</span><input value="<?= ident_e($muestraActual['tipo_muestra']) ?>" disabled></label>
                            <label class="ident-field"><span>Cliente / Ingenio</span><input value="<?= ident_e($muestraActual['cliente'] ?: 'Sin cliente') ?>" disabled></label>
                            <label class="ident-field"><span>Cultivo</span><select name="cultivo"><?php foreach ($cultivos as $cultivo): ?><option value="<?= ident_e($cultivo) ?>" <?= $cultivo === $cultivoActual ? 'selected' : '' ?>><?= ident_e($cultivo) ?></option><?php endforeach; ?></select></label>
                            <label class="ident-field"><span>Estado de la muestra</span><input value="<?= ident_e(ident_estado($muestraActual)) ?>" disabled></label>
                            <label class="ident-field"><span>Lote asociado</span><input value="<?= ident_e($muestraActual['codigo_lote']) ?>" disabled></label>
                            <label class="ident-field ident-field--full"><span>Lectura QR / código de barras</span><input name="lectura_codigo" value="<?= ident_e($muestraActual['lectura_codigo'] ?: $muestraActual['codigo_lab']) ?>" placeholder="Valor leído del QR o código de barras"></label>
                        </div>
                    </section>

                    <section class="ident-tab-panel" data-ident-tab-panel="ubicacion" hidden>
                        <div class="ident-form-grid">
                            <label class="ident-field"><span>Finca</span><input value="No registrada en recepción" disabled></label>
                            <label class="ident-field"><span>Bloque</span><input name="bloque" value="<?= ident_e($muestraActual['bloque']) ?>" placeholder="Ej. B-04"></label>
                            <label class="ident-field"><span>Parcela</span><input name="parcela" value="<?= ident_e($muestraActual['parcela']) ?>" placeholder="Ej. P-12"></label>
                            <label class="ident-field"><span>Punto de muestreo</span><input name="punto_muestreo" value="<?= ident_e($muestraActual['punto_muestreo']) ?>"></label>
                            <label class="ident-field ident-field--full"><span>Georreferencia (lat, long)</span><input name="georreferencia" value="<?= ident_e($muestraActual['georreferencia']) ?>" placeholder="Ej. 14.3417, -90.9500"></label>
                        </div>
                    </section>

                    <section class="ident-tab-panel" data-ident-tab-panel="muestreo" hidden>
                        <div class="ident-form-grid">
                            <label class="ident-field"><span>Fecha y hora de muestreo</span><input type="datetime-local" name="fecha_muestreo" value="<?= ident_e($fechaMuestreoInput) ?>"></label>
                            <label class="ident-field"><span>Repetición</span><input name="repeticion" value="<?= ident_e($muestraActual['repeticion']) ?>" placeholder="Ej. R1"></label>
                            <label class="ident-field"><span>Variedad</span><input name="variedad" value="<?= ident_e($muestraActual['variedad']) ?>" placeholder="Ej. CP 72-2086"></label>
                            <label class="ident-field"><span>Corte</span><input name="corte" value="<?= ident_e($muestraActual['corte']) ?>" placeholder="Ej. Corte 2"></label>
                            <label class="ident-field"><span>Tratamiento</span><input name="tratamiento" value="<?= ident_e($muestraActual['tratamiento']) ?>" placeholder="Ej. T3 - Fertilización foliar"></label>
                            <label class="ident-field"><span>Tomada / recibida por</span><select name="tomado_por"><option value="">Seleccione…</option><?php foreach ($tomadores as $tomador): ?><option value="<?= ident_e($tomador) ?>" <?= $tomador === ($muestraActual['tomado_por'] ?? '') ? 'selected' : '' ?>><?= ident_e($tomador) ?></option><?php endforeach; ?></select></label>
                        </div>
                    </section>

                    <section class="ident-tab-panel" data-ident-tab-panel="fisica" hidden>
                        <div class="ident-form-grid">
                            <label class="ident-field"><span>Peso / cantidad recibida</span><input type="number" step="any" name="peso_cantidad" value="<?= ident_e($muestraActual['peso_cantidad']) ?>" placeholder="Ej. 500"></label>
                            <label class="ident-field"><span>Unidad</span><select name="unidad_peso"><?php foreach (['g', 'kg', 'ml', 'l', 'unidades'] as $unidad): ?><option <?= $unidad === ($muestraActual['unidad_peso'] ?: 'g') ? 'selected' : '' ?>><?= ident_e($unidad) ?></option><?php endforeach; ?></select></label>
                            <label class="ident-field"><span>Contenedor / envase</span><select name="contenedor"><?php foreach (['Bolsa plástica sellada', 'Bolsa de papel kraft', 'Frasco estéril', 'Hielera con refrigerante', 'Envase del cliente'] as $contenedor): ?><option <?= $contenedor === ($muestraActual['contenedor'] ?: 'Bolsa plástica sellada') ? 'selected' : '' ?>><?= ident_e($contenedor) ?></option><?php endforeach; ?></select></label>
                            <label class="ident-field"><span>Condición física al recibir</span><select name="condicion_fisica"><?php foreach (['Buena', 'Regular', 'Deficiente'] as $condicion): ?><option <?= $condicion === ($muestraActual['condicion_fisica'] ?: 'Buena') ? 'selected' : '' ?>><?= ident_e($condicion) ?></option><?php endforeach; ?></select></label>
                            <label class="ident-field"><span>Temperatura de recepción (°C)</span><input type="number" step="any" name="temperatura_recepcion" value="<?= ident_e($muestraActual['temperatura_recepcion']) ?>" placeholder="Ej. 4"></label>
                        </div>
                    </section>

                    <section class="ident-tab-panel" data-ident-tab-panel="observaciones" hidden>
                        <label class="ident-field ident-field--full"><span>Observaciones</span><textarea name="observaciones" placeholder="Estado del empaque, incidencias durante el traslado, etc."><?= ident_e($muestraActual['observaciones']) ?></textarea></label>
                    </section>
                </div>

                <footer class="ident-form-actions">
                    <a class="ident-btn ident-btn--ghost" href="listar_lotes.php">Cancelar</a>
                    <button class="ident-btn ident-btn--primary" type="submit"><?= ident_svg('save') ?> Guardar identificación</button>
                </footer>
            </form>
        </section>
    <?php endif; ?>

    <section class="ident-base-section">
        <div class="ident-section-title"><h2>Base de identificación de muestras</h2><span data-base-count><?= count($baseIdentificaciones) ?> registradas</span></div>
        <p>Registro consolidado de todas las muestras identificadas — permite ubicar rápidamente muestra, lote, lectura QR o código de barras y datos de campo.</p>
        <div class="ident-table-toolbar">
            <label class="ident-table-search"><?= ident_svg('search') ?><input type="search" placeholder="Buscar por muestra, lote, lectura QR, parcela…" data-base-filter></label>
            <label class="ident-type-filter">
                <span>Tipo de muestra</span>
                <select data-base-type-filter aria-label="Filtrar por tipo de muestra">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tiposFiltro as $clave => $meta): ?><option value="<?= ident_e($clave) ?>"><?= ident_e($meta['prefix'] . ' · ' . $meta['label']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="ident-table-wrap">
            <table>
                <thead><tr><th>Número lab</th><th>Lote</th><th>Lectura QR / código de barras</th><th>Parcela / Tratamiento / Repetición</th><th>Observaciones</th></tr></thead>
                <tbody data-base-body>
                <?php if (!$baseIdentificaciones): ?><tr data-empty-row><td colspan="5">Aún no hay muestras identificadas.</td></tr><?php endif; ?>
                <?php foreach ($baseIdentificaciones as $registro): ?>
                    <?php
                        $campo = implode(' / ', array_filter([$registro['parcela'], $registro['tratamiento'], $registro['repeticion']])) ?: '—';
                        $tipoClave = ident_tipo_muestra_clave($registro);
                        $tipoMeta = $tiposFiltro[$tipoClave] ?? ['label' => ($registro['tipo_muestra'] ?: 'Otro'), 'prefix' => '?'];
                    ?>
                    <tr data-base-row data-type="<?= ident_e($tipoClave) ?>" data-search="<?= ident_e(strtolower(implode(' ', [$registro['codigo_lab'], $registro['codigo_lote'], $registro['lectura_codigo'], $campo, $registro['observaciones'], $registro['cliente'], $tipoMeta['label'], $tipoMeta['prefix']]))) ?>">
                        <td>
                            <div class="ident-lab-number"><span class="ident-prefix ident-prefix--<?= ident_e($tipoClave) ?>"><?= ident_e($tipoMeta['prefix']) ?></span><a href="listar_lotes.php?muestra=<?= rawurlencode((string) $registro['codigo_lab']) ?>"><?= ident_e($registro['codigo_lab']) ?></a></div>
                            <small class="ident-type-caption"><?= ident_e($tipoMeta['label']) ?></small>
                        </td>
                        <td><?= ident_e($registro['codigo_lote']) ?><small><?= ident_e($registro['cliente']) ?></small></td>
                        <td><?= ident_e($registro['lectura_codigo'] ?: $registro['codigo_lab']) ?></td>
                        <td><?= ident_e($campo) ?></td>
                        <td><?= ident_e($registro['observaciones'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="ident-modal" data-scanner-modal hidden>
    <div class="ident-modal-card" role="dialog" aria-modal="true" aria-labelledby="scannerTitle">
        <header><h2 id="scannerTitle">Escanear QR / código de barras</h2><button type="button" data-close-scanner aria-label="Cerrar"><?= ident_svg('close') ?></button></header>
        <div class="ident-modal-body">
            <div class="ident-alert ident-alert--info" data-scanner-status><?= ident_svg('info') ?><div>Solicitando acceso a la cámara…</div></div>
            <video data-scanner-video playsinline autoplay muted></video>
            <canvas data-scanner-canvas hidden></canvas>
        </div>
        <footer><button class="ident-btn ident-btn--ghost" type="button" data-close-scanner>Cerrar</button></footer>
    </div>
</div>

<form method="GET" data-scanner-submit hidden><input name="muestra" data-scanner-value></form>
<script src="../js/identificacion_muestra.js?v=<?= (int) filemtime(__DIR__ . '/../js/identificacion_muestra.js') ?>"></script>
<?php lab_shell_content_close(); ?>
</body>
</html>
