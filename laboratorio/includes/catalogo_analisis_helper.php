<?php

require_once __DIR__ . '/catalogo_muestras_helper.php';

if (!function_exists('labCatalogoAnalisisNormalizarTexto')) {
    function labCatalogoAnalisisNormalizarTexto(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = strtolower($value);
        $value = strtr($value, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
            'Á' => 'a',
            'É' => 'e',
            'Í' => 'i',
            'Ó' => 'o',
            'Ú' => 'u',
            'Ü' => 'u',
            'Ñ' => 'n',
        ]);

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = strtolower($converted);
            }
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}

if (!function_exists('labCatalogoAnalisisClaveModulo')) {
    function labCatalogoAnalisisClaveModulo(string $nombre, ?string $prefijo = null): string
    {
        if ($prefijo !== null && trim((string) $prefijo) !== '') {
            return labCatalogoMuestrasClaveDesdePrefijo($prefijo, $nombre);
        }

        $normalizado = labCatalogoAnalisisNormalizarTexto($nombre);

        switch ($normalizado) {
            case 'suelo':
            case 'suelos':
                return 'suelos';
            case 'agua':
            case 'aguas':
                return 'agua';
            case 'foliar':
            case 'foliares':
                return 'foliares';
            case 'cana':
            case 'canas':
            case 'cañas':
                return 'cana';
            case 'miel':
            case 'mieles':
                return 'miel';
            default:
                return $normalizado !== '' ? $normalizado : 'sin_clasificar';
        }
    }
}

if (!function_exists('labCatalogoAnalisisEtiquetaModulo')) {
    function labCatalogoAnalisisEtiquetaModulo(string $clave): string
    {
        switch ($clave) {
            case 'suelos':
                return 'Suelos';
            case 'agua':
                return 'Agua';
            case 'foliares':
                return 'Foliares';
            case 'cana':
                return 'Caña';
            case 'miel':
                return 'Miel';
            default:
                return ucfirst($clave);
        }
    }
}

if (!function_exists('labCatalogoAnalisisEtiquetaModuloPlural')) {
    function labCatalogoAnalisisEtiquetaModuloPlural(string $clave): string
    {
        switch ($clave) {
            case 'suelos':
                return 'Suelos';
            case 'agua':
                return 'Aguas';
            case 'foliares':
                return 'Foliares';
            case 'cana':
                return 'Caña';
            case 'miel':
                return 'Mieles';
            default:
                return ucfirst($clave);
        }
    }
}

if (!function_exists('labCatalogoAnalisisOrdenModulo')) {
    function labCatalogoAnalisisOrdenModulo(string $clave): int
    {
        static $orden = [
            'suelos' => 10,
            'agua' => 20,
            'foliares' => 30,
            'cana' => 40,
            'miel' => 50,
            'sin_clasificar' => 90,
        ];

        return $orden[$clave] ?? 80;
    }
}

if (!function_exists('labCatalogoAnalisisColumnExists')) {
    function labCatalogoAnalisisColumnExists(PDO $conexion, string $table, string $column): bool
    {
        static $cache = [];
        $cacheKey = $table . '.' . $column;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $stmt = $conexion->prepare(
            'SELECT 1
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
              LIMIT 1'
        );
        $stmt->execute([$table, $column]);

        $cache[$cacheKey] = (bool) $stmt->fetchColumn();

        return $cache[$cacheKey];
    }
}

if (!function_exists('labCatalogoAnalisisAsegurarEsquema')) {
    function labCatalogoAnalisisAsegurarEsquema(PDO $conexion): void
    {
        static $esquemaAsegurado = false;
        if ($esquemaAsegurado) {
            return;
        }

        $columnas = [
            'activo' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER nombre',
            'metodo' => 'VARCHAR(255) NULL AFTER activo',
            'norma' => 'VARCHAR(255) NULL AFTER metodo',
            'equipo_default' => 'VARCHAR(255) NULL AFTER norma',
            'unidad' => 'VARCHAR(80) NULL AFTER equipo_default',
            'limite_min' => 'DECIMAL(18,6) NULL AFTER unidad',
            'limite_max' => 'DECIMAL(18,6) NULL AFTER limite_min',
            'tiempo_estimado_min' => 'INT NULL AFTER limite_max',
        ];

        foreach ($columnas as $columna => $definicion) {
            if (!labCatalogoAnalisisColumnExists($conexion, 'tipo_analisis', $columna)) {
                try {
                    $conexion->exec("ALTER TABLE tipo_analisis ADD COLUMN {$columna} {$definicion}");
                } catch (PDOException $e) {
                    $codigoMysql = (int) ($e->errorInfo[1] ?? 0);
                    if ($codigoMysql !== 1060) {
                        throw $e;
                    }
                }
            }
        }

        $conexion->exec("UPDATE tipo_analisis SET activo = 1 WHERE activo IS NULL");
        $esquemaAsegurado = true;
    }
}

