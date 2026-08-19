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
            'return_url' => '../../laboratorio/index.php',
            'label' => 'Laboratorio',
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

if (!$esSuperadmin && !$scopeConfig) {
    header("Location: usuarios.php");
    exit;
}

$stmtUsuario = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmtUsuario->execute([$id]);
$usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    usuarios_mostrar_aviso("Usuario no encontrado", "El usuario que intentas editar ya no existe.", $scopeConfig['return_url'] ?? 'usuarios.php');
}

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
    $placeholders = implode(',', array_fill(0, count($scopeConfig['module_names']), '?'));
    $stmtScopeMods = $conn->prepare("
        SELECT id, nombre
        FROM modulos
        WHERE LOWER(nombre) IN ($placeholders)
        ORDER BY nombre
    ");
    $stmtScopeMods->execute($scopeConfig['module_names']);
    $modulosDisponibles = $stmtScopeMods->fetchAll(PDO::FETCH_ASSOC);
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

$stmtModUser = $conn->prepare("SELECT modulo_id FROM usuario_modulo WHERE usuario_id = ?");
$stmtModUser->execute([$id]);
$modulosUsuarioActuales = array_map('intval', $stmtModUser->fetchAll(PDO::FETCH_COLUMN));

$destinoRegreso = $scopeConfig['return_url'] ?? 'usuarios.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $rolId = (int) ($_POST['rol_id'] ?? 0);
    $contrasena = trim($_POST['contrasena'] ?? '');
    $ingenioId = $_POST['ingenio_id'] !== '' ? (int) $_POST['ingenio_id'] : null;
    $esSuperadminNuevo = $rolId === 1 ? 1 : 0;

    if ($scopeConfig || !$esSuperadmin) {
        $moduloIdsSeleccionados = $moduloIdsForzados;
    } else {
        $moduloIdsSeleccionados = array_map('intval', $_POST['modulo_ids'] ?? []);
    }

    if ($nombre === '' || $correo === '' || $rolId <= 0 || $ingenioId === null) {
        $error = "Completa los campos obligatorios.";
    } else {
        $check = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? AND id <> ?");
        $check->execute([$correo, $id]);

        if ($check->fetchColumn()) {
            $error = "El correo ya esta registrado por otro usuario.";
        } else {
            if ($contrasena !== '') {
                $contrasenaHash = password_hash($contrasena, PASSWORD_DEFAULT);
                $stmtUpdate = $conn->prepare("
                    UPDATE usuarios
                    SET nombre = ?, correo = ?, contrasena = ?, rol_id = ?, ingenio_id = ?, es_superadmin = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$nombre, $correo, $contrasenaHash, $rolId, $ingenioId, $esSuperadminNuevo, $id]);
            } else {
                $stmtUpdate = $conn->prepare("
                    UPDATE usuarios
                    SET nombre = ?, correo = ?, rol_id = ?, ingenio_id = ?, es_superadmin = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$nombre, $correo, $rolId, $ingenioId, $esSuperadminNuevo, $id]);
            }

            $conn->prepare("DELETE FROM usuario_modulo WHERE usuario_id = ?")->execute([$id]);

            if (!$esSuperadminNuevo && !empty($moduloIdsSeleccionados)) {
                $stmtModulo = $conn->prepare("
                    INSERT INTO usuario_modulo (usuario_id, modulo_id)
                    VALUES (?, ?)
                ");

                foreach (array_unique($moduloIdsSeleccionados) as $moduloId) {
                    $stmtModulo->execute([$id, (int) $moduloId]);
                }
            }

            header("Location: {$destinoRegreso}");
            exit;
        }
    }

    $usuario['nombre'] = $nombre;
    $usuario['correo'] = $correo;
    $usuario['rol_id'] = $rolId;
    $usuario['ingenio_id'] = $ingenioId;
    $modulosUsuarioActuales = $moduloIdsSeleccionados;
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

        <div class="form-field">
            <label for="nombre">Nombre</label>
            <input id="nombre" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>" required autofocus>
        </div>

        <div class="form-field">
            <label for="correo">Correo</label>
            <input id="correo" name="correo" value="<?= htmlspecialchars($usuario['correo']) ?>" required>
        </div>

        <div class="form-field">
            <label for="contrasena">Nueva contraseña</label>
            <input id="contrasena" type="password" name="contrasena" placeholder="Nueva contraseña (opcional)">
        </div>

        <div class="form-field">
            <label for="rolSelect">Rol</label>
            <select id="rolSelect" name="rol_id" required>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?= (int) $rol['id'] ?>" <?= ((int) $rol['id'] === (int) $usuario['rol_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rol['nombre_rol']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="ingenio_id">Ingenio asignado</label>
            <select id="ingenio_id" name="ingenio_id" required>
                <option value="">Seleccione un ingenio</option>
                <?php foreach ($ingenios as $ingenio): ?>
                    <option value="<?= (int) $ingenio['id'] ?>" <?= ((int) $ingenio['id'] === (int) ($usuario['ingenio_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ingenio['nombre_ingenio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help">Mantener el ingenio correcto es necesario para que el usuario solo vea su información.</p>
        </div>

        <?php if ($scopeConfig): ?>
            <div class="form-field">
                <label>Módulo asignado</label>
                <div class="module-badges">
                    <?php foreach ($modulosDisponibles as $modulo): ?>
                        <span class="module-badge"><?= htmlspecialchars($modulo['nombre']) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="form-help">Este usuario seguirá vinculado al módulo <?= htmlspecialchars($scopeConfig['label']) ?>.</p>
            </div>
        <?php elseif ($esSuperadmin): ?>
            <div class="form-field" id="moduloContainer">
                <label>Módulos asignados</label>
                <div class="module-picker">
                    <?php foreach ($modulosDisponibles as $modulo): ?>
                        <?php $moduloMarcado = in_array((int) $modulo['id'], $modulosUsuarioActuales, true); ?>
                        <label class="module-chip<?= $moduloMarcado ? ' is-checked' : '' ?>">
                            <input type="checkbox" name="modulo_ids[]" value="<?= (int) $modulo['id'] ?>" <?= $moduloMarcado ? 'checked' : '' ?>>
                            <span class="material-symbols-outlined">widgets</span>
                            <?= htmlspecialchars($modulo['nombre']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="form-help">Selecciona uno o más módulos para este usuario.</p>
            </div>
        <?php elseif (!empty($modulosDisponibles)): ?>
            <div class="form-field">
                <label>Módulo asignado</label>
                <div class="module-badges">
                    <?php foreach ($modulosDisponibles as $modulo): ?>
                        <span class="module-badge"><?= htmlspecialchars($modulo['nombre']) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="form-help">Como administrador de módulo, solo puedes administrar usuarios del mismo módulo.</p>
            </div>
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
