-- SalaFlow / Supabase
-- 09: registro sujeto a aprobación y administración lógica de usuarios.
-- Ejecutar después de 01-07. Es seguro volver a ejecutarlo.

begin;

do $$ begin
  create type public.user_account_status as enum ('pending','active','rejected','deleted');
exception when duplicate_object then null;
end $$;

alter table public.profiles add column if not exists email text;
alter table public.profiles add column if not exists account_status public.user_account_status;
alter table public.profiles add column if not exists status_reason text;
alter table public.profiles add column if not exists reviewed_by uuid references auth.users(id) on delete set null;
alter table public.profiles add column if not exists reviewed_at timestamptz;
alter table public.profiles add column if not exists deleted_at timestamptz;

update public.profiles p
set email = u.email
from auth.users u
where u.id = p.id and p.email is null;

-- Las cuentas existentes se consideran aprobadas. Las nuevas quedarán pendientes.
update public.profiles
set account_status = case when is_active then 'active'::public.user_account_status else 'deleted'::public.user_account_status end
where account_status is null;

alter table public.profiles alter column account_status set default 'pending';
alter table public.profiles alter column account_status set not null;
create unique index if not exists uq_profiles_email_lower on public.profiles(lower(email)) where email is not null;
create index if not exists ix_profiles_status_role on public.profiles(account_status,role,created_at desc);

-- Organizaciones disponibles inicialmente en el registro. Las existentes se conservan.
insert into public.organizations(code,name,can_request_refreshments,is_active) values
  ('CENGICANA','Cengicaña',true,true),
  ('ICC','ICC',false,true),
  ('ATAGUA','Atagua',false,true),
  ('ASAZGUA','Asazgua',false,true),
  ('FUNDAZUCAR','Fundazucar',false,true),
  ('INNOVAHUB','Innovahub',false,true),
  ('EXTERNAL','Externo',false,true)
on conflict (code) do update set name=excluded.name,is_active=true;

create table if not exists public.user_account_status_history (
  id bigint generated always as identity primary key,
  user_id uuid not null references auth.users(id) on delete cascade,
  from_status public.user_account_status,
  to_status public.user_account_status not null,
  reason text,
  changed_by uuid references auth.users(id) on delete set null,
  changed_at timestamptz not null default now()
);
create index if not exists ix_user_status_history_user_date on public.user_account_status_history(user_id,changed_at desc);

create or replace function private.is_admin()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((
    select p.role = 'admin'::public.app_role
      and p.is_active
      and p.account_status = 'active'::public.user_account_status
    from public.profiles p
    where p.id = auth.uid()
  ), false);
$$;

create or replace function private.require_active_user()
returns void
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  if auth.uid() is null then
    raise exception using errcode='42501',message='Debe iniciar sesión.';
  end if;
  if not exists (
    select 1 from public.profiles p
    where p.id=auth.uid()
      and p.is_active
      and p.account_status='active'::public.user_account_status
  ) then
    raise exception using errcode='42501',message='La cuenta está pendiente, rechazada, eliminada o inactiva.';
  end if;
end;
$$;

-- Todo registro público empieza pendiente y sin permisos de aplicación.
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_organization_id bigint;
  v_requested_organization_id bigint;
  v_requested_organization_code text;
  v_name text;
begin
  v_requested_organization_code:=upper(nullif(btrim(new.raw_user_meta_data->>'organization_code'),''));
  if v_requested_organization_code is not null then
    select o.id into v_organization_id from public.organizations o
    where o.code=v_requested_organization_code and o.is_active limit 1;
  end if;
  if coalesce(new.raw_user_meta_data->>'organization_id','') ~ '^[0-9]+$' then
    v_requested_organization_id:=(new.raw_user_meta_data->>'organization_id')::bigint;
  end if;
  if v_organization_id is null then
    select o.id into v_organization_id from public.organizations o
    where o.id=v_requested_organization_id and o.is_active limit 1;
  end if;
  if v_organization_id is null then
    select o.id into v_organization_id from public.organizations o where o.code='EXTERNAL' limit 1;
  end if;
  v_name:=coalesce(nullif(btrim(new.raw_user_meta_data->>'full_name'),''),split_part(new.email,'@',1),'Usuario');

  insert into public.profiles(id,email,organization_id,full_name,role,is_active,account_status)
  values(new.id,lower(new.email),v_organization_id,v_name,'user'::public.app_role,false,'pending'::public.user_account_status)
  on conflict(id) do update
  set email=excluded.email,
      full_name=coalesce(nullif(public.profiles.full_name,''),excluded.full_name);

  insert into public.user_account_status_history(user_id,from_status,to_status,reason,changed_by)
  values(new.id,null,'pending','Solicitud de registro creada',null);
  insert into public.board_events(event_type,entity_type,entity_id)
  values('request','user',new.id::text);
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

