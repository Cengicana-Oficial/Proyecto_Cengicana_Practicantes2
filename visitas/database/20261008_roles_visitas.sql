BEGIN;
ALTER TABLE public.app_module_roles DROP CONSTRAINT IF EXISTS app_module_roles_code_check;
ALTER TABLE public.app_module_roles ADD CONSTRAINT app_module_roles_code_check
    CHECK (code IN ('admin','user','manager','asistente_direccion','director_general','coordinador_visitas','gestor_pagos'));
INSERT INTO public.app_module_roles(module_id,code,name)
SELECT m.id,r.code,r.name FROM public.app_modules m CROSS JOIN (VALUES
    ('asistente_direccion','Asistente Dirección'),('director_general','Director General'),
    ('coordinador_visitas','Coordinador de Visitas'),('gestor_pagos','Gestor de Pagos')
) r(code,name) WHERE m.code='visitas'
ON CONFLICT(module_id,code) DO UPDATE SET name=excluded.name;
INSERT INTO public.app_module_permissions(module_id,code,name,description)
SELECT m.id,p.code,p.name,p.description FROM public.app_modules m CROSS JOIN (VALUES
    ('ver_reportes','Ver reportes','Consultar y exportar reportes de visitas.'),
    ('gestionar_roles_visitas','Gestionar usuarios y roles','Crear usuarios del portal y asignar roles de Visitas.')
) p(code,name,description) WHERE m.code='visitas'
ON CONFLICT(module_id,code) DO UPDATE SET name=excluded.name,description=excluded.description;
INSERT INTO public.app_module_role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id
JOIN public.app_module_permissions p ON p.module_id=m.id WHERE m.code='visitas'
AND (r.code IN ('asistente_direccion','director_general','coordinador_visitas')
OR (r.code='gestor_pagos' AND p.code IN ('ver_reportes','ver_pagos','gestionar_pagos')))
ON CONFLICT DO NOTHING;
UPDATE public.app_modules SET route='/login/sso_visitas.php',name='Visitas',updated_at=now() WHERE code='visitas';
COMMIT;
