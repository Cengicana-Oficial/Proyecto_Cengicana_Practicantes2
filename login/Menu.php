<?php
session_start();
require_once(__DIR__ . "/config/conexion.php");

// 🔒 Validar sesión
if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$titulo = "Plataforma de Gestión";

$conn = Conexion::conectar();
$id_usuario = $_SESSION['id_usuario'];

$stmtEnsureModulo = $conn->prepare("
    INSERT INTO modulos (nombre)
    SELECT ?
    WHERE NOT EXISTS (
        SELECT 1
        FROM modulos
        WHERE LOWER(nombre) = LOWER(?)
    )
");
$stmtEnsureModulo->execute(['Solicitudes internas', 'Solicitudes internas']);

// 🔍 Obtener datos del usuario
$stmtUser = $conn->prepare("
    SELECT
        id,
        nombre,
        correo,
        es_superadmin,
        rol_id
    FROM usuarios
    WHERE id = ?
");

$stmtUser->execute([$id_usuario]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// ✅ Guardar en sesión
$_SESSION['es_superadmin'] = $user['es_superadmin'];
$_SESSION['rol_id'] = $user['rol_id'];

try {
    $stmtPermisos = $conn->prepare("
        SELECT p.nombre_permiso
        FROM rol_permiso rp
        INNER JOIN permisos p ON p.id = rp.permiso_id
        WHERE rp.rol_id = ?
    ");
    $stmtPermisos->execute([$user['rol_id']]);
    $_SESSION['user_permissions'] = $stmtPermisos->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $_SESSION['user_permissions'] = $_SESSION['user_permissions'] ?? [];
}

function menu_can($permission)
{
    if (isset($_SESSION['es_superadmin']) && (int) $_SESSION['es_superadmin'] === 1) {
        return true;
    }

    return in_array($permission, $_SESSION['user_permissions'] ?? [], true);
}

$mostrarAdministracion = isset($_SESSION['es_superadmin']) && (int) $_SESSION['es_superadmin'] === 1;

// 🔥 OBTENER MÓDULOS
if ($user['es_superadmin']) {

    $stmt = $conn->query("
        SELECT id, nombre
        FROM modulos
        ORDER BY nombre
    ");

} else {

    $stmt = $conn->prepare("
        SELECT m.id, m.nombre
        FROM usuario_modulo um
        JOIN modulos m ON m.id = um.modulo_id
        WHERE um.usuario_id = ?
        ORDER BY m.nombre
    ");

    $stmt->execute([$id_usuario]);
}

$modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🔥 DASHBOARD
if ($user['es_superadmin']) {

    $stmtDash = $conn->query("
        SELECT
            m.nombre AS modulo,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.nombre END) AS admin_nombre,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.correo END) AS admin_correo,
            COUNT(DISTINCT u.id) AS total_usuarios
        FROM modulos m
        LEFT JOIN usuario_modulo um ON m.id = um.modulo_id
        LEFT JOIN usuarios u ON u.id = um.usuario_id
        LEFT JOIN roles r ON u.rol_id = r.id
        GROUP BY m.id
        ORDER BY m.nombre
    ");

} else {

    $stmtDash = $conn->prepare("
        SELECT
            m.nombre AS modulo,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.nombre END) AS admin_nombre,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.correo END) AS admin_correo,
            COUNT(DISTINCT u.id) AS total_usuarios
        FROM usuario_modulo um
        JOIN modulos m ON m.id = um.modulo_id
        JOIN usuarios u ON u.id = um.usuario_id
        JOIN roles r ON u.rol_id = r.id
        WHERE um.modulo_id IN (
            SELECT modulo_id
            FROM usuario_modulo
            WHERE usuario_id = ?
        )
        GROUP BY m.id
        ORDER BY m.nombre
    ");

    $stmtDash->execute([$id_usuario]);
}

$dashboard = $stmtDash->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html class="light" lang="es">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php echo $titulo; ?></title>

<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>

<link rel="stylesheet" href="assets/menu.css">

<script>
tailwind.config = {

    darkMode: "class",

    theme: {

        extend: {

            colors: {

                primary: "#73BC25",
                secondary: "#5e9b1d",
                background: "#eef8df",
                surface: "#ffffff",
                surface2: "#dff2c2",
                borderc: "#c7dfaa",
                accent: "#ffcc00",
                textc: "#1f2937",
                muted: "#5f6b5f"

            }

        }

    }

}
</script>

</head>

<body class="bg-background text-textc min-h-screen overflow-x-hidden lg:flex">

<!-- MOBILE TOPBAR -->

<div class="lg:hidden fixed top-0 left-0 right-0 z-50 bg-[#73BC25] flex items-center justify-between px-4 py-3 shadow-lg">

    <h1 class="text-xl font-black text-black">
        Cengicaña
    </h1>

    <button id="menuBtn" class="text-black">

        <span class="material-symbols-outlined text-3xl">
            menu
        </span>

    </button>

</div>

<!-- OVERLAY -->

<div id="overlay"
class="fixed inset-0 bg-black/40 z-40 hidden lg:hidden"></div>

<!-- SIDEBAR -->

<aside id="sidebar"
class="fixed lg:sticky top-0 left-0 h-screen w-[280px] bg-[#73BC25] border-r border-[#73BC25]/30 shadow-sm p-6 z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 flex flex-col">

    <div class="mb-8 mt-10 lg:mt-0">

        <h1 class="text-3xl font-black text-black">
            Cengicaña
        </h1>

        <p class="text-sm text-black">
            Precision Agriculture
        </p>

    </div>

    <!-- USER -->

    <div class="flex items-center gap-4 mb-10 p-4 rounded-2xl bg-surface2">

        <div class="w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center font-bold text-lg">

            <?= strtoupper(substr($user['nombre'],0,1)) ?>

        </div>

        <div class="overflow-hidden">

            <p class="font-bold truncate">
                <?= htmlspecialchars($user['nombre']) ?>
            </p>

            <p class="text-sm text-muted truncate">
                <?= htmlspecialchars($user['correo']) ?>
            </p>

            <span class="text-[10px] uppercase tracking-wider text-primary font-bold">
                Online
            </span>

        </div>

    </div>

<nav class="flex flex-col gap-2 flex-1 overflow-y-auto scrollbar-hide">

<a href="Menu.php"
class="flex items-center gap-4 bg-[#ffcc00]/60 text-Black font-bold rounded-xl px-4 py-3">

    <span class="material-symbols-outlined">
        dashboard
    </span>

    <span>
        Dashboard
    </span>

</a>

<?php if($mostrarAdministracion): ?>

<p class="text-xs uppercase text-black font-bold mt-6 mb-2 px-2">
    Administración
</p>

<?php if(menu_can('gestionar_usuarios')): ?>
<a href="usuarios/usuarios.php"
class="flex items-center gap-4 text-black hover:text-primary hover:bg-surface2 rounded-xl px-4 py-3 transition-all">

    <span class="material-symbols-outlined">
        group
    </span>

    <span>
        Usuarios
    </span>

</a>
<?php endif; ?>

<?php if(menu_can('gestionar_roles')): ?>
<a href="roles/roles.php"
class="flex items-center gap-4 text-black hover:text-primary hover:bg-surface2 rounded-xl px-4 py-3 transition-all">

    <span class="material-symbols-outlined">
        admin_panel_settings
    </span>

    <span>
        Roles y Permisos
    </span>

</a>
<?php endif; ?>

<?php if(menu_can('gestionar_modulos')): ?>
<a href="modulos/modulos.php"
class="flex items-center gap-4 text-black hover:text-primary hover:bg-surface2 rounded-xl px-4 py-3 transition-all">

    <span class="material-symbols-outlined">
        apps
    </span>

    <span>
        Módulos
    </span>

</a>
<?php endif; ?>

<?php if(menu_can('gestionar_ingenios')): ?>
<a href="ingenios/ingenios.php"
class="flex items-center gap-4 text-black hover:text-primary hover:bg-surface2 rounded-xl px-4 py-3 transition-all">

    <span class="material-symbols-outlined">
        factory
    </span>

    <span>
        Ingenios
    </span>

</a>
<?php endif; ?>

<?php endif; ?>

<p class="text-xs uppercase text-black font-bold mt-6 mb-2 px-2">
    Sistemas
</p>

<?php foreach($modulos as $mod): ?>

<?php

$modulo = $mod['nombre'];

$ruta = "#";
$icono = "apps";

if (trim(strtolower($modulo)) == "cursos") {
    $ruta = "../cengicursos/index.php";
    $icono = "school";
 } elseif (in_array(trim(strtolower($modulo)), ["laboratorio", "laboratorios"], true)) {
    $ruta = "../Laboratorio/index.php";
    $icono = "science";
} elseif (trim(strtolower($modulo)) == "solicitudes internas") {
    $ruta = "../sistema_de_solicitudes/index.php";
    $icono = "assignment";
} elseif ($_SESSION['es_superadmin']) {

    if ($modulo == "Solicitud de visitas") {
        $ruta = "../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes";
        $icono = "calendar_today";
    } elseif ($modulo == "Servicio técnico") {
        $ruta = "../Pruebas/public/admin/dashboard_servicio.php";
        $icono = "build";
    } elseif ($modulo == "Laboratorio") {
    $ruta = "../Laboratorio/index.php";
        $icono = "science";
    } elseif ($modulo == "Ensayos") {
        $ruta = "../Pruebas/public/admin/dashboard_ensayos.php";
        $icono = "biotech";
    } elseif ($modulo == "Cursos") {
        $ruta = "../cengicursos/index.php";
        $icono = "school";
    } elseif (trim(strtolower($modulo)) == "pago") {
        $ruta = "../Pruebas/public/admin/dashboard_pago.php";
        $icono = "payments";
    }

} elseif ($_SESSION['rol_id'] == 9) {
    if (trim(strtolower($modulo)) == "pago") {
        $ruta = "../Pruebas/public/admin/dashboard_pago.php";
    }
} elseif ($_SESSION['rol_id'] == 2) {
    if (trim(strtolower($modulo)) == "solicitud de visitas") {
        $ruta = "../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes&estado=aprobado";
    } else {
        $ruta = "#";
    }
} else {
    if (trim(strtolower($modulo)) == "solicitud de visitas") {
        $ruta = "../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes";
    } else {
        $ruta = "#";
    }
}

?>

<a href="<?= $ruta ?>"
class="flex items-center gap-4 text-black hover:text-primary hover:bg-surface2 rounded-xl px-4 py-3 transition-all">

    <span class="material-symbols-outlined">
        <?= $icono ?>
    </span>

    <span>
        <?= htmlspecialchars($modulo) ?>
    </span>

</a>

<?php endforeach; ?>

</nav>

<div class="pt-6 border-t border-borderc">

    <a href="../Pruebas/public/admin/logout.php"
    class="flex items-center gap-4 text-red-600 hover:bg-red-50 rounded-xl px-4 py-3 transition-all">

        <span class="material-symbols-outlined">
            logout
        </span>

        <span>
            Cerrar sesión
        </span>

    </a>

</div>

</aside>

<!-- MAIN -->

<div class="flex-1 flex flex-col min-w-0 lg:ml-0 pt-[70px] lg:pt-0">

<header class="sticky top-0 z-30 bg-[#73BC25]/10 backdrop-blur-xl border-b border-borderc shadow-sm">

    <div class="flex justify-between items-center px-4 md:px-8 py-4">

        <div class="flex items-center gap-4">

            <h2 class="text-xl md:text-2xl font-black text-primary">
                Cengicaña Digital
            </h2>

        </div>

    </div>

</header>

<main class="flex-1 overflow-y-auto">

<section class="relative px-4 md:px-8 py-10 md:py-16 overflow-hidden">

    <div class="absolute inset-0 bg-gradient-to-br from-green-100/40 to-transparent"></div>

    <div class="relative z-10 max-w-6xl mx-auto">

        <div class="max-w-3xl">

            <span class="inline-block px-3 py-1 rounded-full bg-[#ffcc00] text-black text-sm font-bold mb-4">
                Cengicaña Digital
            </span>

            <h1 class="text-4xl md:text-5xl xl:text-6xl font-black mb-6 leading-tight">
                <?= $titulo ?>
            </h1>

            <p class="text-base md:text-xl text-muted leading-relaxed">

                Plataforma integral para la gestión y administración de procesos, solicitudes y aprobaciones desde un solo sistema de manera rápida, organizada y eficiente.

            </p>

        </div>

    </div>

</section>

<section class="px-4 md:px-8 py-8 max-w-7xl mx-auto w-full">

    <div class la="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-8">

        <div>

            <h2 class="text-2xl md:text- la font-bold mb-2">
                Panel general del sistema
            </h2>

            <p class="text-muted">
                Resumen ejecutivo y métricas por departamento
            </p>

        </div>

    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">

<?php foreach ($dashboard as $d): ?>

<div class="bg-[#f4faea] border border-borderc rounded-2xl p-6 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group">

    <div class="flex justify-between items-start mb-6">

        <div class="w-12 h-12 rounded-xl bg-[#73BC25]/10 flex items-center justify-center group-hover:bg-primary transition-all">

            <span class="material-symbols-outlined text-primary group-hover:text-white">
                dashboard
            </span>

        </div>

        <span class="text-xs bg-[#ffcc00] text-black px-3 py-1 rounded-full font-bold">
            Activo
        </span>

    </div>

    <h3 class="font-bold text-xl mb-4">
        <?= htmlspecialchars($d['modulo']) ?>
    </h3>

    <div class="flex items-center gap-3 mb-6">

        <div class="w-10 h-10 rounded-full bg-surface2 flex items-center justify-center font-bold">

            <?= strtoupper(substr($d['admin_nombre'] ?? 'NA',0,2)) ?>

        </div>

        <div class="min-w-0">

            <p class="font-semibold truncate">
                <?= $d['admin_nombre'] ?? 'No asignado' ?>
            </p>

            <p class="text-xs text-muted truncate">
                <?= $d['admin_correo'] ?? '' ?>
            </p>

        </div>

    </div>

    <div class="pt-4 border-t border-borderc">

        <p class="text-5xl font-black text-primary">
            <?= $d['total_usuarios'] ?>
        </p>

        <p class="text-sm text-muted">
            Usuarios activos
        </p>

    </div>

</div>

<?php endforeach; ?>

    </div>

</section>

<section class="px-4 md:px-8 py-12 bg-surface2/50 mt-10">

    <div class="max-w-7xl mx-auto">

        <div class="mb-10 text-center">

            <h2 class="text-3xl font-bold mb-2">
                Sistema de innovación y servicio
            </h2>

            <p class="text-muted">
                Acceso directo a herramientas operativas
            </p>

        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-6">

<?php foreach ($modulos as $mod): ?>

<?php

$modulo = $mod['nombre'];

$ruta = "#";

if (trim(strtolower($modulo)) == "cursos") {
    $ruta = "../cengicursos/index.php";
} elseif (in_array(trim(strtolower($modulo)), ["laboratorio", "laboratorios"], true)) {
    $ruta = "../Laboratorio/index.php";
} elseif (trim(strtolower($modulo)) == "solicitudes internas") {
    $ruta = "../sistema_de_solicitudes/index.php";
} elseif ($_SESSION['es_superadmin']) {

    if ($modulo == "Solicitud de visitas") {
        $ruta = "../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes";
    } elseif ($modulo == "Servicio técnico") {
        $ruta = "../Pruebas/public/admin/dashboard_servicio.php";
    } elseif ($modulo == "Laboratorio") {
    $ruta = "../Laboratorio/index.php";
    } elseif ($modulo == "Ensayos") {
        $ruta = "../Pruebas/public/admin/dashboard_ensayos.php";
    } elseif ($modulo == "Cursos") {
        $ruta = "../cengicursos/index.php";
    } elseif (trim(strtolower($modulo)) == "pago") {
        $ruta = "../Pruebas/public/admin/dashboard_pago.php";
    }

} elseif ($_SESSION['rol_id'] == 9) {
    if (trim(strtolower($modulo)) == "pago") {
        $ruta = "../Pruebas/public/admin/dashboard_pago.php";
    }
} elseif ($_SESSION['rol_id'] == 2) {
    if (trim(strtolower($modulo)) == "solicitud de visitas") {
        $ruta = "../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes&estado=aprobado";
    } else {
        $ruta = "#";
    }
} else {
    if (trim(strtolower($modulo)) == "solicitud de visitas") {
        $ruta = "../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes";
    } else {
        $ruta = "#";
    }
}

?>

<a href="<?= $ruta ?>">

    <div class="bg-[#f4faea] p-6 rounded-3xl border border-borderc shadow-sm hover:shadow-xl hover:scale-105 transition-all duration-300 group flex flex-col items-center text-center h-full">

        <div class="w-16 h-16 rounded-full bg-[#73BC25]/10 flex items-center justify-center mb-4 group-hover:bg-primary transition-all">

            <span class="material-symbols-outlined text-primary group-hover:text-white text-3xl">
                apps
            </span>

        </div>

        <span class="font-bold group-hover:text-primary transition-colors">

            <?= htmlspecialchars($modulo) ?>

        </span>

    </div>

</a>

<?php endforeach; ?>

        </div>

    </div>

</section>

</main>

<footer class="w-full py-6 bg-[#e8f5d6] border-t border-[#c7dfaa]">

    <div class="flex justify-between items-center px-4 md:px-8 max-w-7xl mx-auto">

        <div class="flex items-center gap-2">

            <span class="font-bold text-primary">
                CENGICAÑA
            </span>

            <span class="text-muted">
                © <?= date("Y") ?>
            </span>

        </div>

    </div>

</footer>

</div>

<script src="config/menu.js"></script>

</body>
</html>
