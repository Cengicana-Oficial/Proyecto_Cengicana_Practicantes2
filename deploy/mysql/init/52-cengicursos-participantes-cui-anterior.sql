USE cengi_cursos;
-- Participantes sin documento: si el CUI/DPI/pasaporte viene vacío el sistema asigna un código
-- de referencia "NNNND" (conexion.php, cengi_cui_nd_generar). Cuando la persona entrega su
-- documento, se reemplaza y el valor anterior queda aquí para no perder la trazabilidad.
-- La aplicacion ya no crea esta estructura: solo esta migracion (AUD-2026-001/I-2).

-- Idempotente: cada columna se agrega solo si falta (actualizar-bd.sh se puede repetir).
SET @c1=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='participantes' AND COLUMN_NAME='cui_anterior');
SET @s1=IF(@c1=0,"ALTER TABLE participantes ADD COLUMN cui_anterior VARCHAR(500) NOT NULL DEFAULT ''","SELECT 1");
PREPARE st1 FROM @s1;EXECUTE st1;DEALLOCATE PREPARE st1;
