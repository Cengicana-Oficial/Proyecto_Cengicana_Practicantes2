<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/catalogo_muestras_helper.php';

lab_require_permission('laboratorio.solicitudes.crear');

$loteSeleccionado = trim((string) ($_GET['lote'] ?? ''));
$currentUser = lab_current_user();
$catalogoMuestras = labCatalogoMuestrasFormularioData($conexion, true);

function menuSolicitudUrl(string $tipo, string $lote): string
{
    $url = 'solicitud_formulario.php?tipo=' . rawurlencode($tipo);
    if ($lote !== '') {
        $url .= '&lote=' . rawurlencode($lote);
    }

    return $url;
}

function menuSolicitudEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function menuSolicitudAvatar(array $user): string
{
    $name = trim((string) ($user['nombre'] ?? ''));
    if ($name === '') {
        return 'LAB';
    }

    $initial = function_exists('mb_substr')
        ? mb_substr($name, 0, 1, 'UTF-8')
        : substr($name, 0, 1);

    return strtoupper((string) $initial);
}

$menuSolicitudAvatar = menuSolicitudAvatar($currentUser);
$newRequestHref = 'menu_solicitud.php' . ($loteSeleccionado !== '' ? '?lote=' . rawurlencode($loteSeleccionado) : '');

$sampleVisuals = [
    'suelos' => ['icon' => 'fa-mountain', 'subtitle' => 'Análisis físico químico', 'accent' => 'suelos'],
    'foliares' => ['icon' => 'fa-leaf', 'subtitle' => 'Tejido vegetal y nutrición', 'accent' => 'foliares'],
    'cana' => ['icon' => 'fa-tractor', 'subtitle' => 'Rendimiento y sacarosa', 'accent' => 'cana'],
    'miel' => ['icon' => 'fa-mug-hot', 'subtitle' => 'Pureza y componentes', 'accent' => 'miel'],
    'agua' => ['icon' => 'fa-droplet', 'subtitle' => 'Calidad de riego y consumo', 'accent' => 'agua', 'wide' => true],
];

$sampleCards = [];
foreach ($catalogoMuestras as $clave => $muestra) {
    $meta = $sampleVisuals[$clave] ?? ['icon' => 'fa-vial', 'subtitle' => 'Registro de muestra', 'accent' => 'suelos'];
    $sampleCards[] = [
        'type' => $clave,
        'title' => (string) ($muestra['nombre'] ?? $muestra['label'] ?? ucfirst($clave)),
        'subtitle' => $meta['subtitle'],
        'icon' => $meta['icon'],
        'accent' => $meta['accent'],
        'wide' => !empty($meta['wide']),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <title>Nuevo análisis — AgroLab</title>

    <link rel="stylesheet" href="../styles/base.css">
    <link rel="stylesheet" href="../css/menu_solicitud.css?v=3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="page-shell">
    <header class="topbar">
        <div class="topbar-left">
            <a class="brand-chip" href="../index.php" aria-label="Ir al escritorio">
                LAB
            </a>

            <nav class="topnav" aria-label="Navegación principal">
                <a href="../index.php">Escritorio</a>
                <a class="active" href="listar_lotes.php" aria-current="page">Muestras</a>
                <a href="../index.php">Laboratorio</a>
            </nav>
        </div>

        <div class="topbar-actions">
            <a class="action-button primary" href="<?= menuSolicitudEscape($newRequestHref) ?>">
                <i class="fa-solid fa-plus"></i>
                <span>Nueva solicitud</span>
            </a>

            <a class="action-button subtle" href="dashboard.php">Historial</a>

            <button type="button" class="icon-button" aria-label="Notificaciones">
                <i class="fa-regular fa-bell"></i>
            </button>

            <button type="button" class="icon-button" aria-label="Configuración">
                <i class="fa-solid fa-gear"></i>
            </button>

            <div class="avatar-chip" title="<?= menuSolicitudEscape((string) ($currentUser['nombre'] ?? 'Usuario')) ?>">
                <?= menuSolicitudEscape($menuSolicitudAvatar) ?>
            </div>
        </div>
    </header>

    <main class="page-main">
        <section class="request-panel">
            <div class="panel-head">
                <h1>Nuevo análisis</h1>
                <p>Seleccione el tipo de muestra que desea registrar para iniciar el proceso</p>
            </div>

            <div class="sample-grid" aria-label="Tipos de muestra disponibles">
                <?php foreach ($sampleCards as $card): ?>
                    <?php
                        $classes = ['sample-card', 'sample-card--' . $card['accent']];
                        if (!empty($card['wide'])) {
                            $classes[] = 'sample-card--wide';
                        }
                    ?>
                    <a
                        class="<?= menuSolicitudEscape(implode(' ', $classes)) ?>"
                        href="<?= menuSolicitudEscape(menuSolicitudUrl($card['type'], $loteSeleccionado)) ?>">
                        <span class="sample-card-icon" aria-hidden="true">
                            <i class="fa-solid <?= menuSolicitudEscape($card['icon']) ?>"></i>
                        </span>

                        <span class="sample-card-copy">
                            <strong><?= menuSolicitudEscape($card['title']) ?></strong>
                            <small><?= menuSolicitudEscape($card['subtitle']) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="info-callout">
                <div class="info-callout-icon" aria-hidden="true">
                    <i class="fa-solid fa-circle-info"></i>
                </div>

                <p>
                    Al elegir un tipo, el sistema abrirá automáticamente el formulario correspondiente con sus
                    análisis y filtros configurados específicamente para ese rubro.
                </p>
            </div>

            <footer class="panel-footer">
                <a class="back-link" href="../index.php">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Volver al inicio</span>
                </a>
            </footer>
        </section>
    </main>
</div>
</body>
</html>
