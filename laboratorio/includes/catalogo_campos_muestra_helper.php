<?php

/**
 * catalogo_campos_muestra_helper.php
 *
 * Soporte de datos para la pestaña "Campos por tipo de muestra" del
 * catálogo (catalogo_analisis.php). Permite definir campos
 * personalizados/adicionales que aplican a cada tipo de muestra
 * (nombre técnico, etiqueta visible, tipo de dato, unidad, obligatorio,
 * orden, estado y opciones para los campos de tipo lista).
 *
 * Persistencia: tabla `tipo_muestra_campo` (migración
 * database/005_campos_tipo_muestra.sql). El esquema se asegura en
 * runtime con labCamposMuestraAsegurarEsquema(), siguiendo el mismo
 * patrón que labSolicitudHistorialAsegurarEsquema().
 */

if (!function_exists('labCamposMuestraTiposDato')) {
    /**
     * Catálogo de tipos de dato admitidos para un campo.
     *
     * @return array<string,string> clave => etiqueta visible
     */
    function labCamposMuestraTiposDato(): array
    {
        return [
            'texto'    => 'Texto',
            'numero'   => 'Número',
            'lista'    => 'Lista de opciones',
            'fecha'    => 'Fecha',
            'booleano' => 'Booleano (sí / no)',
        ];
    }
}

if (!function_exists('labCamposMuestraTipoDatoLabel')) {
    function labCamposMuestraTipoDatoLabel(string $clave): string
    {
        $mapa = labCamposMuestraTiposDato();

        return $mapa[$clave] ?? ucfirst($clave);
    }
}

if (!function_exists('labCamposMuestraAsegurarEsquema')) {
    function labCamposMuestraAsegurarEsquema(PDO $conexion): void
    {
        static $esquemaAsegurado = false;
        if ($esquemaAsegurado) {
            return;
        }

        $rutaMigracion = __DIR__ . '/../database/005_campos_tipo_muestra.sql';
        $sql = is_file($rutaMigracion) ? file_get_contents($rutaMigracion) : false;

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('No se encontró la migración de campos por tipo de muestra.');
        }

        try {
            $conexion->exec($sql);
            // Campos predefinidos (los de la etiqueta de Identificación): no se eliminan ni cambian de nombre técnico.
            $existe = $conexion->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipo_muestra_campo' AND COLUMN_NAME = 'predefinido'")->fetchColumn();
            if (!(int) $existe) {
                $conexion->exec('ALTER TABLE tipo_muestra_campo ADD COLUMN predefinido TINYINT(1) NOT NULL DEFAULT 0 AFTER opciones');
            }
        } catch (Throwable $e) {
            error_log('[laboratorio][schema:campos_tipo_muestra] ' . $e->getMessage());
            throw $e;
        }

        $esquemaAsegurado = true;
    }
}

if (!function_exists('labCamposMuestraNormalizarClave')) {
    /**
     * Convierte un texto libre en una clave técnica segura
     * (minúsculas, sin acentos, solo [a-z0-9_]).
     */
    function labCamposMuestraNormalizarClave(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return '';
        }

        $valor = strtolower($valor);
        $valor = strtr($valor, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'Ü' => 'u', 'Ñ' => 'n',
        ]);

        if (function_exists('iconv')) {
            $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            if ($convertido !== false) {
                $valor = strtolower($convertido);
            }
        }

        $valor = preg_replace('/[^a-z0-9]+/', '_', $valor) ?? '';

        return trim($valor, '_');
    }
}

if (!function_exists('labCamposMuestraDecodificarOpciones')) {
    /**
     * Devuelve el arreglo de opciones (para tipo_dato = 'lista') a partir
     * del JSON almacenado en la columna `opciones`.
     *
     * @return string[]
     */
    function labCamposMuestraDecodificarOpciones($valor): array
    {
        if (!is_string($valor) || trim($valor) === '') {
            return [];
        }

        $data = json_decode($valor, true);
        if (!is_array($data)) {
            return [];
        }

        $limpias = [];
        foreach ($data as $opcion) {
            $opcion = trim((string) $opcion);
            if ($opcion !== '' && !in_array($opcion, $limpias, true)) {
                $limpias[] = $opcion;
            }
        }

        return $limpias;
    }
}

