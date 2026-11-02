CREATE TABLE IF NOT EXISTS recibos_contables_correo_config (
 id TINYINT PRIMARY KEY,
 destinatarios TEXT NOT NULL,
 responder_a VARCHAR(180) NOT NULL DEFAULT '',
 automatico TINYINT NOT NULL DEFAULT 0,
 desde DATE NOT NULL,
 actualizado_por VARCHAR(180) NOT NULL DEFAULT '',
 actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recibos_contables_solicitudes_correo (
 recibo_id INT UNSIGNED PRIMARY KEY,
 token CHAR(32) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recibos_contables_envios (
 recibo_id INT UNSIGNED NOT NULL,
 destinatario VARCHAR(180) NOT NULL,
 enviado DATETIME NULL,
 error TEXT NOT NULL,
 PRIMARY KEY(recibo_id,destinatario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;