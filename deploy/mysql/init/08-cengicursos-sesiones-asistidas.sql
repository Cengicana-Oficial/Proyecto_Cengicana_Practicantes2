USE cengi_cursos;

-- "Sesiones asistidas": columna editable manualmente (numero entero, sin relacion
-- automatica con el porcentaje de "asistencia") que se muestra al lado de la columna
-- "Asistencia" en cengicursos/ver_participante_curso.php, cengicursos/participantes.php
-- y el dashboard "Mi Ingenio" (cengicursos/dashboard_ingenio.php /
-- cengicursos/exportardashboardingenio.php). Se agrega tanto al resumen del curso
-- completo (control_cursos) como al detalle por modulo (control_curso_modulos), igual
-- que "asistencia" ya existe en ambas tablas.
-- Idempotente, mismo patron PREPARE/EXECUTE que
-- deploy/mysql/init/07-cengicursos-recordatorio-cupo.sql.
SET @col_sesiones_asistidas_control_cursos := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'control_cursos' AND COLUMN_NAME = 'sesiones_asistidas'
);
SET @ddl_sesiones_asistidas_control_cursos := IF(
  @col_sesiones_asistidas_control_cursos = 0,
  'ALTER TABLE control_cursos ADD COLUMN sesiones_asistidas SMALLINT UNSIGNED NULL DEFAULT NULL AFTER asistencia',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_sesiones_asistidas_control_cursos; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_sesiones_asistidas_control_curso_modulos := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'control_curso_modulos' AND COLUMN_NAME = 'sesiones_asistidas'
);
SET @ddl_sesiones_asistidas_control_curso_modulos := IF(
  @col_sesiones_asistidas_control_curso_modulos = 0,
  'ALTER TABLE control_curso_modulos ADD COLUMN sesiones_asistidas SMALLINT UNSIGNED NULL DEFAULT NULL AFTER asistencia',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_sesiones_asistidas_control_curso_modulos; EXECUTE stmt; DEALLOCATE PREPARE stmt;
