<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/catalogo_analisis_helper.php';
require_once __DIR__ . '/includes/catalogo_campos_muestra_helper.php';
require_once __DIR__ . '/includes/recepcion_catalogo_helper.php';
require_once __DIR__ . '/includes/catalogo_control_helper.php';
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

function catalogoAnalisisFecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') return '—';
    $ts = strtotime($fecha);
    return $ts ? date(strlen($fecha) > 10 ? 'd/m/Y H:i' : 'd/m/Y', $ts) : $fecha;
}

/** Grupo con que aparece el análisis en Recepción: el elegido en el catálogo o el del formato de recepción. */
function catalogoAnalisisGrupo(string $clave, string $nombre, string $grupoRecepcion): string
{
    if (trim($grupoRecepcion) !== '') return trim($grupoRecepcion);
    $def = labRecepcionAnalisisDefinicion($clave, $nombre);
    $nombreRec = $def['nombre'] ?? $nombre;
    foreach (labRecepcionCatalogoConfig()[$clave]['grupos'] ?? [] as $g) {
        if ($g['analisis'] === null || in_array($nombreRec, $g['analisis'], true)) return (string) $g['nombre'];
    }
    return 'Otros análisis';
}

function catalogoAnalisisSvg(string $icono): string
{
    $trazos = [
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/>',
        'editar' => '<path d="m4 20 4.2-1 10.6-10.6a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/><path d="m14.5 6.7 2.8 2.8"/>',
        'cerrar' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'guardar' => '<path d="M5 3h12l2 2v16H5zM8 3v6h8V3M8 21v-7h8v7"/>',
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'aviso' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    ];
    return '<svg class="analysis-control-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($trazos[$icono] ?? $trazos['info']) . '</svg>';
}

$canEdit = lab_can('laboratorio.analisis.editar');
$usuarioActual = lab_current_user();
$nombreUsuario = trim((string) ($usuarioActual['nombre'] ?? $usuarioActual['correo'] ?? '')) ?: 'Usuario';
$mensaje = '';
$errorMensaje = '';
$schemaMensaje = '';
$msg = trim((string) ($_GET['msg'] ?? ''));
$mensajes = [
    'created' => 'El análisis se creó correctamente.',
    'created_on' => 'El análisis se creó y ya está disponible para solicitar en Recepción.',
    'created_off' => 'El análisis se creó. Queda oculto en Recepción hasta que lo marque como disponible.',
    'updated' => 'El análisis se actualizó correctamente.',
    'disponible' => 'El análisis ya está disponible para solicitar en Recepción.',
    'oculto' => 'El análisis quedó oculto en Recepción. Las solicitudes existentes se conservan.',
    'deleted' => 'El análisis se desactivó correctamente.',
    'activated' => 'El análisis se reactivó correctamente.',
    'equipo_created' => 'El equipo se registró correctamente.',
    'equipo_updated' => 'El equipo se actualizó correctamente.',
    'metodo_created' => 'El método se creó y se asignó a los análisis marcados.',
    'metodo_updated' => 'El método se actualizó en los análisis marcados.',
    'campo_created' => 'El campo se creó correctamente.',
    'campo_updated' => 'El campo se actualizó correctamente.',
    'campo_deleted' => 'El campo se eliminó correctamente.',
];
$mensaje = $mensajes[$msg] ?? '';

$tabsValidos = ['analisis', 'equipos', 'metodos', 'campos', 'auditoria'];
$tab = in_array((string) ($_GET['tab'] ?? ''), $tabsValidos, true) ? (string) $_GET['tab'] : 'analisis';

if (empty($_SESSION['catalogo_analisis_csrf'])) {
    $_SESSION['catalogo_analisis_csrf'] = bin2hex(random_bytes(32));
}

try {
    labCatalogoAnalisisAsegurarEsquema($conexion);
    labCatalogoControlEsquema($conexion);
} catch (Throwable $e) {
    $schemaMensaje = $e->getMessage();
}

