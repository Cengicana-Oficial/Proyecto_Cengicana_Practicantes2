<?php
require_once __DIR__ . '/../config/session.php';
cengi_session_start();
require_once __DIR__ . '/../config/conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login.php");
    exit;
}

function user_scope_config($scope)
{
    $scope = strtolower(trim((string) $scope));

    $configs = [
        'cursos' => [
            'module_names' => ['cursos', 'cengicursos'],
            'return_url' => '../../cengicursos/ver_usuarios.php',
            'label' => 'Cengicursos',
        ],
        'visitas' => [
            'module_names' => ['solicitud de visitas'],
            'return_url' => '../../Pruebas/public/admin/dashboard_unificado.php?modulo=usuarios',
            'label' => 'Solicitud de visitas',
        ],
        'laboratorio' => [
            'module_names' => ['laboratorio'],
            'return_url' => '../../laboratorio/view/usuarios_view.php',
            'label' => 'Laboratorio',
        ],
        'solicitudes' => [
            'module_names' => ['solicitudes internas'],
            'return_url' => '../../sistema_de_solicitudes/index.php?view=usuarios',
            'label' => 'Solicitudes Internas',
        ],
    ];

    return $configs[$scope] ?? null;
}

$conn = Conexion::conectar();
$idUsuario = (int) $_SESSION['id_usuario'];
$esSuperadmin = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$scope = $_GET['scope'] ?? '';
$scopeConfig = user_scope_config($scope);
$cengiAltaLimitada = false;
if (strtolower(trim((string) $scope)) === 'cursos') {
    require_once __DIR__ . '/../../cengicursos/revisar_permisos.php';
    require_once __DIR__ . '/../../cengicursos/accesos_helpers.php';
    if (!cengi_puede_gestionar_usuarios()) { http_response_code(403); exit('No tienes permiso para crear usuarios de Cursos.'); }
    $cengiAltaLimitada = !cengi_ve_todo_por_rol_o_ingenio();
    if ($cengiAltaLimitada && cengi_ingenio_id_actual() <= 0) { http_response_code(403); exit('Asigna un ingenio a esta cuenta antes de crear usuarios.'); }
}

require_once __DIR__ . '/_autorizacion.php';
$modulosGestionados = usr_exigir_acceso_scope($conn, $idUsuario, $esSuperadmin, $scope, $scopeConfig);

