<?php
declare(strict_types=1);

// Usuarios y programas: asigna rol, programa y acceso al modulo. El rol es el
// del usuario EN COMITES (usuario_modulo.rol_id, ver login/config/roles_modulo.php):
// no cambia su rol en otros modulos. Los programas son los mismos de Solicitudes
// internas, para no mantener dos listas.

require_once __DIR__ . '/../../login/config/roles_modulo.php';

/** Expresion SQL del rol del usuario en Comites (superadmin: su rol de plataforma). */
function usuarios_sql_rol_modulo(): string
{
    return 'CASE WHEN u.es_superadmin = 1 THEN u.rol_id ELSE umr.rol_id END';
}

/** Roles que se pueden asignar desde Comites; el resto se administra en la plataforma. */
function usuarios_roles_asignables(): array
{
    return ['Director', 'Jefe de programa', 'Colaborador de programa'];
}

/** Crea las tablas de programas en la base de Solicitudes internas si ese modulo aun no las creo. */
function usuarios_preparar_programas(PDO $menuPdo): void
{
    $db = comites_programas_db();
    $menuPdo->exec("CREATE TABLE IF NOT EXISTS {$db}.programas (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120) NOT NULL UNIQUE, activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $menuPdo->exec("CREATE TABLE IF NOT EXISTS {$db}.usuario_programa (usuario_id INT NOT NULL PRIMARY KEY, programa_id INT NOT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_usuario_programa_programa (programa_id)) ENGINE=InnoDB");
}

function usuarios_roles(PDO $menuPdo): array
{
    $roles = $menuPdo->query('SELECT id, nombre_rol FROM roles ORDER BY nombre_rol')->fetchAll();
    foreach ($roles as &$r) {
        $r['id'] = (int) $r['id'];
        $r['asignable'] = in_array($r['nombre_rol'], usuarios_roles_asignables(), true);
    }
    return $roles;
}

function usuarios_programas(PDO $menuPdo, bool $soloActivos = false): array
{
    $db = comites_programas_db();
    return $menuPdo->query(
        "SELECT p.id, p.nombre, p.activo, (SELECT COUNT(*) FROM {$db}.usuario_programa up WHERE up.programa_id = p.id) AS usuarios
         FROM {$db}.programas p" . ($soloActivos ? ' WHERE p.activo = 1' : '') . ' ORDER BY p.nombre'
    )->fetchAll();
}

/** Usuarios de la plataforma con su rol, programa, acceso al modulo y perfil efectivo en Comites. */
function usuarios_listado(PDO $menuPdo, string $busqueda, string $filtro, int $rolId): array
{
    $db = comites_programas_db();
    $modulos = module_ids($menuPdo) ?: [0];
    $marcadores = implode(',', array_fill(0, count($modulos), '?'));
    $rolModulo = usuarios_sql_rol_modulo();
    $sql = "SELECT u.id, u.nombre, u.correo, {$rolModulo} AS rol_id, u.es_superadmin, COALESCE(r.nombre_rol, '') AS nombre_rol, p.id AS programa_id, p.nombre AS programa,
                EXISTS (SELECT 1 FROM usuario_modulo um WHERE um.usuario_id = u.id AND um.modulo_id IN ($marcadores)) AS acceso
            FROM usuarios u
            LEFT JOIN usuario_modulo umr ON umr.usuario_id = u.id AND umr.modulo_id = ?
            LEFT JOIN roles r ON r.id = {$rolModulo}
            LEFT JOIN {$db}.usuario_programa up ON up.usuario_id = u.id
            LEFT JOIN {$db}.programas p ON p.id = up.programa_id
            WHERE 1 = 1";
    $params = array_merge($modulos, [$modulos[0]]);
    if ($busqueda !== '') {
        $sql .= ' AND (u.nombre LIKE ? OR u.correo LIKE ? OR p.nombre LIKE ?)';
        $like = '%' . $busqueda . '%';
        array_push($params, $like, $like, $like);
    }
    if ($rolId > 0) {
        $sql .= " AND {$rolModulo} = ?";
        $params[] = $rolId;
    }
    if ($filtro === 'con_acceso') {
        $sql .= " AND (u.es_superadmin = 1 OR EXISTS (SELECT 1 FROM usuario_modulo um2 WHERE um2.usuario_id = u.id AND um2.modulo_id IN ($marcadores)))";
        array_push($params, ...$modulos);
    } elseif ($filtro === 'sin_programa') {
        $sql .= ' AND p.id IS NULL AND u.es_superadmin = 0';
    }
    $stmt = $menuPdo->prepare($sql . ' ORDER BY u.nombre');
    $stmt->execute($params);
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['perfil'] = (int) $f['es_superadmin'] === 1 ? 'Superadministrador' : (comites_permisos_por_programa((string) $f['nombre_rol'], $f['programa'])['perfil'] ?? null);
    }
    return $filas;
}

