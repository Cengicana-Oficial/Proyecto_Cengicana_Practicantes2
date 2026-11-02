-- Sesiones por modulo (Programa de sesiones): cada modulo del curso indica cuantas
-- sesiones tiene. Con eso las sesiones del curso, ordenadas por fecha, se asignan solas
-- a su modulo (sesiones 1-6 -> modulo 1, 7-12 -> modulo 2...) y se vuelven a asignar
-- cuando una sesion se corre de fecha, se cancela o se agrega
-- (cengi_pp_asignar_modulos() en planificacion_helpers.php).
--
-- NULL = sin definir: mientras algun modulo no lo indique, el modulo de cada sesion se
-- elige a mano como antes. Antes de que exista el curso, el plan guarda lo mismo en
-- planificacion_cursos.modulos ("Nombre|sesiones" por linea) y se copia al crear el curso.
--
-- Idempotente. Espejo en deploy/mysql/init/29-cengicursos-modulos-sesiones.sql.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe:
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20261015_curso_modulos_sesiones.sql

SET NAMES utf8mb4;

SET @col_modulo_sesiones := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curso_modulos' AND COLUMN_NAME = 'sesiones'
);
SET @ddl_modulo_sesiones := IF(
  @col_modulo_sesiones = 0,
  'ALTER TABLE curso_modulos ADD COLUMN sesiones SMALLINT UNSIGNED NULL AFTER horas',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_modulo_sesiones; EXECUTE stmt; DEALLOCATE PREPARE stmt;
