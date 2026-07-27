<?php
require_once __DIR__ . '/revisar_permisos.php';

function menu_render()
{
    $puedeGestionar = cengi_puede_gestionar();
    $puedeVerUsuarios = cengi_puede_ver_usuarios();
    $puedeGestionarUsuarios = cengi_puede_gestionar_usuarios();
    $puedeVerIngenios = cengi_puede_ver_ingenios();
    $puedeGestionarIngenios = cengi_puede_gestionar_ingenios();
    $puedeVerParticipantes = cengi_puede_ver_participantes();
    $puedeCargarParticipantes = cengi_puede_cargar_participantes();
    $puedeVerSolicitudes = cengi_puede_ver_solicitudes();
    $puedeEditarSolicitudes = cengi_puede_editar_solicitudes();
    $puedeAprobarSolicitudes = cengi_puede_aprobar_solicitudes();
    $puedeRechazarSolicitudes = cengi_puede_rechazar_solicitudes();
    $esEstudiante = cengi_es_estudiante();
    ?>
<style>
:root {
    --cengi-lms-primary: #73BC25;
    --cengi-lms-primary-strong: #5e9b1d;
    --cengi-lms-secondary: #73BC25;
    --cengi-lms-accent: #eef8df;
    --cengi-lms-surface: #ffffff;
    --cengi-lms-border: #dbe8dc;
    --cengi-lms-copy: #294033;
    --cengi-lms-soft: #f5f8f2;
}

body {
    background:
        radial-gradient(circle at top right, rgba(148, 201, 115, 0.16), transparent 24%),
        radial-gradient(circle at top left, rgba(76, 154, 100, 0.12), transparent 28%),
        linear-gradient(180deg, #f9fcf7 0%, #eff5ec 100%);
    color: var(--cengi-lms-copy);
}

.container {
    width: min(1180px, calc(100% - 32px));
}

.navbar.navbar-inverse {
    background: linear-gradient(
        135deg,
        #5e9b1d 0%,
        #73BC25 100%
    );

border: 0;
    border-radius: 24px;
    box-shadow: 0 18px 40px rgba(47, 111, 68, 0.18);
    margin-top: 22px;
    margin-bottom: 28px;
    padding: 8px 10px;
}

.navbar-inverse .navbar-brand,
.navbar-inverse .navbar-nav > li > a {
    color: #f8fff6;
    font-weight: 600;
}

.navbar-inverse .navbar-brand {
    letter-spacing: 0.08em;
}

.navbar-inverse .navbar-nav > li > a {
    border-radius: 14px;
    transition: background-color 0.2s ease, transform 0.2s ease;
}

.navbar-inverse .navbar-nav > li > a:hover,
.navbar-inverse .navbar-nav > li > a:focus,
.navbar-inverse .navbar-nav > .open > a,
.navbar-inverse .navbar-nav > .open > a:hover,
.navbar-inverse .navbar-nav > .open > a:focus {
    background: rgba(255, 255, 255, 0.16);
    color: #ffffff;
}

.navbar-inverse .dropdown-menu {
    border: 0;
    border-radius: 18px;
    padding: 10px;
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.14);
    background: rgba(255, 255, 255, 0.98);
}

.navbar-inverse .dropdown-menu > li > a {
    border-radius: 12px;
    padding: 10px 14px;
    color: var(--cengi-lms-copy);
}

.navbar-inverse .dropdown-menu > li > a:hover,
.navbar-inverse .dropdown-menu > li > a:focus {
    background: #edf5ea;
}

.panel {
    border: 0;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 18px 38px rgba(41, 64, 51, 0.10);
}

.panel.panel-success > .panel-heading {
    background: linear-gradient(135deg, var(--cengi-lms-primary) 0%, var(--cengi-lms-secondary) 100%);
    border: 0;
    color: #fff;
    padding: 18px 24px;
}

.panel .panel-title {
    font-size: 20px;
    font-weight: 700;
}

.panel .panel-body {
    background: rgba(255, 255, 255, 0.95);
    padding: 24px;
}

.btn {
    border-radius: 14px;
    border: 0;
    font-weight: 600;
    padding-inline: 16px;
}

.btn-primary,
.btn-success,
.btn-info {
    box-shadow: 0 10px 24px rgba(47, 125, 50, 0.18);
}

.btn-primary {
    background: var(--cengi-lms-primary);
}

.btn-success {
    background: var(--cengi-lms-secondary);
}

.btn-info {
    background: #3c7d58;
}

.btn-danger {
    background: #d64545;
}

.form-control {
    border-radius: 14px;
    border: 1px solid var(--cengi-lms-border);
    box-shadow: none;
    min-height: 42px;
}

.form-control:focus {
    border-color: #7ab56d;
    box-shadow: 0 0 0 3px rgba(122, 181, 109, 0.16);
}

.table {
    border-collapse: separate;
    border-spacing: 0;
    overflow: hidden;
    border-radius: 18px;
}

.table > thead > tr > th {
    background: #edf4e9;
    border-bottom-width: 1px;
    color: var(--cengi-lms-primary-strong);
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.table > tbody > tr > td,
.table > thead > tr > th {
    padding: 13px 14px;
    vertical-align: middle;
}

.table-hover > tbody > tr:hover {
    background: #f7fbf5;
}

.well {
    border: 0;
    border-radius: 18px;
    background: var(--cengi-lms-soft);
    box-shadow: none;
}

.cengi-hero {
    background: linear-gradient(
        180deg,
        #ffffff,
        #eef8df
    );

    border-radius: 30px;
    color: #5e9b1d;
    padding: 34px;
    box-shadow: 0 24px 46px rgba(47, 111, 68, 0.22);
    margin-bottom: 28px;
}

.cengi-hero h2,
.cengi-hero h1,
.cengi-hero p {
    color: #5e9b1d !important;
}

.cengi-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.14);
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.cengi-card-grid {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.cengi-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 22px;
    padding: 22px;
    box-shadow: 0 18px 38px rgba(24, 49, 83, 0.08);
}

.cengi-card h3 {
    margin-top: 0;
    margin-bottom: 8px;
    color: var(--cengi-lms-primary-strong);
}

.cengi-empty {
    background: #f7fbf5;
    border: 1px dashed #c4d7c0;
    border-radius: 18px;
    padding: 18px;
    color: #4c6654;
}

@media (max-width: 767px) {
    .navbar.navbar-inverse {
        border-radius: 22px;
        padding: 10px 6px;
    }

    .panel .panel-body {
        padding: 18px;
    }

    .cengi-hero {
        padding: 24px;
    }
}

#cengi-progress-bar {
    position: fixed;
    top: 0;
    left: 0;
    width: 0;
    height: 3px;
    background: linear-gradient(90deg, #5e9b1d, #73BC25, #a6d85c);
    box-shadow: 0 0 14px rgba(115, 188, 37, 0.35);
    opacity: 0;
    z-index: 9999;
    transition: width 0.22s ease, opacity 0.22s ease;
}

#cengi-progress-bar.is-visible {
    opacity: 1;
}