/** Guarda rol, programa y acceso al modulo de un usuario. */
function usuarios_guardar(PDO $menuPdo, array $actor, array $post): string
{
    $id = (int) ($post['usuario_id'] ?? 0);
    $stmt = $menuPdo->prepare('SELECT u.id, u.nombre, u.es_superadmin FROM usuarios u WHERE u.id = ?');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        throw new RuntimeException('El usuario no existe.');
    }
    // Rol actual EN COMITES (no su rol en Cursos, Laboratorio, etc.).
    $actual = cengi_rm_rol_en_modulo($menuPdo, $id, current_module_names()[0]);
    $usuario['rol_id'] = $actual['rol_id'] ?? 0;
    $rolId = (int) ($post['rol_id'] ?? $usuario['rol_id']);
    $programaId = (int) ($post['programa_id'] ?? 0);
    $acceso = !empty($post['acceso']);
    $esSuper = (int) $usuario['es_superadmin'] === 1;

    $nuevoRol = $menuPdo->prepare('SELECT nombre_rol FROM roles WHERE id = ?');
    $nuevoRol->execute([$rolId]);
    $nombreRol = (string) $nuevoRol->fetchColumn();
    if ($nombreRol === '') {
        throw new RuntimeException('Rol no válido.');
    }
    if ($rolId !== (int) $usuario['rol_id']) {
        if ($esSuper) {
            throw new RuntimeException('El rol de un superadministrador solo se cambia desde la gestión de usuarios de la plataforma.');
        }
        if ($id === (int) $actor['id']) {
            throw new RuntimeException('No puedes cambiar tu propio rol.');
        }
        if (!in_array($nombreRol, usuarios_roles_asignables(), true)) {
            throw new RuntimeException('Desde Comités solo se asignan los roles ' . implode(', ', usuarios_roles_asignables()) . '. Los demás se asignan en la plataforma.');
        }
    }
    if (normalize_text($nombreRol) === 'jefe de programa' && $programaId <= 0) {
        throw new RuntimeException('Un Jefe de programa necesita un programa asignado.');
    }
    $db = comites_programas_db();
    if ($programaId > 0) {
        $existe = $menuPdo->prepare("SELECT COUNT(*) FROM {$db}.programas WHERE id = ? AND activo = 1");
        $existe->execute([$programaId]);
        if (!(int) $existe->fetchColumn()) {
            throw new RuntimeException('El programa no existe o está inactivo.');
        }
    }

    $menuPdo->beginTransaction();
    try {
        if ($programaId > 0) {
            $menuPdo->prepare("INSERT INTO {$db}.usuario_programa (usuario_id, programa_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE programa_id = VALUES(programa_id)")->execute([$id, $programaId]);
        } else {
            $menuPdo->prepare("DELETE FROM {$db}.usuario_programa WHERE usuario_id = ?")->execute([$id]);
        }
        if (!$esSuper) {
            foreach (module_ids($menuPdo) as $modulo) {
                if ($acceso) {
                    // El rol se guarda solo para Comites; los demas modulos no cambian.
                    cengi_rm_asignar($menuPdo, $id, $modulo, $rolId);
                } else {
                    $menuPdo->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?')->execute([$id, $modulo]);
                }
            }
        }
        $menuPdo->commit();
    } catch (Throwable $e) {
        $menuPdo->rollBack();
        throw $e;
    }
    return 'Usuario ' . $usuario['nombre'] . ' actualizado.';
}

/**
 * Lo que un usuario puede hacer en Comites: permisos de su rol mas los que se
 * derivan de su programa (superadmin: todo). Devuelve por seccion el nivel
 * (gestionar / ver / sin acceso) y las aprobaciones especiales.
 */
