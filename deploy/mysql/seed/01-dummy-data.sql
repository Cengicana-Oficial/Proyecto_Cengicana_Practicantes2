-- Datos opcionales para pruebas locales.
-- Todas las cuentas demo usan la contrasena temporal: CengiTest2026!

USE usuarios_menu;

INSERT INTO usuarios (
  nombre,
  correo,
  contrasena,
  rol_id,
  es_superadmin,
  ingenio_id
) VALUES
  (
    'Administrador Cursos Demo',
    'admin.cursos.test@cengicana.org',
    '$2y$10$vNepk1hJIu6goWyE3jk7duls8km5QnMcw6MzEEXayDrgHsmeDm47S',
    2,
    0,
    1
  ),
  (
    'Gestor Solicitudes Demo',
    'gestor.solicitudes.test@cengicana.org',
    '$2y$10$vNepk1hJIu6goWyE3jk7duls8km5QnMcw6MzEEXayDrgHsmeDm47S',
    12,
    0,
    1
  ),
  (
    'Instructor Cursos Demo',
    'instructor.test@cengicana.org',
    '$2y$10$vNepk1hJIu6goWyE3jk7duls8km5QnMcw6MzEEXayDrgHsmeDm47S',
    6,
    0,
    1
  ),
  (
    'Estudiante Cursos Demo',
    'estudiante.test@cengicana.org',
    '$2y$10$vNepk1hJIu6goWyE3jk7duls8km5QnMcw6MzEEXayDrgHsmeDm47S',
    11,
    0,
    2
  )
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  contrasena = VALUES(contrasena),
  rol_id = VALUES(rol_id),
  es_superadmin = VALUES(es_superadmin),
  ingenio_id = VALUES(ingenio_id);

SET @admin_cursos_id = (
  SELECT id FROM usuarios WHERE correo = 'admin.cursos.test@cengicana.org'
);
SET @gestor_solicitudes_id = (
  SELECT id FROM usuarios WHERE correo = 'gestor.solicitudes.test@cengicana.org'
);
SET @instructor_id = (
  SELECT id FROM usuarios WHERE correo = 'instructor.test@cengicana.org'
);
SET @estudiante_id = (
  SELECT id FROM usuarios WHERE correo = 'estudiante.test@cengicana.org'
);
SET @developer_id = (
  SELECT id FROM usuarios WHERE correo = 'developer@cengicana.org'
);

INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id)
SELECT @admin_cursos_id, id
FROM modulos
WHERE nombre IN ('Cursos', 'Solicitudes internas');

INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id)
SELECT @gestor_solicitudes_id, id
FROM modulos
WHERE nombre = 'Solicitudes internas';

INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id)
SELECT @instructor_id, id
FROM modulos
WHERE nombre = 'Cursos';

INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id)
SELECT @estudiante_id, id
FROM modulos
WHERE nombre = 'Cursos';

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id
FROM permisos;

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT 12, id
FROM permisos
WHERE nombre_permiso IN (
  'solicitudes_internas.crear',
  'solicitudes_internas.ver',
  'solicitudes_internas.gestionar',
  'ver_solicitudes_cengi',
  'gestionar_solicitudes_cengi',
  'editar_solicitudes_cengi',
  'aprobar_solicitudes_cengi',
  'rechazar_solicitudes_cengi'
);

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT 6, id
FROM permisos
WHERE nombre_permiso IN (
  'ver_participantes_cengi',
  'cargar_participantes_cengi',
  'ver_solicitudes_cengi',
  'gestionar_notas_cengi',
  'subir_diplomas_cengi'
);

USE cengi_cursos;

INSERT IGNORE INTO categorias_cursos (
  descripcion_categorias_cursos,
  estado_categorias_cursos
) VALUES
  ('Tecnologia', 1),
  ('Seguridad industrial', 1),
  ('Produccion agricola', 1),
  ('Laboratorio', 1);

INSERT INTO cursos (
  categoria_curso_id,
  ingenio_id,
  tipo,
  nombre_cursos,
  jornada_cursos,
  dias,
  horario,
  inicio,
  fin
)
SELECT
  ca.id,
  1,
  'Presencial',
  'Excel avanzado - Demo',
  'Matutina',
  'Lunes y miercoles',
  '08:00 - 12:00',
  CURRENT_DATE,
  DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)
