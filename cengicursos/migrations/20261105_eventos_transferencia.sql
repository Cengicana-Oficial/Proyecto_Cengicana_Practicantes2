-- Vinculo del evento (Control QR) con un evento de transferencia de Comites
-- (sistema_comites.eventos_transferencia.id). Comites lee esta columna y copia
-- como asistentes a los participantes con ingreso registrado.
SET @transf_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eventos' AND COLUMN_NAME='transferencia_id');
SET @transf_sql = IF(@transf_exists=0,'ALTER TABLE eventos ADD COLUMN transferencia_id INT NULL, ADD UNIQUE KEY uq_eventos_transferencia (transferencia_id)','SELECT 1');
PREPARE transf_stmt FROM @transf_sql;
EXECUTE transf_stmt;
DEALLOCATE PREPARE transf_stmt;
