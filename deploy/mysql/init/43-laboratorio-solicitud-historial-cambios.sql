USE `laboratorios_prueba`;

-- Historial de cambios de las solicitudes del laboratorio.
-- Espejo de laboratorio/database/004_solicitud_historial_cambios.sql (ver comentarios alli): sin este
-- archivo una instalacion desde cero no tenia estas tablas. Idempotente.

CREATE TABLE IF NOT EXISTS solicitud_historial_cambios (
    id_historial BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_solicitud INT NOT NULL,
    tipo_muestra VARCHAR(100) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nombre VARCHAR(255) DEFAULT NULL,
    fecha_cambio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resumen VARCHAR(255) NOT NULL,
    motivo VARCHAR(500) DEFAULT NULL,
    cambios_json LONGTEXT NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    PRIMARY KEY (id_historial),
    KEY idx_solicitud_historial_solicitud_fecha (id_solicitud, fecha_cambio),
    KEY idx_solicitud_historial_usuario_fecha (usuario_id, fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
