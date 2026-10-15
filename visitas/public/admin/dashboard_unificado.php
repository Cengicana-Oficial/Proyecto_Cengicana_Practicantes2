<?php
require_once("../../config/auth.php");
require_login();
require_once __DIR__ . '/../../config/presentation.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = conexion::conectar();
$modulo = $_GET['modulo'] ?? 'solicitudes';

$canInicio = can_access('ver_dashboard');
$canUsuarios = false; // Los usuarios se administran centralmente desde CengiSalas.
$canAreas = can_access_any(['gestionar_areas', 'gestionar_solicitudes']);
$canSolicitudes = can_access_any(['ver_solicitudes', 'gestionar_solicitudes', 'ver_solicitudes_aprobadas']);

// Las áreas ahora se administran dentro del dashboard unificado de visitas;
// conserva esta ruta solamente como redirección para enlaces antiguos.
if ($modulo === 'areas') {
    if ($canAreas) {
        header('Location: dashboard_unificado.php?modulo=solicitudes&vista=areas');
        exit;
    }
    $modulo = 'inicio';
} elseif ($modulo === 'usuarios' && !$canUsuarios) {
    $modulo = 'inicio';
} elseif ($modulo === 'solicitudes' && !$canSolicitudes) {
    $modulo = 'inicio';
}

if (!$canInicio && !$canUsuarios && !$canAreas && !$canSolicitudes) {
    $sinPermisos = true;
} else {
    $sinPermisos = false;
}

$totalUsuarios = 0;
$totalAreas = 0;
$pendientes = 0;
$aprobadas = 0;
$rechazadas = 0;

