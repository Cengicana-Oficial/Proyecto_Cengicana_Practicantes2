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
            'title' => 'Usuarios del modulo Cursos',
        ],
        'visitas' => [
            'module_names' => ['solicitud de visitas'],
            'return_url' => '../../Pruebas/public/admin/dashboard_unificado.php?modulo=usuarios',
            'title' => 'Usuarios del modulo Solicitud de visitas',
        ],
        'laboratorio' => [
            'module_names' => ['laboratorio'],
            'return_url' => '../../laboratorio/view/usuarios_view.php',
            'title' => 'Usuarios del modulo Laboratorio',
        ],
    ];

    return $configs[$scope] ?? null;
}

$conn = Conexion::conectar();
$idUsuario = (int) $_SESSION['id_usuario'];
$esSuperadmin = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$scope = $_GET['scope'] ?? '';
$scopeConfig = user_scope_config($scope);

require_once __DIR__ . '/_autorizacion.php';
usr_exigir_acceso_scope($conn, $idUsuario, $esSuperadmin, $scope, $scopeConfig);

$params = [];
$where = [];

if ($scopeConfig) {
    $placeholders = implode(',', array_fill(0, count($scopeConfig['module_names']), '?'));
    $where[] = "EXISTS (
        SELECT 1
        FROM usuario_modulo umf
        INNER JOIN modulos mf ON mf.id = umf.modulo_id
        WHERE umf.usuario_id = u.id
          AND LOWER(mf.nombre) IN ($placeholders)
    )";
    $params = array_merge($params, $scopeConfig['module_names']);
}

if (!$esSuperadmin && !$scopeConfig) {
    $where[] = "EXISTS (
        SELECT 1
        FROM usuario_modulo umscope
        WHERE umscope.usuario_id = u.id
          AND umscope.modulo_id IN (
              SELECT modulo_id FROM usuario_modulo WHERE usuario_id = ?
          )
    )";
    $params[] = $idUsuario;
}

$sql = "
    SELECT
        u.id,
        u.nombre,
        u.correo,
        u.es_superadmin,
        r.nombre_rol,
        COALESCE(i.nombre_ingenio, 'Sin ingenio') AS ingenio,
        GROUP_CONCAT(DISTINCT CONCAT(m.nombre, '||', COALESCE(rm.nombre_rol, '')) ORDER BY m.nombre SEPARATOR '##') AS modulos
    FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id
    LEFT JOIN ingenios i ON i.id = u.ingenio_id
    LEFT JOIN usuario_modulo um ON um.usuario_id = u.id
    LEFT JOIN modulos m ON m.id = um.modulo_id
    LEFT JOIN roles rm ON rm.id = um.rol_id -- rol del usuario en cada modulo
";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= "
    GROUP BY u.id, u.nombre, u.correo, u.es_superadmin, r.nombre_rol, i.nombre_ingenio
    ORDER BY u.nombre
";

$conn->exec("SET SESSION group_concat_max_len = 100000");
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Se arma la vista: modulos con su rol, indicadores y opciones de los filtros.
$opcModulos = [];
$opcRoles = [];
$opcIngenios = [];
$totalSuperadmin = 0;
$totalSinRol = 0;
$totalSinModulo = 0;

foreach ($usuarios as &$usuario) {
    $usuario['es_super'] = (int) $usuario['es_superadmin'] === 1 || strtolower((string) $usuario['nombre_rol']) === 'superadmin';
    $usuario['lista_modulos'] = [];
    $usuario['sin_rol'] = false;

    // "Modulo||Rol##Modulo||Rol": cada modulo con el rol que tiene ahi.
    foreach (array_filter(explode('##', (string) ($usuario['modulos'] ?? ''))) as $par) {
        [$moduloNombre, $rolModulo] = array_pad(explode('||', $par, 2), 2, '');
        $usuario['lista_modulos'][] = ['modulo' => $moduloNombre, 'rol' => $rolModulo];
        $opcModulos[$moduloNombre] = true;
        if ($rolModulo !== '') {
            $opcRoles[$rolModulo] = true;
        } elseif (!$usuario['es_super']) {
            $usuario['sin_rol'] = true;
        }
    }

    $opcRoles[$usuario['nombre_rol']] = true;
    $opcIngenios[$usuario['ingenio']] = true;
    $totalSuperadmin += $usuario['es_super'] ? 1 : 0;
    $totalSinRol += $usuario['sin_rol'] ? 1 : 0;
    $totalSinModulo += (!$usuario['lista_modulos'] && !$usuario['es_super']) ? 1 : 0;
}
unset($usuario);

