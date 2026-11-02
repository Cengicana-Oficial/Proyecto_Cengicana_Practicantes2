-- Foto de perfil del participante (Mi perfil del estudiante). Ruta /uploads/participantes/...
-- El archivo se guarda reducido y sin metadatos (estudiante_perfil_helpers.php).
SET @foto_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='participantes' AND COLUMN_NAME='foto_path');
SET @foto_sql = IF(@foto_exists=0,'ALTER TABLE participantes ADD COLUMN foto_path VARCHAR(255) NULL','SELECT 1');
PREPARE foto_stmt FROM @foto_sql;
EXECUTE foto_stmt;
DEALLOCATE PREPARE foto_stmt;