create or replace function public.get_signup_organizations()
returns table(id bigint,code text,name text)
language sql
stable
security definer
set search_path = ''
as $$
  select o.id,o.code,o.name
  from public.organizations o
  where o.is_active
  order by case o.code when 'CENGICANA' then 1 when 'ICC' then 2 when 'ATAGUA' then 3
           when 'ASAZGUA' then 4 when 'FUNDAZUCAR' then 5 when 'INNOVAHUB' then 6
           when 'EXTERNAL' then 999 else 100 end,o.name;
$$;

create or replace function public.admin_create_organization(p_name text,p_code text)
returns bigint
language plpgsql
security definer
set search_path = ''
as $$
declare v_id bigint; v_code text;
begin
  perform private.require_admin();
  if nullif(btrim(p_name),'') is null then raise exception 'Debe indicar el nombre de la institución.'; end if;
  v_code:=upper(regexp_replace(btrim(p_code),'[^A-Za-z0-9]+','_','g'));
  v_code:=trim(both '_' from v_code);
  if v_code='' then raise exception 'Debe indicar un código válido.'; end if;
  insert into public.organizations(code,name,can_request_refreshments,is_active)
  values(v_code,btrim(p_name),false,true)
  returning id into v_id;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','create','organization',v_id::text,jsonb_build_object('code',v_code,'name',btrim(p_name)));
  insert into public.board_events(event_type,entity_type,entity_id) values('upsert','organization',v_id::text);
  return v_id;
exception when unique_violation then
  raise exception 'Ya existe una institución con ese código.';
end;
$$;

create or replace function public.get_admin_users()
returns table(
  user_id uuid,email text,full_name text,role public.app_role,
  account_status public.user_account_status,is_active boolean,
  organization_id bigint,organization_name text,department_id bigint,department_name text,
  status_reason text,created_at timestamptz,reviewed_at timestamptz,deleted_at timestamptz
)
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  perform private.require_admin();
  return query
  select p.id,p.email,p.full_name,p.role,p.account_status,p.is_active,
         p.organization_id,o.name,p.department_id,d.name,p.status_reason,
         p.created_at,p.reviewed_at,p.deleted_at
  from public.profiles p
  left join public.organizations o on o.id=p.organization_id
  left join public.departments d on d.id=p.department_id
  order by p.account_status,p.role,p.full_name;
end;
$$;

create or replace function public.admin_approve_user(
  p_user_id uuid,
  p_role public.app_role default 'user',
  p_organization_id bigint default null,
  p_department_id bigint default null
)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_old_status public.user_account_status;
  v_org bigint;
begin
  perform private.require_admin();
  select p.account_status into strict v_old_status from public.profiles p where p.id=p_user_id for update;
  if v_old_status not in ('pending','rejected') then raise exception 'Solo puede aprobar cuentas pendientes o rechazadas.'; end if;
  v_org:=coalesce(p_organization_id,(select id from public.organizations where code='EXTERNAL'));
  if not exists(select 1 from public.organizations where id=v_org and is_active) then raise exception 'Organización inválida.'; end if;
  if p_department_id is not null and not exists(
    select 1 from public.departments where id=p_department_id and organization_id=v_org and is_active
  ) then raise exception 'El departamento no pertenece a la organización seleccionada.'; end if;

  update public.profiles set role=p_role,organization_id=v_org,department_id=p_department_id,
    account_status='active',is_active=true,status_reason=null,reviewed_by=auth.uid(),reviewed_at=now(),deleted_at=null
  where id=p_user_id;
  insert into public.user_account_status_history(user_id,from_status,to_status,reason,changed_by)
  values(p_user_id,v_old_status,'active','Cuenta aprobada',auth.uid());
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','approve','user',p_user_id::text,jsonb_build_object('role',p_role,'organization_id',v_org));
  insert into public.board_events(event_type,entity_type,entity_id) values('upsert','user',p_user_id::text);
end;
$$;

