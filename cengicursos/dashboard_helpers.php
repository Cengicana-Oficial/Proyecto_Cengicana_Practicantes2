<?php
/** Consultas de solo lectura para el resumen. Los errores no se presentan como ceros. */
function cengi_dash_query(PDO $db, string $sql, array $params = []): ?array
{
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Dashboard Cengicursos: ' . $e->getMessage());
        return null;
    }
}
function cengi_dash_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function cengi_dash_n($row, string $key): string { return $row === null ? '—' : number_format((int) ($row[$key] ?? 0)); }
function cengi_dash_data(PDO $db, int $year): array
{
    $range = ["$year-01-01", ($year + 1) . '-01-01'];
    $scope = cengi_scope_sql_por_nombre_ingenio('i', true);
    $courseFrom = " FROM cursos c INNER JOIN ingenios i ON i.id = c.ingenio_id WHERE c.inicio >= ? AND c.inicio < ? $scope";
    $rows = cengi_dash_query($db, "SELECT COUNT(*) total,
        COALESCE(SUM(c.inicio > CURDATE()),0) proximos,
        COALESCE(SUM(c.inicio <= CURDATE() AND (c.fin IS NULL OR YEAR(c.fin) = 0 OR c.fin >= CURDATE())),0) activos,
        COALESCE(SUM(c.fin IS NOT NULL AND YEAR(c.fin) > 0 AND c.fin < CURDATE()),0) finalizados" . $courseFrom, $range);
    $courses = $rows === null ? null : $rows[0];
    $rows = cengi_dash_query($db, "SELECT COUNT(*) inscripciones, COUNT(DISTINCT p.id) personas, COUNT(DISTINCT p.ingenio_id) ingenios
        FROM asignaciones a INNER JOIN cursos c ON c.id = a.cursos_id
        INNER JOIN participantes p ON p.id = a.participantes_id INNER JOIN ingenios i ON i.id = p.ingenio_id
        WHERE a.estado_asignaciones = 1 AND c.inicio >= ? AND c.inicio < ? $scope", $range);
    $people = $rows === null ? null : $rows[0];
    $rows = cengi_dash_query($db, "SELECT COUNT(*) total, COALESCE(SUM(s.estado = 'impartida'),0) impartidas,
        COALESCE(SUM(s.fecha < CURDATE() AND s.estado <> 'impartida'),0) pendientes
        FROM curso_sesiones s INNER JOIN planificacion_cursos pc ON pc.id = s.planificacion_id
        INNER JOIN cursos c ON c.id = pc.curso_id INNER JOIN ingenios i ON i.id = c.ingenio_id
        WHERE s.fecha >= ? AND s.fecha < ? AND s.estado <> 'cancelada' $scope", $range);
    $sessions = $rows === null ? null : $rows[0];
    $monthly = cengi_dash_query($db, "SELECT MONTH(c.inicio) mes, COUNT(*) total" . $courseFrom . ' GROUP BY MONTH(c.inicio)', $range);
    $nextCourses = cengi_dash_query($db, "SELECT c.nombre_cursos nombre, c.inicio fecha, c.fin" . $courseFrom . ' AND c.inicio >= CURDATE() ORDER BY c.inicio, c.id LIMIT 5', $range);
    // El módulo de eventos tiene permisos propios y alcance institucional.
    $events = $attendance = $nextEvents = null;
    $showEvents = cengi_puede_ver_eventos();
    if ($showEvents) {
        $rows = cengi_dash_query($db, "SELECT COUNT(*) total, COALESCE(SUM(fecha < CURDATE()),0) transcurridos,
            COALESCE(SUM(fecha >= CURDATE()),0) proximos FROM eventos
            WHERE fecha >= ? AND fecha < ? AND estado <> 'Cancelado'", $range);
        $events = $rows === null ? null : $rows[0];
        $rows = cengi_dash_query($db, "SELECT COUNT(*) registros, COALESCE(SUM(ep.ingreso_en IS NOT NULL),0) ingresos
            FROM evento_participantes ep INNER JOIN eventos e ON e.id = ep.evento_id
            WHERE e.fecha >= ? AND e.fecha < ? AND e.fecha <= CURDATE() AND e.estado <> 'Cancelado'", $range);
        $attendance = $rows === null ? null : $rows[0];
        $nextEvents = cengi_dash_query($db, "SELECT nombre, fecha FROM eventos WHERE fecha >= ? AND fecha < ?
            AND fecha >= CURDATE() AND estado <> 'Cancelado' ORDER BY fecha, id LIMIT 5", $range);
    }
    return compact('courses', 'people', 'sessions', 'monthly', 'nextCourses', 'showEvents', 'events', 'attendance', 'nextEvents');
}
