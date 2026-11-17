-- CengiSalas / Supabase
-- Matriz de permisos por usuario. Solo el superadministrador puede modificarla.

begin;

-- Permisos adicionales para poder delegar funciones concretas del módulo de
-- salones sin otorgar el rol global de administrador.
insert into public.app_module_permissions(module_id,code,name,description)
select m.id,p.code,p.name,p.description
from public.app_modules m
cross join (values
  ('gestionar_mantenimiento','Gestionar mantenimientos','Programar y cancelar avisos de mantenimiento.'),
  ('gestionar_salones','Gestionar salones','Modificar capacidades, consumo energético y tarifas.'),
  ('ver_reportes','Ver reportería','Consultar reportes y exportaciones.'),
  ('gestionar_usuarios','Gestionar usuarios','Aprobar, rechazar y administrar cuentas.'),
  ('gestionar_incidencias','Gestionar incidencias','Registrar incidencias de reuniones.'),
  ('administrar_instituciones','Administrar instituciones','Crear y administrar organizaciones.'),
  ('ver_solicitudes_pago','Ver solicitudes de pago','Consultar solicitudes comerciales y sus costos.')
) as p(code,name,description)
where m.code='salas'
on conflict (module_id,code) do update set name=excluded.name,description=excluded.description;

alter table public.profiles
  add column if not exists is_superadmin boolean not null default false;

-- Superadministrador inicial del sistema. Si la cuenta cambia, sustituye este
-- correo por el del usuario que debe administrar la matriz de permisos.
update public.profiles
set is_superadmin=true, updated_at=now()
where lower(email)=lower('scapacitacion@cengicana.org');

-- Mantiene compatibles las operaciones administrativas existentes y permite
-- que la marca de superadministrador también sea reconocida por sus RPC.
create or replace function private.is_admin()
returns boolean language sql stable security definer set search_path=''
as $$
  select coalesce((
    select (p.role='admin'::public.app_role or p.is_superadmin)
      and p.is_active
      and p.account_status::text='active'
    from public.profiles p
    where p.id=auth.uid()
  ),false);
$$;

create table if not exists public.profile_module_permissions (
  profile_id uuid not null references public.profiles(id) on delete cascade,
  permission_id bigint not null references public.app_module_permissions(id) on delete cascade,
  allowed boolean not null default true,
  granted_by uuid references auth.users(id) on delete set null,
  granted_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  primary key (profile_id, permission_id)
);

create index if not exists profile_module_permissions_permission_idx
  on public.profile_module_permissions(permission_id, profile_id);

create or replace function private.require_superadmin()
returns void language plpgsql security definer set search_path=''
as $$
begin
  if not exists (
    select 1
    from public.profiles p
    where p.id=auth.uid()
      and p.is_superadmin
      and p.is_active
      and p.account_status::text='active'
  ) then
    raise exception 'Solo el superadministrador puede administrar permisos.' using errcode='42501';
  end if;
end;
$$;
revoke all on function private.require_superadmin() from public,anon,authenticated;

create or replace function public.get_admin_permission_matrix()
returns table(
  user_id uuid,
  email text,
  full_name text,
  role public.app_role,
  is_active boolean,
  account_status public.user_account_status,
  module_code text,
  module_name text,
  module_role text,
  permission_id bigint,
  permission_code text,
  permission_name text,
  permission_description text,
  permission_enabled boolean
)
language plpgsql stable security definer set search_path=''
as $$
begin
  perform private.require_superadmin();
  return query
  select
    p.id,
    p.email,
    p.full_name,
    p.role,
    p.is_active,
    p.account_status,
    m.code,
    m.name,
    coalesce((
      select r.code
      from public.profile_module_roles pmr
      join public.app_module_roles r on r.id=pmr.module_role_id
      where pmr.profile_id=p.id and pmr.is_active and r.module_id=m.id
      order by r.code
      limit 1
    ), 'personalizado'),
    mp.id,
    mp.code,
    mp.name,
    mp.description,
    case
      when p.role::text='admin' and not exists (
        select 1 from public.profile_module_permissions own_permissions
        where own_permissions.profile_id=p.id
      ) then true
      else coalesce(
        (select pmp.allowed
         from public.profile_module_permissions pmp
         where pmp.profile_id=p.id and pmp.permission_id=mp.id
         limit 1),
        exists (
          select 1
          from public.profile_module_roles pmr
          join public.app_module_role_permissions rp on rp.role_id=pmr.module_role_id
          where pmr.profile_id=p.id and pmr.is_active and rp.permission_id=mp.id
        ),
        false
      )
    end
  from public.profiles p
  cross join public.app_modules m
  join public.app_module_permissions mp on mp.module_id=m.id
  where p.is_active
    and p.account_status::text='active'
    and m.is_active
  order by lower(p.full_name),m.sort_order,mp.id;
end;
$$;

