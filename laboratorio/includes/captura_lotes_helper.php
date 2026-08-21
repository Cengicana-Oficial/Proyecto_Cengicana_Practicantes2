<?php

require_once __DIR__ . '/catalogo_analisis_helper.php';
require_once __DIR__ . '/estado_lote_helper.php';
require_once __DIR__ . '/../models/conexion.php';

if (!function_exists('labCapturaLower')) {
    function labCapturaLower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}

if (!function_exists('labCapturaConexion')) {
    function labCapturaConexion(): ?PDO
    {
        global $conn, $conexion;

        if ($conn instanceof PDO) {
            return $conn;
        }

        if ($conexion instanceof PDO) {
            return $conexion;
        }

        if (class_exists('Conexion')) {
            $pdo = Conexion::conectar();
            return $pdo instanceof PDO ? $pdo : null;
        }

        return null;
    }
}

if (!function_exists('labCapturaResolverIdsPorNombre')) {
    function labCapturaResolverIdsPorNombre(PDO $pdo, string $tabla, string $columnaId, array $valores, string $columnaNombre = 'nombre'): array
    {
        $valores = array_values(array_filter(array_map('trim', $valores), static function (string $value): bool {
            return $value !== '';
        }));

        if (!$valores) {
            return [];
        }

        $stmt = $pdo->query("SELECT `$columnaId`, `$columnaNombre`, prefijo FROM `$tabla`");
        $filas = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$filas) {
            return [];
        }

        $buscados = [];
        foreach ($valores as $valor) {
            $buscados[labCapturaLower($valor)] = true;
        }

        $ids = [];
        foreach ($filas as $fila) {
            $id = (int) ($fila[$columnaId] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $nombre = labCapturaLower((string) ($fila[$columnaNombre] ?? ''));
            $prefijo = labCapturaLower((string) ($fila['prefijo'] ?? ''));
            $clave = labCapturaLower((string) ($fila[$columnaNombre] ?? ''));

            if (isset($buscados[$nombre]) || ($prefijo !== '' && isset($buscados[$prefijo])) || isset($buscados[$clave])) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('labCapturaResolverTipoIds')) {
    function labCapturaResolverTipoIds(PDO $pdo, array $tipos): array
    {
        $tipos = array_values(array_filter(array_map(static function ($tipo): string {
            return labCatalogoMuestrasClaveDesdePrefijo(null, (string) $tipo);
        }, $tipos)));

        if (!$tipos) {
            return [];
        }

        $stmt = $pdo->query("SELECT id_tipo, nombre, prefijo FROM tipo_muestra");
        $filas = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$filas) {
            return [];
        }

        $buscados = array_fill_keys($tipos, true);
        $ids = [];

        foreach ($filas as $fila) {
            $id = (int) ($fila['id_tipo'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $clave = labCatalogoMuestrasClaveDesdePrefijo(
                (string) ($fila['prefijo'] ?? ''),
                (string) ($fila['nombre'] ?? '')
            );

            if (isset($buscados[$clave])) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('labCapturaResolverAnalisisIds')) {
    function labCapturaResolverAnalisisIds(PDO $pdo, array $analisis, array $tipoIds = []): array
    {
        $analisis = array_values(array_filter(array_map('trim', $analisis), static function (string $value): bool {
            return $value !== '';
        }));

        if (!$analisis) {
            return [];
        }

        $stmt = $pdo->query("
            SELECT ta.id_tipo, ta.nombre, ta.id_tipo_muestra, tm.nombre AS nombre_muestra, tm.prefijo AS prefijo_muestra
              FROM tipo_analisis ta
              LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
        ");
        $filas = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$filas) {
            return [];
        }

        $buscados = [];
        foreach ($analisis as $valor) {
            $buscados[labCapturaLower($valor)] = true;
        }

        $permitidosTipo = $tipoIds ? array_fill_keys(array_map('intval', $tipoIds), true) : [];
        $ids = [];

        foreach ($filas as $fila) {
            $id = (int) ($fila['id_tipo'] ?? 0);
            $idTipoMuestra = (int) ($fila['id_tipo_muestra'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if ($permitidosTipo && !isset($permitidosTipo[$idTipoMuestra])) {
                continue;
            }

            $nombre = labCapturaLower((string) ($fila['nombre'] ?? ''));
            if (isset($buscados[$nombre])) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('labCapturaContextoAnalisis')) {
    function labCapturaContextoAnalisis(): ?array
    {
        global $labAnalysisContexto;

        $catalogoId = (int) ($_GET['catalogo_id'] ?? 0);
        $captureKey = trim((string) ($_GET['analisis'] ?? ''));
        if (
            $catalogoId > 0
            && $captureKey !== ''
            && (string) ($_GET['embed'] ?? '') === '1'
            && (string) ($_GET['from'] ?? '') === 'bandeja'
        ) {
            try {
                require_once __DIR__ . '/captura_variables_registry.php';
                $capture = lab_captura_variables_por_clave($captureKey);
                $pdo = labCapturaConexion();

                if ($capture && $pdo && !empty($capture['controller'])) {
                    $stmt = $pdo->prepare("
                        SELECT ta.id_tipo, ta.nombre, ta.id_tipo_muestra,
                               tm.nombre AS nombre_muestra, tm.prefijo AS prefijo_muestra
                          FROM tipo_analisis ta
                          INNER JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
                         WHERE ta.id_tipo = ?
                           AND COALESCE(ta.activo, 1) = 1
                         LIMIT 1
                    ");
                    $stmt->execute([$catalogoId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                    if ($row) {
                        $rowType = labCatalogoMuestrasClaveDesdePrefijo(
                            (string) ($row['prefijo_muestra'] ?? ''),
                            (string) ($row['nombre_muestra'] ?? '')
                        );
                        $captureType = labCatalogoMuestrasClaveDesdePrefijo(null, (string) $capture['tipo']);
                        $rowName = lab_captura_variables_normalizar((string) ($row['nombre'] ?? ''));
                        $aliases = array_map('lab_captura_variables_normalizar', (array) $capture['aliases']);

                        if ($rowType === $captureType && in_array($rowName, $aliases, true)) {
                            return [
                                'tipo_ids' => [(int) $row['id_tipo_muestra']],
                                'analisis_ids' => [(int) $row['id_tipo']],
                                'label' => (string) ($capture['label'] ?? $row['nombre']),
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Si el contexto explicito no es valido, se conserva el flujo historico.
            }
        }

        if (isset($labAnalysisContexto) && is_array($labAnalysisContexto)) {
            return $labAnalysisContexto;
        }

        $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));

        $mapa = [
            'suelos/cc_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Capacidad de Campo', 'Capacidad campo'],
                'label' => 'Capacidad de Campo',
            ],
            'suelos/pmp_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Punto de Marchitez Permanente', 'Marchitez Permanente', 'PMP'],
                'label' => 'Punto de Marchitez Permanente',
            ],
            'suelos/macroscic_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Macronutrientes y CIC', 'Macronutrientes', 'CIC'],
                'label' => 'Macronutrientes y CIC',
            ],
            'suelos/micros_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Micro Nutrientes', 'Micronutrientes', 'Cu, Zn, Fe, Mn, K'],
                'label' => 'Micro Nutrientes de Suelos',
            ],
            'suelos/nitrogeno_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Nitrógeno', 'Nitrogeno', 'Nitrógeno total', 'Nitrogeno total'],
                'label' => 'Nitrógeno de Suelos',
            ],
            'suelos/boro_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Boro'],
                'label' => 'Boro de Suelos',
            ],
            'suelos/azufre_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Azufre', 'SO4'],
                'label' => 'Azufre de Suelos',
            ],
            'suelos/fosforo_controller.php' => [
                'tipos' => ['suelos', 'suelo'],
                'analisis' => ['Fósforo', 'Fosforo', 'Fósforo disponible', 'Fosforo disponible'],
                'label' => 'Fósforo de Suelos',
            ],
            'foliares/micros_controller.php' => [
                'tipos' => ['foliares', 'foliar'],
                'analisis' => ['Micro Nutrientes', 'Micronutrientes', 'Cu, Zn, Fe, Mn, K'],
                'label' => 'Micro Nutrientes Foliares',
            ],
            'foliares/fosforo_controller.php' => [
                'tipos' => ['foliares', 'foliar'],
                'analisis' => ['Fósforo', 'Fosforo', 'Fósforo foliar', 'Fosforo foliar'],
                'label' => 'Fósforo Foliar',
            ],
            'aguas/micros_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Micro Nutrientes', 'Micronutrientes', 'Micro Nutrientes (Cu, Zn, Fe, Mn)', 'Micronutrientes (Cu, Zn, Fe, Mn)', 'Micro Nutrientes de Aguas', 'Micronutrientes de Aguas', 'Cu, Zn, Fe, Mn'],
                'label' => 'Micro Nutrientes de Aguas',
            ],
            'aguas/fosforo_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Fósforo', 'Fosforo'],
                'label' => 'Fósforo de Aguas',
            ],
            'aguas/conductividad_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Conductividad Eléctrica', 'Conductividad Electrica', 'CE'],
                'label' => 'Conductividad Eléctrica',
            ],
            'aguas/tds_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['TDS', 'Sólidos totales disueltos', 'Solidos totales disueltos', 'STD'],
                'label' => 'TDS',
            ],
            'aguas/resistividad_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Resistividad'],
                'label' => 'Resistividad',
            ],
            'aguas/cloruros_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Cloruros'],
                'label' => 'Cloruros',
            ],
            'aguas/alcanilidad_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Alcalinidad', 'Alcanilidad'],
                'label' => 'Alcalinidad',
            ],
            'aguas/bicarbonato_controller.php' => [
                'tipos' => ['agua', 'aguas'],
                'analisis' => ['Bicarbonatos', 'Bicarbonato'],
                'label' => 'Bicarbonatos',
            ],
            'cana/humedad_controller.php' => [
                'tipos' => ['cañas', 'caña', 'canas', 'cana'],
                'analisis' => ['% de Humedad', 'Humedad'],
                'label' => '% de Humedad en Caña',
            ],
            'cana/brixpol_controller.php' => [
                'tipos' => ['cañas', 'caña', 'canas', 'cana'],
                'analisis' => ['Determinación de Brix y Pol', 'Determinacion de Brix y Pol', 'Brix', 'Pol'],
                'label' => 'Brix y Pol',
            ],
        ];

        foreach ($mapa as $needle => $contexto) {
            if (strpos($script, $needle) !== false) {
                return $contexto;
            }
        }

        return null;
    }
}

