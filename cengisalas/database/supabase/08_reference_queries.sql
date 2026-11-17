-- SalaFlow / Supabase
-- 08: ejemplos de configuración y pruebas. No se ejecuta como migración automática.

-- 1) Después de crear el usuario en Authentication > Users, promoverlo a administrador:
-- update public.profiles
-- set role = 'admin',
--     account_status = 'active',
--     is_active = true,
--     status_reason = null,
--     organization_id = (select id from public.organizations where code='CENGICANA'),
--     department_id = (select d.id from public.departments d join public.organizations o on o.id=d.organization_id
--                      where o.code='CENGICANA' and d.name='Administración')
-- where id = (select id from auth.users where email='admin@ejemplo.com');

-- 2) Asignar un usuario común a Cengicaña (esto habilita la solicitud de refacción):
-- update public.profiles
-- set organization_id = (select id from public.organizations where code='CENGICANA'),
--     department_id = (select d.id from public.departments d join public.organizations o on o.id=d.organization_id
--                      where o.code='CENGICANA' and d.name='Tecnología')
-- where id = (select id from auth.users where email='usuario@ejemplo.com');

-- 3) Verificar catálogos y el espacio combinado:
select s.code,s.name,s.space_type,array_agg(r.name order by r.name) as physical_rooms
from public.spaces s
join public.space_rooms sr on sr.space_id=s.id
join public.rooms r on r.id=sr.room_id
group by s.id,s.code,s.name,s.space_type
order by s.name;

-- 4) Estado público del tablero para hoy en Guatemala:
select * from public.get_public_board((now() at time zone 'America/Guatemala')::date);

-- 5) Integridad: este resultado debe ser cero.
select count(*) as active_overlaps
from public.room_blocks a
join public.room_blocks b on b.id>a.id and b.room_id=a.room_id
  and a.active and b.active and a.during && b.during;