FROM categorias_cursos ca
WHERE ca.descripcion_categorias_cursos = 'Tecnologia'
  AND NOT EXISTS (
    SELECT 1 FROM cursos WHERE nombre_cursos = 'Excel avanzado - Demo'
  );

INSERT INTO cursos (
  categoria_curso_id,
  ingenio_id,
  tipo,
  nombre_cursos,
  jornada_cursos,
  dias,
  horario,
  inicio,
  fin
)
SELECT
  ca.id,
  1,
  'Presencial',
  'Seguridad en campo - Demo',
  'Vespertina',
  'Martes',
  '13:00 - 16:00',
  DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY),
  DATE_ADD(CURRENT_DATE, INTERVAL 37 DAY)
FROM categorias_cursos ca
WHERE ca.descripcion_categorias_cursos = 'Seguridad industrial'
  AND NOT EXISTS (
    SELECT 1 FROM cursos WHERE nombre_cursos = 'Seguridad en campo - Demo'
  );

INSERT INTO cursos (
  categoria_curso_id,
  ingenio_id,
  tipo,
  nombre_cursos,
  jornada_cursos,
  dias,
  horario,
  inicio,
  fin
)
SELECT
  ca.id,
  2,
  'Virtual',
  'Buenas practicas agricolas - Demo',
  'Matutina',
  'Viernes',
  '09:00 - 11:00',
  DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY),
  DATE_ADD(CURRENT_DATE, INTERVAL 44 DAY)
FROM categorias_cursos ca
WHERE ca.descripcion_categorias_cursos = 'Produccion agricola'
  AND NOT EXISTS (
    SELECT 1 FROM cursos WHERE nombre_cursos = 'Buenas practicas agricolas - Demo'
  );

INSERT INTO cursos (
  categoria_curso_id,
  ingenio_id,
  tipo,
  nombre_cursos,
  jornada_cursos,
  dias,
  horario,
  inicio,
  fin
)
SELECT
  ca.id,
  1,
  'Hibrido',
  'Interpretacion de analisis de suelos - Demo',
  'Vespertina',
  'Jueves',
  '14:00 - 17:00',
  DATE_ADD(CURRENT_DATE, INTERVAL 21 DAY),
  DATE_ADD(CURRENT_DATE, INTERVAL 51 DAY)
FROM categorias_cursos ca
WHERE ca.descripcion_categorias_cursos = 'Laboratorio'
  AND NOT EXISTS (
    SELECT 1 FROM cursos
    WHERE nombre_cursos = 'Interpretacion de analisis de suelos - Demo'
  );

INSERT INTO participantes (
  ingenio_id,
  usuarios_id,
  cui_participantes,
  nombre_participantes,
  puesto_participantes,
  area_participantes,
  estado_participantes
) VALUES
  (1, @estudiante_id, 'TEST-CUI-001', 'Ana Perez Demo', 'Analista', 'Agronomia', 1),
  (1, NULL, 'TEST-CUI-002', 'Carlos Mendez Demo', 'Tecnico', 'Campo', 1),
  (1, NULL, 'TEST-CUI-003', 'Diana Lopez Demo', 'Coordinadora', 'Capacitacion', 1),
  (2, NULL, 'TEST-CUI-004', 'Ernesto Garcia Demo', 'Supervisor', 'Produccion', 1),
  (2, NULL, 'TEST-CUI-005', 'Fatima Ruiz Demo', 'Auxiliar', 'Calidad', 1),
  (3, NULL, 'TEST-CUI-006', 'Gabriel Santos Demo', 'Tecnico', 'Riego', 1),
  (4, NULL, 'TEST-CUI-007', 'Helena Castillo Demo', 'Analista', 'Laboratorio', 1),
  (5, NULL, 'TEST-CUI-008', 'Ivan Morales Demo', 'Encargado', 'Seguridad', 0)
ON DUPLICATE KEY UPDATE
  ingenio_id = VALUES(ingenio_id),
  usuarios_id = VALUES(usuarios_id),
  nombre_participantes = VALUES(nombre_participantes),
  puesto_participantes = VALUES(puesto_participantes),
  area_participantes = VALUES(area_participantes),
  estado_participantes = VALUES(estado_participantes);

INSERT IGNORE INTO asignaciones (
  participantes_id,
  usuarios_id,
  cursos_id,
  estado_asignaciones
)
SELECT p.id, COALESCE(p.usuarios_id, @instructor_id), c.id, 1
FROM participantes p
JOIN cursos c ON c.nombre_cursos = 'Excel avanzado - Demo'
WHERE p.cui_participantes IN ('TEST-CUI-001', 'TEST-CUI-002', 'TEST-CUI-003');