if (!function_exists('labCapturaCondicionesContexto')) {
    function labCapturaCondicionesContexto(?array $contexto, array &$params): array
    {
        $pdo = labCapturaConexion();
        if (!$pdo || !$contexto) {
            return ['tipo' => '0 = 1', 'analisis' => '0 = 1'];
        }

        $tipoIds = [];
        if (isset($contexto['tipo_ids']) && is_array($contexto['tipo_ids'])) {
            $tipoIds = array_values(array_filter(array_map('intval', $contexto['tipo_ids']), static function (int $value): bool {
                return $value > 0;
            }));
        } elseif (!empty($contexto['tipos']) && is_array($contexto['tipos'])) {
            $tipoIds = labCapturaResolverTipoIds($pdo, $contexto['tipos']);
        }

        $analisisIds = [];
        if (isset($contexto['analisis_ids']) && is_array($contexto['analisis_ids'])) {
            $analisisIds = array_values(array_filter(array_map('intval', $contexto['analisis_ids']), static function (int $value): bool {
                return $value > 0;
            }));
        } elseif (!empty($contexto['analisis']) && is_array($contexto['analisis'])) {
            $analisisIds = labCapturaResolverAnalisisIds($pdo, $contexto['analisis'], $tipoIds);
        }

        if (!$tipoIds || !$analisisIds) {
            return ['tipo' => '0 = 1', 'analisis' => '0 = 1'];
        }

        $tipoPlaceholders = implode(', ', array_fill(0, count($tipoIds), '?'));
        $analisisPlaceholders = implode(', ', array_fill(0, count($analisisIds), '?'));
        $params = array_merge($params, $tipoIds, $analisisIds);

        return [
            'tipo' => "tm.id_tipo IN ({$tipoPlaceholders})",
            'analisis' => "ta.id_tipo IN ({$analisisPlaceholders})",
        ];
    }
}

