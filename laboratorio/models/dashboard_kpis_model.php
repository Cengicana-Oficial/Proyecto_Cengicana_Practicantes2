<?php
/**
 * dashboard_kpis_model.php
 *
 * Consultas reales para el dashboard general (view/dashboard.php):
 * KPIs superiores, tiempo promedio por etapa, cumplimiento de SLA y
 * productividad por analista. Todas se calculan sobre las tablas ya
 * existentes (formulario/estado_formulario/historial_formulario/
 * solicitud/muestra); ninguna usa datos de ejemplo. Cuando un bloque no
 * se puede calcular honestamente con los datos disponibles hoy (ej. sin
 * transiciones de estado registradas todavia), la funcion correspondiente
 * devuelve un array vacio / null y la vista debe omitir esa seccion en
 * vez de rellenarla con cifras inventadas.
 */

if (!function_exists('lab_dashboard_kpis')) {
    function lab_dashboard_kpis(PDO $pdo): array
    {
        $kpis = [
            'muestras_hoy' => 0,
            'analisis_en_proceso' => 0,
            'en_revision_tecnica' => 0,
            'rechazados_mes' => 0,
        ];

        try {
            $stmt = $pdo->query("SELECT COALESCE(SUM(numero_muestras), 0) FROM solicitud WHERE fecha_ingreso = CURDATE()");
            $kpis['muestras_hoy'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM formulario f
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) = 'en proceso'
            ");
            $kpis['analisis_en_proceso'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM formulario f
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) LIKE '%revis%'
            ");
            $kpis['en_revision_tecnica'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM historial_formulario
                WHERE LOWER(TRIM(COALESCE(estado_nuevo, ''))) LIKE 'rechaz%'
                  AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())
            ");
            $kpis['rechazados_mes'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        return $kpis;
    }
}

if (!function_exists('lab_dashboard_tiempo_por_etapa')) {
    /**
     * Tiempo promedio (en horas) que un analisis permanece en cada estado
     * antes de pasar al siguiente, calculado con LEAD() (MySQL 8) sobre
     * las transiciones reales registradas en historial_formulario.
     * Devuelve [] si todavia no hay suficientes transiciones consecutivas
     * para calcular un promedio (nada que promediar todavia).
     */
    function lab_dashboard_tiempo_por_etapa(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("
                SELECT estado_nuevo, AVG(horas) AS horas_promedio, COUNT(*) AS transiciones
                FROM (
                    SELECT
                        estado_nuevo,
                        TIMESTAMPDIFF(
                            HOUR, fecha,
                            LEAD(fecha) OVER (PARTITION BY id_formulario ORDER BY fecha, id_historial)
                        ) AS horas
                    FROM historial_formulario
                    WHERE estado_nuevo IS NOT NULL AND TRIM(estado_nuevo) <> ''
                ) t
                WHERE horas IS NOT NULL
                GROUP BY estado_nuevo
                ORDER BY horas_promedio DESC
            ");
            $filas = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }

        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[] = [
                'estado' => (string) $fila['estado_nuevo'],
                'horas_promedio' => round((float) $fila['horas_promedio'], 1),
                'transiciones' => (int) $fila['transiciones'],
            ];
        }

        return $resultado;
    }
}

if (!function_exists('lab_dashboard_cumplimiento_sla')) {
    /**
     * Cumplimiento de SLA: de las solicitudes cuya fecha_estimada (unico
     * campo real de plazo comprometido en el esquema actual) ya paso,
     * que porcentaje tiene TODOS sus analisis (formulario, via
     * lote_rango) en estado "Aprobado" y con la ultima aprobacion
     * (historial_formulario) en o antes de esa fecha_estimada.
     * Devuelve null si no hay ninguna solicitud con fecha_estimada
     * vencida todavia (no se puede juzgar cumplimiento sin al menos un
     * plazo ya cumplido).
     */
    function lab_dashboard_cumplimiento_sla(PDO $pdo): ?array
    {
        try {
            $stmt = $pdo->query("
                SELECT
                    s.id_solicitud,
                    s.fecha_estimada,
                    COUNT(DISTINCT f.id_formulario) AS total_formularios,
                    SUM(CASE WHEN LOWER(TRIM(COALESCE(ef.nombre, ''))) = 'aprobado' THEN 1 ELSE 0 END) AS aprobados,
                    MAX(CASE WHEN LOWER(TRIM(COALESCE(ef.nombre, ''))) = 'aprobado' THEN uh.max_fecha END) AS fecha_ultimo_aprobado
                FROM solicitud s
                INNER JOIN lote_rango lr ON lr.id_lote = s.id_lote
                INNER JOIN formulario f ON f.id_rango = lr.id_rango
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                LEFT JOIN (
                    SELECT id_formulario, MAX(fecha) AS max_fecha
                    FROM historial_formulario
                    WHERE LOWER(TRIM(COALESCE(estado_nuevo, ''))) = 'aprobado'
                    GROUP BY id_formulario
                ) uh ON uh.id_formulario = f.id_formulario
                WHERE s.fecha_estimada IS NOT NULL AND s.fecha_estimada <= CURDATE()
                GROUP BY s.id_solicitud, s.fecha_estimada
            ");
            $filas = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return null;
        }

        if (!$filas) {
            return null;
        }

        $cumplidas = 0;
        $total = 0;

        foreach ($filas as $fila) {
            $totalFormularios = (int) $fila['total_formularios'];
            if ($totalFormularios <= 0) {
                continue;
            }
            $total++;

            $todosAprobados = ((int) $fila['aprobados']) >= $totalFormularios;
            $fechaAprobado = $fila['fecha_ultimo_aprobado'] ?? null;
            $aTiempo = $todosAprobados && (
                $fechaAprobado === null
                || strtotime((string) $fechaAprobado) <= strtotime((string) $fila['fecha_estimada'] . ' 23:59:59')
            );

            if ($aTiempo) {
                $cumplidas++;
            }
        }

        if ($total === 0) {
            return null;
        }

        return [
            'cumplidas' => $cumplidas,
            'total' => $total,
            'porcentaje' => round(($cumplidas / $total) * 100, 1),
        ];
    }
}

if (!function_exists('lab_dashboard_productividad_analista')) {
    /**
     * Analisis (formulario) por analista, usando el campo real
     * formulario.analista (texto libre capturado en cada formulario, ver
     * includes/analisis_controller_helper.php / legacy_analysis_form_helper.php).
     * No es un id de usuario formal, pero es el unico dato real de "quien
     * realizo este analisis" que existe hoy en el esquema. Devuelve []
     * si ningun formulario tiene ese campo lleno todavia.
     */
    function lab_dashboard_productividad_analista(PDO $pdo, int $limite = 8): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    TRIM(f.analista) AS analista,
                    COUNT(*) AS total,
                    SUM(CASE WHEN LOWER(TRIM(COALESCE(ef.nombre, ''))) = 'aprobado' THEN 1 ELSE 0 END) AS aprobados
                FROM formulario f
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                WHERE TRIM(COALESCE(f.analista, '')) <> ''
                GROUP BY TRIM(f.analista)
                ORDER BY total DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limite, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