function usuarios_permisos_efectivos(PDO $menuPdo, array $usuario): array
{
    $esSuper = (int) $usuario['es_superadmin'] === 1;
    $stmt = $menuPdo->prepare("SELECT p.nombre_permiso FROM rol_permiso rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ? AND p.nombre_permiso LIKE 'comites.%'");
    $stmt->execute([(int) $usuario['rol_id']]);
    $derivados = comites_permisos_por_programa((string) $usuario['nombre_rol'], $usuario['programa'] ?? null);
    $permisos = array_unique(array_merge($stmt->fetchAll(PDO::FETCH_COLUMN), $derivados['permisos']));
    $tiene = static fn(string $p): bool => $esSuper || in_array($p, $permisos, true);
    $secciones = [
        'reuniones' => 'Reuniones', 'eventos' => 'Eventos de transferencia', 'acuerdos' => 'Seguimiento a próximos pasos',
        'contactos' => 'Directorio de contactos', 'documentos' => 'Biblioteca para ingenios', 'biblioteca_interna' => 'Biblioteca interna',
        'correos' => 'Correos y envíos', 'tableros' => 'Tableros', 'informes' => 'Informes cuatrimestrales', 'memorias' => 'Memoria de resultados',
        'publicaciones' => 'Redes sociales', 'estadisticas' => 'Estadísticas', 'configuracion' => 'Comités y configuración', 'usuarios' => 'Usuarios y programas',
    ];
    $niveles = [];
    foreach ($secciones as $clave => $nombre) {
        $niveles[$clave] = ['nombre' => $nombre, 'nivel' => $tiene("comites.$clave.gestionar") ? 'gestionar' : ($tiene("comites.$clave.ver") ? 'ver' : 'no')];
    }
    $especiales = [
        'comites.documentos.revisar_transferencia' => 'Revisa documentos para ingenios (Transferencia)',
        'comites.documentos.aprobar_direccion' => 'Aprueba documentos para ingenios (Dirección)',
        'comites.informes.revisar' => 'Revisa informes cuatrimestrales (Dirección)',
        'comites.memorias.entregar' => 'Entrega artículos a la memoria',
        'comites.memorias.aprobar_transferencia' => 'Aprueba la memoria (Transferencia)',
        'comites.memorias.aprobar_direccion' => 'Aprueba la memoria (Dirección)',
        'comites.memorias.diagramar' => 'Diagrama la memoria',
    ];
    return [
        'secciones' => $niveles,
        'especiales' => array_values(array_filter($especiales, $tiene, ARRAY_FILTER_USE_KEY)),
        'por_programa' => $derivados['perfil'],
    ];
}

/** Resumen de como quedo un usuario en Comites, para confirmar despues de guardar. */
function usuarios_confirmacion(PDO $menuPdo, int $id): ?array
{
    foreach (usuarios_listado($menuPdo, '', 'todos', 0) as $u) {
        if ((int) $u['id'] !== $id) {
            continue;
        }
        $esSuper = (int) $u['es_superadmin'] === 1;
        $acceso = $esSuper ? 'acceso total (superadministrador)' : ((int) $u['acceso'] ? 'con acceso al módulo' : 'SIN acceso al módulo (no podrá entrar a Comités)');
        return [
            'correo' => (string) $u['correo'],
            'texto' => 'Así quedó ' . $u['nombre'] . ': ' . $u['nombre_rol'] . ($u['perfil'] && !$esSuper ? ' (' . $u['perfil'] . ')' : '') . ' · ' . ($u['programa'] ?: 'sin programa') . ' · ' . $acceso . '.',
        ];
    }
    return null;
}

/** Busca una cuenta por correo (sin distinguir mayusculas ni espacios). */
function usuarios_buscar_correo(PDO $menuPdo, string $correo): ?array
{
    $db = comites_programas_db();
    $stmt = $menuPdo->prepare(
        // Rol que la cuenta tiene en Comites (no el de otro modulo).
        "SELECT u.id, u.nombre, u.correo, u.es_superadmin, COALESCE(r.nombre_rol, '') AS nombre_rol, p.nombre AS programa
         FROM usuarios u
         LEFT JOIN usuario_modulo umr ON umr.usuario_id = u.id AND umr.modulo_id = ?
         LEFT JOIN roles r ON r.id = " . usuarios_sql_rol_modulo() . "
         LEFT JOIN {$db}.usuario_programa up ON up.usuario_id = u.id
         LEFT JOIN {$db}.programas p ON p.id = up.programa_id
         WHERE LOWER(TRIM(u.correo)) = ? LIMIT 1"
    );
    $stmt->execute([(int) cengi_rm_modulo_id($menuPdo, 'comites'), mb_strtolower(trim($correo))]);
    return $stmt->fetch() ?: null;
}

