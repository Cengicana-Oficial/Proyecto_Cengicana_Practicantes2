<?php
$conn = conexion::conectar();

$canManageSolicitudes = can_access('gestionar_solicitudes');
$canManageReservaSalones = can_access('gestionar_reserva_salones');
$canSendMail = can_access_any(['gestionar_solicitudes', 'enviar_correos']);
$canHideSolicitudes = can_access_any(['gestionar_solicitudes', 'ocultar_solicitudes']);
$onlyApproved = can_access('ver_solicitudes_aprobadas') && !$canManageSolicitudes && !can_access('ver_solicitudes');

$filtro_estado = isset($_GET['estado']) ? strtolower(trim($_GET['estado'])) : '';
$condiciones = ["sox.id_solicitud IS NULL"];
$params = [];

if ($onlyApproved) {
    $condiciones[] = "LOWER(TRIM(e.nombre_estado)) = 'aprobado'";
} elseif ($filtro_estado === 'pendiente') {
    $condiciones[] = "UPPER(TRIM(e.nombre_estado)) IN ('ENVIADO', 'PENDIENTE')";
} elseif ($filtro_estado === 'aprobado') {
    $condiciones[] = "UPPER(TRIM(e.nombre_estado)) = 'APROBADO'";
} elseif ($filtro_estado === 'rechazado') {
    $condiciones[] = "UPPER(TRIM(e.nombre_estado)) = 'RECHAZADO'";
}

$where = "WHERE " . implode(" AND ", $condiciones);

