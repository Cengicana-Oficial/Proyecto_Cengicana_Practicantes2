<?php
declare(strict_types=1);
require_once __DIR__ . '/cengisalas_access.php';

function visitas_permissions(string $role): array
{
    return match (visitas_role_code($role)) {
        'asistente_direccion', 'director_general', 'coordinador_visitas' => ['ver_dashboard', 'gestionar_solicitudes', 'ver_solicitudes',
            'gestionar_pagos', 'ver_pagos', 'gestionar_areas', 'gestionar_reserva_salones',
            'ver_solicitudes_aprobadas', 'enviar_correos', 'ocultar_solicitudes', 'gestionar_roles_visitas', 'ver_reportes'],
        'gestor_pagos' => ['ver_reportes', 'ver_pagos', 'gestionar_pagos'],
        default => [],
    };
}

function visitas_actor(PDO $db, int $userId): ?array
{
    $query = $db->prepare("SELECT u.id,u.nombre,u.correo,u.es_superadmin,a.role_code
        FROM usuarios u LEFT JOIN cengisalas_module_access a
          ON a.usuario_id=u.id AND a.module_code='visitas' WHERE u.id=?");
    $query->execute([$userId]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    $user['role_code'] = (int) $user['es_superadmin'] === 1 ? 'coordinador_visitas' : visitas_role_code((string) $user['role_code']);
    return isset(cengisalas_roles()['visitas'][$user['role_code'] ?? '']) ? $user : null;
}