/**
 * Agrega un usuario desde Comites. Si el correo ya tiene cuenta, solo se le
 * asignan rol, programa y acceso (no cambia su nombre ni su contrasena). Si
 * no existe, se crea con una contrasena aleatoria inutilizable y se le envia
 * un enlace para crear la suya.
 */
function usuarios_agregar(PDO $menuPdo, array $actor, array $post): string
{
    $correo = mb_strtolower(trim((string) ($post['correo'] ?? '')));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 150) {
        throw new RuntimeException('Escribe un correo válido.');
    }
    $existente = usuarios_buscar_correo($menuPdo, $correo);
    if ($existente) {
        usuarios_guardar($menuPdo, $actor, $post + ['usuario_id' => $existente['id']]);
        return 'El correo ya tenía cuenta (' . $existente['nombre'] . '): solo se le asignaron el rol, el programa y el acceso.';
    }

    $nombre = trim(preg_replace('/\s+/', ' ', (string) ($post['nombre'] ?? '')));
    if ($nombre === '' || mb_strlen($nombre) > 150) {
        throw new RuntimeException('Escribe el nombre completo de la persona.');
    }
    $rol = $menuPdo->prepare('SELECT nombre_rol FROM roles WHERE id = ?');
    $rol->execute([(int) ($post['rol_id'] ?? 0)]);
    if (!in_array($rol->fetchColumn(), usuarios_roles_asignables(), true)) {
        throw new RuntimeException('Elige uno de los roles: ' . implode(', ', usuarios_roles_asignables()) . '.');
    }

    try {
        $menuPdo->prepare('INSERT INTO usuarios (nombre, correo, contrasena, rol_id, es_superadmin) VALUES (?, ?, ?, ?, 0)')
            ->execute([$nombre, $correo, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), (int) $post['rol_id']]);
    } catch (PDOException $e) {
        // Otra persona creo la cuenta al mismo tiempo: se trata como existente.
        if ($e->getCode() === '23000') {
            return usuarios_agregar($menuPdo, $actor, $post);
        }
        throw $e;
    }
    $id = (int) $menuPdo->lastInsertId();
    try {
        usuarios_guardar($menuPdo, $actor, $post + ['usuario_id' => $id]);
    } catch (RuntimeException $e) {
        $menuPdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        throw $e;
    }

    if (empty($post['enviar_bienvenida'])) {
        return "Cuenta de $nombre creada. Para entrar debe usar \"¿Olvidaste tu contraseña?\" en el inicio de sesión.";
    }
    $enlace = usuarios_enlace_contrasena($menuPdo, $id);
    try {
        usuarios_enviar_bienvenida($correo, $nombre, $enlace, $actor);
        return "Cuenta de $nombre creada. Se le envió un correo para crear su contraseña (el enlace vence en 72 horas).";
    } catch (RuntimeException $e) {
        return "Cuenta de $nombre creada, pero el correo no se pudo enviar ({$e->getMessage()}). Compártele este enlace para crear su contraseña (vence en 72 horas): $enlace";
    }
}

/** Enlace de un solo uso a login/reset_password.php para que la persona cree su contrasena. */
function usuarios_enlace_contrasena(PDO $menuPdo, int $usuarioId): string
{
    $token = bin2hex(random_bytes(32));
    $menuPdo->prepare('DELETE FROM password_resets WHERE usuario_id = ?')->execute([$usuarioId]);
    $menuPdo->prepare('INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip_solicitud) VALUES (?, ?, ?, ?)')
        ->execute([$usuarioId, hash('sha256', $token), date('Y-m-d H:i:s', time() + 72 * 3600), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'comites')]);
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/comites/index.php'))), '/');
    return $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/login/reset_password.php?token=' . $token;
}