$stmtAreasDisponibles = $conn->query("
    SELECT id_area, nombre_area
    FROM areas_interes
    WHERE estado = 1
");
$areasDisponiblesList = $stmtAreasDisponibles->fetchAll(PDO::FETCH_ASSOC);

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
    GROUP_CONCAT(DISTINCT ai.nombre_area SEPARATOR ', ') AS areas,
    GROUP_CONCAT(DISTINCT CONCAT(a.nombre, ' ', a.apellido) SEPARATOR ', ') AS aprobadores,
    s.fecha_registro,
    s.correo_enviado
FROM solicitudes s
INNER JOIN solicitantes so ON s.id_solicitante = so.id_solicitante
INNER JOIN niveles_academicos n ON s.id_nivel = n.id_nivel
INNER JOIN estados e ON s.id_estado = e.id_estado
LEFT JOIN solicitud_areas sa ON s.id_solicitud = sa.id_solicitud
LEFT JOIN areas_interes ai ON sa.id_area = ai.id_area
LEFT JOIN solicitud_museo m ON s.id_solicitud = m.id_solicitud
LEFT JOIN estado_pago ep ON m.id_estado_pago = ep.id_estado_pago
LEFT JOIN aprobacion_solicitud aps ON s.id_solicitud = aps.id_solicitud
LEFT JOIN aprobadores a ON aps.id_aprobador = a.id_aprobador
LEFT JOIN solicitudes_ocultas sox ON s.id_solicitud = sox.id_solicitud
$where
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
    s.nombre_archivo_pdf,
    m.ruta_carta_pdf,
    m.nombre_archivo_pdf,
    ep.nombre_estado_pago,
    m.id_estado_pago,
    s.fecha_registro,
    s.correo_enviado
ORDER BY s.fecha_registro DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$areasPorSolicitud = [];

if ($canManageSolicitudes && !empty($datos)) {
    $idsSolicitudes = array_column($datos, 'id_solicitud');
    $placeholders = implode(',', array_fill(0, count($idsSolicitudes), '?'));

    $stmtAreasSolicitudes = $conn->prepare("
        SELECT
            sa.id_solicitud,
            sa.id_area,
            sa.id_area_asignada,
            ai.nombre_area AS area_original,
            aia.nombre_area AS area_asignada
        FROM solicitud_areas sa
        LEFT JOIN areas_interes ai ON sa.id_area = ai.id_area
        LEFT JOIN areas_interes aia ON sa.id_area_asignada = aia.id_area
        WHERE sa.id_solicitud IN ($placeholders)
        ORDER BY sa.id_solicitud, ai.nombre_area
    ");
    $stmtAreasSolicitudes->execute($idsSolicitudes);

    foreach ($stmtAreasSolicitudes->fetchAll(PDO::FETCH_ASSOC) as $area) {
        $areasPorSolicitud[$area['id_solicitud']][] = $area;
    }
}

$bar_colors = ['blue', 'orange', 'slate', 'teal', 'rose'];
$totalSolicitudes = count($datos);
$totalPendientes = 0;
$totalAprobadas = 0;
$totalRechazadas = 0;

foreach ($datos as $filaResumen) {
    $estadoResumen = strtoupper(trim((string) ($filaResumen['nombre_estado'] ?? '')));
    if (in_array($estadoResumen, ['PENDIENTE', 'ENVIADO'], true)) {
        $totalPendientes++;
    } elseif ($estadoResumen === 'APROBADO') {
        $totalAprobadas++;
    } elseif ($estadoResumen === 'RECHAZADO') {
        $totalRechazadas++;
    }
}
?>

<header class="dashboard-header dashboard-header-rich">
    <div>
        <span class="dashboard-kicker">Modulo de visitas</span>
        <h2>Solicitudes de Visitantes</h2>
        <p class="dashboard-subtitle">La informacion sigue igual, pero la vista queda mas limpia para revisar estados, areas, pagos y acciones.</p>
    </div>
</header>

<div class="dashboard-mini-stats">
    <div class="mini-stat-card">
        <span class="mini-stat-label">Total visibles</span>
        <strong><?= $totalSolicitudes ?></strong>
    </div>
    <div class="mini-stat-card">
        <span class="mini-stat-label">Pendientes</span>
        <strong><?= $totalPendientes ?></strong>
    </div>
    <div class="mini-stat-card">
        <span class="mini-stat-label">Aprobadas</span>
        <strong><?= $totalAprobadas ?></strong>
    </div>
    <div class="mini-stat-card">
        <span class="mini-stat-label">Rechazadas</span>
        <strong><?= $totalRechazadas ?></strong>
    </div>
</div>

<div class="filter-chip-row">
    <a href="dashboard_unificado.php?modulo=solicitudes" class="filter-chip <?= $filtro_estado === '' ? 'active' : '' ?>">Todas</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=pendiente" class="filter-chip <?= $filtro_estado === 'pendiente' ? 'active' : '' ?>">Pendientes</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=aprobado" class="filter-chip <?= $filtro_estado === 'aprobado' ? 'active' : '' ?>">Aprobadas</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=rechazado" class="filter-chip <?= $filtro_estado === 'rechazado' ? 'active' : '' ?>">Rechazadas</a>
</div>

<div class="card-table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Area</th>
                <th>Institucion</th>
                <th>Solicitante</th>
                <th>Telefono</th>
                <th>Total Museo</th>
                <th>Visitantes</th>
                <th>Fecha & Hora</th>
                <th>Estado</th>
                <th>Pago</th>
                <th>Carta PDF</th>
                <th>Listado Museo</th>
                <th>Correo</th>
                <?php if ($canManageSolicitudes || $canSendMail || $canHideSolicitudes): ?>
                    <th>Acciones</th>
                <?php endif; ?>
            </tr>
        </thead>

        <tbody>
        <?php if (empty($datos)): ?>
            <tr>
                <td colspan="<?= ($canManageSolicitudes || $canSendMail || $canHideSolicitudes) ? 13 : 12 ?>" class="table-empty">
                    No hay solicitudes para este filtro en este momento.
                </td>
            </tr>
        <?php endif; ?>
        <?php foreach($datos as $index => $row): ?>
            <?php
            $color = $bar_colors[$index % count($bar_colors)];
            $estado = strtolower(trim($row['nombre_estado']));
            $estado_class = $estado === 'aprobado' ? 'approved' : ($estado === 'rechazado' ? 'rejected' : 'pending');
            ?>
            <tr>
                <td><?= $row['id_solicitud'] ?></td>
                <td>
                    <div class="area-cell">
                        <div class="area-bar <?= $color ?>"></div>
                        <?php if ($canManageSolicitudes): ?>
                            <div class="area-box">
                                <?php $areasSolicitud = $areasPorSolicitud[$row['id_solicitud']] ?? []; ?>

                                <?php foreach($areasSolicitud as $area): ?>
                                    <div class="area-row">
                                        <span class="area-text">
                                            <?= htmlspecialchars($area['area_asignada'] ?? $area['area_original']) ?>
                                        </span>

                                        <form method="POST" action="reasignar_area.php" style="display:flex; gap:6px;">
                                            <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                            <input type="hidden" name="id_area_original" value="<?= $area['id_area'] ?>">
                                            <input type="hidden" name="from_unified" value="1">
                                            <select name="id_area_nueva" class="area-select" required>
                                                <option value="">v</option>
                                                <?php foreach($areasDisponiblesList as $opcion): ?>
                                                    <option value="<?= $opcion['id_area'] ?>">
                                                        <?= htmlspecialchars($opcion['nombre_area']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-small btn-change">R</button>
                                        </form>

                                        <form method="POST" action="eliminar_area_solicitada.php">
                                            <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                            <input type="hidden" name="id_area" value="<?= $area['id_area'] ?>">
                                            <input type="hidden" name="from_unified" value="1">
                                            <button type="submit" class="btn-small btn-delete">X</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>

                                <form method="POST" action="guardar_area_asignada.php" class="area-add-row">
                                    <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                    <input type="hidden" name="from_unified" value="1">
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
                        <?php else: ?>
                            <span class="area-name"><?= htmlspecialchars($row['areas'] ?? '') ?></span>
                        <?php endif; ?>
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
                <td><?= $row['total_museo'] > 0 ? 'Q ' . number_format($row['total_museo'], 2) : '-' ?></td>
                <td><?= str_pad($row['cantidad_visitantes'], 2, '0', STR_PAD_LEFT) ?></td>
                <td>
                    <div class="date-stack">
                        <strong><?= htmlspecialchars($row['fecha_visita']) ?></strong>
                        <span><?= htmlspecialchars($row['hora_visita']) ?></span>
                    </div>
                </td>
                <td>
                    <?php if ($canManageSolicitudes): ?>
                        <form method="POST" action="actualizar_estado.php">
                            <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                            <input type="hidden" name="from_unified" value="1">
                            <select name="id_estado" class="estado-select status-<?= $estado_class ?>" onchange="this.form.submit()">
                                <option value="4" <?= in_array($estado, ['pendiente', 'enviado']) ? 'selected' : '' ?>>PENDIENTE</option>
                                <option value="3" <?= $estado === 'rechazado' ? 'selected' : '' ?>>RECHAZADO</option>
                                <option value="2" <?= $estado === 'aprobado' ? 'selected' : '' ?>>APROBADO</option>
                            </select>
                        </form>
                    <?php else: ?>
                        <span class="status-<?= $estado_class ?>"><?= htmlspecialchars(strtoupper($row['nombre_estado'])) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-pill <?= (int)$row['id_estado_pago'] === 2 ? 'status-paid' : 'status-pending' ?>">
                        <?= htmlspecialchars(strtoupper($row['nombre_estado_pago'])) ?>
                    </span>
                </td>
                <td>
                    <button type="button" class="btn-pdf" data-archivo="<?= htmlspecialchars(basename($row['ruta_carta_pdf'])) ?>" data-type="cartas" onclick="mostrarPDF(this)">
                        Ver Carta
                    </button>
                </td>
                <td>
                    <?php if (!empty($row['ruta_listado_pdf'])): ?>
                        <button type="button" class="btn-pdf" data-archivo="<?= htmlspecialchars(basename($row['ruta_listado_pdf'])) ?>" data-type="listado" onclick="mostrarPDF(this)">
                            Ver Listado
                        </button>
                    <?php else: ?>
                        <span style="color:#999;">-</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($row['correo']) ?></td>
                <?php if ($canManageSolicitudes || $canSendMail || $canHideSolicitudes): ?>
                    <td>
                        <div class="action-cell">
                            <?php if ($canSendMail && in_array($estado, ['aprobado', 'rechazado'])): ?>
                                <?php if ((int)$row['correo_enviado'] === 0): ?>
                                    <button class="btn-mail btn-enviar-correo" data-id="<?= $row['id_solicitud'] ?>">Enviar</button>
                                <?php else: ?>
                                    <span class="mail-status sent">Enviado</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canManageReservaSalones): ?>
                                <a
                                    href="reserva_salones.php?id_solicitud=<?= (int) $row['id_solicitud'] ?>"
                                    class="btn-reserva"
                                    title="Abrir apartado de reserva de salones"
                                >
                                    Reserva salones
                                </a>
                            <?php endif; ?>
                            <?php if ($canHideSolicitudes): ?>
                                <form method="POST" action="modulos/eliminar_solicitud.php" style="display:inline-block;">
                                    <input type="hidden" name="id_solicitud" value="<?= $row['id_solicitud'] ?>">
                                    <input type="hidden" name="from_unified" value="1">
                                    <input type="hidden" name="estado" value="<?= htmlspecialchars($filtro_estado) ?>">
                                    <button type="submit" class="btn-delete" onclick="return confirm('Ocultar esta solicitud del dashboard?')">
                                        Ocultar
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
