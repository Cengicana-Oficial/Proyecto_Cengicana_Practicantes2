<?php
/**
 * Inicio de CENGILABS: bienvenida, indicadores del día, lo que le toca atender al usuario
 * (mismas notificaciones de la campanita), el flujo del laboratorio por etapa (según permisos)
 * y los tipos de muestra activos para registrar una nueva recepción.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/shell_sidebar.php';
require_once __DIR__ . '/includes/notificaciones_helper.php';
require_once __DIR__ . '/includes/catalogo_muestras_helper.php';

lab_require_module_access();

$usuario = lab_current_user();
$canRecepcion = lab_can('laboratorio.solicitudes.crear');
$canCaptura = lab_can('laboratorio.formularios_pendientes.ver') || lab_is_technician();

function ini_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// ---- Datos del día (a prueba de fallos: si algo no está disponible, se muestra 0 o se omite).
$tareas = [];
try {
    $tareas = wfTareas($conexion);
} catch (Throwable $e) {
    error_log('[laboratorio][inicio] tareas: ' . $e->getMessage());
}
$conteo = ['pendiente' => 0, 'proceso' => 0, 'rechazado' => 0, 'repetido' => 0, 'validacion' => 0, 'validado' => 0];
$sinAsignar = 0;
$lotesValidados = [];
foreach ($tareas as $t) {
    $k = $t['estado']['key'] ?? '';
    $conteo[$k] = ($conteo[$k] ?? 0) + 1;
    if ($k === 'pendiente' && empty($t['usuario_id']) && trim((string) ($t['analista'] ?? '')) === '') $sinAsignar++;
    $id = (int) $t['id_lote'];
    $lotesValidados[$id] = ($lotesValidados[$id] ?? true) && $k === 'validado';
}
$porCapturar = $conteo['pendiente'] + $conteo['proceso'] + $conteo['rechazado'] + $conteo['repetido'];

$muestrasHoy = 0;
$informesFirmados = [];
try {
    $muestrasHoy = (int) $conexion->query('SELECT COALESCE(SUM(numero_muestras), 0) FROM solicitud WHERE fecha_ingreso = CURDATE()')->fetchColumn();
    foreach ($conexion->query("SELECT id_lote FROM lab_informe WHERE estado = 'FIRMADO'") as $fila) {
        $informesFirmados[(int) $fila['id_lote']] = true;
    }
} catch (Throwable $e) {
    error_log('[laboratorio][inicio] indicadores: ' . $e->getMessage());
}
$listosFirma = count(array_filter($lotesValidados, static fn($ok, $id) => $ok && empty($informesFirmados[$id]), ARRAY_FILTER_USE_BOTH));

$pendientes = [];
try {
    $pendientes = lab_notificaciones($conexion);
} catch (Throwable $e) {
    error_log('[laboratorio][inicio] notificaciones: ' . $e->getMessage());
}

// ---- Flujo del laboratorio: solo las etapas que el usuario puede abrir.
$etapas = array_values(array_filter([
    ['ok' => $canRecepcion, 'href' => 'view/solicitud_formulario.php', 'icono' => 'inbox', 'titulo' => 'Recepción', 'texto' => 'Registro de boletas y muestras', 'n' => $muestrasHoy, 'nl' => 'muestras hoy'],
    ['ok' => lab_can('laboratorio.lotes.ver'), 'href' => 'view/listar_lotes.php', 'icono' => 'qr_code_scanner', 'titulo' => 'Identificación', 'texto' => 'Escaneo y etiquetas por lote', 'n' => null, 'nl' => ''],
    ['ok' => lab_can('laboratorio.kanban.ver'), 'href' => 'view/kanban_view.php', 'icono' => 'view_kanban', 'titulo' => 'Planificación', 'texto' => 'Asignación de analistas y prioridad', 'n' => $sinAsignar, 'nl' => 'sin asignar'],
    ['ok' => $canCaptura, 'href' => 'view/solicitudes_pendientes_tecnico.php', 'icono' => 'checklist', 'titulo' => 'Bandeja del analista', 'texto' => 'Captura en hojas de trabajo', 'n' => $porCapturar, 'nl' => 'por capturar'],
    ['ok' => lab_can('laboratorio.validacion_tecnica.ver'), 'href' => 'view/validacion_tecnica_view.php', 'icono' => 'fact_check', 'titulo' => 'Validación técnica', 'texto' => 'Revisión de cálculos y controles', 'n' => $conteo['validacion'], 'nl' => 'por validar'],
    ['ok' => lab_can('laboratorio.informes.ver'), 'href' => 'view/informes_view.php', 'icono' => 'summarize', 'titulo' => 'Informes', 'texto' => 'Aprobación y firma del jefe', 'n' => $listosFirma, 'nl' => 'por firmar'],
    ['ok' => lab_can('laboratorio.documentos.ver'), 'href' => 'view/documentos_view.php', 'icono' => 'description', 'titulo' => 'Reportes', 'texto' => 'Entrega de informes firmados', 'n' => null, 'nl' => ''],
], static fn($e) => $e['ok']));

// ---- Tipos de muestra activos (catálogo) para registrar una recepción.
$presentacion = [
    'suel' => ['img' => 'assets/suelos.jpeg', 'texto' => 'Fertilidad y propiedades físicas: pH, materia orgánica, macro y micronutrientes, textura y humedad.'],
    'foliar' => ['img' => 'assets/foliares.jpeg', 'texto' => 'Estado nutricional del cultivo a partir de tejido vegetal: nitrógeno, fósforo, potasio, micronutrientes y boro.'],
    'agua' => ['img' => 'assets/aguas.jpeg', 'texto' => 'Agua para riego y aplicaciones agrícolas: pH, conductividad, sales, dureza, bicarbonatos y micronutrientes.'],
    'cana' => ['img' => 'assets/ca%C3%B1as.jpeg', 'texto' => 'Calidad de la materia prima: Brix, Pol, fibra, humedad y peso seco.'],
    'miel' => ['img' => 'assets/Mieles.jpeg', 'texto' => 'Mieles, jugos y masas: Brix, Pol y azúcares por HPLC (sacarosa, glucosa y fructosa).'],
];
$tiposMuestra = [];
if ($canRecepcion) {
    try {
        foreach (labCatalogoMuestrasFormularioData($conexion, true) as $clave => $tipo) {
            $norm = strtr(mb_strtolower($clave . ' ' . $tipo['nombre'], 'UTF-8'), ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
            $pres = null;
            foreach ($presentacion as $raiz => $p) {
                if (str_contains($norm, $raiz)) { $pres = $p; break; }
            }
            $tiposMuestra[] = [
                'clave' => $clave,
                'titulo' => $tipo['label_plural'] ?: $tipo['label'] ?: $tipo['nombre'],
                'prefijo' => $tipo['prefijo'],
                'analisis' => $tipo['analisis_activos'],
                'img' => $pres['img'] ?? null,
                'texto' => $pres['texto'] ?? 'Registro de muestras y análisis solicitados.',
            ];
        }
    } catch (Throwable $e) {
        error_log('[laboratorio][inicio] catálogo: ' . $e->getMessage());
    }
}

// ---- Saludo
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$nombre = trim((string) ($usuario['nombre'] ?? ''));
$primerNombre = $nombre !== '' ? preg_split('/\s+/', $nombre)[0] : '';
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaHoy = ucfirst($dias[(int) date('w')]) . ' ' . date('j') . ' de ' . $meses[(int) date('n')] . ' de ' . date('Y');
$totalPendientes = array_sum(array_column($pendientes, 'cantidad'));

lab_shell_head('Inicio', 'Laboratorio agroindustrial CENGICAÑA', [
    'css/inicio.css?v=' . filemtime(__DIR__ . '/css/inicio.css'),
]);
lab_shell_open('index.php');
?>
<div class="ini">
    <section class="ini-hero">
        <div class="ini-hero-copy">
            <span class="ini-eyebrow"><?= ini_e($fechaHoy) ?></span>
            <h1><?= ini_e($saludo) ?><?= $primerNombre !== '' ? ', ' . ini_e($primerNombre) : '' ?></h1>
            <p><?= $totalPendientes > 0
                ? 'Tiene <b>' . (int) $totalPendientes . '</b> pendiente(s) por atender hoy.'
                : 'Está al día: no tiene pendientes asignados en este momento.' ?></p>
        </div>
        <div class="ini-hero-actions">
            <?php if ($canRecepcion): ?>
                <a class="ini-btn ini-btn-primary" href="#nueva-recepcion"><span class="material-symbols-outlined" aria-hidden="true">add_circle</span>Registrar recepción</a>
            <?php endif; ?>
            <?php if ($canCaptura): ?>
                <a class="ini-btn ini-btn-light" href="view/solicitudes_pendientes_tecnico.php"><span class="material-symbols-outlined" aria-hidden="true">checklist</span>Mi bandeja</a>
            <?php endif; ?>
            <a class="ini-btn ini-btn-light" href="view/dashboard.php"><span class="material-symbols-outlined" aria-hidden="true">monitoring</span>Dashboard</a>
        </div>
    </section>

    <section class="ini-kpis" aria-label="Indicadores del día">
        <article class="ini-kpi">
            <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">inbox</span></span>
            <div><strong><?= $muestrasHoy ?></strong><span>Muestras recibidas hoy</span></div>
        </article>
        <article class="ini-kpi">
            <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">science</span></span>
            <div><strong><?= $porCapturar ?></strong><span>Análisis por capturar</span></div>
        </article>
        <article class="ini-kpi">
            <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">fact_check</span></span>
            <div><strong><?= $conteo['validacion'] ?></strong><span>En validación técnica</span></div>
        </article>
        <article class="ini-kpi">
            <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">draw</span></span>
            <div><strong><?= $listosFirma ?></strong><span>Informes por firmar</span></div>
        </article>
    </section>

    <div class="ini-grid">
        <section class="ini-card" aria-labelledby="ini-pend-titulo">
            <header class="ini-card-head">
                <h2 id="ini-pend-titulo">Lo que le toca atender</h2>
                <?php if ($totalPendientes > 0): ?><span class="ini-count"><?= (int) $totalPendientes ?></span><?php endif; ?>
            </header>
            <?php if ($pendientes): ?>
                <ul class="ini-tasks">
                    <?php foreach ($pendientes as $p): ?>
                        <li><a class="ini-task is-<?= ini_e($p['tono']) ?>" href="<?= ini_e($p['href']) ?>">
                            <span class="ini-task-num"><?= (int) $p['cantidad'] ?></span>
                            <span class="ini-task-text"><b><?= ini_e($p['titulo']) ?></b><small><?= ini_e($p['detalle']) ?></small></span>
                            <span class="material-symbols-outlined ini-task-go" aria-hidden="true">chevron_right</span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ini-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
                    <p><b>Todo al día.</b> Cuando le asignen análisis, validaciones o informes aparecerán aquí y en la campanita.</p>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($etapas): ?>
            <section class="ini-card" aria-labelledby="ini-flujo-titulo">
                <header class="ini-card-head"><h2 id="ini-flujo-titulo">Flujo del laboratorio</h2></header>
                <ol class="ini-flow">
                    <?php foreach ($etapas as $i => $et): ?>
                        <li><a class="ini-step" href="<?= ini_e($et['href']) ?>">
                            <span class="ini-step-icon"><span class="material-symbols-outlined" aria-hidden="true"><?= ini_e($et['icono']) ?></span></span>
                            <span class="ini-step-text"><b><?= ini_e($et['titulo']) ?></b><small><?= ini_e($et['texto']) ?></small></span>
                            <?php if ($et['n'] !== null): ?>
                                <span class="ini-step-badge<?= $et['n'] > 0 ? ' is-on' : '' ?>"><?= (int) $et['n'] ?> <small><?= ini_e($et['nl']) ?></small></span>
                            <?php endif; ?>
                        </a></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($canRecepcion && $tiposMuestra): ?>
        <section id="nueva-recepcion" class="ini-section" aria-labelledby="ini-tipos-titulo">
            <header class="ini-section-head">
                <div>
                    <h2 id="ini-tipos-titulo">Registrar nueva recepción</h2>
                    <p>Seleccione el tipo de muestra para abrir la boleta de recepción con sus análisis disponibles.</p>
                </div>
            </header>
            <div class="ini-types">
                <?php foreach ($tiposMuestra as $tipo): ?>
                    <a class="ini-type" href="view/solicitud_formulario.php?tipo=<?= rawurlencode($tipo['clave']) ?>">
                        <div class="ini-type-media">
                            <?php if ($tipo['img']): ?><img src="<?= ini_e($tipo['img']) ?>" alt="" loading="lazy"><?php endif; ?>
                            <span class="ini-type-prefix"><?= ini_e($tipo['prefijo']) ?></span>
                        </div>
                        <div class="ini-type-body">
                            <h3><?= ini_e($tipo['titulo']) ?></h3>
                            <p><?= ini_e($tipo['texto']) ?></p>
                            <div class="ini-type-foot">
                                <span><?= (int) $tipo['analisis'] ?> análisis disponibles</span>
                                <span class="ini-type-cta">Registrar <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php lab_shell_close(); ?>
