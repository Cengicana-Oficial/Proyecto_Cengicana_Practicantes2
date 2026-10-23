USE cengi_cursos;
CREATE TABLE IF NOT EXISTS recibos_contables (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 fuente VARCHAR(12) NOT NULL,
 fuente_id INT UNSIGNED NOT NULL,
 estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
 solicitado_fecha DATE NULL,
 solicitud_referencia VARCHAR(180) NOT NULL DEFAULT '',
 responsable VARCHAR(180) NOT NULL DEFAULT '',
 numero VARCHAR(100) NOT NULL DEFAULT '',
 recibido_fecha DATE NULL,
 archivo_ruta VARCHAR(500) NOT NULL DEFAULT '',
 archivo_nombre VARCHAR(255) NOT NULL DEFAULT '',
 destinatario VARCHAR(180) NOT NULL DEFAULT '',
 enviado_fecha DATE NULL,
 actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY recibo_pago (fuente,fuente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recibos_contables_historial (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 recibo_id INT UNSIGNED NOT NULL,
 accion VARCHAR(30) NOT NULL,
 usuario VARCHAR(180) NOT NULL,
 detalle TEXT NOT NULL,
 creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY recibo_historial (recibo_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;