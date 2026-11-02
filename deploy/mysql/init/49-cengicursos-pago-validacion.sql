USE cengi_cursos;
SET @pago_cols=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='solicitudes_inscripcion' AND COLUMN_NAME='pago_validado_rol');
SET @pago_ddl=IF(@pago_cols=0,"ALTER TABLE solicitudes_inscripcion ADD pago_validado_por VARCHAR(255) NOT NULL DEFAULT '', ADD pago_validado_rol VARCHAR(30) NOT NULL DEFAULT '', ADD pago_validado_en DATETIME NULL, ADD pago_validacion_evidencia TEXT NULL","SELECT 1");
PREPARE pago_stmt FROM @pago_ddl;EXECUTE pago_stmt;DEALLOCATE PREPARE pago_stmt;
