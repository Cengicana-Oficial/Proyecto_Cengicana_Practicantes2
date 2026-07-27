<?php
require_once __DIR__ . '/includes/auth.php';

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratorio</title>

    <link rel="stylesheet" href="css/laboratorio.css?v=2">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<div class="sidebar closed" id="sidebar">

    <div class="toggle-btn" id="toggleBtn">
        <i class="fas fa-bars"></i>
    </div>

    <ul class="menu">

        <?php if (lab_can('laboratorio.usuarios.gestionar') && is_file(__DIR__ . '/usuarios.php')): ?>
            <li>
                <a href="usuarios.php">
                    <i class="fas fa-user"></i>
                    <span>Usuarios</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if (is_file(__DIR__ . '/muestras.php') && lab_can('laboratorio.muestras.ver')): ?>
            <li>
                <a href="muestras.php">
                    <i class="fas fa-vial"></i>
                    <span>Muestras</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($canLotes): ?>
            <li>
                <a href="view/listar_lotes.php">
                    <i class="fas fa-box"></i>
                    <span>Lotes</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($canFormulariosPendientes): ?>
            <li>
                <a href="view/solicitudes_pendientes_tecnico.php">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Pendientes</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($canLabc || $canAnalisis || $canBlancoControl || $canConsolidacion || $canCatalogoAnalisis || $canCatalogoMuestras): ?>
            <li>
                <a href="view/labc_index.php">
                    <i class="fas fa-flask-vial"></i>
                    <span>LABC</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($canCatalogoAnalisis): ?>
            <li>
                <a href="catalogo_analisis.php">
                    <i class="fas fa-table-list"></i>
                    <span>Catálogo de análisis</span>
                </a>
            </li>
        <?php endif; ?>
        <?php if ($canCatalogoMuestras): ?>
            <li>
                <a href="catalogo_muestras.php">
                    <i class="fas fa-vials"></i>
                    <span>Catálogo de muestras</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($canConsolidacion): ?>
            <li>
                <a href="controllers/consolidacion_controller.php">
                    <i class="fas fa-eye"></i>
                    <span>Vista</span>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($canFormulariosErroneos): ?>
            <li>
                <a href="controllers/formularios_erroneos_controller.php">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Formularios erróneos</span>
                </a>
            </li>
        <?php endif; ?>

    </ul>

    <!-- LOGOUT -->
    <a href="<?= htmlspecialchars(lab_logout_url(), ENT_QUOTES, 'UTF-8') ?>" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        <span>Cerrar sesion</span>
    </a>

</div>

<div class="main-content" id="mainContent">

    <!-- LOGO -->
    <div class="background-logo">
        <img src="img/logo.png" alt="">
    </div>
  
    <!-- TITULO -->
    <h1 class="titulo-modulo">Laboratorio</h1>

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

</div>

<script src="js/sidebar.js?v=2"></script>

</body>
</html>
