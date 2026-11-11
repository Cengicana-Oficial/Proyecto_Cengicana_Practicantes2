-- Modalidad asincronica en la misma aula del curso.
-- Un estudiante marcado como asincronico avanza viendo las grabaciones de las sesiones: al ver al menos el 70 %
-- de cada grabacion la marca como vista; cuando ve todas las de una sesion, esa sesion cuenta como asistida
-- (origen "grabacion") y alimenta el mismo porcentaje de asistencia de Seguimiento y del diploma.
-- Las evaluaciones de cada modulo se le habilitan al terminar las grabaciones de ese modulo.
-- Idempotente: se puede ejecutar mas de una vez.
SET NAMES utf8mb4;

-- Modalidad por inscripcion (sin fila = sincronica). Plazo opcional para completar el curso.
CREATE TABLE IF NOT EXISTS aula_modalidad (
  asignacion_id INT UNSIGNED NOT NULL PRIMARY KEY,
  modalidad ENUM('sincronica','asincronica') NOT NULL DEFAULT 'sincronica',
  plazo DATE NULL,
  actualizado_por INT NULL,
  actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Avance de cada estudiante en cada grabacion.
--   segmentos: mapa de bits (base64) de los tramos de 5 s reproducidos (YouTube, Vimeo, MP4).
--   segundos:  tiempo con el reproductor abierto (Google Drive u otros enlaces sin medicion).
CREATE TABLE IF NOT EXISTS aula_video_avance (
  asignacion_id INT UNSIGNED NOT NULL,
  recurso_id INT UNSIGNED NOT NULL,
  segmentos TEXT NULL,
  segundos INT UNSIGNED NOT NULL DEFAULT 0,
  duracion INT UNSIGNED NOT NULL DEFAULT 0,
  porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
  visto_en DATETIME NULL,
  actualizado DATETIME NOT NULL,
  PRIMARY KEY (asignacion_id, recurso_id),
  KEY aula_video_avance_recurso (recurso_id),
  FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id) ON DELETE CASCADE,
  FOREIGN KEY (recurso_id) REFERENCES aula_recursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asistencia registrada al ver las grabaciones.
ALTER TABLE aula_asistencias MODIFY origen ENUM('estudiante','coordinacion','grabacion') NOT NULL DEFAULT 'estudiante';

-- Duracion de la grabacion en minutos: necesaria solo para enlaces que no informan su duracion (Google Drive, otros).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_recursos' AND COLUMN_NAME='duracion_min');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_recursos ADD COLUMN duracion_min SMALLINT UNSIGNED NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
