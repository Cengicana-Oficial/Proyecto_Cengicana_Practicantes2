<?php
require_once __DIR__ . '/../../login/config/visitas_access.php';
/** Presentación compartida; los permisos siguen resolviéndose en cada pantalla. */
function visitas_ui_escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function visitas_ui_head(): void { ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="/visitas/assets/img/logo-cengivisitas.svg">
<link rel="stylesheet" href="/visitas/assets/module-theme.css?v=20261109-3">
<script src="/visitas/assets/module-shell.js?v=20261008-1" defer></script>
<?php }
function visitas_ui_shell(string $title, string $subtitle, string $active = 'solicitudes', bool $dynamic = false): void {
    $role = visitas_role_code((string) ($_SESSION['visitas_role'] ?? $GLOBALS['actor']['role_code'] ?? 'asistente_direccion'));
    $permissions = visitas_permissions($role);
    $name = (string) ($_SESSION['nombre'] ?? $GLOBALS['actor']['nombre'] ?? 'Usuario');
    $roleLabel = cengisalas_roles()['visitas'][$role] ?? 'Visitas';
    $canRequests = in_array('ver_solicitudes', $permissions, true);
    $canAreas = in_array('gestionar_areas', $permissions, true);
    // "Áreas y contactos" reúne el catálogo de áreas y las personas que reciben
    // los avisos de cada área (antes eran dos secciones: Contactos y Áreas).
    $links = $canRequests ? [
        ['Solicitudes', 'solicitudes', 'Solicitudes', 'list'],
        ['Reportes', 'reportes', 'Reportes', 'chart'],
    ] : [['Reportes', 'reportes', 'Reportes', 'chart']];
    if ($canRequests || $canAreas) $links[] = ['Areas', 'areas', 'Áreas y contactos', 'pin'];
    if ($canRequests) $links[] = ['BaseDatos', 'basedatos', 'Directorio de solicitantes', 'database'];
    if ($dynamic && in_array('gestionar_roles_visitas', $permissions, true)) $links[] = ['Roles', 'roles', 'Roles y permisos', 'shield'];
    ?>
<a class="visits-skip" href="#visits-content">Saltar al contenido</a>
<aside class="visits-sidebar" id="visits-sidebar" aria-label="Navegación de Visitas">
    <a class="visits-shell-brand cv-brand" href="/visitas/public/admin/dashboard_unificado.php?modulo=solicitudes" aria-label="Inicio de CengiVisitas">
        <img class="cv-logo" src="/visitas/assets/img/logo-cengivisitas.svg" alt="" aria-hidden="true">
        <span class="cv-brand-copy"><strong>Cengi<b>Visitas</b></strong><small>Gestión institucional</small></span>
    </a>
    <nav class="visits-shell-nav" aria-label="Secciones del módulo">
        <span class="visits-nav-label">Gestión de visitas</span>
        <?php foreach ($links as [$id, $key, $label, $icon]): ?>
            <?php if ($dynamic): ?>
            <button class="visits-nav-link <?= $active === $key ? 'active' : '' ?>" id="nav<?= $id ?>" type="button" data-section="<?= $key ?>"><?= visitas_ui_icon($icon) ?><span><?= $label ?></span></button>
            <?php else: ?>
            <a class="visits-nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= $key === 'reportes' ? '/visitas/public/admin/dashboard_reportes.php' : '/visitas/public/admin/dashboard_unificado.php?modulo=solicitudes&amp;vista=' . $key ?>"><?= visitas_ui_icon($icon) ?><span><?= $label ?></span></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (in_array('ver_pagos', $permissions, true)): ?><a class="visits-nav-link <?= $active === 'pagos' ? 'active' : '' ?>" href="/visitas/public/admin/dashboard_pago.php"><?= visitas_ui_icon('wallet') ?><span>Pagos</span></a><?php endif; ?>
        <?php if (!$dynamic && in_array('gestionar_roles_visitas', $permissions, true)): ?><a class="visits-nav-link <?= $active === 'roles' ? 'active' : '' ?>" href="/login/visitas_roles.php"><?= visitas_ui_icon('shield') ?><span>Roles y permisos</span></a><?php endif; ?>
    </nav>
    <div class="visits-sidebar-footer">
        <a class="visits-nav-link" href="/login/Menu.php"><?= visitas_ui_icon('grid') ?><span>Menú de módulos</span></a>
        <a class="visits-nav-link visits-logout" href="/visitas/public/admin/logout.php"><?= visitas_ui_icon('logout') ?><span>Cerrar sesión</span></a>
        <small>CengiVisitas · Solicitudes de visitas · Cengicaña</small>
    </div>
</aside>
<button class="visits-nav-backdrop" id="visits-nav-backdrop" type="button" aria-label="Cerrar navegación" hidden></button>
<header class="visits-topbar">
    <button class="visits-menu-toggle" id="visits-menu-toggle" type="button" aria-label="Abrir navegación" aria-controls="visits-sidebar" aria-expanded="false"><?= visitas_ui_icon('menu') ?></button>
    <div class="visits-topbar-titles"><strong id="visits-page-title"><?= visitas_ui_escape($title) ?></strong><span><?= visitas_ui_escape($subtitle) ?></span></div>
    <span class="visits-module-pill">CengiVisitas</span>
    <div class="visits-user"><span class="visits-avatar"><?= visitas_ui_escape(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><div><strong><?= visitas_ui_escape($name) ?></strong><small><?= visitas_ui_escape($roleLabel) ?></small></div></div>
</header>
<?php }
function visitas_ui_icon(string $name): string {
    $paths = [
        'list' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'chart' => '<path d="M4 3v17h17M9 16v-5M14 16V7M19 16V4"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-3-5"/>',
        'pin' => '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
        'wallet' => '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M16 13h5v4h-5z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'logout' => '<path d="M9 4H4v16h5M13 8l4 4-4 4M8 12h13"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];
    return '<svg class="visits-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['list']) . '</svg>';
}
