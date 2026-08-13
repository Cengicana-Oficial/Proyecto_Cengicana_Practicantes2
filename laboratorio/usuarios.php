<?php

require_once __DIR__ . '/includes/user_module_helper.php';

lab_require_permission('laboratorio.usuarios.gestionar');

function lab_users_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'US';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : strtoupper(substr($name, 0, 2));
}

function lab_users_avatar_style(string $seed): string
{
    $palettes = [
        ['rgba(115, 188, 37, 0.14)', '#335512'],
        ['rgba(163, 211, 0, 0.16)', '#4a7d19'],
        ['rgba(206, 210, 213, 0.42)', '#4a4d49'],
        ['rgba(115, 188, 37, 0.10)', '#5f9d1f'],
        ['rgba(163, 211, 0, 0.12)', '#335512'],
        ['rgba(206, 210, 213, 0.30)', '#6f7579'],
    ];

    $hash = abs(crc32($seed));
    $palette = $palettes[$hash % count($palettes)];

    return sprintf('background:%s;color:%s;', $palette[0], $palette[1]);
}

function lab_users_role_class(string $role): string
{
    $normalized = strtolower(trim($role));

    if (strpos($normalized, 'admin') !== false) {
        return 'role-admin';
    }

    if (strpos($normalized, 'analista') !== false) {
        return 'role-analyst';
    }

    if (strpos($normalized, 'tecnico') !== false) {
        return 'role-tech';
    }

    return 'role-default';
}

$conn = lab_users_connection();
$module = lab_laboratory_module($conn);
$usuarios = lab_fetch_laboratory_users($conn, (int) $module['id']);

$ingenios = [];
$roles = [];
$usuariosActivos = 0;

foreach ($usuarios as $usuario) {
    $ingenio = trim((string) ($usuario['ingenio'] ?? ''));
    $rol = trim((string) ($usuario['nombre_rol'] ?? ''));
    $estado = (int) ($usuario['estado'] ?? 1);

    if ($ingenio !== '') {
        $ingenios[$ingenio] = $ingenio;
    }

    if ($rol !== '') {
        $roles[$rol] = $rol;
    }

    if ($estado === 1) {
        $usuariosActivos++;
    }
}