$seccionPost = (string) ($_POST['seccion'] ?? 'analisis');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lab_require_permission('laboratorio.analisis.editar');
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['catalogo_analisis_csrf'], $token)) {
        $errorMensaje = 'La sesión del editor expiró. Recarga la página e inténtalo nuevamente.';
        $seccionPost = 'ninguna';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $seccionPost === 'campos') {
    $tab = 'campos';
    $campoTipoSeleccionado = (int) ($_POST['id_tipo_muestra'] ?? 0);
    try {
        $accion = (string) ($_POST['accion'] ?? 'guardar');
        if ($accion === 'eliminar') {
            labCamposMuestraEliminar($conexion, (int) ($_POST['id_campo'] ?? 0));
            header('Location: catalogo_analisis.php?tab=campos&tipo=' . $campoTipoSeleccionado . '&msg=campo_deleted');
            exit;
        }

        $idCampo = (int) ($_POST['id_campo'] ?? 0);
        $esNuevo = $idCampo <= 0;
        labCamposMuestraGuardar($conexion, $esNuevo ? null : $idCampo, $campoTipoSeleccionado, [
            'nombre' => $_POST['nombre'] ?? '',
            'etiqueta' => $_POST['etiqueta'] ?? '',
            'tipo_dato' => $_POST['tipo_dato'] ?? 'texto',
            'unidad' => $_POST['unidad'] ?? '',
            'obligatorio' => isset($_POST['obligatorio']),
            'orden' => $_POST['orden'] ?? '0',
            'activo' => isset($_POST['activo']),
            'opciones' => $_POST['opciones'] ?? '',
        ]);
        header('Location: catalogo_analisis.php?tab=campos&tipo=' . $campoTipoSeleccionado . '&msg=' . ($esNuevo ? 'campo_created' : 'campo_updated'));
        exit;
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $seccionPost === 'equipos') {
    $tab = 'equipos';
    try {
        $resultado = labEquipoGuardar($conexion, [
            'id_equipo' => $_POST['id_equipo'] ?? 0,
            'nombre' => $_POST['nombre'] ?? '',
            'codigo' => $_POST['codigo'] ?? '',
            'ultima_calibracion' => $_POST['ultima_calibracion'] ?? '',
            'proxima_calibracion' => $_POST['proxima_calibracion'] ?? '',
            'activo' => isset($_POST['activo']),
            'observaciones' => $_POST['observaciones'] ?? '',
        ], $nombreUsuario);
        header('Location: catalogo_analisis.php?tab=equipos&msg=' . $resultado);
        exit;
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $seccionPost === 'metodos') {
    $tab = 'metodos';
    try {
        $resultado = labMetodoGuardar($conexion, [
            'id_tipo_muestra' => $_POST['id_tipo_muestra'] ?? 0,
            'metodo' => $_POST['metodo'] ?? '',
            'norma' => $_POST['norma'] ?? '',
            'metodo_original' => $_POST['metodo_original'] ?? '',
            'norma_original' => $_POST['norma_original'] ?? '',
            'analisis' => $_POST['analisis'] ?? [],
        ], $nombreUsuario);
        header('Location: catalogo_analisis.php?tab=metodos&msg=' . $resultado);
        exit;
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();
    }
}

// Disponibilidad en Recepción (casilla de la tabla): activa u oculta el análisis sin borrar órdenes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $seccionPost === 'disponibilidad') {
    try {
        $fila = labCatalogoAnalisisObtenerPorId($conexion, (int) ($_POST['id_tipo'] ?? 0));
        if (!$fila) {
            throw new RuntimeException('El análisis ya no existe.');
        }
        $activo = isset($_POST['activo']) ? 1 : 0;
        labCatalogoAnalisisCambiarEstado($conexion, (int) $fila['id_tipo'], $activo);
        labCatalogoAuditar($conexion, $nombreUsuario, 'Disponibilidad en recepción', $fila['nombre'] . ' · ' . ($fila['nombre_muestra'] ?? ''),
            $activo ? 'Disponible para solicitar' : 'Oculto en recepción (se conservan las órdenes existentes)');
        header('Location: catalogo_analisis.php?msg=' . ($activo ? 'disponible' : 'oculto') . '#analisis-' . (int) $fila['id_tipo']);
        exit;
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $seccionPost === 'analisis') {
    try {
        $idTipo = (int) ($_POST['id_tipo'] ?? 0);
        $esNuevoAnalisis = $idTipo <= 0;
        $anterior = $esNuevoAnalisis ? null : labCatalogoAnalisisObtenerPorId($conexion, $idTipo);
        if (!$esNuevoAnalisis && !$anterior) {
            throw new RuntimeException('El análisis ya no existe.');
        }
        // Como en el prototipo, el nombre y el tipo identifican al análisis (y a su formato de captura):
        // solo se definen al crearlo.
        $idTipoMuestra = $esNuevoAnalisis ? (int) ($_POST['id_tipo_muestra'] ?? 0) : (int) $anterior['id_tipo_muestra'];
        $nombre = $esNuevoAnalisis ? trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? ''))) : (string) $anterior['nombre'];
        $activo = isset($_POST['activo']) ? 1 : 0;
        $metodo = trim((string) ($_POST['metodo'] ?? ''));
        if ($idTipoMuestra <= 0 || $nombre === '') {
            throw new RuntimeException('Completa el tipo de muestra y el nombre antes de guardar.');
        }
        if ($esNuevoAnalisis && $metodo === '') {
            throw new RuntimeException('Indica el método del análisis.');
        }

        $tipoValido = false;
        $claveTipo = '';
        foreach (labCatalogoAnalisisTipoMuestraOptions($conexion) as $opcion) {
            if ((int) $opcion['id_tipo'] === $idTipoMuestra) {
                $tipoValido = true;
                $claveTipo = (string) $opcion['clave'];
                break;
            }
        }
        if (!$tipoValido) {
            throw new RuntimeException('El tipo de muestra seleccionado no existe.');
        }
        if ($esNuevoAnalisis) {
            $dup = $conexion->prepare('SELECT COUNT(*) FROM tipo_analisis WHERE id_tipo_muestra = ? AND LOWER(TRIM(nombre)) = LOWER(?)');
            $dup->execute([$idTipoMuestra, $nombre]);
            if ((int) $dup->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe "' . $nombre . '" para este tipo de muestra.');
            }
        }

        $limiteMin = catalogoAnalisisNumeroNullable('limite_min');
        $limiteMax = catalogoAnalisisNumeroNullable('limite_max');
        if ($limiteMin !== null && $limiteMax !== null && $limiteMin > $limiteMax) {
            throw new RuntimeException('El límite mínimo no puede ser mayor que el límite máximo.');
        }
        $duracionTexto = trim((string) ($_POST['duracion_dias'] ?? ''));
        $duracion = $duracionTexto === '' ? null : filter_var($duracionTexto, FILTER_VALIDATE_INT);
        if ($duracionTexto !== '' && ($duracion === false || $duracion < 0 || $duracion > 365)) {
            throw new RuntimeException('La duración debe ser un número entero de días entre 0 y 365.');
        }
        $grupo = trim((string) ($_POST['grupo_recepcion'] ?? ''));
        if (mb_strlen($grupo) > 80) {
            throw new RuntimeException('El nombre del grupo es demasiado largo.');
        }
        // Solo se guarda si difiere del grupo que ya le da el formato de recepción.
        if ($grupo === catalogoAnalisisGrupo($claveTipo, $nombre, '')) {
            $grupo = '';
        }

        $metadatos = [
            'metodo' => $metodo,
            'norma' => $_POST['norma'] ?? '',
            'equipo_default' => $_POST['equipo_default'] ?? '',
            'unidad' => $_POST['unidad'] ?? '',
            'limite_min' => $limiteMin,
            'limite_max' => $limiteMax,
            'duracion_dias' => $duracion,
            'grupo_recepcion' => $grupo,
        ];
        $conexion->beginTransaction();
        labCatalogoAnalisisGuardar($conexion, $esNuevoAnalisis ? null : $idTipo, $idTipoMuestra, $nombre, $activo, $metadatos);
        $tipoNombre = '';
        foreach (labCatalogoAnalisisTipoMuestraOptions($conexion) as $opcion) {
            if ((int) $opcion['id_tipo'] === $idTipoMuestra) $tipoNombre = $opcion['nombre'];
        }
        if ($esNuevoAnalisis) {
            labCatalogoAuditar($conexion, $nombreUsuario, 'Alta en catálogo', $nombre . ' · ' . $tipoNombre,
                'Método ' . $metodo . '; ' . ($activo ? 'disponible' : 'oculto') . ' en recepción' . ($grupo !== '' ? ' (grupo ' . $grupo . ')' : ''));
        } else {
            $etiquetas = ['metodo' => 'Método', 'norma' => 'Norma', 'equipo_default' => 'Equipo', 'unidad' => 'Unidad', 'limite_min' => 'Límite mín.',
                'limite_max' => 'Límite máx.', 'duracion_dias' => 'Duración (días)', 'grupo_recepcion' => 'Grupo en recepción'];
            $cambios = [];
            foreach ($etiquetas as $campo => $etiqueta) {
                $antes = in_array($campo, ['limite_min', 'limite_max'], true) ? catalogoAnalisisFormatoNumero($anterior[$campo] ?? null) : trim((string) ($anterior[$campo] ?? ''));
                $despues = in_array($campo, ['limite_min', 'limite_max'], true) ? catalogoAnalisisFormatoNumero($metadatos[$campo]) : trim((string) ($metadatos[$campo] ?? ''));
                if ($antes !== $despues) $cambios[] = $etiqueta . ': ' . ($antes !== '' ? $antes : '—') . ' → ' . ($despues !== '' ? $despues : '—');
            }
            if ((int) $anterior['activo'] !== $activo) $cambios[] = $activo ? 'Disponible en recepción' : 'Oculto en recepción';
            if ($cambios) labCatalogoAuditar($conexion, $nombreUsuario, 'Edición de catálogo', $nombre . ' · ' . $tipoNombre, implode('; ', $cambios));
        }
        $conexion->commit();
        header('Location: catalogo_analisis.php?msg=' . ($esNuevoAnalisis ? ($activo ? 'created_on' : 'created_off') : 'updated'));
        exit;
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        $errorMensaje = $e->getMessage();
    }
}

try {
    labCamposMuestraAsegurarEsquema($conexion);
    $tiposMuestra = labCatalogoAnalisisTipoMuestraOptions($conexion);
    $filas = labCatalogoAnalisisFilas($conexion, false);
} catch (Throwable $e) {
    $schemaMensaje = $e->getMessage();
    $tiposMuestra = [];
    $filas = [];
}

try {
    $equipos = $schemaMensaje === '' ? labEquiposListar($conexion) : [];
} catch (Throwable $e) {
    $equipos = [];
}
$equiposActivos = array_values(array_filter($equipos, static fn($e) => (int) $e['activo'] === 1));

// Grupos de Recepción por tipo de muestra (formato de recepción + grupos ya usados en el catálogo).
$clavesTipo = [];
$gruposPorTipo = [];
foreach ($tiposMuestra as $tipo) {
    $clavesTipo[(int) $tipo['id_tipo']] = $tipo['clave'];
    $gruposPorTipo[(int) $tipo['id_tipo']] = array_column(labRecepcionCatalogoConfig()[$tipo['clave']]['grupos'] ?? [], 'nombre');
}
foreach ($filas as $fila) {
    $g = trim((string) ($fila['grupo_recepcion'] ?? ''));
    if ($g !== '' && !in_array($g, $gruposPorTipo[(int) $fila['id_tipo_muestra']] ?? [], true)) {
        $gruposPorTipo[(int) $fila['id_tipo_muestra']][] = $g;
    }
}
foreach ($gruposPorTipo as &$lista) {
    if (!in_array('Otros análisis', $lista, true)) $lista[] = 'Otros análisis';
}
unset($lista);

$camposTiposDato = labCamposMuestraTiposDato();
$campoTipoIds = array_map(static fn(array $tipo): int => (int) $tipo['id_tipo'], $tiposMuestra);
$campoTipoActual = (int) ($_GET['tipo'] ?? ($campoTipoSeleccionado ?? 0));
if ($campoTipoActual <= 0 || !in_array($campoTipoActual, $campoTipoIds, true)) {
    $campoTipoActual = $campoTipoIds[0] ?? 0;
}

$campos = [];
$camposJs = [];
if ($tab === 'campos' && $campoTipoActual > 0 && $schemaMensaje === '') {
    try {
        labCamposMuestraSincronizarPredefinidos($conexion);
        $campos = labCamposMuestraListar($conexion, $campoTipoActual);
    } catch (Throwable $e) {
        $schemaMensaje = $e->getMessage();
        $campos = [];
    }
    foreach ($campos as $campo) {
        $camposJs[(int) $campo['id_campo']] = [
            'id_campo' => (int) $campo['id_campo'],
            'nombre' => (string) $campo['nombre'],
            'etiqueta' => (string) $campo['etiqueta'],
            'tipo_dato' => (string) $campo['tipo_dato'],
            'unidad' => (string) ($campo['unidad'] ?? ''),
            'obligatorio' => (int) ($campo['obligatorio'] ?? 0) === 1,
            'orden' => (int) ($campo['orden'] ?? 0),
            'activo' => (int) ($campo['activo'] ?? 1) === 1,
            'opciones' => implode("\n", labCamposMuestraDecodificarOpciones($campo['opciones'] ?? '')),
            'predefinido' => (int) ($campo['predefinido'] ?? 0) === 1,
        ];
    }
}

