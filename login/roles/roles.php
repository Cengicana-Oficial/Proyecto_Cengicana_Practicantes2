<?php
session_start();
require_once("../config/conexion.php");
require_once("../config/permisos_roles.php");
require_once __DIR__ . "/_guard.php";

$conn = Conexion::conectar();
asegurar_tablas_permisos($conn);
// Las listas de permisos de un rol (Superadmin tiene mas de 100) superan el limite por defecto de GROUP_CONCAT (1024).
$conn->exec("SET SESSION group_concat_max_len = 1000000");

$stmt = $conn->query("
    SELECT
        r.id,
        r.nombre_rol,
        COUNT(rp.permiso_id) AS total_permisos,
        GROUP_CONCAT(p.nombre_permiso ORDER BY p.nombre_permiso SEPARATOR ',') AS permisos,
        (SELECT GROUP_CONCAT(m.nombre ORDER BY m.nombre SEPARATOR '##')
           FROM rol_modulo rm INNER JOIN modulos m ON m.id = rm.modulo_id
          WHERE rm.rol_id = r.id) AS modulos
    FROM roles r
    LEFT JOIN rol_permiso rp ON rp.rol_id = r.id
    LEFT JOIN permisos p ON p.id = rp.permiso_id
    GROUP BY r.id, r.nombre_rol
    ORDER BY r.id
");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Usuarios por rol: como rol de la plataforma o como rol en algun modulo (sin repetir usuario).
$usuariosPorRol = [];
foreach ($conn->query("
    SELECT rol_id, COUNT(DISTINCT usuario_id) FROM (
        SELECT rol_id, id AS usuario_id FROM usuarios WHERE rol_id IS NOT NULL
        UNION ALL
        SELECT rol_id, usuario_id FROM usuario_modulo WHERE rol_id IS NOT NULL
    ) x GROUP BY rol_id
")->fetchAll(PDO::FETCH_NUM) as [$rolId, $total]) {
    $usuariosPorRol[(int) $rolId] = (int) $total;
}

$totalPermisosSistema = count(obtener_permisos($conn));
$opcModulos = [];
$totalGenerales = 0;
$totalConUsuarios = 0;
$totalSinPermisos = 0;

foreach ($roles as &$r) {
    $r['lista_modulos'] = array_values(array_filter(explode('##', (string) ($r['modulos'] ?? ''))));
    $r['usuarios'] = $usuariosPorRol[(int) $r['id']] ?? 0;
    $r['protegido'] = strtolower($r['nombre_rol']) === 'superadmin';

    // Permisos agrupados por modulo, con su etiqueta legible.
    $r['grupos'] = [];
    foreach (array_filter(explode(',', (string) ($r['permisos'] ?? ''))) as $nombrePermiso) {
        $grupo = titulo_corto_grupo_permiso(clasificar_grupo_permiso($nombrePermiso));
        $r['grupos'][$grupo][] = etiqueta_permiso($nombrePermiso);
    }
    ksort($r['grupos']);

    foreach ($r['lista_modulos'] as $m) {
        $opcModulos[$m] = true;
    }
    $totalGenerales += $r['lista_modulos'] ? 0 : 1;
    $totalConUsuarios += $r['usuarios'] > 0 ? 1 : 0;
    $totalSinPermisos += (int) $r['total_permisos'] === 0 ? 1 : 0;
}
unset($r);
ksort($opcModulos);

/** Color estable por modulo (el mismo que en la lista de usuarios). */
function rol_tono_modulo($nombre)
{
    $clave = mb_strtolower((string) $nombre);
    $fijos = ['cursos' => 0, 'laboratorio' => 1, 'solicitudes internas' => 2, 'sigec' => 3, 'servicio tecnico' => 4, 'solicitud de visitas' => 5, 'comit' => 6];
    foreach ($fijos as $patron => $tono) {
        if (strpos($clave, $patron) !== false) {
            return $tono;
        }
    }
    return abs(crc32($clave)) % 7;
}

function rol_icono($nombre)
{
    $n = mb_strtolower($nombre);
    $mapa = [
        'superadmin' => 'shield_person', 'administrador' => 'admin_panel_settings', 'instructor' => 'co_present',
        'estudiante' => 'school', 'gestor' => 'manage_accounts', 'laboratorio' => 'biotech', 'analista' => 'science',
        'recepcion' => 'move_to_inbox', 'cnico' => 'build', 'director' => 'workspace_premium', 'jefe de programa' => 'supervisor_account',
        'colaborador' => 'group',
    ];
    foreach ($mapa as $patron => $icono) {
        if (strpos($n, $patron) !== false) {
            return $icono;
        }
    }
    return 'badge';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles | CENGICAÑA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/ingenios.css?v=<?= @filemtime(__DIR__ . '/../assets/ingenios.css') ?: '1' ?>">
    <style>
        .ingenios-shell { width: min(1280px, calc(100% - 48px)); }

        /* Indicadores */
        .rl-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .rl-kpi { display: flex; align-items: center; gap: 14px; padding: 16px 18px; border: 1px solid var(--border); border-radius: 16px; background: #fff; box-shadow: 0 6px 18px rgba(31, 86, 50, .05); text-align: left; font-family: inherit; color: inherit; }
        button.rl-kpi { cursor: pointer; transition: border-color .16s, transform .16s; }
        button.rl-kpi:hover { border-color: #aed58d; transform: translateY(-1px); }
        button.rl-kpi.is-on { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(115, 188, 37, .18); }
        .rl-kpi-ico { width: 42px; height: 42px; flex: none; display: grid; place-items: center; border-radius: 12px; background: var(--surface-soft); color: var(--primary-deep); }
        .rl-kpi-ico.is-gold { background: #fff6d6; color: #8a6a00; }
        .rl-kpi-ico.is-blue { background: #e6f2fb; color: #1d5f8f; }
        .rl-kpi-ico.is-gray { background: #f0f2ef; color: #5f6b62; }
        .rl-kpi strong { display: block; font-size: 22px; line-height: 1.1; color: var(--text); }
        .rl-kpi .lbl { font-size: 12px; color: var(--muted); font-weight: 600; }

        /* Filtros */
        .rl-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }
        .rl-search { position: relative; flex: 1 1 280px; }
        .rl-search .material-symbols-outlined { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 20px; }
        .rl-search input { width: 100%; padding: 11px 12px 11px 40px; border: 1px solid var(--border); border-radius: 12px; font: inherit; font-size: 13.5px; background: #fff; }
        .rl-toolbar select { padding: 11px 12px; border: 1px solid var(--border); border-radius: 12px; font: inherit; font-size: 13px; background: #fff; color: var(--text); }
        .rl-search input:focus, .rl-toolbar select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(115, 188, 37, .18); }
        .rl-clear { display: none; align-items: center; gap: 4px; padding: 10px 12px; border: 0; border-radius: 12px; background: transparent; color: var(--primary-deep); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
        .rl-clear.is-visible { display: inline-flex; }
        .rl-clear:hover { background: var(--surface-soft); }
        .rl-count { margin-left: auto; font-size: 12.5px; color: var(--muted); }

        /* Tarjetas de rol */
        .rl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; align-items: start; }
        .rl-card { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: 18px; background: #fff; box-shadow: 0 6px 18px rgba(31, 86, 50, .05); overflow: hidden; transition: border-color .16s, box-shadow .16s; }
        .rl-card:hover { border-color: #c4dfae; box-shadow: 0 12px 28px rgba(31, 86, 50, .09); }
        .rl-card.is-super { border-color: #efe2a4; }
        .rl-head { display: flex; align-items: flex-start; gap: 12px; padding: 18px 18px 12px; }
        .rl-ico { width: 44px; height: 44px; flex: none; display: grid; place-items: center; border-radius: 13px; background: linear-gradient(135deg, #8fd14f, #1f5632); color: #fff; }
        .rl-card.is-super .rl-ico { background: linear-gradient(135deg, #ffd84d, #b98900); }
        .rl-head h2 { margin: 0; font-size: 16px; color: var(--text); letter-spacing: -.01em; }
        .rl-head h2 small { color: #9aa79e; font-size: 11px; font-weight: 700; margin-left: 4px; }
        .rl-mods { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
        .rl-mod { padding: 3px 8px; border-radius: 7px; font-size: 11.5px; font-weight: 700; background: var(--t-bg); color: var(--t-fg); border: 1px solid var(--t-b); }
        .rl-mod.is-general { --t-bg: #f2f4f1; --t-fg: #5f6b62; --t-b: #e1e6de; display: inline-flex; align-items: center; gap: 4px; }
        .rl-mod.is-general .material-symbols-outlined { font-size: 14px; }
        .t0 { --t-bg: #eef8df; --t-fg: #2f6b12; --t-b: #d3eab8; }
        .t1 { --t-bg: #e6f2fb; --t-fg: #1d5f8f; --t-b: #c6dff2; }
        .t2 { --t-bg: #f3ecfb; --t-fg: #6a3fa0; --t-b: #e0d1f3; }
        .t3 { --t-bg: #fdf0e4; --t-fg: #9a4f12; --t-b: #f4d8bd; }
        .t4 { --t-bg: #e5f6f3; --t-fg: #17695c; --t-b: #c1e8e0; }
        .t5 { --t-bg: #fbe9ee; --t-fg: #9b2c4c; --t-b: #f2cbd6; }
        .t6 { --t-bg: #fdf7d8; --t-fg: #7a5d00; --t-b: #efe2a4; }

        .rl-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 0 18px 14px; }
        .rl-stat { padding: 10px 12px; border-radius: 12px; background: #f7faf4; }
        .rl-stat strong { display: block; font-size: 18px; color: var(--text); }
        .rl-stat span { font-size: 11.5px; color: var(--muted); font-weight: 600; }
        .rl-bar { height: 5px; margin-top: 7px; border-radius: 99px; background: #e3ecdc; overflow: hidden; }
        .rl-bar i { display: block; height: 100%; border-radius: 99px; background: var(--primary); }

        .rl-groups { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 18px 14px; }
        .rl-group { display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; border-radius: 999px; background: #f2f6ee; color: #3f5245; font-size: 11.5px; font-weight: 700; }
        .rl-group b { min-width: 18px; padding: 1px 6px; border-radius: 99px; background: #fff; color: var(--primary-deep); font-size: 11px; text-align: center; }
        .rl-nogroups { padding: 0 18px 14px; font-size: 12.5px; color: var(--muted); display: flex; align-items: center; gap: 5px; }
        .rl-nogroups .material-symbols-outlined { font-size: 16px; }

        .rl-detail { border-top: 1px solid #edf3e8; }
        .rl-detail summary { list-style: none; display: flex; align-items: center; gap: 6px; padding: 11px 18px; font-size: 12.5px; font-weight: 700; color: var(--primary-deep); cursor: pointer; }
        .rl-detail summary::-webkit-details-marker { display: none; }
        .rl-detail summary:hover { background: #f8fcf3; }
        .rl-detail summary .material-symbols-outlined { font-size: 18px; transition: transform .16s; }
        .rl-detail[open] summary .material-symbols-outlined { transform: rotate(180deg); }
        .rl-perms { max-height: 280px; overflow: auto; padding: 4px 18px 14px; }
        .rl-perms h4 { margin: 10px 0 6px; font-size: 10.5px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; color: #9aa79e; }
        .rl-perms ul { margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 5px; }
        .rl-perms li { padding: 3px 8px; border-radius: 7px; border: 1px solid #e3ecdc; font-size: 11.5px; color: #3f5245; background: #fff; }

        .rl-foot { display: flex; align-items: center; gap: 8px; padding: 12px 18px; margin-top: auto; border-top: 1px solid #edf3e8; background: #fbfdf9; }
        .rl-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 10px; background: #fff; color: var(--primary-deep); font: inherit; font-size: 12.5px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .rl-btn:hover { background: var(--surface-soft); border-color: #aed58d; }
        .rl-btn .material-symbols-outlined { font-size: 17px; }
        .rl-btn.is-danger { color: var(--danger); margin-left: auto; }
        .rl-btn.is-danger:hover { background: var(--danger-soft); border-color: #f4c7c1; }
        .rl-btn.is-disabled { margin-left: auto; color: #9aa79e; cursor: not-allowed; background: #f6f7f5; }
        .rl-btn.is-lock { margin-left: auto; color: #8a6a00; background: #fff9e3; border-color: #efe2a4; cursor: default; }

        .rl-empty { display: none; padding: 50px 20px; text-align: center; color: var(--muted); border: 1px dashed var(--border); border-radius: 18px; background: #fff; }
        .rl-empty .material-symbols-outlined { font-size: 36px; display: block; margin-bottom: 6px; }

        /* Selector de vista */
        .rl-view { display: inline-flex; padding: 3px; border: 1px solid var(--border); border-radius: 12px; background: #fff; }
        .rl-view button { display: inline-flex; align-items: center; gap: 5px; padding: 7px 11px; border: 0; border-radius: 9px; background: transparent; color: var(--muted); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
        .rl-view button .material-symbols-outlined { font-size: 18px; }
        .rl-view button.is-on { background: var(--primary-deep); color: #fff; }

        /* Vista de lista: una fila por rol */
        .rl-list-head { display: none; }
        .rl-list-head, .rl-grid.is-list .rl-card { grid-template-columns: minmax(240px, 1.5fr) 150px 90px minmax(240px, 2fr) 190px; column-gap: 16px; }
        body.vista-lista .rl-list-head { display: grid; padding: 11px 18px; border: 1px solid var(--border); border-bottom: 0; border-radius: 18px 18px 0 0; background: #f7faf4; font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .rl-grid.is-list { display: block; border: 1px solid var(--border); border-radius: 0 0 18px 18px; background: #fff; overflow: hidden; }
        .rl-grid.is-list .rl-card { display: grid; align-items: center; padding: 12px 18px; border: 0; border-bottom: 1px solid #edf3e8; border-radius: 0; box-shadow: none; overflow: visible; }
        .rl-grid.is-list .rl-card:last-child { border-bottom: 0; }
        .rl-grid.is-list .rl-card:hover { background: #f8fcf3; box-shadow: none; }
        .rl-grid.is-list .rl-card.is-super { box-shadow: inset 3px 0 0 #e8c547; }
        .rl-grid.is-list .rl-head { padding: 0; align-items: center; }
        .rl-grid.is-list .rl-ico { width: 36px; height: 36px; border-radius: 11px; }
        .rl-grid.is-list .rl-ico .material-symbols-outlined { font-size: 20px; }
        .rl-grid.is-list .rl-head h2 { font-size: 14.5px; }
        .rl-grid.is-list .rl-mods { margin-top: 4px; }
        .rl-grid.is-list .rl-mod { padding: 2px 7px; font-size: 11px; }
        .rl-grid.is-list .rl-stats { display: contents; }
        .rl-grid.is-list .rl-stat { padding: 0; background: none; }
        .rl-grid.is-list .rl-stat strong { display: inline; font-size: 15px; }
        .rl-grid.is-list .rl-stat span { margin-left: 4px; font-size: 11.5px; }
        .rl-grid.is-list .rl-stat:last-child span { display: none; }
        .rl-grid.is-list .rl-bar { margin-top: 5px; max-width: 130px; }
        .rl-grid.is-list .rl-groups, .rl-grid.is-list .rl-nogroups { padding: 0; }
        .rl-grid.is-list .rl-permcol { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-width: 0; }
        .rl-grid.is-list .rl-detail { border: 0; }
        .rl-grid.is-list .rl-detail summary { padding: 3px 6px; border-radius: 8px; font-size: 12px; }
        .rl-grid.is-list .rl-detail[open] { flex-basis: 100%; }
        .rl-grid.is-list .rl-perms { padding: 2px 0 6px; max-height: 240px; }
        .rl-grid.is-list .rl-foot { padding: 0; margin: 0; border: 0; background: none; justify-content: flex-end; }
        .rl-grid.is-list .rl-btn { padding: 7px 10px; }
        .rl-grid.is-list .rl-btn.is-danger, .rl-grid.is-list .rl-btn.is-disabled, .rl-grid.is-list .rl-btn.is-lock { margin-left: 0; }
        .rl-grid.is-list .rl-lbl-extra { display: none; }
        body.vista-lista .rl-empty { border-radius: 0 0 18px 18px; border-top: 0; }

        @media (max-width: 1000px) {
            /* En pantallas medianas la lista se compacta en dos lineas por rol */
            body.vista-lista .rl-list-head { display: none; }
            .rl-grid.is-list { border-radius: 18px; }
            .rl-grid.is-list .rl-card { grid-template-columns: minmax(0, 1fr) auto auto; row-gap: 8px; }
            .rl-grid.is-list .rl-permcol { grid-column: 1 / -1; grid-row: 2; }
            .rl-grid.is-list .rl-stat:last-child span { display: inline; }
            .rl-grid.is-list .rl-foot { grid-column: 1 / -1; grid-row: 3; justify-content: flex-start; }
        }

        @media (max-width: 980px) { .rl-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) {
            .ingenios-shell { width: calc(100% - 32px); }
            .rl-grid { grid-template-columns: minmax(0, 1fr); }
            .rl-toolbar select { flex: 1 1 160px; }
            .rl-count { margin-left: 0; width: 100%; }
        }
        @media (max-width: 520px) { .rl-kpis { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body class="vista-lista">
<div class="ingenios-shell">
    <a href="../Menu.php" class="btn-back">
        <span class="material-symbols-outlined">arrow_back</span>
        Volver al panel
    </a>

    <div class="ingenios-topbar">
        <div class="page-heading">
            <span class="eyebrow">Administración</span>
            <h1>Roles y permisos</h1>
            <p>Define en qué módulos se usa cada rol y qué acciones permite. El rol de un módulo solo da permisos dentro de ese módulo.</p>
        </div>

        <a href="crear_rol.php" class="btn-primary">
            <span class="material-symbols-outlined">add</span>
            Crear rol
        </a>
    </div>

    <div class="rl-kpis">
        <div class="rl-kpi">
            <span class="rl-kpi-ico"><span class="material-symbols-outlined">admin_panel_settings</span></span>
            <div><strong><?= count($roles) ?></strong><span class="lbl">Roles registrados</span></div>
        </div>
        <button type="button" class="rl-kpi" data-quick="general" title="Mostrar roles generales">
            <span class="rl-kpi-ico is-gold"><span class="material-symbols-outlined">all_inclusive</span></span>
            <div><strong><?= $totalGenerales ?></strong><span class="lbl">Generales (todos los módulos)</span></div>
        </button>
        <button type="button" class="rl-kpi" data-quick="usuarios" title="Mostrar roles en uso">
            <span class="rl-kpi-ico is-blue"><span class="material-symbols-outlined">group</span></span>
            <div><strong><?= $totalConUsuarios ?></strong><span class="lbl">Con usuarios asignados</span></div>
        </button>
        <button type="button" class="rl-kpi" data-quick="sinpermisos" title="Mostrar roles sin permisos">
            <span class="rl-kpi-ico is-gray"><span class="material-symbols-outlined">key_off</span></span>
            <div><strong><?= $totalSinPermisos ?></strong><span class="lbl">Sin permisos asignados</span></div>
        </button>
    </div>

    <?php if (count($roles) === 0): ?>
        <div class="ingenios-card">
            <div class="empty-state">
                <span class="material-symbols-outlined">admin_panel_settings</span>
                <strong>Aún no hay roles registrados</strong>
                <span>Crea el primero con el botón "Crear rol".</span>
            </div>
        </div>
    <?php else: ?>
        <div class="rl-toolbar">
            <label class="rl-search">
                <span class="material-symbols-outlined">search</span>
                <input type="search" id="fBuscar" placeholder="Buscar rol o permiso…" autocomplete="off">
            </label>
            <select id="fModulo" aria-label="Filtrar por módulo">
                <option value="">Todos los módulos</option>
                <option value="__general">Solo roles generales</option>
                <?php foreach (array_keys($opcModulos) as $op): ?>
                    <option value="<?= htmlspecialchars(mb_strtolower($op)) ?>"><?= htmlspecialchars($op) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="fOrden" aria-label="Ordenar">
                <option value="id">Orden: ID</option>
                <option value="nombre">Orden: nombre</option>
                <option value="permisos">Orden: más permisos</option>
                <option value="usuarios">Orden: más usuarios</option>
            </select>
            <button type="button" class="rl-clear" id="fLimpiar">
                <span class="material-symbols-outlined">filter_alt_off</span> Limpiar filtros
            </button>
            <span class="rl-count" id="conteo"></span>
            <div class="rl-view" role="group" aria-label="Tipo de vista">
                <button type="button" data-vista="lista" title="Ver como lista"><span class="material-symbols-outlined">view_list</span>Lista</button>
                <button type="button" data-vista="tarjetas" title="Ver como tarjetas"><span class="material-symbols-outlined">grid_view</span>Tarjetas</button>
            </div>
        </div>

        <div class="rl-list-head" aria-hidden="true">
            <span>Rol y módulos</span><span>Permisos</span><span>Usuarios</span><span>Permisos por módulo</span><span>Acciones</span>
        </div>
        <div class="rl-grid is-list" id="gridRoles">
            <?php foreach ($roles as $r):
                $pct = $totalPermisosSistema > 0 ? min(100, round((int) $r['total_permisos'] * 100 / $totalPermisosSistema)) : 0;
                $permisosTxt = mb_strtolower(implode(' ', array_merge(...array_values($r['grupos'] ?: [[]]))));
            ?>
                <article class="rl-card<?= $r['protegido'] ? ' is-super' : '' ?>"
                         data-id="<?= (int) $r['id'] ?>"
                         data-nombre="<?= htmlspecialchars(mb_strtolower($r['nombre_rol'])) ?>"
                         data-texto="<?= htmlspecialchars(mb_strtolower($r['nombre_rol']) . ' ' . $permisosTxt) ?>"
                         data-modulos="|<?= htmlspecialchars(mb_strtolower(implode('|', $r['lista_modulos']))) ?>|"
                         data-general="<?= $r['lista_modulos'] ? 0 : 1 ?>"
                         data-permisos="<?= (int) $r['total_permisos'] ?>"
                         data-usuarios="<?= (int) $r['usuarios'] ?>">
                    <div class="rl-head">
                        <span class="rl-ico"><span class="material-symbols-outlined"><?= rol_icono($r['nombre_rol']) ?></span></span>
                        <div>
                            <h2><?= htmlspecialchars($r['nombre_rol']) ?><small>#<?= (int) $r['id'] ?></small></h2>
                            <div class="rl-mods">
                                <?php if ($r['lista_modulos']): ?>
                                    <?php foreach ($r['lista_modulos'] as $m): ?>
                                        <span class="rl-mod t<?= rol_tono_modulo($m) ?>"><?= htmlspecialchars($m) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="rl-mod is-general" title="Se puede asignar en cualquier módulo"><span class="material-symbols-outlined">all_inclusive</span>General · todos los módulos</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="rl-stats">
                        <div class="rl-stat">
                            <strong><?= (int) $r['total_permisos'] ?></strong>
                            <span>permiso(s) de <?= $totalPermisosSistema ?></span>
                            <div class="rl-bar"><i style="width: <?= $pct ?>%"></i></div>
                        </div>
                        <div class="rl-stat">
                            <strong><?= (int) $r['usuarios'] ?></strong>
                            <span>usuario(s) con este rol</span>
                        </div>
                    </div>

                    <div class="rl-permcol">
                    <?php if ($r['grupos']): ?>
                        <div class="rl-groups">
                            <?php foreach ($r['grupos'] as $grupo => $lista): ?>
                                <span class="rl-group"><?= htmlspecialchars($grupo) ?> <b><?= count($lista) ?></b></span>
                            <?php endforeach; ?>
                        </div>
                        <details class="rl-detail">
                            <summary><span class="material-symbols-outlined">expand_more</span>Ver permisos</summary>
                            <div class="rl-perms">
                                <?php foreach ($r['grupos'] as $grupo => $lista): ?>
                                    <h4><?= htmlspecialchars($grupo) ?></h4>
                                    <ul>
                                        <?php foreach ($lista as $etiqueta): ?><li><?= htmlspecialchars($etiqueta) ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php else: ?>
                        <div class="rl-nogroups"><span class="material-symbols-outlined">info</span>Sin permisos asignados.</div>
                    <?php endif; ?>
                    </div>

                    <div class="rl-foot">
                        <a class="rl-btn" href="editar_rol.php?id=<?= (int) $r['id'] ?>">
                            <span class="material-symbols-outlined">tune</span>Editar<span class="rl-lbl-extra"> rol y permisos</span>
                        </a>
                        <?php if ($r['protegido']): ?>
                            <span class="rl-btn is-lock" title="El rol Superadmin no se puede eliminar"><span class="material-symbols-outlined">lock</span>Protegido</span>
                        <?php elseif ($r['usuarios'] > 0): ?>
                            <span class="rl-btn is-disabled" title="Tiene <?= (int) $r['usuarios'] ?> usuario(s): reasígnalos antes de eliminarlo"><span class="material-symbols-outlined">delete</span>En uso</span>
                        <?php else: ?>
                            <a class="rl-btn is-danger btn-delete" href="#" data-url="eliminar_rol.php?id=<?= (int) $r['id'] ?>" data-nombre="<?= htmlspecialchars($r['nombre_rol']) ?>">
                                <span class="material-symbols-outlined">delete</span>Eliminar
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="rl-empty" id="sinResultados">
            <span class="material-symbols-outlined">search_off</span>
            Ningún rol coincide con los filtros.
        </div>
    <?php endif; ?>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="confirm-icon">
            <span class="material-symbols-outlined">warning</span>
        </div>
        <h3>¿Eliminar rol?</h3>
        <p id="deleteTexto">Esta acción no se puede deshacer.</p>

        <div class="modal-buttons">
            <button id="cancelBtn" type="button">Cancelar</button>
            <a id="confirmDelete" href="#">Eliminar</a>
        </div>
    </div>
</div>

<script>
(function () {
    const grid = document.getElementById("gridRoles");
    if (grid) {
        const tarjetas = Array.from(grid.children);
        const $ = (id) => document.getElementById(id);
        const buscar = $("fBuscar"), modulo = $("fModulo"), orden = $("fOrden");
        const quickBtns = document.querySelectorAll("[data-quick]");

        // Vista lista / tarjetas (se recuerda en este navegador).
        function vista(v) {
            const lista = v !== "tarjetas";
            grid.classList.toggle("is-list", lista);
            document.body.classList.toggle("vista-lista", lista);
            document.querySelectorAll("[data-vista]").forEach((b) => b.classList.toggle("is-on", b.dataset.vista === (lista ? "lista" : "tarjetas")));
            try { localStorage.setItem("roles.vista", lista ? "lista" : "tarjetas"); } catch (e) {}
        }
        document.querySelectorAll("[data-vista]").forEach((b) => b.addEventListener("click", () => vista(b.dataset.vista)));
        let vistaGuardada = "lista";
        try { vistaGuardada = localStorage.getItem("roles.vista") || "lista"; } catch (e) {}
        vista(vistaGuardada);
        let quick = "";

        function pintar() {
            const q = buscar.value.trim().toLowerCase();
            const campo = orden.value;
            tarjetas.sort((a, b) => {
                if (campo === "nombre") return a.dataset.nombre.localeCompare(b.dataset.nombre, "es");
                if (campo === "id") return a.dataset.id - b.dataset.id;
                return (b.dataset[campo] - a.dataset[campo]) || a.dataset.nombre.localeCompare(b.dataset.nombre, "es");
            }).forEach((t) => grid.appendChild(t));

            let visibles = 0;
            tarjetas.forEach((t) => {
                const d = t.dataset;
                let ok = !q || q.split(/\s+/).every((p) => d.texto.includes(p));
                if (ok && modulo.value === "__general") ok = d.general === "1";
                else if (ok && modulo.value) ok = d.modulos.includes("|" + modulo.value + "|");
                if (ok && quick === "general") ok = d.general === "1";
                if (ok && quick === "usuarios") ok = d.usuarios > 0;
                if (ok && quick === "sinpermisos") ok = d.permisos === "0";
                t.style.display = ok ? "" : "none";
                visibles += ok ? 1 : 0;
            });

            $("sinResultados").style.display = visibles ? "none" : "block";
            $("conteo").textContent = visibles === tarjetas.length ? `${visibles} rol(es)` : `${visibles} de ${tarjetas.length} rol(es)`;
            $("fLimpiar").classList.toggle("is-visible", !!(buscar.value || modulo.value || quick));
            quickBtns.forEach((b) => b.classList.toggle("is-on", b.dataset.quick === quick));
        }

        [buscar, modulo, orden].forEach((el) => el.addEventListener("input", pintar));
        quickBtns.forEach((b) => b.addEventListener("click", () => { quick = quick === b.dataset.quick ? "" : b.dataset.quick; pintar(); }));
        $("fLimpiar").addEventListener("click", () => { buscar.value = ""; modulo.value = ""; quick = ""; pintar(); });
        document.addEventListener("keydown", (e) => {
            if (e.key === "/" && document.activeElement.tagName !== "INPUT") { e.preventDefault(); buscar.focus(); }
        });
        pintar();
    }

    const modal = document.getElementById("deleteModal");
    const confirmBtn = document.getElementById("confirmDelete");
    document.querySelectorAll(".btn-delete").forEach((btn) => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            confirmBtn.href = this.dataset.url;
            document.getElementById("deleteTexto").textContent = "Se eliminará el rol " + this.dataset.nombre + " y sus permisos. Esta acción no se puede deshacer.";
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