ksort($ingenios, SORT_NATURAL | SORT_FLAG_CASE);
ksort($roles, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios de Laboratorio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/button.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --page-bg: #ffffff;
            --surface: #ffffff;
            --surface-soft: #f7f8f8;
            --border: #ced2d5;
            --border-strong: #ced2d5;
            --text-main: #232523;
            --text-soft: #4a4d49;
            --brand: #73bc25;
            --brand-soft: rgba(115, 188, 37, 0.12);
            --brand-accent: #73bc25;
            --brand-secondary: #a3d300;
            --chip-bg: rgba(163, 211, 0, 0.14);
            --shadow: 0 16px 40px rgba(12, 39, 27, 0.08);
            --danger: #ff6b00;
            --danger-soft: rgba(255, 107, 0, 0.08);
            --warning: #ffcc00;
            --warning-soft: rgba(255, 204, 0, 0.14);
            --green-50: #eef7e2;
            --green-100: #dceebf;
            --green-200: #c8e58d;
            --green-300: #b3da5a;
            --green-400: #9ed129;
            --green-500: #88c818;
            --green-600: #73bc25;
            --green-700: #5f9d1f;
            --green-800: #4a7d19;
            --green-900: #335512;
            --teal-50: #f4fbe3;
            --teal-100: #e5f3b7;
            --teal-200: #d7eb86;
            --teal-300: #c8e356;
            --teal-400: #b7da2f;
            --teal-500: #a3d300;
            --teal-600: #8db600;
            --teal-700: #789700;
            --teal-800: #627800;
            --tertiary-50: #fff1e3;
            --tertiary-100: #ffd9b8;
            --tertiary-200: #ffbf86;
            --tertiary-300: #ffa255;
            --tertiary-400: #ff8a33;
            --tertiary-500: #ff6b00;
            --tertiary-600: #d95800;
            --tertiary-700: #b34700;
            --tertiary-800: #8c3600;
            --neutral-50: #f7f8f8;
            --neutral-100: #eceeed;
            --neutral-200: #dde1e3;
            --neutral-300: #ced2d5;
            --neutral-400: #b9bec2;
            --neutral-500: #a4aaaf;
            --neutral-600: #8d9398;
            --neutral-700: #6f7579;
            --neutral-800: #555b5f;
            --neutral-900: #232523;
            --color-info-50: #f4fbe3;
            --color-info-100: #e5f3b7;
            --color-info-200: #d7eb86;
            --color-info-300: #c8e356;
            --color-info-400: #b7da2f;
            --color-info-500: #a3d300;
            --color-info-600: #8db600;
            --color-info-700: #789700;
            --color-warning-50: #fff8db;
            --color-warning-100: #ffef9f;
            --color-warning-200: #ffe866;
            --color-warning-300: #ffdb33;
            --color-warning-400: #ffcf0f;
            --color-warning-500: #ffcc00;
            --color-warning-600: #d9ad00;
            --color-warning-700: #b38f00;
            --color-danger-50: #fff0e3;
            --color-danger-100: #ffd5b8;
            --color-danger-200: #ffb486;
            --color-danger-300: #ff9155;
            --color-danger-400: #ff7833;
            --color-danger-500: #ff6b00;
            --color-danger-600: #d95a00;
            --color-danger-700: #b34a00;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 32px 18px 40px;
            background:
                radial-gradient(circle at top right, rgba(163, 211, 0, 0.10), transparent 30%),
                linear-gradient(180deg, #ffffff 0%, rgba(206, 210, 213, 0.12) 100%);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
        }

        a {
            text-decoration: none;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .page-shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .hero-admin {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin-bottom: 18px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 0;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.90);
            color: var(--brand);
            font-weight: 700;
            box-shadow: 0 10px 22px rgba(12, 39, 27, 0.05);
            backdrop-filter: blur(10px);
            width: fit-content;
        }

        .panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(255, 255, 255, 0.96));
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: 0 18px 48px rgba(12, 39, 27, 0.08);
            backdrop-filter: blur(12px);
            overflow: hidden;
        }

        .panel-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: start;
            gap: 20px;
            padding: 30px 30px 18px;
        }

        .hero-copy {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0;
            max-width: 760px;
        }

        .hero-copy .back-link {
            margin-bottom: 12px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: linear-gradient(180deg, var(--brand-soft), rgba(255, 255, 255, 0.96));
            color: var(--brand);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .panel-header h1 {
            margin: 0;
            font-size: clamp(32px, 4vw, 42px);
            line-height: 1.02;
            letter-spacing: -0.04em;
        }

        .panel-header p {
            max-width: 700px;
            margin: 12px 0 0;
            color: var(--text-soft);
            font-size: 15px;
            line-height: 1.6;
        }

        .primary-action {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 14px;
            background: var(--brand);
            color: #fff;
            font-weight: 700;
            white-space: nowrap;
            box-shadow: 0 14px 28px rgba(5, 59, 42, 0.22);
        }

        .header-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 0 30px 24px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            padding: 0 30px 22px;
        }

        .stat-card {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-width: 0;
            padding: 18px 18px 16px;
            border: 1px solid var(--border);
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(255, 255, 255, 0.96));
            box-shadow: 0 10px 26px rgba(12, 39, 27, 0.06);
            transition:
                transform 200ms ease,
                box-shadow 200ms ease,
                border-color 200ms ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(115, 188, 37, 0.18);
            box-shadow: 0 14px 30px rgba(12, 39, 27, 0.09);
        }

        .stat-card-icon {
            display: inline-grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 16px;
            flex-shrink: 0;
        }

        .stat-card-value {
            margin: 0;
            color: var(--text-main);
            font-size: clamp(28px, 3vw, 32px);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .stat-card-label {
            margin: 4px 0 0;
            color: var(--text-soft);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.35;
        }

        .stat-card-meta {
            margin: 0;
            color: var(--text-soft);
            font-size: 12px;
            line-height: 1.35;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--chip-bg);
            color: var(--text-main);
            font-size: 13px;
            font-weight: 600;
        }

        .toolbar {
            display: grid;
            grid-template-columns: minmax(300px, 1.65fr) repeat(2, minmax(190px, 0.95fr)) 56px;
            align-items: center;
            gap: 10px;
            padding: 16px 30px 18px;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 255, 255, 0.96));
        }

        .search-box,
        .filter-select,
        .filter-reset {
            height: 50px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff, rgba(255, 255, 255, 0.98));
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.85);
            transition:
                border-color 0.18s ease,
                box-shadow 0.18s ease,
                background 0.18s ease,
                transform 0.18s ease;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 16px;
            min-width: 0;
        }

        .search-box i {
            color: var(--brand);
            font-size: 14px;
        }

        .search-box input {
            width: 100%;
            border: 0;
            outline: none;
            background: transparent;
            color: var(--text-main);
            font-size: 14px;
            font-weight: 600;
            min-width: 0;
        }

        .filter-select {
            width: 100%;
            padding: 0 16px;
            color: var(--text-main);
            outline: none;
            font-size: 14px;
            font-weight: 600;
            min-width: 0;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        .filter-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            padding: 0;
            color: var(--brand-accent);
            cursor: pointer;
        }

        .search-box:hover,
        .filter-select:hover,
        .filter-reset:hover {
            border-color: rgba(115, 188, 37, 0.22);
            background: linear-gradient(180deg, #ffffff, rgba(255, 255, 255, 0.98));
            box-shadow: 0 8px 20px rgba(12, 39, 27, 0.06);
            transform: translateY(-1px);
        }

        .search-box:focus-within,
        .filter-select:focus,
        .filter-reset:focus-visible {
            outline: none;
            border-color: rgba(115, 188, 37, 0.34);
            box-shadow:
                0 0 0 4px rgba(115, 188, 37, 0.12),
                0 8px 20px rgba(12, 39, 27, 0.06);
        }

        .filter-reset i {
            font-size: 14px;
        }

        .table-area {
            padding: 14px 18px 18px;
        }

        .table-shell {
            border: 1px solid var(--border);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 14px 34px rgba(12, 39, 27, 0.06);
            overflow: hidden;
        }

        .table-shell > table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-shell > table thead th {
            padding: 16px 16px 14px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(238, 247, 226, 0.96));
            color: var(--brand);
            font-size: 11px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: 0.08em;
            vertical-align: middle;
            box-shadow: inset 0 -1px 0 rgba(206, 210, 213, 0.72);
        }

        .table-shell > table tbody td {
            padding: 16px 16px;
            line-height: 1.42;
            vertical-align: middle;
            border-bottom: 1px solid rgba(206, 210, 213, 0.68);
        }

        .table-shell > table tbody tr {
            transition: background 200ms ease, box-shadow 200ms ease;
        }

        .table-shell > table tbody tr:nth-child(even) td {
            background: rgba(248, 250, 248, 0.88);
        }

        .table-shell > table tbody tr:hover td {
            background: rgba(238, 247, 226, 0.94);
        }

        .table-shell > table tbody tr:focus-within td {
            background: rgba(238, 247, 226, 0.96);
        }

        .table-shell > table tbody tr:last-child td {
            border-bottom: none;
        }

        .table-shell > table th:first-child,
        .table-shell > table td:first-child {
            padding-left: 18px;
        }

        .table-shell > table th:last-child,
        .table-shell > table td:last-child {
            padding-right: 18px;
            text-align: center;
        }

        .table-shell > table th:nth-child(3),
        .table-shell > table th:nth-child(4),
        .table-shell > table th:last-child,
        .table-shell > table td:nth-child(3),
        .table-shell > table td:nth-child(4),
        .table-shell > table td:last-child {
            text-align: center;
        }

        .table-shell > table td:nth-child(3) .role-badge,
        .table-shell > table td:nth-child(4) .status-badge,
        .table-shell > table td:last-child .action-links {
            margin-inline: auto;
        }

        .table-shell .role-badge,
        .table-shell .status-badge {
            min-height: 30px;
            padding: 0.45rem 0.8rem;
            font-size: 12px;
            letter-spacing: 0.02em;
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
            flex-shrink: 0;
            border: 1px solid rgba(115, 188, 37, 0.14);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }

        .user-name {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }

        .user-email {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--text-soft);
            line-height: 1.3;
        }

        .company-cell {
            min-width: 0;
            color: var(--text-soft);
            font-size: 14px;
            line-height: 1.45;
        }

        .action-links {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border);
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #ffffff, rgba(255, 255, 255, 0.98));
            color: var(--text-main);
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.85);
            transition:
                transform 200ms ease,
                border-color 200ms ease,
                background 200ms ease,
                box-shadow 200ms ease;
        }

        .action-btn:hover,
        .action-btn:focus-visible {
            transform: translateY(-1px);
            border-color: rgba(115, 188, 37, 0.2);
            background: linear-gradient(180deg, #ffffff, rgba(255, 255, 255, 0.98));
            box-shadow: 0 8px 18px rgba(12, 39, 27, 0.08);
            outline: none;
        }

        .action-btn.delete {
            color: var(--danger);
            background: var(--danger-soft);
            border-color: rgba(255, 107, 0, 0.18);
        }

        .action-btn.delete:hover,
        .action-btn.delete:focus-visible {
            border-color: rgba(255, 107, 0, 0.28);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 107, 0, 0.06));
        }

        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 16px 18px 8px;
            color: var(--text-soft);
            font-size: 13px;
        }

        .pagination {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .page-btn,
        .page-indicator {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid var(--border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }

        .page-btn {
            cursor: pointer;
            color: var(--text-main);
        }

        .page-btn:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        .page-indicator {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
            font-weight: 800;
        }

        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(35, 37, 35, 0.45);
            backdrop-filter: blur(4px);
            z-index: 9999;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            width: min(100%, 360px);
            padding: 28px;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(12, 39, 27, 0.18);
        }

        .modal-content h3 {
            margin: 0 0 10px;
            font-size: 22px;
        }

        .modal-content p {
            margin: 0;
            color: var(--text-soft);
            line-height: 1.5;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        @media (max-width: 980px) {
            .panel-header {
                grid-template-columns: 1fr;
            }

            .primary-action {
                width: 100%;
                justify-content: center;
            }

            .toolbar {
                grid-template-columns: 1fr 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            body {
                padding: 20px 12px 28px;
            }

            .panel-header,
            .header-meta,
            .toolbar,
            .table-area {
                padding-left: 16px;
                padding-right: 16px;
            }

            .toolbar {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-reset {
                width: 100%;
            }

            .table-footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
    <link rel="stylesheet" href="styles/tables.css">
    <style>
        .table-shell .role-badge.role-admin {
            --status-border-color: rgba(115, 188, 37, 0.22);
            --status-bg: linear-gradient(180deg, rgba(115, 188, 37, 0.12), rgba(255, 255, 255, 0.98));
            --status-color: var(--green-800);
        }

        .table-shell .role-badge.role-tech {
            --status-border-color: rgba(163, 211, 0, 0.22);
            --status-bg: linear-gradient(180deg, rgba(163, 211, 0, 0.14), rgba(255, 255, 255, 0.98));
            --status-color: var(--teal-700);
        }

        .table-shell .role-badge.role-analyst {
            --status-border-color: var(--border);
            --status-bg: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 248, 248, 0.98));
            --status-color: var(--text-soft);
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <section class="hero-admin">
            <section class="panel">
                <div class="panel-header">
                    <div class="hero-copy">
                        <a href="<?= lab_users_e(lab_user_module_back_url()) ?>" class="back-link">
                            <i class="fa-solid fa-arrow-left"></i>
                            <span>Volver al panel</span>
                        </a>
                        <span class="eyebrow">
                            <i class="fa-solid fa-flask-vial"></i>
                            <span>Gestion de usuarios del modulo</span>
                        </span>
                        <h1>Usuarios del Laboratorio</h1>
                        <p>Gestion de accesos y roles institucionales del modulo <?= lab_users_e($module['nombre']) ?>.</p>
                    </div>

                    <a href="usuarios_crear.php" class="primary-action">
                        <i class="fa-solid fa-plus"></i>
                        <span>Crear Usuario</span>
                    </a>
                </div>

                <div class="stats-grid" aria-label="Resumen estadístico de usuarios">
                    <article class="stat-card">
                        <span class="stat-card-icon"><i class="fa-solid fa-users"></i></span>
                        <div>
                            <p class="stat-card-value"><?= count($usuarios) ?></p>
                            <p class="stat-card-label">Usuarios del módulo</p>
                            <p class="stat-card-meta">Usuarios registrados</p>
                        </div>
                    </article>

                    <article class="stat-card">
                        <span class="stat-card-icon"><i class="fa-solid fa-circle-check"></i></span>
                        <div>
                            <p class="stat-card-value"><?= (int) $usuariosActivos ?></p>
                            <p class="stat-card-label">Estado activo</p>
                            <p class="stat-card-meta">Usuarios activos</p>
                        </div>
                    </article>

                    <article class="stat-card">
                        <span class="stat-card-icon"><i class="fa-solid fa-building"></i></span>
                        <div>
                            <p class="stat-card-value"><?= count($ingenios) ?></p>
                            <p class="stat-card-label">Empresas registradas</p>
                            <p class="stat-card-meta">Empresas / ingenios</p>
                        </div>
                    </article>

                    <article class="stat-card">
                        <span class="stat-card-icon"><i class="fa-solid fa-shield-halved"></i></span>
                        <div>
                            <p class="stat-card-value"><?= count($roles) ?></p>
                            <p class="stat-card-label">Roles disponibles</p>
                            <p class="stat-card-meta">Roles distintos</p>
                        </div>
                    </article>
                </div>

                <div class="header-meta">


                </div>
            </section>
        </section>

            <?php if (empty($usuarios)): ?>
                <div class="table-area">
                    <div class="empty-state">
                        No hay usuarios asignados al modulo Laboratorio todavia.
                    </div>
                </div>
            <?php else: ?>
                <div class="toolbar">
                    <label class="search-box" for="searchUsers">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" id="searchUsers" placeholder="Buscar por nombre o correo...">
                    </label>

                    <select id="filterIngenio" class="filter-select" aria-label="Filtrar por ingenio">
                        <option value="">Todos los Ingenios</option>
                        <?php foreach ($ingenios as $ingenio): ?>
                            <option value="<?= lab_users_e($ingenio) ?>"><?= lab_users_e($ingenio) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterRol" class="filter-select" aria-label="Filtrar por rol">
                        <option value="">Todos los Roles</option>
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= lab_users_e($rol) ?>"><?= lab_users_e($rol) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" id="resetFilters" class="filter-reset" aria-label="Limpiar filtros">
                        <i class="fa-solid fa-filter-circle-xmark"></i>
                    </button>
                </div>

                <div class="table-area">
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Ingenio / Empresa</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <?php foreach ($usuarios as $usuario): ?>
                                    <?php
                                    $name = (string) $usuario['nombre'];
                                    $email = (string) $usuario['correo'];
                                    $ingenio = (string) $usuario['ingenio'];
                                    $rol = (string) $usuario['nombre_rol'];
                                    $estado = (int) ($usuario['estado'] ?? 1);
                                    $estadoLabel = $estado === 1 ? 'Activo' : 'Inactivo';
                                    $estadoClass = $estado === 1 ? 'status-active' : 'status-inactive';
                                    ?>
                                    <tr
                                        data-search="<?= lab_users_e(strtolower($name . ' ' . $email)) ?>"
                                        data-ingenio="<?= lab_users_e($ingenio) ?>"
                                        data-rol="<?= lab_users_e($rol) ?>"
                                        data-estado="<?= $estado ?>"
                                    >
                                        <td>
                                            <div class="user-cell">
                                                <span class="user-avatar" style="<?= lab_users_e(lab_users_avatar_style($name)) ?>">
                                                    <?= lab_users_e(lab_users_initials($name)) ?>
                                                </span>
                                                <div>
                                                    <p class="user-name"><?= lab_users_e($name) ?></p>
                                                    <p class="user-email"><?= lab_users_e($email) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="company-cell"><?= lab_users_e($ingenio) ?></td>
                                        <td>
                                            <span class="role-badge <?= lab_users_e(lab_users_role_class($rol)) ?>">
                                                <?= lab_users_e($rol) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= lab_users_e($estadoClass) ?>">
                                                <?= lab_users_e($estadoLabel) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-links">
                                                <a href="usuarios_editar.php?id=<?= (int) $usuario['id'] ?>" class="action-btn" aria-label="Editar usuario">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a href="#" class="action-btn delete btn-delete" data-id="<?= (int) $usuario['id'] ?>" aria-label="Eliminar usuario">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <span id="resultsSummary">Mostrando <?= count($usuarios) ?> de <?= count($usuarios) ?> resultados</span>

                        <div class="pagination">
                            <button type="button" class="page-btn" id="prevPage" aria-label="Pagina anterior">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                            <span class="page-indicator" id="pageIndicator">1</span>
                            <button type="button" class="page-btn" id="nextPage" aria-label="Pagina siguiente">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Eliminar usuario</h3>
            <p>Esta accion eliminara el usuario del modulo de Laboratorio. Asegurese antes de continuar.</p>

            <div class="modal-buttons">
                <button type="button" id="cancelBtn" class="btn-secondary">Cancelar</button>
                <a id="confirmDelete" href="#" class="btn-delete">Eliminar</a>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const modal = document.getElementById('deleteModal');
        const confirmBtn = document.getElementById('confirmDelete');
        const cancelBtn = document.getElementById('cancelBtn');
        const deleteButtons = document.querySelectorAll('.btn-delete');
        const searchInput = document.getElementById('searchUsers');
        const ingenioFilter = document.getElementById('filterIngenio');
        const rolFilter = document.getElementById('filterRol');
        const resetFilters = document.getElementById('resetFilters');
        const tableBody = document.getElementById('usersTableBody');
        const rows = tableBody ? Array.from(tableBody.querySelectorAll('tr')) : [];
        const resultsSummary = document.getElementById('resultsSummary');
        const tableEmpty = document.getElementById('tableEmpty');
        const prevPage = document.getElementById('prevPage');
        const nextPage = document.getElementById('nextPage');
        const pageIndicator = document.getElementById('pageIndicator');
        const pageSize = 5;
        let currentPage = 1;

        deleteButtons.forEach((button) => {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                confirmBtn.href = 'usuarios_eliminar.php?id=' + this.dataset.id;
                modal.classList.add('active');
            });
        });

        cancelBtn.addEventListener('click', function () {
            modal.classList.remove('active');
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.classList.remove('active');
            }
        });

        function normalized(value) {
            return (value || '').toString().trim().toLowerCase();
        }

        function getFilteredRows() {
            const search = normalized(searchInput ? searchInput.value : '');
            const ingenio = normalized(ingenioFilter ? ingenioFilter.value : '');
            const rol = normalized(rolFilter ? rolFilter.value : '');

            return rows.filter((row) => {
                const matchesSearch = search === '' || normalized(row.dataset.search).includes(search);
                const matchesIngenio = ingenio === '' || normalized(row.dataset.ingenio) === ingenio;
                const matchesRol = rol === '' || normalized(row.dataset.rol) === rol;
                return matchesSearch && matchesIngenio && matchesRol;
            });
        }

        function renderTable() {
            const filteredRows = getFilteredRows();
            const totalFiltered = filteredRows.length;
            const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));

            currentPage = Math.min(currentPage, totalPages);
            currentPage = Math.max(currentPage, 1);

            rows.forEach((row) => {
                row.hidden = true;
            });

            const start = (currentPage - 1) * pageSize;
            const end = start + pageSize;
            const visibleRows = filteredRows.slice(start, end);

            visibleRows.forEach((row) => {
                row.hidden = false;
            });

            if (resultsSummary) {
                resultsSummary.textContent = 'Mostrando ' + visibleRows.length + ' de ' + totalFiltered + ' resultados';
            }

            if (tableEmpty) {
                tableEmpty.hidden = totalFiltered !== 0;
            }

            if (prevPage) {
                prevPage.disabled = currentPage <= 1 || totalFiltered === 0;
            }

            if (nextPage) {
                nextPage.disabled = currentPage >= totalPages || totalFiltered === 0;
            }

            if (pageIndicator) {
                pageIndicator.textContent = totalFiltered === 0 ? '0' : String(currentPage);
            }
        }

        function resetAndRender() {
            currentPage = 1;
            renderTable();
        }

        if (searchInput) {
            searchInput.addEventListener('input', resetAndRender);
        }

        if (ingenioFilter) {
            ingenioFilter.addEventListener('change', resetAndRender);
        }

        if (rolFilter) {
            rolFilter.addEventListener('change', resetAndRender);
        }

        if (resetFilters) {
            resetFilters.addEventListener('click', function () {
                if (searchInput) {
                    searchInput.value = '';
                }

                if (ingenioFilter) {
                    ingenioFilter.value = '';
                }

                if (rolFilter) {
                    rolFilter.value = '';
                }

                resetAndRender();
            });
        }

        if (prevPage) {
            prevPage.addEventListener('click', function () {
                currentPage -= 1;
                renderTable();
            });
        }

        if (nextPage) {
            nextPage.addEventListener('click', function () {
                currentPage += 1;
                renderTable();
            });
        }

        renderTable();
    })();
    </script>
</body>
</html>
