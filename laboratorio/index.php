<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/shell_sidebar.php';

lab_require_module_access();

$canAnalisis = lab_can('laboratorio.formularios_labc.ver') || lab_can('laboratorio.analisis.ver');
$canCatalogoAnalisis = lab_can('laboratorio.catalogo_analisis.ver');
$canCatalogoMuestras = lab_can('laboratorio.catalogo_muestras.ver');
$canConsolidacion = lab_can('laboratorio.consolidacion.ver');
$canLotes = lab_can('laboratorio.lotes.ver');
$canLabc = lab_can('laboratorio.labc.ver');
$canBlancoControl = lab_can('laboratorio.blanco_control.ver');
$canFormulariosErroneos = lab_can_view_error_forms();
$canFormulariosPendientes = lab_can('laboratorio.formularios_pendientes.ver') || lab_is_technician();
$canCreateSolicitud = lab_can('laboratorio.solicitudes.crear');

$nuevoAnalisisCards = [
    [
        'tipo' => 'suelo-fisico',
        'titulo' => 'Suelos',
        'imagen' => 'assets/suelos.jpeg',
        'descripcion' => 'Registro de muestras de suelo para análisis químicos y físicos orientados a la evaluación de fertilidad y propiedades agronómicas.',
    ],
    [
        'tipo' => 'foliares',
        'titulo' => 'Foliares',
        'imagen' => 'assets/foliares.jpeg',
        'descripcion' => 'Registro de muestras de tejidos vegetales para evaluar el estado nutricional del cultivo e identificar deficiencias o excesos de nutrientes que apoyen la toma de decisiones agronómicas.',
    ],
    [
        'tipo' => 'cana',
        'titulo' => 'Caña',
        'imagen' => 'assets/ca%C3%B1as.jpeg',
        'descripcion' => 'Ingrese muestras de cana para registrar lotes, rangos y determinaciones requeridas en el proceso.',
    ],
    [
        'tipo' => 'miel',
        'titulo' => 'Miel',
        'imagen' => 'assets/Mieles.jpeg',
        'descripcion' => 'Registro de muestras caña, jugos, masas y mieles para evaluar calidad y pureza mediante Brix y Pol, además de HPLC para determinar concentraciones de sacarosa, glucosa y fructosa.',
    ],
    [
        'tipo' => 'agua',
        'titulo' => 'Agua',
        'imagen' => 'assets/aguas.jpeg',
        'descripcion' => 'Analisis de agua para riego y pulverización agrícola
Registro de muestras de agua para identificar compuestos que pueden afectar cultivos, suelos, agroquímicos y sistemas de riego, apoyando la toma de decisiones en el manejo agrícola.',
    ],
];

function labNuevoAnalisisUrl(string $tipo): string
{
    return 'view/solicitud_formulario.php?tipo=' . rawurlencode($tipo);
}

lab_shell_head('Laboratorio', 'Registro y seguimiento de analisis del laboratorio agroindustrial', [
    'css/laboratorio.css?v=2',
]);
lab_shell_open('index.php');
?>

<?php if ($canCreateSolicitud): ?>
    <section class="menu-intro">
        <span>Nuevo analisis</span>
        <p>Seleccione el tipo de muestra para abrir el formulario de solicitud correspondiente.</p>
    </section>

    <div class="cards-container">
        <?php foreach ($nuevoAnalisisCards as $card): ?>
            <a class="info-card analysis-card" href="<?= htmlspecialchars(labNuevoAnalisisUrl($card['tipo']), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($card['imagen'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($card['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="card-body">
                    <h2><?= htmlspecialchars($card['titulo'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars($card['descripcion'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php lab_shell_close(); ?>

