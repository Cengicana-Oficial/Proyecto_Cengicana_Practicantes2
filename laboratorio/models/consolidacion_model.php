<?php

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../includes/estado_lote_helper.php';

$connConsolidacion = Conexion::conectar();
labFormularioEnsureSchema();

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
        "SELECT id_tipo, nombre
           FROM tipo_analisis
          WHERE id_tipo_muestra = ?
          ORDER BY nombre"
    );
    $stmt->execute([$idTipo]);
    $analisis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($analisis)) {
        return $analisis;
    }

    return obtenerAnalisisBaseConsolidacion((int) $idTipo);
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
