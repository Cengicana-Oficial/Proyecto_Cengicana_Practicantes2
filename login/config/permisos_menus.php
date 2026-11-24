<?php
/**
 * Catálogos por opción de menú de los módulos cuyos permisos se guardan con nombres sueltos
 * (Cengicursos, Visitas, Solicitudes internas y la administración del portal). Crear rol y
 * Editar rol los muestran agrupados como el menú de cada módulo, con lo que habilita cada uno.
 *
 * Formato: [grupo de permisos => [subtítulo => [permiso => [etiqueta, descripción]]]].
 * Si un módulo cambia su menú o los permisos que consulta, actualizar aquí.
 *   - Cengicursos: cengicursos/menu.php (cengi_nav_estructura) y cengicursos/revisar_permisos.php.
 *     Su pantalla Roles y permisos usa las áreas de cengicursos/accesos_helpers.php.
 *   - Visitas: Pruebas/public/admin/dashboard_unificado.php y modulos/solicitudes_unificadas.php.
 *   - Solicitudes internas: sistema_de_solicitudes/index.php e includes/functions.php.
 *   - Portal: login/Menu.php (Administración).
 * Cengilabs, Comités, Inventario y Experimentos tienen su propio catálogo.
 */

function permisos_menus_catalogo()
{
    return [
        'Permisos de cursos' => [
            'Administración completa' => [
                'administrar_cursos_cengi' => ['Coordinación de capacitación (todo Cursos)', 'Todo el menú de Cursos y todos los ingenios, incluidas Carga masiva y los Excel por ingenio y por curso. Solo lo asigna un superadmin.'],
            ],
            'Planificación y Cursos' => [
                'gestionar_cursos_cengi' => ['Gestionar cursos y planificación', 'Menú Planificación (planificación, calendario general, programa de sesiones, bitácora y comunicaciones), Aula virtual, Áreas técnicas y Categorías; crear y editar cursos.'],
            ],
            'Inscripciones' => [
                'ver_solicitudes_cengi' => ['Ver inscripciones', 'Próximos cursos, Inscripciones y pagos y Solicitudes directas, en consulta.'],
                'editar_solicitudes_cengi' => ['Editar inscripciones', 'Corregir los datos de una inscripción o solicitud directa.'],
                'aprobar_solicitudes_cengi' => ['Aprobar inscripciones', 'Aprobar inscripciones y solicitudes directas.'],
                'rechazar_solicitudes_cengi' => ['Rechazar inscripciones', 'Rechazar inscripciones y solicitudes directas.'],
                'gestionar_solicitudes_cengi' => ['Gestionar inscripciones (todo)', 'Ver, editar, aprobar y rechazar inscripciones.'],
            ],
            'Seguimiento por curso y participantes' => [
                'ver_participantes_cengi' => ['Ver participantes', 'Seguimiento por curso (consulta) y Directorio de participantes.'],
                'gestionar_notas_cengi' => ['Asistencia y notas', 'Seguimiento por curso: registrar asistencia y notas, y adjuntar diplomas; también abre el Aula virtual del curso.'],
                'cargar_participantes_cengi' => ['Cargar participantes', 'Carga masiva de participantes a un curso.'],
                'editar_participantes_cengi' => ['Editar participantes', 'Corregir los datos de un participante.'],
                'eliminar_participantes_cengi' => ['Desactivar participantes', 'Quitar participantes de un curso o desactivarlos.'],
                'gestionar_participantes_cengi' => ['Gestionar participantes (todo)', 'Ver, cargar, editar y desactivar participantes.'],
            ],
            'Certificación' => [
                'ver_diplomas_cengi' => ['Ver certificación', 'Opción Certificación: diplomas y constancias, en consulta.'],
                'subir_diplomas_cengi' => ['Adjuntar diplomas', 'Adjuntar el diploma firmado de cada participante.'],
                'gestionar_diplomas_cengi' => ['Gestionar certificación', 'Generar diplomas y constancias de notas.'],
            ],
            'Eventos' => [
                'ver_eventos_cengi' => ['Ver eventos', 'Eventos y pagos y Evaluación de eventos, en consulta.'],
                'gestionar_eventos_cengi' => ['Gestionar eventos', 'Crear eventos, enviar gafetes, registrar ingreso con QR y acuerdos.'],
            ],
            'Administración: pagos' => [
                'ver_pagos_cengi' => ['Ver pagos', 'Pagos de inscripción, y Pagos y cobros por evento (con Ver eventos), en consulta.'],
                'validar_pagos_cengi' => ['Validar pagos', 'Validar comprobantes y registrar pagos de inscripción y de eventos.'],
                'firmar_liquidaciones_cengi' => ['Pago a instructores', 'Opción Pago a instructores: validar y firmar las liquidaciones (Jefe de Transferencia).'],
            ],
            'Reportes' => [
                'ver_reportes_cengi' => ['Informe de gestión', 'Opción Informe de gestión con los indicadores para la Dirección.'],
            ],
            'Instructores' => [
                'ver_instructores_cengi' => ['Ver instructores', 'Directorio de instructores, Base de datos y documentos y Evaluación de instructores.'],
                'gestionar_instructores_cengi' => ['Gestionar instructores', 'Lo anterior más Necesidades de capacitación y Carga masiva de CV.'],
            ],
            'Catálogos' => [
                'ver_ingenios_cengi' => ['Ver ingenios e instituciones', 'Opción Ingenios e instituciones, en consulta.'],
                'gestionar_ingenios_cengi' => ['Gestionar ingenios e instituciones', 'Crear y editar ingenios e instituciones.'],
                'ver_organizaciones_cengi' => ['Ver organizaciones', 'Instituciones aliadas, en consulta.'],
                'gestionar_organizaciones_cengi' => ['Gestionar organizaciones', 'Crear y editar instituciones aliadas.'],
            ],
            'Sistema' => [
                'ver_usuarios_cengi' => ['Ver usuarios de Cursos', 'Opción Usuarios, en consulta.'],
                'gestionar_usuarios_cengi' => ['Gestionar usuarios de Cursos', 'Cambiar el rol y el ingenio de los usuarios en Cursos.'],
                'ver_roles_cengi' => ['Ver roles de Cursos', 'Opción Roles y permisos de Cursos, en consulta.'],
                'roles_gestionar_cengi' => ['Editar roles de Cursos', 'Editar la matriz de permisos de los roles de Cursos. Solo lo asigna un superadmin.'],
            ],
        ],
        'Permisos de visitas' => [
            'Menú del panel' => [
                'ver_dashboard' => ['Inicio', 'Opción Inicio (resumen) del panel de Visitas.'],
                'ver_solicitudes' => ['Ver solicitudes', 'Opciones Solicitudes, Pendientes, Aprobadas y Rechazadas, en consulta.'],
                'ver_solicitudes_aprobadas' => ['Ver solo las aprobadas', 'Opción Solicitudes limitada a las visitas aprobadas.'],
            ],
            'Acciones sobre solicitudes' => [
                'gestionar_solicitudes' => ['Gestionar solicitudes', 'Aprobar o rechazar visitas, asignar y reasignar áreas; incluye la opción Áreas.'],
                'enviar_correos' => ['Enviar correos', 'Enviar correos al solicitante desde la solicitud.'],
                'ocultar_solicitudes' => ['Ocultar solicitudes', 'Ocultar solicitudes del listado (no las borra).'],
                'gestionar_reserva_salones' => ['Reserva de salones', 'Registrar y enviar la reserva de salones de una visita.'],
            ],
            'Catálogos' => [
                'gestionar_areas' => ['Gestionar áreas', 'Opción Áreas: crear, editar y eliminar áreas de interés.'],
            ],
        ],
        'Permisos de solicitudes internas' => [
            'Solicitudes' => [
                'solicitudes_internas.crear' => ['Crear solicitudes', 'Nueva solicitud y Mis solicitudes. El usuario necesita un programa de origen asignado.'],
                'solicitudes_internas.ver' => ['Ver recibidas', 'Opción Recibidas: lo que otros programas envían al programa del usuario.'],
                'solicitudes_internas.gestionar' => ['Atender recibidas', 'Cambiar el estado y dar seguimiento a las solicitudes recibidas.'],
                'solicitudes_internas.ver_todas' => ['Ver todas (Dirección)', 'Panel y Recibidas de todos los programas, en consulta; aprueba las ideas del Buzón.'],
            ],
            'Buzón de ideas' => [
                'solicitudes_internas.buzon.ver' => ['Ver el Buzón de ideas', 'Opción Buzón de ideas y el seguimiento de cada idea.'],
                'solicitudes_internas.buzon.gestionar' => ['Gestionar el Buzón de ideas', 'Marcar ideas como revisadas, archivarlas y asignar responsables.'],
            ],
            'Administración' => [
                'solicitudes_internas.usuarios.ver' => ['Ver usuarios', 'Opción Usuarios, en consulta.'],
                'solicitudes_internas.usuarios.gestionar' => ['Gestionar usuarios y correos', 'Agregar usuarios al módulo, cambiar su programa y rol, y configurar Correos.'],
                'solicitudes_internas.programas.ver' => ['Ver programas', 'Opción Programas, en consulta.'],
                'solicitudes_internas.programas.gestionar' => ['Gestionar programas', 'Crear, editar y eliminar programas.'],
            ],
        ],
        'Permisos de usuarios y accesos' => [
            'Administración del portal' => [
                'gestionar_usuarios' => ['Usuarios', 'Administración › Usuarios del portal. En Visitas habilita también la opción Usuarios.'],
                'gestionar_modulos' => ['Módulos', 'Administración › Módulos del portal.'],
                'gestionar_ingenios' => ['Ingenios', 'Administración › Ingenios del portal. En Cursos equivale a gestionar ingenios.'],
            ],
        ],
    ];
}

