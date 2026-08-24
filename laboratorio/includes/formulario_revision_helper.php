<?php

require_once __DIR__ . '/../models/conexion.php';

function labFormularioPdo(): PDO
{
    return Conexion::conectar();
}

function labFormularioEnsureSchema(): void
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    $pdo = labFormularioPdo();

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS formulario_version (
                id_version INT AUTO_INCREMENT PRIMARY KEY,
                id_formulario INT NOT NULL,
                version_numero INT NOT NULL,
                tipo_version VARCHAR(50) NOT NULL,
                datos_json LONGTEXT NOT NULL,
                usuario VARCHAR(255) NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                comentario TEXT NULL,
                INDEX idx_formulario_version_formulario (id_formulario),
                CONSTRAINT fk_formulario_version_formulario
                    FOREIGN KEY (id_formulario)
                    REFERENCES formulario(id_formulario)
                    ON DELETE CASCADE
            )
        ");

        labFormularioEstadoId('Revisar', 'Formulario ingresado y pendiente de revision.');
        labFormularioEstadoId('Aprobado', 'Formulario revisado y aprobado.');
    } catch (Throwable $e) {
        error_log('[laboratorio][schema:formulario_version] ' . $e->getMessage());
        throw $e;
    }

    $schemaEnsured = true;
}

