<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-base" content="{{ url('/') }}">
    <title>@yield('page_title', $title ?? 'Experimentos') · CengiInvestiga</title>

    <link rel="icon" type="image/svg+xml" href="{{ asset('img/logo-cengiinvestiga.svg') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/dist/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="{{ asset(mix('dist/css/app.css')) }}">
    {{-- Identidad CengiInvestiga: CSS plano, fuera de Laravel Mix --}}
    <link rel="stylesheet" href="{{ asset('css/cengiinvestiga.css') }}?v={{ @filemtime(public_path('css/cengiinvestiga.css')) }}">
    <style>
        /* Campanita de notificaciones del encabezado */
        .cengi-notif { position: relative; }
        .cengi-notif-btn { position: relative; width: 38px; height: 38px; border: 1px solid #e2e4e6; border-radius: 10px; background: #fff; color: #5f666c; cursor: pointer; }
        .cengi-notif-btn:hover, .cengi-notif-btn[aria-expanded="true"] { border-color: #73bc25; color: #3d6614; background: #f1f8e9; }
        .cengi-notif-badge { position: absolute; top: -6px; right: -6px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: #ff6b00; color: #fff; font-size: 10.5px; font-weight: 700; line-height: 18px; text-align: center; }
        .cengi-notif-panel { position: absolute; right: 0; top: 46px; z-index: 1100; width: 380px; max-width: calc(100vw - 24px); background: #fff; border: 1px solid #e2e4e6; border-radius: 14px; box-shadow: 0 16px 40px rgba(42,46,49,.18); overflow: hidden; }
        .cengi-notif-head { display: flex; justify-content: space-between; align-items: baseline; padding: 12px 14px; border-bottom: 1px solid #f0f1f2; }
        .cengi-notif-head small { color: #8b9298; }
        .cengi-notif-list { max-height: 420px; overflow-y: auto; }
        .cengi-notif-item { display: grid; grid-template-columns: 30px 1fr; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #f6f7f7; color: #3d4247; text-decoration: none !important; }
        .cengi-notif-item:hover { background: #f8faf5; color: #2a2e31; }
        .cengi-notif-item.is-new { background: #fbfdf7; }
        .cengi-notif-item.is-new .cengi-notif-tit::after { content: ''; display: inline-block; width: 7px; height: 7px; margin-left: 6px; border-radius: 50%; background: #ff6b00; vertical-align: middle; }
        .cengi-notif-ico { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 8px; background: #eef6fb; color: #1f6fbf; font-size: 13px; }
        .cengi-notif-item.tono-aviso .cengi-notif-ico { background: #fff8db; color: #7a6100; }
        .cengi-notif-item.tono-alerta .cengi-notif-ico { background: #fff0e6; color: #b34b00; }
        .cengi-notif-item.tono-bien .cengi-notif-ico { background: #e3f2d3; color: #3d6614; }
        .cengi-notif-tit { display: block; font-size: 12.5px; font-weight: 700; }
        .cengi-notif-txt { display: block; font-size: 12px; color: #5f666c; line-height: 1.4; }
        .cengi-notif-empty { margin: 0; padding: 28px 14px; text-align: center; color: #8b9298; font-size: 13px; }
        @media (max-width: 600px) { .cengi-notif-panel { position: fixed; left: 12px; right: 12px; top: 64px; width: auto; } }
    </style>
</head>
@php
    $user = auth()->user();
    $roleName = $user && $user->roles->isNotEmpty() ? $user->roles->first()->name : 'usuario';
    // Nombre del rol como se llama en el portal (config/sso.php roles_portal).
    $roleLabel = $roleName === 'administrador' ? 'Administrador'
        : (collect(config('sso.roles_portal'))->search(fn ($r) => $r['rol'] === $roleName) ?: ucfirst(str_replace('_', ' ', $roleName)));
    $nameParts = preg_split('/\s+/', trim($user->name ?? 'Usuario'), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach (array_slice($nameParts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
    }
    $initials = $initials ?: 'U';

    $pageTitle = trim($__env->yieldContent('page_title', $title ?? 'Experimentos'));
    $pageSubtitles = [
        'Dashboard' => 'Resumen general de investigacion y ensayos',
        'Programas y proyectos' => 'Areas tematicas, sus proyectos y el avance de sus ensayos',
        'Ensayos experimentales' => 'Detalle de ensayos en campo, tratamientos y parcelas',
        'Variables' => 'Catalogo de variables de evaluacion',
        'Evaluaciones y Análisis' => 'Evaluaciones de campo y laboratorio y análisis estadístico por ensayo y proyecto',
        'Clima · Red de estaciones' => 'Catalogo de estaciones meteorologicas, ubicaciones y datos diarios',

        'Muestras' => 'Generacion de etiquetas y seguimiento con el modulo Laboratorio',
        'Imagenes Geoespaciales' => 'Archivos y sensores asociados a ensayos',
        'Archivos' => 'Documentacion asociada a proyectos y ensayos',
        'Bitacora' => 'Historial de actividades de investigacion',
        'Formularios de campo' => 'Captura y asignacion de formularios',
        'Reportes' => 'Exportaciones e informes técnicos de los ensayos de Experimentos',
        'Importación de datos' => 'Plantillas y carga de datos por ensayo de Experimentos',
        'Usuarios y permisos' => 'Administracion de accesos a Experimentos',
        'Catalogos' => 'Disenos experimentales, ingenios y responsables',
    ];
    $pageSubtitle = $pageSubtitles[$pageTitle] ?? 'Sistema de Gestion de Ensayos Experimentales';
@endphp
<body class="cengi-canvas sigec-shell">
    <aside class="cengi-sidebar" id="cengiSigecSidebar">
        <a class="cengi-brand" href="{{ route('dashboard') }}" aria-label="Ir al dashboard de CengiInvestiga">
            <img class="cgi-logo" src="{{ asset('img/logo-cengiinvestiga.svg') }}" alt="" aria-hidden="true">
            <div class="cengi-brand-copy">
                <strong>Cengi<b>Investiga</b></strong>
                <small>Investigación y campo</small>
            </div>
        </a>

        <nav class="cengi-sidebar-nav" id="cengiSigecNav" aria-label="Navegacion principal de Experimentos">
            <div class="cengi-nav-group">
                <div class="cengi-nav-label">Principal</div>
                <a href="{{ route('dashboard') }}" class="cengi-nav-item{{ request()->routeIs('dashboard') ? ' is-active' : '' }}">
                    <i class="fas fa-chart-pie"></i><span>Dashboard</span>
                </a>
            </div>

            @can('menu_general')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Investigacion</div>
                    <a href="{{ route('investigacion.proyectos.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.proyectos.*', 'investigacion.programas.*') ? ' is-active' : '' }}">
                        <i class="fas fa-layer-group"></i><span>Programas y proyectos</span>
                    </a>
                    <a href="{{ route('investigacion.ensayos.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.ensayos.*') ? ' is-active' : '' }}">
                        @include('components.icono-cana', ['marca' => false])<span>Ensayos</span>
                    </a>
                    <a href="{{ route('investigacion.clima.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.clima.index') ? ' is-active' : '' }}">
                        <i class="fas fa-cloud-sun-rain"></i><span>Clima y estaciones</span>
                    </a>
                    <a href="{{ route('investigacion.variables.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.variables.*') ? ' is-active' : '' }}">
                        <i class="fas fa-chart-line"></i><span>Variables</span>
                    </a>
                    <a href="{{ route('investigacion.evaluaciones.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.evaluaciones.*') ? ' is-active' : '' }}">
                        <i class="fas fa-clipboard-check"></i><span>Evaluaciones y Análisis</span>
                    </a>
                </div>
            @endcan

            @can('menu_general')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Soporte</div>
                    {{-- El trabajo de laboratorio se hace en el modulo Laboratorio del portal; aqui se generan las muestras y se consultan sus resultados. --}}
                    @canany(['create_muestra', 'view_lab'])
                        {{-- Generar etiquetas + Seguimiento en una sola pagina; los resultados se analizan en Evaluaciones (categoria Laboratorio). --}}
                        <a href="{{ route('soporte.muestras.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.muestras.*', 'soporte.muestras-gen.*', 'soporte.muestras-consulta.*', 'soporte.laboratorio.*') ? ' is-active' : '' }}">
                            <i class="fas fa-flask"></i><span>Muestras</span>
                        </a>
                    @endcanany
                    <a href="{{ route('soporte.imagenes-geo.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.imagenes-geo.*') ? ' is-active' : '' }}">
                        <i class="fas fa-satellite"></i><span>Imagenes geoespaciales</span>
                    </a>
                    <a href="{{ route('soporte.archivos.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.archivos.*') ? ' is-active' : '' }}">
                        <i class="fas fa-paperclip"></i><span>Archivos</span>
                    </a>
                    @canany(['manage_formularios', 'ver_formulario_asignado'])
                        <a href="{{ route('soporte.formularios.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.formularios.*') ? ' is-active' : '' }}">
                            <i class="fab fa-wpforms"></i><span>Formularios de campo</span>
                        </a>
                    @endcanany
                    @can('view_reports_menu')
                        <a href="{{ route('soporte.reportes.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.reportes.*') ? ' is-active' : '' }}">
                            <i class="fas fa-file-alt"></i><span>Reportes</span>
                        </a>
                    @endcan
                </div>
            @endcan

            @can('run_analysis')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Datos y analisis</div>
                    <a href="{{ route('analisis.index') }}" class="cengi-nav-item{{ request()->routeIs('analisis.*') ? ' is-active' : '' }}">
                        <i class="fas fa-file-import"></i><span>Importación de datos</span>
                    </a>
                </div>
            @endcan

            @can('admin_only')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Sistema</div>
                    <a href="{{ route('admin.usuarios.index') }}" class="cengi-nav-item{{ request()->routeIs('admin.usuarios.*') ? ' is-active' : '' }}">
                        <i class="fas fa-users-cog"></i><span>Usuarios y permisos</span>
                    </a>
                    <a href="{{ route('admin.catalogos.index') }}" class="cengi-nav-item{{ request()->routeIs('admin.catalogos.*') ? ' is-active' : '' }}">
                        <i class="fas fa-list-alt"></i><span>Catalogos</span>
                    </a>
                </div>
            @endcan
        </nav>

        <div class="cengi-sidebar-foot">
            <a href="/login/Menu.php" class="cengi-nav-item cengi-menu-link">
                <i class="fas fa-th-large"></i><span>Menu principal</span>
            </a>
            <form action="{{ route('logout') }}" method="post">
                @csrf
                <button type="submit" class="cengi-nav-item cengi-logout-link">
                    <i class="fas fa-sign-out-alt"></i><span>Cerrar sesion</span>
                </button>
            </form>
            @php($zafraIni = now()->month >= 11 ? now()->year : now()->year - 1)
            <div class="cengi-sidebar-version">CengiInvestiga · Zafra {{ $zafraIni }}–{{ $zafraIni + 1 }}</div>
        </div>
    </aside>

    <header class="cengi-topbar">
        <button type="button" class="cengi-menu-toggle" id="cengiSigecMenuToggle"
                aria-label="Abrir menu" aria-expanded="false" aria-controls="cengiSigecSidebar">
            <span></span><span></span><span></span>
        </button>
        <div class="cengi-topbar-titles">
            <div class="cengi-topbar-title">{{ $pageTitle }}</div>
            <div class="cengi-topbar-sub">{{ $pageSubtitle }}</div>
        </div>
        <div class="cengi-topbar-right">
            <div class="cengi-notif" id="cengiNotif">
                <button type="button" class="cengi-notif-btn" id="cengiNotifBtn" aria-label="Notificaciones" aria-expanded="false" aria-controls="cengiNotifPanel">
                    <i class="fas fa-bell"></i><span class="cengi-notif-badge" id="cengiNotifBadge" hidden></span>
                </button>
                <div class="cengi-notif-panel" id="cengiNotifPanel" hidden>
                    <div class="cengi-notif-head"><strong>Notificaciones</strong><small id="cengiNotifResumen"></small></div>
                    <div class="cengi-notif-list" id="cengiNotifList"><p class="cengi-notif-empty">Cargando…</p></div>
                </div>
            </div>
            <span class="cengi-pill">{{ $roleLabel }}</span>
            <div class="cengi-userbox">
                <div class="cengi-avatar">{{ $initials }}</div>
                <div>
                    <div class="cengi-userbox-u1">{{ $user->name ?? 'Usuario' }}</div>
                    <div class="cengi-userbox-u2">Experimentos</div>
                </div>
            </div>
        </div>
    </header>

    <main class="cengi-lab-content">
        <div id="app">
            @yield('app_content')
        </div>
    </main>

    <script src="{{ asset('vendor/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset(mix('dist/js/manifest.js')) }}"></script>
    <script src="{{ asset(mix('dist/js/vendor.js')) }}"></script>
    <script src="{{ asset(mix('dist/js/app.js')) }}"></script>
    <script>
        (function () {
            var sidebar = document.getElementById('cengiSigecSidebar');
            var toggle = document.getElementById('cengiSigecMenuToggle');
            var nav = document.getElementById('cengiSigecNav');

            function closeMenu() {
                if (!sidebar || !toggle) return;
                sidebar.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('cengi-menu-open');
            }

            if (toggle && sidebar) {
                toggle.addEventListener('click', function () {
                    var willOpen = !sidebar.classList.contains('is-open');
                    sidebar.classList.toggle('is-open', willOpen);
                    document.body.classList.toggle('cengi-menu-open', willOpen);
                    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
            }

            document.addEventListener('click', function (event) {
                if (sidebar && sidebar.classList.contains('is-open')
                    && !sidebar.contains(event.target)
                    && toggle && event.target !== toggle
                    && !toggle.contains(event.target)) {
                    closeMenu();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeMenu();
            });

            if (nav) {
                nav.addEventListener('click', function (event) {
                    if (event.target.closest('a')) closeMenu();
                });
            }
        })();

        // Campanita: carga los avisos al abrir la pagina y los marca vistos al abrir el panel.
        (function () {
            var btn = document.getElementById('cengiNotifBtn');
            var panel = document.getElementById('cengiNotifPanel');
            var badge = document.getElementById('cengiNotifBadge');
            var list = document.getElementById('cengiNotifList');
            var resumen = document.getElementById('cengiNotifResumen');
            if (!btn) return;
            var token = document.querySelector('meta[name="csrf-token"]').content;
            var urlIndex = @json(route('notificaciones.index'));
            var urlVistas = @json(route('notificaciones.vistas'));
            var datos = null;
            var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

            function pintar() {
                var n = datos ? datos.no_leidas : 0;
                badge.hidden = !n;
                badge.textContent = n > 9 ? '9+' : n;
                btn.setAttribute('aria-label', n ? 'Notificaciones: ' + n + ' nueva(s)' : 'Notificaciones');
                if (!datos) return;
                resumen.textContent = datos.items.length ? datos.items.length + ' aviso(s)' : '';
                list.innerHTML = datos.items.length ? datos.items.map(function (i) {
                    return '<a class="cengi-notif-item tono-' + esc(i.tono) + (i.nueva ? ' is-new' : '') + '" href="' + esc(i.url) + '">'
                        + '<span class="cengi-notif-ico"><i class="fas ' + esc(i.icono) + '"></i></span>'
                        + '<span><span class="cengi-notif-tit">' + esc(i.titulo) + '</span><span class="cengi-notif-txt">' + esc(i.texto) + '</span></span></a>';
                }).join('') : '<p class="cengi-notif-empty"><i class="fas fa-check-circle" style="color:#73bc25"></i> Todo al día: no hay pendientes.</p>';
            }

            function cargar() {
                fetch(urlIndex, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) { if (d) { datos = d; pintar(); } })
                    .catch(function () { list.innerHTML = '<p class="cengi-notif-empty">No se pudieron cargar las notificaciones.</p>'; });
            }

            function cerrar() { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); }

            btn.addEventListener('click', function (event) {
                event.stopPropagation();
                if (!panel.hidden) { cerrar(); return; }
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                if (datos && datos.no_leidas) {
                    fetch(urlVistas, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, credentials: 'same-origin' });
                    // Se quitan los puntos de "nuevo" al cerrar, para que se vean mientras el panel esta abierto.
                    datos.no_leidas = 0;
                    badge.hidden = true;
                    datos.items.forEach(function (i) { i.vista = true; });
                }
            });
            document.addEventListener('click', function (event) {
                if (!panel.hidden && !panel.contains(event.target)) {
                    cerrar();
                    if (datos) { datos.items.forEach(function (i) { if (i.vista) i.nueva = false; }); pintar(); }
                }
            });
            document.addEventListener('keydown', function (event) { if (event.key === 'Escape') cerrar(); });
            cargar();
        })();
    </script>
</body>
</html>