INSERT IGNORE INTO asignaciones (
  participantes_id,
  usuarios_id,
  cursos_id,
  estado_asignaciones
)
SELECT p.id, COALESCE(p.usuarios_id, @instructor_id), c.id, 1
FROM participantes p
JOIN cursos c ON c.nombre_cursos = 'Buenas practicas agricolas - Demo'
WHERE p.cui_participantes IN ('TEST-CUI-004', 'TEST-CUI-005');

INSERT IGNORE INTO asignaciones (
  participantes_id,
  usuarios_id,
  cursos_id,
  estado_asignaciones
)
SELECT p.id, COALESCE(p.usuarios_id, @instructor_id), c.id, 1
FROM participantes p
JOIN cursos c ON c.nombre_cursos = 'Interpretacion de analisis de suelos - Demo'
WHERE p.cui_participantes IN ('TEST-CUI-006', 'TEST-CUI-007');

INSERT INTO control_cursos (
  asignacion_id,
  asistencia,
  evaluacion,
  posevaluacion,
  diploma
)
SELECT
  a.id,
  CASE WHEN MOD(a.id, 2) = 0 THEN '90' ELSE '100' END,
  CASE WHEN MOD(a.id, 2) = 0 THEN '78' ELSE '85' END,
  CASE WHEN MOD(a.id, 2) = 0 THEN '88' ELSE '92' END,
  ''
FROM asignaciones a
JOIN participantes p ON p.id = a.participantes_id
WHERE p.cui_participantes LIKE 'TEST-CUI-%'
ON DUPLICATE KEY UPDATE
  asistencia = VALUES(asistencia),
  evaluacion = VALUES(evaluacion),
  posevaluacion = VALUES(posevaluacion);

INSERT INTO asistencia (id_asignacion, fecha)
SELECT a.id, CURRENT_DATE
FROM asignaciones a
JOIN participantes p ON p.id = a.participantes_id
WHERE p.cui_participantes LIKE 'TEST-CUI-%'
  AND NOT EXISTS (
    SELECT 1
    FROM asistencia asi
    WHERE asi.id_asignacion = a.id
      AND asi.fecha = CURRENT_DATE
  );

INSERT INTO solicitudes_inscripcion (
  nombre_participante,
  cui_participante,
  puesto_participante,
  area_participante,
  correo,
  telefono,
  ingenio_id,
  curso_id,
  tipo_pago,
  estado
)
SELECT
  'Julia Ramirez Demo',
  'TEST-SOL-CUI-001',
  'Asistente',
  'Administracion',
  'julia.ramirez.test@example.test',
  '5555-0101',
  1,
  c.id,
  'Ingenio',
  'Pendiente'
FROM cursos c
WHERE c.nombre_cursos = 'Excel avanzado - Demo'
  AND NOT EXISTS (
    SELECT 1 FROM solicitudes_inscripcion
    WHERE cui_participante = 'TEST-SOL-CUI-001'
  );

INSERT INTO solicitudes_inscripcion (
  nombre_participante,
  cui_participante,
  puesto_participante,
  area_participante,
  correo,
  telefono,
  ingenio_id,
  curso_id,
  tipo_pago,
  estado
)
SELECT
  'Kevin Ortiz Demo',
  'TEST-SOL-CUI-002',
  'Tecnico',
  'Produccion',
  'kevin.ortiz.test@example.test',
  '5555-0102',
  2,
  c.id,
  'Propio',
  'Pendiente'
FROM cursos c
WHERE c.nombre_cursos = 'Buenas practicas agricolas - Demo'
  AND NOT EXISTS (
    SELECT 1 FROM solicitudes_inscripcion
    WHERE cui_participante = 'TEST-SOL-CUI-002'
  );

INSERT INTO solicitudes_inscripcion (
  nombre_participante,
  cui_participante,
  puesto_participante,
  area_participante,
  correo,
  telefono,
  ingenio_id,
  curso_id,
  tipo_pago,
  estado
)
SELECT
  'Laura Chavez Demo',
  'TEST-SOL-CUI-003',
  'Analista',
  'Laboratorio',
  'laura.chavez.test@example.test',
  '5555-0103',
  1,
  c.id,
  'Ingenio',
  'Rechazado'
