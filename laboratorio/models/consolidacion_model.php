<?php

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../includes/estado_lote_helper.php';
require_once __DIR__ . '/../includes/schema_safe_helper.php';

$connConsolidacion = Conexion::conectar();
lab_ensure_schema_safe(fn() => labFormularioEnsureSchema(), 'formulario_version');

function listarTiposMuestraConsolidacion()
{
    global $connConsolidacion;

    $res = $connConsolidacion->query(
        "SELECT id_tipo, nombre, prefijo
           FROM tipo_muestra
          ORDER BY nombre"
    );

    return $res ? $res->fetchAll(PDO::FETCH_ASSOC) : [];
}

function obtenerTipoMuestraConsolidacion($idTipo)
{
    global $connConsolidacion;

    $stmt = $connConsolidacion->prepare(
        "SELECT id_tipo, nombre, prefijo
           FROM tipo_muestra
          WHERE id_tipo = ?
          LIMIT 1"
    );
    $stmt->execute([$idTipo]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function obtenerAnalisisConsolidacion($idTipo)
{
    global $connConsolidacion;

    $stmt = $connConsolidacion->prepare(
        "SELECT
                ta.id_tipo,
                ta.nombre,
                COALESCE(ta.activo, 1) AS activo
           FROM tipo_analisis ta
          WHERE ta.id_tipo_muestra = ?
            AND COALESCE(ta.activo, 1) = 1
          ORDER BY ta.nombre ASC, ta.id_tipo ASC"
    );
    $stmt->execute([$idTipo]);

    $analisis = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $analisis[] = [
            'id_tipo' => (int) $row['id_tipo'],
            'nombre' => (string) $row['nombre'],
            'activo' => (int) ($row['activo'] ?? 1),
        ];
    }

    return $analisis;
}

function obtenerAnalistasConsolidacion($idTipo)
{
    global $connConsolidacion;

    $stmt = $connConsolidacion->prepare(
        "SELECT
                f.id_tipo_analisis,
                lr.id_rango,
                l.codigo_lote,
                TRIM(f.analista) AS analista
           FROM formulario f
           INNER JOIN lote_rango lr
                   ON lr.id_rango = f.id_rango
           INNER JOIN lote l
                   ON l.id_lote = lr.id_lote
           INNER JOIN solicitud s
                   ON s.id_lote = l.id_lote
          WHERE s.id_tipo = ?
            AND TRIM(COALESCE(f.analista, '')) <> ''
          ORDER BY f.id_tipo_analisis ASC, l.codigo_lote ASC, analista ASC"
    );
    $stmt->execute([$idTipo]);

    $analistas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idAnalisis = (string) ($row['id_tipo_analisis'] ?? '');
        $idRango = normalizarRangoConsolidacion($row['id_rango'] ?? null);
        $analista = trim((string) ($row['analista'] ?? ''));

        if ($idAnalisis === '' || $analista === '') {
            continue;
        }

        if (!isset($analistas[$idAnalisis])) {
            $analistas[$idAnalisis] = [];
        }

        if (!isset($analistas[$idAnalisis][$idRango])) {
            $analistas[$idAnalisis][$idRango] = [];
        }

        if (!in_array($analista, $analistas[$idAnalisis][$idRango], true)) {
            $analistas[$idAnalisis][$idRango][] = $analista;
        }
    }

    return $analistas;
}

