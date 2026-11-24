<?php
/**
 * Permisos del modulo Experimentos (SIGEC) que se editan en Roles y permisos, agrupados como
 * se muestran en Crear/Editar rol. Cada permiso: [etiqueta, descripcion].
 *
 * Los "sigec.menu.*" controlan cada opcion del menu lateral de Experimentos y la entrada a su
 * pagina; se aplican al rol que el usuario tiene EN Experimentos. Administrador y Superadmin
 * ven todo. Las acciones dentro de cada pagina (crear, editar, eliminar) siguen dependiendo del
 * rol de Experimentos. Mantener sincronizado con experimentos/config/sso.php ("menu").
 */
function permisos_sigec_catalogo()
{
    return [
        'Acceso' => [
            'sigec.acceder' => ['Acceder al modulo Experimentos', 'Muestra Experimentos en el menu principal del portal y permite entrar al modulo.'],
        ],
        'Menu: Investigacion' => [
            'sigec.menu.proyectos' => ['Programas y proyectos', 'Opcion "Programas y proyectos" del menu y su pagina.'],
            'sigec.menu.ensayos' => ['Ensayos', 'Opcion "Ensayos" del menu, el listado y el detalle de los ensayos.'],
            'sigec.menu.clima' => ['Clima y estaciones', 'Opcion "Clima y estaciones": consulta, analisis y estaciones de la red climatica.'],
            'sigec.menu.variables' => ['Variables', 'Opcion "Variables" del menu y su catalogo.'],
            'sigec.menu.evaluaciones' => ['Evaluaciones y Analisis', 'Opcion "Evaluaciones y Analisis": registros, graficos y analisis estadistico.'],
        ],
        'Menu: Soporte' => [
            'sigec.menu.muestras' => ['Muestras', 'Opcion "Muestras": generar etiquetas y seguimiento de los resultados del Laboratorio.'],
            'sigec.menu.imagenes_geo' => ['Imagenes geoespaciales', 'Opcion "Imagenes geoespaciales" del menu y su pagina.'],
            'sigec.menu.archivos' => ['Archivos', 'Opcion "Archivos" del menu y su pagina.'],
            'sigec.menu.formularios' => ['Formularios de campo', 'Opcion "Formularios de campo": administrarlos o llenar los asignados, segun el rol.'],
            'sigec.menu.reportes' => ['Reportes', 'Opcion "Reportes": informes y exportaciones de los ensayos.'],
        ],
        'Menu: Datos y analisis' => [
            'sigec.menu.importacion' => ['Importacion de datos', 'Opcion "Importacion de datos" (carga masiva desde Excel).'],
        ],
        'Catalogos' => [
            'sigec.programas.gestionar' => ['Gestionar el catalogo de programas', 'Crear, editar y eliminar programas en Sistema › Catalogos › Programas. Al editar la sigla se recodifican proyectos y ensayos. Administrador y Superadmin siempre pueden.'],
        ],
        'Clima' => [
            'sigec.clima_api.gestionar' => ['Gestionar la conexion API del ICC', 'Ver y editar la conexion con la API del ICC en Clima (configurar, probar, sincronizar y ver la bitacora). Administrador y Superadmin siempre pueden.'],
        ],
    ];
}

function permisos_sigec_todos()
{
    $todos = [];
    foreach (permisos_sigec_catalogo() as $permisos) {
        $todos += $permisos;
    }
    return $todos;
}
