<?php
/**
 * Indica si solicitud_museo ya tiene las columnas de 40_visit_payment_details.sql
 * (monto_pagado, observacion_pago, ...). Permite que Pagos siga funcionando
 * mientras la migración no se haya aplicado.
 */
function visitas_payment_details_available(PDO $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_name = 'solicitud_museo'
               AND column_name IN ('monto_pagado', 'observacion_pago', 'fecha_pago', 'pago_actualizado_por', 'pago_actualizado_en')"
        );
        $stmt->execute();
        $available = (int) $stmt->fetchColumn() >= 5;
    } catch (Throwable $error) {
        $available = false;
    }
    return $available;
}
