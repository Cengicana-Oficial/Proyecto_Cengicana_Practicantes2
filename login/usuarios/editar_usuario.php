<?php
session_start();
require_once "../config/conexion.php";

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login.php");
    exit;
}

/**
 * Muestra una página de aviso con el mismo lenguaje visual del módulo
 * (usada cuando no se puede continuar con la edición) y termina la
 * ejecución.
 */
function usuarios_mostrar_aviso($titulo, $mensaje, $volverA = 'usuarios.php')
{
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($titulo) ?> | CENGICAÑA</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
    </head>
    <body>
    <div class="ingenios-shell">
        <a href="<?= htmlspecialchars($volverA) ?>" class="btn-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Volver
        </a>

        <div class="confirm-card">
            <div class="confirm-icon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p><?= htmlspecialchars($mensaje) ?></p>
            <a href="<?= htmlspecialchars($volverA) ?>" class="btn-primary">Volver al listado</a>
        </div>
    </div>
    </body>
    </html>
    <?php
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
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    usuarios_mostrar_aviso("ID inválido", "No se recibió un identificador de usuario válido.", $scopeConfig['return_url'] ?? 'usuarios.php');
}

require_once __DIR__ . '/_autorizacion.php';
$modulosGestionados = usr_exigir_acceso_scope($conn, $idUsuario, $esSuperadmin, $scope, $scopeConfig);

$stmtUsuario = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmtUsuario->execute([$id]);
$usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    usuarios_mostrar_aviso("Usuario no encontrado", "El usuario que intentas editar ya no existe.", $scopeConfig['return_url'] ?? 'usuarios.php');
}