$modulosActualesUsuario = [];
if (!$esSuperadmin) {
    $stmtAdminMod = $conn->prepare("
        SELECT modulo_id
        FROM usuario_modulo
        WHERE usuario_id = ?
    ");
    $stmtAdminMod->execute([$idUsuario]);
    $modulosActualesUsuario = array_map('intval', $stmtAdminMod->fetchAll(PDO::FETCH_COLUMN));
}

$modulosDisponibles = [];
$moduloIdsForzados = [];

if ($scopeConfig) {
    // Solo los modulos del scope en los que quien crea puede gestionar usuarios.
    $modulosDisponibles = $modulosGestionados;
    $moduloIdsForzados = array_map(fn($mod) => (int) $mod['id'], $modulosDisponibles);
} elseif ($esSuperadmin) {
    $stmtMod = $conn->query("SELECT id, nombre FROM modulos ORDER BY nombre");
    $modulosDisponibles = $stmtMod->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtMod = $conn->prepare("
        SELECT id, nombre
        FROM modulos
        WHERE id IN (" . implode(',', array_fill(0, max(1, count($modulosActualesUsuario)), '?')) . ")
        ORDER BY nombre
    ");
    $stmtMod->execute($modulosActualesUsuario ?: [0]);
    $modulosDisponibles = $stmtMod->fetchAll(PDO::FETCH_ASSOC);
    $moduloIdsForzados = $modulosActualesUsuario;
}

$destinoRegreso = $scopeConfig['return_url'] ?? 'usuarios.php';

// Rol por modulo (login/config/roles_modulo.php): en cada modulo solo se ofrecen sus roles y
// los genericos. Desde la pantalla de un modulo (scope) el rol de plataforma no se pide: se usa
// el rol del modulo.
require_once __DIR__ . '/_modulos_roles.php';
$modulosPermitidos = array_map(static fn($m) => (int) $m['id'], $modulosDisponibles);
$nombresModulos = array_column($modulosDisponibles, 'nombre', 'id');
$rolesPorModulo = usr_filtrar_roles_asignables($conn, $idUsuario, $esSuperadmin, usr_roles_por_modulo($conn, $modulosDisponibles, $esSuperadmin), $modulosDisponibles);
if (strtolower(trim((string) $scope)) === 'cursos') {
    $permitidosCursos = array_column(array_filter(cengi_acc_roles_cursos($conn), 'cengi_acc_puede_asignar_rol'), 'id');
    foreach ($rolesPorModulo as &$opciones) $opciones = array_intersect_key($opciones, array_flip($permitidosCursos));
    unset($opciones);
}
$pidePlataforma = $esSuperadmin && !$scopeConfig;
$modulosElegidos = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usr_csrf_valido($_POST['csrf'] ?? null)) {
        usr_denegar("La sesión del formulario expiró. Vuelve a abrir el formulario e inténtalo de nuevo.", $destinoRegreso);
    }
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $contrasenaPlano = trim($_POST['contrasena'] ?? '');
    $rolId = $pidePlataforma ? (int) ($_POST['rol_id'] ?? 0) : 0;
    $ingenioId = $cengiAltaLimitada ? cengi_ingenio_id_actual() : (($_POST['ingenio_id'] ?? '') !== '' ? (int) $_POST['ingenio_id'] : null);
    $esSuperadminNuevo = $pidePlataforma && $rolId === 1 ? 1 : 0;

    try {
        $modulosElegidos = $esSuperadminNuevo ? [] : usr_leer_modulos_roles($_POST, $modulosPermitidos, $rolesPorModulo, $nombresModulos);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    // Sin rol de plataforma elegido: se usa el del primer modulo.
    if (!$rolId && $modulosElegidos) {
        $rolId = (int) reset($modulosElegidos);
    }
    $moduloIdsSeleccionados = array_keys($modulosElegidos);

    if (!empty($error)) {
        // mensaje del selector de modulos
    } elseif ($nombre === '' || $correo === '' || $contrasenaPlano === '' || $ingenioId === null) {
        $error = "Completa todos los campos obligatorios.";
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($contrasenaPlano) < 8 || !preg_match('/[A-Za-z]/', $contrasenaPlano) || !preg_match('/[0-9]/', $contrasenaPlano)) {
        $error = 'Indica un correo válido y una contraseña de al menos 8 caracteres con letras y números.';
    } elseif (!$esSuperadminNuevo && !$modulosElegidos) {
        $error = "Activa al menos un módulo y elige el rol del usuario en él.";
    } elseif (array_diff($moduloIdsForzados, $moduloIdsSeleccionados)) {
        $error = "Elige el rol del usuario en el módulo.";
    } elseif ($rolId <= 0) {
        $error = "Elige el rol del usuario en la plataforma.";
    } else {
        $check = $conn->prepare("SELECT id FROM usuarios WHERE correo = ?");
        $check->execute([$correo]);

        if ($check->fetchColumn()) {
            $error = "El correo ya esta registrado.";
        } else {
            $contrasenaHash = password_hash($contrasenaPlano, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO usuarios (nombre, correo, contrasena, rol_id, ingenio_id, es_superadmin)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $nombre,
                $correo,
                $contrasenaHash,
                $rolId,
                $ingenioId,
                $esSuperadminNuevo,
            ]);

            $usuarioId = (int) $conn->lastInsertId();

            if (!$esSuperadminNuevo) {
                usr_guardar_modulos_roles($conn, $usuarioId, $modulosElegidos, $modulosPermitidos);
            }

            // Laboratorio vuelve a su pantalla de Usuarios con el usuario nuevo resaltado.
            $esLab = $scopeConfig && strtolower(trim((string) $scope)) === 'laboratorio';
            header("Location: {$destinoRegreso}" . ($esLab ? (strpos($destinoRegreso, '?') === false ? '?' : '&') . 'creado=' . $usuarioId : ''));
            exit;
        }
    }
}

