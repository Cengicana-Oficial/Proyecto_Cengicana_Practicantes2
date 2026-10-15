<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function room_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function supabase_settings(): array
{
    $configPath = dirname(__DIR__, 3) . '/cengisalas/supabase-config.js';
    $config = is_file($configPath) ? (string) file_get_contents($configPath) : '';
    preg_match("/url:\s*'([^']+)'/", $config, $urlMatch);
    preg_match("/publishableKey:\s*'([^']+)'/", $config, $keyMatch);
    return [rtrim((string) ($urlMatch[1] ?? ''), '/'), (string) ($keyMatch[1] ?? '')];
}

function supabase_call(string $path, array $payload): mixed
{
    [$url, $apiKey] = supabase_settings();
    $token = (string) ($_SESSION['cengisalas_access_token'] ?? $apiKey);
    if ($url === '' || $apiKey === '') {
        throw new RuntimeException('No se encontro la configuracion de CengiSalas.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extension cURL de PHP no esta habilitada.');
    }

    $handle = curl_init($url . '/rest/v1/' . ltrim($path, '/'));
    // PostgREST espera un objeto JSON para una RPC sin argumentos. Enviar []
    // provoca un 400 aunque la función exista y tenga permisos.
    $requestBody = $payload === [] ? new stdClass() : $payload;
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $apiKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if ($body === false || $curlError !== '') {
        throw new RuntimeException('No fue posible comunicarse con CengiSalas.');
    }
    $decoded = json_decode((string) $body, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error_description'] ?? '') : '';
        throw new RuntimeException($message !== '' ? $message : 'CengiSalas rechazo la operacion.');
    }
    return $decoded;
}

function local_timestamp(string $date, string $time): string
{
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone('America/Guatemala'));
    if (!$value) {
        throw new RuntimeException('La fecha u hora no es valida.');
    }
    return $value->format('Y-m-d\TH:i:sP');
}

if (!can_access('gestionar_reserva_salones')) {
    room_respond(['success' => false, 'error' => 'No tienes permiso para solicitar salones.'], 403);
}

$payload = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? json_decode((string) file_get_contents('php://input'), true)
    : $_GET;
if (!is_array($payload)) {
    room_respond(['success' => false, 'error' => 'Solicitud invalida.'], 400);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals((string) ($_SESSION['visits_csrf'] ?? ''), (string) ($payload['csrf'] ?? ''))) {
    room_respond(['success' => false, 'error' => 'La sesion vencio. Actualiza la pagina.'], 419);
}

$requestId = (int) ($payload['request_id'] ?? 0);
$endTime = trim((string) ($payload['end_time'] ?? ''));
if ($requestId <= 0 || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
    room_respond(['success' => false, 'error' => 'Indica una hora de finalizacion valida.'], 422);
}

