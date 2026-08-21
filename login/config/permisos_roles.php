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
        'solicitudes_internas.crear' => 'Permite crear solicitudes en el modulo Solicitudes internas',
        'solicitudes_internas.ver' => 'Permite ver las solicitudes del modulo Sistema de solicitudes',
        'solicitudes_internas.gestionar' => 'Permite gestionar solicitudes del modulo Sistema de solicitudes',
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
        'Permisos de laboratorio' => 'Controla el acceso a lotes, LABC, formularios, revision y controles de laboratorio.',
        'Permisos de SIGEC' => 'Controla el acceso al modulo SIGEC (Sistema de Gestion de Ensayos Experimentales).',
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
        'solicitudes_internas.crear' => 'Crear solicitudes del modulo',
        'solicitudes_internas.ver' => 'Ver solicitudes del modulo',
        'solicitudes_internas.gestionar' => 'Gestionar solicitudes del modulo',
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

    if (isset($mapa[$nombrePermiso])) {
        return $mapa[$nombrePermiso];
    }

    return ucwords(str_replace('_', ' ', (string) $nombrePermiso));
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