$alcance = usr_alcance_sobre($conn, $idUsuario, $esSuperadmin, $usuario, $modulosGestionados);
if ($alcance === 'ninguno') {
    usr_denegar("No puedes editar este usuario desde este módulo.", $scopeConfig['return_url'] ?? 'usuarios.php');
}
// Sin alcance total solo se cambia el acceso/rol en los modulos que gestiona quien edita.
$editaCuenta = $alcance === 'total';

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
    // Solo los modulos del scope en los que quien edita puede gestionar usuarios.
    $modulosDisponibles = $modulosGestionados;
    $moduloIdsForzados = array_map(fn($mod) => (int) $mod['id'], $modulosDisponibles);
} elseif ($esSuperadmin) {
    $stmtMods = $conn->query("SELECT id, nombre FROM modulos ORDER BY nombre");
    $modulosDisponibles = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtMods = $conn->prepare("
        SELECT id, nombre
        FROM modulos
        WHERE id IN (" . implode(',', array_fill(0, max(1, count($modulosActualesUsuario)), '?')) . ")
        ORDER BY nombre
    ");
    $stmtMods->execute($modulosActualesUsuario ?: [0]);
    $modulosDisponibles = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
    $moduloIdsForzados = $modulosActualesUsuario;
}

// Rol por modulo (login/config/roles_modulo.php): cada modulo guarda su propio rol en
// usuario_modulo.rol_id; usuarios.rol_id queda solo como rol de la plataforma (Menu y admin).
require_once __DIR__ . '/_modulos_roles.php';
$modulosPermitidos = array_map(static fn($m) => (int) $m['id'], $modulosDisponibles);
$nombresModulos = array_column($modulosDisponibles, 'nombre', 'id');
$rolesPorModulo = usr_filtrar_roles_asignables($conn, $idUsuario, $esSuperadmin, usr_roles_por_modulo($conn, $modulosDisponibles, $esSuperadmin), $modulosDisponibles);
$pidePlataforma = $esSuperadmin && !$scopeConfig;

$stmtModUser = $conn->prepare("SELECT modulo_id, rol_id FROM usuario_modulo WHERE usuario_id = ?");
$stmtModUser->execute([$id]);
$actualesModulos = [];
foreach ($stmtModUser->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $actualesModulos[(int) $fila['modulo_id']] = $fila['rol_id'] !== null ? (int) $fila['rol_id'] : null;
}

$destinoRegreso = $scopeConfig['return_url'] ?? 'usuarios.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usr_csrf_valido($_POST['csrf'] ?? null)) {
        usr_denegar("La sesión del formulario expiró. Vuelve a abrir el formulario e inténtalo de nuevo.", $destinoRegreso);
    }
    if ($editaCuenta) {
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $contrasena = trim($_POST['contrasena'] ?? '');
        $ingenioId = ($_POST['ingenio_id'] ?? '') !== '' ? (int) $_POST['ingenio_id'] : null;
    } else {
        $nombre = (string) $usuario['nombre'];
        $correo = (string) $usuario['correo'];
        $contrasena = '';
        $ingenioId = $usuario['ingenio_id'] !== null ? (int) $usuario['ingenio_id'] : null;
    }
    // El rol de la plataforma solo lo cambia el superadmin desde la administracion general;
    // desde un modulo se conserva el que ya tenia.
    $rolId = $pidePlataforma ? (int) ($_POST['rol_id'] ?? 0) : (int) $usuario['rol_id'];
    $esSuperadminNuevo = $pidePlataforma ? ($rolId === 1 ? 1 : 0) : (int) $usuario['es_superadmin'];

    $modulosElegidos = [];
    try {
        $modulosElegidos = $esSuperadminNuevo ? [] : usr_leer_modulos_roles($_POST, $modulosPermitidos, $rolesPorModulo, $nombresModulos);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    if ($rolId <= 0 && $modulosElegidos) {
        $rolId = (int) reset($modulosElegidos);
    }

    if (!empty($error)) {
        // mensaje del selector de modulos
    } elseif ($editaCuenta && ($nombre === '' || $correo === '' || $ingenioId === null)) {
        $error = "Completa los campos obligatorios.";
    } elseif ($rolId <= 0) {
        $error = "Elige el rol del usuario en la plataforma.";
    } elseif (!$esSuperadminNuevo && array_diff($moduloIdsForzados, array_keys($modulosElegidos))) {
        $error = "Elige el rol del usuario en el módulo.";
    } else {
        $check = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? AND id <> ?");
        $check->execute([$correo, $id]);

        if ($check->fetchColumn()) {
            $error = "El correo ya esta registrado por otro usuario.";
        } else {
            $conn->beginTransaction();
            if ($contrasena !== '') {
                $conn->prepare("
                    UPDATE usuarios
                    SET nombre = ?, correo = ?, contrasena = ?, rol_id = ?, ingenio_id = ?, es_superadmin = ?
                    WHERE id = ?
                ")->execute([$nombre, $correo, password_hash($contrasena, PASSWORD_DEFAULT), $rolId, $ingenioId, $esSuperadminNuevo, $id]);
            } else {
                $conn->prepare("
                    UPDATE usuarios
                    SET nombre = ?, correo = ?, rol_id = ?, ingenio_id = ?, es_superadmin = ?
                    WHERE id = ?
                ")->execute([$nombre, $correo, $rolId, $ingenioId, $esSuperadminNuevo, $id]);
            }

            // Solo se tocan los modulos que esta pantalla administra: los demas accesos
            // del usuario (y su rol en ellos) se conservan.
            usr_guardar_modulos_roles($conn, $id, $modulosElegidos, $modulosPermitidos);
            $conn->commit();

            header("Location: {$destinoRegreso}");
            exit;
        }
    }

    $usuario['nombre'] = $nombre;
    $usuario['correo'] = $correo;
    $usuario['rol_id'] = $rolId;
    $usuario['ingenio_id'] = $ingenioId;
    $actualesModulos = array_map(static fn($d) => (int) ($d['rol_id'] ?? 0) ?: null, array_filter((array) ($_POST['modulos'] ?? []), static fn($d) => !empty($d['acceso'])));
}

if ($esSuperadmin) {
    $stmtRoles = $conn->query("SELECT id, nombre_rol FROM roles ORDER BY nombre_rol");
} else {
    $stmtRoles = $conn->query("SELECT id, nombre_rol FROM roles WHERE id != 1 ORDER BY nombre_rol");
}
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

$stmtIngenios = $conn->query("SELECT id, nombre_ingenio FROM ingenios ORDER BY nombre_ingenio");
$ingenios = $stmtIngenios->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar usuario | CENGICAÑA</title>
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
        <h1>Editar usuario</h1>
        <p class="form-sub">Actualiza los datos del usuario #<?= (int) $usuario['id'] ?>.</p>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(usr_csrf_token()) ?>">

        <?php if (!$editaCuenta): ?>
            <p class="form-help">Este usuario también tiene acceso a otros módulos o permisos que no administras: desde aquí solo puedes cambiar su acceso y rol en este módulo.</p>
        <?php endif; ?>

        <div class="form-field">
            <label for="nombre">Nombre</label>
            <input id="nombre" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>" required autofocus <?= $editaCuenta ? '' : 'disabled' ?>>
        </div>

        <div class="form-field">
            <label for="correo">Correo</label>
            <input id="correo" name="correo" value="<?= htmlspecialchars($usuario['correo']) ?>" required <?= $editaCuenta ? '' : 'disabled' ?>>
        </div>

        <?php if ($editaCuenta): ?>
        <div class="form-field">
            <label for="contrasena">Nueva contraseña</label>
            <input id="contrasena" type="password" name="contrasena" placeholder="Nueva contraseña (opcional)">
        </div>
        <?php endif; ?>

        <?php if ($pidePlataforma): ?>
        <div class="form-field">
            <label for="rolSelect">Rol en la plataforma</label>
            <select id="rolSelect" name="rol_id" required>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?= (int) $rol['id'] ?>" <?= ((int) $rol['id'] === (int) $usuario['rol_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rol['nombre_rol']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help">Rol para el panel principal y la administración general. Los permisos dentro de cada módulo los da el rol de ese módulo (abajo).</p>
        </div>
        <?php endif; ?>

        <div class="form-field">
            <label for="ingenio_id">Ingenio asignado</label>
            <select id="ingenio_id" name="ingenio_id" required <?= $editaCuenta ? '' : 'disabled' ?>>
                <option value="">Seleccione un ingenio</option>
                <?php foreach ($ingenios as $ingenio): ?>
                    <option value="<?= (int) $ingenio['id'] ?>" <?= ((int) $ingenio['id'] === (int) ($usuario['ingenio_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ingenio['nombre_ingenio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help">Mantener el ingenio correcto es necesario para que el usuario solo vea su información.</p>
        </div>

        <?php if (!empty($modulosDisponibles)): ?>
            <?php usr_render_modulos_roles($modulosDisponibles, $rolesPorModulo, $actualesModulos, $moduloIdsForzados); ?>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined">check</span>
                Guardar cambios
            </button>
            <a href="<?= htmlspecialchars($destinoRegreso) ?>" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php if ($pidePlataforma): ?>
<script>
document.getElementById("rolSelect").addEventListener("change", function () {
    const moduloDiv = document.getElementById("moduloContainer");
    if (moduloDiv) moduloDiv.style.display = this.value === "1" ? "none" : "block";
});
</script>
<?php endif; ?>
</body>
</html>