/**
 * Permisos que ningún módulo consulta: no se ofrecen en Crear/Editar rol. Si un rol ya los
 * tiene se conservan sin mostrarse (igual que los obsoletos de Cengilabs).
 */
function permisos_sin_uso()
{
    return [
        'gestionar_roles' => 'Roles y permisos del portal es solo del superadmin (login/roles/_guard.php).',
        'ver_cursos_cengi' => 'En Cursos, la opción Cursos la ve cualquier rol del módulo.',
        'ver_pagos' => 'El panel de pagos de Visitas se controla por rol, no por permiso.',
        'gestionar_pagos' => 'El panel de pagos de Visitas se controla por rol, no por permiso.',
        'cengisalas.salas.admin' => 'CengiSalas aún no tiene pantallas.',
        'cengisalas.salas.manager' => 'CengiSalas aún no tiene pantallas.',
        'cengisalas.salas.user' => 'CengiSalas aún no tiene pantallas.',
    ];
}

/** [permiso => [etiqueta, descripción]] de todos los catálogos por menú. */
function permisos_menus_todos()
{
    $todos = [];
    foreach (permisos_menus_catalogo() as $subgrupos) {
        foreach ($subgrupos as $permisos) {
            $todos += $permisos;
        }
    }
    return $todos;
}