if (!function_exists('labCapturaTablaActual')) {
    function labCapturaTablaActual(): ?string
    {
        $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));

        $mapa = [
            'suelos/cc_controller.php' => 'suelo_cc',
            'suelos/pmp_controller.php' => 'suelo_pmp',
            'suelos/macroscic_controller.php' => 'suelo_macros',
            'suelos/micros_controller.php' => 'suelo_micros',
            'suelos/nitrogeno_controller.php' => 'suelo_nitrogeno',
            'suelos/boro_controller.php' => 'suelo_boro',
            'suelos/azufre_controller.php' => 'suelo_azufre',
            'suelos/fosforo_controller.php' => 'suelo_fosforo',
            'foliares/micros_controller.php' => 'foliar_micros',
            'foliares/fosforo_controller.php' => 'foliar_fosforo',
            'aguas/micros_controller.php' => 'agua_micros',
            'aguas/fosforo_controller.php' => 'agua_fosforo',
            'aguas/conductividad_controller.php' => 'agua_conductividad',
            'aguas/tds_controller.php' => 'agua_tds',
            'aguas/resistividad_controller.php' => 'agua_resistividad',
            'aguas/cloruros_controller.php' => 'agua_cloruros',
            'aguas/alcanilidad_controller.php' => 'agua_alcalinidad',
            'aguas/bicarbonato_controller.php' => 'agua_bicarbonatos',
            'cana/humedad_controller.php' => 'cana_humedad',
            'cana/brixpol_controller.php' => 'cana_brixpol',
        ];

        foreach ($mapa as $needle => $tabla) {
            if (strpos($script, $needle) !== false) {
                return $tabla;
            }
        }

        return null;
    }
}

