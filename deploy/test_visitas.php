<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/login/config/conexion.php';
$db = Conexion::conectar();
$base = 'http://127.0.0.1';
$ids = [];
$sessions = [];
$createdEmails = [];
require_once dirname(__DIR__) . '/login/config/cengisalas_supabase.php';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function request(string $url, string $cookie = '', ?array $post = null): array {
    global $base;
    $ch = curl_init($base . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 55, CURLOPT_COOKIE => $cookie]);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException(curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($body, 0, $headerSize), 'body' => substr($body, $headerSize)];
}
function sessionCookie(int $id, bool $visit = false): string {
    global $sessions;
    $sid = bin2hex(random_bytes(24));
    session_name($visit ? 'CENGISALAS_VISIT' : 'PHPSESSID');
    session_id($sid); session_start();
    $_SESSION = $visit ? ['portal_user_id' => $id, 'id_usuario' => '00000000-0000-4000-8000-000000000001', 'cengisalas_authenticated' => true, 'cengisalas_last_activity' => time()] : ['id_usuario' => $id];
    session_write_close();
    $sessions[] = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid;
    return ($visit ? 'CENGISALAS_VISIT=' : 'PHPSESSID=') . $sid;
}
try {
    $adminId = (int) $db->query('SELECT id FROM usuarios WHERE es_superadmin=1 ORDER BY id LIMIT 1')->fetchColumn();
    check($adminId > 0, 'Falta un administrador de prueba.');
    $adminCookie = sessionCookie($adminId);
    $menu = request('/login/Menu.php', $adminCookie);
    check($menu['status'] === 200 && substr_count($menu['body'], 'href="sso_visitas.php"') === 2, 'El menú debe tener un solo acceso a Visitas.');
    check(!str_contains($menu['body'], 'Visitas CengiSalas') && !str_contains($menu['body'], 'Solicitud de visitas'), 'Quedan nombres duplicados en el menú.');
    $panel = request('/login/visitas_roles.php', $adminCookie);
    check($panel['status'] === 200 && str_contains($panel['body'], 'Usuarios del portal'), 'Panel de roles no disponible.');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $panel['body'], $csrf);
    check(!empty($csrf[1]), 'Falta CSRF.');
    $portalRole = (int) $db->query('SELECT id FROM roles ORDER BY id LIMIT 1')->fetchColumn();
    $module = (int) $db->query("SELECT id FROM modulos WHERE nombre='Visitas'")->fetchColumn();
    $db->prepare('INSERT INTO usuarios(nombre,correo,contrasena,rol_id,es_superadmin) VALUES (?,?,?,?,0)')->execute(['Prueba temporal Visitas', 'visitas-test-' . bin2hex(random_bytes(6)) . '@example.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $portalRole]);
    $target = (int) $db->lastInsertId(); $ids[] = $target;
    $db->prepare("INSERT INTO cengisalas_module_access(usuario_id,module_code,role_code) VALUES (?,'salas','user')")->execute([$target]);
    $cookie = sessionCookie($target);
    $visitCookie = $cookie . '; ' . sessionCookie($target, true);
    check(request('/login/visitas_roles.php', $cookie)['status'] === 403, 'Cuenta sin acceso pudo abrir roles.');
    check(request('/visitas/public/admin/dashboard_unificado.php', $visitCookie)['status'] === 403, 'Cuenta sin acceso pudo abrir visitas.');
    check(request('/login/visitas_roles.php', $adminCookie, ['user_id' => $target, 'role' => 'coordinador_visitas', 'csrf' => 'invalid'])['status'] === 403, 'CSRF inválido aceptado.');
    check(request('/login/visitas_roles.php', $adminCookie, ['user_id' => $adminId, 'role' => 'asistente_direccion', 'csrf' => $csrf[1]])['status'] === 422, 'Se permitió editar el acceso propio.');
    foreach (['asistente_direccion','director_general','coordinador_visitas','gestor_pagos'] as $role) {
        $response = request('/login/visitas_roles.php', $adminCookie, ['user_id' => $target, 'role' => $role, 'csrf' => $csrf[1]]);
        check($response['status'] === 303, 'No se guardó el rol ' . $role);
        $q = $db->prepare("SELECT role_code FROM cengisalas_module_access WHERE usuario_id=? AND module_code='visitas'"); $q->execute([$target]);
        check($q->fetchColumn() === $role, 'Rol incorrecto en la base.');
        $q = $db->prepare('SELECT rol_id FROM usuarios WHERE id=?'); $q->execute([$target]);
        check((int) $q->fetchColumn() === $portalRole, 'Se alteró el rol global.');
        $q = $db->prepare("SELECT role_code FROM cengisalas_module_access WHERE usuario_id=? AND module_code='salas'"); $q->execute([$target]);
        check($q->fetchColumn() === 'user', 'Se alteró el rol de CengiSalas.');
        if ($role === 'gestor_pagos') check(request('/visitas/public/admin/modulos/actualizar_estado_solicitud.php', $visitCookie, ['id_solicitud' => 0, 'id_estado' => 2])['status'] === 403, 'Ruta antigua permitió modificar solicitudes.');
        $rolesPage = request('/login/visitas_roles.php', $cookie);
        check($rolesPage['status'] === ($role !== 'gestor_pagos' ? 200 : 403), 'Permiso de administración incorrecto para ' . $role);
        $route = $role === 'gestor_pagos' ? 'dashboard_pago.php' : 'dashboard_unificado.php?modulo=solicitudes';
        $dashboard = request('/visitas/public/admin/' . $route, $visitCookie);
        check($dashboard['status'] === 200 && !preg_match('/Fatal error|Warning:|Parse error/', $dashboard['body']), 'Falla dashboard de ' . $role);
    }
    check(request('/login/usuarios/crear_usuario.php?scope=visitas', $cookie, ['nombre'=>'No crear'])['status'] === 303, 'El alta heredada no redirige al panel de Visitas.');
    check(request('/login/usuarios/editar_usuario.php?scope=visitas&id=' . $target, $cookie)['status'] === 303, 'La edición heredada no redirige al panel de Visitas.');
    check(request('/visitas/public/admin/dashboard_reportes.php', $visitCookie)['status'] === 200, 'Gestor no puede consultar reportes.');
    check(request('/visitas/public/admin/dashboard_reportes.php?export=csv', $visitCookie)['status'] === 200, 'Gestor no puede exportar reportes.');
    check(request('/visitas/public/admin/dashboard_unificado.php', $visitCookie)['status'] === 403, 'Gestor puede abrir solicitudes.');
    $paymentCheck = request('/visitas/public/admin/actualizar_pago.php', $visitCookie, ['id_solicitud'=>0,'id_estado_pago'=>2]); check($paymentCheck['status'] === 403, 'Pago sin CSRF: ' . $paymentCheck['status'] . ' ' . substr($paymentCheck['body'],0,500));
    check(request('/login/visitas_roles.php', $adminCookie, ['user_id' => $target, 'role' => '', 'csrf' => $csrf[1]])['status'] === 303, 'Fallo al revocar.');
    check(request('/visitas/public/admin/dashboard_unificado.php', $visitCookie)['status'] === 403, 'La sesión abierta conserva acceso revocado.');
    check(request('/visitas/public/solicitud_visita.php')['status'] === 200, 'Formulario público no disponible.');
    $legacy = request('/cengisalas/modules/visitas/public/registro_visitantes.php?token=prueba');
    check($legacy['status'] === 308 && str_contains($legacy['headers'], '/visitas/public/registro_visitantes.php?token=prueba'), 'Enlace antiguo perdió su destino o token.');
    check(request('/visitas/.env')['status'] === 403, 'Configuración privada expuesta.');
    $sso = request('/login/sso_visitas.php', $adminCookie);
    check($sso['status'] === 303 && str_contains($sso['headers'], '../visitas/public/admin/'), 'Falló el ingreso real desde el portal. HTTP ' . $sso['status']);
    preg_match_all('/Set-Cookie: CENGISALAS_VISIT=([^;]+)/i', $sso['headers'], $visitCookies);
    $visitSession = [1 => end($visitCookies[1])];
    check(!empty($visitSession[1]), 'No se emitió la sesión de Visitas.');
    $sessions[] = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $visitSession[1];
    $dashboard = request('/visitas/public/admin/dashboard_unificado.php?modulo=solicitudes', $adminCookie . '; CENGISALAS_VISIT=' . $visitSession[1]);
    check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Administrar roles de Visitas'), 'Ingreso real no llegó al panel. HTTP ' . $dashboard['status']);
    $pg = cengisalas_pg();
    $org = (int) $pg->query('SELECT id FROM public.organizations WHERE is_active ORDER BY id LIMIT 1')->fetchColumn();
    $email = 'visitas-create-' . bin2hex(random_bytes(8)) . '@example.com'; $createdEmails[] = $email;
    $password = bin2hex(random_bytes(12));
    $newUser = ['action'=>'create','csrf'=>$csrf[1],'name'=>'Prueba temporal de Visitas','email'=>$email,'password'=>$password,'password_confirmation'=>$password,'organization_id'=>$org,'department_id'=>'','role'=>'gestor_pagos'];
    $invalid = $newUser; $invalid['password_confirmation'] = 'different';
    check(request('/login/visitas_roles.php', $adminCookie, $invalid)['status'] === 422, 'Se aceptaron contraseñas distintas.');
    $invalid = $newUser; $invalid['role'] = 'admin';
    check(request('/login/visitas_roles.php', $adminCookie, $invalid)['status'] === 422, 'Se aceptó un rol ajeno al catálogo.');
    $created = request('/login/visitas_roles.php', $adminCookie, $newUser);
    check($created['status'] === 303 && str_contains($created['headers'],'created=1'), 'No se creó el usuario: HTTP ' . $created['status']);
    $q = $db->prepare('SELECT id,contrasena,rol_id,es_superadmin FROM usuarios WHERE correo=?'); $q->execute([$email]); $account = $q->fetch(PDO::FETCH_ASSOC);
    check($account && password_verify($password,$account['contrasena']) && !$account['es_superadmin'], 'Cuenta central incorrecta.');
    $q = $db->prepare('SELECT modulo_id FROM usuario_modulo WHERE usuario_id=?'); $q->execute([$account['id']]);
    check($q->fetchAll(PDO::FETCH_COLUMN) == [$module], 'La cuenta recibió módulos adicionales.');
    $q = $db->prepare('SELECT COUNT(*) FROM rol_permiso WHERE rol_id=?'); $q->execute([$account['rol_id']]); check((int)$q->fetchColumn() === 0,'La cuenta recibió permisos globales.');
    $q = $pg->prepare('SELECT id FROM public.profiles WHERE email=? AND portal_user_id=?'); $q->execute([$email,$account['id']]); $profileId = $q->fetchColumn(); check((bool)$profileId,'Falta perfil vinculado.');
    $q = $pg->prepare('SELECT p.code FROM public.profile_module_permissions x JOIN public.app_module_permissions p ON p.id=x.permission_id WHERE x.profile_id=? AND x.allowed ORDER BY p.code'); $q->execute([$profileId]);
    check($q->fetchAll(PDO::FETCH_COLUMN) === ['gestionar_pagos','ver_pagos','ver_reportes'], 'El perfil tiene permisos incorrectos.');
    check(request('/login/visitas_roles.php',$adminCookie,$newUser)['status'] === 409,'Correo duplicado aceptado.');
    $login = request('/login/login.php','',['correo'=>$email,'password'=>$password]);
    check(in_array($login['status'],[302,303],true) && str_contains($login['headers'],'Menu.php'),'La cuenta no inicia sesión en Módulos.');
    preg_match_all('/Set-Cookie: PHPSESSID=([^;]+)/i',$login['headers'],$loginCookies); $newCookie = 'PHPSESSID=' . end($loginCookies[1]);
    check(!empty($loginCookies[1]),'No se recibió sesión central.');
    foreach($loginCookies[1] as $sid) $sessions[] = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid;
    $sso = request('/login/sso_visitas.php',$newCookie);
    check($sso['status'] === 303 && str_contains($sso['headers'],'dashboard_pago.php'),'SSO de Gestor no abre Pagos.');
    preg_match_all('/Set-Cookie: CENGISALAS_VISIT=([^;]+)/i',$sso['headers'],$freshCookies);
    foreach($freshCookies[1] as $sid) $sessions[] = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid;
    $newVisitCookie = $newCookie . '; CENGISALAS_VISIT=' . end($freshCookies[1]);
    $reports = request('/visitas/public/admin/dashboard_reportes.php',$newVisitCookie);
    check($reports['status'] === 200 && !preg_match('/Fatal error|Warning:|Parse error/',$reports['body']),'Fallan reportes con sesión real.');
    check(!str_contains($reports['body'],'navContactos') && !str_contains($reports['body'],'pago_token') && !str_contains($reports['body'],'Roles y permisos'),'Reportes expone otras secciones.');
    check(request('/login/visitas_roles.php',$newCookie,$newUser)['status'] === 403,'Gestor pudo crear usuarios.');
    echo "OK: $checks comprobaciones de menú, acceso, roles, revocación, formularios y SSO.
";
} finally {
    foreach ($createdEmails as $email) {
        $pg = cengisalas_pg(); $q = $pg->prepare('SELECT id FROM public.profiles WHERE email=?'); $q->execute([$email]);
        if ($testProfile = $q->fetchColumn()) cengisalas_admin_request('DELETE','/auth/v1/admin/users/' . rawurlencode($testProfile));
        $q = $db->prepare('SELECT id FROM usuarios WHERE correo=?'); $q->execute([$email]); if ($testId = $q->fetchColumn()) $ids[] = (int)$testId;
    }
    foreach ($ids as $id) {
        $db->prepare('DELETE FROM usuario_modulo WHERE usuario_id=?')->execute([$id]);
        $db->prepare('DELETE FROM cengisalas_module_access WHERE usuario_id=?')->execute([$id]);
        $db->prepare('DELETE FROM usuarios WHERE id=?')->execute([$id]);
    }
    foreach ($sessions as $file) if (is_file($file)) unlink($file);
}