if ($canUsuarios) {
    try {
        $connUsuarios = Conexion::conectarUsuariosMenu();
        $stmtUsuarios = $connUsuarios->prepare("
            SELECT COUNT(DISTINCT u.id)
            FROM usuarios u
            INNER JOIN usuario_modulo um ON um.usuario_id = u.id
            INNER JOIN modulos m ON m.id = um.modulo_id
            WHERE LOWER(m.nombre) = 'solicitud de visitas'
        ");
        $stmtUsuarios->execute();
        $totalUsuarios = $stmtUsuarios->fetchColumn();
    } catch (Exception $e) {
        $totalUsuarios = 0;
    }
}

try {
    $totalAreas = $conn->query("SELECT COUNT(*) FROM areas_interes WHERE estado = 1")->fetchColumn();
    $sqlEstados = "
        SELECT UPPER(TRIM(e.nombre_estado)) AS nombre_estado, COUNT(*) AS total
        FROM solicitudes s
        JOIN estados e ON s.id_estado = e.id_estado
        GROUP BY UPPER(TRIM(e.nombre_estado))
    ";
    foreach ($conn->query($sqlEstados) as $row) {
        if (in_array($row['nombre_estado'], ['ENVIADO', 'PENDIENTE'])) {
            $pendientes += (int) $row['total'];
        } elseif ($row['nombre_estado'] === 'APROBADO') {
            $aprobadas = (int) $row['total'];
        } elseif ($row['nombre_estado'] === 'RECHAZADO') {
            $rechazadas = (int) $row['total'];
        }
    }
} catch (Exception $e) {
}

if ($modulo === 'solicitudes' && $canSolicitudes) {
    define('VISITS_DATA_ONLY', true);
    require __DIR__ . '/modulos/solicitudes_unificadas.php';
    require __DIR__ . '/modulos/solicitudes_dashboard_exacto.php';
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Unificado</title>
    <link rel="stylesheet" href="../../assets/dashboard.css">
    <link rel="stylesheet" href="../../assets/inicio.css">
    <link rel="stylesheet" href="../../assets/cengisalas-theme.css?v=20260902-2">
<?php visitas_ui_head(); ?>
</head>
<body class="visits-admin">
<?php visitas_ui_shell("Panel de Visitas", "Resumen de solicitudes y áreas", "inicio"); ?>

<div class="layout">

    <main class="main-content" id="visits-content" tabindex="-1">
        <?php if ($sinPermisos): ?>
            <header class="dashboard-header">
                <h2>Sin permisos asignados</h2>
            </header>
            <p>No tienes permisos activos para este módulo.</p>
        <?php elseif ($modulo === 'usuarios' && $canUsuarios): ?>
            <?php include 'modulos/usuarios.php'; ?>
        <?php elseif ($modulo === 'solicitudes' && $canSolicitudes): ?>
            <?php include 'modulos/solicitudes_unificadas.php'; ?>
        <?php else: ?>
            <header class="dashboard-header">
                <h2>Dashboard General</h2>
            </header>

            <div class="cards">
                <?php if ($canUsuarios): ?>
                    <div class="card green">
                        <div class="icon">US</div>
                        <h3>Usuarios</h3>
                        <h2><?= $totalUsuarios ?></h2>
                    </div>
                <?php endif; ?>

                <?php if ($canAreas): ?>
                    <div class="card blue">
                        <div class="icon">AR</div>
                        <h3>Areas</h3>
                        <h2><?= $totalAreas ?></h2>
                    </div>
                <?php endif; ?>

                <?php if ($canSolicitudes): ?>
                    <div class="card orange">
                        <div class="icon">PE</div>
                        <h3>Pendientes</h3>
                        <h2><?= $pendientes ?></h2>
                    </div>

                    <div class="card blue">
                        <div class="icon">AP</div>
                        <h3>Aprobadas</h3>
                        <h2><?= $aprobadas ?></h2>
                    </div>

                    <div class="card red">
                        <div class="icon">RE</div>
                        <h3>Rechazadas</h3>
                        <h2><?= $rechazadas ?></h2>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<div id="modalPDF" class="modal-pdf">
    <div class="modal-container-pdf">
        <div class="modal-header-pdf">
            <h3>Visualizar PDF</h3>
            <button class="btn-cerrar-pdf" onclick="cerrarModalPDF()">&times;</button>
        </div>
        <div class="modal-body-pdf">
            <embed id="pdfViewer" src="" type="application/pdf" style="width:100%; height:100%;">
        </div>
    </div>
</div>

<script>
function mostrarPDF(btn) {
    const archivo = btn.getAttribute('data-archivo');
    const tipo = btn.getAttribute('data-type') || 'cartas';

    if (!archivo) {
        alert('No hay archivo PDF');
        return;
    }

    document.getElementById('pdfViewer').src =
        'ver_pdf.php?tipo=' + encodeURIComponent(tipo) + '&file=' + encodeURIComponent(archivo);
    document.getElementById('modalPDF').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function cerrarModalPDF() {
    document.getElementById('modalPDF').style.display = 'none';
    document.getElementById('pdfViewer').src = '';
    document.body.style.overflow = 'auto';
}

document.getElementById('modalPDF').addEventListener('click', function (e) {
    if (e.target === this) cerrarModalPDF();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrarModalPDF();
});

document.querySelectorAll('.btn-enviar-correo').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const id = this.getAttribute('data-id');
        const btnEl = this;

        btnEl.disabled = true;
        btnEl.textContent = 'Enviando...';

        const formData = new FormData();
        formData.append('id_solicitud', id);

        fetch('enviar_correo.php', {
            method: 'POST',
            body: formData,
            signal: AbortSignal.timeout(30000)
        })
        .then(res => {
            if (!res.ok) {
                throw new Error('Error HTTP ' + res.status);
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                const span = document.createElement('span');
                span.className = 'mail-status sent';
                span.textContent = 'Enviado';
                btnEl.replaceWith(span);
            } else {
                btnEl.disabled = false;
                btnEl.textContent = 'Enviar';
                alert('Error: ' + (data.error || 'No se pudo enviar'));
            }
        })
        .catch(error => {
            btnEl.disabled = false;
            btnEl.textContent = 'Enviar';
            alert('Error de conexion: ' + error.message);
        });
    });
});
</script>

</body>
</html>
