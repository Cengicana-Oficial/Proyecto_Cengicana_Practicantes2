<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('page_title', $title ?? 'SIGEC') · CENGICANA</title>

    <link rel="icon" type="image/png" href="{{ asset('img/cengicana-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/dist/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="{{ asset(mix('dist/css/app.css')) }}">
</head>
@php
    $user = auth()->user();
    $roleName = $user && $user->roles->isNotEmpty() ? $user->roles->first()->name : 'usuario';
    $roleLabel = ucfirst(str_replace('_', ' ', $roleName));
    $nameParts = preg_split('/\s+/', trim($user->name ?? 'Usuario'), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach (array_slice($nameParts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
    }
    $initials = $initials ?: 'U';

    $pageTitle = trim($__env->yieldContent('page_title', $title ?? 'SIGEC'));
    $pageSubtitles = [
        'Dashboard' => 'Resumen general de investigacion y ensayos',
        'Programas' => 'Lineas y programas de investigacion',
        'Proyectos' => 'Planificacion y seguimiento de proyectos',
        'Ensayos' => 'Gestion de ensayos experimentales',
        'Variables' => 'Catalogo de variables de evaluacion',
        'Evaluaciones' => 'Registro y consulta de resultados de campo',
        'Laboratorio' => 'Recepcion y seguimiento de muestras',
        'Generacion de ID de Muestras' => 'Creacion de lotes y etiquetas de muestra',
        'Consulta de Muestras' => 'Trazabilidad del ciclo de laboratorio',
        'Imagenes Geoespaciales' => 'Archivos y sensores asociados a ensayos',
        'Graficas Temporales' => 'Comportamiento de variables y analitos',
        'Archivos' => 'Documentacion asociada a proyectos y ensayos',
        'Bitacora' => 'Historial de actividades de investigacion',
        'Formularios de campo' => 'Captura y asignacion de formularios',
        'Reportes' => 'Exportacion y resumen de resultados',
        'Importar y Analizar' => 'Carga masiva y analisis de evaluaciones',
        'Usuarios y permisos' => 'Administracion de accesos a SIGEC',
    ];
    $pageSubtitle = $pageSubtitles[$pageTitle] ?? 'Sistema de Gestion de Ensayos Experimentales';
@endphp
<body class="cengi-canvas sigec-shell">
    <aside class="cengi-sidebar" id="cengiSigecSidebar">
        <a class="cengi-brand" href="{{ route('dashboard') }}" aria-label="Ir al dashboard de SIGEC">
            <div class="cengi-brand-mark"><i class="fas fa-seedling"></i></div>
            <div class="cengi-brand-copy">
                <strong>SIGEC</strong>
                <small>Investigacion y campo</small>
            </div>
        </a>

        <nav class="cengi-sidebar-nav" id="cengiSigecNav" aria-label="Navegacion principal de SIGEC">
            <div class="cengi-nav-group">
                <div class="cengi-nav-label">Principal</div>
                <a href="{{ route('dashboard') }}" class="cengi-nav-item{{ request()->routeIs('dashboard') ? ' is-active' : '' }}">
                    <i class="fas fa-chart-pie"></i><span>Dashboard</span>
                </a>
            </div>

            @can('menu_general')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Investigacion</div>
                    <a href="{{ route('investigacion.programas.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.programas.*') ? ' is-active' : '' }}">
                        <i class="fas fa-layer-group"></i><span>Programas</span>
                    </a>
                    <a href="{{ route('investigacion.proyectos.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.proyectos.*') ? ' is-active' : '' }}">
                        <i class="fas fa-folder-open"></i><span>Proyectos</span>
                    </a>
                    <a href="{{ route('investigacion.ensayos.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.ensayos.*') ? ' is-active' : '' }}">
                        <i class="fas fa-seedling"></i><span>Ensayos</span>
                    </a>
                    <a href="{{ route('investigacion.variables.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.variables.*') ? ' is-active' : '' }}">
                        <i class="fas fa-chart-line"></i><span>Variables</span>
                    </a>
                    <a href="{{ route('investigacion.evaluaciones.index') }}" class="cengi-nav-item{{ request()->routeIs('investigacion.evaluaciones.*') ? ' is-active' : '' }}">
                        <i class="fas fa-clipboard-check"></i><span>Evaluaciones</span>
                    </a>
                </div>
            @endcan

            @can('menu_general')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Soporte</div>
                    <a href="{{ route('soporte.laboratorio.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.laboratorio.*') ? ' is-active' : '' }}">
                        <i class="fas fa-flask"></i><span>Laboratorio</span>
                    </a>
                    @can('create_muestra')
                        <a href="{{ route('soporte.muestras-gen.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.muestras-gen.*') ? ' is-active' : '' }}">
                            <i class="fas fa-barcode"></i><span>Generar muestras</span>
                        </a>
                        <a href="{{ route('soporte.muestras-consulta.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.muestras-consulta.*') ? ' is-active' : '' }}">
                            <i class="fas fa-search"></i><span>Consultar muestras</span>
                        </a>
                    @endcan
                    <a href="{{ route('soporte.imagenes-geo.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.imagenes-geo.*') ? ' is-active' : '' }}">
                        <i class="fas fa-satellite"></i><span>Imagenes geoespaciales</span>
                    </a>
                    <a href="{{ route('soporte.graficas.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.graficas.*') ? ' is-active' : '' }}">
                        <i class="fas fa-chart-area"></i><span>Graficas temporales</span>
                    </a>
                    <a href="{{ route('soporte.archivos.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.archivos.*') ? ' is-active' : '' }}">
                        <i class="fas fa-paperclip"></i><span>Archivos</span>
                    </a>
                    <a href="{{ route('soporte.bitacora.index') }}" class="cengi-nav-item{{ request()->routeIs('soporte.bitacora.*') ? ' is-active' : '' }}">
                        <i class="fas fa-book-open"></i><span>Bitacora</span>
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
                        <i class="fas fa-file-import"></i><span>Importar y analizar</span>
                    </a>
                </div>
            @endcan

            @can('admin_only')
                <div class="cengi-nav-group">
                    <div class="cengi-nav-label">Sistema</div>
                    <a href="{{ route('admin.usuarios.index') }}" class="cengi-nav-item{{ request()->routeIs('admin.usuarios.*') ? ' is-active' : '' }}">
                        <i class="fas fa-users-cog"></i><span>Usuarios y permisos</span>
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
            <div class="cengi-sidebar-version">SIGEC · Zafra 2025–2026</div>
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
            <span class="cengi-pill">{{ $roleLabel }}</span>
            <div class="cengi-userbox">
                <div class="cengi-avatar">{{ $initials }}</div>
                <div>
                    <div class="cengi-userbox-u1">{{ $user->name ?? 'Usuario' }}</div>
                    <div class="cengi-userbox-u2">Sistema SIGEC</div>
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
    </script>
</body>
</html>
