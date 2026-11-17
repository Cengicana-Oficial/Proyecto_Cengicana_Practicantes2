USE cengi_cursos;
-- Limite de intentos de la encuesta publica general (QR) de un evento (AUD-2026-003/A-1).
-- Cada consulta de correo o envio del formulario de identificacion registra una fila por IP (hash SHA-256,
-- no se guarda la IP) y evento; encuesta_evento.php responde 429 si en 10 minutos se superan 120 intentos.
-- Las filas con mas de un dia se borran solas. Idempotente: se puede ejecutar mas de una vez.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS evento_encuesta_intentos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_enc_intentos (evento_id, ip_hash, creado_en),
  KEY idx_enc_intentos_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