if (!function_exists('labCamposMuestraListar')) {
    /**
     * Lista los campos definidos para un tipo de muestra, ordenados por
     * `orden` y luego por etiqueta.
     *
     * @return array<int,array<string,mixed>>
     */
    function labCamposMuestraListar(PDO $conexion, int $idTipoMuestra): array
    {
        labCamposMuestraAsegurarEsquema($conexion);

        if ($idTipoMuestra <= 0) {
            return [];
        }

        $stmt = $conexion->prepare("
            SELECT id_campo, id_tipo_muestra, nombre, etiqueta, tipo_dato, unidad,
                   obligatorio, orden, activo, opciones, predefinido
              FROM tipo_muestra_campo
             WHERE id_tipo_muestra = ?
             ORDER BY orden ASC, etiqueta ASC, id_campo ASC
        ");
        $stmt->execute([$idTipoMuestra]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('labCamposMuestraObtener')) {
    function labCamposMuestraObtener(PDO $conexion, int $idCampo): ?array
    {
        labCamposMuestraAsegurarEsquema($conexion);

        $stmt = $conexion->prepare("
            SELECT id_campo, id_tipo_muestra, nombre, etiqueta, tipo_dato, unidad,
                   obligatorio, orden, activo, opciones, predefinido
              FROM tipo_muestra_campo
             WHERE id_campo = ?
             LIMIT 1
        ");
        $stmt->execute([$idCampo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }
}

if (!function_exists('labCamposMuestraGuardar')) {
    /**
     * Crea o actualiza un campo por tipo de muestra con validación
     * server-side completa.
     *
     * @param array<string,mixed> $datos nombre, etiqueta, tipo_dato,
     *        unidad, obligatorio, orden, activo, opciones
     * @return int id del campo creado/actualizado
     */
    function labCamposMuestraGuardar(PDO $conexion, ?int $idCampo, int $idTipoMuestra, array $datos): int
    {
        labCamposMuestraAsegurarEsquema($conexion);

        if ($idTipoMuestra <= 0) {
            throw new InvalidArgumentException('Selecciona un tipo de muestra válido.');
        }

        $stmtTipo = $conexion->prepare('SELECT 1 FROM tipo_muestra WHERE id_tipo = ? LIMIT 1');
        $stmtTipo->execute([$idTipoMuestra]);
        if (!$stmtTipo->fetchColumn()) {
            throw new RuntimeException('El tipo de muestra seleccionado no existe.');
        }

        $etiqueta = trim((string) ($datos['etiqueta'] ?? ''));
        if ($etiqueta === '') {
            throw new InvalidArgumentException('La etiqueta del campo es obligatoria.');
        }
        if (mb_strlen($etiqueta) > 150) {
            throw new InvalidArgumentException('La etiqueta no puede superar los 150 caracteres.');
        }

        $previo = ($idCampo !== null && $idCampo > 0) ? labCamposMuestraObtener($conexion, $idCampo) : null;
        if ($idCampo !== null && $idCampo > 0 && (!$previo || (int) $previo['id_tipo_muestra'] !== $idTipoMuestra)) {
            throw new RuntimeException('El campo ya no existe.');
        }
        $esPredefinido = $previo && (int) ($previo['predefinido'] ?? 0) === 1;
        $nombre = labCamposMuestraNormalizarClave((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = labCamposMuestraNormalizarClave($etiqueta);
        }
        if ($esPredefinido) {
            $nombre = (string) $previo['nombre']; // el nombre técnico enlaza con los datos ya capturados
        } elseif ($nombre === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $nombre)) {
            throw new InvalidArgumentException('El nombre técnico debe iniciar con una letra y contener solo letras, números y guiones bajos.');
        }
        if (mb_strlen($nombre) > 80) {
            throw new InvalidArgumentException('El nombre técnico no puede superar los 80 caracteres.');
        }

        $tiposValidos = array_keys(labCamposMuestraTiposDato());
        $tipoDato = (string) ($datos['tipo_dato'] ?? 'texto');
        if (!in_array($tipoDato, $tiposValidos, true)) {
            throw new InvalidArgumentException('El tipo de dato seleccionado no es válido.');
        }

        $unidad = trim((string) ($datos['unidad'] ?? ''));
        if (mb_strlen($unidad) > 40) {
            throw new InvalidArgumentException('La unidad no puede superar los 40 caracteres.');
        }
        $unidad = $unidad !== '' ? $unidad : null;

        $ordenRaw = trim((string) ($datos['orden'] ?? '0'));
        if ($ordenRaw === '') {
            $ordenRaw = '0';
        }
        if (!preg_match('/^-?\d+$/', $ordenRaw)) {
            throw new InvalidArgumentException('El orden debe ser un número entero.');
        }
        $orden = (int) $ordenRaw;
        if ($orden < 0) {
            throw new InvalidArgumentException('El orden no puede ser un número negativo.');
        }

        $obligatorio = !empty($datos['obligatorio']) ? 1 : 0;
        $activo = !empty($datos['activo']) ? 1 : 0;

        $opcionesJson = null;
        if ($tipoDato === 'lista') {
            $lineas = preg_split('/\r\n|\r|\n/', (string) ($datos['opciones'] ?? '')) ?: [];
            $opciones = [];
            foreach ($lineas as $linea) {
                $linea = trim($linea);
                if ($linea !== '' && !in_array($linea, $opciones, true)) {
                    $opciones[] = $linea;
                }
            }
            if (count($opciones) < 2) {
                throw new InvalidArgumentException('Un campo de tipo lista necesita al menos dos opciones (una por línea).');
            }
            $opcionesJson = json_encode(array_values($opciones), JSON_UNESCAPED_UNICODE);
        }

        if ($idCampo !== null && $idCampo > 0) {
            $dup = $conexion->prepare(
                'SELECT 1 FROM tipo_muestra_campo WHERE id_tipo_muestra = ? AND nombre = ? AND id_campo <> ? LIMIT 1'
            );
            $dup->execute([$idTipoMuestra, $nombre, $idCampo]);
        } else {
            $dup = $conexion->prepare(
                'SELECT 1 FROM tipo_muestra_campo WHERE id_tipo_muestra = ? AND nombre = ? LIMIT 1'
            );
            $dup->execute([$idTipoMuestra, $nombre]);
        }
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Ya existe un campo con ese nombre técnico para este tipo de muestra.');
        }

        if ($idCampo !== null && $idCampo > 0) {
            $stmt = $conexion->prepare("
                UPDATE tipo_muestra_campo
                   SET nombre = ?, etiqueta = ?, tipo_dato = ?, unidad = ?,
                       obligatorio = ?, orden = ?, activo = ?, opciones = ?
                 WHERE id_campo = ? AND id_tipo_muestra = ?
            ");
            $stmt->execute([
                $nombre, $etiqueta, $tipoDato, $unidad,
                $obligatorio, $orden, $activo, $opcionesJson,
                $idCampo, $idTipoMuestra,
            ]);

            return $idCampo;
        }

        $stmt = $conexion->prepare("
            INSERT INTO tipo_muestra_campo
                (id_tipo_muestra, nombre, etiqueta, tipo_dato, unidad, obligatorio, orden, activo, opciones)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $idTipoMuestra, $nombre, $etiqueta, $tipoDato, $unidad,
            $obligatorio, $orden, $activo, $opcionesJson,
        ]);

        return (int) $conexion->lastInsertId();
    }
}

if (!function_exists('labCamposMuestraEliminar')) {
    function labCamposMuestraEliminar(PDO $conexion, int $idCampo): bool
    {
        labCamposMuestraAsegurarEsquema($conexion);

        if ($idCampo <= 0) {
            throw new InvalidArgumentException('No se encontró el campo a eliminar.');
        }
        $campo = labCamposMuestraObtener($conexion, $idCampo);
        if ($campo && (int) ($campo['predefinido'] ?? 0) === 1) {
            throw new InvalidArgumentException('Los campos predefinidos no se eliminan; desactívalo para ocultarlo en Identificación.');
        }

        $stmt = $conexion->prepare('DELETE FROM tipo_muestra_campo WHERE id_campo = ?');

        return $stmt->execute([$idCampo]);
    }
}

if (!function_exists('labCamposMuestraSincronizarPredefinidos')) {
    /**
     * Carga, una sola vez por tipo de muestra, los campos predefinidos de la etiqueta de
     * Identificación (config/identificacion_v3.json: formato asignado a cada tipo). Después
     * se editan desde el catálogo; no se vuelven a sobrescribir.
     */
    function labCamposMuestraSincronizarPredefinidos(PDO $conexion): void
    {
        labCamposMuestraAsegurarEsquema($conexion);
        require_once __DIR__ . '/catalogo_analisis_helper.php';
        $config = json_decode((string) file_get_contents(__DIR__ . '/../config/identificacion_v3.json'), true) ?: [];
        $etiquetas = array_column($config['campos'] ?? [], 'label', 'key');
        $tieneFijos = $conexion->prepare('SELECT COUNT(*) FROM tipo_muestra_campo WHERE id_tipo_muestra = ? AND predefinido = 1');
        $insertar = $conexion->prepare("INSERT IGNORE INTO tipo_muestra_campo
            (id_tipo_muestra, nombre, etiqueta, tipo_dato, obligatorio, orden, activo, predefinido) VALUES (?, ?, ?, ?, 0, ?, 1, 1)");
        foreach (labCatalogoAnalisisTipoMuestraOptions($conexion) as $tipo) {
            $plantilla = $config['plantillas'][$config['porTipo'][$tipo['clave']] ?? ''] ?? null;
            if (!$plantilla) continue;
            $tieneFijos->execute([(int) $tipo['id_tipo']]);
            if ((int) $tieneFijos->fetchColumn() > 0) continue;
            foreach (array_values($plantilla) as $i => $clave) {
                $insertar->execute([(int) $tipo['id_tipo'], $clave, $etiquetas[$clave] ?? $clave, $clave === 'fecha' ? 'fecha' : 'texto', ($i + 1) * 10]);
            }
        }
    }
}