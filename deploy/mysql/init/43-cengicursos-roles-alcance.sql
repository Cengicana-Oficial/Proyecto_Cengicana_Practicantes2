USE usuarios_menu;
-- Roles del módulo Cursos (base usuarios_menu). Se puede ejecutar varias veces.
--
--   Estudiante                           participante de cursos: entra al aula virtual (ya existía).
--   Gestor de capacitación de ingenio    gestiona la capacitación de SU ingenio (usuarios.ingenio_id):
--                                        participantes, inscripciones, notas y diplomas, solo de su ingenio.
--                                        Reemplaza al rol "Administrador de ingenio" (mismo alcance).
--   Coordinador de capacitación          administra todo Cursos y ve todos los ingenios
--                                        (permiso administrar_cursos_cengi, ver cengi_es_admin()).
--   Jefe de transferencia de tecnología  aprueba y firma liquidaciones y valida recibos de pago a
--                                        instructores; consulta cursos, instructores y reportes.
--
-- Cada rol pertenece al módulo Cursos (rol_modulo): así aparece al asignar el módulo Cursos a un
-- usuario en login/usuarios/usuarios.php. Los permisos se afinan en login/roles/roles.php.

INSERT IGNORE INTO permisos (nombre_permiso, descripcion) VALUES
    ('administrar_cursos_cengi', 'Coordinacion de capacitacion: administra todo el modulo de Cursos y ve todos los ingenios');

-- "Administrador de ingenio" pasa a llamarse "Gestor de capacitación de ingenio" (conserva usuarios y permisos).
UPDATE roles SET nombre_rol = 'Gestor de capacitación de ingenio'
    WHERE nombre_rol = 'Administrador de ingenio'
      AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM roles WHERE nombre_rol = 'Gestor de capacitación de ingenio') AS ya);

INSERT IGNORE INTO roles (nombre_rol) VALUES
    ('Estudiante'),
    ('Gestor de capacitación de ingenio'),
    ('Coordinador de capacitación'),
    ('Jefe de transferencia de tecnología');

INSERT IGNORE INTO rol_modulo (rol_id, modulo_id)
SELECT r.id, m.id FROM roles r INNER JOIN modulos m ON LOWER(m.nombre) IN ('cursos', 'cengicursos')
WHERE r.nombre_rol IN ('Estudiante', 'Gestor de capacitación de ingenio', 'Coordinador de capacitación', 'Jefe de transferencia de tecnología');

-- Coordinador de capacitación: administración completa de Cursos.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r INNER JOIN permisos p ON p.nombre_permiso IN (
    'administrar_cursos_cengi', 'ver_cursos_cengi', 'gestionar_cursos_cengi',
    'ver_participantes_cengi', 'gestionar_participantes_cengi', 'cargar_participantes_cengi', 'editar_participantes_cengi', 'eliminar_participantes_cengi',
    'ver_solicitudes_cengi', 'gestionar_solicitudes_cengi', 'editar_solicitudes_cengi', 'aprobar_solicitudes_cengi', 'rechazar_solicitudes_cengi',
    'gestionar_notas_cengi', 'subir_diplomas_cengi', 'ver_diplomas_cengi', 'gestionar_diplomas_cengi',
    'ver_instructores_cengi', 'gestionar_instructores_cengi', 'ver_organizaciones_cengi', 'gestionar_organizaciones_cengi',
    'ver_eventos_cengi', 'gestionar_eventos_cengi', 'ver_pagos_cengi', 'validar_pagos_cengi', 'ver_reportes_cengi',
    'ver_ingenios_cengi', 'gestionar_ingenios_cengi', 'ver_usuarios_cengi', 'gestionar_usuarios_cengi', 'ver_roles_cengi')
WHERE r.nombre_rol = 'Coordinador de capacitación';

-- Gestor de capacitación de ingenio: lo de su ingenio (el alcance lo pone usuarios.ingenio_id).
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r INNER JOIN permisos p ON p.nombre_permiso IN (
    'ver_cursos_cengi', 'ver_participantes_cengi', 'cargar_participantes_cengi', 'editar_participantes_cengi',
    'ver_solicitudes_cengi', 'editar_solicitudes_cengi', 'gestionar_notas_cengi', 'subir_diplomas_cengi',
    'ver_diplomas_cengi', 'ver_instructores_cengi', 'ver_eventos_cengi')
WHERE r.nombre_rol = 'Gestor de capacitación de ingenio';

-- Jefe de transferencia de tecnología: administración completa, incluida aprobación y firma.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r INNER JOIN permisos p ON p.nombre_permiso IN (
    'firmar_liquidaciones_cengi', 'ver_cursos_cengi', 'ver_instructores_cengi', 'ver_reportes_cengi')
WHERE r.nombre_rol = 'Jefe de transferencia de tecnología';

-- Alcance solicitado: jefe y coordinador administran todo Cursos; gestor solo su ingenio.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
WHERE r.nombre_rol IN ('Coordinador de capacitación', 'Jefe de transferencia de tecnología')
  AND p.nombre_permiso IN ('administrar_cursos_cengi', 'firmar_liquidaciones_cengi');
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
WHERE r.nombre_rol = 'Gestor de capacitación de ingenio'
  AND p.nombre_permiso IN ('ver_pagos_cengi', 'ver_usuarios_cengi', 'gestionar_usuarios_cengi');
DELETE rp FROM rol_permiso rp JOIN roles r ON r.id=rp.rol_id JOIN permisos p ON p.id=rp.permiso_id
WHERE r.nombre_rol='Gestor de capacitación de ingenio'
 AND p.nombre_permiso IN ('administrar_cursos_cengi','validar_pagos_cengi','roles_gestionar_cengi','gestionar_usuarios','gestionar_roles');