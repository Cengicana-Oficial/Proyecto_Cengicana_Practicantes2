<?php
/**
 * Permisos del modulo "Comites, Transferencia y Comunicacion" (carpeta comites/).
 *
 * Es la unica lista de secciones y permisos del modulo. La usan:
 *  - comites/ (sembrar permisos, menu lateral y resumen de permisos por usuario);
 *  - login/roles (tabla Ver / Gestionar de Comites en Crear rol / Editar rol).
 *
 * Nombre de permiso: comites.<seccion>.ver | comites.<seccion>.gestionar, mas las
 * aprobaciones especiales de permisos_comites_especiales().
 */

/**
 * [grupo del menu => [seccion => [etiqueta, que permite]]], en el orden del menu lateral.
 * "gestionar" incluye "ver".
 */
function permisos_comites_catalogo()
{
    return [
        'Comités' => [
            'grupos' => ['Comités', 'Grupos de comités, sus integrantes y su información.'],
            'contactos' => ['Directorio de contactos', 'Contactos de ingenios y exportación del directorio.'],
            'reuniones' => ['Reuniones', 'Agenda, actas e informes de reuniones de comités.'],
            'eventos' => ['Eventos de transferencia', 'Eventos, asistentes y vínculo con Cengicursos.'],
            'acuerdos' => ['Seguimiento a próximos pasos', 'Acuerdos de reuniones y su avance.'],
        ],
        'Documentación' => [
            'documentos' => ['Biblioteca documental para ingenios', 'Documentos que se comparten con los ingenios.'],
            'biblioteca_interna' => ['Biblioteca documental interna', 'Documentos de uso interno de CENGICAÑA.'],
            'correos' => ['Correos y envíos', 'Envío de documentos por correo y su historial.'],
        ],
        'Resultados' => [
            'tableros' => ['Tableros', 'Tableros e indicadores del módulo.'],
            'informes' => ['Informes cuatrimestrales', 'Informes de los programas por cuatrimestre.'],
            'memorias' => ['Memoria de resultados', 'Artículos y consolidado de la memoria anual.'],
            'cifras' => ['Cifras institucionales', 'Cifras, alianzas y presentación institucional.'],
        ],
        'Comunicación' => [
            'publicaciones' => ['Redes sociales', 'Cuentas, publicaciones y audiencia en redes.'],
            'estadisticas' => ['Estadísticas', 'Estadísticas de comunicación por periodo.'],
        ],
        'Administración' => [
            'configuracion' => ['Configuración', 'Catálogo de comités (nombres y estado activo).'],
            'notificaciones' => ['Notificaciones por correo', 'Qué avisos se envían por correo y a quién.'],
            'usuarios' => ['Usuarios y programas', 'Acceso de usuarios al módulo, su rol y su programa.'],
        ],
    ];
}

/** [seccion => etiqueta] de todas las secciones, en orden. */
function permisos_comites_secciones()
{
    $secciones = [];
    foreach (permisos_comites_catalogo() as $items) {
        foreach ($items as $clave => [$etiqueta]) {
            $secciones[$clave] = $etiqueta;
        }
    }
    return $secciones;
}

/** [permiso => [etiqueta, descripcion]] de las aprobaciones y acciones especiales. */
function permisos_comites_especiales()
{
    return [
        'comites.documentos.revisar_transferencia' => ['Revisar documentos para ingenios (Transferencia)', 'Primera y segunda revisión antes de publicar a los ingenios.'],
        'comites.documentos.aprobar_direccion' => ['Aprobar documentos para ingenios (Dirección)', 'Aprobación final de documentos para ingenios.'],
        'comites.informes.revisar' => ['Revisar informes cuatrimestrales (Dirección)', 'Revisión de los informes que entregan los programas.'],
        'comites.memorias.entregar' => ['Entregar artículos a la memoria', 'Subir los artículos propios a la memoria de resultados.'],
        'comites.memorias.ver_todos' => ['Ver todos los artículos de la memoria', 'Consultar los artículos de todos los programas, no solo los propios.'],
        'comites.memorias.diagramar' => ['Diagramar la memoria', 'Diagramación de artículos y del consolidado.'],
        'comites.memorias.aprobar_transferencia' => ['Aprobar la memoria (Transferencia)', 'Aprobación de la versión final del consolidado.'],
        'comites.memorias.aprobar_direccion' => ['Aprobar la memoria (Dirección)', 'Liberación del consolidado de la memoria.'],
    ];
}

/**
 * Permisos nuevos que salieron de otro: al crearse, los reciben los roles que ya tenian el
 * de origen, para que nadie pierda acceso (antes compartian permiso).
 * [permiso nuevo => permiso de origen]
 */
function permisos_comites_heredados()
{
    return [
        'comites.grupos.ver' => 'comites.configuracion.ver',
        'comites.grupos.gestionar' => 'comites.configuracion.gestionar',
        'comites.notificaciones.ver' => 'comites.configuracion.ver',
        'comites.notificaciones.gestionar' => 'comites.configuracion.gestionar',
        'comites.cifras.ver' => 'comites.estadisticas.ver',
        'comites.cifras.gestionar' => 'comites.estadisticas.gestionar',
    ];
}

/**
 * Plantillas de rol para Comites: [nombre => [permisos]]. Marcan solo permisos de Comites.
 * El Jefe de Transferencia y el Director General se derivan ademas del programa asignado
 * (comites/includes/functions.php, comites_permisos_por_programa()).
 */
function permisos_comites_plantillas()
{
    $ver = static fn(array $s) => array_map(static fn($x) => "comites.$x.ver", $s);
    $gestionar = static fn(array $s) => array_map(static fn($x) => "comites.$x.gestionar", $s);
    $todas = array_keys(permisos_comites_secciones());
    $operativas = array_diff($todas, ['configuracion', 'notificaciones', 'usuarios']);
    return [
        'Director' => array_merge(
            $ver($operativas),
            ['comites.documentos.aprobar_direccion', 'comites.informes.revisar', 'comites.memorias.aprobar_direccion', 'comites.memorias.ver_todos']
        ),
        'Jefe de programa' => array_merge(
            $ver(['grupos', 'contactos', 'reuniones', 'eventos', 'acuerdos', 'documentos', 'biblioteca_interna', 'tableros', 'informes', 'memorias', 'cifras', 'estadisticas']),
            ['comites.acuerdos.gestionar', 'comites.memorias.entregar', 'comites.memorias.ver_todos']
        ),
        'Colaborador de programa' => array_merge(
            $ver(['reuniones', 'acuerdos', 'documentos', 'biblioteca_interna', 'memorias']),
            ['comites.memorias.entregar']
        ),
        'Administrador del módulo' => array_merge($ver($todas), $gestionar($todas)),
    ];
}
