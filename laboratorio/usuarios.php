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
        ['#d6f5df', '#0c6b43'],
        ['#d8e9ff', '#1f4f91'],
        ['#fce4d6', '#9a4b13'],
        ['#e8dcff', '#5f2e99'],
        ['#ffe7b8', '#935e00'],
        ['#d7f0ef', '#0f6b66'],
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

foreach ($usuarios as $usuario) {
    $ingenio = trim((string) ($usuario['ingenio'] ?? ''));
    $rol = trim((string) ($usuario['nombre_rol'] ?? ''));

    if ($ingenio !== '') {
        $ingenios[$ingenio] = $ingenio;
    }

    if ($rol !== '') {
        $roles[$rol] = $rol;
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
            --page-bg: #f3f7f2;
            --surface: #ffffff;
            --surface-soft: #f8fbf7;
            --border: #d9e4d8;
            --border-strong: #c8d7c8;
            --text-main: #123126;
            --text-soft: #597265;
            --brand: #053b2a;
            --brand-soft: #e0f1e7;
            --brand-accent: #0d6b47;
            --chip-bg: #edf4ed;
            --shadow: 0 16px 40px rgba(12, 39, 27, 0.08);
            --danger: #b63d2d;
            --danger-soft: #fff1ef;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 32px 18px 40px;
            background:
                radial-gradient(circle at top right, rgba(120, 185, 122, 0.15), transparent 30%),
                linear-gradient(180deg, #f9fcf8 0%, var(--page-bg) 100%);
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

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.76);
            color: var(--text-main);
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(12, 39, 27, 0.05);
        }

        .panel {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(201, 217, 201, 0.72);
            border-radius: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            padding: 28px 28px 18px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand-accent);
            font-size: 12px;
            font-weight: 700;
        }

        .panel-header h1 {
            margin: 0;
            font-size: clamp(28px, 4vw, 36px);
            line-height: 1.1;
        }

        .panel-header p {
            margin: 10px 0 0;
            color: var(--text-soft);
            font-size: 15px;
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
            padding: 0 28px 22px;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #f2f8f1;
            color: var(--text-main);
            font-size: 13px;
            font-weight: 600;
        }

        .toolbar {
            display: grid;
            grid-template-columns: minmax(260px, 1.5fr) repeat(3, minmax(0, 0.7fr));
            gap: 12px;
            padding: 18px 28px 20px;
            border-top: 1px solid rgba(217, 228, 216, 0.72);
            border-bottom: 1px solid rgba(217, 228, 216, 0.72);
            background: var(--surface-soft);
        }

        .search-box,
        .filter-select,
        .filter-reset {
            height: 48px;
            border: 1px solid var(--border-strong);
            border-radius: 14px;
            background: #fff;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 14px;
        }

        .search-box i {
            color: var(--text-soft);
        }

        .search-box input {
            width: 100%;
            border: 0;
            outline: none;
            background: transparent;
            color: var(--text-main);
        }

        .filter-select {
            width: 100%;
            padding: 0 14px;
            color: var(--text-main);
            outline: none;
        }

        .filter-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            cursor: pointer;
        }

        .table-area {
            padding: 12px 18px 18px;
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 250px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.04em;
            flex-shrink: 0;
        }

        .user-name {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
        }

        .user-email {
            margin: 4px 0 0;
            font-size: 12px;
            color: #2f66a7;
        }

        .company-cell {
            min-width: 210px;
            color: var(--text-soft);
            font-size: 14px;
        }

        .action-links {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            border: 1px solid var(--border);
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: var(--text-main);
            transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            border-color: #a8c4b0;
            background: #f8fbf8;
        }

        .action-btn.delete {
            color: var(--danger);
            background: var(--danger-soft);
            border-color: #f0c5bf;
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
            background: rgba(10, 22, 16, 0.45);
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
                flex-direction: column;
            }

            .primary-action {
                width: 100%;
                justify-content: center;
            }

            .toolbar {
                grid-template-columns: 1fr 1fr;
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

            .table-footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
    <link rel="stylesheet" href="styles/tables.css">
</head>
<body>
    <div class="page-shell">
        <a href="<?= lab_users_e(lab_user_module_back_url()) ?>" class="back-link">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Volver al panel</span>
        </a>

        <section class="panel">
            <div class="panel-header">
                <div>
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

            <div class="header-meta">
                <span class="meta-pill">
                    <i class="fa-solid fa-users"></i>
                    <span><?= count($usuarios) ?> usuarios registrados</span>
                </span>
                <span class="meta-pill">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Solo usuarios asignados a Laboratorio</span>
                </span>
            </div>

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

                    <div class="table-empty" id="tableEmpty" hidden>
                        No se encontraron usuarios con los filtros aplicados.
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
        </section>
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