create or replace function public.admin_reject_user(p_user_id uuid,p_reason text)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare v_old_status public.user_account_status;
begin
  perform private.require_admin();
  if p_user_id=auth.uid() then raise exception 'No puede rechazar su propia cuenta.'; end if;
  if nullif(btrim(p_reason),'') is null then raise exception 'Debe indicar el motivo del rechazo.'; end if;
  select account_status into strict v_old_status from public.profiles where id=p_user_id for update;
  if v_old_status<>'pending' then raise exception 'Solo puede rechazar solicitudes pendientes.'; end if;
  update public.profiles set account_status='rejected',is_active=false,status_reason=btrim(p_reason),
    reviewed_by=auth.uid(),reviewed_at=now() where id=p_user_id;
  insert into public.user_account_status_history(user_id,from_status,to_status,reason,changed_by)
  values(p_user_id,v_old_status,'rejected',btrim(p_reason),auth.uid());
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','reject','user',p_user_id::text,jsonb_build_object('reason',btrim(p_reason)));
  insert into public.board_events(event_type,entity_type,entity_id) values('remove','user',p_user_id::text);
end;
$$;

create or replace function public.admin_delete_user_logically(p_user_id uuid,p_reason text default null)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare v_old_status public.user_account_status; v_role public.app_role;
begin
  perform private.require_admin();
  if p_user_id=auth.uid() then raise exception 'No puede eliminar su propia cuenta.'; end if;
  select account_status,role into strict v_old_status,v_role from public.profiles where id=p_user_id for update;
  if v_old_status<>'active' then raise exception 'Solo se pueden eliminar cuentas activas.'; end if;
  if v_role='admin' and (select count(*) from public.profiles where role='admin' and account_status='active' and is_active)<=1 then
    raise exception 'No puede eliminar al último administrador activo.';
  end if;
  update public.profiles set account_status='deleted',is_active=false,status_reason=coalesce(nullif(btrim(p_reason),''),'Eliminación lógica'),
    reviewed_by=auth.uid(),reviewed_at=now(),deleted_at=now() where id=p_user_id;
  insert into public.user_account_status_history(user_id,from_status,to_status,reason,changed_by)
  values(p_user_id,v_old_status,'deleted',coalesce(nullif(btrim(p_reason),''),'Eliminación lógica'),auth.uid());
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','logical_delete','user',p_user_id::text,jsonb_build_object('reason',p_reason));
  insert into public.board_events(event_type,entity_type,entity_id) values('remove','user',p_user_id::text);
end;
$$;

create or replace function public.admin_restore_user(p_user_id uuid)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare v_old_status public.user_account_status;
begin
  perform private.require_admin();
  select account_status into strict v_old_status from public.profiles where id=p_user_id for update;
  if v_old_status<>'deleted' then raise exception 'Solo se pueden restaurar cuentas eliminadas.'; end if;
  update public.profiles set account_status='active',is_active=true,status_reason=null,
    reviewed_by=auth.uid(),reviewed_at=now(),deleted_at=null where id=p_user_id;
  insert into public.user_account_status_history(user_id,from_status,to_status,reason,changed_by)
  values(p_user_id,v_old_status,'active','Cuenta restaurada',auth.uid());
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','restore','user',p_user_id::text,'{}'::jsonb);
  insert into public.board_events(event_type,entity_type,entity_id) values('upsert','user',p_user_id::text);
end;
$$;

alter table public.user_account_status_history enable row level security;
revoke all on public.user_account_status_history from anon,authenticated;
grant select on public.user_account_status_history to authenticated;
drop policy if exists user_status_history_read on public.user_account_status_history;
create policy user_status_history_read on public.user_account_status_history for select to authenticated
using(user_id=auth.uid() or private.is_admin());

revoke all on function public.get_admin_users() from public,anon,authenticated;
revoke all on function public.get_signup_organizations() from public,anon,authenticated;
revoke all on function public.admin_create_organization(text,text) from public,anon,authenticated;
revoke all on function public.admin_approve_user(uuid,public.app_role,bigint,bigint) from public,anon,authenticated;
revoke all on function public.admin_reject_user(uuid,text) from public,anon,authenticated;
revoke all on function public.admin_delete_user_logically(uuid,text) from public,anon,authenticated;
revoke all on function public.admin_restore_user(uuid) from public,anon,authenticated;
grant execute on function public.get_admin_users() to authenticated;
grant execute on function public.get_signup_organizations() to anon,authenticated;
grant execute on function public.admin_create_organization(text,text) to authenticated;
grant execute on function public.admin_approve_user(uuid,public.app_role,bigint,bigint) to authenticated;
grant execute on function public.admin_reject_user(uuid,text) to authenticated;
grant execute on function public.admin_delete_user_logically(uuid,text) to authenticated;
grant execute on function public.admin_restore_user(uuid) to authenticated;

revoke all on function public.handle_new_user() from public,anon,authenticated;
revoke all on function private.is_admin() from public,anon,authenticated;
revoke all on function private.require_active_user() from public,anon,authenticated;
grant execute on function private.is_admin() to authenticated;

commit;
