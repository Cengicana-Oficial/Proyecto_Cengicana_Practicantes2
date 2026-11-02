-- Editar evento (eventos_qr.php): imagen y logo del evento, como en el prototipo.
-- imagen: portada del formulario publico de inscripcion y fondo predeterminado de la
--   constancia (Certificacion). logo: gafete (PDF e impreso) y constancia.
-- Se guardan como archivos en uploads/eventos/ (rutas /uploads/...), ya reducidos en el navegador.
--
-- Es idempotente. Espejo en deploy/mysql/init/27-cengicursos-eventos-imagen-logo.sql.

SET NAMES utf8mb4;

SET @col_ev_img := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'imagen'
);
SET @ddl_ev_img := IF(
  @col_ev_img = 0,
  'ALTER TABLE eventos ADD COLUMN imagen VARCHAR(255) NULL, ADD COLUMN logo VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_ev_img; EXECUTE stmt; DEALLOCATE PREPARE stmt;
