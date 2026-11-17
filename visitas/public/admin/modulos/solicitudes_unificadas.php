<?php
if (!function_exists('can_access')) { http_response_code(403); exit('Acceso no permitido.'); }
$conn = conexion::conectar();

$canManageSolicitudes = can_access('gestionar_solicitudes');
$canManageReservaSalones = can_access('gestionar_reserva_salones');
$canManagePayments = can_access('gestionar_pagos');
$canSendMail = can_access_any(['gestionar_solicitudes', 'enviar_correos']);
$canHideSolicitudes = can_access_any(['gestionar_solicitudes', 'ocultar_solicitudes']);
$onlyApproved = can_access('ver_solicitudes_aprobadas') && !$canManageSolicitudes && !can_access('ver_solicitudes');
if (empty($_SESSION['visits_csrf'])) {
    $_SESSION['visits_csrf'] = bin2hex(random_bytes(24));
}
$visitsCsrf = (string) $_SESSION['visits_csrf'];

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
} elseif ($filtro_estado === 'cancelado') {
    $condiciones[] = "UPPER(TRIM(e.nombre_estado)) = 'CANCELADO'";
}

$where = "WHERE " . implode(" AND ", $condiciones);

$stmtAreasDisponibles = $conn->query("
    SELECT id_area, nombre_area, profesional_area, correo_area
    FROM areas_interes
    WHERE estado = 1
");
$areasDisponiblesList = $stmtAreasDisponibles->fetchAll(PDO::FETCH_ASSOC);
$stmtTodasAreas = $conn->query("\n    SELECT id_area, nombre_area, profesional_area, correo_area, estado\n    FROM areas_interes\n    ORDER BY estado DESC, nombre_area ASC\n");
$todasAreasList = $stmtTodasAreas->fetchAll(PDO::FETCH_ASSOC);

$areasAggregate = Conexion::groupConcat('ai.nombre_area', true);
$approversAggregate = Conexion::groupConcat("CONCAT(a.nombre, ' ', COALESCE(a.apellido, ''))", true);
$visitorsAggregate = Conexion::driver() === 'pgsql'
    ? "(SELECT string_agg(v.nombre_completo::text, ', ' ORDER BY v.orden) FROM solicitud_visitantes v WHERE v.id_solicitud = s.id_solicitud)"
    : "(SELECT GROUP_CONCAT(v.nombre_completo ORDER BY v.orden SEPARATOR ', ') FROM solicitud_visitantes v WHERE v.id_solicitud = s.id_solicitud)";
$sql = "
SELECT
    s.id_solicitud,
    s.id_estado,
    s.tipo_visita,
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
    m.pago_token,
    m.ruta_comprobante_pago,
    m.nombre_comprobante_pago,
    m.fecha_comprobante_pago,
    m.fecha_envio_formulario_pago,
    m.correo_formulario_pago_enviado,
    {$areasAggregate} AS areas,
    {$approversAggregate} AS aprobadores,
    s.fecha_registro,
    s.correo_enviado,
    src.request_code AS cengisalas_request_code,
    src.space_name AS cengisalas_space_name,
    src.configuration_name AS cengisalas_configuration_name,
    src.hora_fin AS cengisalas_hora_fin,
    sgv.agenda_nombre,
    sgv.agenda_ruta,
    sgv.notif_areas_at,
    sgv.notif_institucion_at,
    sgv.contacto_guardado_at,
    {$visitorsAggregate} AS visitantes_nombres
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
LEFT JOIN solicitud_reservas_cengisalas src ON src.id_solicitud = s.id_solicitud
LEFT JOIN solicitud_gestion_visita sgv ON sgv.id_solicitud = s.id_solicitud
$where
GROUP BY
    s.id_solicitud,
    s.tipo_visita,
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
    m.pago_token,
    m.ruta_comprobante_pago,
    m.nombre_comprobante_pago,
    m.fecha_comprobante_pago,
    m.fecha_envio_formulario_pago,
    m.correo_formulario_pago_enviado,
    s.fecha_registro,
    s.correo_enviado,
    src.request_code,
    src.space_name,
    src.configuration_name,
    src.hora_fin,
    sgv.agenda_nombre,
    sgv.agenda_ruta,
    sgv.notif_areas_at,
    sgv.notif_institucion_at,
    sgv.contacto_guardado_at
ORDER BY s.fecha_registro DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$areasPorSolicitud = [];
$fotosPorSolicitud = [];
$eventosPorSolicitud = [];
$visitantesPorSolicitud = [];

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

    $stmtFotos = $conn->prepare("SELECT id_foto, id_solicitud, nombre_archivo, ruta_archivo, created_at FROM solicitud_fotos_visita WHERE id_solicitud IN ($placeholders) ORDER BY created_at, id_foto");
    $stmtFotos->execute($idsSolicitudes);
    foreach ($stmtFotos->fetchAll(PDO::FETCH_ASSOC) as $foto) {
        $fotosPorSolicitud[$foto['id_solicitud']][] = $foto;
    }

    $stmtEventos = $conn->prepare("SELECT id_solicitud, tipo, descripcion, actor, created_at FROM solicitud_eventos_visita WHERE id_solicitud IN ($placeholders) ORDER BY created_at, id_evento");
    $stmtEventos->execute($idsSolicitudes);
    foreach ($stmtEventos->fetchAll(PDO::FETCH_ASSOC) as $evento) {
        $eventosPorSolicitud[$evento['id_solicitud']][] = $evento;
    }

    $stmtVisitantes = $conn->prepare("SELECT id_solicitud, nombre_completo, orden FROM solicitud_visitantes WHERE id_solicitud IN ($placeholders) ORDER BY id_solicitud, orden");
    $stmtVisitantes->execute($idsSolicitudes);
    foreach ($stmtVisitantes->fetchAll(PDO::FETCH_ASSOC) as $visitante) {
        $visitantesPorSolicitud[$visitante['id_solicitud']][] = (string) $visitante['nombre_completo'];
    }
}