#cengi-progress-bar.is-active {
    width: 100%;
}

body.cengi-nav-loading {
    cursor: progress;
}
</style>
<div class="container">
    <nav class="navbar navbar-inverse">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#app-navbar-collapse-pattern">
                <span class="sr-only">Toggle Navigation</span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
            <span class="navbar-brand">CENGICURSOS</span>
        </div>

        <div class="navbar-collapse collapse" id="app-navbar-collapse-pattern">
            <ul class="nav navbar-nav">
                <li role="presentation"><a href="index.php">Inicio</a></li>

                <?php if ($puedeVerUsuarios): ?>
                    <li class="dropdown">
                        <a class="dropdown-toggle" data-toggle="dropdown" href="#">Usuarios</a>
                        <ul class="dropdown-menu">
                            <li role="presentation"><a href="ver_usuarios.php"><span class="glyphicon glyphicon-th-large"></span> Ver todos</a></li>
                            <?php if ($puedeGestionarUsuarios): ?>
                                <li role="presentation"><a href="../login/usuarios/crear_usuario.php?scope=cursos"><span class="glyphicon glyphicon-plus"></span> Agregar</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if ($puedeVerIngenios): ?>
                    <li class="dropdown">
                        <a class="dropdown-toggle" data-toggle="dropdown" href="#">Ingenios</a>
                        <ul class="dropdown-menu">
                            <li role="presentation"><a href="ver_ingenios.php"><span class="glyphicon glyphicon-th-large"></span> Ver todos</a></li>
                            <?php if ($puedeGestionarIngenios): ?>
                                <li role="presentation"><a href="agregar_ingenios.php"><span class="glyphicon glyphicon-plus"></span> Agregar</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if ($puedeVerParticipantes): ?>
                    <li class="dropdown">
                        <a class="dropdown-toggle" data-toggle="dropdown" href="#">Participantes</a>
                        <ul class="dropdown-menu">
                            <li role="presentation"><a href="participantes.php"><span class="glyphicon glyphicon-th-large"></span> Ver participantes</a></li>
                            <?php if ($puedeCargarParticipantes): ?>
                                <li role="presentation"><a href="participantes.php#cargarParticipantes"><span class="glyphicon glyphicon-cloud-upload"></span> Cargar CSV o Excel</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <li class="dropdown">
                    <a class="dropdown-toggle" data-toggle="dropdown" href="#">Cursos</a>
                    <ul class="dropdown-menu">
                        <li><a href="ver_cursos.php"><span class="glyphicon glyphicon-th-large"></span> <?php echo $esEstudiante ? 'Mis cursos' : 'Ver todos'; ?></a></li>
                        <?php if ($puedeGestionar): ?>
                            <li><a href="agregar_cursos.php"><span class="glyphicon glyphicon-plus"></span> Agregar</a></li>
                            <li role="presentation" class="dropdown-header">Categorias</li>
                            <li><a href="ver_categorias.php"><span class="glyphicon glyphicon-th-large"></span> Ver todos</a></li>
                            <li><a href="agregar_categorias.php"><span class="glyphicon glyphicon-plus"></span> Agregar</a></li>
                        <?php endif; ?>
                    </ul>
                </li>

                <?php if ($puedeVerSolicitudes): ?>
                    <li class="dropdown">
                        <a class="dropdown-toggle" data-toggle="dropdown" href="#">Solicitudes</a>
                        <ul class="dropdown-menu">
                            <li role="presentation"><a href="solicitudes.php"><span class="glyphicon glyphicon-th-large"></span> Ver solicitudes</a></li>
                            <?php if ($puedeEditarSolicitudes): ?>
                                <li role="presentation"><a href="solicitudes.php"><span class="glyphicon glyphicon-pencil"></span> Editar pendientes</a></li>
                            <?php endif; ?>
                            <?php if ($puedeAprobarSolicitudes): ?>
                                <li role="presentation"><a href="solicitudes.php"><span class="glyphicon glyphicon-ok"></span> Aprobar</a></li>
                            <?php endif; ?>
                            <?php if ($puedeRechazarSolicitudes): ?>
                                <li role="presentation"><a href="solicitudes.php"><span class="glyphicon glyphicon-remove"></span> Rechazar</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if ($puedeGestionar): ?>
                    <li class="dropdown">
                        <a class="dropdown-toggle" data-toggle="dropdown" href="#">Reportes</a>
                        <ul class="dropdown-menu">
                            <li role="presentation" class="dropdown-header">Seleccionar</li>
                            <li><a href="exportaringenios.php"><span class="glyphicon glyphicon-th-large"></span> Reporte por ingenio</a></li>
                            <li><a href="exportarcursos.php"><span class="glyphicon glyphicon-th-large"></span> Reporte por curso</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <li role="presentation"><a href="logout.php?act=logout">Logout</a></li>
            </ul>
        </div>
    </nav>
</div>
<script src="js/cengi-navigation.js"></script>
<?php
}

