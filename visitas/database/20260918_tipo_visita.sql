-- Distingue las solicitudes directas al Museo del Azúcar de las visitas a Cengicaña.
-- Las solicitudes existentes se conservan como visitas a Cengicaña.
-- El bloque es idempotente: puede ejecutarse nuevamente sin duplicar la columna.
SET @tipo_visita_existe = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'solicitudes'
      AND COLUMN_NAME = 'tipo_visita'
);

SET @tipo_visita_sql = IF(
    @tipo_visita_existe = 0,
    'ALTER TABLE solicitudes ADD COLUMN tipo_visita ENUM(''cengicana'', ''museo_azucar'') NOT NULL DEFAULT ''cengicana'' AFTER id_solicitante',
    'SELECT ''La columna tipo_visita ya existe'' AS resultado'
);

PREPARE tipo_visita_stmt FROM @tipo_visita_sql;
EXECUTE tipo_visita_stmt;
DEALLOCATE PREPARE tipo_visita_stmt;
