-- Informe de gestión (informe_gestion.php): otros gastos por actividad.
--
-- Alimentación, materiales, logística, transporte, etc. registrados por curso o evento
-- para el balance del informe (los pagos a instructores salen de curso_sesiones).
--   tipo 'plan'   -> ref_id = planificacion_cursos.id
--   tipo 'curso'  -> ref_id = cursos.id (cursos creados sin planificación)
--   tipo 'evento' -> ref_id = eventos.id
--
-- Idempotente (CREATE TABLE IF NOT EXISTS), segura de aplicar más de una vez.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gestion_gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(10) NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  concepto VARCHAR(255) NOT NULL,
  categoria VARCHAR(60) NOT NULL DEFAULT 'Otro',
  documento VARCHAR(80) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gestion_gastos_ref (tipo, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