if (!function_exists('labCapturaColumnasTabla')) {
    function labCapturaColumnasTabla(PDO $pdo, string $tabla): array
    {
        static $cache = [];

        if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla)) {
            return [];
        }

        if (array_key_exists($tabla, $cache)) {
            return $cache[$tabla];
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `$tabla`");
        $columnas = [];
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $columna) {
            $nombre = (string) ($columna['Field'] ?? '');
            if ($nombre !== '') {
                $columnas[] = $nombre;
            }
        }

        $cache[$tabla] = $columnas;
        return $columnas;
    }
}

if (!function_exists('labCapturaMuestrasUsadasPorTablaActual')) {
    function labCapturaMuestrasUsadasPorTablaActual(): array
    {
        try {
            $pdo = labCapturaConexion();
            $tabla = labCapturaTablaActual();
            if (!$pdo || !$tabla) {
                return [];
            }

            $columnas = labCapturaColumnasTabla($pdo, $tabla);
            if (!$columnas) {
                return [];
            }

            $exprLote = null;
            $joinLote = '';

            if (in_array('id_lote', $columnas, true)) {
                $exprLote = 'l.codigo_lote';
                $joinLote = ' INNER JOIN lote l ON l.id_lote = t.id_lote ';
            } elseif (in_array('codigo_lote', $columnas, true)) {
                $exprLote = 't.codigo_lote';
            } elseif (in_array('lote', $columnas, true)) {
                $exprLote = 't.lote';
            }

            $exprNumero = null;
            $joinMuestra = '';
            if (in_array('no_lab', $columnas, true)) {
                $exprNumero = 't.no_lab';
            } elseif (in_array('numero_laboratorio', $columnas, true)) {
                if (in_array('id_solicitud', $columnas, true)) {
                    $joinMuestra = ' LEFT JOIN muestra m ON m.id_solicitud = t.id_solicitud AND m.numero_muestra = t.numero_laboratorio ';
                    $exprNumero = "COALESCE(m.codigo_lab, CAST(t.numero_laboratorio AS CHAR))";
                } else {
                    $exprNumero = 'CAST(t.numero_laboratorio AS CHAR)';
                }
            } elseif (in_array('numero_muestra', $columnas, true)) {
                $exprNumero = 'CAST(t.numero_muestra AS CHAR)';
            }

            if (!$exprLote || !$exprNumero) {
                return [];
            }

            $stmt = $pdo->query("
                SELECT DISTINCT {$exprLote} AS codigo_lote, {$exprNumero} AS numero_lab
                  FROM `{$tabla}` t
                  {$joinLote}
                  {$joinMuestra}
                 WHERE {$exprLote} IS NOT NULL
                   AND {$exprLote} <> ''
                   AND {$exprNumero} IS NOT NULL
                   AND {$exprNumero} <> ''
            ");

            $usadas = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $lote = trim((string) ($row['codigo_lote'] ?? ''));
                $numero = trim((string) ($row['numero_lab'] ?? ''));
                if ($lote === '' || $numero === '') {
                    continue;
                }

                $usadas[$lote] ??= [];
                if (!in_array($numero, $usadas[$lote], true)) {
                    $usadas[$lote][] = $numero;
                }
            }

            return $usadas;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('labObtenerLotesDisponiblesCaptura')) {
    function labObtenerLotesDisponiblesCaptura(?array $contexto = null, ?string $loteActual = null): array
    {
        $pdo = labCapturaConexion();
        $loteActual = trim((string) ($loteActual ?? ''));
        $contexto = $contexto ?? labCapturaContextoAnalisis();
        $muestrasUsadas = labCapturaMuestrasUsadasPorTablaActual();
        $solicitudes = [];

        if (!$pdo || !$contexto) {
            return [
                'lotes' => [],
                'muestras' => [],
                'muestrasUsadas' => $muestrasUsadas,
                'loteActual' => $loteActual,
                'solicitudes' => [],
            ];
        }

        $params = [];
        $condiciones = labCapturaCondicionesContexto($contexto, $params);

        $analisisRequeridosSql = "
            SELECT
                s.id_lote,
                COUNT(DISTINCT sa.id_tipo_analisis) AS analisis_requeridos
            FROM solicitud s
            INNER JOIN solicitud_analisis sa
                ON sa.id_solicitud = s.id_solicitud
            GROUP BY s.id_lote
        ";

        $analisisIngresadosSql = "
            SELECT
                lr.id_lote,
                COUNT(DISTINCT f.id_tipo_analisis) AS analisis_ingresados,
                COUNT(
                    DISTINCT CASE
                        WHEN LOWER(COALESCE(ef.nombre, '')) = 'aprobado'
                        THEN f.id_tipo_analisis
                    END
                ) AS analisis_aprobados
            FROM lote_rango lr
            LEFT JOIN formulario f
                ON f.id_rango = lr.id_rango
            LEFT JOIN estado_formulario ef
                ON ef.id_estado = f.id_estado
            GROUP BY lr.id_lote
        ";

        $sql = "
            SELECT DISTINCT
                l.id_lote,
                l.codigo_lote,
                COALESCE(ar.analisis_requeridos, 0) AS analisis_requeridos,
                COALESCE(ai.analisis_ingresados, 0) AS analisis_ingresados,
                COALESCE(ai.analisis_aprobados, 0) AS analisis_aprobados
            FROM lote l
            LEFT JOIN ({$analisisRequeridosSql}) ar
                ON ar.id_lote = l.id_lote
            LEFT JOIN ({$analisisIngresadosSql}) ai
                ON ai.id_lote = l.id_lote
            INNER JOIN solicitud s
                ON s.id_lote = l.id_lote
            INNER JOIN tipo_muestra tm
                ON tm.id_tipo = s.id_tipo
            INNER JOIN solicitud_analisis sa
                ON sa.id_solicitud = s.id_solicitud
            INNER JOIN tipo_analisis ta
                ON ta.id_tipo = sa.id_tipo_analisis
            WHERE l.codigo_lote IS NOT NULL
              AND l.codigo_lote <> ''
              AND {$condiciones['tipo']}
              AND {$condiciones['analisis']}
            ORDER BY l.codigo_lote ASC, l.id_lote DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lotes = [];
        $eligible = [];
        foreach ($rows as $row) {
            $idLote = (int) ($row['id_lote'] ?? 0);
            $codigoLote = trim((string) ($row['codigo_lote'] ?? ''));
            if ($idLote <= 0 || $codigoLote === '' || isset($eligible[$idLote])) {
                continue;
            }

            $estado = labCalcularEstadoLote(
                $row['analisis_requeridos'] ?? 0,
                $row['analisis_ingresados'] ?? 0,
                $row['analisis_aprobados'] ?? 0
            );

            if (!in_array($estado['codigo'] ?? '', ['pendiente', 'en_proceso'], true)) {
                continue;
            }

            $eligible[$idLote] = true;
            $lotes[] = $codigoLote;
        }

        $esEdicion = false;
        foreach (['id_formulario', 'id_solicitud'] as $key) {
            $valor = $_REQUEST[$key] ?? null;
            if (is_numeric($valor) && (int) $valor > 0) {
                $esEdicion = true;
                break;
            }
        }

        if ($esEdicion && $loteActual !== '' && !in_array($loteActual, $lotes, true)) {
            array_unshift($lotes, $loteActual);
            $lotes = array_values($lotes);
        }

        $lotes = array_values(array_filter($lotes, static function (string $lote): bool {
            return trim($lote) !== '';
        }));
        sort($lotes);

        if ($loteActual !== '' && !in_array($loteActual, $lotes, true) && $esEdicion) {
            array_unshift($lotes, $loteActual);
            $lotes = array_values($lotes);
        }

        if (!$lotes) {
            return [
                'lotes' => [],
                'muestras' => [],
                'muestrasUsadas' => $muestrasUsadas,
                'loteActual' => $loteActual,
                'solicitudes' => [],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($lotes), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT l.codigo_lote, m.codigo_lab, m.numero_muestra
              FROM lote l
              INNER JOIN solicitud s ON s.id_lote = l.id_lote
              INNER JOIN muestra m ON m.id_solicitud = s.id_solicitud
             WHERE l.codigo_lote IN ({$placeholders})
               AND l.codigo_lote IS NOT NULL
               AND l.codigo_lote <> ''
               AND (m.codigo_lab IS NOT NULL OR m.numero_muestra IS NOT NULL)
             ORDER BY l.codigo_lote, m.numero_muestra, m.codigo_lab
        ");
        $stmt->execute($lotes);

        $muestras = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codigoLote = trim((string) ($row['codigo_lote'] ?? ''));
            $numero = trim((string) ($row['codigo_lab'] ?? ''));
            if ($numero === '' && $row['numero_muestra'] !== null) {
                $numero = (string) $row['numero_muestra'];
            }

            if ($codigoLote === '' || $numero === '') {
                continue;
            }

            $muestras[$codigoLote] ??= [];
            if (!in_array($numero, $muestras[$codigoLote], true)) {
                $muestras[$codigoLote][] = $numero;
            }
        }

        foreach ($muestras as $codigoLote => $numeros) {
            $muestras[$codigoLote] = array_values(array_unique($numeros));
        }

        $muestrasOrdenadas = [];
        foreach ($lotes as $codigoLote) {
            $muestrasOrdenadas[$codigoLote] = $muestras[$codigoLote] ?? [];
        }

        $solicitudesPorId = [];
        try {
            $paramsSolicitudes = $lotes;
            $condicionesSolicitudes = labCapturaCondicionesContexto($contexto, $paramsSolicitudes);
            $placeholdersSolicitudes = implode(', ', array_fill(0, count($lotes), '?'));

            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    s.id_solicitud,
                    l.id_lote,
                    l.codigo_lote,
                    tm.nombre AS tipo_muestra
                  FROM lote l
                  INNER JOIN solicitud s ON s.id_lote = l.id_lote
                  INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
                  INNER JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
                  INNER JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
                 WHERE l.codigo_lote IN ({$placeholdersSolicitudes})
                   AND l.codigo_lote IS NOT NULL
                   AND l.codigo_lote <> ''
                   AND {$condicionesSolicitudes['tipo']}
                   AND {$condicionesSolicitudes['analisis']}
                 ORDER BY l.codigo_lote ASC, s.id_solicitud DESC
            ");
            $stmt->execute($paramsSolicitudes);
            $rowsSolicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rowsSolicitudes as $row) {
                $idSolicitud = (int) ($row['id_solicitud'] ?? 0);
                $idLote = (int) ($row['id_lote'] ?? 0);
                $codigoLote = trim((string) ($row['codigo_lote'] ?? ''));
                $tipoMuestra = trim((string) ($row['tipo_muestra'] ?? ''));

                if ($idSolicitud <= 0 || $idLote <= 0 || $codigoLote === '' || isset($solicitudesPorId[$idSolicitud])) {
                    continue;
                }

                $solicitudesPorId[$idSolicitud] = [
                    'id_solicitud' => $idSolicitud,
                    'id_lote' => $idLote,
                    'codigo_lote' => $codigoLote,
                    'tipo_muestra' => $tipoMuestra,
                    'muestras' => [],
                    'muestrasUsadas' => [],
                ];
            }

            if ($solicitudesPorId) {
                $tablaActual = labCapturaTablaActual();
                $muestrasUsadasPorSolicitud = [];

                if ($tablaActual) {
                    $columnasTablaActual = labCapturaColumnasTabla($pdo, $tablaActual);
                    if ($columnasTablaActual && in_array('id_solicitud', $columnasTablaActual, true)) {
                        $exprNumero = null;
                        $joinMuestra = '';

                        if (in_array('no_lab', $columnasTablaActual, true)) {
                            $exprNumero = 't.no_lab';
                        } elseif (in_array('numero_laboratorio', $columnasTablaActual, true)) {
                            if (in_array('id_solicitud', $columnasTablaActual, true)) {
                                $joinMuestra = ' LEFT JOIN muestra m ON m.id_solicitud = t.id_solicitud AND m.numero_muestra = t.numero_laboratorio ';
                                $exprNumero = "COALESCE(m.codigo_lab, CAST(t.numero_laboratorio AS CHAR))";
                            } else {
                                $exprNumero = 'CAST(t.numero_laboratorio AS CHAR)';
                            }
                        } elseif (in_array('numero_muestra', $columnasTablaActual, true)) {
                            $exprNumero = 'CAST(t.numero_muestra AS CHAR)';
                        }

                        if ($exprNumero) {
                            $stmt = $pdo->query("
                                SELECT DISTINCT t.id_solicitud AS id_solicitud, {$exprNumero} AS numero_lab
                                  FROM `{$tablaActual}` t
                                  {$joinMuestra}
                                 WHERE t.id_solicitud IS NOT NULL
                                   AND t.id_solicitud > 0
                                   AND {$exprNumero} IS NOT NULL
                                   AND {$exprNumero} <> ''
                            ");

                            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                $idSolicitud = (int) ($row['id_solicitud'] ?? 0);
                                $numero = trim((string) ($row['numero_lab'] ?? ''));
                                if ($idSolicitud <= 0 || $numero === '') {
                                    continue;
                                }

                                $muestrasUsadasPorSolicitud[$idSolicitud] ??= [];
                                if (!in_array($numero, $muestrasUsadasPorSolicitud[$idSolicitud], true)) {
                                    $muestrasUsadasPorSolicitud[$idSolicitud][] = $numero;
                                }
                            }
                        }
                    }
                }

                $idsSolicitudes = array_keys($solicitudesPorId);
                $placeholdersIds = implode(', ', array_fill(0, count($idsSolicitudes), '?'));
                $stmt = $pdo->prepare("
                    SELECT DISTINCT
                        s.id_solicitud,
                        l.id_lote,
                        l.codigo_lote,
                        tm.nombre AS tipo_muestra,
                        m.codigo_lab,
                        m.numero_muestra
                      FROM lote l
                      INNER JOIN solicitud s ON s.id_lote = l.id_lote
                      INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
                      INNER JOIN muestra m ON m.id_solicitud = s.id_solicitud
                     WHERE s.id_solicitud IN ({$placeholdersIds})
                       AND l.codigo_lote IS NOT NULL
                       AND l.codigo_lote <> ''
                       AND (m.codigo_lab IS NOT NULL OR m.numero_muestra IS NOT NULL)
                     ORDER BY l.codigo_lote, s.id_solicitud, m.numero_muestra, m.codigo_lab
                ");
                $stmt->execute($idsSolicitudes);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $idSolicitud = (int) ($row['id_solicitud'] ?? 0);
                    $codigoLote = trim((string) ($row['codigo_lote'] ?? ''));
                    $numero = trim((string) ($row['codigo_lab'] ?? ''));
                    if ($numero === '' && $row['numero_muestra'] !== null) {
                        $numero = (string) $row['numero_muestra'];
                    }

                    if ($idSolicitud <= 0 || $codigoLote === '' || $numero === '' || !isset($solicitudesPorId[$idSolicitud])) {
                        continue;
                    }

                    $solicitudesPorId[$idSolicitud]['muestras'] ??= [];
                    if (!in_array($numero, $solicitudesPorId[$idSolicitud]['muestras'], true)) {
                        $solicitudesPorId[$idSolicitud]['muestras'][] = $numero;
                    }
                }

                foreach ($solicitudesPorId as $idSolicitud => &$solicitud) {
                    $solicitud['muestras'] = array_values(array_unique($solicitud['muestras']));
                    $solicitud['muestrasUsadas'] = $muestrasUsadasPorSolicitud[$idSolicitud] ?? ($muestrasUsadas[$solicitud['codigo_lote']] ?? []);
                    if (is_array($solicitud['muestrasUsadas'])) {
                        $solicitud['muestrasUsadas'] = array_values(array_unique(array_filter(array_map(static function ($valor): string {
                            return trim((string) $valor);
                        }, $solicitud['muestrasUsadas']), static function (string $valor): bool {
                            return $valor !== '';
                        })));
                    } else {
                        $solicitud['muestrasUsadas'] = [];
                    }
                }
                unset($solicitud);

                $solicitudes = array_values($solicitudesPorId);
            }
        } catch (Throwable $e) {
            $solicitudes = [];
        }

        return [
            'lotes' => $lotes,
            'muestras' => $muestrasOrdenadas,
            'muestrasUsadas' => $muestrasUsadas,
            'loteActual' => $loteActual,
            'solicitudes' => $solicitudes,
        ];
    }
}