if ($esSuperadmin) {
    $stmtRoles = $conn->query("SELECT id, nombre_rol FROM roles ORDER BY nombre_rol");
} else {
    $stmtRoles = $conn->query("SELECT id, nombre_rol FROM roles WHERE id != 1 ORDER BY nombre_rol");
}
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);
// Al volver con error se conserva lo elegido; en un alta nueva desde un modulo, ese modulo va marcado.
$actualesModulos = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_map(static fn($d) => (int) ($d['rol_id'] ?? 0) ?: null, array_filter((array) ($_POST['modulos'] ?? []), static fn($d) => !empty($d['acceso'])))
    : [];

$stmtIngenios = $conn->query("SELECT id, nombre_ingenio FROM ingenios ORDER BY nombre_ingenio");
$ingenios = $stmtIngenios->fetchAll(PDO::FETCH_ASSOC);
if ($cengiAltaLimitada) $ingenios = array_values(array_filter($ingenios, static function ($i) { return (int) $i['id'] === cengi_ingenio_id_actual(); }));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear usuario | CENGICAÑA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
</head>
<body>
<div class="ingenios-shell">
    <a href="<?= htmlspecialchars($destinoRegreso) ?>" class="btn-back">
        <span class="material-symbols-outlined">arrow_back</span>
        Volver
    </a>

    <?php if (!empty($error)): ?>
        <div class="alert-banner">
            <span class="material-symbols-outlined">error</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-card form-card-wide">
        <h1>Crear usuario</h1>
        <p class="form-sub">Registra un nuevo usuario y asígnale un rol, ingenio y módulos.</p>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(usr_csrf_token()) ?>">

        <div class="form-field">
            <label for="nombre">Nombre</label>
            <input id="nombre" type="text" name="nombre" placeholder="Nombre completo"
                   value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required autofocus>
        </div>

        <div class="form-field">
            <label for="correo">Correo</label>
            <input id="correo" type="email" name="correo" placeholder="correo@cengicana.org"
                   value="<?= htmlspecialchars($_POST['correo'] ?? '') ?>" required>
        </div>

        <div class="form-field">
            <label for="contrasena">Contraseña</label>
            <input id="contrasena" type="password" name="contrasena" placeholder="Contraseña" required>
        </div>

        <?php if ($pidePlataforma): ?>
        <div class="form-field">
            <label for="rolSelect">Rol en la plataforma</label>
            <select id="rolSelect" name="rol_id" required>
                <option value="">Seleccione un rol</option>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?= (int) $rol['id'] ?>" <?= ((int) $rol['id'] === (int) ($_POST['rol_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rol['nombre_rol']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help">Solo para el menú central y la administración de usuarios, roles y módulos. Lo que puede hacer dentro de cada módulo se define abajo, con su rol en ese módulo. “Superadmin” tiene acceso total.</p>
        </div>
        <?php endif; ?>

        <div class="form-field">
            <label for="ingenio_id">Ingenio asignado</label>
            <select id="ingenio_id" name="ingenio_id" required>
                <option value="">Seleccione un ingenio</option>
                <?php foreach ($ingenios as $ingenio): ?>
                    <option value="<?= (int) $ingenio['id'] ?>" <?= ((int) $ingenio['id'] === (int) ($_POST['ingenio_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ingenio['nombre_ingenio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help">Este campo es obligatorio para que los filtros por ingenio funcionen correctamente.</p>
        </div>

        <?php if (!empty($modulosDisponibles)): ?>
            <?php usr_render_modulos_roles($modulosDisponibles, $rolesPorModulo, $actualesModulos, $moduloIdsForzados); ?>
            <?php if (!$esSuperadmin && !$scopeConfig): ?><p class="form-help">Como administrador de módulo, solo puedes crear usuarios para tus módulos.</p><?php endif; ?>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined">check</span>
                Crear usuario
            </button>
            <a href="<?= htmlspecialchars($destinoRegreso) ?>" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php if ($esSuperadmin && !$scopeConfig): ?>
<script>
document.getElementById("rolSelect").addEventListener("change", function () {
    const moduloDiv = document.getElementById("moduloContainer");
    if (!moduloDiv) return;
    moduloDiv.style.display = this.value === "1" ? "none" : "block";
});

document.querySelectorAll(".module-chip input[type=\"checkbox\"]").forEach(function (cb) {
    cb.addEventListener("change", function () {
        cb.closest(".module-chip").classList.toggle("is-checked", cb.checked);
    });
});
</script>
<?php endif; ?>
</body>
</html>