$bar_colors = ['blue', 'orange', 'slate', 'teal', 'rose'];
$totalSolicitudes = count($datos);
$totalPendientes = 0;
$totalAprobadas = 0;
$totalRechazadas = 0;
$totalCanceladas = 0;

foreach ($datos as $filaResumen) {
    $estadoResumen = strtoupper(trim((string) ($filaResumen['nombre_estado'] ?? '')));
    if (in_array($estadoResumen, ['PENDIENTE', 'ENVIADO'], true)) {
        $totalPendientes++;
    } elseif ($estadoResumen === 'APROBADO') {
        $totalAprobadas++;
    } elseif ($estadoResumen === 'RECHAZADO') {
        $totalRechazadas++;
    } elseif ($estadoResumen === 'CANCELADO') {
        $totalCanceladas++;
    }
}

$reviewRequests = [];
foreach ($datos as $reviewRow) {
    $reviewRequests[(string) $reviewRow['id_solicitud']] = [
        'id' => (int) $reviewRow['id_solicitud'],
        'type' => (string) ($reviewRow['tipo_visita'] ?? 'cengicana'),
        'status' => strtolower(trim((string) $reviewRow['nombre_estado'])),
        'institution' => (string) $reviewRow['nombre_institucion'],
        'requester' => (string) $reviewRow['nombre_solicitante'],
        'date' => (string) $reviewRow['fecha_visita'],
        'startTime' => substr((string) $reviewRow['hora_visita'], 0, 5),
        'attendees' => (int) $reviewRow['cantidad_visitantes'],
        'email' => (string) $reviewRow['correo'],
        'phone' => (string) $reviewRow['telefono'],
        'agenda' => empty($reviewRow['agenda_nombre']) ? null : [
            'name' => (string) $reviewRow['agenda_nombre'],
            'path' => (string) $reviewRow['agenda_ruta'],
        ],
        'photos' => array_map(static function (array $photo): array {
            return [
                'id' => (int) $photo['id_foto'],
                'name' => (string) $photo['nombre_archivo'],
                'path' => (string) $photo['ruta_archivo'],
            ];
        }, $fotosPorSolicitud[$reviewRow['id_solicitud']] ?? []),
        'notifications' => [
            'areas' => !empty($reviewRow['notif_areas_at']) ? (string) $reviewRow['notif_areas_at'] : null,
            'institution' => !empty($reviewRow['notif_institucion_at']) ? (string) $reviewRow['notif_institucion_at'] : null,
        ],
        'contactSaved' => !empty($reviewRow['contacto_guardado_at']),
        'events' => array_map(static function (array $event): array {
            return [
                'type' => (string) $event['tipo'],
                'description' => (string) $event['descripcion'],
                'actor' => (string) ($event['actor'] ?? ''),
                'date' => (string) $event['created_at'],
            ];
        }, $eventosPorSolicitud[$reviewRow['id_solicitud']] ?? []),
        'areas' => array_map(static function (array $area): array {
            return [
                'originalId' => (int) $area['id_area'],
                'name' => (string) ($area['area_asignada'] ?? $area['area_original']),
            ];
        }, $areasPorSolicitud[$reviewRow['id_solicitud']] ?? []),
        'roomRequest' => empty($reviewRow['cengisalas_request_code']) ? null : [
            'code' => (string) $reviewRow['cengisalas_request_code'],
            'space' => (string) $reviewRow['cengisalas_space_name'],
            'configuration' => (string) $reviewRow['cengisalas_configuration_name'],
            'endTime' => substr((string) $reviewRow['cengisalas_hora_fin'], 0, 5),
        ],
    ];
}