function labFormularioEstadoId(string $nombre, string $descripcion = ''): int
{
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("SELECT id_estado FROM estado_formulario WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
    $stmt->execute([$nombre]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare("INSERT INTO estado_formulario (nombre, descripcion) VALUES (?, ?)");
    $stmt->execute([$nombre, $descripcion]);

    return (int) $pdo->lastInsertId();
}

function labFormularioEstadoRevisarId(): int
{
    labFormularioEnsureSchema();
    return labFormularioEstadoId('Revisar', 'Formulario ingresado y pendiente de revision.');
}

function labFormularioEstadoAprobadoId(): int
{
    labFormularioEnsureSchema();
    return labFormularioEstadoId('Aprobado', 'Formulario revisado y aprobado.');
}

function labFormularioEstadoNombre($idEstado): ?string
{
    if (!$idEstado) {
        return null;
    }

    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("SELECT nombre FROM estado_formulario WHERE id_estado = ? LIMIT 1");
    $stmt->execute([(int) $idEstado]);
    $nombre = $stmt->fetchColumn();

    return $nombre !== false ? (string) $nombre : null;
}

function labFormularioRegistrarHistorial(int $idFormulario, string $accion, ?string $estadoAnterior, ?string $estadoNuevo, string $usuario, string $comentario = ''): void
{
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("
        INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([$idFormulario, $accion, $estadoAnterior, $estadoNuevo, $usuario, $comentario]);
}

function labFormularioJsonEncode(array $datos): string
{
    return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function labFormularioDatosActuales(int $idFormulario): array
{
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("
        SELECT f.*, ef.nombre AS estado_nombre, ta.nombre AS analisis_nombre
          FROM formulario f
          LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
          LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
         WHERE f.id_formulario = ?
         LIMIT 1
    ");
    $stmt->execute([$idFormulario]);
    $formulario = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'formulario' => $formulario,
        'tablas' => labFormularioTablasConDatos($idFormulario),
    ];
}

function labFormularioTablasConDatos(int $idFormulario): array
{
    $pdo = labFormularioPdo();
    $tablas = labFormularioTablasCandidatas();
    $tablasConDatos = labFormularioTablasConFilas($idFormulario, $tablas);
    $resultado = [];

    foreach ($tablasConDatos as $tabla) {
        $pk = labFormularioPrimaryKey($tabla);
        $columns = labFormularioColumnas($tabla);
        $query = "SELECT * FROM `$tabla` WHERE id_formulario = ?";
        if ($pk !== null) {
            $query .= " ORDER BY `$pk` ASC";
        }

        $rowsStmt = $pdo->prepare($query);
        $rowsStmt->execute([$idFormulario]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            continue;
        }

        $resultado[] = [
            'tabla' => $tabla,
            'primary_key' => $pk,
            'columnas' => $columns,
            'filas' => $rows,
        ];
    }

    return $resultado;
}

function labFormularioTablasCandidatas(): array
{
    static $tablas = null;

    if ($tablas !== null) {
        return $tablas;
    }

    $pdo = labFormularioPdo();
    $stmt = $pdo->query("
        SELECT TABLE_NAME
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND COLUMN_NAME = 'id_formulario'
           AND TABLE_NAME NOT IN ('formulario', 'historial_formulario', 'formulario_version')
         ORDER BY TABLE_NAME
    ");

    $tablas = array_values(array_filter($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [], static function ($tabla) {
        return preg_match('/^[A-Za-z0-9_]+$/', (string) $tabla);
    }));

    return $tablas;
}

function labFormularioTablasConFilas(int $idFormulario, array $tablas): array
{
    if (!$tablas) {
        return [];
    }

    $pdo = labFormularioPdo();
    $partes = [];
    $params = [];

    foreach ($tablas as $tabla) {
        $partes[] = "SELECT ? AS tabla, COUNT(*) AS total FROM `$tabla` WHERE id_formulario = ?";
        $params[] = $tabla;
        $params[] = $idFormulario;
    }

    $stmt = $pdo->prepare(implode(' UNION ALL ', $partes));
    $stmt->execute($params);
    $conDatos = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) ($row['total'] ?? 0) > 0) {
            $conDatos[] = (string) $row['tabla'];
        }
    }

    return $conDatos;
}

function labFormularioPrimaryKey(string $tabla): ?string
{
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("SHOW KEYS FROM `$tabla` WHERE Key_name = 'PRIMARY'");
    $stmt->execute();
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return count($keys) === 1 ? (string) $keys[0]['Column_name'] : null;
}

function labFormularioColumnas(string $tabla): array
{
    $pdo = labFormularioPdo();
    $stmt = $pdo->query("SHOW COLUMNS FROM `$tabla`");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function labFormularioColumnasEditables(array $tabla): array
{
    $pk = $tabla['primary_key'] ?? null;
    $excluidas = ['id_formulario', 'id_solicitud', 'id_lote', 'created_at', 'updated_at', 'fecha_creacion'];
    $editables = [];

    foreach ($tabla['columnas'] as $columna) {
        $nombre = (string) ($columna['Field'] ?? '');
        if ($nombre === '' || $nombre === $pk || in_array($nombre, $excluidas, true) || preg_match('/^id_/', $nombre)) {
            continue;
        }
        $editables[] = $nombre;
    }

    return $editables;
}

function labFormularioCantidadVersiones(int $idFormulario): int
{
    labFormularioEnsureSchema();
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM formulario_version WHERE id_formulario = ?");
    $stmt->execute([$idFormulario]);

    return (int) $stmt->fetchColumn();
}

function labFormularioGuardarVersion(int $idFormulario, string $tipoVersion, string $usuario, string $comentario = ''): void
{
    labFormularioEnsureSchema();
    $pdo = labFormularioPdo();
    $version = labFormularioCantidadVersiones($idFormulario) + 1;
    $datos = labFormularioJsonEncode(labFormularioDatosActuales($idFormulario));

    $stmt = $pdo->prepare("
        INSERT INTO formulario_version (id_formulario, version_numero, tipo_version, datos_json, usuario, comentario)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$idFormulario, $version, $tipoVersion, $datos, $usuario, $comentario]);
}

function labFormularioTieneVersionTipo(int $idFormulario, string $tipoVersion): bool
{
    labFormularioEnsureSchema();
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("
        SELECT 1
          FROM formulario_version
         WHERE id_formulario = ?
           AND tipo_version = ?
         LIMIT 1
    ");
    $stmt->execute([$idFormulario, $tipoVersion]);

    return (bool) $stmt->fetchColumn();
}

function labFormularioGuardarVersionConErrores(int $idFormulario, string $usuario, string $comentario = ''): void
{
    if (labFormularioTieneVersionTipo($idFormulario, 'con_errores')) {
        return;
    }

    labFormularioGuardarVersion(
        $idFormulario,
        'con_errores',
        $usuario,
        $comentario !== '' ? $comentario : 'Formulario original guardado con errores antes de correccion.'
    );
}

function labFormularioEnsureVersionInicial(int $idFormulario, string $usuario): void
{
    if (labFormularioCantidadVersiones($idFormulario) === 0) {
        labFormularioGuardarVersion($idFormulario, 'inicial', $usuario, 'Version inicial guardada automaticamente.');
    }
}

function labFormularioVersiones(int $idFormulario): array
{
    labFormularioEnsureSchema();
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("
        SELECT id_version, version_numero, tipo_version, usuario, fecha, comentario
          FROM formulario_version
         WHERE id_formulario = ?
         ORDER BY version_numero ASC
    ");
    $stmt->execute([$idFormulario]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function labFormularioActualizarBase(int $idFormulario, array $datos): void
{
    $pdo = labFormularioPdo();
    $fecha = trim((string) ($datos['fecha'] ?? ''));
    $analista = trim((string) ($datos['analista'] ?? ''));

    $stmt = $pdo->prepare("UPDATE formulario SET fecha = ?, analista = ? WHERE id_formulario = ?");
    $stmt->execute([$fecha !== '' ? $fecha : null, $analista !== '' ? $analista : null, $idFormulario]);
}

function labFormularioActualizarDatos(int $idFormulario, array $datosTablas): void
{
    $pdo = labFormularioPdo();
    $tablasActuales = [];

    foreach (labFormularioTablasConDatos($idFormulario) as $tabla) {
        $tablasActuales[$tabla['tabla']] = [
            'pk' => $tabla['primary_key'],
            'editables' => labFormularioColumnasEditables($tabla),
        ];
    }

    foreach ($datosTablas as $tabla => $filas) {
        if (!isset($tablasActuales[$tabla]) || !is_array($filas) || !preg_match('/^[A-Za-z0-9_]+$/', $tabla)) {
            continue;
        }

        $pk = $tablasActuales[$tabla]['pk'];
        $editables = $tablasActuales[$tabla]['editables'];
        if ($pk === null || !$editables) {
            continue;
        }

        foreach ($filas as $idFila => $columnas) {
            if (!is_array($columnas)) {
                continue;
            }

            $sets = [];
            $params = [];
            foreach ($columnas as $columna => $valor) {
                if (!in_array($columna, $editables, true) || !preg_match('/^[A-Za-z0-9_]+$/', $columna)) {
                    continue;
                }
                $sets[] = "`$columna` = ?";
                $params[] = trim((string) $valor) === '' ? null : $valor;
            }

            if (!$sets) {
                continue;
            }

            $params[] = $idFormulario;
            $params[] = $idFila;
            $stmt = $pdo->prepare("UPDATE `$tabla` SET " . implode(', ', $sets) . " WHERE id_formulario = ? AND `$pk` = ?");
            $stmt->execute($params);
        }
    }
}

function labFormularioAprobar(int $idFormulario, string $usuario, string $comentario = ''): void
{
    $pdo = labFormularioPdo();
    $stmt = $pdo->prepare("SELECT id_estado FROM formulario WHERE id_formulario = ? LIMIT 1");
    $stmt->execute([$idFormulario]);
    $estadoAnteriorId = $stmt->fetchColumn();
    $estadoAnterior = labFormularioEstadoNombre($estadoAnteriorId);
    $estadoAprobadoId = labFormularioEstadoAprobadoId();

    $update = $pdo->prepare("UPDATE formulario SET id_estado = ? WHERE id_formulario = ?");
    $update->execute([$estadoAprobadoId, $idFormulario]);

    labFormularioRegistrarHistorial($idFormulario, 'Formulario aprobado', $estadoAnterior, 'Aprobado', $usuario, $comentario);
}

?>
