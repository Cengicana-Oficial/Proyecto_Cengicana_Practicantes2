-- Pago por persona en las liquidaciones de instructores y soporte (pago_instructores.php, pestaña
-- "Pagos"): monto realmente pagado, forma, fecha, referencia y comentario de cada fila, para
-- registrar pagos de varias personas a la vez y llevar el balance. Cuando todas las filas de
-- una liquidación quedan pagadas, la liquidación pasa a "pagada".
SET @t = 'liquidacion_detalle';
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@t AND COLUMN_NAME='pago_monto');
SET @s = IF(@c=0, 'ALTER TABLE liquidacion_detalle
    ADD COLUMN pago_monto DECIMAL(12,2) NULL AFTER documento_numero,
    ADD COLUMN pago_forma VARCHAR(60) NOT NULL DEFAULT '''' AFTER pago_monto,
    ADD COLUMN pago_referencia VARCHAR(120) NOT NULL DEFAULT '''' AFTER pago_forma,
    ADD COLUMN pago_fecha DATE NULL AFTER pago_referencia,
    ADD COLUMN pago_nota VARCHAR(500) NOT NULL DEFAULT '''' AFTER pago_fecha,
    ADD COLUMN pagado_en DATETIME NULL AFTER pago_nota,
    ADD COLUMN pagado_por VARCHAR(255) NOT NULL DEFAULT '''' AFTER pagado_en', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Liquidaciones ya pagadas completas: cada fila queda con el pago de su liquidación.
UPDATE liquidacion_detalle d INNER JOIN liquidaciones l ON l.id = d.liquidacion_id
SET d.pago_monto = d.total, d.pago_forma = l.pago_forma, d.pago_referencia = l.pago_referencia, d.pago_fecha = l.pago_fecha,
    d.pagado_en = COALESCE(l.pagada_en, NOW()), d.pagado_por = l.pagada_por
WHERE l.estado = 'pagada' AND d.pagado_en IS NULL;
