<?php
declare(strict_types=1);
date_default_timezone_set('America/Guatemala');
ini_set('session.use_strict_mode', '1');
require_once dirname(__DIR__) . '/login/config/session.php';
cengi_session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
if (($_SESSION['tech_identity'] ?? null) !== ($_SESSION['id_usuario'] ?? null)) {
    unset($_SESSION['tech_csrf']);
}
$_SESSION['tech_identity'] = $_SESSION['id_usuario'] ?? null;
$_SESSION['tech_csrf'] ??= bin2hex(random_bytes(24));

function storage(): PDO {
    static $db;
    if ($db) return $db;
    $directory = getenv('TECH_STORAGE_DIR') ?: dirname(__DIR__, 2) . '/encuestas-private-' . substr(hash('sha256', __DIR__), 0, 10);
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('No se pudo preparar el almacenamiento.');
    $db = new PDO('sqlite:' . $directory . '/gestion.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS service_meta (id TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec("CREATE TABLE IF NOT EXISTS user_access (user_id INTEGER PRIMARY KEY, rol TEXT NOT NULL CHECK (rol IN ('admin','profesional')), area TEXT NOT NULL, CHECK (rol='admin' OR (area<>'' AND area<>'Todas')))");
    return $db;
}
function primary(): PDO {
    static $db;
    if ($db) return $db;
    if (getenv('TECH_DB_DSN')) {
        $db = new PDO(getenv('TECH_DB_DSN'), getenv('TECH_DB_USER') ?: null, getenv('TECH_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        require __DIR__ . '/Conexion.php';
        $db = $conn;
    }
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
}
function query(PDO $db, string $sql, array $params = []): PDOStatement {
    $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt;
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function uploads_directory(): string { return getenv('TECH_UPLOAD_DIR') ?: __DIR__ . '/uploads'; }
function fail(string $message, int $code = 422): void { http_response_code($code); throw new DomainException($message); }
function csrf(): void {
    if (!hash_equals($_SESSION['tech_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? ''))) fail('La sesión venció. Recarga la página.', 403);
}
function modules_db(): PDO {
    require_once dirname(__DIR__) . '/login/config/conexion.php';
    return Conexion::conectar();
}
function current_user(): ?array {
    $id = filter_var($_SESSION['id_usuario'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) return null;
    $user = query(modules_db(), 'SELECT id,nombre,correo,es_superadmin FROM usuarios WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    $superadmin = (int)$user['es_superadmin'] === 1;
    $access = $superadmin ? ['rol'=>'admin','area'=>'Todas'] : query(storage(), 'SELECT rol,area FROM user_access WHERE user_id=?', [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$access || !in_array($access['rol'], ['admin','profesional'], true) || ($access['rol'] === 'profesional' && (!$access['area'] || $access['area'] === 'Todas'))) fail('No tienes acceso a Servicios Técnicos. Solicita a un administrador que te asigne un perfil y área.', 403);
    return ['id'=>(int)$user['id'], 'nombre'=>$user['nombre'], 'email'=>$user['correo'], 'rol'=>$access['rol'], 'area'=>$access['rol'] === 'admin' ? 'Todas' : $access['area'], 'superadmin'=>$superadmin];
}
function require_user(): array { $u = current_user(); if (!$u) fail('Inicia sesión en Módulos para continuar.', 401); return $u; }
function require_page_user(): array {
    try { return require_user(); }
    catch (DomainException $e) {
        if (http_response_code() === 401) { header('Location: ../login/login.php', true, 302); exit; }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Acceso restringido</title><h1>Acceso restringido</h1><p>' . h($e->getMessage()) . '</p><a href="../login/Menu.php">Volver a Módulos</a></html>';
        exit;
    }
}
function module_users(): array {
    $access = storage()->query('SELECT user_id,rol,area FROM user_access')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $users = modules_db()->query('SELECT id,nombre,correo AS email,es_superadmin FROM usuarios ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$user) {
        $user['id'] = (int)$user['id'];
        $user['superadmin'] = (int)$user['es_superadmin'] === 1;
        $assignment = $access[$user['id']] ?? ['rol'=>'','area'=>''];
        $user['rol'] = $user['superadmin'] ? 'admin' : $assignment['rol'];
        $user['area'] = $user['rol'] === 'admin' ? 'Todas' : $assignment['area'];
        unset($user['es_superadmin']);
    }
    return $users;
}
function module_areas(): array {
    $areas = ['Riegos','Malezas y Madurantes','Recuperación de Sacarosa','Variedades','Estación Experimental','Capacitación y Transferencia de Tecnología'];
    $existing = primary()->query("SELECT DISTINCT Area FROM Encuestas WHERE Area IS NOT NULL AND TRIM(Area)<>'' AND Area<>'Todas'")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge($areas,$existing)));
}
function permissions(): array {
    $labels = ['Ver listado de servicios (de su área, salvo Administrador)', 'Ver detalle de un servicio', 'Registrar nuevo servicio', 'Generar / copiar enlace de encuesta', 'Llenar encuesta por llamada telefónica', 'Notificar al asesor y clasificar seguimiento', 'Ver Base de Contactos', 'Ver Dashboard y estadísticas', 'Exportar reportes (CSV / PDF)', 'Gestionar usuarios y permisos'];
    $labels[] = 'Editar servicios existentes';
    return array_map(fn($i) => ['accion'=>$labels[$i], 'admin'=>true, 'profesional'=>in_array($i,[0,1,2,6,7,8],true)], array_keys($labels));
}
function allowed(array $user, int $index): bool { return $user['rol'] === 'admin' || !empty(permissions()[$index][$user['rol']]); }
function permit(array $user, int $index): void { if (!allowed($user, $index)) fail('No tienes permiso para esta acción.', 403); }
function scope(array $user, array $row): void { if ($user['rol'] !== 'admin' && (!$user['area'] || $user['area'] === 'Todas' || $user['area'] !== $row['Area'])) fail('Este servicio pertenece a otra área.', 403); }
function meta(string $id): array {
    $raw = query(storage(), 'SELECT value FROM service_meta WHERE id=?', [$id])->fetchColumn();
    return $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
}
function save_meta(string $id, array $value): void { query(storage(), 'INSERT INTO service_meta(id,value) VALUES (?,?) ON CONFLICT(id) DO UPDATE SET value=excluded.value', [$id, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]); }
function track(array &$m, string $action, string $detail, string $who): void {
    $m['track'][] = ['fecha'=>date('d/m/Y'), 'hora'=>date('H:i'), 'rol'=>$who, 'accion'=>$action, 'detalle'=>$detail];
}
function service(string $id): array {
    $s = query(primary(), 'SELECT * FROM Encuestas WHERE ID_Servicio_Tecnico=?', [$id])->fetch();
    if (!$s) fail('Servicio no encontrado.', 404);
    return $s;
}
function yes($value): bool { return in_array(mb_strtolower(trim((string)$value)), ['si','sí','1'], true); }
function display_date($value): string { return $value && strtotime($value) ? date('d/m/Y', strtotime($value)) : '-'; }
function service_rows(array $u): array {
    $sql = 'SELECT * FROM Encuestas'; $params = [];
    if ($u['rol'] !== 'admin') { $sql .= ' WHERE Area=?'; $params[] = $u['area']; }
    $records = query(primary(), $sql . ' ORDER BY ID DESC', $params)->fetchAll();
    $responses = [];
    if ($records) {
        $ids = array_column($records, 'ID_Servicio_Tecnico');
        foreach (query(primary(), 'SELECT * FROM Respuestas WHERE ID_Servicio_Tecnico IN (' . implode(',', array_fill(0,count($ids),'?')) . ') ORDER BY ID_Respuesta ASC', $ids)->fetchAll() as $r) $responses[$r['ID_Servicio_Tecnico']] = $r;
    }
    $rows = [];
    foreach ($records as $s) {
        $id = (string)$s['ID_Servicio_Tecnico']; $r = $responses[$id] ?? []; $m = meta($id);
        $score = isset($r['Puntuacion']) && $r['Puntuacion'] >= 1 && $r['Puntuacion'] <= 5 ? (int)$r['Puntuacion'] : null;
        $status = $score !== null ? 'respondida' : (!empty($m['token']) || yes($s['Envio_Encuesta']) ? 'pendiente' : 'sin-generar');
        $row = ['id'=>$id, 'cliente'=>$s['Nombre_Cliente'] ?? '', 'institucion'=>$s['Institucion'] ?? '', 'area'=>$s['Area'] ?? '', 'asesor'=>$s['Asesor'] ?? '', 'tipo'=>$s['Tipo_Servicio'] ?? '', 'desc'=>$s['Descripcion_Actividad'] ?? '', 'estado'=>$s['Estado'] ?? 'Abierto', 'fecha'=>display_date($s['Fecha_Solicitud']), 'fechaISO'=>$s['Fecha_Solicitud'], 'fechaCierre'=>$s['Fecha_Cierre'] ?? '', 'lugar'=>$s['Lugar'] ?? '', 'celular'=>$s['Celular_Cliente'] ?? '', 'email'=>$s['Email_Cliente'] ?? '', 'emailProfesional'=>$s['Email_Profesional'] ?? '', 'medio'=>$s['Medio_Respuesta'] ?? '', 'participantes'=>(int)($s['Numero_Participantes'] ?? 0), 'encuesta'=>$status, 'puntuacion'=>$score, 'comentario'=>$r['Comentario'] ?? '', 'personaRespondio'=>$r['Persona_Respondio'] ?? '', 'cargoRespondente'=>$m['cargo'] ?? '', 'envioEncuesta'=>yes($s['Envio_Encuesta']) || $score !== null ? 'Si' : 'No', 'transferencia'=>yes($s['Transferencia']) ? 'Si' : 'No', 'fechaTransferencia'=>display_date($s['Fecha2']), 'evidencia'=>$s['Evidencia'] ?? '-', 'lugarTransferencia'=>$s['Lugar_transferencia'] ?? '-', 'descripcionTransferencia'=>$s['descripcion_transferencia'] ?? '-', 'track'=>$m['track'] ?? [], 'seguimiento'=>$m['seguimiento'] ?? ['estado'=>$score !== null && $score <= 3 ? 'pendiente':'no-requiere','notas'=>[],'notificado'=>false], 'token'=>allowed($u,3) ? ($m['token'] ?? '') : ''];
        $row['diasAbierto'] = $row['estado'] === 'Finalizado' ? null : ($s['Fecha_Solicitud'] ? max(0,(int)floor((strtotime(date('Y-m-d')) - strtotime($s['Fecha_Solicitud'])) / 86400)) : 0);
        $rows[] = $row;
    }
    return $rows;
}
function clean(array $input, string $key, int $max = 150, bool $required = false): string {
    $v = $input[$key] ?? ''; if (!is_scalar($v)) fail('Valor inválido: ' . $key);
    $v = trim((string)$v);
    if (($required && $v === '') || mb_strlen($v) > $max) fail('Revisa el campo ' . $key . '.');
    return $v;
}
function date_value(array $input, string $key): ?string {
    $v = clean($input,$key,10); if (!$v) return null;
    $d = DateTime::createFromFormat('!Y-m-d',$v); if (!$d || $d->format('Y-m-d') !== $v) fail('Fecha inválida: ' . $key); return $v;
}
function save_response(string $id, array $input, string $actor, bool $public = false): void {
    $name = clean($input,'nombre',$public ? 120 : 100,true); $comment = clean($input,'comentario',5000); $cargo = clean($input,'cargo',150);
    $score = filter_var($input['puntuacion'] ?? null, FILTER_VALIDATE_INT);
    if (!$score || $score < 1 || $score > 5) fail('Selecciona una calificación entre 1 y 5.');
    $db = primary(); $db->beginTransaction();
    try {
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if (!query($db,'SELECT ID FROM Encuestas WHERE ID_Servicio_Tecnico=?' . $lock,[$id])->fetch()) fail('Servicio no encontrado.',404);
        if (query($db,'SELECT COUNT(*) FROM Respuestas WHERE ID_Servicio_Tecnico=?',[$id])->fetchColumn()) fail('Esta encuesta ya fue respondida.',409);
        query($db,'INSERT INTO Respuestas (ID_Servicio_Tecnico,Puntuacion,Comentario,Persona_Respondio,Fecha_Respuesta) VALUES (?,?,?,?,?)',[$id,$score,$comment,$public ? $name : 'Contactado por Admin (' . $name . ')',date('Y-m-d')]);
        query($db,"UPDATE Encuestas SET Estado='Finalizado', Envio_Encuesta='Si', Fecha_Cierre=COALESCE(Fecha_Cierre,?) WHERE ID_Servicio_Tecnico=?",[date('Y-m-d'),$id]);
        $m = meta($id); $m['cargo'] = $cargo; $m['seguimiento'] = ['estado'=>$score <= 3 ? 'pendiente':'no-requiere','notas'=>[],'notificado'=>false];
        track($m,'Encuesta respondida', 'Calificación ' . $score . '/5. Respondió: ' . $name, $actor); save_meta($id,$m);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
