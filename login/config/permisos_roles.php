<?php

function asegurar_tablas_permisos(PDO $conn)
{
    $conn->exec("
        CREATE TABLE IF NOT EXISTS permisos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre_permiso VARCHAR(100) NOT NULL UNIQUE,
            descripcion VARCHAR(255)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS rol_permiso (
            rol_id INT NOT NULL,
            permiso_id INT NOT NULL,
            PRIMARY KEY (rol_id, permiso_id),
            CONSTRAINT fk_rol_permiso_rol
                FOREIGN KEY (rol_id) REFERENCES roles(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_rol_permiso_permiso
                FOREIGN KEY (permiso_id) REFERENCES permisos(id)
                ON DELETE CASCADE
        )
    ");

    sembrar_permisos_base($conn);
}

function sembrar_permisos_base(PDO $conn)
{
    $permisos = [
        'ver_dashboard' => 'Permite ver el dashboard general',
        'gestionar_solicitudes' => 'Permite aprobar y rechazar solicitudes del modulo de visitas',
        'ver_solicitudes' => 'Permite ver el listado de solicitudes del modulo de visitas',
        'gestionar_pagos' => 'Permite marcar solicitudes como pagadas',
        'ver_pagos' => 'Permite ver el dashboard de pagos',
        'gestionar_usuarios' => 'Permite crear y editar usuarios',
        'gestionar_roles' => 'Permite editar roles y sus permisos',
        'gestionar_modulos' => 'Permite gestionar los modulos del sistema',
        'gestionar_ingenios' => 'Permite gestionar los ingenios',
        'gestionar_areas' => 'Permite crear y editar areas del modulo de visitas',
        'gestionar_reserva_salones' => 'Permite preparar y enviar reservaciones de salones desde el modulo de visitas',
        'ver_solicitudes_aprobadas' => 'Permite ver solo solicitudes aprobadas',
        'enviar_correos' => 'Permite enviar correos de solicitudes',
        'ocultar_solicitudes' => 'Permite ocultar solicitudes del dashboard',
        'ver_cursos_cengi' => 'Permite ver el modulo de cursos y su listado principal',
        'gestionar_cursos_cengi' => 'Permite crear, editar y administrar cursos',
        'ver_usuarios_cengi' => 'Permite ver los usuarios del modulo de cursos',
        'gestionar_usuarios_cengi' => 'Permite crear y editar usuarios del modulo de cursos',
        'ver_ingenios_cengi' => 'Permite ver los ingenios del modulo de cursos',
        'gestionar_ingenios_cengi' => 'Permite crear y editar ingenios del modulo de cursos',
        'ver_participantes_cengi' => 'Permite ver el listado de participantes',
        'cargar_participantes_cengi' => 'Permite cargar participantes desde CSV o Excel',
        'editar_participantes_cengi' => 'Permite editar participantes',
        'eliminar_participantes_cengi' => 'Permite desactivar participantes',
        'gestionar_participantes_cengi' => 'Permite crear, cargar y actualizar participantes',
        'ver_solicitudes_cengi' => 'Permite ver solicitudes de inscripcion en Cengicursos',
        'editar_solicitudes_cengi' => 'Permite editar solicitudes de inscripcion en Cengicursos',
        'aprobar_solicitudes_cengi' => 'Permite aprobar solicitudes de inscripcion en Cengicursos',
        'rechazar_solicitudes_cengi' => 'Permite rechazar solicitudes de inscripcion en Cengicursos',
        'gestionar_solicitudes_cengi' => 'Permite aprobar y rechazar solicitudes de inscripcion en Cengicursos',
        'ver_reportes_cengi' => 'Permite ver y generar reportes de Cengicursos',
        'gestionar_notas_cengi' => 'Permite registrar asistencia, evaluaciones y diplomas en cursos',
        'subir_diplomas_cengi' => 'Permite subir y actualizar diplomas en cursos',
        'roles_gestionar_cengi' => 'Permite gestionar roles y permisos de Cengicursos desde el modulo de cursos',
        'ver_roles_cengi' => 'Permite ver la matriz de roles y permisos de Cengicursos sin editarla',
        'ver_organizaciones_cengi' => 'Permite ver el directorio de organizaciones de Cengicursos',
        'gestionar_organizaciones_cengi' => 'Permite crear y editar organizaciones de Cengicursos',
        'ver_instructores_cengi' => 'Permite ver el directorio de instructores de Cengicursos',
        'gestionar_instructores_cengi' => 'Permite crear y editar instructores de Cengicursos',
        'ver_eventos_cengi' => 'Permite ver los eventos con control QR de Cengicursos',
        'gestionar_eventos_cengi' => 'Permite crear eventos y registrar participantes con QR en Cengicursos',
        'ver_diplomas_cengi' => 'Permite ver el modulo de certificacion y diplomas de Cengicursos',
        'gestionar_diplomas_cengi' => 'Permite generar y cargar diplomas en Cengicursos',
        'ver_pagos_cengi' => 'Permite ver los pagos de inscripcion de las convocatorias y de los eventos pagados de Cengicursos',
        'validar_pagos_cengi' => 'Permite validar comprobantes y registrar los pagos de inscripcion y de eventos pagados en Cengicursos',
        'solicitudes_internas.crear' => 'Permite crear solicitudes desde su programa y ver las que el usuario envio',
        'solicitudes_internas.ver' => 'Permite ver las solicitudes que otros programas envian al programa del usuario',
        'solicitudes_internas.gestionar' => 'Permite cambiar estado y dar seguimiento a las solicitudes recibidas por el programa del usuario',
        'solicitudes_internas.ver_todas' => 'Permite ver (solo lectura) todas las solicitudes entre programas',
        'solicitudes_internas.usuarios.ver' => 'Permite ver usuarios del modulo Sistema de solicitudes',
        'solicitudes_internas.usuarios.gestionar' => 'Permite crear, editar y eliminar usuarios del modulo Sistema de solicitudes',
        'solicitudes_internas.programas.ver' => 'Permite ver programas del modulo Sistema de solicitudes',
        'solicitudes_internas.programas.gestionar' => 'Permite crear, editar y eliminar programas del modulo Sistema de solicitudes',
        'laboratorio.solicitudes.crear' => 'Permite ingresar un nuevo analisis en Laboratorio',
        'laboratorio.lotes.ver' => 'Permite visualizar lotes en Laboratorio',
        'laboratorio.labc.ver' => 'Permite ver el panel LABC de Laboratorio',
        'laboratorio.formularios_labc.ver' => 'Permite ver los formularios dentro del LABC',
        'laboratorio.consolidacion.ver' => 'Permite ver la vista de consolidacion de Laboratorio',
        'laboratorio.catalogo_analisis.ver' => 'Permite ver el catalogo de analisis de Laboratorio',
        'laboratorio.catalogo_muestras.ver' => 'Permite ver el catalogo de muestras de Laboratorio',
        'laboratorio.formularios_pendientes.ver' => 'Permite ver formularios pendientes de Laboratorio',
        'laboratorio.formularios_erroneos.ver' => 'Permite ver formularios erroneos de Laboratorio',
        'laboratorio.blanco_control.ver' => 'Permite ver blancos y controles dentro del LABC',
        'laboratorio.consolidacion.aprobar' => 'Permite aprobar formularios de Laboratorio',
        'laboratorio.formularios.guardar_corregidos' => 'Permite guardar formularios corregidos de Laboratorio',
        'laboratorio.formularios.guardar_errores' => 'Permite guardar formularios con errores de Laboratorio',
        'laboratorio.kanban.ver' => 'Permite ver el tablero de planificacion (Kanban) de Laboratorio',
        'laboratorio.kanban.gestionar' => 'Permite mover tarjetas en el tablero de planificacion de Laboratorio',
        'laboratorio.validacion_tecnica.ver' => 'Permite ver la matriz de validacion tecnica de Laboratorio',
        'laboratorio.validacion_tecnica.aprobar' => 'Permite aprobar o rechazar analisis en validacion tecnica',
        'laboratorio.trazabilidad.ver' => 'Permite ver el historial de versiones/trazabilidad por lote de Laboratorio',
        'laboratorio.documentos.ver' => 'Permite ver los documentos (boletas/informes) de Laboratorio',
        'laboratorio.documentos.gestionar' => 'Permite gestionar los documentos (boletas/informes) de Laboratorio',
        'laboratorio.firma.gestionar' => 'Permite gestionar la firma electronica de documentos de Laboratorio',
        'laboratorio.informes.ver' => 'Permite ver el buscador de informes por lote y los informes generados de Laboratorio',
        // Permisos faltantes que ya usaba el motor propio de laboratorio
        // (lab_all_permissions()) pero que no estaban sembrados en la tabla
        // central de permisos; se agregan aqui para que rol_permiso sea la
        // unica fuente de verdad del modulo Laboratorio.
        'laboratorio.acceder' => 'Permite acceder al modulo de Laboratorio',
        'laboratorio.solicitudes.ver' => 'Permite ver las solicitudes de analisis de Laboratorio',
        'laboratorio.solicitudes.editar' => 'Permite editar solicitudes de analisis de Laboratorio',
        'laboratorio.solicitudes.editar.foliar' => 'Permite editar solicitudes de muestras foliares',
        'laboratorio.solicitudes.editar.suelo' => 'Permite editar solicitudes de muestras de suelo',
        'laboratorio.solicitudes.editar.aguas' => 'Permite editar solicitudes de muestras de aguas',
        'laboratorio.solicitudes.editar.cana' => 'Permite editar solicitudes de muestras de caña',
        'laboratorio.analisis.ver' => 'Permite ver los analisis registrados en Laboratorio',
        'laboratorio.analisis.crear' => 'Permite crear nuevos analisis en Laboratorio',
        'laboratorio.analisis.editar' => 'Permite editar analisis en Laboratorio',
        'laboratorio.analisis.editar_finalizado' => 'Permite editar analisis ya finalizados en Laboratorio',
        'laboratorio.blanco_control.gestionar' => 'Permite gestionar blancos y controles dentro del LABC',
        'laboratorio.usuarios.gestionar' => 'Permite gestionar los usuarios del modulo Laboratorio',
        'laboratorio.roles.gestionar' => 'Permite gestionar roles y permisos de Laboratorio',
        'laboratorio.configuracion.gestionar' => 'Permite gestionar la configuracion general de Laboratorio',
        'sigec.acceder' => 'Permite acceder al modulo SIGEC',
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO permisos (nombre_permiso, descripcion)
        VALUES (?, ?)
    ");

    foreach ($permisos as $nombre => $descripcion) {
        $stmt->execute([$nombre, $descripcion]);
    }

    // Cengilabs: los permisos vigentes y su descripción actual salen del catálogo por etapa
    // del flujo; se insertan si faltan y se actualiza la descripción guardada.
    $actualizar = $conn->prepare("
        INSERT INTO permisos (nombre_permiso, descripcion) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)
    ");
    foreach (permisos_laboratorio_catalogo() as $seccion) {
        foreach ($seccion['permisos'] as $nombre => [, $descripcion]) {
            $actualizar->execute([$nombre, $descripcion]);
        }
    }
}

/**
 * Permisos de Cengilabs (Laboratorio) agrupados por etapa del flujo actual, en el orden en que
 * avanza una muestra: Recepción → Identificación → Planificación → Bandeja del analista →
 * Validación técnica → Control de calidad → Informes → Reportes. Cada permiso: [etiqueta,
 * descripción de lo que habilita hoy en el sistema]. Es la única lista que muestran Crear rol y
 * Editar rol para Cengilabs; los permisos que el sistema ya no consulta están en
 * permisos_laboratorio_obsoletos() y no se ofrecen.
 */
function permisos_laboratorio_catalogo()
{
    return [
        'recepcion' => ['titulo' => 'Recepción', 'permisos' => [
            'laboratorio.solicitudes.crear' => ['Registrar recepciones', 'Abre Recepción para registrar boletas de muestras nuevas y ver Recepciones registradas.'],
            'laboratorio.solicitudes.ver' => ['Consultar recepciones y boletas', 'Ver Recepciones registradas y abrir, descargar o enviar boletas, sin registrar nuevas.'],
            'laboratorio.solicitudes.editar' => ['Editar recepciones (todos los tipos)', 'Editar recepciones de cualquier tipo de muestra; cada edición queda en el historial de versiones.'],
            'laboratorio.solicitudes.editar.suelo' => ['Editar recepciones de Suelos', 'Editar solo recepciones de muestras de suelo.'],
            'laboratorio.solicitudes.editar.foliar' => ['Editar recepciones Foliares', 'Editar solo recepciones de muestras foliares.'],
            'laboratorio.solicitudes.editar.aguas' => ['Editar recepciones de Agua', 'Editar solo recepciones de muestras de agua.'],
            'laboratorio.solicitudes.editar.cana' => ['Editar recepciones de Caña', 'Editar solo recepciones de muestras de caña.'],
        ]],
        'identificacion' => ['titulo' => 'Identificación de muestra', 'permisos' => [
            'laboratorio.lotes.ver' => ['Identificar muestras e imprimir etiquetas', 'Identificación de muestra por lote (escaneo) e impresión de etiquetas con código de barras.'],
        ]],
        'planificacion' => ['titulo' => 'Planificación del trabajo', 'permisos' => [
            'laboratorio.kanban.ver' => ['Ver planificación del trabajo', 'Ver los lotes, sus análisis y a quién están asignados.'],
            'laboratorio.kanban.gestionar' => ['Asignar analistas y prioridades', 'Asignar muestras a analistas y cambiar la prioridad del lote; ve la bandeja de todos los analistas.'],
        ]],
        'bandeja' => ['titulo' => 'Bandeja del analista', 'permisos' => [
            'laboratorio.formularios_pendientes.ver' => ['Usar la Bandeja del analista', 'Ver la bandeja con los análisis asignados, abrir sus hojas de trabajo (con blanco, controles y equipo del catálogo) y quitar o agregar muestras.'],
            'laboratorio.analisis.ver' => ['Capturar resultados en hojas de trabajo', 'Guardar y enviar a validación las hojas de trabajo (se requiere junto con la Bandeja).'],
        ]],
        'validacion' => ['titulo' => 'Validación técnica', 'permisos' => [
            'laboratorio.validacion_tecnica.ver' => ['Ver validación técnica', 'Ver los análisis capturados con sus cálculos, controles y rangos, y la hoja de revisión de cada formulario.'],
            'laboratorio.validacion_tecnica.aprobar' => ['Validar o rechazar análisis', 'Aprobar los análisis, rechazarlos o devolver muestras a reanálisis, con categoría y motivo.'],
        ]],
        'calidad' => ['titulo' => 'Control de calidad', 'permisos' => [
            'laboratorio.consolidacion.ver' => ['Ver control de calidad', 'Indicadores ISO/IEC 17025: tasa de rechazo, motivos, reanálisis abiertos, rechazos recientes, tasa por análisis y calibración de equipos.'],
        ]],
        'informes' => ['titulo' => 'Informes y firma', 'permisos' => [
            'laboratorio.informes.ver' => ['Ver informes por lote', 'Ver el informe de cada lote (borrador y firmado) y descargarlo en Excel.'],
            'laboratorio.firma.gestionar' => ['Aprobar y firmar informes', 'Firma del jefe de laboratorio; también puede devolver el informe al técnico.'],
        ]],
        'reportes' => ['titulo' => 'Reportes', 'permisos' => [
            'laboratorio.documentos.ver' => ['Ver, descargar y enviar reportes', 'Reportes firmados en PDF: ver, descargar y enviar por correo al cliente.'],
            'laboratorio.documentos.gestionar' => ['Seguimiento de entrega de reportes', 'La campanita le avisa de los reportes firmados que aún no se envían al cliente.'],
        ]],
        'historial' => ['titulo' => 'Historial', 'permisos' => [
            'laboratorio.trazabilidad.ver' => ['Ver historial de versiones', 'Cada captura, rechazo y reanálisis por muestra y análisis, con su resultado, datos originales, motivo y responsables.'],
        ]],
        'catalogos' => ['titulo' => 'Catálogos', 'permisos' => [
            'laboratorio.catalogo_analisis.ver' => ['Ver catálogo de análisis', 'Consultar análisis, métodos analíticos, equipos y su calibración, campos por tipo de muestra y auditoría.'],
            'laboratorio.analisis.editar' => ['Editar catálogo de análisis', 'Agregar o modificar análisis (método, equipo, unidad, rango y duración en días), métodos analíticos, equipos y campos (requiere ver el catálogo).'],
            'laboratorio.catalogo_muestras.ver' => ['Catálogo de tipos de muestra', 'Consultar y configurar los tipos de muestra.'],
        ]],
        'administracion' => ['titulo' => 'Administración', 'permisos' => [
            'laboratorio.usuarios.gestionar' => ['Asignar usuarios y roles del laboratorio', 'En Roles y permisos: dar o quitar acceso al laboratorio y asignar el rol de cada usuario (no puede asignar Superadmin).'],
        ]],
        'anteriores' => ['titulo' => 'Pantallas anteriores (LABC)', 'permisos' => [
            'laboratorio.labc.ver' => ['Panel LABC', 'Panel de la versión anterior con los formularios por análisis.'],
            'laboratorio.formularios_labc.ver' => ['Formularios por análisis (LABC)', 'Formularios de captura de la versión anterior; también habilita capturar en la Bandeja.'],
            'laboratorio.blanco_control.ver' => ['Ver blancos y controles (LABC)', 'Pantalla anterior de blancos y controles generales; en el flujo actual van dentro de cada hoja de trabajo.'],
            'laboratorio.blanco_control.gestionar' => ['Registrar blancos y controles (LABC)', 'Crear y modificar blancos y controles en la pantalla anterior.'],
            'laboratorio.consolidacion.aprobar' => ['Aprobar formularios (LABC)', 'Aprobar formularios en la revisión de los formularios anteriores.'],
            'laboratorio.formularios_erroneos.ver' => ['Ver formularios con errores (LABC)', 'Ver los formularios anteriores marcados con errores para su corrección.'],
            'laboratorio.formularios.guardar_corregidos' => ['Guardar formularios corregidos (LABC)', 'Guardar la corrección de un formulario anterior marcado con errores.'],
            'laboratorio.formularios.guardar_errores' => ['Marcar formularios con errores (LABC)', 'Marcar un formulario anterior con errores durante la revisión.'],
        ]],
    ];
}

/** Permisos "laboratorio.*" que el sistema ya no consulta: no se ofrecen al crear o editar roles. */
function permisos_laboratorio_obsoletos()
{
    return [
        'laboratorio.acceder',
        'laboratorio.analisis.crear',
        'laboratorio.analisis.editar_finalizado',
        'laboratorio.roles.gestionar',
        'laboratorio.configuracion.gestionar',
    ];
}

/** [permiso => [etiqueta, descripción]] de todos los permisos vigentes de Cengilabs. */
function permisos_laboratorio_vigentes()
{
    $todos = [];
    foreach (permisos_laboratorio_catalogo() as $seccion) {
        $todos += $seccion['permisos'];
    }
    return $todos;
}

/** Descripción para mostrar: la del catálogo de Cengilabs si existe; si no, la guardada. */
function descripcion_permiso($nombrePermiso, $descripcionGuardada = '')
{
    $vigentes = permisos_laboratorio_vigentes();
    return $vigentes[$nombrePermiso][1] ?? ($descripcionGuardada ?: $nombrePermiso);
}

function obtener_permisos(PDO $conn)
{
    $stmt = $conn->query("
        SELECT id, nombre_permiso, descripcion
        FROM permisos
        ORDER BY nombre_permiso
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtener_permisos_rol(PDO $conn, $rolId)
{
    $stmt = $conn->prepare("
        SELECT p.nombre_permiso
        FROM rol_permiso rp
        INNER JOIN permisos p ON p.id = rp.permiso_id
        WHERE rp.rol_id = ?
    ");
    $stmt->execute([$rolId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function agrupar_permisos(array $permisos)
{
    $grupos = [
        'Permisos de visitas' => [],
        'Permisos de cursos' => [],
        'Permisos de solicitudes internas' => [],
        'Permisos de laboratorio' => [],
        'Permisos de SIGEC' => [],
        'Permisos de Comités' => [],
        'Permisos de pagos' => [],
        'Permisos de usuarios y accesos' => [],
        'Permisos generales' => [],
    ];

    foreach ($permisos as $permiso) {
        $grupos[clasificar_grupo_permiso($permiso['nombre_permiso'])][] = $permiso;
    }

    return array_filter($grupos);
}

function clasificar_grupo_permiso($nombrePermiso)
{
    $nombre = strtolower((string) $nombrePermiso);

    if (strpos($nombre, 'solicitudes_internas.') === 0) {
        return 'Permisos de solicitudes internas';
    }

    if (strpos($nombre, 'curso') !== false || strpos($nombre, 'participante') !== false || strpos($nombre, 'asistencia') !== false || strpos($nombre, 'evaluacion') !== false || strpos($nombre, 'ingenio') !== false || strpos($nombre, 'cengi') !== false || strpos($nombre, 'nota') !== false) {
        return 'Permisos de cursos';
    }

    if (strpos($nombre, 'laboratorio.') === 0 || strpos($nombre, 'labc') !== false) {
        return 'Permisos de laboratorio';
    }

    if (strpos($nombre, 'sigec.') === 0) {
        return 'Permisos de SIGEC';
    }

    if (strpos($nombre, 'comites.') === 0) {
        return 'Permisos de Comités';
    }

    if (strpos($nombre, 'reserva_salones') !== false) {
        return 'Permisos de visitas';
    }

    if (strpos($nombre, 'pago') !== false) {
        return 'Permisos de pagos';
    }

    if (strpos($nombre, 'usuario') !== false || strpos($nombre, 'rol') !== false || strpos($nombre, 'modulo') !== false || strpos($nombre, 'permiso') !== false) {
        return 'Permisos de usuarios y accesos';
    }

    if (strpos($nombre, 'visita') !== false || strpos($nombre, 'solicitud') !== false || strpos($nombre, 'area') !== false || strpos($nombre, 'correo') !== false || strpos($nombre, 'museo') !== false) {
        return 'Permisos de visitas';
    }

    return 'Permisos generales';
}

function titulo_corto_grupo_permiso($grupo)
{
    $mapa = [
        'Permisos de visitas' => 'Visitas',
        'Permisos de cursos' => 'Cengicursos',
        'Permisos de solicitudes internas' => 'Solicitudes internas',
        'Permisos de laboratorio' => 'Cengilabs',
        'Permisos de SIGEC' => 'SIGEC',
        'Permisos de Comités' => 'Comités',
        'Permisos de pagos' => 'Pagos',
        'Permisos de usuarios y accesos' => 'General',
        'Permisos generales' => 'General',
    ];

    return $mapa[$grupo] ?? $grupo;
}

function descripcion_grupo_permiso($grupo)
{
    $mapa = [
        'Permisos de visitas' => 'Controla solicitudes, areas, correos y seguimiento del modulo de visitas.',
        'Permisos de cursos' => 'Agrupa acciones para cursos, participantes, notas, ingenios y asignaciones.',
        'Permisos de solicitudes internas' => 'Controla acceso a solicitudes, usuarios y programas del modulo Sistema de solicitudes.',
        'Permisos de laboratorio' => 'Cengilabs: permisos ordenados por etapa del flujo, de Recepción a la entrega de Reportes. Puede partir de una plantilla de rol y ajustar.',
        'Permisos de SIGEC' => 'Controla el acceso al modulo SIGEC (Sistema de Gestion de Ensayos Experimentales).',
        'Permisos de Comités' => 'Comités, Transferencia y Comunicación: ver o gestionar cada sección del módulo y las aprobaciones de Transferencia y Dirección.',
        'Permisos de pagos' => 'Reune permisos relacionados con estados de pago y control de cobros.',
        'Permisos de usuarios y accesos' => 'Incluye gestion de usuarios, roles, modulos y acceso administrativo.',
        'Permisos generales' => 'Permisos transversales que no pertenecen a un solo modulo.',
    ];

    return $mapa[$grupo] ?? '';
}

function etiqueta_permiso($nombrePermiso)
{
    $mapa = [
        'ver_dashboard' => 'Ver dashboard',
        'ver_solicitudes' => 'Ver solicitudes',
        'ver_solicitudes_aprobadas' => 'Ver solicitudes aprobadas',
        'gestionar_solicitudes' => 'Gestionar solicitudes',
        'ocultar_solicitudes' => 'Ocultar solicitudes',
        'enviar_correos' => 'Enviar correos',
        'gestionar_areas' => 'Gestionar areas',
        'gestionar_reserva_salones' => 'Gestionar reserva de salones',
        'gestionar_usuarios' => 'Gestionar usuarios',
        'gestionar_roles' => 'Gestionar roles',
        'gestionar_modulos' => 'Gestionar modulos',
        'gestionar_ingenios' => 'Gestionar ingenios',
        'ver_cursos_cengi' => 'Ver cursos',
        'gestionar_cursos_cengi' => 'Gestionar cursos',
        'ver_usuarios_cengi' => 'Ver usuarios de cursos',
        'gestionar_usuarios_cengi' => 'Gestionar usuarios de cursos',
        'ver_ingenios_cengi' => 'Ver ingenios de cursos',
        'gestionar_ingenios_cengi' => 'Gestionar ingenios de cursos',
        'ver_participantes_cengi' => 'Ver participantes',
        'cargar_participantes_cengi' => 'Cargar participantes',
        'editar_participantes_cengi' => 'Editar participantes',
        'eliminar_participantes_cengi' => 'Eliminar participantes',
        'gestionar_participantes_cengi' => 'Gestionar participantes',
        'ver_solicitudes_cengi' => 'Ver solicitudes de cursos',
        'editar_solicitudes_cengi' => 'Editar solicitudes de cursos',
        'aprobar_solicitudes_cengi' => 'Aprobar solicitudes de cursos',
        'rechazar_solicitudes_cengi' => 'Rechazar solicitudes de cursos',
        'gestionar_solicitudes_cengi' => 'Gestionar solicitudes de cursos',
        'ver_reportes_cengi' => 'Ver reportes de cursos',
        'gestionar_notas_cengi' => 'Gestionar notas y diplomas',
        'subir_diplomas_cengi' => 'Subir diplomas',
        'roles_gestionar_cengi' => 'Gestionar roles y permisos (Cengicursos)',
        'ver_roles_cengi' => 'Ver roles y permisos (Cengicursos)',
        'ver_organizaciones_cengi' => 'Ver organizaciones',
        'gestionar_organizaciones_cengi' => 'Gestionar organizaciones',
        'ver_instructores_cengi' => 'Ver instructores',
        'gestionar_instructores_cengi' => 'Gestionar instructores',
        'ver_eventos_cengi' => 'Ver eventos y control QR',
        'gestionar_eventos_cengi' => 'Gestionar eventos y control QR',
        'ver_diplomas_cengi' => 'Ver certificacion y diplomas',
        'gestionar_diplomas_cengi' => 'Gestionar certificacion y diplomas',
        'ver_pagos_cengi' => 'Ver pagos de inscripcion y eventos',
        'validar_pagos_cengi' => 'Validar y registrar pagos de inscripcion y eventos (Administracion)',
        'solicitudes_internas.crear' => 'Crear solicitudes (colaborador)',
        'solicitudes_internas.ver' => 'Ver solicitudes recibidas por su programa (jefe)',
        'solicitudes_internas.gestionar' => 'Gestionar solicitudes recibidas por su programa (jefe)',
        'solicitudes_internas.ver_todas' => 'Ver todas las solicitudes entre programas, solo lectura (director)',
        'solicitudes_internas.usuarios.ver' => 'Ver usuarios del modulo',
        'solicitudes_internas.usuarios.gestionar' => 'Gestionar usuarios del modulo',
        'solicitudes_internas.programas.ver' => 'Ver programas del modulo',
        'solicitudes_internas.programas.gestionar' => 'Gestionar programas del modulo',
        'laboratorio.solicitudes.crear' => 'Ingresar nuevo analisis',
        'laboratorio.lotes.ver' => 'Visualizar lotes',
        'laboratorio.labc.ver' => 'LABC',
        'laboratorio.formularios_labc.ver' => 'Ver formularios en LABC',
        'laboratorio.consolidacion.ver' => 'Ver vista',
        'laboratorio.catalogo_analisis.ver' => 'Ver catalogo de analisis',
        'laboratorio.catalogo_muestras.ver' => 'Ver catalogo de muestras',
        'laboratorio.formularios_pendientes.ver' => 'Ver formularios pendientes',
        'laboratorio.formularios_erroneos.ver' => 'Ver formularios erroneos',
        'laboratorio.blanco_control.ver' => 'Ver blancos y controles',
        'laboratorio.consolidacion.aprobar' => 'Aprobar formularios',
        'laboratorio.formularios.guardar_corregidos' => 'Guardar formularios corregidos',
        'laboratorio.formularios.guardar_errores' => 'Guardar formulario con errores',
        'laboratorio.kanban.ver' => 'Ver planificacion (Kanban)',
        'laboratorio.kanban.gestionar' => 'Mover tarjetas en planificacion',
        'laboratorio.validacion_tecnica.ver' => 'Ver validacion tecnica',
        'laboratorio.validacion_tecnica.aprobar' => 'Aprobar/rechazar en validacion tecnica',
        'laboratorio.trazabilidad.ver' => 'Ver historial de versiones',
        'laboratorio.documentos.ver' => 'Ver documentos (PDF)',
        'laboratorio.documentos.gestionar' => 'Gestionar documentos (PDF)',
        'laboratorio.firma.gestionar' => 'Gestionar firma electronica',
        'laboratorio.informes.ver' => 'Ver informes',
        'laboratorio.acceder' => 'Acceder al modulo',
        'laboratorio.solicitudes.ver' => 'Ver solicitudes',
        'laboratorio.solicitudes.editar' => 'Editar solicitudes',
        'laboratorio.solicitudes.editar.foliar' => 'Editar solicitudes foliares',
        'laboratorio.solicitudes.editar.suelo' => 'Editar solicitudes de suelo',
        'laboratorio.solicitudes.editar.aguas' => 'Editar solicitudes de aguas',
        'laboratorio.solicitudes.editar.cana' => 'Editar solicitudes de caña',
        'laboratorio.analisis.ver' => 'Ver analisis',
        'laboratorio.analisis.crear' => 'Crear analisis',
        'laboratorio.analisis.editar' => 'Editar analisis',
        'laboratorio.analisis.editar_finalizado' => 'Editar analisis finalizados',
        'laboratorio.blanco_control.gestionar' => 'Gestionar blancos y controles',
        'laboratorio.usuarios.gestionar' => 'Gestionar usuarios',
        'laboratorio.roles.gestionar' => 'Gestionar roles y permisos',
        'laboratorio.configuracion.gestionar' => 'Gestionar configuracion',
        'sigec.acceder' => 'Acceder al modulo SIGEC',
    ];

    $vigentesLab = permisos_laboratorio_vigentes();
    if (isset($vigentesLab[$nombrePermiso])) {
        return $vigentesLab[$nombrePermiso][0];
    }

    $comites = etiqueta_permiso_comites((string) $nombrePermiso);
    if ($comites !== null) {
        return $comites;
    }

    if (isset($mapa[$nombrePermiso])) {
        return $mapa[$nombrePermiso];
    }

    return ucwords(str_replace('_', ' ', (string) $nombrePermiso));
}

/**
 * Etiqueta legible de los permisos del modulo Comites ("comites.<seccion>.<accion>").
 * Devuelve null si el permiso no es de Comites.
 */
function etiqueta_permiso_comites($nombrePermiso)
{
    if (strpos($nombrePermiso, 'comites.') !== 0) {
        return null;
    }
    $especiales = [
        'comites.documentos.revisar_transferencia' => 'Revisar documentos para ingenios (Transferencia)',
        'comites.documentos.aprobar_direccion' => 'Aprobar documentos para ingenios (Dirección)',
        'comites.informes.revisar' => 'Revisar informes cuatrimestrales (Dirección)',
        'comites.memorias.entregar' => 'Entregar artículos a la memoria de resultados',
        'comites.memorias.diagramar' => 'Diagramar la memoria de resultados',
        'comites.memorias.aprobar_transferencia' => 'Aprobar memoria de resultados (Transferencia)',
        'comites.memorias.aprobar_direccion' => 'Aprobar memoria de resultados (Dirección)',
        'comites.memorias.ver_todos' => 'Ver todos los artículos de la memoria',
    ];
    if (isset($especiales[$nombrePermiso])) {
        return $especiales[$nombrePermiso];
    }
    $secciones = [
        'contactos' => 'directorio de contactos', 'reuniones' => 'reuniones', 'eventos' => 'eventos de transferencia',
        'acuerdos' => 'seguimiento a próximos pasos', 'documentos' => 'biblioteca para ingenios',
        'biblioteca_interna' => 'biblioteca interna', 'correos' => 'correos y envíos', 'tableros' => 'tableros',
        'informes' => 'informes cuatrimestrales', 'memorias' => 'memoria de resultados', 'publicaciones' => 'redes sociales',
        'estadisticas' => 'estadísticas', 'configuracion' => 'comités y configuración', 'usuarios' => 'usuarios y programas',
    ];
    $partes = explode('.', $nombrePermiso);
    if (count($partes) === 3 && isset($secciones[$partes[1]]) && in_array($partes[2], ['ver', 'gestionar'], true)) {
        return ($partes[2] === 'ver' ? 'Ver ' : 'Gestionar ') . $secciones[$partes[1]];
    }
    return null;
}

function guardar_permisos_rol(PDO $conn, $rolId, array $permisos)
{
    $conn->beginTransaction();

    try {
        $delete = $conn->prepare("DELETE FROM rol_permiso WHERE rol_id = ?");
        $delete->execute([$rolId]);

        if (!empty($permisos)) {
            $insert = $conn->prepare("
                INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
                SELECT ?, id
                FROM permisos
                WHERE nombre_permiso = ?
            ");

            foreach ($permisos as $permiso) {
                $insert->execute([$rolId, $permiso]);
            }
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function usuario_puede_permiso($permission)
{
    if (isset($_SESSION['es_superadmin']) && (int) $_SESSION['es_superadmin'] === 1) {
        return true;
    }

    return in_array($permission, $_SESSION['user_permissions'] ?? [], true);
}
