<?php
/**
 * Recepciones registradas (sección inferior de Recepción): consulta paginada desde la base de
 * datos, 25 recepciones por página y por tipo de muestra, más recientes primero. La usan la
 * vista (primera página) y controllers/recepciones_historial.php (páginas siguientes y búsqueda).
 */
require_once __DIR__ . '/solicitud_formulario_helpers.php';

const LAB_RECEPCIONES_POR_PAGINA = 25;

/** Condición de búsqueda por lote, cliente, prefijo o número de laboratorio. */
function labRecepcionesBusqueda(string $q, array &$params): string
{
    $q = trim($q);
    if ($q === '') {
        return '1=1';
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like, $like, $like);
    return "(l.codigo_lote LIKE ? OR s.institucion LIKE ? OR tm.prefijo LIKE ?
        OR EXISTS (SELECT 1 FROM muestra mq WHERE mq.id_solicitud = s.id_solicitud AND mq.codigo_lab LIKE ?))";
}

/** Pestañas por tipo de muestra, en el orden del laboratorio, con totales según la búsqueda. */
function labRecepcionesTabs(PDO $pdo, string $q = ''): array
{
    $params = [];
    $where = labRecepcionesBusqueda($q, $params);
    $stmt = $pdo->prepare("
        SELECT s.id_tipo, tm.nombre, COUNT(*) AS total, COALESCE(SUM(s.numero_muestras), 0) AS muestras
        FROM solicitud s
        LEFT JOIN lote l ON l.id_lote = s.id_lote
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
        WHERE {$where}
        GROUP BY s.id_tipo, tm.nombre
    ");
    $stmt->execute($params);

    $orden = ['suelo' => 'Suelos', 'agua' => 'Agua', 'foliar' => 'Foliares', 'cana' => 'Caña', 'miel' => 'Mieles'];
    $tabs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $nombre = (string) ($fila['nombre'] ?? '');
        $norm = strtolower(strtr($nombre, ['ñ' => 'n', 'Ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']));
        $label = $nombre !== '' ? mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8') : 'Sin tipo';
        $rango = 99;
        foreach (array_keys($orden) as $i => $clave) {
            if (str_contains($norm, $clave)) { $label = $orden[$clave]; $rango = $i; break; }
        }
        $tabs[] = ['id' => (int) $fila['id_tipo'], 'label' => $label, 'rango' => $rango,
            'total' => (int) $fila['total'], 'muestras' => (int) $fila['muestras']];
    }
    usort($tabs, static fn($a, $b) => $a['rango'] <=> $b['rango'] ?: strcmp($a['label'], $b['label']));
    return $tabs;
}

/** Columnas de una recepción (mismas que usa la edición y el menú de acciones). */
function labRecepcionesSelect(): string
{
    return "
        SELECT
          s.id_solicitud, s.id_lote, s.id_tipo, s.codigo_muestreo, s.fecha_muestreo, s.fecha_ingreso, s.fecha_estimada,
          s.numero_muestras, s.institucion, s.responsable_envio, s.ingresado_por, s.correo_ingresado,
          s.recibido_por, s.correo_recibido, s.observaciones,
          l.codigo_lote,
          (SELECT COUNT(*) FROM solicitud_historial_cambios h WHERE h.id_solicitud = s.id_solicitud) AS ediciones,
          COALESCE(pl.prioridad, 'media') AS prioridad,
          tm.nombre AS tipo_nombre,
          tm.prefijo,
          (SELECT GROUP_CONCAT(sa.id_tipo_analisis ORDER BY sa.id_tipo_analisis SEPARATOR ',')
             FROM solicitud_analisis sa WHERE sa.id_solicitud = s.id_solicitud) AS analisis_ids,
          (SELECT MIN(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(m.codigo_lab, '-', 2), '-', -1) AS UNSIGNED))
             FROM muestra m WHERE m.id_solicitud = s.id_solicitud AND m.codigo_lab IS NOT NULL AND m.codigo_lab <> '') AS inicio_laboratorio,
          (SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(m.codigo_lab, '-', 2), '-', -1) AS UNSIGNED))
             FROM muestra m WHERE m.id_solicitud = s.id_solicitud AND m.codigo_lab IS NOT NULL AND m.codigo_lab <> '') AS fin_laboratorio,
          (SELECT CONCAT('[', GROUP_CONCAT(JSON_QUOTE(m.codigo_lab) ORDER BY m.numero_muestra SEPARATOR ','), ']')
             FROM muestra m WHERE m.id_solicitud = s.id_solicitud AND m.codigo_lab IS NOT NULL AND m.codigo_lab <> '') AS codigos_laboratorio
        FROM solicitud s
        LEFT JOIN lote l ON l.id_lote = s.id_lote
        LEFT JOIN lab_plan_lote pl ON pl.id_lote = s.id_lote
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
    ";
}

/**
 * Una página de recepciones de un tipo (0 = todos los tipos), más recientes primero.
 * Devuelve filas, total de recepciones y muestras con el filtro, página y número de páginas.
 */