if (!function_exists('labCatalogoAnalisisTipoMuestraOptions')) {
    function labCatalogoAnalisisTipoMuestraOptions(PDO $conexion): array
    {
        $stmt = $conexion->query("
            SELECT id_tipo, nombre, prefijo, COALESCE(activo, 1) AS activo
              FROM tipo_muestra
             ORDER BY CASE UPPER(prefijo)
                WHEN 'S' THEN 10
                WHEN 'A' THEN 20
                WHEN 'F' THEN 30
                WHEN 'C' THEN 40
                WHEN 'M' THEN 50
                ELSE 90
             END, id_tipo ASC
        ");

        $options = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clave = labCatalogoAnalisisClaveModulo((string) ($row['nombre'] ?? ''), (string) ($row['prefijo'] ?? ''));
            $options[] = [
                'id_tipo' => (int) $row['id_tipo'],
                'nombre' => (string) ($row['nombre'] ?? ''),
                'clave' => $clave,
                'label' => labCatalogoAnalisisEtiquetaModuloPlural($clave),
                'prefijo' => (string) ($row['prefijo'] ?? ''),
                'activo' => (int) ($row['activo'] ?? 1) === 1,
            ];
        }

        return $options;
    }
}

if (!function_exists('labCatalogoAnalisisFilas')) {
    function labCatalogoAnalisisFilas(PDO $conexion, bool $soloActivas = false): array
    {
        labCatalogoAnalisisAsegurarEsquema($conexion);

        $stmt = $conexion->query("
            SELECT
                ta.id_tipo,
                ta.id_tipo_muestra,
                ta.nombre,
                COALESCE(ta.activo, 1) AS activo,
                ta.metodo,
                ta.norma,
                ta.equipo_default,
                ta.unidad,
                ta.limite_min,
                ta.limite_max,
                ta.tiempo_estimado_min,
                tm.nombre AS nombre_muestra,
                tm.prefijo AS prefijo_muestra
            FROM tipo_analisis ta
            LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
        ");

        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($soloActivas) {
            $filas = array_values(array_filter($filas, static function (array $fila): bool {
                return (int) ($fila['activo'] ?? 1) === 1;
            }));
        }

        usort($filas, static function (array $a, array $b): int {
            $ordenA = labCatalogoAnalisisOrdenModulo(labCatalogoAnalisisClaveModulo((string) ($a['nombre_muestra'] ?? ''), (string) ($a['prefijo_muestra'] ?? '')));
            $ordenB = labCatalogoAnalisisOrdenModulo(labCatalogoAnalisisClaveModulo((string) ($b['nombre_muestra'] ?? ''), (string) ($b['prefijo_muestra'] ?? '')));

            if ($ordenA !== $ordenB) {
                return $ordenA <=> $ordenB;
            }

            $activoA = (int) ($a['activo'] ?? 1);
            $activoB = (int) ($b['activo'] ?? 1);
            if ($activoA !== $activoB) {
                return $activoB <=> $activoA;
            }

            $nombreA = labCatalogoAnalisisNormalizarTexto((string) ($a['nombre'] ?? ''));
            $nombreB = labCatalogoAnalisisNormalizarTexto((string) ($b['nombre'] ?? ''));
            if ($nombreA !== $nombreB) {
                return $nombreA <=> $nombreB;
            }

            return (int) ($a['id_tipo'] ?? 0) <=> (int) ($b['id_tipo'] ?? 0);
        });

        return $filas;
    }
}

if (!function_exists('labCatalogoAnalisisAgrupar')) {
    function labCatalogoAnalisisAgrupar(array $filas, bool $deduplicarNombres = false): array
    {
        $grupos = [];
        $ordenBase = ['suelos', 'agua', 'foliares', 'cana', 'miel'];

        foreach ($ordenBase as $clave) {
            $grupos[$clave] = [
                'key' => $clave,
                'label' => labCatalogoAnalisisEtiquetaModulo($clave),
                'label_plural' => labCatalogoAnalisisEtiquetaModuloPlural($clave),
                'items' => [],
                'total' => 0,
                'activos' => 0,
                'inactivos' => 0,
            ];
        }

        $vistos = [];

        foreach ($filas as $fila) {
            $clave = labCatalogoAnalisisClaveModulo((string) ($fila['nombre_muestra'] ?? ''), (string) ($fila['prefijo_muestra'] ?? ''));
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'key' => $clave,
                    'label' => labCatalogoAnalisisEtiquetaModulo($clave),
                    'label_plural' => labCatalogoAnalisisEtiquetaModuloPlural($clave),
                    'items' => [],
                    'total' => 0,
                    'activos' => 0,
                    'inactivos' => 0,
                ];
            }

            $nombre = (string) ($fila['nombre'] ?? '');
            $activo = (int) ($fila['activo'] ?? 1) === 1;
            $normalizedNombre = labCatalogoAnalisisNormalizarTexto($nombre);

            if ($deduplicarNombres) {
                $vistos[$clave] ??= [];
                if (isset($vistos[$clave][$normalizedNombre])) {
                    continue;
                }
                $vistos[$clave][$normalizedNombre] = true;
            }

            $item = [
                'id_tipo' => (int) ($fila['id_tipo'] ?? 0),
                'id_tipo_muestra' => (int) ($fila['id_tipo_muestra'] ?? 0),
                'nombre' => $nombre,
                'activo' => $activo,
                'metodo' => (string) ($fila['metodo'] ?? ''),
                'norma' => (string) ($fila['norma'] ?? ''),
                'equipo_default' => (string) ($fila['equipo_default'] ?? ''),
                'unidad' => (string) ($fila['unidad'] ?? ''),
                'limite_min' => $fila['limite_min'] !== null ? (float) $fila['limite_min'] : null,
                'limite_max' => $fila['limite_max'] !== null ? (float) $fila['limite_max'] : null,
                'tiempo_estimado_min' => $fila['tiempo_estimado_min'] !== null ? (int) $fila['tiempo_estimado_min'] : null,
                'tipo' => labCatalogoAnalisisEtiquetaModulo($clave),
                'tipo_plural' => labCatalogoAnalisisEtiquetaModuloPlural($clave),
            ];

            $grupos[$clave]['items'][] = $item;
            $grupos[$clave]['total']++;
            if ($activo) {
                $grupos[$clave]['activos']++;
            } else {
                $grupos[$clave]['inactivos']++;
            }
        }

        $ordenados = [];
        foreach ($ordenBase as $clave) {
            $ordenados[$clave] = $grupos[$clave];
        }

        foreach ($grupos as $clave => $grupo) {
            if (!isset($ordenados[$clave])) {
                $ordenados[$clave] = $grupo;
            }
        }

        return $ordenados;
    }
}

