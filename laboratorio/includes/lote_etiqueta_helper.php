<?php
/**
 * Texto "Lote <código>" sin repetir la palabra cuando el código ya la trae
 * (p. ej. el lote "Lote-001" se muestra "Lote-001", no "Lote Lote-001").
 */
if (!function_exists('lab_etiqueta_lote')) {
    function lab_etiqueta_lote($codigo, string $vacio = 'Sin código'): string
    {
        $c = trim((string) $codigo);
        if ($c === '') {
            return 'Lote ' . $vacio;
        }
        return preg_match('/^lote\b/iu', $c) ? $c : 'Lote ' . $c;
    }
}

if (!function_exists('lab_etiqueta_lote_sql')) {
    /** Misma regla en SQL, para consultas que arman la referencia del lote. */
    function lab_etiqueta_lote_sql(string $columna): string
    {
        return "CASE WHEN LOWER(TRIM(COALESCE($columna, ''))) LIKE 'lote%' THEN TRIM($columna) ELSE CONCAT('Lote ', COALESCE($columna, '—')) END";
    }
}
