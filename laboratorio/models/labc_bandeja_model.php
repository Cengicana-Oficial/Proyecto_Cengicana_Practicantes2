<?php

require_once __DIR__ . '/../includes/catalogo_analisis_helper.php';
require_once __DIR__ . '/../includes/catalogo_muestras_helper.php';
require_once __DIR__ . '/../includes/estado_lote_helper.php';

if (!function_exists('labcBandejaNormalizarTexto')) {
    function labcBandejaNormalizarTexto(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = labCatalogoAnalisisNormalizarTexto($value);
        return $value;
    }
}

if (!function_exists('labcBandejaEtiquetaRango')) {
    function labcBandejaEtiquetaRango(?int $inicio, ?int $fin): string
    {
        $inicio = $inicio !== null ? (int) $inicio : 0;
        $fin = $fin !== null ? (int) $fin : 0;

        if ($inicio <= 0 && $fin <= 0) {
            return 'Sin rango';
        }

        if ($inicio > 0 && $fin > 0 && $inicio !== $fin) {
            return 'Lab ' . $inicio . ' - ' . $fin;
        }

        $valor = $inicio > 0 ? $inicio : $fin;

        return 'Lab ' . $valor;
    }
}

if (!function_exists('labcBandejaTipoMuestra')) {
    function labcBandejaTipoMuestra(PDO $pdo, int $idTipoMuestra): ?array
    {
        $tipo = labCatalogoMuestrasObtenerPorId($pdo, $idTipoMuestra);
        if (!$tipo) {
            return null;
        }

        return [
            'id_tipo' => (int) ($tipo['id_tipo'] ?? 0),
            'nombre' => (string) ($tipo['nombre'] ?? ''),
            'prefijo' => (string) ($tipo['prefijo'] ?? ''),
            'clave' => (string) ($tipo['clave'] ?? ''),
            'label' => (string) ($tipo['label'] ?? ''),
            'label_plural' => (string) ($tipo['label_plural'] ?? ''),
            'activo' => (int) ($tipo['activo'] ?? 1) === 1,
        ];
    }
}