if (!function_exists('labCatalogoAnalisisFormularioData')) {
    function labCatalogoAnalisisFormularioData(PDO $conexion): array
    {
        $filas = labCatalogoAnalisisFilas($conexion, true);
        $grupos = labCatalogoAnalisisAgrupar($filas, true);

        $resultado = [];
        foreach ($grupos as $clave => $grupo) {
            $resultado[$clave] = [
                'key' => $grupo['key'],
                'label' => $grupo['label'],
                'items' => array_map(static function (array $item) use ($grupo): array {
                    return [
                        'id_tipo' => $item['id_tipo'],
                        'nombre' => $item['nombre'],
                        'tipo' => $grupo['label'],
                    ];
                }, $grupo['items']),
            ];
        }

        return $resultado;
    }
}

if (!function_exists('labCatalogoAnalisisObtenerPorId')) {
    function labCatalogoAnalisisObtenerPorId(PDO $conexion, int $idTipo): ?array
    {
        labCatalogoAnalisisAsegurarEsquema($conexion);

        $stmt = $conexion->prepare("
            SELECT
                ta.id_tipo,
                ta.id_tipo_muestra,
                ta.nombre,
                COALESCE(ta.activo, 1) AS activo,
                ta.metodo,
                ta.norma,
                ta.equipo_default,
                ta.unidad,
                ta.limite_min,
                ta.limite_max,
                ta.tiempo_estimado_min,
                tm.nombre AS nombre_muestra,
                tm.prefijo AS prefijo_muestra
            FROM tipo_analisis ta
            LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
            WHERE ta.id_tipo = ?
            LIMIT 1
        ");
        $stmt->execute([$idTipo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }
}

if (!function_exists('labCatalogoAnalisisGuardar')) {
    function labCatalogoAnalisisGuardar(
        PDO $conexion,
        ?int $idTipo,
        int $idTipoMuestra,
        string $nombre,
        int $activo,
        array $metadatos = []
    ): int
    {
        labCatalogoAnalisisAsegurarEsquema($conexion);

        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre del análisis no puede estar vacío.');
        }

        if ($idTipo === null || $idTipo <= 0) {
            throw new InvalidArgumentException('La creación de nuevos tipos de análisis todavía está pendiente.');
        }

        $stmt = $conexion->prepare("
            UPDATE tipo_analisis
               SET id_tipo_muestra = ?, nombre = ?, activo = ?,
                   metodo = ?, norma = ?, equipo_default = ?, unidad = ?,
                   limite_min = ?, limite_max = ?, tiempo_estimado_min = ?
             WHERE id_tipo = ?
        ");
        $stmt->execute([
            $idTipoMuestra,
            $nombre,
            $activo,
            trim((string) ($metadatos['metodo'] ?? '')) ?: null,
            trim((string) ($metadatos['norma'] ?? '')) ?: null,
            trim((string) ($metadatos['equipo_default'] ?? '')) ?: null,
            trim((string) ($metadatos['unidad'] ?? '')) ?: null,
            $metadatos['limite_min'] ?? null,
            $metadatos['limite_max'] ?? null,
            $metadatos['tiempo_estimado_min'] ?? null,
            $idTipo,
        ]);

        return $idTipo;
    }
}

if (!function_exists('labCatalogoAnalisisCambiarEstado')) {
    function labCatalogoAnalisisCambiarEstado(PDO $conexion, int $idTipo, int $activo): bool
    {
        labCatalogoAnalisisAsegurarEsquema($conexion);

        $stmt = $conexion->prepare("
            UPDATE tipo_analisis
               SET activo = ?
             WHERE id_tipo = ?
        ");

        return $stmt->execute([$activo, $idTipo]);
    }
}
