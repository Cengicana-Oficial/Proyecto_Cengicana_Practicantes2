<?php
/**
 * bandeja_analista_model.php
 *
 * Cola de trabajo pendiente por analista/tipo de muestra ("Bandeja del
 * analista" en el sidebar). Extraida de view/solicitudes_pendientes_tecnico.php
 * (misma consulta exacta) para poder reutilizar el mismo conteo real como
 * badge del menu sin duplicar el SQL.
 */

if (!function_exists('lab_bandeja_analista_pendientes')) {
    /**
     * Una fila por cada combinacion lote + solicitud + tipo de muestra que
     * todavia tiene analisis solicitados (solicitud_analisis) sin
     * formulario ingresado en ese rango de lote.
     */
    function lab_bandeja_analista_pendientes(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                l.id_lote,
                l.codigo_lote,
                s.id_solicitud,
                s.fecha_ingreso,
                s.fecha_estimada,
                s.numero_muestras,
                tm.id_tipo AS id_tipo_muestra,
                tm.nombre AS tipo_muestra,
                COUNT(DISTINCT ta.id_tipo) AS total_pendientes,
                GROUP_CONCAT(DISTINCT ta.nombre ORDER BY ta.nombre SEPARATOR '||') AS analisis_pendientes
            FROM solicitud s
            INNER JOIN lote l
                ON l.id_lote = s.id_lote
            INNER JOIN tipo_muestra tm
                ON tm.id_tipo = s.id_tipo
            INNER JOIN solicitud_analisis sa
                ON sa.id_solicitud = s.id_solicitud
            INNER JOIN tipo_analisis ta
                ON ta.id_tipo = sa.id_tipo_analisis
            WHERE NOT EXISTS (
                SELECT 1
                FROM formulario f_done
                LEFT JOIN estado_formulario ef_done ON ef_done.id_estado = f_done.id_estado
                WHERE f_done.id_formulario = (
                    SELECT MAX(f_latest.id_formulario)
                    FROM muestra m_scope
                    INNER JOIN lote_rango lr_scope
                        ON lr_scope.id_lote = s.id_lote
                       AND m_scope.numero_muestra BETWEEN lr_scope.inicio AND lr_scope.fin
                    INNER JOIN formulario f_latest
                        ON f_latest.id_rango = lr_scope.id_rango
                       AND f_latest.id_tipo_analisis = ta.id_tipo
                    WHERE m_scope.id_solicitud = s.id_solicitud
                )
                  AND LOWER(TRIM(COALESCE(ef_done.nombre, ''))) NOT LIKE '%rechaz%'
            )
              AND ta.id_tipo_muestra = s.id_tipo
            GROUP BY
                l.id_lote,
                l.codigo_lote,
                s.id_solicitud,
                s.fecha_ingreso,
                s.fecha_estimada,
                s.numero_muestras,
                tm.id_tipo,
                tm.nombre
            HAVING total_pendientes > 0
            ORDER BY
                CASE LOWER(tm.nombre)
                    WHEN 'suelos' THEN 10
                    WHEN 'agua' THEN 20
                    WHEN 'foliares' THEN 30
                    WHEN 'cañas' THEN 40
                    WHEN 'cana' THEN 40
                    WHEN 'mieles' THEN 50
                    WHEN 'miel' THEN 50
                    ELSE 90
                END,
                l.codigo_lote ASC,
                s.fecha_ingreso DESC,
                l.id_lote DESC
        ");

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}
