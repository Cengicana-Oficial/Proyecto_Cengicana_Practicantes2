<?php
require_once __DIR__ . '/catalogo_analisis_helper.php';

function labRecepcionCatalogoConfig(): array
{
    static $config;
    return $config ??= json_decode(file_get_contents(__DIR__ . '/../config/recepcion_catalogo_v3.json'), true, 512, JSON_THROW_ON_ERROR);
}

function labRecepcionAnalisisDefinicion(string $tipo, string $nombre): ?array
{
    $buscado = labCatalogoAnalisisNormalizarTexto($nombre);
    foreach (labRecepcionCatalogoConfig()[$tipo]['items'] ?? [] as $item) {
        foreach (array_merge([$item['nombre']], $item['aliases']) as $alias) {
            if (labCatalogoAnalisisNormalizarTexto($alias) === $buscado) {
                return $item;
            }
        }
    }
    return null;
}

/** Migración aditiva, una sola vez. Conserva IDs, nombres, inactivos y metadatos existentes. */
function labRecepcionPrepararCatalogo(PDO $conexion): void
{
    $conexion->exec("CREATE TABLE IF NOT EXISTS lab_recepcion_migracion (
        clave VARCHAR(80) PRIMARY KEY, aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $clave = 'catalogo_workflow_v3_1';
    $existe = $conexion->prepare('SELECT 1 FROM lab_recepcion_migracion WHERE clave = ?');
    $existe->execute([$clave]);
    if ($existe->fetchColumn()) return;

    $lock = $conexion->query("SELECT GET_LOCK('lab_recepcion_catalogo_v3', 15)")->fetchColumn();
    if ((int) $lock !== 1) throw new RuntimeException('No se pudo preparar el catálogo de recepción.');
    try {
        $conexion->beginTransaction();
        $existe->execute([$clave]);
        if (!$existe->fetchColumn()) {
            $tipos = $conexion->query('SELECT id_tipo, nombre, prefijo FROM tipo_muestra')->fetchAll(PDO::FETCH_ASSOC);
            $buscar = $conexion->prepare('SELECT id_tipo, nombre FROM tipo_analisis WHERE id_tipo_muestra = ? ORDER BY id_tipo');
            $insertar = $conexion->prepare('INSERT INTO tipo_analisis (id_tipo_muestra, nombre, activo, metodo, norma, unidad, tiempo_estimado_min) VALUES (?, ?, 1, ?, ?, ?, ?)');
            $completar = $conexion->prepare("UPDATE tipo_analisis SET
                metodo = COALESCE(NULLIF(metodo, ''), ?),
                norma = COALESCE(NULLIF(norma, ''), ?),
                unidad = COALESCE(NULLIF(unidad, ''), ?),
                tiempo_estimado_min = COALESCE(tiempo_estimado_min, ?)
                WHERE id_tipo = ?");
            foreach ($tipos as $tipo) {
                $key = labCatalogoAnalisisClaveModulo($tipo['nombre'], $tipo['prefijo']);
                $buscar->execute([$tipo['id_tipo']]);
                $existentes = $buscar->fetchAll(PDO::FETCH_ASSOC);
                foreach (labRecepcionCatalogoConfig()[$key]['items'] ?? [] as $item) {
                    $coincidencias = array_filter($existentes, static function ($fila) use ($key, $item) {
                        $def = labRecepcionAnalisisDefinicion($key, $fila['nombre']);
                        return $def && $def['nombre'] === $item['nombre'];
                    });
                    if ($coincidencias) {
                        foreach ($coincidencias as $fila) {
                            $completar->execute([$item['metodo'], $item['norma'], $item['unidad'], $item['tiempo_estimado_min'], $fila['id_tipo']]);
                        }
                    } else {
                        $insertar->execute([$tipo['id_tipo'], $item['nombre'], $item['metodo'], $item['norma'], $item['unidad'], $item['tiempo_estimado_min']]);
                    }
                }
            }
            $conexion->prepare('INSERT INTO lab_recepcion_migracion (clave) VALUES (?)')->execute([$clave]);
        }
        $conexion->commit();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        throw $e;
    } finally {
        $conexion->query("SELECT RELEASE_LOCK('lab_recepcion_catalogo_v3')");
    }
}

function labRecepcionCatalogoFormulario(PDO $conexion, int $idSolicitud = 0): array
{
    $catalogo = labCatalogoAnalisisFormularioData($conexion);
    // Conserva también IDs históricos duplicados o desactivados al abrir una edición.
    if ($idSolicitud > 0) {
        $stmt = $conexion->prepare('SELECT ta.id_tipo, ta.nombre, ta.metodo, ta.norma, tm.nombre AS tipo_nombre, tm.prefijo
            FROM solicitud_analisis sa JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
            JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra WHERE sa.id_solicitud = ?');
        $stmt->execute([$idSolicitud]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tipo = labCatalogoAnalisisClaveModulo($row['tipo_nombre'], $row['prefijo']);
            $catalogo[$tipo] ??= ['key' => $tipo, 'label' => labCatalogoAnalisisEtiquetaModuloPlural($tipo), 'items' => []];
            $items = &$catalogo[$tipo]['items'];
            if (!in_array((int) $row['id_tipo'], array_column($items, 'id_tipo'), true)) {
                $items[] = [
                    'id_tipo' => (int) $row['id_tipo'], 'nombre' => $row['nombre'],
                    'tipo' => $catalogo[$tipo]['label'], 'metodo' => $row['metodo'], 'norma' => $row['norma'],
                ];
            }
            unset($items);
        }
    }
    foreach ($catalogo as $tipo => &$grupo) {
        $config = labRecepcionCatalogoConfig()[$tipo] ?? [];
        $grupo['paquetes'] = $config['paquetes'] ?? [];
        $grupo['grupos'] = array_column($config['grupos'] ?? [], 'nombre');
        foreach ($grupo['items'] as &$item) {
            $def = labRecepcionAnalisisDefinicion($tipo, $item['nombre']);
            $item['nombre_original'] = $item['nombre'];
            $item['nombre'] = $def['nombre'] ?? $item['nombre'];
            $item['dependencias'] = $def['dependencias'] ?? [];
            // Implícito: no se ofrece en la recepción; se agrega solo al solicitar todos estos análisis.
            $item['implicito_con'] = $def['implicito_con'] ?? [];
            $item['grupo'] = 'Otros análisis';
            foreach ($config['grupos'] ?? [] as $g) {
                if ($g['analisis'] === null || in_array($item['nombre'], $g['analisis'], true)) {
                    $item['grupo'] = $g['nombre'];
                    break;
                }
            }
            // El grupo elegido en el Catálogo de análisis tiene prioridad sobre el formato de recepción.
            if (trim((string) ($item['grupo_recepcion'] ?? '')) !== '') {
                $item['grupo'] = trim((string) $item['grupo_recepcion']);
                if (!in_array($item['grupo'], $grupo['grupos'], true)) $grupo['grupos'][] = $item['grupo'];
            }
        }
        unset($item);
        $orden = array_flip(array_column($config['items'] ?? [], 'nombre'));
        usort($grupo['items'], static fn($a, $b) => (($orden[$a['nombre']] ?? PHP_INT_MAX) <=> ($orden[$b['nombre']] ?? PHP_INT_MAX)) ?: ((int) $a['id_tipo'] <=> (int) $b['id_tipo']));
    }
    unset($grupo);
    return $catalogo;
}


/** Las dependencias se validan también al guardar, no solo en el navegador. */
function labRecepcionCompletarAnalisis(array $items, array $seleccion): array
{
    $porId = array_column($items, null, 'id_tipo');
    $porNombre = array_column($items, 'id_tipo', 'nombre');
    $ids = array_values(array_unique(array_map('intval', $seleccion)));
    foreach ($ids as $id) {
        if (!isset($porId[$id])) throw new RuntimeException('Uno de los análisis no está disponible para este tipo de muestra.');
        foreach ($porId[$id]['dependencias'] ?? [] as $dep) {
            if (!isset($porNombre[$dep['nombre']])) {
                throw new RuntimeException($porId[$id]['nombre'] . ' requiere ' . $dep['nombre'] . ', que no está disponible.');
            }
            $ids[] = (int) $porNombre[$dep['nombre']];
        }
    }
    // Los implícitos (Humedad residual con Textura, Rendimiento con Brix y Pol) dependen
    // solo de la regla: se agregan si se cumple y se quitan si no.
    $elegidos = array_map(static fn($id) => $porId[$id]['nombre'], array_unique($ids));
    foreach ($items as $item) {
        $requeridos = $item['implicito_con'] ?? [];
        if (!$requeridos) continue;
        $ids = array_values(array_diff($ids, [(int) $item['id_tipo']]));
        if (!array_diff($requeridos, $elegidos)) $ids[] = (int) $item['id_tipo'];
    }
    return array_values(array_unique($ids));
}
