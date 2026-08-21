<?php

if (!function_exists('labMuestraIdentificacionEnsureSchema')) {
    function labMuestraIdentificacionEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS muestra_identificacion (
                id_identificacion INT NOT NULL AUTO_INCREMENT,
                id_muestra INT NOT NULL,
                lectura_codigo VARCHAR(255) DEFAULT NULL,
                cultivo VARCHAR(150) DEFAULT NULL,
                bloque VARCHAR(120) DEFAULT NULL,
                parcela VARCHAR(120) DEFAULT NULL,
                punto_muestreo VARCHAR(180) DEFAULT NULL,
                georreferencia VARCHAR(180) DEFAULT NULL,
                fecha_muestreo DATETIME DEFAULT NULL,
                repeticion VARCHAR(120) DEFAULT NULL,
                variedad VARCHAR(180) DEFAULT NULL,
                corte VARCHAR(120) DEFAULT NULL,
                tratamiento VARCHAR(255) DEFAULT NULL,
                tomado_por VARCHAR(180) DEFAULT NULL,
                peso_cantidad DECIMAL(12,4) DEFAULT NULL,
                unidad_peso VARCHAR(30) DEFAULT NULL,
                contenedor VARCHAR(180) DEFAULT NULL,
                condicion_fisica VARCHAR(80) DEFAULT NULL,
                temperatura_recepcion DECIMAL(8,2) DEFAULT NULL,
                observaciones TEXT DEFAULT NULL,
                actualizado_por VARCHAR(180) DEFAULT NULL,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id_identificacion),
                UNIQUE KEY uq_muestra_identificacion_muestra (id_muestra),
                KEY idx_muestra_identificacion_lectura (lectura_codigo),
                CONSTRAINT fk_muestra_identificacion_muestra
                    FOREIGN KEY (id_muestra) REFERENCES muestra (id_muestra)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

if (!function_exists('labMuestraIdentificacionCodigo')) {
    function labMuestraIdentificacionCodigo(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }
}

if (!function_exists('labMuestraIdentificacionBuscar')) {
    function labMuestraIdentificacionBuscar(PDO $pdo, string $codigo): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                m.id_muestra,
                m.id_solicitud,
                m.numero_muestra,
                m.codigo_lab,
                s.id_lote,
                s.institucion AS cliente,
                s.responsable_envio,
                s.ingresado_por,
                s.recibido_por,
                s.fecha_muestreo AS fecha_muestreo_solicitud,
                s.codigo_muestreo,
                l.codigo_lote,
                tm.nombre AS tipo_muestra,
                mi.id_identificacion,
                mi.lectura_codigo,
                mi.cultivo,
                mi.bloque,
                mi.parcela,
                mi.punto_muestreo,
                mi.georreferencia,
                mi.fecha_muestreo,
                mi.repeticion,
                mi.variedad,
                mi.corte,
                mi.tratamiento,
                mi.tomado_por,
                mi.peso_cantidad,
                mi.unidad_peso,
                mi.contenedor,
                mi.condicion_fisica,
                mi.temperatura_recepcion,
                mi.observaciones,
                mi.actualizado_por,
                mi.actualizado_en,
                (
                    SELECT COUNT(DISTINCT sa.id_tipo_analisis)
                    FROM solicitud_analisis sa
                    WHERE sa.id_solicitud = s.id_solicitud
                ) AS analisis_requeridos,
                (
                    SELECT COUNT(DISTINCT f.id_tipo_analisis)
                    FROM lote_rango lr
                    INNER JOIN formulario f ON f.id_rango = lr.id_rango
                    WHERE lr.id_lote = s.id_lote
                ) AS analisis_ingresados
            FROM muestra m
            INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
            INNER JOIN lote l ON l.id_lote = s.id_lote
            INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
            LEFT JOIN muestra_identificacion mi ON mi.id_muestra = m.id_muestra
            WHERE UPPER(TRIM(m.codigo_lab)) = UPPER(TRIM(?))
               OR UPPER(TRIM(COALESCE(mi.lectura_codigo, ''))) = UPPER(TRIM(?))
            ORDER BY (UPPER(TRIM(m.codigo_lab)) = UPPER(TRIM(?))) DESC, m.id_muestra DESC
            LIMIT 1
        ");
        $stmt->execute([$codigo, $codigo, $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('labMuestraIdentificacionSolicitudes')) {
    function labMuestraIdentificacionSolicitudes(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                s.id_solicitud,
                s.id_lote,
                l.codigo_lote,
                tm.nombre AS tipo_muestra,
                COALESCE(NULLIF(TRIM(s.institucion), ''), 'Sin cliente') AS cliente
            FROM solicitud s
            INNER JOIN lote l ON l.id_lote = s.id_lote
            INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
            ORDER BY s.id_solicitud DESC
            LIMIT 150
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('labMuestraIdentificacionCrear')) {
    function labMuestraIdentificacionCrear(PDO $pdo, string $codigo, int $idSolicitud, string $cultivo, string $usuario): array
    {
        $codigo = labMuestraIdentificacionCodigo($codigo);
        if ($codigo === '' || strlen($codigo) > 255 || !preg_match('/^[A-Z0-9._\/-]+$/u', $codigo)) {
            throw new InvalidArgumentException('Ingrese un codigo de muestra valido usando letras, numeros, punto, diagonal o guion.');
        }

        if (labMuestraIdentificacionBuscar($pdo, $codigo)) {
            throw new RuntimeException('Ese codigo de muestra ya existe. Busquelo para continuar su identificacion.');
        }

        $stmt = $pdo->prepare('SELECT id_solicitud FROM solicitud WHERE id_solicitud = ? LIMIT 1');
        $stmt->execute([$idSolicitud]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Seleccione un lote y tipo de muestra validos.');
        }

        $manageTransaction = !$pdo->inTransaction();
        if ($manageTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(numero_muestra), 0) + 1 FROM muestra WHERE id_solicitud = ? FOR UPDATE');
            $stmt->execute([$idSolicitud]);
            $numeroMuestra = max(1, (int) $stmt->fetchColumn());

            $stmt = $pdo->prepare('INSERT INTO muestra (id_solicitud, numero_muestra, codigo_lab) VALUES (?, ?, ?)');
            $stmt->execute([$idSolicitud, $numeroMuestra, $codigo]);
            $idMuestra = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO muestra_identificacion
                    (id_muestra, lectura_codigo, cultivo, actualizado_por)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$idMuestra, $codigo, trim($cultivo), $usuario]);
            if ($manageTransaction) {
                $pdo->commit();
            }

            return labMuestraIdentificacionBuscar($pdo, $codigo) ?: [];
        } catch (Throwable $e) {
            if ($manageTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('labMuestraIdentificacionGuardar')) {
    function labMuestraIdentificacionGuardar(PDO $pdo, int $idMuestra, array $data, string $usuario): string
    {
        $stmt = $pdo->prepare('SELECT codigo_lab FROM muestra WHERE id_muestra = ? LIMIT 1');
        $stmt->execute([$idMuestra]);
        $codigo = (string) ($stmt->fetchColumn() ?: '');
        if ($codigo === '') {
            throw new RuntimeException('La muestra seleccionada ya no existe.');
        }

        $fechaMuestreo = trim((string) ($data['fecha_muestreo'] ?? ''));
        if ($fechaMuestreo !== '') {
            $timestamp = strtotime($fechaMuestreo);
            if (!$timestamp) {
                throw new InvalidArgumentException('La fecha de muestreo no es valida.');
            }
            $fechaMuestreo = date('Y-m-d H:i:s', $timestamp);
        } else {
            $fechaMuestreo = null;
        }

        $peso = trim((string) ($data['peso_cantidad'] ?? ''));
        $temperatura = trim((string) ($data['temperatura_recepcion'] ?? ''));
        $values = [
            'lectura_codigo' => trim((string) ($data['lectura_codigo'] ?? '')) ?: $codigo,
            'cultivo' => trim((string) ($data['cultivo'] ?? '')),
            'bloque' => trim((string) ($data['bloque'] ?? '')),
            'parcela' => trim((string) ($data['parcela'] ?? '')),
            'punto_muestreo' => trim((string) ($data['punto_muestreo'] ?? '')),
            'georreferencia' => trim((string) ($data['georreferencia'] ?? '')),
            'fecha_muestreo' => $fechaMuestreo,
            'repeticion' => trim((string) ($data['repeticion'] ?? '')),
            'variedad' => trim((string) ($data['variedad'] ?? '')),
            'corte' => trim((string) ($data['corte'] ?? '')),
            'tratamiento' => trim((string) ($data['tratamiento'] ?? '')),
            'tomado_por' => trim((string) ($data['tomado_por'] ?? '')),
            'peso_cantidad' => $peso === '' ? null : (float) $peso,
            'unidad_peso' => trim((string) ($data['unidad_peso'] ?? '')),
            'contenedor' => trim((string) ($data['contenedor'] ?? '')),
            'condicion_fisica' => trim((string) ($data['condicion_fisica'] ?? '')),
            'temperatura_recepcion' => $temperatura === '' ? null : (float) $temperatura,
            'observaciones' => trim((string) ($data['observaciones'] ?? '')),
            'actualizado_por' => $usuario,
        ];

        $stmt = $pdo->prepare("
            INSERT INTO muestra_identificacion (
                id_muestra, lectura_codigo, cultivo, bloque, parcela, punto_muestreo,
                georreferencia, fecha_muestreo, repeticion, variedad, corte, tratamiento,
                tomado_por, peso_cantidad, unidad_peso, contenedor, condicion_fisica,
                temperatura_recepcion, observaciones, actualizado_por
            ) VALUES (
                :id_muestra, :lectura_codigo, :cultivo, :bloque, :parcela, :punto_muestreo,
                :georreferencia, :fecha_muestreo, :repeticion, :variedad, :corte, :tratamiento,
                :tomado_por, :peso_cantidad, :unidad_peso, :contenedor, :condicion_fisica,
                :temperatura_recepcion, :observaciones, :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                lectura_codigo = VALUES(lectura_codigo),
                cultivo = VALUES(cultivo),
                bloque = VALUES(bloque),
                parcela = VALUES(parcela),
                punto_muestreo = VALUES(punto_muestreo),
                georreferencia = VALUES(georreferencia),
                fecha_muestreo = VALUES(fecha_muestreo),
                repeticion = VALUES(repeticion),
                variedad = VALUES(variedad),
                corte = VALUES(corte),
                tratamiento = VALUES(tratamiento),
                tomado_por = VALUES(tomado_por),
                peso_cantidad = VALUES(peso_cantidad),
                unidad_peso = VALUES(unidad_peso),
                contenedor = VALUES(contenedor),
                condicion_fisica = VALUES(condicion_fisica),
                temperatura_recepcion = VALUES(temperatura_recepcion),
                observaciones = VALUES(observaciones),
                actualizado_por = VALUES(actualizado_por)
        ");
        $stmt->execute(array_merge(['id_muestra' => $idMuestra], $values));

        return $codigo;
    }
}

if (!function_exists('labMuestraIdentificacionBase')) {
    function labMuestraIdentificacionBase(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                m.id_muestra,
                m.codigo_lab,
                l.id_lote,
                l.codigo_lote,
                COALESCE(NULLIF(TRIM(s.institucion), ''), 'Sin cliente') AS cliente,
                tm.nombre AS tipo_muestra,
                mi.lectura_codigo,
                mi.parcela,
                mi.tratamiento,
                mi.repeticion,
                mi.observaciones,
                mi.actualizado_en
            FROM muestra_identificacion mi
            INNER JOIN muestra m ON m.id_muestra = mi.id_muestra
            INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
            INNER JOIN lote l ON l.id_lote = s.id_lote
            INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
            ORDER BY mi.actualizado_en DESC, mi.id_identificacion DESC
            LIMIT 300
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