create or replace function public.admin_save_profile_permissions(
  p_profile_id uuid,
  p_assignments jsonb
)
returns void language plpgsql security definer set search_path=''
as $$
begin
  perform private.require_superadmin();

  if not exists (
    select 1 from public.profiles p
    where p.id=p_profile_id and p.is_active and p.account_status::text='active'
  ) then
    raise exception 'El usuario seleccionado no está activo.' using errcode='22023';
  end if;
  if p_profile_id=auth.uid() then
    raise exception 'El superadministrador no puede quitarse sus propios permisos.' using errcode='42501';
  end if;
  if jsonb_typeof(coalesce(p_assignments,'[]'::jsonb)) <> 'array' then
    raise exception 'La matriz de permisos no tiene un formato válido.' using errcode='22023';
  end if;

  -- Al guardar una matriz, los permisos directos pasan a ser la fuente de
  -- verdad del usuario. Se eliminan los roles heredados para que una casilla
  -- desmarcada sí pueda revocar el permiso.
  delete from public.profile_module_roles where profile_id=p_profile_id;
  delete from public.profile_module_permissions where profile_id=p_profile_id;

  insert into public.profile_module_permissions(
    profile_id,permission_id,allowed,granted_by,granted_at,updated_at
  )
  select
    p_profile_id,
    (item->>'permission_id')::bigint,
    coalesce((item->>'allowed')::boolean,false),
    auth.uid(),now(),now()
  from jsonb_array_elements(coalesce(p_assignments,'[]'::jsonb)) item
  join public.app_module_permissions mp
    on mp.id=(item->>'permission_id')::bigint
  on conflict(profile_id,permission_id) do update set
    allowed=excluded.allowed,
    granted_by=excluded.granted_by,
    updated_at=now();

  insert into public.board_events(event_type,entity_type,entity_id)
  values('permissions_updated','user',p_profile_id::text)
  on conflict do nothing;
end;
$$;

-- Permisos efectivos: una asignación directa (true o false) prevalece sobre
-- el rol del módulo. El rol admin conserva su acceso global existente.
create or replace function public.get_my_modules()
returns table(code text,name text,description text,route text,module_role text,sort_order integer)
language sql stable security definer set search_path=''
as $$
  select distinct m.code,m.name,m.description,m.route,
    case when p.is_superadmin or (p.role::text='admin' and not exists (
      select 1 from public.profile_module_permissions own_permissions
      where own_permissions.profile_id=p.id
    )) then 'admin' else coalesce(r.code,'personalizado') end,
    m.sort_order
  from public.profiles p
  join public.app_modules m on m.is_active
  left join lateral (
    select r.code
    from public.profile_module_roles pmr
    join public.app_module_roles r on r.id=pmr.module_role_id
    where pmr.profile_id=p.id and pmr.is_active and r.module_id=m.id
    order by r.code
    limit 1
  ) r on true
  where p.id=auth.uid() and p.is_active and p.account_status::text='active'
    and (
      p.is_superadmin
      or (p.role::text='admin' and not exists (
        select 1 from public.profile_module_permissions own_permissions
        where own_permissions.profile_id=p.id
      ))
      or exists (
        select 1 from public.profile_module_permissions pmp
        join public.app_module_permissions mp on mp.id=pmp.permission_id and mp.module_id=m.id
        where pmp.profile_id=p.id and pmp.allowed
      )
      or exists (
        select 1
        from public.profile_module_roles pmr
        join public.app_module_roles r2 on r2.id=pmr.module_role_id
        where pmr.profile_id=p.id and pmr.is_active and r2.module_id=m.id
      )
    )
  order by m.sort_order,m.name;
$$;

create or replace function private.has_module_access(p_module_code text)
returns boolean language sql stable security definer set search_path=''
as $$
  select private.is_admin() or exists (
    select 1
    from public.profiles p
    join public.app_modules m on m.code=lower(p_module_code) and m.is_active
    join public.app_module_permissions mp on mp.module_id=m.id
    join public.profile_module_permissions pmp on pmp.permission_id=mp.id
    where p.id=auth.uid() and pmp.profile_id=p.id and pmp.allowed
  ) or exists (
    select 1
    from public.profiles p
    join public.profile_module_roles pmr on pmr.profile_id=p.id and pmr.is_active
    join public.app_module_roles r on r.id=pmr.module_role_id
    join public.app_modules m on m.id=r.module_id and m.is_active
    where p.id=auth.uid() and m.code=lower(p_module_code)
  );
$$;

create or replace function public.get_my_module_permissions(p_module_code text)
returns table(permission_code text)
language sql stable security definer set search_path=''
as $$
  select mp.code
  from public.profiles p
  join public.app_modules m on m.code=lower(p_module_code) and m.is_active
  join public.app_module_permissions mp on mp.module_id=m.id
  where p.id=auth.uid() and p.is_active and p.account_status::text='active'
    and (
      p.is_superadmin
      or (p.role::text='admin' and not exists (
        select 1 from public.profile_module_permissions own_permissions
        where own_permissions.profile_id=p.id
      ))
      or coalesce(
        (select pmp.allowed
         from public.profile_module_permissions pmp
         where pmp.profile_id=p.id and pmp.permission_id=mp.id
         limit 1),
        exists (
          select 1
          from public.profile_module_roles pmr
          join public.app_module_role_permissions rp on rp.role_id=pmr.module_role_id
          where pmr.profile_id=p.id and pmr.is_active and rp.permission_id=mp.id
        ),
        false
      )
    )
  order by mp.code;
$$;

-- El cambio de roles por módulo también queda reservado al superadministrador.
create or replace function public.admin_set_profile_module_role(
  p_profile_id uuid,p_module_code text,p_role_code text,p_enabled boolean default true
)
returns void language plpgsql security definer set search_path=''
as $$
declare v_role_id bigint;
begin
  perform private.require_superadmin();
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

alter table public.profile_module_permissions enable row level security;
revoke all on public.profile_module_permissions from anon,authenticated;

revoke all on function public.get_admin_permission_matrix() from public,anon,authenticated;
grant execute on function public.get_admin_permission_matrix() to authenticated;
revoke all on function public.admin_save_profile_permissions(uuid,jsonb) from public,anon,authenticated;
grant execute on function public.admin_save_profile_permissions(uuid,jsonb) to authenticated;

commit;
