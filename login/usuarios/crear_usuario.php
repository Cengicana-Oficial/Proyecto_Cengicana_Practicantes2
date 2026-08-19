<?php
session_start();
require_once "../config/conexion.php";

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

if (!$esSuperadmin && !$scopeConfig) {
    header("Location: usuarios.php");
    exit;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $contrasenaPlano = trim($_POST['contrasena'] ?? '');
    $rolId = (int) ($_POST['rol_id'] ?? 0);
    $ingenioId = $_POST['ingenio_id'] !== '' ? (int) $_POST['ingenio_id'] : null;

    if ($scopeConfig || !$esSuperadmin) {
        $moduloIdsSeleccionados = $moduloIdsForzados;
    } else {
        $moduloIdsSeleccionados = array_map('intval', $_POST['modulo_ids'] ?? []);
    }

    if ($nombre === '' || $correo === '' || $contrasenaPlano === '' || $rolId <= 0 || $ingenioId === null) {
        $error = "Completa todos los campos obligatorios.";
    } else {
        $check = $conn->prepare("SELECT id FROM usuarios WHERE correo = ?");
        $check->execute([$correo]);

        if ($check->fetchColumn()) {
            $error = "El correo ya esta registrado.";
        } else {
            $esSuperadminNuevo = $rolId === 1 ? 1 : 0;
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

            if (!$esSuperadminNuevo && !empty($moduloIdsSeleccionados)) {
                $stmtModulo = $conn->prepare("
                    INSERT INTO usuario_modulo (usuario_id, modulo_id)
                    VALUES (?, ?)
                ");

                foreach (array_unique($moduloIdsSeleccionados) as $moduloId) {
                    $stmtModulo->execute([$usuarioId, (int) $moduloId]);
                }
            }

            header("Location: {$destinoRegreso}");
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

$stmtIngenios = $conn->query("SELECT id, nombre_ingenio FROM ingenios ORDER BY nombre_ingenio");
$ingenios = $stmtIngenios->fetchAll(PDO::FETCH_ASSOC);
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

        <div class="form-field">
            <label for="rolSelect">Rol</label>
            <select id="rolSelect" name="rol_id" required>
                <option value="">Seleccione un rol</option>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?= (int) $rol['id'] ?>" <?= ((int) $rol['id'] === (int) ($_POST['rol_id'] ?? 0)) ? 'selected' : '' ?>>
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
                    <option value="<?= (int) $ingenio['id'] ?>" <?= ((int) $ingenio['id'] === (int) ($_POST['ingenio_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ingenio['nombre_ingenio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-help">Este campo es obligatorio para que los filtros por ingenio funcionen correctamente.</p>
        </div>

        <?php if ($scopeConfig): ?>
            <div class="form-field">
                <label>Módulo asignado</label>
                <div class="module-badges">
                    <?php foreach ($modulosDisponibles as $modulo): ?>
                        <span class="module-badge"><?= htmlspecialchars($modulo['nombre']) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="form-help">Este usuario se guardará automáticamente en el módulo <?= htmlspecialchars($scopeConfig['label']) ?>.</p>
            </div>
        <?php elseif ($esSuperadmin): ?>
            <div class="form-field" id="moduloContainer">
                <label>Módulos asignados</label>
                <div class="module-picker">
                    <?php foreach ($modulosDisponibles as $modulo): ?>
                        <label class="module-chip">
                            <input type="checkbox" name="modulo_ids[]" value="<?= (int) $modulo['id'] ?>">
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
                <p class="form-help">Como administrador de módulo, solo puedes crear usuarios para tu propio módulo.</p>
            </div>
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