try {
    $conn = Conexion::conectar();
    // El módulo de visitas comparte la misma base de datos PostgreSQL de
    // CengiSalas.  No uses GROUP_CONCAT (sintaxis MySQL) aquí: cuando el
    // controlador es pgsql la expresión equivalente es STRING_AGG.
    $areasAggregate = Conexion::groupConcat(
        'COALESCE(aia.nombre_area, ai.nombre_area)',
        true,
        'COALESCE(aia.nombre_area, ai.nombre_area)'
    );
    $stmt = $conn->prepare(
        "SELECT s.id_solicitud,s.fecha_visita,s.hora_visita,s.cantidad_visitantes,
                upper(trim(e.nombre_estado)) estado,so.nombre_solicitante,so.nombre_institucion,so.correo,so.telefono,
                {$areasAggregate} AS areas
         FROM solicitudes s
         INNER JOIN estados e ON e.id_estado=s.id_estado
         INNER JOIN solicitantes so ON so.id_solicitante=s.id_solicitante
         LEFT JOIN solicitud_areas sa ON sa.id_solicitud=s.id_solicitud
         LEFT JOIN areas_interes ai ON ai.id_area=sa.id_area
         LEFT JOIN areas_interes aia ON aia.id_area=sa.id_area_asignada
         WHERE s.id_solicitud=?
         GROUP BY s.id_solicitud,s.fecha_visita,s.hora_visita,s.cantidad_visitantes,e.nombre_estado,
                  so.nombre_solicitante,so.nombre_institucion,so.correo,so.telefono"
    );
    $stmt->execute([$requestId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit) {
        throw new RuntimeException('No se encontro la solicitud de visita.');
    }
    if (!in_array((string) $visit['estado'], ['PENDIENTE', 'ENVIADO'], true)) {
        throw new RuntimeException('La solicitud ya fue decidida y no permite solicitar un salon.');
    }

    $startTime = substr((string) $visit['hora_visita'], 0, 5);
    if ($endTime <= $startTime) {
        throw new RuntimeException('La hora final debe ser posterior a la hora de inicio.');
    }
    $startsAt = local_timestamp((string) $visit['fecha_visita'], $startTime);
    $endsAt = local_timestamp((string) $visit['fecha_visita'], $endTime);

    $availability = supabase_call('rpc/get_public_space_availability_by_occurrence', [
        'p_occurrences' => [['starts_at' => $startsAt, 'ends_at' => $endsAt]],
    ]);
    $catalogs = supabase_call('rpc/get_public_request_catalogs', []);
    $availableSpaces = is_array($availability) && isset($availability[0]['spaces'])
        ? $availability[0]['spaces'] : [];

    $capacityBySpace = [];
    foreach (($catalogs['rooms'] ?? []) as $room) {
        $capacityBySpace[(string) $room['spaceId']] = $room['capacities'] ?? [];
    }
    if (!empty($catalogs['combinedSpace']['id'])) {
        $capacityBySpace[(string) $catalogs['combinedSpace']['id']] = $catalogs['combinedSpace']['capacities'] ?? [];
    }
    $configurationByCode = [];
    foreach (($catalogs['configurations'] ?? []) as $configuration) {
        $configurationByCode[(string) $configuration['code']] = $configuration;
    }

    $spaces = [];
    foreach ($availableSpaces as $space) {
        $configurations = [];
        foreach (($capacityBySpace[(string) $space['id']] ?? []) as $code => $capacity) {
            if ((int) $capacity >= (int) $visit['cantidad_visitantes'] && isset($configurationByCode[$code])) {
                $configuration = $configurationByCode[$code];
                $configurations[] = [
                    'id' => (int) $configuration['id'],
                    'name' => (string) ($configuration['name'] ?? $code),
                    'capacity' => (int) $capacity,
                ];
            }
        }
        if ($configurations !== []) {
            $spaces[] = [
                'id' => (int) $space['id'],
                'name' => (string) $space['name'],
                'configurations' => $configurations,
            ];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        room_respond([
            'success' => true,
            'date' => (string) $visit['fecha_visita'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'spaces' => $spaces,
        ]);
    }

    $spaceId = (int) ($payload['space_id'] ?? 0);
    $configurationId = (int) ($payload['configuration_id'] ?? 0);
    $selectedSpace = null;
    $selectedConfiguration = null;
    foreach ($spaces as $space) {
        if ($space['id'] !== $spaceId) continue;
        $selectedSpace = $space;
        foreach ($space['configurations'] as $configuration) {
            if ($configuration['id'] === $configurationId) $selectedConfiguration = $configuration;
        }
    }
    if (!$selectedSpace || !$selectedConfiguration) {
        throw new RuntimeException('El salon o la distribucion dejaron de estar disponibles.');
    }

    $existing = $conn->prepare('SELECT request_code FROM solicitud_reservas_cengisalas WHERE id_solicitud = ? LIMIT 1');
    $existing->execute([$requestId]);
    $existingCode = (string) $existing->fetchColumn();
    if ($existingCode !== '') {
        throw new RuntimeException('Esta visita ya genero la solicitud ' . $existingCode . ' en CengiSalas.');
    }

    $organizations = $catalogs['organizations'] ?? [];
    $organization = null;
    foreach ($organizations as $candidate) {
        if (mb_strtolower(trim((string) $candidate['name'])) === mb_strtolower(trim((string) $visit['nombre_institucion']))) {
            $organization = $candidate;
            break;
        }
    }
    if (!$organization) {
        foreach ($organizations as $candidate) {
            if (in_array(strtoupper((string) ($candidate['code'] ?? '')), ['EXTERNAL', 'EXTERNO'], true)
                || mb_strtolower((string) ($candidate['name'] ?? '')) === 'externo') {
                $organization = $candidate;
                break;
            }
        }
    }
    if (!$organization) {
        throw new RuntimeException('Configura la organizacion Externo en CengiSalas para registrar visitas.');
    }

    $meetingType = null;
    foreach (($catalogs['meetingTypes'] ?? []) as $candidate) {
        if (in_array(strtoupper((string) ($candidate['code'] ?? '')), ['VISIT', 'VISITA'], true)
            || str_contains(mb_strtolower((string) ($candidate['name'] ?? '')), 'visita')) {
            $meetingType = $candidate;
            break;
        }
    }
    $meetingType = $meetingType ?: (($catalogs['meetingTypes'] ?? [])[0] ?? null);
    if (!$meetingType) {
        throw new RuntimeException('No hay tipos de reunion activos en CengiSalas.');
    }

    $description = 'Visita de ' . $visit['nombre_institucion'] . '. Areas: ' . ($visit['areas'] ?: 'No indicadas') . '.';
    $requestCode = supabase_call('rpc/create_external_meeting_request', [
        'p_contact_name' => (string) $visit['nombre_solicitante'],
        'p_contact_email' => (string) $visit['correo'],
        'p_contact_phone' => (string) $visit['telefono'],
        'p_organization_id' => (int) $organization['id'],
        'p_title' => 'Visita - ' . (string) $visit['nombre_institucion'],
        'p_description' => $description,
        'p_meeting_type_id' => (int) $meetingType['id'],
        'p_configuration_type_id' => $configurationId,
        'p_attendees' => (int) $visit['cantidad_visitantes'],
        'p_occurrences' => [[
            'space_id' => $spaceId,
            'configuration_type_id' => $configurationId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]],
        'p_recurrence_type' => 'none',
        'p_recurrence_end_date' => null,
        'p_observations' => 'Generada desde la solicitud de visita #' . $requestId,
        'p_website' => '',
        'p_refreshment_ids' => [],
    ]);
    $requestCode = is_string($requestCode) ? $requestCode : (string) $requestCode;

    $save = $conn->prepare(
        'INSERT INTO solicitud_reservas_cengisalas
         (id_solicitud,request_code,space_id,space_name,configuration_id,configuration_name,hora_fin,created_by)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $save->execute([
        $requestId, $requestCode, $spaceId, $selectedSpace['name'], $configurationId,
        $selectedConfiguration['name'], $endTime, (string) ($_SESSION['usuario']['correo'] ?? ''),
    ]);

    room_respond(['success' => true, 'request_code' => $requestCode]);
} catch (Throwable $error) {
    room_respond(['success' => false, 'error' => $error->getMessage()], 422);
}