$catalogoJs = [];
$formatosJs = [];
foreach ($filas as &$fila) {
    $clave = $clavesTipo[(int) $fila['id_tipo_muestra']] ?? labCatalogoAnalisisClaveModulo((string) ($fila['nombre_muestra'] ?? ''), (string) ($fila['prefijo_muestra'] ?? ''));
    $fila['grupo_efectivo'] = catalogoAnalisisGrupo($clave, (string) $fila['nombre'], (string) ($fila['grupo_recepcion'] ?? ''));
    $fila['formato'] = $tab === 'analisis' ? labCatalogoFormato((string) ($fila['nombre_muestra'] ?? ''), (string) $fila['nombre']) : null;
    if ($fila['formato']) $formatosJs[(int) $fila['id_tipo']] = $fila['formato'] + ['tipo' => (string) ($fila['nombre_muestra'] ?? '')];
    $catalogoJs[(int) $fila['id_tipo']] = [
        'id_tipo' => (int) $fila['id_tipo'],
        'id_tipo_muestra' => (int) $fila['id_tipo_muestra'],
        'nombre' => (string) $fila['nombre'],
        'tipo' => (string) ($fila['nombre_muestra'] ?? ''),
        'activo' => (int) ($fila['activo'] ?? 1) === 1,
        'metodo' => (string) ($fila['metodo'] ?? ''),
        'norma' => (string) ($fila['norma'] ?? ''),
        'equipo_default' => (string) ($fila['equipo_default'] ?? ''),
        'unidad' => (string) ($fila['unidad'] ?? ''),
        'limite_min' => $fila['limite_min'] !== null ? catalogoAnalisisFormatoNumero($fila['limite_min']) : '',
        'limite_max' => $fila['limite_max'] !== null ? catalogoAnalisisFormatoNumero($fila['limite_max']) : '',
        'duracion_dias' => isset($fila['duracion_dias']) && $fila['duracion_dias'] !== null ? (int) $fila['duracion_dias'] : '',
        'grupo' => $fila['grupo_efectivo'],
    ];
}
unset($fila);

$equiposJs = [];
foreach ($equipos as $equipo) {
    $equiposJs[(int) $equipo['id_equipo']] = [
        'id_equipo' => (int) $equipo['id_equipo'], 'nombre' => (string) $equipo['nombre'], 'codigo' => (string) $equipo['codigo'],
        'ultima_calibracion' => (string) ($equipo['ultima_calibracion'] ?? ''), 'proxima_calibracion' => (string) ($equipo['proxima_calibracion'] ?? ''),
        'activo' => (int) $equipo['activo'] === 1, 'observaciones' => (string) ($equipo['observaciones'] ?? ''),
    ];
}
$resumenEquipos = ['vigente' => 0, 'por_vencer' => 0, 'vencida' => 0, 'sin' => 0];
foreach ($equiposActivos as $equipo) $resumenEquipos[$equipo['estado']['clave']]++;