function usuarios_enviar_bienvenida(string $correo, string $nombre, string $enlace, array $actor): void
{
    foreach (['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'] as $clave) {
        if (in_array(app_env_value($clave), ['', 'change-me'], true)) {
            throw new RuntimeException('SMTP sin configurar');
        }
    }
    require_once dirname(__DIR__, 2) . '/cengicursos/vendor/autoload.php';
    $n = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');
    $quien = htmlspecialchars((string) ($actor['nombre'] ?? 'El equipo de Transferencia de Tecnología'), ENT_QUOTES, 'UTF-8');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = app_env_value('MAIL_HOST');
    $mail->Port = (int) app_env_value('MAIL_PORT', '2525');
    $mail->SMTPAuth = true;
    $mail->Username = app_env_value('MAIL_USERNAME');
    $mail->Password = app_env_value('MAIL_PASSWORD');
    $mail->SMTPSecure = $mail->Port === 465 ? 'ssl' : 'tls';
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 20;
    $mail->setFrom(app_env_value('MAIL_FROM_ADDRESS'), app_env_value('MAIL_FROM_NAME', 'CENGICAÑA'));
    $mail->addAddress($correo, $nombre);
    $mail->isHTML(true);
    $mail->Subject = 'Tu acceso a la plataforma CENGICAÑA';
    $mail->Body = "<div style=\"font-family:Arial,sans-serif;max-width:480px;margin:0 auto;color:#2a2e31\">
        <h2 style=\"color:#73BC25\">CENGICAÑA</h2>
        <p>Hola $n,</p>
        <p>$quien te dio acceso al módulo <b>Comités, Transferencia y Comunicación</b>. Para entrar, crea tu contraseña:</p>
        <p style=\"text-align:center;margin:24px 0\"><a href=\"$l\" style=\"background:#73BC25;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold\">Crear mi contraseña</a></p>
        <p>O copia este enlace en tu navegador:<br><a href=\"$l\">$l</a></p>
        <p>El enlace vence en 72 horas y solo se puede usar una vez. Si vence, usa «¿Olvidaste tu contraseña?» en la pantalla de inicio de sesión con este mismo correo.</p></div>";
    $mail->AltBody = "Hola $nombre, crea tu contraseña para la plataforma CENGICAÑA en: $enlace (vence en 72 horas).";
    try {
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Comites usuarios SMTP: ' . $e->getMessage());
        throw new RuntimeException('el servidor de correo no confirmó el envío');
    }
}

function usuarios_programa_guardar(PDO $menuPdo, array $post): string
{
    $db = comites_programas_db();
    $id = (int) ($post['programa_id'] ?? 0);
    $nombre = trim(preg_replace('/\s+/', ' ', (string) ($post['nombre'] ?? '')));
    if ($nombre === '' || mb_strlen($nombre) > 120) {
        throw new RuntimeException('Escribe el nombre del programa (máximo 120 caracteres).');
    }
    $repetido = $menuPdo->prepare("SELECT id FROM {$db}.programas WHERE nombre = ? AND id <> ?");
    $repetido->execute([$nombre, $id]);
    if ($repetido->fetchColumn()) {
        throw new RuntimeException('Ya existe un programa con ese nombre.');
    }
    if ($id > 0) {
        $menuPdo->prepare("UPDATE {$db}.programas SET nombre = ? WHERE id = ?")->execute([$nombre, $id]);
        return 'Programa actualizado.';
    }
    $menuPdo->prepare("INSERT INTO {$db}.programas (nombre) VALUES (?)")->execute([$nombre]);
    return 'Programa "' . $nombre . '" creado.';
}

function usuarios_programa_estado(PDO $menuPdo, int $id): string
{
    $db = comites_programas_db();
    $stmt = $menuPdo->prepare("SELECT nombre, activo, (SELECT COUNT(*) FROM {$db}.usuario_programa WHERE programa_id = p.id) AS usuarios FROM {$db}.programas p WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) {
        throw new RuntimeException('El programa no existe.');
    }
    if ((int) $p['activo'] === 1 && (int) $p['usuarios'] > 0) {
        throw new RuntimeException('El programa tiene ' . $p['usuarios'] . ' usuario(s). Reasígnalos antes de desactivarlo.');
    }
    $menuPdo->prepare("UPDATE {$db}.programas SET activo = 1 - activo WHERE id = ?")->execute([$id]);
    return 'Programa "' . $p['nombre'] . '" ' . ((int) $p['activo'] === 1 ? 'desactivado.' : 'activado.');
}
