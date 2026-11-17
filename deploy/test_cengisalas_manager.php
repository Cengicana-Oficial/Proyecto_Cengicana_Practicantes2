<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__) . '/login/config/cengisalas_supabase.php';

$expected = ['ver_dashboard', 'ver_reportes', 'ver_solicitudes', 'ver_solicitudes_pago'];
$pg = cengisalas_pg();
$rolePermissions = $pg->query("SELECT p.code FROM public.app_module_role_permissions rp JOIN public.app_module_roles r ON r.id=rp.role_id JOIN public.app_modules m ON m.id=r.module_id JOIN public.app_module_permissions p ON p.id=rp.permission_id WHERE m.code='salas' AND r.code='manager' ORDER BY p.code")->fetchAll(PDO::FETCH_COLUMN);
if ($rolePermissions !== $expected) {
    throw new RuntimeException('El rol Gestor no tiene exactamente los cuatro permisos esperados.');
}
$profileId = $pg->query("SELECT id FROM public.profiles WHERE portal_user_id IS NOT NULL AND is_active AND account_status::text='active' LIMIT 1")->fetchColumn();
if ($profileId === false) {
    echo "Matriz de permisos correcta; no hay un perfil activo disponible para probar RLS.\n";
    exit;
}
$pg->beginTransaction();
try {
    $pg->prepare("UPDATE public.profiles SET role='user'::public.app_role,is_superadmin=false WHERE id=?")->execute([$profileId]);
    $pg->prepare("DELETE FROM public.profile_module_roles pmr USING public.app_module_roles r, public.app_modules m WHERE pmr.module_role_id=r.id AND r.module_id=m.id AND m.code='salas' AND pmr.profile_id=?")->execute([$profileId]);
    $pg->prepare("INSERT INTO public.profile_module_roles(profile_id,module_role_id,is_active) SELECT ?,r.id,true FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id WHERE m.code='salas' AND r.code='manager'")->execute([$profileId]);
    $pg->prepare("DELETE FROM public.profile_module_permissions pp USING public.app_module_permissions p, public.app_modules m WHERE pp.permission_id=p.id AND p.module_id=m.id AND m.code='salas' AND pp.profile_id=?")->execute([$profileId]);
    $pg->prepare("INSERT INTO public.profile_module_permissions(profile_id,permission_id,allowed) SELECT ?,p.id,p.code IN ('ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes') FROM public.app_module_permissions p JOIN public.app_modules m ON m.id=p.module_id WHERE m.code='salas'")->execute([$profileId]);
    $pg->prepare("SELECT set_config('request.jwt.claim.sub', ?, true)")->execute([$profileId]);
    $pg->exec('SET LOCAL ROLE authenticated');
    $actual = $pg->query("SELECT permission_code FROM public.get_my_module_permissions('salas') ORDER BY permission_code")->fetchAll(PDO::FETCH_COLUMN);
    if ($actual !== $expected) {
        throw new RuntimeException('La sesión Gestor no recibe los permisos previstos.');
    }
    if ($pg->query('SELECT private.is_admin()')->fetchColumn()) {
        throw new RuntimeException('El Gestor obtuvo privilegios globales de administrador.');
    }
    $unexpected = $pg->query("SELECT COUNT(*) FROM public.vw_request_occurrences WHERE request_source<>'paid' AND status<>'approved' AND requested_by IS DISTINCT FROM auth.uid()")->fetchColumn();
    if ((int) $unexpected !== 0) {
        throw new RuntimeException('El Gestor puede consultar solicitudes internas no aprobadas.');
    }
    $pg->query('SELECT COUNT(*) FROM public.vw_request_occurrences')->fetchColumn();
    $pg->exec('RESET ROLE');
    $pg->exec("UPDATE public.profile_module_permissions pp SET allowed=false FROM public.app_module_permissions ap,public.app_modules am WHERE pp.permission_id=ap.id AND ap.module_id=am.id AND am.code='salas' AND ap.code='ver_reportes' AND pp.profile_id=auth.uid()");
    $pg->exec('SET LOCAL ROLE authenticated');
    $withoutReports = $pg->query("SELECT permission_code FROM public.get_my_module_permissions('salas') ORDER BY permission_code")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('ver_reportes', $withoutReports, true) || !in_array('ver_solicitudes_pago', $withoutReports, true)) {
        throw new RuntimeException('El permiso individual de Reportería no se respeta.');
    }
    $unexpectedInternal = $pg->query("SELECT COUNT(*) FROM public.vw_request_occurrences WHERE request_source<>'paid' AND requested_by IS DISTINCT FROM auth.uid()")->fetchColumn();
    if ((int) $unexpectedInternal !== 0) {
        throw new RuntimeException('Un Gestor sin Reportería puede leer solicitudes internas ajenas.');
    }
    $pg->exec('RESET ROLE');
    $pg->exec("UPDATE public.profile_module_permissions pp SET allowed=(ap.code='ver_reportes') FROM public.app_module_permissions ap,public.app_modules am WHERE pp.permission_id=ap.id AND ap.module_id=am.id AND am.code='salas' AND ap.code IN ('ver_reportes','ver_solicitudes_pago') AND pp.profile_id=auth.uid()");
    $pg->exec('SET LOCAL ROLE authenticated');
    $withoutPaid = $pg->query("SELECT permission_code FROM public.get_my_module_permissions('salas') ORDER BY permission_code")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('ver_solicitudes_pago', $withoutPaid, true) || !in_array('ver_reportes', $withoutPaid, true)) {
        throw new RuntimeException('El permiso individual de Solicitudes pago no se respeta.');
    }
    $unexpectedPaid = $pg->query("SELECT COUNT(*) FROM public.vw_request_occurrences WHERE request_source='paid' AND status<>'approved' AND requested_by IS DISTINCT FROM auth.uid()")->fetchColumn();
    if ((int) $unexpectedPaid !== 0) {
        throw new RuntimeException('Un Gestor sin permiso de pago puede leer solicitudes comerciales pendientes.');
    }
    echo "Permisos del Gestor y casillas individuales verificados; ningún cambio de prueba se guardó.\n";
} finally {
    $pg->rollBack();
}