// Permite reutilizar la consulta y sus permisos desde la vista renovada sin
// imprimir la interfaz anterior.
if (defined('VISITS_DATA_ONLY') && VISITS_DATA_ONLY) {
    return;
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
    <div class="mini-stat-card">
        <span class="mini-stat-label">Canceladas</span>
        <strong><?= $totalCanceladas ?></strong>
    </div>
</div>

<?php if ($canManageSolicitudes): ?>
<div id="visitReviewModal" class="visit-modal" aria-hidden="true">
    <div class="visit-modal-card visit-review-card" role="dialog" aria-modal="true" aria-labelledby="visitReviewTitle">
        <button type="button" class="visit-modal-close" data-close-visit-modal aria-label="Cerrar">&times;</button>
        <span class="visit-modal-kicker">Revisión administrativa</span>
        <h2 id="visitReviewTitle">Solicitud de visita</h2>
        <div id="visitReviewSummary" class="visit-review-summary"></div>

        <section class="visit-review-section">
            <div class="visit-review-section-head">
                <div><h3>Áreas solicitadas</h3><p>Puedes reemplazar, retirar o agregar áreas antes de decidir.</p></div>
                <button type="button" id="addVisitArea" class="visit-button secondary">+ Agregar área</button>
            </div>
            <div id="visitReviewAreas" class="visit-review-areas"></div>
        </section>

        <?php if ($canManageReservaSalones): ?>
        <section class="visit-review-section room-link-section">
            <div>
                <h3>Salón para la visita</h3>
                <p>Consulta CengiSalas con la fecha y hora de inicio de esta visita.</p>
            </div>
            <button type="button" id="openVisitRoom" class="visit-button blue">Solicitar salón</button>
            <div id="existingRoomRequest" class="existing-room-request hidden"></div>
        </section>
        <?php endif; ?>

        <div class="visit-decision-actions">
            <button type="button" class="visit-button secondary" data-close-visit-modal>Seguir revisando</button>
            <button type="button" class="visit-button danger" data-visit-decision="cancel">Cancelar solicitud</button>
            <button type="button" class="visit-button danger-outline" data-visit-decision="reject">Rechazar</button>
            <button type="button" class="visit-button primary" data-visit-decision="approve">Aprobar</button>
        </div>
    </div>
</div>

<div id="replaceAreaModal" class="visit-modal nested" aria-hidden="true">
    <div class="visit-modal-card compact" role="dialog" aria-modal="true" aria-labelledby="replaceAreaTitle">
        <button type="button" class="visit-modal-close" data-close-replace-modal aria-label="Cerrar">&times;</button>
        <span class="visit-modal-kicker">Reemplazar área</span>
        <h2 id="replaceAreaTitle">Selecciona una nueva área</h2>
        <div class="replacement-route">
            <div><small>Área que se reemplazará</small><strong id="replaceAreaCurrent"></strong></div>
            <span aria-hidden="true">→</span>
            <label>Nueva área
                <select id="replaceAreaSelect">
                    <option value="">Seleccionar área</option>
                    <?php foreach ($areasDisponiblesList as $availableArea): ?>
                        <option value="<?= (int) $availableArea['id_area'] ?>"><?= htmlspecialchars($availableArea['nombre_area']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="visit-modal-actions">
            <button type="button" class="visit-button secondary" data-close-replace-modal>Volver</button>
            <button type="button" id="acceptAreaReplacement" class="visit-button primary">Aceptar reemplazo</button>
        </div>
    </div>
</div>

<div id="addAreaModal" class="visit-modal nested" aria-hidden="true">
    <div class="visit-modal-card compact" role="dialog" aria-modal="true" aria-labelledby="addAreaTitle">
        <button type="button" class="visit-modal-close" data-close-add-modal aria-label="Cerrar">&times;</button>
        <span class="visit-modal-kicker">Agregar área</span>
        <h2 id="addAreaTitle">Añadir otra área a la visita</h2>
        <label class="visit-field">Área disponible
            <select id="addAreaSelect">
                <option value="">Seleccionar área</option>
                <?php foreach ($areasDisponiblesList as $availableArea): ?>
                    <option value="<?= (int) $availableArea['id_area'] ?>"><?= htmlspecialchars($availableArea['nombre_area']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="visit-modal-actions">
            <button type="button" class="visit-button secondary" data-close-add-modal>Volver</button>
            <button type="button" id="acceptAreaAddition" class="visit-button primary">Agregar área</button>
        </div>
    </div>
</div>

<?php if ($canManageReservaSalones): ?>
<div id="visitRoomModal" class="visit-modal nested" aria-hidden="true">
    <div class="visit-modal-card" role="dialog" aria-modal="true" aria-labelledby="visitRoomTitle">
        <button type="button" class="visit-modal-close" data-close-room-modal aria-label="Cerrar">&times;</button>
        <span class="visit-modal-kicker">Conexión con CengiSalas</span>
        <h2 id="visitRoomTitle">Solicitar salón disponible</h2>
        <p class="visit-modal-description">La hora de inicio proviene de la visita y no puede modificarse. Ajusta únicamente la finalización.</p>
        <div class="visit-room-times">
            <label>Fecha<input type="date" id="visitRoomDate" readonly></label>
            <label>Hora de inicio<input type="time" id="visitRoomStart" readonly></label>
            <label>Hora de finalización<input type="time" id="visitRoomEnd" required></label>
        </div>
        <div id="visitRoomAvailabilityMessage" class="availability-message">Selecciona la hora de finalización para consultar disponibilidad.</div>
        <div class="visit-room-selection">
            <label>Salón disponible<select id="visitRoomSpace" disabled><option value="">Consultando...</option></select></label>
            <label>Distribución<select id="visitRoomConfiguration" disabled><option value="">Selecciona un salón</option></select></label>
        </div>
        <div class="visit-modal-actions">
            <button type="button" class="visit-button secondary" data-close-room-modal>Volver</button>
            <button type="button" id="submitVisitRoom" class="visit-button primary" disabled>Enviar solicitud a CengiSalas</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="visitActionMessage" class="visit-action-message" role="status" aria-live="polite"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const requests = <?= json_encode($reviewRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrf = <?= json_encode($visitsCsrf) ?>;
    const reviewModal = document.getElementById('visitReviewModal');
    const replaceModal = document.getElementById('replaceAreaModal');
    const addModal = document.getElementById('addAreaModal');
    const roomModal = document.getElementById('visitRoomModal');
    let activeRequest = null;
    let replacementArea = null;
    let availableRooms = [];

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[character]);
    const isPending = request => request && ['pendiente', 'enviado'].includes(request.status);
    const showModal = modal => { if (modal) { modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); } };
    const hideModal = modal => { if (modal) { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); } };
    const notify = (message, error = false) => {
        const box = document.getElementById('visitActionMessage');
        box.textContent = message;
        box.className = `visit-action-message show${error ? ' error' : ''}`;
        window.setTimeout(() => box.classList.remove('show'), 4200);
    };
    const postAction = async payload => {
        const response = await fetch('gestion_solicitud_visita.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({...payload, csrf})
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) throw new Error(data.error || 'No fue posible completar la operación.');
        return data;
    };
    const setBusy = (button, busy) => {
        if (!button) return;
        if (!button.dataset.label) button.dataset.label = button.textContent;
        button.disabled = busy;
        button.textContent = busy ? 'Procesando…' : button.dataset.label;
    };

    function renderReview() {
        if (!activeRequest) return;
        document.getElementById('visitReviewTitle').textContent = `Solicitud de visita #${activeRequest.id}`;
        document.getElementById('visitReviewSummary').innerHTML = `
            <div><small>Institución</small><strong>${escapeHtml(activeRequest.institution)}</strong></div>
            <div><small>Solicitante</small><strong>${escapeHtml(activeRequest.requester)}</strong></div>
            <div><small>Programación</small><strong>${escapeHtml(activeRequest.date)} · ${escapeHtml(activeRequest.startTime)}</strong></div>
            <div><small>Visitantes</small><strong>${activeRequest.attendees}</strong></div>`;
        const areas = document.getElementById('visitReviewAreas');
        areas.innerHTML = activeRequest.areas.length ? activeRequest.areas.map(area => `
            <div class="visit-review-area">
                <span>${escapeHtml(area.name)}</span>
                <div>
                    <button type="button" class="visit-button small blue-outline" data-replace-area="${area.originalId}">Reemplazar</button>
                    <button type="button" class="visit-button small danger-outline" data-remove-area="${area.originalId}">Quitar</button>
                </div>
            </div>`).join('') : '<div class="visit-empty-state">No quedan áreas asignadas. Agrega una antes de aprobar.</div>';
        areas.querySelectorAll('[data-replace-area]').forEach(button => button.addEventListener('click', () => {
            replacementArea = activeRequest.areas.find(area => area.originalId === Number(button.dataset.replaceArea));
            document.getElementById('replaceAreaCurrent').textContent = replacementArea?.name || '';
            document.getElementById('replaceAreaSelect').value = '';
            showModal(replaceModal);
        }));
        areas.querySelectorAll('[data-remove-area]').forEach(button => button.addEventListener('click', async () => {
            const area = activeRequest.areas.find(item => item.originalId === Number(button.dataset.removeArea));
            if (!window.confirm(`¿Quitar el área “${area?.name || ''}” de esta solicitud?`)) return;
            setBusy(button, true);
            try {
                await postAction({action: 'remove_area', request_id: activeRequest.id, original_area_id: Number(button.dataset.removeArea)});
                window.location.reload();
            } catch (error) { notify(error.message, true); setBusy(button, false); }
        }));
        const existing = document.getElementById('existingRoomRequest');
        const roomButton = document.getElementById('openVisitRoom');
        if (existing && roomButton) {
            if (activeRequest.roomRequest) {
                existing.classList.remove('hidden');
                existing.innerHTML = `<strong>Solicitud ${escapeHtml(activeRequest.roomRequest.code)}</strong><span>${escapeHtml(activeRequest.roomRequest.space)} · ${escapeHtml(activeRequest.roomRequest.configuration)} · hasta ${escapeHtml(activeRequest.roomRequest.endTime)}</span>`;
                roomButton.disabled = true;
                roomButton.textContent = 'Salón solicitado';
            } else {
                existing.classList.add('hidden');
                roomButton.disabled = false;
                roomButton.textContent = 'Solicitar salón';
            }
        }
    }

    document.querySelectorAll('[data-review-request]').forEach(button => button.addEventListener('click', () => {
        activeRequest = requests[String(button.dataset.reviewRequest)];
        if (!isPending(activeRequest)) return;
        renderReview();
        showModal(reviewModal);
    }));
    document.querySelectorAll('[data-close-visit-modal]').forEach(button => button.addEventListener('click', () => hideModal(reviewModal)));
    document.querySelectorAll('[data-close-replace-modal]').forEach(button => button.addEventListener('click', () => hideModal(replaceModal)));
    document.querySelectorAll('[data-close-add-modal]').forEach(button => button.addEventListener('click', () => hideModal(addModal)));
    document.querySelectorAll('[data-close-room-modal]').forEach(button => button.addEventListener('click', () => hideModal(roomModal)));
    [reviewModal, replaceModal, addModal, roomModal].filter(Boolean).forEach(modal => modal.addEventListener('click', event => {
        if (event.target === modal) hideModal(modal);
    }));

    document.getElementById('addVisitArea')?.addEventListener('click', () => {
        document.getElementById('addAreaSelect').value = '';
        showModal(addModal);
    });
    document.getElementById('acceptAreaReplacement')?.addEventListener('click', async event => {
        const newAreaId = Number(document.getElementById('replaceAreaSelect').value);
        if (!newAreaId) return notify('Selecciona el área de reemplazo.', true);
        setBusy(event.currentTarget, true);
        try {
            await postAction({action: 'replace_area', request_id: activeRequest.id, original_area_id: replacementArea.originalId, new_area_id: newAreaId});
            window.location.reload();
        } catch (error) { notify(error.message, true); setBusy(event.currentTarget, false); }
    });
    document.getElementById('acceptAreaAddition')?.addEventListener('click', async event => {
        const newAreaId = Number(document.getElementById('addAreaSelect').value);
        if (!newAreaId) return notify('Selecciona el área que deseas agregar.', true);
        setBusy(event.currentTarget, true);
        try {
            await postAction({action: 'add_area', request_id: activeRequest.id, new_area_id: newAreaId});
            window.location.reload();
        } catch (error) { notify(error.message, true); setBusy(event.currentTarget, false); }
    });
    document.querySelectorAll('[data-visit-decision]').forEach(button => button.addEventListener('click', async () => {
        const decision = button.dataset.visitDecision;
        const labels = {approve: 'aprobar', reject: 'rechazar', cancel: 'cancelar'};
        if (!window.confirm(`¿Confirmas que deseas ${labels[decision]} esta solicitud? Esta decisión bloqueará la edición de áreas.`)) return;
        setBusy(button, true);
        try {
            await postAction({action: 'change_status', request_id: activeRequest.id, decision});
            window.location.reload();
        } catch (error) { notify(error.message, true); setBusy(button, false); }
    }));

    function defaultEndTime(startTime) {
        const [hour, minute] = startTime.split(':').map(Number);
        return `${String(Math.min(hour + 1, 23)).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    }
    function renderConfigurations() {
        const spaceId = Number(document.getElementById('visitRoomSpace').value);
        const space = availableRooms.find(item => item.id === spaceId);
        const select = document.getElementById('visitRoomConfiguration');
        select.innerHTML = space ? space.configurations.map(item => `<option value="${item.id}">${escapeHtml(item.name)} · capacidad ${item.capacity}</option>`).join('') : '<option value="">Selecciona un salón</option>';
        select.disabled = !space;
        document.getElementById('submitVisitRoom').disabled = !space;
    }
    async function loadAvailability() {
        const endTime = document.getElementById('visitRoomEnd').value;
        const message = document.getElementById('visitRoomAvailabilityMessage');
        const spaceSelect = document.getElementById('visitRoomSpace');
        const submit = document.getElementById('submitVisitRoom');
        message.className = 'availability-message loading';
        message.textContent = 'Consultando salones disponibles en CengiSalas…';
        spaceSelect.disabled = true;
        submit.disabled = true;
        try {
            const response = await fetch(`cengisalas_salon_visita.php?request_id=${activeRequest.id}&end_time=${encodeURIComponent(endTime)}`);
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) throw new Error(data.error || 'No fue posible consultar disponibilidad.');
            availableRooms = data.spaces || [];
            spaceSelect.innerHTML = availableRooms.length
                ? '<option value="">Seleccionar salón</option>' + availableRooms.map(item => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')
                : '<option value="">No hay salones disponibles</option>';
            spaceSelect.disabled = !availableRooms.length;
            message.className = `availability-message${availableRooms.length ? ' success' : ' warning'}`;
            message.textContent = availableRooms.length
                ? `${availableRooms.length} ${availableRooms.length === 1 ? 'salón disponible' : 'salones disponibles'} para este horario.`
                : 'No hay salones con capacidad suficiente disponibles en ese horario.';
            renderConfigurations();
        } catch (error) {
            availableRooms = [];
            spaceSelect.innerHTML = '<option value="">Sin disponibilidad</option>';
            message.className = 'availability-message error';
            message.textContent = error.message;
        }
    }
    document.getElementById('openVisitRoom')?.addEventListener('click', () => {
        document.getElementById('visitRoomDate').value = activeRequest.date;
        document.getElementById('visitRoomStart').value = activeRequest.startTime;
        document.getElementById('visitRoomEnd').value = defaultEndTime(activeRequest.startTime);
        showModal(roomModal);
        loadAvailability();
    });
    document.getElementById('visitRoomEnd')?.addEventListener('change', loadAvailability);
    document.getElementById('visitRoomSpace')?.addEventListener('change', renderConfigurations);
    document.getElementById('submitVisitRoom')?.addEventListener('click', async event => {
        setBusy(event.currentTarget, true);
        try {
            const response = await fetch('cengisalas_salon_visita.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    csrf, request_id: activeRequest.id,
                    end_time: document.getElementById('visitRoomEnd').value,
                    space_id: Number(document.getElementById('visitRoomSpace').value),
                    configuration_id: Number(document.getElementById('visitRoomConfiguration').value)
                })
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) throw new Error(data.error || 'No fue posible solicitar el salón.');
            notify(`Solicitud ${data.request_code} enviada a CengiSalas.`);
            window.setTimeout(() => window.location.reload(), 900);
        } catch (error) { notify(error.message, true); setBusy(event.currentTarget, false); }
    });
});
</script>
<?php endif; ?>

<div class="filter-chip-row">
    <a href="dashboard_unificado.php?modulo=solicitudes" class="filter-chip <?= $filtro_estado === '' ? 'active' : '' ?>">Todas</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=pendiente" class="filter-chip <?= $filtro_estado === 'pendiente' ? 'active' : '' ?>">Pendientes</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=aprobado" class="filter-chip <?= $filtro_estado === 'aprobado' ? 'active' : '' ?>">Aprobadas</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=rechazado" class="filter-chip <?= $filtro_estado === 'rechazado' ? 'active' : '' ?>">Rechazadas</a>
    <a href="dashboard_unificado.php?modulo=solicitudes&estado=cancelado" class="filter-chip <?= $filtro_estado === 'cancelado' ? 'active' : '' ?>">Canceladas</a>
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
                <th>Total visita</th>
                <th>Visitantes</th>
                <th>Fecha & Hora</th>
                <th>Estado</th>
                <th>Pago</th>
                <th>Carta PDF</th>
                <th>Nombres de visitantes</th>
                <th>Correo</th>
                <?php if ($canManageSolicitudes || $canSendMail || $canHideSolicitudes): ?>
                    <th>Acciones</th>
                <?php endif; ?>
            </tr>
        </thead>

        <tbody>
        <?php if (empty($datos)): ?>
            <tr>
                <td colspan="<?= ($canManageSolicitudes || $canSendMail || $canHideSolicitudes) ? 14 : 13 ?>" class="table-empty">
                    No hay solicitudes para este filtro en este momento.
                </td>
            </tr>
        <?php endif; ?>
        <?php foreach($datos as $index => $row): ?>
            <?php
            $color = $bar_colors[$index % count($bar_colors)];
            $estado = strtolower(trim($row['nombre_estado']));
            $estado_class = $estado === 'aprobado'
                ? 'approved'
                : ($estado === 'rechazado' ? 'rejected' : ($estado === 'cancelado' ? 'cancelled' : 'pending'));
            ?>
            <tr>
                <td><?= $row['id_solicitud'] ?></td>
                <td>
                    <div class="area-cell">
                        <div class="area-bar <?= $color ?>"></div>
                        <?php if ($canManageSolicitudes): ?>
                            <div class="area-summary-list">
                                <?php $areasSolicitud = $areasPorSolicitud[$row['id_solicitud']] ?? []; ?>
                                <?php foreach($areasSolicitud as $area): ?>
                                    <span class="area-summary-chip"><?= htmlspecialchars($area['area_asignada'] ?? $area['area_original']) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($areasSolicitud)): ?>
                                    <span class="area-summary-empty">Sin áreas</span>
                                <?php endif; ?>
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
                        <?php $isPendingReview = in_array($estado, ['pendiente', 'enviado'], true); ?>
                        <button
                            type="button"
                            class="review-status-button status-<?= $estado_class ?>"
                            data-review-request="<?= (int) $row['id_solicitud'] ?>"
                            <?= $isPendingReview ? '' : 'disabled' ?>
                        ><?= $isPendingReview ? 'Pendiente de aprobación · Revisar' : htmlspecialchars(ucfirst($estado)) ?></button>
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
                    <?php if (!empty($row['visitantes_nombres'])): ?>
                        <div style="max-width:260px;line-height:1.45;">
                            <?= htmlspecialchars($row['visitantes_nombres']) ?>
                        </div>
                    <?php else: ?>
                        <span style="color:#999;">Sin nombres registrados</span>
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
