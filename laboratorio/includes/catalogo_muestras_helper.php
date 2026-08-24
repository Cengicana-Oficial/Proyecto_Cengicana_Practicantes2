<?php

if (!function_exists('labCatalogoMuestrasNormalizarTexto')) {
    function labCatalogoMuestrasNormalizarTexto(string $value): string
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

if (!function_exists('labCatalogoMuestrasClaveDesdePrefijo')) {
    function labCatalogoMuestrasClaveDesdePrefijo(?string $prefijo, ?string $nombre = null): string
    {
        $prefijo = strtoupper(trim((string) $prefijo));

        $mapaPrefijos = [
            'S' => 'suelos',
            'F' => 'foliares',
            'C' => 'cana',
            'M' => 'miel',
            'A' => 'agua',
        ];

        if (isset($mapaPrefijos[$prefijo])) {
            return $mapaPrefijos[$prefijo];
        }

        $normalizado = labCatalogoMuestrasNormalizarTexto((string) $nombre);

        switch ($normalizado) {
            case 'suelo':
            case 'suelos':
                return 'suelos';
            case 'foliar':
            case 'foliares':
                return 'foliares';
            case 'cana':
            case 'canas':
            case 'caña':
            case 'cañas':
                return 'cana';
            case 'miel':
            case 'mieles':
                return 'miel';
            case 'agua':
            case 'aguas':
                return 'agua';
            default:
                return $normalizado !== '' ? $normalizado : 'sin_clasificar';
        }
    }
}

if (!function_exists('labCatalogoMuestrasPrefijoDesdeClave')) {
    function labCatalogoMuestrasPrefijoDesdeClave(string $clave): string
    {
        static $mapa = [
            'suelos' => 'S',
            'foliares' => 'F',
            'cana' => 'C',
            'miel' => 'M',
            'agua' => 'A',
        ];

        return $mapa[$clave] ?? strtoupper(substr($clave, 0, 1));
    }
}

if (!function_exists('labCatalogoMuestrasEtiquetaModulo')) {
    function labCatalogoMuestrasEtiquetaModulo(string $clave): string
    {
        switch ($clave) {
            case 'suelos':
                return 'Suelos';
            case 'foliares':
                return 'Foliares';
            case 'cana':
                return 'Caña';
            case 'miel':
                return 'Miel';
            case 'agua':
                return 'Agua';
            default:
                return ucfirst($clave);
        }
    }
}

if (!function_exists('labCatalogoMuestrasEtiquetaModuloPlural')) {
    function labCatalogoMuestrasEtiquetaModuloPlural(string $clave): string
    {
        switch ($clave) {
            case 'suelos':
                return 'Suelos';
            case 'foliares':
                return 'Foliares';
            case 'cana':
                return 'Cañas';
            case 'miel':
                return 'Mieles';
            case 'agua':
                return 'Aguas';
            default:
                return ucfirst($clave);
        }
    }
}

if (!function_exists('labCatalogoMuestrasOrdenModulo')) {
    function labCatalogoMuestrasOrdenModulo(string $clave): int
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

if (!function_exists('labCatalogoMuestrasColumnExists')) {
    function labCatalogoMuestrasColumnExists(PDO $conexion, string $table, string $column): bool
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

if (!function_exists('labCatalogoMuestrasAsegurarEsquema')) {
    function labCatalogoMuestrasAsegurarEsquema(PDO $conexion): void
    {
        try {
            if (!labCatalogoMuestrasColumnExists($conexion, 'tipo_muestra', 'activo')) {
                try {
                    $conexion->exec("ALTER TABLE tipo_muestra ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER prefijo");
                } catch (PDOException $e) {
                    if ((int) $e->getCode() !== 1060) {
                        throw $e;
                    }
                }
            }

            $conexion->exec("UPDATE tipo_muestra SET activo = 1 WHERE activo IS NULL");
        } catch (Throwable $e) {
            error_log('[laboratorio][schema:catalogo_muestras] ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('labCatalogoMuestrasFilas')) {
    function labCatalogoMuestrasFilas(PDO $conexion, bool $soloActivas = false): array
    {
        labCatalogoMuestrasAsegurarEsquema($conexion);

        $stmt = $conexion->query("
            SELECT
                tm.id_tipo,
                tm.nombre,
                tm.prefijo,
                COALESCE(tm.activo, 1) AS activo,
                (
                    SELECT COUNT(*)
                    FROM tipo_analisis ta
                    WHERE ta.id_tipo_muestra = tm.id_tipo
                ) AS total_analisis,
                (
                    SELECT COUNT(*)
                    FROM tipo_analisis ta
                    WHERE ta.id_tipo_muestra = tm.id_tipo
                      AND COALESCE(ta.activo, 1) = 1
                ) AS analisis_activos
            FROM tipo_muestra tm
            ORDER BY CASE UPPER(tm.prefijo)
                WHEN 'S' THEN 10
                WHEN 'A' THEN 20
                WHEN 'F' THEN 30
                WHEN 'C' THEN 40
                WHEN 'M' THEN 50
                ELSE 90
            END, tm.id_tipo ASC
        ");

        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($soloActivas) {
            $filas = array_values(array_filter($filas, static function (array $fila): bool {
                return (int) ($fila['activo'] ?? 1) === 1;
            }));
        }

        foreach ($filas as &$fila) {
            $fila['clave'] = labCatalogoMuestrasClaveDesdePrefijo(
                (string) ($fila['prefijo'] ?? ''),
                (string) ($fila['nombre'] ?? '')
            );
            $fila['label'] = labCatalogoMuestrasEtiquetaModulo($fila['clave']);
            $fila['label_plural'] = labCatalogoMuestrasEtiquetaModuloPlural($fila['clave']);
        }
        unset($fila);

        return $filas;
    }
}

if (!function_exists('labCatalogoMuestrasFormularioData')) {
    function labCatalogoMuestrasFormularioData(PDO $conexion, bool $soloActivas = true): array
    {
        $filas = labCatalogoMuestrasFilas($conexion, $soloActivas);
        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[$fila['clave']] = [
                'id_tipo' => (int) ($fila['id_tipo'] ?? 0),
                'nombre' => (string) ($fila['nombre'] ?? ''),
                'prefijo' => (string) ($fila['prefijo'] ?? ''),
                'clave' => (string) ($fila['clave'] ?? ''),
                'label' => (string) ($fila['label'] ?? ''),
                'label_plural' => (string) ($fila['label_plural'] ?? ''),
                'activo' => (int) ($fila['activo'] ?? 1) === 1,
                'analisis_total' => (int) ($fila['total_analisis'] ?? 0),
                'analisis_activos' => (int) ($fila['analisis_activos'] ?? 0),
            ];
        }

        return $resultado;
    }
}

if (!function_exists('labCatalogoMuestrasObtenerPorId')) {
    function labCatalogoMuestrasObtenerPorId(PDO $conexion, int $idTipo): ?array
    {
        labCatalogoMuestrasAsegurarEsquema($conexion);

        $stmt = $conexion->prepare("
            SELECT
                tm.id_tipo,
                tm.nombre,
                tm.prefijo,
                COALESCE(tm.activo, 1) AS activo,
                (
                    SELECT COUNT(*)
                    FROM tipo_analisis ta
                    WHERE ta.id_tipo_muestra = tm.id_tipo
                ) AS total_analisis,
                (
                    SELECT COUNT(*)
                    FROM tipo_analisis ta
                    WHERE ta.id_tipo_muestra = tm.id_tipo
                      AND COALESCE(ta.activo, 1) = 1
                ) AS analisis_activos
            FROM tipo_muestra tm
            WHERE tm.id_tipo = ?
            LIMIT 1
        ");
        $stmt->execute([$idTipo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            return null;
        }

        $fila['clave'] = labCatalogoMuestrasClaveDesdePrefijo(
            (string) ($fila['prefijo'] ?? ''),
            (string) ($fila['nombre'] ?? '')
        );
        $fila['label'] = labCatalogoMuestrasEtiquetaModulo($fila['clave']);
        $fila['label_plural'] = labCatalogoMuestrasEtiquetaModuloPlural($fila['clave']);

        return $fila;
    }
}

if (!function_exists('labCatalogoMuestrasObtenerPorClave')) {
    function labCatalogoMuestrasObtenerPorClave(PDO $conexion, string $clave, bool $soloActivos = true): ?array
    {
        labCatalogoMuestrasAsegurarEsquema($conexion);

        $prefijo = labCatalogoMuestrasPrefijoDesdeClave($clave);

        $sql = "
            SELECT
                tm.id_tipo,
                tm.nombre,
                tm.prefijo,
                COALESCE(tm.activo, 1) AS activo
            FROM tipo_muestra tm
            WHERE UPPER(tm.prefijo) = UPPER(?)
        ";
        if ($soloActivos) {
            $sql .= " AND COALESCE(tm.activo, 1) = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $conexion->prepare($sql);
        $stmt->execute([$prefijo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            return null;
        }

        $fila['clave'] = labCatalogoMuestrasClaveDesdePrefijo(
            (string) ($fila['prefijo'] ?? ''),
            (string) ($fila['nombre'] ?? '')
        );
        $fila['label'] = labCatalogoMuestrasEtiquetaModulo($fila['clave']);
        $fila['label_plural'] = labCatalogoMuestrasEtiquetaModuloPlural($fila['clave']);

        return $fila;
    }
}

if (!function_exists('labCatalogoMuestrasGuardar')) {
    function labCatalogoMuestrasGuardar(PDO $conexion, ?int $idTipo, string $nombre, int $activo): int
    {
        labCatalogoMuestrasAsegurarEsquema($conexion);

        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre del tipo de muestra no puede estar vacío.');
        }

        if ($idTipo === null || $idTipo <= 0) {
            throw new InvalidArgumentException('La creación de nuevos tipos de muestra todavía está pendiente.');
        }

        $stmtActual = $conexion->prepare("SELECT prefijo FROM tipo_muestra WHERE id_tipo = ? LIMIT 1");
        $stmtActual->execute([$idTipo]);
        $prefijoActual = (string) ($stmtActual->fetchColumn() ?: '');
        if ($prefijoActual === '') {
            throw new RuntimeException('No se encontró el tipo de muestra a editar.');
        }

        $stmt = $conexion->prepare("
            UPDATE tipo_muestra
               SET nombre = ?, activo = ?
             WHERE id_tipo = ?
        ");
        $stmt->execute([$nombre, $activo, $idTipo]);

        return $idTipo;
    }
}

if (!function_exists('labCatalogoMuestrasCambiarEstado')) {
    function labCatalogoMuestrasCambiarEstado(PDO $conexion, int $idTipo, int $activo): bool
    {
        labCatalogoMuestrasAsegurarEsquema($conexion);

        $stmt = $conexion->prepare("
            UPDATE tipo_muestra
               SET activo = ?
             WHERE id_tipo = ?
        ");

        return $stmt->execute([$activo, $idTipo]);
    }
}
