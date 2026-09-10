USE `laboratorios_prueba`;

-- Campos personalizados por tipo de muestra (pestana "Campos por tipo de
-- muestra" del catalogo de analisis).
-- Espejo de laboratorio/database/005_campos_tipo_muestra.sql: el modulo de
-- laboratorio ya crea esta tabla en caliente al abrir la pagina
-- (labCamposMuestraAsegurarEsquema), este archivo solo cubre la instalacion
-- desde cero.
--
-- Orden de ejecucion (docker-entrypoint-initdb.d corre los .sql en orden por
-- nombre de archivo): 11-laboratorio-schema.sql ya creo la tabla tipo_muestra
-- antes de que este archivo (16-...) se ejecute.

CREATE TABLE IF NOT EXISTS tipo_muestra_campo (
    id_campo INT NOT NULL AUTO_INCREMENT,
    id_tipo_muestra INT NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    etiqueta VARCHAR(150) NOT NULL,
    tipo_dato VARCHAR(20) NOT NULL DEFAULT 'texto',
    unidad VARCHAR(40) DEFAULT NULL,
    obligatorio TINYINT(1) NOT NULL DEFAULT 0,
    orden INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    opciones TEXT DEFAULT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_campo),
    UNIQUE KEY uq_tipo_muestra_campo_nombre (id_tipo_muestra, nombre),
    KEY idx_tipo_muestra_campo_tipo_orden (id_tipo_muestra, orden),
    CONSTRAINT fk_tipo_muestra_campo_tipo
        FOREIGN KEY (id_tipo_muestra) REFERENCES tipo_muestra (id_tipo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