FROM cursos c
WHERE c.nombre_cursos = 'Interpretacion de analisis de suelos - Demo'
  AND NOT EXISTS (
    SELECT 1 FROM solicitudes_inscripcion
    WHERE cui_participante = 'TEST-SOL-CUI-003'
  );

USE sistema_solicitudes;

INSERT INTO usuario_programa (usuario_id, programa_id)
VALUES
  (@developer_id, 8),
  (@admin_cursos_id, 6),
  (@gestor_solicitudes_id, 3)
ON DUPLICATE KEY UPDATE
  programa_id = VALUES(programa_id);

INSERT INTO solicitudes (
  codigo,
  solicitante_id,
  programa_origen_id,
  programa_destino_id,
  tipo,
  prioridad,
  estado,
  titulo,
  descripcion,
  fecha_requerida,
  proveedor_sugerido,
  monto_estimado
) VALUES
  (
    'TEST-2026-001',
    @gestor_solicitudes_id,
    3,
    6,
    'compra',
    'alta',
    'recibido',
    'Compra de equipo de computo - Demo',
    'Solicitud ficticia para validar el flujo de compras.',
    DATE_ADD(CURRENT_DATE, INTERVAL 15 DAY),
    'Proveedor Demo, S.A.',
    12500.00
  ),
  (
    'TEST-2026-002',
    @admin_cursos_id,
    6,
    6,
    'ti',
    'media',
    'proceso',
    'Configuracion de acceso remoto - Demo',
    'Caso ficticio para comprobar estados y seguimientos de TI.',
    DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY),
    NULL,
    NULL
  ),
  (
    'TEST-2026-003',
    @gestor_solicitudes_id,
    3,
    7,
    'apoyo',
    'baja',
    'completado',
    'Apoyo para toma de muestras - Demo',
    'Solicitud ficticia completada para validar filtros del historial.',
    DATE_SUB(CURRENT_DATE, INTERVAL 3 DAY),
    NULL,
    850.00
  ),
  (
    'TEST-2026-004',
    @developer_id,
    8,
    6,
    'compra',
    'alta',
    'rechazado',
    'Compra urgente de suministros - Demo',
    'Solicitud ficticia rechazada para validar el estado correspondiente.',
    DATE_ADD(CURRENT_DATE, INTERVAL 2 DAY),
    'Distribuidora de Prueba',
    3200.00
  ),
  (
    'TEST-2026-005',
    @gestor_solicitudes_id,
    3,
    8,
    'apoyo',
    'media',
    'proceso',
    'Apoyo para evento de capacitacion - Demo',
    'Solicitud ficticia para validar asignaciones entre programas.',
    DATE_ADD(CURRENT_DATE, INTERVAL 25 DAY),
    NULL,
    5000.00
  )
ON DUPLICATE KEY UPDATE
  solicitante_id = VALUES(solicitante_id),
  programa_origen_id = VALUES(programa_origen_id),
  programa_destino_id = VALUES(programa_destino_id),
  tipo = VALUES(tipo),
  prioridad = VALUES(prioridad),
  estado = VALUES(estado),
  titulo = VALUES(titulo),
  descripcion = VALUES(descripcion),
  fecha_requerida = VALUES(fecha_requerida),
  proveedor_sugerido = VALUES(proveedor_sugerido),
  monto_estimado = VALUES(monto_estimado);

INSERT INTO seguimientos (
  solicitud_id,
  usuario_id,
  estado,
  observacion
)
SELECT s.id, @gestor_solicitudes_id, s.estado, 'Seguimiento inicial generado como dato de prueba.'
FROM solicitudes s
WHERE s.codigo LIKE 'TEST-2026-%'
  AND NOT EXISTS (
    SELECT 1
    FROM seguimientos seg
    WHERE seg.solicitud_id = s.id
      AND seg.observacion = 'Seguimiento inicial generado como dato de prueba.'
  );

INSERT INTO seguimientos (
  solicitud_id,
  usuario_id,
  estado,
  observacion
)
SELECT s.id, @admin_cursos_id, 'proceso', 'Revision administrativa ficticia completada.'
FROM solicitudes s
WHERE s.codigo IN ('TEST-2026-002', 'TEST-2026-005')
  AND NOT EXISTS (
    SELECT 1
    FROM seguimientos seg
    WHERE seg.solicitud_id = s.id
      AND seg.observacion = 'Revision administrativa ficticia completada.'
  );
