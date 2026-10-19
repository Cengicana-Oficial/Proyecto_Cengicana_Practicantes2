-- Participantes sin documento: si el CUI/DPI/pasaporte viene vacío el sistema asigna un código
-- de referencia "NNNND" (conexion.php, cengi_cui_nd_generar). Cuando la persona entrega su
-- documento, se reemplaza y el valor anterior queda aquí para no perder la trazabilidad.
-- conexion.php (cengi_cui_asegurar_historial) crea la columna si falta.

ALTER TABLE participantes ADD COLUMN cui_anterior VARCHAR(500) NOT NULL DEFAULT '';
