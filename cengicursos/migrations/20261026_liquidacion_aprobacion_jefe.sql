-- Liquidaciones: el Jefe de Transferencia aprueba la liquidación antes de enviarla a Administración.
-- Estados nuevos (liquidaciones.estado es VARCHAR): 'revision' (por aprobar del Jefe) y 'autorizada'
-- (aprobada por el Jefe, lista para enviar). Si el Jefe la devuelve, queda su observación.
-- La aplicacion ya no crea esta estructura: solo esta migracion (AUD-2026-001/I-2).

-- Idempotente: cada columna se agrega solo si falta (actualizar-bd.sh se puede repetir).
SET @c1=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidaciones' AND COLUMN_NAME='observacion_jefe');
SET @s1=IF(@c1=0,"ALTER TABLE liquidaciones ADD COLUMN observacion_jefe VARCHAR(500) NOT NULL DEFAULT ''","SELECT 1");
PREPARE st1 FROM @s1;EXECUTE st1;DEALLOCATE PREPARE st1;