ksort($opcModulos);
ksort($opcRoles);
ksort($opcIngenios);

/** Iniciales para el avatar (dos primeras palabras). */
function usr_iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim((string) $nombre)) ?: [];
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : '?';
}

/** Color estable por modulo (mismo modulo, mismo color en toda la tabla). */
function usr_tono_modulo($nombre)
{
    static $tonos = [];
    $clave = mb_strtolower((string) $nombre);
    $fijos = ['cursos' => 0, 'laboratorio' => 1, 'solicitudes internas' => 2, 'sigec' => 3, 'servicio tecnico' => 4, 'solicitud de visitas' => 5];
    foreach ($fijos as $patron => $tono) {
        if (strpos($clave, $patron) !== false) {
            return $tono;
        }
    }
    if (strpos($clave, 'comit') !== false) {
        return 6;
    }
    return $tonos[$clave] ??= abs(crc32($clave)) % 7;
}

$titulo = $scopeConfig['title'] ?? 'Usuarios del sistema';
$volverA = $scopeConfig['return_url'] ?? '../Menu.php';
$qsScope = $scope ? '&scope=' . urlencode($scope) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios | CENGICAÑA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
    <style>
        .ingenios-shell { width: min(1280px, calc(100% - 48px)); }

        /* Indicadores */
        .usr-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .usr-kpi { display: flex; align-items: center; gap: 14px; padding: 16px 18px; border: 1px solid var(--border); border-radius: 16px; background: #fff; box-shadow: 0 6px 18px rgba(31, 86, 50, .05); text-align: left; font-family: inherit; color: inherit; }
        button.usr-kpi { cursor: pointer; transition: border-color .16s, transform .16s; }
        button.usr-kpi:hover { border-color: #aed58d; transform: translateY(-1px); }
        button.usr-kpi.is-on { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(115, 188, 37, .18); }
        .usr-kpi-ico { width: 42px; height: 42px; flex: none; display: grid; place-items: center; border-radius: 12px; background: var(--surface-soft); color: var(--primary-deep); }
        .usr-kpi-ico.is-gold { background: #fff6d6; color: #8a6a00; }
        .usr-kpi-ico.is-warn { background: #fff1e6; color: #b45309; }
        .usr-kpi-ico.is-gray { background: #f0f2ef; color: #5f6b62; }
        .usr-kpi strong { display: block; font-size: 22px; line-height: 1.1; color: var(--text); }
        .usr-kpi span.lbl { font-size: 12px; color: var(--muted); font-weight: 600; }

        /* Barra de filtros */
        .usr-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--border); background: #fbfdf9; }
        .usr-search { position: relative; flex: 1 1 260px; }
        .usr-search .material-symbols-outlined { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 20px; }
        .usr-search input { width: 100%; padding: 10px 12px 10px 40px; border: 1px solid var(--border); border-radius: 12px; font: inherit; font-size: 13.5px; background: #fff; }
        .usr-toolbar select { padding: 10px 12px; border: 1px solid var(--border); border-radius: 12px; font: inherit; font-size: 13px; background: #fff; color: var(--text); max-width: 220px; }
        .usr-search input:focus, .usr-toolbar select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(115, 188, 37, .18); }
        .usr-clear { display: none; align-items: center; gap: 4px; padding: 9px 12px; border: 0; border-radius: 12px; background: transparent; color: var(--primary-deep); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
        .usr-clear.is-visible { display: inline-flex; }
        .usr-clear:hover { background: var(--surface-soft); }

        /* Tabla */
        .usr-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .usr-table th { padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); background: #f7faf4; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .usr-table th[data-sort] { cursor: pointer; user-select: none; }
        .usr-table th[data-sort]:hover { color: var(--primary-deep); }
        .usr-table th .material-symbols-outlined { font-size: 15px; opacity: .35; }
        .usr-table th.is-sorted .material-symbols-outlined { opacity: 1; color: var(--primary-deep); }
        .usr-table td { padding: 13px 16px; border-bottom: 1px solid #edf3e8; vertical-align: middle; }
        .usr-table tbody tr { transition: background-color .12s; }
        .usr-table tbody tr:hover { background: #f8fcf3; }
        .usr-table tbody tr.is-flagged td:first-child { box-shadow: inset 3px 0 0 #f59e0b; }

        .usr-person { display: flex; align-items: center; gap: 12px; min-width: 220px; }
        .usr-avatar { width: 38px; height: 38px; flex: none; display: grid; place-items: center; border-radius: 50%; background: linear-gradient(135deg, #8fd14f, #1f5632); color: #fff; font-size: 13px; font-weight: 800; letter-spacing: .02em; }
        .usr-avatar.is-super { background: linear-gradient(135deg, #ffd84d, #b98900); }
        .usr-person b { display: block; color: var(--text); font-weight: 700; }
        .usr-person small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; word-break: break-all; }
        .usr-id { color: #9aa79e; font-size: 11px; font-weight: 700; margin-left: 6px; }

        .usr-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; background: #eef3ea; color: #3f5245; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .usr-pill.is-super { background: #fff6d6; color: #7a5d00; }
        .usr-pill .material-symbols-outlined { font-size: 15px; }
        .usr-ingenio { display: inline-flex; align-items: center; gap: 6px; color: var(--text); white-space: nowrap; }
        .usr-ingenio .material-symbols-outlined { font-size: 17px; color: var(--muted); }
        .usr-ingenio.is-none { color: #9aa79e; font-style: italic; }

        .usr-mods { display: flex; flex-wrap: wrap; gap: 6px; max-width: 460px; }
        .usr-mod { display: inline-flex; align-items: stretch; border-radius: 9px; overflow: hidden; font-size: 12px; border: 1px solid var(--t-b); }
        .usr-mod span { padding: 4px 8px; background: var(--t-bg); color: var(--t-fg); font-weight: 700; white-space: nowrap; }
        .usr-mod b { padding: 4px 8px; background: #fff; color: var(--text); font-weight: 600; white-space: nowrap; }
        .usr-mod.is-missing { border-color: #f5c48b; }
        .usr-mod.is-missing b { background: #fff7ed; color: #b45309; display: inline-flex; align-items: center; gap: 3px; }
        .usr-mod.is-missing b .material-symbols-outlined { font-size: 14px; }
        .t0 { --t-bg: #eef8df; --t-fg: #2f6b12; --t-b: #d3eab8; }
        .t1 { --t-bg: #e6f2fb; --t-fg: #1d5f8f; --t-b: #c6dff2; }
        .t2 { --t-bg: #f3ecfb; --t-fg: #6a3fa0; --t-b: #e0d1f3; }
        .t3 { --t-bg: #fdf0e4; --t-fg: #9a4f12; --t-b: #f4d8bd; }
        .t4 { --t-bg: #e5f6f3; --t-fg: #17695c; --t-b: #c1e8e0; }
        .t5 { --t-bg: #fbe9ee; --t-fg: #9b2c4c; --t-b: #f2cbd6; }
        .t6 { --t-bg: #fdf7d8; --t-fg: #7a5d00; --t-b: #efe2a4; }
        .usr-nomod { color: #9aa79e; font-size: 12.5px; font-style: italic; }
        .usr-all { display: inline-flex; align-items: center; gap: 5px; color: #7a5d00; font-size: 12.5px; font-weight: 600; }
        .usr-all .material-symbols-outlined { font-size: 16px; }

        /* Pie: conteo y paginacion */
        .usr-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 18px; font-size: 12.5px; color: var(--muted); }
        .usr-foot select { padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: #fff; }
        .usr-pager { display: flex; gap: 6px; align-items: center; }
        .usr-pager button { min-width: 32px; height: 32px; padding: 0 8px; border: 1px solid var(--border); border-radius: 9px; background: #fff; color: var(--primary-deep); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
        .usr-pager button:hover:not(:disabled) { background: var(--surface-soft); }
        .usr-pager button.is-on { background: var(--primary-deep); border-color: var(--primary-deep); color: #fff; }
        .usr-pager button:disabled { opacity: .4; cursor: default; }
        .usr-empty-filter { display: none; padding: 40px 20px; text-align: center; color: var(--muted); }
        .usr-empty-filter .material-symbols-outlined { font-size: 36px; display: block; margin-bottom: 6px; }

        .usr-legend { display: flex; flex-wrap: wrap; gap: 14px; margin: 12px 2px 0; font-size: 12px; color: var(--muted); }
        .usr-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .usr-legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }

        @media (max-width: 980px) {
            .usr-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* En pantallas angostas cada usuario se ve como tarjeta */
        @media (max-width: 760px) {
            .ingenios-shell { width: calc(100% - 32px); }
            .usr-toolbar select { flex: 1 1 140px; max-width: none; }
            .usr-table thead { display: none; }
            .usr-table, .usr-table tbody, .usr-table tr, .usr-table td { display: block; width: 100%; }
            .usr-table tbody tr { padding: 14px 16px; border-bottom: 1px solid var(--border); }
            .usr-table td { padding: 5px 0; border: 0; }
            .usr-table td[data-label]::before { content: attr(data-label); display: block; font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #9aa79e; margin-bottom: 3px; }
            .usr-table tbody tr.is-flagged td:first-child { box-shadow: none; }
            .usr-table tbody tr.is-flagged { box-shadow: inset 3px 0 0 #f59e0b; }
            .usr-person { min-width: 0; }
            .usr-mod span, .usr-mod b { white-space: normal; }
            .usr-foot { justify-content: center; }
        }
        @media (max-width: 520px) {
            .usr-kpis { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body>
<div class="ingenios-shell">
    <a href="<?= htmlspecialchars($volverA) ?>" class="btn-back">
        <span class="material-symbols-outlined">arrow_back</span>
        <?= $scopeConfig ? 'Volver al módulo' : 'Volver al panel' ?>
    </a>

    <div class="ingenios-topbar">
        <div class="page-heading">
            <span class="eyebrow">Administración</span>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <p>Gestiona los usuarios, los módulos a los que tienen acceso y el rol que tienen en cada uno.</p>
        </div>

        <a href="crear_usuario.php<?= $scope ? '?scope=' . urlencode($scope) : '' ?>" class="btn-primary">
            <span class="material-symbols-outlined">person_add</span>
            Crear usuario
        </a>
    </div>

    <div class="usr-kpis">
        <div class="usr-kpi">
            <span class="usr-kpi-ico"><span class="material-symbols-outlined">group</span></span>
            <div><strong><?= count($usuarios) ?></strong><span class="lbl">Usuarios registrados</span></div>
        </div>
        <button type="button" class="usr-kpi" data-quick="super" title="Mostrar solo superadministradores">
            <span class="usr-kpi-ico is-gold"><span class="material-symbols-outlined">shield_person</span></span>
            <div><strong><?= $totalSuperadmin ?></strong><span class="lbl">Superadministradores</span></div>
        </button>
        <button type="button" class="usr-kpi" data-quick="sinrol" title="Mostrar usuarios con algún módulo sin rol">
            <span class="usr-kpi-ico is-warn"><span class="material-symbols-outlined">error</span></span>
            <div><strong><?= $totalSinRol ?></strong><span class="lbl">Con módulo sin rol</span></div>
        </button>
        <button type="button" class="usr-kpi" data-quick="sinmod" title="Mostrar usuarios sin módulos">
            <span class="usr-kpi-ico is-gray"><span class="material-symbols-outlined">block</span></span>
            <div><strong><?= $totalSinModulo ?></strong><span class="lbl">Sin acceso a módulos</span></div>
        </button>
    </div>

    <div class="ingenios-card">
        <?php if (count($usuarios) === 0): ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">group</span>
                <strong>Aún no hay usuarios registrados</strong>
                <span>Crea el primero con el botón "Crear usuario".</span>
            </div>
        <?php else: ?>
            <div class="usr-toolbar">
                <label class="usr-search">
                    <span class="material-symbols-outlined">search</span>
                    <input type="search" id="fBuscar" placeholder="Buscar por nombre, correo o ID…" autocomplete="off">
                </label>
                <select id="fModulo" aria-label="Filtrar por módulo">
                    <option value="">Todos los módulos</option>
                    <?php foreach (array_keys($opcModulos) as $op): ?>
                        <option value="<?= htmlspecialchars(mb_strtolower($op)) ?>"><?= htmlspecialchars($op) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fRol" aria-label="Filtrar por rol">
                    <option value="">Todos los roles</option>
                    <?php foreach (array_keys($opcRoles) as $op): ?>
                        <option value="<?= htmlspecialchars(mb_strtolower($op)) ?>"><?= htmlspecialchars($op) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fIngenio" aria-label="Filtrar por ingenio">
                    <option value="">Todos los ingenios</option>
                    <?php foreach (array_keys($opcIngenios) as $op): ?>
                        <option value="<?= htmlspecialchars(mb_strtolower($op)) ?>"><?= htmlspecialchars($op) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="usr-clear" id="fLimpiar">
                    <span class="material-symbols-outlined">filter_alt_off</span> Limpiar filtros
                </button>
            </div>

            <table class="usr-table" id="tablaUsuarios">
                <thead>
                <tr>
                    <th data-sort="nombre" class="is-sorted">Usuario <span class="material-symbols-outlined">arrow_upward</span></th>
                    <th data-sort="ingenio">Ingenio <span class="material-symbols-outlined">swap_vert</span></th>
                    <th data-sort="rol">Rol en la plataforma <span class="material-symbols-outlined">swap_vert</span></th>
                    <th>Módulos · rol en cada uno</th>
                    <th style="width:1%;">Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $usuario):
                    $modulosTxt = mb_strtolower(implode('|', array_column($usuario['lista_modulos'], 'modulo')));
                    $rolesTxt = mb_strtolower(implode('|', array_merge([$usuario['nombre_rol']], array_column($usuario['lista_modulos'], 'rol'))));
                    $sinIngenio = $usuario['ingenio'] === 'Sin ingenio';
                ?>
                    <tr class="<?= $usuario['sin_rol'] ? 'is-flagged' : '' ?>"
                        data-texto="<?= htmlspecialchars(mb_strtolower($usuario['nombre'] . ' ' . $usuario['correo'] . ' #' . $usuario['id'])) ?>"
                        data-nombre="<?= htmlspecialchars(mb_strtolower($usuario['nombre'])) ?>"
                        data-ingenio="<?= htmlspecialchars(mb_strtolower($usuario['ingenio'])) ?>"
                        data-rol="<?= htmlspecialchars(mb_strtolower($usuario['nombre_rol'])) ?>"
                        data-modulos="|<?= htmlspecialchars($modulosTxt) ?>|"
                        data-roles="|<?= htmlspecialchars($rolesTxt) ?>|"
                        data-super="<?= $usuario['es_super'] ? 1 : 0 ?>"
                        data-sinrol="<?= $usuario['sin_rol'] ? 1 : 0 ?>"
                        data-sinmod="<?= (!$usuario['lista_modulos'] && !$usuario['es_super']) ? 1 : 0 ?>">
                        <td data-label="Usuario">
                            <div class="usr-person">
                                <span class="usr-avatar<?= $usuario['es_super'] ? ' is-super' : '' ?>"><?= htmlspecialchars(usr_iniciales($usuario['nombre'])) ?></span>
                                <div>
                                    <b><?= htmlspecialchars($usuario['nombre']) ?><span class="usr-id">#<?= (int) $usuario['id'] ?></span></b>
                                    <small><?= htmlspecialchars($usuario['correo']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td data-label="Ingenio">
                            <span class="usr-ingenio<?= $sinIngenio ? ' is-none' : '' ?>">
                                <span class="material-symbols-outlined">factory</span><?= htmlspecialchars($usuario['ingenio']) ?>
                            </span>
                        </td>
                        <td data-label="Rol en la plataforma">
                            <span class="usr-pill<?= $usuario['es_super'] ? ' is-super' : '' ?>">
                                <?php if ($usuario['es_super']): ?><span class="material-symbols-outlined">shield_person</span><?php endif; ?>
                                <?= htmlspecialchars($usuario['nombre_rol']) ?>
                            </span>
                        </td>
                        <td data-label="Módulos · rol en cada uno">
                            <?php if ($usuario['es_super'] && !$usuario['lista_modulos']): ?>
                                <span class="usr-all"><span class="material-symbols-outlined">all_inclusive</span>Acceso total a todos los módulos</span>
                            <?php elseif ($usuario['lista_modulos']): ?>
                                <div class="usr-mods">
                                    <?php foreach ($usuario['lista_modulos'] as $mod):
                                        $falta = $mod['rol'] === '' && !$usuario['es_super']; ?>
                                        <span class="usr-mod t<?= usr_tono_modulo($mod['modulo']) ?><?= $falta ? ' is-missing' : '' ?>"
                                              title="<?= htmlspecialchars($falta ? 'Sin rol asignado en ' . $mod['modulo'] . ': edita el usuario para asignarlo' : 'Rol en ' . $mod['modulo']) ?>">
                                            <span><?= htmlspecialchars($mod['modulo']) ?></span>
                                            <?php if ($falta): ?>
                                                <b><span class="material-symbols-outlined">warning</span>Sin rol</b>
                                            <?php else: ?>
                                                <b><?= htmlspecialchars($mod['rol'] !== '' ? $mod['rol'] : 'Superadmin') ?></b>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="usr-nomod">Sin acceso a módulos</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Acciones">
                            <div class="row-actions">
                                <a class="action-btn" href="editar_usuario.php?id=<?= (int) $usuario['id'] ?><?= $qsScope ?>" title="Editar usuario y roles">
                                    <span class="material-symbols-outlined">edit</span>
                                </a>
                                <?php if (!$usuario['es_super']): ?>
                                    <a class="action-btn is-danger btn-delete" href="#"
                                       data-url="eliminar_usuario.php?id=<?= (int) $usuario['id'] ?><?= $qsScope ?>"
                                       data-nombre="<?= htmlspecialchars($usuario['nombre']) ?>"
                                       title="Eliminar usuario">
                                        <span class="material-symbols-outlined">delete</span>
                                    </a>
                                <?php else: ?>
                                    <span class="action-btn" title="Superadministrador protegido" style="cursor:default;color:#b98900;">
                                        <span class="material-symbols-outlined">lock</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="usr-empty-filter" id="sinResultados">
                <span class="material-symbols-outlined">search_off</span>
                Ningún usuario coincide con los filtros.
            </div>

            <div class="usr-foot">
                <span id="conteo"></span>
                <div class="usr-pager" id="paginador"></div>
                <label>Mostrar
                    <select id="porPagina">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="0">Todos</option>
                    </select>
                </label>
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($usuarios) > 0): ?>
        <div class="usr-legend">
            <span><i style="background:#f59e0b"></i>Usuario con algún módulo sin rol: edítalo para asignárselo.</span>
            <span><span class="material-symbols-outlined" style="font-size:16px">info</span>El rol de cada módulo solo da permisos dentro de ese módulo.</span>
        </div>
    <?php endif; ?>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="confirm-icon">
            <span class="material-symbols-outlined">warning</span>
        </div>
        <h3>¿Eliminar usuario?</h3>
        <p id="deleteTexto">Esta acción no se puede deshacer.</p>

        <div class="modal-buttons">
            <button id="cancelBtn" type="button">Cancelar</button>
            <a id="confirmDelete" href="#">Eliminar</a>
        </div>
    </div>
</div>

<script>
(function () {
    const tabla = document.getElementById("tablaUsuarios");
    if (tabla) {
        const tbody = tabla.tBodies[0];
        const filas = Array.from(tbody.rows);
        const $ = (id) => document.getElementById(id);
        const f = { buscar: $("fBuscar"), modulo: $("fModulo"), rol: $("fRol"), ingenio: $("fIngenio") };
        const quickBtns = document.querySelectorAll("[data-quick]");
        let quick = "", pagina = 1, orden = { campo: "nombre", dir: 1 };

        function coincide(tr) {
            const d = tr.dataset, q = f.buscar.value.trim().toLowerCase();
            if (q && !q.split(/\s+/).every((t) => d.texto.includes(t))) return false;
            if (f.modulo.value && !d.modulos.includes("|" + f.modulo.value + "|")) return false;
            if (f.rol.value && !d.roles.includes("|" + f.rol.value + "|")) return false;
            if (f.ingenio.value && d.ingenio !== f.ingenio.value) return false;
            if (quick === "super" && d.super !== "1") return false;
            if (quick === "sinrol" && d.sinrol !== "1") return false;
            if (quick === "sinmod" && d.sinmod !== "1") return false;
            return true;
        }

        function pintar() {
            const visibles = filas.filter(coincide);
            const porPag = parseInt($("porPagina").value, 10) || visibles.length || 1;
            const paginas = Math.max(1, Math.ceil(visibles.length / porPag));
            pagina = Math.min(pagina, paginas);
            const ini = (pagina - 1) * porPag;
            filas.forEach((tr) => (tr.style.display = "none"));
            visibles.slice(ini, ini + porPag).forEach((tr) => (tr.style.display = ""));

            $("sinResultados").style.display = visibles.length ? "none" : "block";
            $("conteo").textContent = visibles.length
                ? `Mostrando ${ini + 1}–${Math.min(ini + porPag, visibles.length)} de ${visibles.length} usuario(s)` + (visibles.length < filas.length ? ` (filtrados de ${filas.length})` : "")
                : "0 usuarios";

            const pager = $("paginador");
            pager.innerHTML = "";
            if (paginas > 1) {
                const btn = (txt, p, on, dis) => {
                    const b = document.createElement("button");
                    b.type = "button"; b.innerHTML = txt; b.disabled = !!dis;
                    if (on) b.classList.add("is-on");
                    b.addEventListener("click", () => { pagina = p; pintar(); });
                    pager.appendChild(b);
                };
                btn("‹", pagina - 1, false, pagina === 1);
                for (let p = 1; p <= paginas; p++) {
                    if (p === 1 || p === paginas || Math.abs(p - pagina) <= 1) btn(p, p, p === pagina);
                    else if (Math.abs(p - pagina) === 2) { const s = document.createElement("span"); s.textContent = "…"; pager.appendChild(s); }
                }
                btn("›", pagina + 1, false, pagina === paginas);
            }

            const hayFiltro = f.buscar.value || f.modulo.value || f.rol.value || f.ingenio.value || quick;
            $("fLimpiar").classList.toggle("is-visible", !!hayFiltro);
            quickBtns.forEach((b) => b.classList.toggle("is-on", b.dataset.quick === quick));
        }

        function ordenar(campo) {
            orden.dir = orden.campo === campo ? -orden.dir : 1;
            orden.campo = campo;
            filas.sort((a, b) => a.dataset[campo].localeCompare(b.dataset[campo], "es") * orden.dir || a.dataset.nombre.localeCompare(b.dataset.nombre, "es"));
            filas.forEach((tr) => tbody.appendChild(tr));
            tabla.querySelectorAll("th[data-sort]").forEach((th) => {
                const on = th.dataset.sort === campo;
                th.classList.toggle("is-sorted", on);
                th.querySelector(".material-symbols-outlined").textContent = on ? (orden.dir === 1 ? "arrow_upward" : "arrow_downward") : "swap_vert";
            });
            pintar();
        }

        Object.values(f).forEach((el) => el.addEventListener("input", () => { pagina = 1; pintar(); }));
        $("porPagina").addEventListener("change", () => { pagina = 1; pintar(); });
        quickBtns.forEach((b) => b.addEventListener("click", () => { quick = quick === b.dataset.quick ? "" : b.dataset.quick; pagina = 1; pintar(); }));
        $("fLimpiar").addEventListener("click", () => { Object.values(f).forEach((el) => (el.value = "")); quick = ""; pagina = 1; pintar(); });
        tabla.querySelectorAll("th[data-sort]").forEach((th) => th.addEventListener("click", () => ordenar(th.dataset.sort)));
        document.addEventListener("keydown", (e) => {
            if (e.key === "/" && document.activeElement.tagName !== "INPUT") { e.preventDefault(); f.buscar.focus(); }
        });
        pintar();
    }

    const modal = document.getElementById("deleteModal");
    const confirmBtn = document.getElementById("confirmDelete");
    document.querySelectorAll(".btn-delete").forEach((btn) => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            confirmBtn.href = this.dataset.url;
            document.getElementById("deleteTexto").textContent = "Se eliminará a " + this.dataset.nombre + " y sus accesos a los módulos. Esta acción no se puede deshacer.";
            modal.classList.add("active");
        });
    });
    document.getElementById("cancelBtn").onclick = () => modal.classList.remove("active");
    window.addEventListener("click", (e) => { if (e.target === modal) modal.classList.remove("active"); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") modal.classList.remove("active"); });
})();
</script>
</body>
</html>