function obtenerFilasConsolidacion($idTipo, $codigoLote = '')
{
    global $connConsolidacion;

    $codigoLote = trim((string) $codigoLote);
    $params = [$idTipo];
    $filtroLote = '';

    if ($codigoLote !== '') {
        $filtroLote = ' AND l.codigo_lote = ?';
        $params[] = $codigoLote;
    }

    $stmt = $connConsolidacion->prepare(
        "SELECT
            s.id_solicitud,
            s.fecha_ingreso,
            s.numero_muestras,
            l.id_lote,
            l.codigo_lote,
            lr.id_rango,
            ff.fecha_finalizacion,
            ff.id_formulario_revision,
            COALESCE(ar.analisis_requeridos, 0) AS analisis_requeridos,
            COALESCE(ai.analisis_ingresados, 0) AS analisis_ingresados,
            COALESCE(ai.analisis_aprobados, 0) AS analisis_aprobados,
            COALESCE(lr.inicio, m.min_muestra) AS inicio,
            COALESCE(lr.fin, m.max_muestra) AS fin
        FROM solicitud s
        LEFT JOIN lote l ON l.id_lote = s.id_lote
        LEFT JOIN (
            SELECT id_solicitud, MIN(numero_muestra) AS min_muestra, MAX(numero_muestra) AS max_muestra
              FROM muestra
             GROUP BY id_solicitud
        ) m ON m.id_solicitud = s.id_solicitud
        LEFT JOIN lote_rango lr ON lr.id_lote = l.id_lote
            AND lr.inicio = m.min_muestra
            AND lr.fin = m.max_muestra
        LEFT JOIN (
            SELECT s.id_lote,
                   COUNT(DISTINCT sa.id_tipo_analisis) AS analisis_requeridos
              FROM solicitud s
              INNER JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
             GROUP BY s.id_lote
        ) ar ON ar.id_lote = l.id_lote
        LEFT JOIN (
            SELECT lr2.id_lote,
                   COUNT(DISTINCT f.id_tipo_analisis) AS analisis_ingresados,
                   COUNT(
                       DISTINCT CASE
                           WHEN LOWER(COALESCE(ef.nombre, '')) = 'aprobado'
                           THEN f.id_tipo_analisis
                       END
                   ) AS analisis_aprobados
              FROM lote_rango lr2
              LEFT JOIN formulario f ON f.id_rango = lr2.id_rango
              LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
             GROUP BY lr2.id_lote
        ) ai ON ai.id_lote = l.id_lote
        LEFT JOIN (
            SELECT f.id_rango,
                   MAX(f.fecha) AS fecha_finalizacion,
                   MAX(f.id_formulario) AS id_formulario_revision
              FROM formulario f
             GROUP BY f.id_rango
        ) ff ON ff.id_rango = lr.id_rango
        WHERE s.id_tipo = ?{$filtroLote}
        ORDER BY s.fecha_ingreso DESC, s.id_solicitud DESC, lr.inicio ASC"
    );
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerEstadosAnalisisConsolidacion($idTipo, array $filas)
{
    global $connConsolidacion;

    $estados = [];

    foreach ($filas as $fila) {
        $idSolicitud = (int) $fila['id_solicitud'];
        $idRango = normalizarRangoConsolidacion($fila['id_rango'] ?? null);

        if (!isset($estados[$idSolicitud])) {
            $estados[$idSolicitud] = [];
        }

        if (!isset($estados[$idSolicitud][$idRango])) {
            $estados[$idSolicitud][$idRango] = [];
        }
    }

    if (empty($filas)) {
        return $estados;
    }

    $stmt = $connConsolidacion->prepare(
        "SELECT sa.id_solicitud, sa.id_tipo_analisis
           FROM solicitud_analisis sa
           INNER JOIN solicitud s ON s.id_solicitud = sa.id_solicitud
          WHERE s.id_tipo = ?"
    );
    $stmt->execute([$idTipo]);
    $solicitados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($solicitados as $solicitado) {
        $idSolicitud = (int) $solicitado['id_solicitud'];
        $idAnalisis = (string) $solicitado['id_tipo_analisis'];

        if (empty($estados[$idSolicitud])) {
            continue;
        }

        foreach (array_keys($estados[$idSolicitud]) as $idRango) {
            registrarEstadoConsolidacion($estados, $idSolicitud, $idRango, $idAnalisis, true, false);
        }
    }

    $stmt = $connConsolidacion->prepare(
        "SELECT s.id_solicitud, lr.id_rango, la.id_tipo_analisis, la.estado
           FROM lote_analisis la
           INNER JOIN lote_rango lr ON lr.id_rango = la.id_rango
           INNER JOIN lote l ON l.id_lote = lr.id_lote
           INNER JOIN solicitud s ON s.id_lote = l.id_lote
          WHERE s.id_tipo = ?"
    );
    $stmt->execute([$idTipo]);
    $analisisLote = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($analisisLote as $item) {
        registrarEstadoConsolidacion(
            $estados,
            (int) $item['id_solicitud'],
            normalizarRangoConsolidacion($item['id_rango'] ?? null),
            (string) $item['id_tipo_analisis'],
            true,
            estadoCompletadoConsolidacion($item['estado'] ?? null, false)
        );
    }

    $stmt = $connConsolidacion->prepare(
        "SELECT s.id_solicitud, f.id_rango, f.id_tipo_analisis, ef.nombre AS estado
           FROM formulario f
           INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
           INNER JOIN lote l ON l.id_lote = lr.id_lote
           INNER JOIN solicitud s ON s.id_lote = l.id_lote
           LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
          WHERE s.id_tipo = ?"
    );
    $stmt->execute([$idTipo]);
    $formularios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($formularios as $formulario) {
        registrarEstadoConsolidacion(
            $estados,
            (int) $formulario['id_solicitud'],
            normalizarRangoConsolidacion($formulario['id_rango'] ?? null),
            (string) $formulario['id_tipo_analisis'],
            true,
            estadoCompletadoConsolidacion($formulario['estado'] ?? null, true)
        );
    }

    return $estados;
}

function celdaConsolidacion(array $estados, $idSolicitud, $idRango, $idAnalisis)
{
    $idSolicitud = (int) $idSolicitud;
    $idRango = normalizarRangoConsolidacion($idRango);
    $idAnalisis = (string) $idAnalisis;

    return $estados[$idSolicitud][$idRango][$idAnalisis] ?? [
        'solicitado' => false,
        'completado' => false,
    ];
}

function registrarEstadoConsolidacion(array &$estados, $idSolicitud, $idRango, $idAnalisis, $solicitado, $completado)
{
    $idSolicitud = (int) $idSolicitud;
    $idRango = normalizarRangoConsolidacion($idRango);
    $idAnalisis = (string) $idAnalisis;

    if (!isset($estados[$idSolicitud])) {
        $estados[$idSolicitud] = [];
    }

    if (!isset($estados[$idSolicitud][$idRango])) {
        $estados[$idSolicitud][$idRango] = [];
    }

    if (!isset($estados[$idSolicitud][$idRango][$idAnalisis])) {
        $estados[$idSolicitud][$idRango][$idAnalisis] = [
            'solicitado' => false,
            'completado' => false,
        ];
    }

    $estados[$idSolicitud][$idRango][$idAnalisis]['solicitado'] =
        $estados[$idSolicitud][$idRango][$idAnalisis]['solicitado'] || (bool) $solicitado;

    $estados[$idSolicitud][$idRango][$idAnalisis]['completado'] =
        $estados[$idSolicitud][$idRango][$idAnalisis]['completado'] || (bool) $completado;
}

function normalizarRangoConsolidacion($idRango)
{
    return $idRango === null || $idRango === '' ? 'sin-rango' : (string) $idRango;
}

function estadoCompletadoConsolidacion($estado, $vacioEsCompletado = false)
{
    if ($estado === null || trim((string) $estado) === '') {
        return $vacioEsCompletado;
    }

    $estado = strtolower(trim((string) $estado));
    $pendientes = ['pendiente', 'correccion', 'correccion solicitada', 'rechazado', 'borrador', 'error'];

    foreach ($pendientes as $pendiente) {
        if (strpos($estado, $pendiente) !== false) {
            return false;
        }
    }

    $completados = ['complet', 'finaliz', 'termin', 'aprob', 'revis', 'guard'];

    foreach ($completados as $completado) {
        if (strpos($estado, $completado) !== false) {
            return true;
        }
    }

    return false;
}

// Esta función proporciona un conjunto de análisis base para cada tipo de muestra,
// en caso de que no se encuentren análisis específicos en la base de datos.
function obtenerIndicadoresControlCalidad(): array
{
    global $connConsolidacion;

    $resumen = [
        'tasa_rechazo' => 0.0,
        'variacion_rechazo' => 0.0,
        'reanalisis_abiertos' => 0,
        'equipos_por_calibrar' => null,
        'equipos_disponibles' => false,
        'no_conformidades_mes' => 0,
        'variacion_no_conformidades' => 0,
        'motivos_rechazo' => [],
        'rechazos_ultimos_30' => 0,
        'revisiones_ultimos_30' => 0,
    ];

    $tablaExiste = static function (string $tabla) use ($connConsolidacion): bool {
        $stmt = $connConsolidacion->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$tabla]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$tablaExiste('historial_formulario') || !$tablaExiste('formulario_error')) {
        return $resumen;
    }

    $contarRevisiones = static function (int $desdeDias, int $hastaDias) use ($connConsolidacion): int {
        $stmt = $connConsolidacion->prepare("
            SELECT COUNT(DISTINCT id_formulario)
              FROM historial_formulario
             WHERE fecha >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND fecha < DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (
                    LOWER(COALESCE(accion, '')) REGEXP 'aprob|error|rechaz|correg'
                    OR LOWER(COALESCE(estado_nuevo, '')) REGEXP 'aprob|error|rechaz'
               )
        ");
        $stmt->execute([$desdeDias, $hastaDias]);
        return (int) $stmt->fetchColumn();
    };

    $contarRechazos = static function (int $desdeDias, int $hastaDias) use ($connConsolidacion): int {
        $stmt = $connConsolidacion->prepare("
            SELECT COUNT(DISTINCT id_formulario)
              FROM historial_formulario
             WHERE fecha >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND fecha < DATE_SUB(NOW(), INTERVAL ? DAY)
               AND LOWER(CONCAT_WS(' ', accion, estado_nuevo, comentario)) REGEXP 'error|rechaz'
        ");
        $stmt->execute([$desdeDias, $hastaDias]);
        return (int) $stmt->fetchColumn();
    };

    $revisionesActuales = $contarRevisiones(30, 0);
    $rechazosActuales = $contarRechazos(30, 0);
    $revisionesAnteriores = $contarRevisiones(60, 30);
    $rechazosAnteriores = $contarRechazos(60, 30);

    $tasaActual = $revisionesActuales > 0 ? ($rechazosActuales / $revisionesActuales) * 100 : 0.0;
    $tasaAnterior = $revisionesAnteriores > 0 ? ($rechazosAnteriores / $revisionesAnteriores) * 100 : 0.0;
    $resumen['tasa_rechazo'] = round($tasaActual, 1);
    $resumen['variacion_rechazo'] = round($tasaActual - $tasaAnterior, 1);
    $resumen['rechazos_ultimos_30'] = $rechazosActuales;
    $resumen['revisiones_ultimos_30'] = $revisionesActuales;

    $stmt = $connConsolidacion->query("
        SELECT COUNT(DISTINCT id_formulario)
          FROM formulario_error
         WHERE COALESCE(activo, 1) = 1
    ");
    $resumen['reanalisis_abiertos'] = $stmt ? (int) $stmt->fetchColumn() : 0;

    $stmt = $connConsolidacion->query("
        SELECT COUNT(*)
          FROM formulario_error
         WHERE fecha >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')
           AND fecha < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)
    ");
    $noConformidadesActuales = $stmt ? (int) $stmt->fetchColumn() : 0;
    $stmt = $connConsolidacion->query("
        SELECT COUNT(*)
          FROM formulario_error
         WHERE fecha >= DATE_SUB(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)
           AND fecha < DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')
    ");
    $noConformidadesAnteriores = $stmt ? (int) $stmt->fetchColumn() : 0;
    $resumen['no_conformidades_mes'] = $noConformidadesActuales;
    $resumen['variacion_no_conformidades'] = $noConformidadesActuales - $noConformidadesAnteriores;

    if ($tablaExiste('tipo_error')) {
        $stmt = $connConsolidacion->query("
            SELECT COALESCE(NULLIF(TRIM(te.nombre), ''), 'Sin clasificar') AS motivo, COUNT(*) AS total
              FROM formulario_error fe
              LEFT JOIN tipo_error te ON te.id_error = fe.id_error
             WHERE fe.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY COALESCE(NULLIF(TRIM(te.nombre), ''), 'Sin clasificar')
             ORDER BY total DESC, motivo ASC
        ");
        $motivos = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $maximo = 0;
        foreach ($motivos as $motivo) {
            $maximo = max($maximo, (int) ($motivo['total'] ?? 0));
        }
        foreach ($motivos as $motivo) {
            $total = (int) ($motivo['total'] ?? 0);
            $resumen['motivos_rechazo'][] = [
                'motivo' => (string) ($motivo['motivo'] ?? 'Sin clasificar'),
                'total' => $total,
                'porcentaje' => $maximo > 0 ? (int) round(($total / $maximo) * 100) : 0,
            ];
        }
    }

    foreach (['equipo', 'equipos'] as $tablaEquipo) {
        if (!$tablaExiste($tablaEquipo)) {
            continue;
        }
        $columnas = $connConsolidacion->query('SHOW COLUMNS FROM `' . $tablaEquipo . '`')->fetchAll(PDO::FETCH_COLUMN);
        $columnaFecha = null;
        foreach (['proxima_calibracion', 'fecha_proxima_calibracion', 'fecha_calibracion'] as $candidata) {
            if (in_array($candidata, $columnas, true)) {
                $columnaFecha = $candidata;
                break;
            }
        }
        if ($columnaFecha !== null) {
            $stmt = $connConsolidacion->query(
                'SELECT COUNT(*) FROM `' . $tablaEquipo . '` WHERE `' . $columnaFecha . '` <= DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY)'
            );
            $resumen['equipos_por_calibrar'] = $stmt ? (int) $stmt->fetchColumn() : 0;
            $resumen['equipos_disponibles'] = true;
        }
        break;
    }

    return $resumen;
}

function obtenerAnalisisBaseConsolidacion($idTipo)
{
    $base = [
        1 => [
            'Textura',
            'Densidad aparente',
            'Densidad real',
            'Humedad gravimétrica',
            'Porosidad total',
            'pH',
            'Materia orgánica',
            'Nitrógeno total',
            'Fósforo disponible',
            'Potasio intercambiable',
            'CIC',
        ],
        2 => [
            'Brix',
            'Pol',
            'Pureza',
            'Fibra bruta',
            'Humedad del bagazo',
            'Jugo extraído',
        ],
        3 => [
            'Humedad',
            'HMF',
            'Actividad diastásica',
            'Sólidos solubles (Brix)',
            'pH y acidez libre',
        ],
        4 => [
            'pH',
            'Macros',
            'Micros',
            'RAS',
            'Fósforo',
            'Boro',
            'Conductividad Eléctrica',
            'TDS',
            'Salinidad',
            'Resistividad',
            'Cloruros',
            'Dureza',
            'Alcalinidad Total',
            'Carbonatos',
            'Bicarbonatos',
        ],
        5 => [
            'Nitrógeno foliar',
            'Fósforo foliar',
            'Potasio foliar',
            'Calcio y Magnesio',
            'Micronutrientes',
        ],
    ];

    if (!isset($base[$idTipo])) {
        return [];
    }

    $analisis = [];
    foreach ($base[$idTipo] as $index => $nombre) {
        $analisis[] = [
            'id_tipo' => 'base-' . $idTipo . '-' . $index,
            'nombre' => $nombre,
            'es_base' => true,
        ];
    }

    return $analisis;
}

?>
