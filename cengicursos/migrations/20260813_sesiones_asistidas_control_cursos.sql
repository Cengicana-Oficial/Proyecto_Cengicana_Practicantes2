-- "Sesiones asistidas": nueva columna editable manualmente (numero entero, sin relacion
-- automatica con el porcentaje de "asistencia") que se muestra al lado de la columna
-- "Asistencia" en cengicursos/ver_participante_curso.php, cengicursos/participantes.php
-- y el dashboard "Mi Ingenio" (cengicursos/dashboard_ingenio.php /
-- cengicursos/exportardashboardingenio.php).
--
-- Se agrega tanto al resumen del curso completo (control_cursos) como al detalle por
-- modulo (control_curso_modulos), igual que "asistencia" ya existe en ambas tablas.
--
-- Es aditiva e idempotente (ALTER TABLE ... ADD COLUMN via PREPARE/EXECUTE condicionado
-- a information_schema.COLUMNS), mismo patron que
-- cengicursos/migrations/20260811_recordatorio_cupo_cursos.sql.
--
-- Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/08-cengicursos-sesiones-asistidas.sql para que una instalacion nueva
-- ya incluya esta columna desde el CREATE TABLE + ALTER TABLE inicial.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260813_sesiones_asistidas_control_cursos.sql

USE cengi_cursos;

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