$metodos = $tab === 'metodos' ? labCatalogoMetodos($filas) : [];
$sinMetodo = $tab === 'metodos' ? labCatalogoSinMetodo($filas) : [];
// Editor de métodos: análisis de cada tipo de muestra (para marcar a cuáles se aplica el método).
$metodosJs = [];
$analisisPorTipo = [];
$tiposMetodo = [];
if ($tab === 'metodos') {
    foreach ($metodos as $i => $m) {
        $metodosJs[$i] = ['metodo' => $m['metodo'], 'norma' => $m['norma'], 'id_tipo_muestra' => $m['id_tipo_muestra'], 'tipo' => $m['tipo'], 'ids' => $m['ids']];
    }
    foreach ($filas as $f) {
        $idTm = (int) ($f['id_tipo_muestra'] ?? 0);
        if ($idTm <= 0) continue;
        $tiposMetodo[$idTm] = (string) ($f['nombre_muestra'] ?? ('Tipo #' . $idTm));
        $analisisPorTipo[$idTm][] = ['id' => (int) $f['id_tipo'], 'nombre' => (string) $f['nombre'], 'activo' => (int) ($f['activo'] ?? 1) === 1,
            'metodo' => trim((string) ($f['metodo'] ?? '')), 'norma' => trim((string) ($f['norma'] ?? ''))];
    }
    foreach ($analisisPorTipo as &$lista) {
        usort($lista, static fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
    }
    unset($lista);
    asort($tiposMetodo);
}
$auditoria = $tab === 'auditoria' && $schemaMensaje === '' ? labCatalogoAuditoria($conexion) : [];
$modulosAuditoria = array_values(array_unique(array_column($auditoria, 'modulo')));
sort($modulosAuditoria);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de análisis</title>
    <link rel="stylesheet" href="styles/catalogo_analisis_template.css?v=<?= filemtime(__DIR__ . '/styles/catalogo_analisis_template.css') ?>">
    <link rel="stylesheet" href="css/lab_shell.css?v=20261006">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('catalogo_analisis.php', 'Catálogo de análisis', 'Análisis, métodos, equipos y auditoría de trazabilidad'); ?>
    <div class="analysis-control-page">
        <nav class="analysis-control-tabs" aria-label="Secciones del catálogo">
            <?php foreach (['analisis' => 'Análisis', 'equipos' => 'Equipos', 'metodos' => 'Métodos analíticos', 'campos' => 'Campos por tipo de muestra', 'auditoria' => 'Auditoría'] as $claveTab => $textoTab): ?>
                <a href="catalogo_analisis.php?tab=<?= $claveTab ?>" class="analysis-control-tab<?= $tab === $claveTab ? ' is-active' : '' ?>"<?= $tab === $claveTab ? ' aria-current="page"' : '' ?>><?= catalogoAnalisisE($textoTab) ?></a>
            <?php endforeach; ?>
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

        <?php if ($tab === 'analisis'): ?>
        <section class="analysis-control-alert">
            <?= catalogoAnalisisSvg('info') ?>
            <div><strong>Catálogo maestro:</strong> cada análisis vincula método, equipo por defecto, unidad y rango esperado (mín/máx). Este catálogo alimenta las alertas de <em>fuera de rango</em> en Validación técnica, define qué análisis se pueden solicitar en <strong>Recepción</strong> (y en qué grupo aparecen) y muestra el <strong>formato de captura</strong> de cada análisis: filas de control, encabezado, columnas del analista, columnas calculadas y curva. Haz clic en el formato para verlo.</div>
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
                    <label>
                        <span class="sr-only">Filtrar por disponibilidad</span>
                        <select id="analysisStateFilter">
                            <option value="">Disponibles y ocultos</option>
                            <option value="1">Disponibles en recepción</option>
                            <option value="0">Ocultos en recepción</option>
                        </select>
                    </label>
                    <?php if ($canEdit): ?>
                        <button type="button" class="analysis-control-button is-primary" data-new-analysis>+ Nuevo análisis</button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="analysis-control-table-wrap">
                <table class="analysis-control-table">
                    <thead><tr><th>Análisis</th><th>Tipo</th><th>Método / Norma</th><th>Equipo por defecto</th><th>Unidad</th><th>Rango esperado</th><th>Recepción</th><th>Formato de captura</th><th title="Días desde la recepción de la muestra hasta el envío del reporte">Duración (días)</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php if (empty($filas)): ?>
                            <tr class="analysis-control-empty"><td colspan="10">No hay análisis registrados en el catálogo.</td></tr>
                        <?php else: ?>
                            <?php foreach ($filas as $fila): ?>
                                <?php
                                $activo = (int) ($fila['activo'] ?? 1) === 1;
                                $idFila = (int) $fila['id_tipo'];
                                $textoBusqueda = mb_strtolower(implode(' ', [
                                    $fila['nombre'] ?? '', $fila['nombre_muestra'] ?? '', $fila['metodo'] ?? '', $fila['norma'] ?? '',
                                    $fila['equipo_default'] ?? '', $fila['unidad'] ?? '', $fila['grupo_efectivo'] ?? '',
                                ]), 'UTF-8');
                                ?>
                                <tr class="analysis-control-row<?= $activo ? '' : ' is-inactive' ?>" id="analisis-<?= $idFila ?>" data-type="<?= (int) $fila['id_tipo_muestra'] ?>" data-active="<?= $activo ? '1' : '0' ?>" data-search="<?= catalogoAnalisisE($textoBusqueda) ?>">
                                    <td><strong><?= catalogoAnalisisE($fila['nombre']) ?></strong><small><?= catalogoAnalisisE($fila['grupo_efectivo']) ?></small></td>
                                    <td><span class="analysis-control-chip"><?= catalogoAnalisisE($fila['nombre_muestra'] ?: 'Sin tipo') ?></span></td>
                                    <td><?= catalogoAnalisisE($fila['metodo'] ?: 'Sin configurar') ?><small class="analysis-control-mono"><?= catalogoAnalisisE($fila['norma'] ?: 'Sin norma') ?></small></td>
                                    <td><?= catalogoAnalisisE($fila['equipo_default'] ?: '—') ?></td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE($fila['unidad'] ?: '—') ?></td>
                                    <td class="analysis-control-range<?= ($fila['limite_min'] === null && $fila['limite_max'] === null) ? ' is-empty' : '' ?>"><?= catalogoAnalisisE(catalogoAnalisisRango($fila)) ?></td>
                                    <td>
                                        <?php if ($canEdit): ?>
                                            <form method="post" class="analysis-control-inline-form">
                                                <input type="hidden" name="seccion" value="disponibilidad">
                                                <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                                                <input type="hidden" name="id_tipo" value="<?= $idFila ?>">
                                                <label class="analysis-control-rec<?= $activo ? '' : ' is-off' ?>"><input type="checkbox" name="activo" value="1" data-auto-submit<?= $activo ? ' checked' : '' ?> aria-label="Disponible en recepción: <?= catalogoAnalisisE($fila['nombre']) ?>"> <?= $activo ? 'Disponible' : 'Oculto' ?></label>
                                            </form>
                                        <?php else: ?>
                                            <span class="analysis-control-rec<?= $activo ? '' : ' is-off' ?>"><?= $activo ? 'Disponible' : 'Oculto' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($fila['formato']): ?>
                                            <button type="button" class="analysis-control-link" data-formato="<?= $idFila ?>"><?= catalogoAnalisisE($fila['formato']['resumen']) ?></button>
                                            <?php if ($fila['formato']['detalle'] !== ''): ?><small><?= catalogoAnalisisE($fila['formato']['detalle']) ?></small><?php endif; ?>
                                        <?php else: ?>
                                            <span class="analysis-control-muted">Sin formato configurado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="analysis-control-muted"><?= isset($fila['duracion_dias']) && $fila['duracion_dias'] !== null ? (int) $fila['duracion_dias'] . ((int) $fila['duracion_dias'] === 1 ? ' día' : ' días') : '—' ?></td>
                                    <td class="analysis-control-actions">
                                        <?php if ($canEdit): ?>
                                            <button type="button" class="analysis-control-button is-icon" data-edit-analysis="<?= $idFila ?>" title="Editar análisis" aria-label="Editar <?= catalogoAnalisisE($fila['nombre']) ?>"><?= catalogoAnalisisSvg('editar') ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="analysis-control-empty" id="analysisFilteredEmpty" hidden><td colspan="10">No hay análisis que coincidan con los filtros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'equipos'): ?>
        <section class="analysis-control-alert">
            <?= catalogoAnalisisSvg('info') ?>
            <div><strong>Equipos de laboratorio:</strong> registro de equipos con su calendario de calibración. El estado se calcula con la próxima calibración: <em>Vigente</em>, <em>Por vencer</em> (<?= LAB_EQUIPO_DIAS_AVISO ?> días o menos) o <em>Vencida</em>. Los equipos en servicio son los que se ofrecen como equipo por defecto de cada análisis.</div>
        </section>
        <div class="analysis-control-kpis">
            <article><span>Vigentes</span><strong class="is-ok"><?= $resumenEquipos['vigente'] ?></strong></article>
            <article><span>Por vencer</span><strong class="is-warn"><?= $resumenEquipos['por_vencer'] ?></strong></article>
            <article><span>Vencidas</span><strong class="is-bad"><?= $resumenEquipos['vencida'] ?></strong></article>
            <article><span>Sin calibración registrada</span><strong><?= $resumenEquipos['sin'] ?></strong></article>
        </div>
        <section class="analysis-control-card">
            <header class="analysis-control-toolbar">
                <h2>Equipos de laboratorio</h2>
                <div class="analysis-control-filters">
                    <?php if ($canEdit): ?><button type="button" class="analysis-control-button is-primary" data-new-equipo>+ Nuevo equipo</button><?php endif; ?>
                </div>
            </header>
            <div class="analysis-control-table-wrap">
                <table class="analysis-control-table">
                    <thead><tr><th>Equipo</th><th>Código</th><th>Última calibración</th><th>Próxima calibración</th><th>Estado</th><th>Análisis que lo usan</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php if (empty($equipos)): ?>
                            <tr class="analysis-control-empty"><td colspan="7">No hay equipos registrados. Agrega el primero con “Nuevo equipo”.</td></tr>
                        <?php else: ?>
                            <?php foreach ($equipos as $equipo): ?>
                                <?php $enServicio = (int) $equipo['activo'] === 1; ?>
                                <tr class="analysis-control-row<?= $enServicio ? '' : ' is-inactive' ?>">
                                    <td><strong><?= catalogoAnalisisE($equipo['nombre']) ?></strong><?php if ($equipo['observaciones']): ?><small><?= catalogoAnalisisE($equipo['observaciones']) ?></small><?php endif; ?></td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE($equipo['codigo']) ?></td>
                                    <td><?= catalogoAnalisisE(catalogoAnalisisFecha($equipo['ultima_calibracion'])) ?></td>
                                    <td><?= catalogoAnalisisE(catalogoAnalisisFecha($equipo['proxima_calibracion'])) ?></td>
                                    <td><?php if ($enServicio): ?><span class="analysis-control-status is-<?= catalogoAnalisisE($equipo['estado']['clave']) ?>"><?= catalogoAnalisisE($equipo['estado']['texto']) ?></span><?php else: ?><span class="analysis-control-status is-sin">Fuera de servicio</span><?php endif; ?></td>
                                    <td class="analysis-control-muted"><?= (int) $equipo['analisis'] ?></td>
                                    <td class="analysis-control-actions"><?php if ($canEdit): ?><button type="button" class="analysis-control-button" data-edit-equipo="<?= (int) $equipo['id_equipo'] ?>"><?= catalogoAnalisisSvg('editar') ?> Editar</button><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'metodos'): ?>
        <section class="analysis-control-alert">
            <?= catalogoAnalisisSvg('info') ?>
            <div><strong>Métodos analíticos por tipo de muestra:</strong> se forman con el método y la norma de cada análisis del catálogo, así no se duplican datos. <?= $canEdit ? 'Con <em>Editar</em> cambias el nombre del método o su norma y eliges a qué análisis se aplica; los cambios se guardan en cada análisis y quedan en Auditoría.' : 'Solo quien puede editar el catálogo puede modificar los métodos.' ?></div>
        </section>
        <?php if ($sinMetodo): ?>
            <div class="analysis-control-message is-warning"><?= catalogoAnalisisSvg('aviso') ?><span><?= count($sinMetodo) ?> análisis sin método configurado: <?= catalogoAnalisisE(implode(', ', array_map(static fn($f) => $f['nombre'] . ' (' . ($f['nombre_muestra'] ?? '') . ')', array_slice($sinMetodo, 0, 12)))) ?><?= count($sinMetodo) > 12 ? '…' : '' ?></span></div>
        <?php endif; ?>
        <section class="analysis-control-card">
            <header class="analysis-control-toolbar">
                <h2>Métodos analíticos y plantillas por tipo de muestra</h2>
                <div class="analysis-control-filters"><span class="analysis-control-muted"><?= count($metodos) ?> métodos</span>
                    <?php if ($canEdit && $tiposMetodo): ?><button type="button" class="analysis-control-button is-primary" data-new-metodo>+ Nuevo método</button><?php endif; ?></div>
            </header>
            <div class="analysis-control-table-wrap">
                <table class="analysis-control-table">
                    <thead><tr><th>Método</th><th>Norma / Referencia</th><th>Tipo de muestra</th><th>Parámetros</th><?php if ($canEdit): ?><th><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php if (empty($metodos)): ?>
                            <tr class="analysis-control-empty"><td colspan="<?= $canEdit ? 5 : 4 ?>">Todavía no hay análisis con método configurado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($metodos as $iMetodo => $metodo): ?>
                                <tr class="analysis-control-row">
                                    <td><strong><?= catalogoAnalisisE($metodo['metodo']) ?></strong></td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE($metodo['norma'] ?: '—') ?></td>
                                    <td><span class="analysis-control-chip"><?= catalogoAnalisisE($metodo['tipo'] ?: 'Sin tipo') ?></span></td>
                                    <td><strong><?= count($metodo['analisis']) ?></strong><small><?= catalogoAnalisisE(implode(', ', $metodo['analisis'])) ?></small></td>
                                    <?php if ($canEdit): ?>
                                        <td class="analysis-control-actions"><?php if ($metodo['id_tipo_muestra'] > 0): ?><button type="button" class="analysis-control-button" data-edit-metodo="<?= (int) $iMetodo ?>" aria-label="Editar el método <?= catalogoAnalisisE($metodo['metodo']) ?>"><?= catalogoAnalisisSvg('editar') ?> Editar</button><?php endif; ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'campos'): ?>
        <section class="analysis-control-alert">
            <?= catalogoAnalisisSvg('info') ?>
            <div><strong>Campos por tipo de muestra:</strong> son las columnas de la etiqueta que se registran en <a href="view/listar_lotes.php">Identificación de muestras</a> (ingreso manual). Los campos <strong>predefinidos</strong> vienen del formato de cada tipo: puedes cambiar su etiqueta, tipo de dato y orden, marcarlos obligatorios u ocultarlos. También puedes <strong>agregar campos</strong> nuevos. El <em>nombre técnico</em> identifica el campo internamente y no puede repetirse dentro del mismo tipo de muestra.</div>
        </section>

        <section class="analysis-control-card">
            <header class="analysis-control-toolbar">
                <h2>Campos personalizados</h2>
                <div class="analysis-control-filters">
                    <label>
                        <span class="sr-only">Tipo de muestra</span>
                        <select id="campoTypeSelect"<?= empty($tiposMuestra) ? ' disabled' : '' ?>>
                            <?php if (empty($tiposMuestra)): ?>
                                <option value="0">Sin tipos de muestra</option>
                            <?php else: ?>
                                <?php foreach ($tiposMuestra as $tipo): ?>
                                    <option value="<?= (int) $tipo['id_tipo'] ?>"<?= (int) $tipo['id_tipo'] === $campoTipoActual ? ' selected' : '' ?>>
                                        <?= catalogoAnalisisE($tipo['nombre'] !== '' ? $tipo['nombre'] : $tipo['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                    <?php if ($canEdit && !empty($tiposMuestra)): ?>
                        <button type="button" class="analysis-control-button is-primary" data-new-campo><?= catalogoAnalisisSvg('guardar') ?> Nuevo campo</button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="analysis-control-table-wrap">
                <table class="analysis-control-table">
                    <thead><tr><th>Orden</th><th>Etiqueta</th><th>Nombre técnico</th><th>Tipo de dato</th><th>Unidad</th><th>Obligatorio</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php if (empty($tiposMuestra)): ?>
                            <tr class="analysis-control-empty"><td colspan="8">No hay tipos de muestra registrados. Crea primero un tipo de muestra en su catálogo.</td></tr>
                        <?php elseif (empty($campos)): ?>
                            <tr class="analysis-control-empty"><td colspan="8">Este tipo de muestra todavía no tiene campos personalizados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($campos as $campo): ?>
                                <?php $campoActivo = (int) ($campo['activo'] ?? 1) === 1; ?>
                                <tr class="analysis-control-row<?= $campoActivo ? '' : ' is-inactive' ?>">
                                    <td class="analysis-control-mono"><?= (int) $campo['orden'] ?></td>
                                    <td>
                                        <strong><?= catalogoAnalisisE($campo['etiqueta']) ?></strong><?php if ((int) ($campo['predefinido'] ?? 0) === 1): ?> <span class="analysis-control-chip is-predef" title="Campo del formato de etiqueta; no se elimina, solo se oculta">Predefinido</span><?php endif; ?>
                                        <?php if ((string) $campo['tipo_dato'] === 'lista'): ?>
                                            <small><?= catalogoAnalisisE(implode(' · ', labCamposMuestraDecodificarOpciones($campo['opciones'] ?? ''))) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE($campo['nombre']) ?></td>
                                    <td><span class="analysis-control-chip"><?= catalogoAnalisisE(labCamposMuestraTipoDatoLabel((string) $campo['tipo_dato'])) ?></span></td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE(($campo['unidad'] ?? '') !== '' ? $campo['unidad'] : '—') ?></td>
                                    <td><?= (int) ($campo['obligatorio'] ?? 0) === 1 ? 'Sí' : 'No' ?></td>
                                    <td>
                                        <?php if ($campoActivo): ?>
                                            Activo
                                        <?php else: ?>
                                            <span class="analysis-control-inactive">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="analysis-control-actions">
                                        <?php if ($canEdit): ?>
                                            <button type="button" class="analysis-control-button" data-edit-campo="<?= (int) $campo['id_campo'] ?>"><?= catalogoAnalisisSvg('editar') ?> Editar</button>
                                            <?php if ((int) ($campo['predefinido'] ?? 0) !== 1): ?>
                                            <form method="post" class="analysis-control-inline-form" onsubmit="return confirm('¿Eliminar definitivamente este campo personalizado?');">
                                                <input type="hidden" name="seccion" value="campos">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                                                <input type="hidden" name="id_tipo_muestra" value="<?= $campoTipoActual ?>">
                                                <input type="hidden" name="id_campo" value="<?= (int) $campo['id_campo'] ?>">
                                                <button type="submit" class="analysis-control-button"><?= catalogoAnalisisSvg('cerrar') ?> Eliminar</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'auditoria'): ?>
        <section class="analysis-control-alert">
            <?= catalogoAnalisisSvg('info') ?>
            <div><strong>Registro de auditoría:</strong> cambios del catálogo (análisis, disponibilidad y equipos) y los eventos que registra cada etapa del flujo: recepción, identificación, planificación, hojas de trabajo, validación, informes y envíos. Se muestran los <?= count($auditoria) ?> eventos más recientes.</div>
        </section>
        <section class="analysis-control-card">
            <header class="analysis-control-toolbar">
                <h2>Registro de auditoría</h2>
                <div class="analysis-control-filters">
                    <label class="analysis-control-search">
                        <?= catalogoAnalisisSvg('buscar') ?>
                        <span class="sr-only">Buscar en auditoría</span>
                        <input type="search" id="auditSearch" placeholder="Buscar por usuario, lote o muestra…" autocomplete="off">
                    </label>
                    <label>
                        <span class="sr-only">Filtrar por módulo</span>
                        <select id="auditModule">
                            <option value="">Todos los módulos</option>
                            <?php foreach ($modulosAuditoria as $modulo): ?><option><?= catalogoAnalisisE($modulo) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </header>
            <div class="analysis-control-table-wrap">
                <table class="analysis-control-table">
                    <thead><tr><th>Fecha / Hora</th><th>Usuario</th><th>Módulo</th><th>Acción</th><th>Referencia</th><th>Detalle</th></tr></thead>
                    <tbody>
                        <?php if (empty($auditoria)): ?>
                            <tr class="analysis-control-empty"><td colspan="6">Todavía no hay eventos registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditoria as $evento): ?>
                                <tr class="analysis-control-row audit-row" data-module="<?= catalogoAnalisisE($evento['modulo']) ?>" data-search="<?= catalogoAnalisisE(mb_strtolower(implode(' ', $evento), 'UTF-8')) ?>">
                                    <td class="analysis-control-mono analysis-control-nowrap"><?= catalogoAnalisisE(catalogoAnalisisFecha($evento['fecha'])) ?></td>
                                    <td><?= catalogoAnalisisE($evento['usuario'] ?: '—') ?></td>
                                    <td><span class="analysis-control-chip"><?= catalogoAnalisisE($evento['modulo']) ?></span></td>
                                    <td><?= catalogoAnalisisE($evento['accion']) ?></td>
                                    <td class="analysis-control-mono"><?= catalogoAnalisisE($evento['referencia']) ?></td>
                                    <td class="analysis-control-muted analysis-control-detail"><?= catalogoAnalisisE($evento['detalle'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="analysis-control-empty" id="auditFilteredEmpty" hidden><td colspan="6">No hay eventos que coincidan con la búsqueda.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'analisis'): ?>
        <div class="analysis-control-modal" id="formatModal" hidden>
            <div class="analysis-control-dialog is-wide" role="dialog" aria-modal="true" aria-labelledby="formatModalTitle">
                <header class="analysis-control-modal-head">
                    <h2 id="formatModalTitle">Formato de captura</h2>
                    <button type="button" class="analysis-control-close" data-close-format aria-label="Cerrar"><?= catalogoAnalisisSvg('cerrar') ?></button>
                </header>
                <div class="analysis-control-modal-body" id="formatModalBody"></div>
                <footer class="analysis-control-modal-foot"><button type="button" class="analysis-control-button" data-close-format>Cerrar</button></footer>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canEdit && $tab === 'analisis'): ?>
        <div class="analysis-control-modal" id="analysisModal" hidden>
            <div class="analysis-control-dialog" role="dialog" aria-modal="true" aria-labelledby="analysisModalTitle">
                <header class="analysis-control-modal-head">
                    <h2 id="analysisModalTitle">Editar análisis</h2>
                    <button type="button" class="analysis-control-close" data-close-analysis aria-label="Cerrar"><?= catalogoAnalisisSvg('cerrar') ?></button>
                </header>
                <form method="post" id="analysisForm">
                    <input type="hidden" name="seccion" value="analisis">
                    <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                    <input type="hidden" name="id_tipo" id="analysisId">
                    <div class="analysis-control-modal-body">
                        <div class="analysis-control-form-grid">
                            <label class="analysis-control-field">
                                <span>Nombre del análisis *</span>
                                <input type="text" name="nombre" id="analysisName" maxlength="255" required placeholder="Ej. Sodio intercambiable">
                            </label>
                            <label class="analysis-control-field">
                                <span>Tipo de muestra *</span>
                                <select name="id_tipo_muestra" id="analysisSampleType" required>
                                    <?php foreach ($tiposMuestra as $tipo): ?>
                                        <option value="<?= (int) $tipo['id_tipo'] ?>"><?= catalogoAnalisisE($tipo['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="analysis-control-field">
                                <span>Grupo en recepción</span>
                                <select name="grupo_recepcion" id="analysisGroup"></select>
                            </label>
                            <label class="analysis-control-check">
                                <input type="checkbox" name="activo" id="analysisActive" value="1">
                                <span><strong>Disponible para solicitar en recepción</strong><small>Si se oculta, se conservan las solicitudes existentes.</small></span>
                            </label>
                            <label class="analysis-control-field">
                                <span>Método <em id="analysisMethodReq">*</em></span>
                                <input type="text" name="metodo" id="analysisMethod" maxlength="255" placeholder="Ej. Espectrofotometría UV-Vis">
                            </label>
                            <label class="analysis-control-field">
                                <span>Norma / referencia</span>
                                <input type="text" name="norma" id="analysisStandard" maxlength="255" placeholder="Ej. ISO 10390">
                            </label>
                            <label class="analysis-control-field">
                                <span>Equipo por defecto</span>
                                <select name="equipo_default" id="analysisEquipment">
                                    <option value="">— Sin equipo —</option>
                                    <?php foreach ($equiposActivos as $equipo): ?>
                                        <option value="<?= catalogoAnalisisE($equipo['etiqueta']) ?>"><?= catalogoAnalisisE($equipo['etiqueta']) ?><?= $equipo['estado']['clave'] === 'vencida' ? ' · calibración vencida' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="analysis-control-field">
                                <span>Unidad</span>
                                <input type="text" name="unidad" id="analysisUnit" maxlength="80" placeholder="Ej. %, ppm, meq/100g">
                            </label>
                            <label class="analysis-control-field">
                                <span>Límite mínimo esperado</span>
                                <input type="number" name="limite_min" id="analysisMin" step="any" placeholder="Sin límite">
                            </label>
                            <label class="analysis-control-field">
                                <span>Límite máximo esperado</span>
                                <input type="number" name="limite_max" id="analysisMax" step="any" placeholder="Sin límite">
                            </label>
                            <label class="analysis-control-field">
                                <span>Duración (días)</span>
                                <input type="number" name="duracion_dias" id="analysisTime" min="0" max="365" step="1" placeholder="Ej. 7" title="Días desde la recepción de la muestra hasta el envío del reporte">
                            </label>
                        </div>
                        <p class="analysis-control-note" id="analysisLockedNote" hidden>El nombre y el tipo de muestra identifican al análisis y a su formato de captura; no se cambian al editar.</p>
                        <div class="analysis-control-message is-warning"><?= catalogoAnalisisSvg('aviso') ?><span>Dejar el límite en blanco desactiva la alerta automática de rango para este análisis.</span></div>
                    </div>
                    <footer class="analysis-control-modal-foot">
                        <button type="button" class="analysis-control-button" data-close-analysis>Cancelar</button>
                        <button type="submit" class="analysis-control-button is-primary" id="analysisSubmit"><?= catalogoAnalisisSvg('guardar') ?> Guardar cambios</button>
                    </footer>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canEdit && $tab === 'equipos'): ?>
        <div class="analysis-control-modal" id="equipoModal" hidden>
            <div class="analysis-control-dialog" role="dialog" aria-modal="true" aria-labelledby="equipoModalTitle">
                <header class="analysis-control-modal-head">
                    <h2 id="equipoModalTitle">Nuevo equipo</h2>
                    <button type="button" class="analysis-control-close" data-close-equipo aria-label="Cerrar"><?= catalogoAnalisisSvg('cerrar') ?></button>
                </header>
                <form method="post" id="equipoForm">
                    <input type="hidden" name="seccion" value="equipos">
                    <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                    <input type="hidden" name="id_equipo" id="equipoId">
                    <div class="analysis-control-modal-body">
                        <div class="analysis-control-form-grid">
                            <label class="analysis-control-field"><span>Equipo *</span><input type="text" name="nombre" id="equipoNombre" maxlength="160" required placeholder="Ej. Espectrofotómetro UV-Vis"></label>
                            <label class="analysis-control-field"><span>Código *</span><input type="text" name="codigo" id="equipoCodigo" maxlength="40" required placeholder="Ej. EQ-014"></label>
                            <label class="analysis-control-field"><span>Última calibración</span><input type="date" name="ultima_calibracion" id="equipoUltima"></label>
                            <label class="analysis-control-field"><span>Próxima calibración</span><input type="date" name="proxima_calibracion" id="equipoProxima"></label>
                            <label class="analysis-control-field analysis-control-field-wide"><span>Observaciones</span><input type="text" name="observaciones" id="equipoObs" maxlength="500" placeholder="Ej. Proveedor de calibración, ubicación…"></label>
                            <label class="analysis-control-check">
                                <input type="checkbox" name="activo" id="equipoActivo" value="1" checked>
                                <span><strong>En servicio</strong><small>Se ofrece como equipo por defecto en el catálogo de análisis.</small></span>
                            </label>
                        </div>
                    </div>
                    <footer class="analysis-control-modal-foot">
                        <button type="button" class="analysis-control-button" data-close-equipo>Cancelar</button>
                        <button type="submit" class="analysis-control-button is-primary"><?= catalogoAnalisisSvg('guardar') ?> Guardar equipo</button>
                    </footer>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canEdit && $tab === 'metodos' && $tiposMetodo): ?>
        <div class="analysis-control-modal" id="metodoModal" hidden>
            <div class="analysis-control-dialog" role="dialog" aria-modal="true" aria-labelledby="metodoModalTitle">
                <header class="analysis-control-modal-head">
                    <h2 id="metodoModalTitle">Nuevo método</h2>
                    <button type="button" class="analysis-control-close" data-close-metodo aria-label="Cerrar"><?= catalogoAnalisisSvg('cerrar') ?></button>
                </header>
                <form method="post" id="metodoForm">
                    <input type="hidden" name="seccion" value="metodos">
                    <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                    <input type="hidden" name="metodo_original" id="metodoOriginal">
                    <input type="hidden" name="norma_original" id="normaOriginal">
                    <div class="analysis-control-modal-body">
                        <div class="analysis-control-form-grid">
                            <label class="analysis-control-field"><span>Tipo de muestra *</span>
                                <select name="id_tipo_muestra" id="metodoTipo" required>
                                    <?php foreach ($tiposMetodo as $idTm => $nombreTm): ?><option value="<?= (int) $idTm ?>"><?= catalogoAnalisisE($nombreTm) ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label class="analysis-control-field"><span>Método *</span><input type="text" name="metodo" id="metodoNombre" maxlength="255" required placeholder="Ej. Walkley-Black (dicromato)"></label>
                            <label class="analysis-control-field analysis-control-field-wide"><span>Norma / Referencia</span><input type="text" name="norma" id="metodoNorma" maxlength="255" placeholder="Ej. NOM-021-RECNAT-2000 AS-07"></label>
                        </div>
                        <fieldset class="analysis-control-metodo-analisis">
                            <legend>Análisis que usan este método</legend>
                            <p class="analysis-control-muted" id="metodoAyuda">Marque los análisis a los que se aplica. Si un análisis tenía otro método, se reemplaza.</p>
                            <div id="metodoLista" class="analysis-control-metodo-lista"></div>
                        </fieldset>
                    </div>
                    <footer class="analysis-control-modal-foot">
                        <button type="button" class="analysis-control-button" data-close-metodo>Cancelar</button>
                        <button type="submit" class="analysis-control-button is-primary"><?= catalogoAnalisisSvg('guardar') ?> Guardar método</button>
                    </footer>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canEdit && $tab === 'campos' && !empty($tiposMuestra)): ?>
        <div class="analysis-control-modal" id="campoModal" hidden>
            <div class="analysis-control-dialog" role="dialog" aria-modal="true" aria-labelledby="campoModalTitle">
                <header class="analysis-control-modal-head">
                    <h2 id="campoModalTitle">Nuevo campo</h2>
                    <button type="button" class="analysis-control-close" data-close-campo aria-label="Cerrar"><?= catalogoAnalisisSvg('cerrar') ?></button>
                </header>
                <form method="post" id="campoForm">
                    <input type="hidden" name="seccion" value="campos">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="csrf_token" value="<?= catalogoAnalisisE($_SESSION['catalogo_analisis_csrf']) ?>">
                    <input type="hidden" name="id_tipo_muestra" value="<?= $campoTipoActual ?>">
                    <input type="hidden" name="id_campo" id="campoId">
                    <div class="analysis-control-modal-body">
                        <div class="analysis-control-form-grid">
                            <label class="analysis-control-field">
                                <span>Etiqueta visible</span>
                                <input type="text" name="etiqueta" id="campoEtiqueta" maxlength="150" required placeholder="Ej. Profundidad de muestreo">
                            </label>
                            <label class="analysis-control-field">
                                <span>Nombre técnico</span>
                                <input type="text" name="nombre" id="campoNombre" maxlength="80" placeholder="Se genera desde la etiqueta" pattern="[A-Za-z][A-Za-z0-9_]*">
                            </label>
                            <label class="analysis-control-field">
                                <span>Tipo de dato</span>
                                <select name="tipo_dato" id="campoTipoDato" required>
                                    <?php foreach ($camposTiposDato as $clave => $etiquetaTipo): ?>
                                        <option value="<?= catalogoAnalisisE($clave) ?>"><?= catalogoAnalisisE($etiquetaTipo) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="analysis-control-field">
                                <span>Unidad (opcional)</span>
                                <input type="text" name="unidad" id="campoUnidad" maxlength="40" placeholder="Ej. cm">
                            </label>
                            <label class="analysis-control-field">
                                <span>Orden</span>
                                <input type="number" name="orden" id="campoOrden" min="0" step="1" value="0">
                            </label>
                            <label class="analysis-control-field analysis-control-field-wide" id="campoOpcionesWrap" hidden>
                                <span>Opciones de la lista (una por línea)</span>
                                <textarea name="opciones" id="campoOpciones" rows="4" placeholder="Opción 1&#10;Opción 2"></textarea>
                            </label>
                            <label class="analysis-control-check">
                                <input type="checkbox" name="obligatorio" id="campoObligatorio" value="1">
                                <span><strong>Campo obligatorio</strong><small>Debe completarse en cada muestra identificada por ingreso manual.</small></span>
                            </label>
                            <label class="analysis-control-check">
                                <input type="checkbox" name="activo" id="campoActivo" value="1" checked>
                                <span><strong>Campo activo</strong><small>Visible como columna en Identificación de muestras para este tipo.</small></span>
                            </label>
                        </div>
                    </div>
                    <footer class="analysis-control-modal-foot">
                        <button type="button" class="analysis-control-button" data-close-campo>Cancelar</button>
                        <button type="submit" class="analysis-control-button is-primary"><?= catalogoAnalisisSvg('guardar') ?> Guardar campo</button>
                    </footer>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php lab_shell_content_close(); ?>
