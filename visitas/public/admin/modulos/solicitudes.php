<?php
if (!function_exists('can_access')) { http_response_code(403); exit('Acceso no permitido.'); }
$conn = conexion::conectar();

/* =========================
   🔥 FILTRO POR ESTADO
========================= */
$filtro_estado = $_GET['estado'] ?? null;
$condicion_estado = "";
$params = [];

if ($filtro_estado === 'pendiente') {
    $condicion_estado = " AND e.nombre_estado IN (?, ?)";
    $params[] = 'ENVIADO';
    $params[] = 'PENDIENTE';
} elseif ($filtro_estado === 'aprobado') {
    $condicion_estado = " AND e.nombre_estado = ?";
    $params[] = 'APROBADO';
} elseif ($filtro_estado === 'rechazado') {
    $condicion_estado = " AND e.nombre_estado = ?";
    $params[] = 'RECHAZADO';
}

/* =========================
   ÁREAS DISPONIBLES
========================= */
$stmtAreasDisponibles = $conn->query("
    SELECT id_area, nombre_area
    FROM areas_interes
    WHERE estado = 1
");
$areasDisponiblesList = $stmtAreasDisponibles->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   CONSULTA PRINCIPAL
========================= */
$areasAggregate = Conexion::groupConcat('ai.nombre_area', true);
$visitorsAggregate = Conexion::driver() === 'pgsql'
    ? "(SELECT string_agg(v.nombre_completo::text, ', ' ORDER BY v.orden) FROM solicitud_visitantes v WHERE v.id_solicitud = s.id_solicitud)"
    : "(SELECT GROUP_CONCAT(v.nombre_completo ORDER BY v.orden SEPARATOR ', ') FROM solicitud_visitantes v WHERE v.id_solicitud = s.id_solicitud)";
$sql = "
SELECT 
    s.id_solicitud,
    so.nombre_solicitante,
    so.nombre_institucion,
    so.correo,
    so.telefono,
    MAX(COALESCE(m.total, 0)) AS total_museo,
    s.fecha_visita,
    s.hora_visita,
    s.cantidad_visitantes,
    n.nombre_nivel,
    e.nombre_estado,
    s.ruta_carta_pdf,
    s.nombre_archivo_pdf,
    m.ruta_carta_pdf AS ruta_listado_pdf,
    m.nombre_archivo_pdf AS nombre_archivo_listado,
    COALESCE(ep.nombre_estado_pago, 'PENDIENTE') AS nombre_estado_pago,
    m.id_estado_pago,
    {$areasAggregate} AS areas,
    {$visitorsAggregate} AS visitantes_nombres
FROM solicitudes s
INNER JOIN solicitantes so
    ON s.id_solicitante = so.id_solicitante
INNER JOIN niveles_academicos n
    ON s.id_nivel = n.id_nivel
INNER JOIN estados e
    ON s.id_estado = e.id_estado
LEFT JOIN solicitud_areas sa
    ON s.id_solicitud = sa.id_solicitud
LEFT JOIN areas_interes ai
    ON sa.id_area = ai.id_area
LEFT JOIN solicitud_museo m
    ON s.id_solicitud = m.id_solicitud
LEFT JOIN estado_pago ep
    ON m.id_estado_pago = ep.id_estado_pago
LEFT JOIN solicitudes_ocultas sox
    ON s.id_solicitud = sox.id_solicitud
WHERE sox.id_solicitud IS NULL
$condicion_estado
GROUP BY 
    s.id_solicitud,
    so.nombre_solicitante,
    so.nombre_institucion,
    so.correo,
    so.telefono,
    s.fecha_visita,
    s.hora_visita,
    s.cantidad_visitantes,
    n.nombre_nivel,
    e.nombre_estado,
    s.ruta_carta_pdf,
    m.ruta_carta_pdf,
    m.nombre_archivo_pdf,
    ep.nombre_estado_pago,
    m.id_estado_pago,
    s.nombre_archivo_pdf,
    s.fecha_registro
ORDER BY s.fecha_registro DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bar_colors = ['blue', 'orange', 'slate', 'teal', 'rose'];
?>

<div class="card-table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Área</th>
                <th>Institución</th>
                <th>Solicitante</th>
                <th>Teléfono</th>
                <th>Total visita</th>
                <th>Visitantes</th>
                <th>Fecha & Hora</th>
                <th>Estado</th>
                <th>Pago</th>
                <th>Carta PDF</th>
                <th>Nombres de visitantes</th>
                <th>Correo</th>
                <th>Acciones</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach($datos as $index => $row): ?>

            <?php
            $color = $bar_colors[$index % count($bar_colors)];
            $estado = strtolower(trim($row['nombre_estado']));

            if ($estado == 'aprobado') {
                $estado_class = 'approved';
            } elseif ($estado == 'rechazado') {
                $estado_class = 'rejected';
            } else {
                $estado_class = 'pending';
            }
            ?>

            <tr>
                <td><?= $row['id_solicitud'] ?></td>

                <!-- ÁREAS -->
                <td>
                    <div class="area-cell">
                        <div class="area-bar <?= $color ?>"></div>

                        <div class="area-box">

                            <?php
                            $stmtAreas = $conn->prepare("
                                SELECT 
                                    sa.id_area,
                                    sa.id_area_asignada,
                                    ai.nombre_area AS area_original,
                                    aia.nombre_area AS area_asignada
                                FROM solicitud_areas sa
                                LEFT JOIN areas_interes ai
                                    ON sa.id_area = ai.id_area
                                LEFT JOIN areas_interes aia
                                    ON sa.id_area_asignada = aia.id_area
                                WHERE sa.id_solicitud = ?
                            ");
                            $stmtAreas->execute([$row['id_solicitud']]);
                            $areasSolicitud = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);
                            ?>

                            <?php foreach($areasSolicitud as $area): ?>
                                <div class="area-row">

                                    <span class="area-text">
                                        <?= htmlspecialchars($area['area_asignada'] ?? $area['area_original']) ?>
                                    </span>

                                    <form method="POST" action="reasignar_area.php" style="display:flex; gap:6px;">
                                        <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                        <input type="hidden" name="id_area_original" value="<?= $area['id_area'] ?>">
                                        <input type="hidden" name="from_superadmin" value="1">

                                        <select name="id_area_nueva" class="area-select" required>
                                            <option value="">▼</option>
                                            <?php foreach($areasDisponiblesList as $opcion): ?>
                                                <option value="<?= $opcion['id_area'] ?>">
                                                    <?= htmlspecialchars($opcion['nombre_area']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit" class="btn-small btn-change">↻</button>
                                    </form>

                                    <form method="POST" action="eliminar_area_solicitada.php">
                                        <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                        <input type="hidden" name="id_area" value="<?= $area['id_area'] ?>">
                                        <input type="hidden" name="from_superadmin" value="1">
                                        <button type="submit" class="btn-small btn-delete">X</button>
                                    </form>

                                </div>
                            <?php endforeach; ?>

                            <form method="POST" action="guardar_area_asignada.php" class="area-add-row">
                                <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                <input type="hidden" name="from_superadmin" value="1">

                                <select name="id_area_asignada" class="area-select" required>
                                    <option value="">Agregar</option>
                                    <?php foreach($areasDisponiblesList as $nueva): ?>
                                        <option value="<?= $nueva['id_area'] ?>">
                                            <?= htmlspecialchars($nueva['nombre_area']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" class="btn-small btn-add">+</button>
                            </form>

                        </div>
                    </div>
                </td>

                <td><?= htmlspecialchars($row['nombre_institucion']) ?></td>

                <td>
                    <div class="requester-info">
                        <span class="name"><?= htmlspecialchars($row['nombre_solicitante']) ?></span>
                        <span class="email"><?= htmlspecialchars($row['correo']) ?></span>
                    </div>
                </td>

                <td><?= htmlspecialchars($row['telefono']) ?></td>

                <td>
                    <?= $row['total_museo'] > 0 ? 'Q ' . number_format($row['total_museo'], 2) : '—' ?>
                </td>

                <td><?= str_pad($row['cantidad_visitantes'], 2, '0', STR_PAD_LEFT) ?></td>

                <td>
                    <div><?= htmlspecialchars($row['fecha_visita']) ?></div>
                    <div><?= htmlspecialchars($row['hora_visita']) ?></div>
                </td>

                <!-- ESTADO -->
                <td>
                    <form method="POST" action="modulos/actualizar_estado_solicitud.php">
                        <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">

                        <select name="id_estado"
                                class="estado-select status-<?= $estado_class ?>"
                                onchange="this.form.submit()">

                            <option value="4" <?= $estado=='pendiente'?'selected':'' ?>>PENDIENTE</option>
                            <option value="3" <?= $estado=='rechazado'?'selected':'' ?>>RECHAZADO</option>
                            <option value="2" <?= $estado=='aprobado'?'selected':'' ?>>APROBADO</option>
                        </select>
                    </form>
                </td>

                <td>
                    <span class="status-pill <?= (int)$row['id_estado_pago'] === 2 ? 'status-paid' : 'status-pending' ?>">
                        <?= htmlspecialchars(strtoupper($row['nombre_estado_pago'])) ?>
                    </span>
                </td>

                <!-- PDF CARTA -->
                <td>
                    <button
                        type="button"
                        class="btn-pdf"
                        data-archivo="<?= htmlspecialchars(basename($row['ruta_carta_pdf'])) ?>"
                        data-type="cartas"
                        onclick="mostrarPDF(this)">
                        Ver Carta
                    </button>
                </td>

                <td>
                    <?php if (!empty($row['visitantes_nombres'])): ?>
                        <span title="<?= htmlspecialchars($row['visitantes_nombres']) ?>">
                            <?= htmlspecialchars($row['visitantes_nombres']) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#999;">Sin nombres registrados</span>
                    <?php endif; ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['correo']) ?>
                </td>

                <!-- ACCIONES -->
                <td>
                    <?php if ($estado == "rechazado"): ?>
                        <form method="POST" action="enviar_correo.php" style="display:inline-block; margin-bottom: 4px;">
                            <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                            <input type="hidden" name="correo" value="<?= htmlspecialchars($row['correo']) ?>">
                            <input type="hidden" name="from_superadmin" value="1">
                            <button type="submit" class="btn-mail">
                                📧 Enviar
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" action="modulos/eliminar_solicitud.php" style="display:inline-block;">
                        <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                        <input type="hidden" name="from_superadmin" value="1">
                        <input type="hidden" name="estado" value="<?= htmlspecialchars($filtro_estado) ?>">
                        <button type="submit" class="btn-delete" onclick="return confirm('¿Ocultar esta solicitud del dashboard?')">
                            🗑 Ocultar
                        </button>
                    </form>
                </td>

        <?php endforeach; ?>

        </tbody>
    </table>
</div>
