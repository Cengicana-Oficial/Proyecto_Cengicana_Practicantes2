-- CengiSalas / Supabase
-- 29: catalogo de modulos y permisos por usuario.

begin;

create table if not exists public.app_modules (
  id bigint generated always as identity primary key,
  code text not null unique check (code = lower(code) and code ~ '^[a-z0-9_]+$'),
  name text not null,
  description text,
  route text not null,
  sort_order integer not null default 0,
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.app_module_roles (
  id bigint generated always as identity primary key,
  module_id bigint not null references public.app_modules(id) on delete cascade,
  code text not null check (code in ('admin','user')),
  name text not null,
  unique (module_id, code)
);

create table if not exists public.app_module_permissions (
  id bigint generated always as identity primary key,
  module_id bigint not null references public.app_modules(id) on delete cascade,
  code text not null check (code ~ '^[a-z0-9_]+$'),
  name text not null,
  description text,
  unique (module_id, code)
);

create table if not exists public.app_module_role_permissions (
  role_id bigint not null references public.app_module_roles(id) on delete cascade,
  permission_id bigint not null references public.app_module_permissions(id) on delete cascade,
  primary key (role_id, permission_id)
);

create table if not exists public.profile_module_roles (
  profile_id uuid not null references public.profiles(id) on delete cascade,
  module_role_id bigint not null references public.app_module_roles(id) on delete cascade,
  is_active boolean not null default true,
  granted_by uuid references auth.users(id) on delete set null,
  granted_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  primary key (profile_id, module_role_id)
);

insert into public.app_modules(code,name,description,route,sort_order,is_active) values
  ('salas','Gestion de salones','Solicitudes, agenda, mantenimientos, incidencias y reporteria.','index.html?modulo=salones',10,true),
  ('visitas','Solicitudes de visitas','Visitas al Museo del Azucar y a Cengicana.','modules/visitas/public/admin/dashboard_unificado.php?modulo=solicitudes',20,true)
on conflict (code) do update set
  name=excluded.name,description=excluded.description,route=excluded.route,
  sort_order=excluded.sort_order,is_active=excluded.is_active,updated_at=now();

insert into public.app_module_roles(module_id,code,name)
select m.id,r.code,r.name
from public.app_modules m
cross join (values ('admin','Administrador'),('user','Usuario')) as r(code,name)
where m.code in ('salas','visitas')
on conflict (module_id,code) do update set name=excluded.name;

insert into public.app_module_permissions(module_id,code,name,description)
select m.id,p.code,p.name,p.description
from public.app_modules m
cross join (values
  ('ver_dashboard','Ver panel','Permite ingresar al panel del modulo.'),
  ('gestionar_solicitudes','Gestionar solicitudes','Permite aprobar, rechazar, cancelar y editar solicitudes.'),
  ('ver_solicitudes','Ver solicitudes','Permite consultar solicitudes.'),
  ('gestionar_pagos','Gestionar pagos','Permite actualizar el estado de pago.'),
  ('ver_pagos','Ver pagos','Permite consultar pagos.'),
  ('gestionar_areas','Gestionar areas','Permite crear y editar areas de visita.'),
  ('gestionar_reserva_salones','Solicitar salones','Permite vincular una visita con CengiSalas.'),
  ('ver_solicitudes_aprobadas','Ver aprobadas','Permite consultar solicitudes aprobadas.'),
  ('enviar_correos','Enviar correos','Permite enviar notificaciones de solicitudes.'),
  ('ocultar_solicitudes','Ocultar solicitudes','Permite ocultar solicitudes del tablero.')
) as p(code,name,description)
where m.code='visitas'
on conflict (module_id,code) do update set name=excluded.name,description=excluded.description;

insert into public.app_module_permissions(module_id,code,name,description)
select m.id,p.code,p.name,p.description
from public.app_modules m
cross join (values
  ('ver_dashboard','Ver panel','Permite ingresar al modulo de salones.'),
  ('gestionar_solicitudes','Gestionar solicitudes','Permite administrar solicitudes de salones.'),
  ('ver_solicitudes','Ver solicitudes','Permite consultar solicitudes propias o administrativas.')
) as p(code,name,description)
where m.code='salas'
on conflict (module_id,code) do update set name=excluded.name,description=excluded.description;

-- Administradores de cada modulo reciben todos sus permisos.
insert into public.app_module_role_permissions(role_id,permission_id)
select r.id,p.id
from public.app_module_roles r
join public.app_module_permissions p on p.module_id=r.module_id
where r.code='admin'
on conflict do nothing;

-- El usuario estandar de Visitas conserva el acceso de solo consulta aprobado.
insert into public.app_module_role_permissions(role_id,permission_id)
select r.id,p.id
from public.app_module_roles r
join public.app_module_permissions p on p.module_id=r.module_id
where r.code='user'
  and ((p.code in ('ver_dashboard','ver_solicitudes_aprobadas') and
        r.module_id=(select id from public.app_modules where code='visitas'))
    or (p.code in ('ver_dashboard','ver_solicitudes') and
        r.module_id=(select id from public.app_modules where code='salas')))
on conflict do nothing;

-- Los usuarios activos actuales obtienen ambos modulos con un rol equivalente al global.
insert into public.profile_module_roles(profile_id,module_role_id,is_active)
select p.id,r.id,true
from public.profiles p
cross join public.app_modules m
cross join public.app_module_roles r
where r.module_id=m.id and r.code=p.role::text
  and p.is_active and p.account_status::text='active' and m.code in ('salas','visitas')
on conflict (profile_id,module_role_id) do update set is_active=true,updated_at=now();

create or replace function private.sync_profile_module_roles()
returns trigger language plpgsql security definer set search_path=''
as $$
begin
  if new.is_active and new.account_status::text='active' then
    delete from public.profile_module_roles pmr
    using public.app_module_roles r
    where pmr.profile_id=new.id and pmr.module_role_id=r.id and r.code<>new.role::text;

    insert into public.profile_module_roles(profile_id,module_role_id,is_active)
    select new.id,r.id,true
    from public.app_module_roles r
    join public.app_modules m on m.id=r.module_id
    where m.is_active and r.code=new.role::text
    on conflict (profile_id,module_role_id)
    do update set is_active=true,updated_at=now();
  else
    update public.profile_module_roles set is_active=false,updated_at=now() where profile_id=new.id;
  end if;
  return new;
end;
$$;

drop trigger if exists profiles_sync_module_roles on public.profiles;
create trigger profiles_sync_module_roles
after insert or update of role,is_active,account_status on public.profiles
for each row execute function private.sync_profile_module_roles();

create or replace function private.has_module_access(p_module_code text)
returns boolean language sql stable security definer set search_path=''
as $$
  select exists (
    select 1
    from public.profiles p
    join public.profile_module_roles pmr on pmr.profile_id=p.id and pmr.is_active
    join public.app_module_roles r on r.id=pmr.module_role_id
    join public.app_modules m on m.id=r.module_id and m.is_active
    where p.id=auth.uid() and p.is_active and p.account_status::text='active'
      and m.code=lower(p_module_code)
  ) or private.is_admin();
$$;

create or replace function public.get_my_modules()
returns table(code text,name text,description text,route text,module_role text,sort_order integer)
language sql stable security definer set search_path=''
as $$
  select distinct m.code,m.name,m.description,m.route,
    case when p.role::text='admin' then 'admin' else r.code end,m.sort_order
  from public.profiles p
  join public.app_modules m on m.is_active
  left join public.app_module_roles r on r.module_id=m.id
  left join public.profile_module_roles pmr
    on pmr.profile_id=p.id and pmr.module_role_id=r.id and pmr.is_active
  where p.id=auth.uid() and p.is_active and p.account_status::text='active'
    and (p.role::text='admin' or pmr.profile_id is not null)
  order by m.sort_order,m.name;
$$;

create or replace function public.get_my_module_permissions(p_module_code text)
returns table(permission_code text)
language sql stable security definer set search_path=''
as $$
  select distinct mp.code
  from public.profiles p
  join public.app_modules m on m.code=lower(p_module_code) and m.is_active
  join public.app_module_permissions mp on mp.module_id=m.id
  left join public.app_module_roles r on r.module_id=m.id
  left join public.profile_module_roles pmr
    on pmr.profile_id=p.id and pmr.module_role_id=r.id and pmr.is_active
  left join public.app_module_role_permissions rp
    on rp.role_id=r.id and rp.permission_id=mp.id
  where p.id=auth.uid() and p.is_active and p.account_status::text='active'
    and (p.role::text='admin' or (pmr.profile_id is not null and rp.permission_id is not null))
  order by mp.code;
$$;

create or replace function public.admin_set_profile_module_role(
  p_profile_id uuid,p_module_code text,p_role_code text,p_enabled boolean default true
)
returns void language plpgsql security definer set search_path=''
as $$
declare v_role_id bigint;
begin
  perform private.require_admin();
  select r.id into strict v_role_id
  from public.app_module_roles r join public.app_modules m on m.id=r.module_id
  where m.code=lower(p_module_code) and r.code=lower(p_role_code);

  delete from public.profile_module_roles pmr
  using public.app_module_roles r
  where pmr.profile_id=p_profile_id and pmr.module_role_id=r.id
    and r.module_id=(select module_id from public.app_module_roles where id=v_role_id);

  if p_enabled then
    insert into public.profile_module_roles(profile_id,module_role_id,is_active,granted_by)
    values(p_profile_id,v_role_id,true,auth.uid());
  end if;
end;
$$;

alter table public.app_modules enable row level security;
alter table public.app_module_roles enable row level security;
alter table public.app_module_permissions enable row level security;
alter table public.app_module_role_permissions enable row level security;
alter table public.profile_module_roles enable row level security;

revoke all on public.app_modules,public.app_module_roles,public.app_module_permissions,
  public.app_module_role_permissions,public.profile_module_roles from anon,authenticated;

revoke all on function public.get_my_modules() from public,anon,authenticated;
grant execute on function public.get_my_modules() to authenticated;
revoke all on function public.get_my_module_permissions(text) from public,anon,authenticated;
grant execute on function public.get_my_module_permissions(text) to authenticated;
revoke all on function public.admin_set_profile_module_role(uuid,text,text,boolean) from public,anon,authenticated;
grant execute on function public.admin_set_profile_module_role(uuid,text,text,boolean) to authenticated;
revoke all on function private.has_module_access(text) from public,anon,authenticated;
grant execute on function private.has_module_access(text) to authenticated;

commit;