<script>
(function () {
    var catalog = <?= json_encode($catalogoJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var formatos = <?= json_encode((object) $formatosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var grupos = <?= json_encode((object) $gruposPorTipo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var equipos = <?= json_encode((object) $equiposJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var openModals = [];

    function normalize(value) {
        return String(value || '').toLocaleLowerCase('es').normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; });
    }
    function setValue(id, value) {
        var field = document.getElementById(id);
        if (field) field.value = value == null ? '' : value;
    }
    function showModal(modal, focusId) {
        if (!modal) return;
        modal.hidden = false;
        openModals.push(modal);
        document.body.classList.add('analysis-control-modal-open');
        var f = focusId && document.getElementById(focusId);
        if (f && !f.disabled) f.focus();
    }
    function hideModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        openModals = openModals.filter(function (m) { return m !== modal; });
        if (!openModals.length) document.body.classList.remove('analysis-control-modal-open');
    }

    // ---- Filtros de tablas --------------------------------------------
    function bindFilter(rowsSelector, emptyId, controls, matches) {
        var rows = Array.prototype.slice.call(document.querySelectorAll(rowsSelector));
        var empty = document.getElementById(emptyId);
        function apply() {
            var visible = 0;
            rows.forEach(function (row) { row.hidden = !matches(row); if (!row.hidden) visible++; });
            if (empty) empty.hidden = visible !== 0;
        }
        controls.forEach(function (c) { if (c) c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', apply); });
    }
    var typeFilter = document.getElementById('analysisTypeFilter'), search = document.getElementById('analysisSearch'), stateFilter = document.getElementById('analysisStateFilter');
    if (search) bindFilter('tr.analysis-control-row[data-type]', 'analysisFilteredEmpty', [typeFilter, search, stateFilter], function (row) {
        var term = normalize(search.value.trim());
        return (!typeFilter.value || row.dataset.type === typeFilter.value) && (!stateFilter.value || row.dataset.active === stateFilter.value) && (!term || normalize(row.dataset.search).indexOf(term) !== -1);
    });
    var auditSearch = document.getElementById('auditSearch'), auditModule = document.getElementById('auditModule');
    if (auditSearch) bindFilter('tr.audit-row', 'auditFilteredEmpty', [auditSearch, auditModule], function (row) {
        var term = normalize(auditSearch.value.trim());
        return (!auditModule.value || row.dataset.module === auditModule.value) && (!term || normalize(row.dataset.search).indexOf(term) !== -1);
    });

    // Disponibilidad en recepción: se guarda al marcar o desmarcar.
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-auto-submit]')) event.target.form.submit();
    });

    // ---- Editor de análisis -------------------------------------------
    var modal = document.getElementById('analysisModal');
    function fillGroups(idTipoMuestra, selected) {
        var select = document.getElementById('analysisGroup');
        if (!select) return;
        var lista = (grupos[idTipoMuestra] || ['Otros análisis']).slice();
        if (selected && lista.indexOf(selected) === -1) lista.unshift(selected);
        select.innerHTML = lista.map(function (g) { return '<option value="' + esc(g === 'Otros análisis' ? '' : g) + '"' + (g === selected ? ' selected' : '') + '>' + esc(g) + '</option>'; }).join('');
    }
    function fillEquipment(value) {
        var select = document.getElementById('analysisEquipment');
        if (!select) return;
        Array.prototype.slice.call(select.querySelectorAll('[data-legacy]')).forEach(function (o) { o.remove(); });
        if (value && !Array.prototype.some.call(select.options, function (o) { return o.value === value; })) {
            var opt = document.createElement('option');
            opt.value = value; opt.textContent = value + ' (no registrado en Equipos)'; opt.dataset.legacy = '1';
            select.appendChild(opt);
        }
        select.value = value || '';
    }
    function openEditor(id) {
        if (!modal) return;
        var item = id ? catalog[id] : null;
        if (id && !item) return;
        var typeSelect = document.getElementById('analysisSampleType');
        setValue('analysisId', item ? item.id_tipo : '');
        if (item) setValue('analysisSampleType', item.id_tipo_muestra);
        else typeSelect.value = (typeFilter && typeFilter.value) || (typeSelect.options[0] && typeSelect.options[0].value) || '';
        setValue('analysisName', item ? item.nombre : '');
        document.getElementById('analysisName').disabled = !!item;
        typeSelect.disabled = !!item;
        document.getElementById('analysisLockedNote').hidden = !item;
        document.getElementById('analysisMethod').required = !item;
        document.getElementById('analysisMethodReq').hidden = !!item;
        fillGroups(typeSelect.value, item ? item.grupo : '');
        setValue('analysisMethod', item ? item.metodo : '');
        setValue('analysisStandard', item ? item.norma : '');
        fillEquipment(item ? item.equipo_default : '');
        setValue('analysisUnit', item ? item.unidad : '');
        setValue('analysisMin', item ? item.limite_min : '');
        setValue('analysisMax', item ? item.limite_max : '');
        setValue('analysisTime', item ? item.duracion_dias : '');
        document.getElementById('analysisActive').checked = item ? !!item.activo : true;
        document.getElementById('analysisModalTitle').textContent = item ? ('Editar análisis — ' + item.nombre + ' (' + item.tipo + ')') : 'Nuevo análisis';
        document.getElementById('analysisSubmit').lastChild.textContent = item ? ' Guardar cambios' : ' Crear análisis';
        showModal(modal, item ? 'analysisMethod' : 'analysisName');
    }
    var sampleType = document.getElementById('analysisSampleType');
    if (sampleType) sampleType.addEventListener('change', function () { fillGroups(sampleType.value, ''); });

    // ---- Visor del formato de captura ---------------------------------
    var formatModal = document.getElementById('formatModal');
    function chips(list) {
        return list && list.length ? list.map(function (x) { return '<span class="analysis-control-chip">' + esc(x) + '</span>'; }).join(' ') : '<span class="analysis-control-muted">—</span>';
    }
    function openFormat(id) {
        var f = formatos[id];
        if (!f || !formatModal) return;
        document.getElementById('formatModalTitle').textContent = 'Formato de captura — ' + f.titulo + ' (' + f.tipo + ')';
        var html = '';
        if (f.analisis.length > 1) html += '<div class="analysis-control-message is-info">Esta hoja captura en la misma corrida: <strong>' + esc(f.analisis.join(', ')) + '</strong>.</div>';
        if (f.generico) {
            html += '<p>Captura directa del resultado: el analista registra el valor final de cada muestra, sin filas de control ni columnas calculadas.</p>';
        } else {
            html += '<h3 class="analysis-control-subtitle">Bandeja del analista</h3>'
                + '<p><strong>Encabezado:</strong> ' + chips(f.encabezado) + '</p>'
                + '<p><strong>Filas de control:</strong> ' + chips(f.controles) + '</p>'
                + '<p><strong>Columnas:</strong> ' + chips(['Lote', 'Número de laboratorio'].concat(f.cols)) + '</p>'
                + (f.curva ? '<p><strong>Curva de calibración:</strong> ' + esc(f.curva) + '</p>' : '')
                + '<h3 class="analysis-control-subtitle">Validación técnica y aprobación del jefe</h3>'
                + f.vistas.map(function (v) { return '<p><strong>' + esc(v.titulo) + ':</strong> ' + chips(['No. Lab', 'Lote'].concat(v.cols)) + (v.grafico ? ' <span class="analysis-control-status is-vigente">+ gráfico de curva</span>' : '') + '</p>'; }).join('')
                + (f.formula ? '<div class="analysis-control-formula"><strong>Cálculo:</strong> ' + esc(f.formula) + '</div>' : '');
        }
        document.getElementById('formatModalBody').innerHTML = html;
        showModal(formatModal);
    }

    // ---- Editor de equipos --------------------------------------------
    var equipoModal = document.getElementById('equipoModal');
    function openEquipo(id) {
        if (!equipoModal) return;
        var e = id ? equipos[id] : null;
        setValue('equipoId', e ? e.id_equipo : '');
        setValue('equipoNombre', e ? e.nombre : '');
        setValue('equipoCodigo', e ? e.codigo : '');
        setValue('equipoUltima', e ? e.ultima_calibracion : '');
        setValue('equipoProxima', e ? e.proxima_calibracion : '');
        setValue('equipoObs', e ? e.observaciones : '');
        document.getElementById('equipoActivo').checked = e ? !!e.activo : true;
        document.getElementById('equipoModalTitle').textContent = e ? 'Editar equipo — ' + e.nombre : 'Nuevo equipo';
        showModal(equipoModal, 'equipoNombre');
    }

    // ---- Métodos analíticos -------------------------------------------
    var metodoModal = document.getElementById('metodoModal');
    var metodos = <?= json_encode($metodosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var analisisPorTipo = <?= json_encode((object) $analisisPorTipo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var metodoActual = null;
    function escMetodo(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    function pintarAnalisisMetodo() {
        var tipo = document.getElementById('metodoTipo').value;
        var lista = analisisPorTipo[tipo] || [];
        var marcados = metodoActual ? metodoActual.ids.map(String) : [];
        document.getElementById('metodoLista').innerHTML = lista.length ? lista.map(function (a) {
            var otro = a.metodo && marcados.indexOf(String(a.id)) === -1
                ? '<small>Actual: ' + escMetodo(a.metodo) + (a.norma ? ' · ' + escMetodo(a.norma) : '') + '</small>'
                : (!a.metodo ? '<small class="is-sin">Sin método</small>' : '');
            return '<label class="analysis-control-check"><input type="checkbox" name="analisis[]" value="' + a.id + '"' + (marcados.indexOf(String(a.id)) !== -1 ? ' checked' : '') + '>'
                + '<span><strong>' + escMetodo(a.nombre) + (a.activo ? '' : ' <em>(inactivo)</em>') + '</strong>' + otro + '</span></label>';
        }).join('') : '<p class="analysis-control-muted">Este tipo de muestra no tiene análisis en el catálogo.</p>';
    }
    function openMetodo(indice) {
        if (!metodoModal) return;
        metodoActual = indice !== null && metodos[indice] ? metodos[indice] : null;
        var tipo = document.getElementById('metodoTipo');
        if (metodoActual) tipo.value = String(metodoActual.id_tipo_muestra);
        tipo.disabled = !!metodoActual;
        setValue('metodoNombre', metodoActual ? metodoActual.metodo : '');
        setValue('metodoNorma', metodoActual ? metodoActual.norma : '');
        setValue('metodoOriginal', metodoActual ? metodoActual.metodo : '');
        setValue('normaOriginal', metodoActual ? metodoActual.norma : '');
        document.getElementById('metodoAyuda').textContent = metodoActual
            ? 'Marque los análisis que usan este método. Desmarcar un análisis le quita el método; marcar otro le reemplaza el que tenía.'
            : 'Marque los análisis a los que se aplica. Si un análisis tenía otro método, se reemplaza.';
        document.getElementById('metodoModalTitle').textContent = metodoActual ? 'Editar método — ' + metodoActual.metodo + ' (' + metodoActual.tipo + ')' : 'Nuevo método';
        pintarAnalisisMetodo();
        showModal(metodoModal, 'metodoNombre');
    }
    if (metodoModal) {
        document.getElementById('metodoTipo').addEventListener('change', pintarAnalisisMetodo);
        document.getElementById('metodoForm').addEventListener('submit', function (e) {
            // Un select deshabilitado no se envía: se habilita justo antes de enviar.
            document.getElementById('metodoTipo').disabled = false;
            if (!metodoActual && !this.querySelector('input[name="analisis[]"]:checked')) {
                e.preventDefault();
                document.getElementById('metodoTipo').disabled = false;
                alert('Marque al menos un análisis que use este método.');
            }
        });
    }

    // ---- Campos por tipo de muestra -----------------------------------
    var campoSelect = document.getElementById('campoTypeSelect');
    if (campoSelect) {
        campoSelect.addEventListener('change', function () {
            window.location.href = 'catalogo_analisis.php?tab=campos&tipo=' + encodeURIComponent(campoSelect.value);
        });
    }
    var campoModal = document.getElementById('campoModal');
    var campoCatalog = <?= json_encode($camposJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function toggleCampoOpciones() {
        var tipo = document.getElementById('campoTipoDato');
        var wrap = document.getElementById('campoOpcionesWrap');
        var field = document.getElementById('campoOpciones');
        if (!tipo || !wrap) return;
        var esLista = tipo.value === 'lista';
        wrap.hidden = !esLista;
        if (field) field.required = esLista;
    }
    function openCampoEditor(id) {
        if (!campoModal) return;
        var item = id ? campoCatalog[id] : null;
        setValue('campoId', item ? item.id_campo : '');
        setValue('campoEtiqueta', item ? item.etiqueta : '');
        setValue('campoNombre', item ? item.nombre : '');
        document.getElementById('campoNombre').readOnly = !!(item && item.predefinido);
        setValue('campoTipoDato', item ? item.tipo_dato : 'texto');
        setValue('campoUnidad', item ? item.unidad : '');
        setValue('campoOrden', item ? item.orden : '0');
        setValue('campoOpciones', item ? item.opciones : '');
        document.getElementById('campoObligatorio').checked = item ? !!item.obligatorio : false;
        document.getElementById('campoActivo').checked = item ? !!item.activo : true;
        document.getElementById('campoModalTitle').textContent = item ? ('Editar campo — ' + item.etiqueta) : 'Nuevo campo';
        toggleCampoOpciones();
        showModal(campoModal, 'campoEtiqueta');
    }
    var campoTipoDato = document.getElementById('campoTipoDato');
    if (campoTipoDato) campoTipoDato.addEventListener('change', toggleCampoOpciones);

    document.addEventListener('click', function (event) {
        var t = event.target;
        if (t.closest('[data-new-analysis]')) openEditor(null);
        if (t.closest('[data-edit-analysis]')) openEditor(t.closest('[data-edit-analysis]').dataset.editAnalysis);
        if (t.closest('[data-formato]')) openFormat(t.closest('[data-formato]').dataset.formato);
        if (t.closest('[data-new-equipo]')) openEquipo(null);
        if (t.closest('[data-edit-equipo]')) openEquipo(t.closest('[data-edit-equipo]').dataset.editEquipo);
        if (t.closest('[data-new-campo]')) openCampoEditor(null);
        if (t.closest('[data-edit-campo]')) openCampoEditor(t.closest('[data-edit-campo]').dataset.editCampo);
        if (t.closest('[data-close-analysis]') || t === modal) hideModal(modal);
        if (t.closest('[data-close-format]') || t === formatModal) hideModal(formatModal);
        if (t.closest('[data-close-equipo]') || t === equipoModal) hideModal(equipoModal);
        if (t.closest('[data-close-campo]') || t === campoModal) hideModal(campoModal);
        if (t.closest('[data-new-metodo]')) openMetodo(null);
        if (t.closest('[data-edit-metodo]')) openMetodo(Number(t.closest('[data-edit-metodo]').dataset.editMetodo));
        if (t.closest('[data-close-metodo]') || (metodoModal && t === metodoModal)) hideModal(metodoModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openModals.length) hideModal(openModals[openModals.length - 1]);
    });
    <?php if ($errorMensaje !== '' && $tab === 'analisis' && $seccionPost === 'analisis' && $canEdit): ?>
    // Reabre el editor con lo escrito si el guardado falló.
    (function () {
        var previo = <?= json_encode(['id' => (int) ($_POST['id_tipo'] ?? 0)], JSON_HEX_TAG) ?>;
        openEditor(previo.id || null);
        <?php foreach (['analysisName' => 'nombre', 'analysisMethod' => 'metodo', 'analysisStandard' => 'norma', 'analysisUnit' => 'unidad', 'analysisMin' => 'limite_min', 'analysisMax' => 'limite_max', 'analysisTime' => 'duracion_dias'] as $idCampo => $campoPost): ?>
        <?php if (isset($_POST[$campoPost])): ?>setValue(<?= json_encode($idCampo) ?>, <?= json_encode((string) $_POST[$campoPost], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);<?php endif; ?>
        <?php endforeach; ?>
    })();
    <?php endif; ?>
})();
</script>
</body>
</html>