if (!function_exists('labcBandejaResolverAnalisisPorRegistro')) {
    function labcBandejaResolverAnalisisPorRegistro(array $catalogoAnalisis, int $idTipoMuestra, array $registros): array
    {
        $descriptores = [];
        foreach ($registros as $registro) {
            $key = (string) ($registro['key'] ?? '');
            $label = trim((string) ($registro['label'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }

            $aliases = [$label];
            if (!empty($registro['aliases']) && is_array($registro['aliases'])) {
                foreach ($registro['aliases'] as $alias) {
                    $alias = trim((string) $alias);
                    if ($alias !== '') {
                        $aliases[] = $alias;
                    }
                }
            }

            $aliases[] = $key;
            $aliases = array_values(array_unique(array_filter(array_map('labcBandejaNormalizarTexto', $aliases))));

            $descriptores[$key] = [
                'key' => $key,
                'label' => $label,
                'href' => (string) ($registro['href'] ?? '#'),
                'icon' => (string) ($registro['icon'] ?? 'fa-flask'),
                'aliases' => $aliases,
                'id_tipo_analisis' => null,
            ];
        }

        if (!$descriptores) {
            return [];
        }

        $catalogoFiltrado = array_values(array_filter($catalogoAnalisis, static function (array $fila) use ($idTipoMuestra): bool {
            return (int) ($fila['id_tipo_muestra'] ?? 0) === $idTipoMuestra;
        }));

        foreach ($catalogoFiltrado as $filaCatalogo) {
            $nombreCatalogo = labcBandejaNormalizarTexto((string) ($filaCatalogo['nombre'] ?? ''));
            if ($nombreCatalogo === '') {
                continue;
            }

            foreach ($descriptores as &$descriptor) {
                if ($descriptor['id_tipo_analisis'] !== null) {
                    continue;
                }

                if (in_array($nombreCatalogo, $descriptor['aliases'], true)) {
                    $descriptor['id_tipo_analisis'] = (int) ($filaCatalogo['id_tipo'] ?? 0);
                    break;
                }
            }
            unset($descriptor);
        }

        return $descriptores;
    }
}

if (!function_exists('labcBandejaConsultarAnalisisPendientes')) {
    function labcBandejaConsultarAnalisisPendientes(PDO $pdo, int $idTipoMuestra, array $analisisRegistro): array
    {
        $tipoMuestra = labcBandejaTipoMuestra($pdo, $idTipoMuestra);
        if (!$tipoMuestra) {
            return [
                'tipo_muestra' => null,
                'analisis' => [],
                'resumen' => [
                    'analisis_pendientes' => 0,
                    'lotes_pendientes' => 0,
                    'muestras_pendientes' => 0,
                ],
            ];
        }

        $catalogoAnalisis = labCatalogoAnalisisFilas($pdo, true);
        $descriptores = labcBandejaResolverAnalisisPorRegistro($catalogoAnalisis, $idTipoMuestra, $analisisRegistro);

        $idsAnalisis = [];
        $clavePorId = [];
        foreach ($descriptores as $descriptor) {
            $idAnalisis = (int) ($descriptor['id_tipo_analisis'] ?? 0);
            if ($idAnalisis <= 0) {
                continue;
            }

            $idsAnalisis[] = $idAnalisis;
            $clavePorId[$idAnalisis] = (string) ($descriptor['key'] ?? '');
        }

        $idsAnalisis = array_values(array_unique($idsAnalisis));
        if (!$idsAnalisis) {
            return [
                'tipo_muestra' => $tipoMuestra,
                'analisis' => [],
                'resumen' => [
                    'analisis_pendientes' => 0,
                    'lotes_pendientes' => 0,
                    'muestras_pendientes' => 0,
                ],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($idsAnalisis), '?'));

        $stmt = $pdo->prepare("
            SELECT
                ta.id_tipo AS id_tipo_analisis,
                ta.nombre AS nombre_analisis,
                l.id_lote,
                l.codigo_lote,
                lr.id_rango,
                lr.inicio,
                lr.fin,
                COALESCE(MAX(s.numero_muestras), 0) AS numero_muestras,
                COUNT(DISTINCT f.id_formulario) AS formularios_total,
                COUNT(DISTINCT CASE
                    WHEN LOWER(COALESCE(ef.nombre, '')) = 'aprobado'
                    THEN f.id_formulario
                END) AS formularios_aprobados
            FROM tipo_analisis ta
            INNER JOIN solicitud_analisis sa
                    ON sa.id_tipo_analisis = ta.id_tipo
            INNER JOIN solicitud s
                    ON s.id_solicitud = sa.id_solicitud
                   AND s.id_tipo = ta.id_tipo_muestra
            INNER JOIN lote l
                    ON l.id_lote = s.id_lote
            INNER JOIN lote_rango lr
                    ON lr.id_lote = l.id_lote
            LEFT JOIN formulario f
                   ON f.id_rango = lr.id_rango
                  AND f.id_tipo_analisis = ta.id_tipo
            LEFT JOIN estado_formulario ef
                   ON ef.id_estado = f.id_estado
            WHERE ta.id_tipo_muestra = ?
              AND COALESCE(ta.activo, 1) = 1
              AND ta.id_tipo IN ({$placeholders})
            GROUP BY
                ta.id_tipo,
                ta.nombre,
                l.id_lote,
                l.codigo_lote,
                lr.id_rango,
                lr.inicio,
                lr.fin
            ORDER BY
                ta.nombre ASC,
                l.codigo_lote ASC,
                lr.inicio ASC,
                lr.fin ASC
        ");

        $params = array_merge([$idTipoMuestra], $idsAnalisis);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $analisis = [];
        foreach ($descriptores as $descriptor) {
            $key = (string) ($descriptor['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $analisis[$key] = [
                'key' => $key,
                'id_tipo_analisis' => (int) ($descriptor['id_tipo_analisis'] ?? 0),
                'nombre' => (string) ($descriptor['label'] ?? ''),
                'href' => (string) ($descriptor['href'] ?? '#'),
                'icon' => (string) ($descriptor['icon'] ?? 'fa-flask'),
                'estado' => [
                    'codigo' => 'pendiente',
                    'texto' => 'Pendiente',
                    'clase' => 'estado-pendiente',
                ],
                'progreso' => [
                    'requeridos' => 0,
                    'aprobados' => 0,
                    'porcentaje' => 0,
                    'texto' => '0 / 0',
                ],
                'lotes_pendientes' => [],
                'lotes_distintos' => 0,
                'muestras_pendientes' => 0,
                'muestras_total' => 0,
                'tiene_formularios' => false,
                'accion' => 'Capturar',
            ];
        }

        foreach ($rows as $row) {
            $idAnalisis = (int) ($row['id_tipo_analisis'] ?? 0);
            $key = $clavePorId[$idAnalisis] ?? '';
            if ($key === '' || !isset($analisis[$key])) {
                continue;
            }

            $formulariosTotal = max(0, (int) ($row['formularios_total'] ?? 0));
            $formulariosAprobados = max(0, (int) ($row['formularios_aprobados'] ?? 0));
            $numeroMuestras = max(0, (int) ($row['numero_muestras'] ?? 0));
            $tieneFormularios = $formulariosTotal > 0;
            $completado = $formulariosTotal > 0 && $formulariosAprobados >= $formulariosTotal;

            $analisis[$key]['lotes_distintos']++;
            $analisis[$key]['muestras_total'] += $numeroMuestras;
            $analisis[$key]['tiene_formularios'] = $analisis[$key]['tiene_formularios'] || $tieneFormularios;
            $analisis[$key]['progreso']['requeridos']++;
            if ($tieneFormularios) {
                $analisis[$key]['progreso']['aprobados']++;
            }

            if ($completado) {
                continue;
            }

            if ($formulariosTotal > 0) {
                $analisis[$key]['progreso']['porcentaje'] = 0;
            }

            $analisis[$key]['lotes_pendientes'][] = [
                'id_lote' => (int) ($row['id_lote'] ?? 0),
                'codigo_lote' => trim((string) ($row['codigo_lote'] ?? '')),
                'id_rango' => (int) ($row['id_rango'] ?? 0),
                'rango_inicio' => isset($row['inicio']) ? (int) $row['inicio'] : null,
                'rango_fin' => isset($row['fin']) ? (int) $row['fin'] : null,
                'numero_muestras' => $numeroMuestras,
                'formularios_total' => $formulariosTotal,
                'formularios_aprobados' => $formulariosAprobados,
                'estado' => $tieneFormularios
                    ? [
                        'codigo' => 'en_proceso',
                        'texto' => 'Continuar',
                        'clase' => 'estado-en-proceso',
                    ]
                    : [
                        'codigo' => 'pendiente',
                        'texto' => 'Capturar',
                        'clase' => 'estado-pendiente',
                    ],
            ];
        }

        $analisis = array_filter($analisis, static function (array $item): bool {
            return !empty($item['lotes_pendientes']);
        });

        foreach ($analisis as &$item) {
            usort($item['lotes_pendientes'], static function (array $left, array $right): int {
                $codigoLeft = (string) ($left['codigo_lote'] ?? '');
                $codigoRight = (string) ($right['codigo_lote'] ?? '');

                return strnatcasecmp($codigoLeft, $codigoRight)
                    ?: ((int) ($left['id_rango'] ?? 0) <=> (int) ($right['id_rango'] ?? 0));
            });

            $requeridos = max(0, (int) ($item['lotes_distintos'] ?? 0));
            $aprobados = 0;
            $formulariosIngresados = 0;
            $muestrasPendientes = 0;

            foreach ($item['lotes_pendientes'] as $chip) {
                $muestrasPendientes += max(0, (int) ($chip['numero_muestras'] ?? 0));
                if ((int) ($chip['formularios_total'] ?? 0) > 0) {
                    $formulariosIngresados++;
                }
                if ((int) ($chip['formularios_total'] ?? 0) > 0 && (int) ($chip['formularios_aprobados'] ?? 0) >= (int) ($chip['formularios_total'] ?? 0)) {
                    $aprobados++;
                }
            }

            $item['muestras_pendientes'] = $muestrasPendientes;
            $item['estado'] = labCalcularEstadoLote($requeridos, $formulariosIngresados, $aprobados);
            $item['progreso'] = [
                'requeridos' => $requeridos,
                'aprobados' => $aprobados,
                'porcentaje' => $requeridos > 0 ? (int) round(($aprobados / $requeridos) * 100) : 0,
                'texto' => $aprobados . ' / ' . $requeridos,
            ];
            $item['accion'] = $formulariosIngresados > 0 ? 'Continuar' : 'Capturar';
        }
        unset($item);

        uasort($analisis, static function (array $left, array $right): int {
            $prioridad = static function (string $codigo): int {
                switch ($codigo) {
                    case 'revision':
                        return 10;
                    case 'en_proceso':
                        return 20;
                    case 'pendiente':
                        return 30;
                    default:
                        return 90;
                }
            };

            $estadoLeft = (string) ($left['estado']['codigo'] ?? 'pendiente');
            $estadoRight = (string) ($right['estado']['codigo'] ?? 'pendiente');
            $cmpEstado = $prioridad($estadoLeft) <=> $prioridad($estadoRight);
            if ($cmpEstado !== 0) {
                return $cmpEstado;
            }

            $nombreLeft = labcBandejaNormalizarTexto((string) ($left['nombre'] ?? ''));
            $nombreRight = labcBandejaNormalizarTexto((string) ($right['nombre'] ?? ''));
            if ($nombreLeft !== $nombreRight) {
                return $nombreLeft <=> $nombreRight;
            }

            return (int) ($left['id_tipo_analisis'] ?? 0) <=> (int) ($right['id_tipo_analisis'] ?? 0);
        });

        $analisis = array_values($analisis);
        $lotesPendientes = 0;
        $muestrasPendientes = 0;
        foreach ($analisis as $item) {
            $lotesPendientes += (int) ($item['lotes_distintos'] ?? 0);
            $muestrasPendientes += (int) ($item['muestras_pendientes'] ?? 0);
        }

        return [
            'tipo_muestra' => $tipoMuestra,
            'analisis' => $analisis,
            'resumen' => [
                'analisis_pendientes' => count($analisis),
                'lotes_pendientes' => $lotesPendientes,
                'muestras_pendientes' => $muestrasPendientes,
            ],
        ];
    }
}

