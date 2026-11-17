-- Identidad del menú Docker: sus permisos son específicos por módulo.
-- Las cuentas originales de CengiSalas mantienen su comportamiento anterior.
begin;

alter table public.profiles add column if not exists portal_user_id integer;
create unique index if not exists profiles_portal_user_id_unique
  on public.profiles(portal_user_id) where portal_user_id is not null;

create or replace function private.sync_profile_module_roles()
returns trigger language plpgsql security definer set search_path=''
as $$
begin
  if new.portal_user_id is not null then
    return new;
  end if;
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
    update public.profile_module_roles set is_active=false,updated_at=now()
    where profile_id=new.id;
  end if;
  return new;
end;
$$;

create or replace function public.get_my_modules()
returns table(code text,name text,description text,route text,module_role text,sort_order integer)
language sql stable security definer set search_path=''
as $$
  select distinct m.code,m.name,m.description,m.route,
    case when p.is_superadmin or (p.portal_user_id is null and p.role::text='admin'
      and not exists(select 1 from public.profile_module_permissions own where own.profile_id=p.id))
      then 'admin' else coalesce(r.code,'personalizado') end,
    m.sort_order
  from public.profiles p
  join public.app_modules m on m.is_active
  left join lateral (
    select ar.code
    from public.profile_module_roles pmr
    join public.app_module_roles ar on ar.id=pmr.module_role_id
    where pmr.profile_id=p.id and pmr.is_active and ar.module_id=m.id
    order by ar.code limit 1
  ) r on true
  where p.id=auth.uid() and p.is_active and p.account_status::text='active'
    and (
      p.is_superadmin
      or (p.portal_user_id is null and p.role::text='admin'
        and not exists(select 1 from public.profile_module_permissions own where own.profile_id=p.id))
      or exists (
        select 1 from public.profile_module_permissions pmp
        join public.app_module_permissions mp on mp.id=pmp.permission_id and mp.module_id=m.id
        where pmp.profile_id=p.id and pmp.allowed
      )
      or exists (
        select 1 from public.profile_module_roles pmr
        join public.app_module_roles ar on ar.id=pmr.module_role_id
        where pmr.profile_id=p.id and pmr.is_active and ar.module_id=m.id
      )
    )
  order by m.sort_order,m.name;
$$;

create or replace function private.has_module_access(p_module_code text)
returns boolean language sql stable security definer set search_path=''
as $$
  select coalesce((
    select p.is_superadmin
      or (p.portal_user_id is null and private.is_admin())
      or exists (
        select 1 from public.app_modules m
        join public.app_module_permissions mp on mp.module_id=m.id
        join public.profile_module_permissions pmp on pmp.permission_id=mp.id
        where m.code=lower(p_module_code) and m.is_active
          and pmp.profile_id=p.id and pmp.allowed
      )
      or exists (
        select 1 from public.profile_module_roles pmr
        join public.app_module_roles ar on ar.id=pmr.module_role_id
        join public.app_modules m on m.id=ar.module_id and m.is_active
        where pmr.profile_id=p.id and pmr.is_active and m.code=lower(p_module_code)
      )
    from public.profiles p
    where p.id=auth.uid() and p.is_active and p.account_status::text='active'
  ),false);
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
      or (p.portal_user_id is null and p.role::text='admin'
        and not exists(select 1 from public.profile_module_permissions own where own.profile_id=p.id))
      or coalesce(
        (select pmp.allowed from public.profile_module_permissions pmp
         where pmp.profile_id=p.id and pmp.permission_id=mp.id limit 1),
        exists (
          select 1 from public.profile_module_roles pmr
          join public.app_module_role_permissions rp on rp.role_id=pmr.module_role_id
          where pmr.profile_id=p.id and pmr.is_active and rp.permission_id=mp.id
        ),false)
    )
  order by mp.code;
$$;

commit;