function labRecepcionesPagina(PDO $pdo, int $idTipo, int $pagina, string $q = '', int $porPagina = LAB_RECEPCIONES_POR_PAGINA): array
{
    $params = [];
    $where = labRecepcionesBusqueda($q, $params);
    if ($idTipo > 0) {
        $where .= ' AND s.id_tipo = ?';
        $params[] = $idTipo;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(s.numero_muestras), 0) AS muestras
        FROM solicitud s
        LEFT JOIN lote l ON l.id_lote = s.id_lote
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
        WHERE {$where}
    ");
    $stmt->execute($params);
    $conteo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'muestras' => 0];
    $total = (int) $conteo['total'];
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, $pagina), $paginas);
    $offset = ($pagina - 1) * $porPagina;

    // GROUP_CONCAT + JSON_QUOTE funciona en MySQL 8 y MariaDB; se amplía el límite para lotes grandes.
    $pdo->exec('SET SESSION group_concat_max_len = 1048576');
    $stmt = $pdo->prepare(labRecepcionesSelect() . " WHERE {$where} ORDER BY s.id_solicitud DESC LIMIT " . (int) $porPagina . ' OFFSET ' . (int) $offset);
    $stmt->execute($params);

    return [
        'filas' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'muestras' => (int) $conteo['muestras'],
        'pagina' => $pagina,
        'paginas' => $paginas,
    ];
}

/** Una recepción por id (para precargar la edición). */
function labRecepcionPorId(PDO $pdo, int $idSolicitud): ?array
{
    $pdo->exec('SET SESSION group_concat_max_len = 1048576');
    $stmt = $pdo->prepare(labRecepcionesSelect() . ' WHERE s.id_solicitud = ?');
    $stmt->execute([$idSolicitud]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Fila de la tabla Recepciones registradas con su menú de acciones (según permisos). */
function labRecepcionFilaHtml(array $recepcion): string
{
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $codigos = json_decode((string) ($recepcion['codigos_laboratorio'] ?? '[]'), true) ?: [];
    natsort($codigos);
    $rango = $codigos ? reset($codigos) . ' a ' . end($codigos) : 'Sin asignar';
    $id = (int) $recepcion['id_solicitud'];
    $ediciones = (int) ($recepcion['ediciones'] ?? 0);
    $lote = (string) ($recepcion['codigo_lote'] ?? '');

    ob_start(); ?>
        <tr data-id="<?= $id ?>" data-muestras="<?= (int) $recepcion['numero_muestras'] ?>">
          <td><?= $e($lote !== '' ? $lote : '—') ?><?php if ($ediciones > 0): ?> <span class="reception-version-badge" title="Recepción editada <?= $ediciones ?> vez/veces; ver historial en Editar">v<?= $ediciones + 1 ?></span><?php endif; ?></td>
          <?php foreach (['tipo_nombre', 'institucion', 'numero_muestras'] as $campo): ?>
          <td><?= $e($recepcion[$campo] ?? '—') ?></td>
          <?php endforeach; ?>
          <td><?= $e($rango) ?></td>
          <td><?= $e($recepcion['fecha_ingreso'] ?? '') ?></td>
          <td><?= $e($recepcion['recibido_por'] ?? '') ?></td>
          <td><details class="reception-menu">
            <summary class="reception-btn reception-btn--ghost" aria-label="Acciones de la recepción <?= $e($lote) ?>">Acciones <span aria-hidden="true">▾</span></summary>
            <div class="reception-menu-list" role="menu">
              <span class="reception-menu-title">Boleta</span>
              <button type="button" role="menuitem" data-boleta-action="ver" data-solicitud="<?= $id ?>"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>Ver boleta</button>
              <button type="button" role="menuitem" data-boleta-action="pdf" data-solicitud="<?= $id ?>"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>Descargar PDF</button>
              <button type="button" role="menuitem" data-boleta-action="correo" data-solicitud="<?= $id ?>" title="Enviar la boleta a los correos de los responsables"><span class="material-symbols-outlined" aria-hidden="true">mail</span>Enviar por correo</button>
              <span class="reception-menu-title">Recepción</span>
              <?php if (labSolicitudPuedeEditarTipo($recepcion)): ?>
                <a role="menuitem" href="?id_solicitud=<?= $id ?>"><span class="material-symbols-outlined" aria-hidden="true">edit</span>Editar recepción<?= $ediciones > 0 ? ' <small>(v' . ($ediciones + 1) . ')</small>' : '' ?></a>
              <?php else: ?>
                <span class="reception-menu-disabled" title="No tiene permiso para editar este tipo de solicitud"><span class="material-symbols-outlined" aria-hidden="true">edit_off</span>Editar recepción</span>
              <?php endif; ?>
              <a role="menuitem" href="etiquetas_laboratorio.php?id_solicitud=<?= $id ?>" target="_blank" rel="noopener" title="Etiquetas con número de laboratorio y código de barras de cada muestra"><span class="material-symbols-outlined" aria-hidden="true">label</span>Imprimir etiquetas</a>
              <?php if (lab_can('laboratorio.lotes.ver')): ?><a role="menuitem" href="listar_lotes.php?id_solicitud=<?= $id ?>"><span class="material-symbols-outlined" aria-hidden="true">qr_code_scanner</span>Identificar muestras</a><?php endif; ?>
              <?php if (lab_can('laboratorio.informes.ver') && !empty($recepcion['id_lote'])): ?><a role="menuitem" href="informes_view.php?lote=<?= (int) $recepcion['id_lote'] ?>"><span class="material-symbols-outlined" aria-hidden="true">summarize</span>Ver informe del lote</a><?php endif; ?>
            </div>
          </details></td>
        </tr>
    <?php
    return (string) ob_get_clean();
}
