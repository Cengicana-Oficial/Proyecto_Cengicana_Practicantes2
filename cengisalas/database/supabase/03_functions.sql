-- SalaFlow / Supabase
-- 03: funciones seguras (RPC), perfil de Auth y operaciones transaccionales.

begin;

create or replace function private.is_admin()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((
    select p.role = 'admin'::public.app_role and p.is_active
    from public.profiles p
    where p.id = auth.uid()
  ), false);
$$;

create or replace function private.current_role()
returns public.app_role
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((select p.role from public.profiles p where p.id = auth.uid()), 'user'::public.app_role);
$$;

create or replace function private.actor_name()
returns text
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((select p.full_name from public.profiles p where p.id = auth.uid()), 'Sistema');
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
    raise exception using errcode = '42501', message = 'Debe iniciar sesión.';
  end if;
  if not exists (select 1 from public.profiles p where p.id = auth.uid() and p.is_active) then
    raise exception using errcode = '42501', message = 'El perfil no existe o está inactivo.';
  end if;
end;
$$;

create or replace function private.require_admin()
returns void
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  perform private.require_active_user();
  if not private.is_admin() then
    raise exception using errcode = '42501', message = 'Esta acción requiere rol de administrador.';
  end if;
end;
$$;

create or replace function private.validate_space_capacity(
  p_space_id bigint,
  p_configuration_type_id bigint,
  p_attendees integer
)
returns void
language plpgsql
stable
security definer
set search_path = ''
as $$
declare
  v_capacity integer;
begin
  if not exists (
    select 1 from public.spaces s
    join public.space_rooms sr on sr.space_id = s.id
    where s.id = p_space_id and s.is_active
  ) or exists (
    select 1 from public.space_rooms sr
    join public.rooms r on r.id = sr.room_id
    where sr.space_id = p_space_id and not r.is_active
  ) then
    raise exception 'El espacio no existe, está inactivo o contiene un salón físico inactivo.';
  end if;

  select sc.capacity into v_capacity
  from public.space_capacities sc
  join public.spaces s on s.id = sc.space_id
  where sc.space_id = p_space_id
    and sc.configuration_type_id = p_configuration_type_id
    and s.is_active;

  if v_capacity is null then
    raise exception 'El espacio no admite la configuración seleccionada.';
  end if;
  if p_attendees > v_capacity then
    raise exception 'La cantidad de asistentes (%) supera la capacidad del espacio (%).', p_attendees, v_capacity;
  end if;
end;
$$;

create or replace function private.space_is_available(
  p_space_id bigint,
  p_starts_at timestamptz,
  p_ends_at timestamptz,
  p_exclude_occurrence_id uuid default null
)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select p_ends_at > p_starts_at
    and exists (select 1 from public.spaces s where s.id = p_space_id and s.is_active)
    and exists (select 1 from public.space_rooms sr where sr.space_id = p_space_id)
    and not exists (
      select 1 from public.space_rooms sr
      join public.rooms r on r.id = sr.room_id
      where sr.space_id = p_space_id and not r.is_active
    )
    and not exists (
      select 1
      from public.space_rooms sr
      join public.room_blocks rb on rb.room_id = sr.room_id and rb.active
      where sr.space_id = p_space_id
        and rb.during && tstzrange(p_starts_at, p_ends_at, '[)')
        and (p_exclude_occurrence_id is null or rb.occurrence_id is distinct from p_exclude_occurrence_id)
    );
$$;

-- Crea automáticamente el perfil de aplicación al registrar un usuario en Supabase Auth.
-- Nunca acepta el rol desde metadata: todo usuario nuevo empieza como user.
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_organization_id bigint;
begin
  select o.id into v_organization_id
  from public.organizations o
  where o.code = 'EXTERNAL'
  limit 1;

  insert into public.profiles (id, organization_id, full_name, role)
  values (
    new.id,
    v_organization_id,
    coalesce(nullif(btrim(new.raw_user_meta_data ->> 'full_name'), ''), split_part(new.email, '@', 1), 'Usuario'),
    'user'::public.app_role
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

create or replace function public.check_space_availability(
  p_space_id bigint,
  p_starts_at timestamptz,
  p_ends_at timestamptz,
  p_exclude_occurrence_id uuid default null
)
returns boolean
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  perform private.require_active_user();
  return private.space_is_available(p_space_id, p_starts_at, p_ends_at, p_exclude_occurrence_id);
end;
$$;

create or replace function public.create_meeting_request(
  p_title text,
  p_description text,
  p_meeting_type_id bigint,
  p_configuration_type_id bigint,
  p_attendees integer,
  p_occurrences jsonb,
  p_recurrence_type public.recurrence_type default 'none',
  p_recurrence_end_date date default null,
  p_refreshment_ids bigint[] default '{}'::bigint[]
)
returns uuid
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_profile public.profiles%rowtype;
  v_meeting_id uuid := gen_random_uuid();
  v_request_code text;
  v_item record;
  v_count integer;
begin
  perform private.require_active_user();
  select * into strict v_profile from public.profiles where id = auth.uid();

  if v_profile.organization_id is null then
    raise exception 'El usuario debe tener una organización asignada.';
  end if;
  if p_attendees is null or p_attendees <= 0 then
    raise exception 'La cantidad de asistentes debe ser mayor que cero.';
  end if;
  if not exists (select 1 from public.meeting_types where id = p_meeting_type_id and is_active) then
    raise exception 'Tipo de reunión inválido o inactivo.';
  end if;
  if not exists (select 1 from public.configuration_types where id = p_configuration_type_id and is_active) then
    raise exception 'Configuración inválida o inactiva.';
  end if;
  if jsonb_typeof(p_occurrences) <> 'array' then
    raise exception 'p_occurrences debe ser un arreglo JSON.';
  end if;
  v_count := jsonb_array_length(p_occurrences);
  if v_count < 1 or v_count > 100 then
    raise exception 'La solicitud debe incluir entre 1 y 100 fechas.';
  end if;
  if p_recurrence_type = 'none' and v_count <> 1 then
    raise exception 'Una reunión no recurrente debe contener exactamente una fecha.';
  end if;
  if p_recurrence_type <> 'none' and v_count < 2 then
    raise exception 'Una reunión recurrente debe contener al menos dos fechas.';
  end if;
  if exists (
    with parsed_occurrences as (
      select item.ord,
             (item.value->>'starts_at')::timestamptz as starts_at,
             (item.value->>'ends_at')::timestamptz as ends_at
      from jsonb_array_elements(p_occurrences) with ordinality as item(value,ord)
    )
    select 1 from parsed_occurrences a
    join parsed_occurrences b on b.ord > a.ord
    where a.ends_at > b.starts_at and b.ends_at > a.starts_at
  ) then
    raise exception 'Dos fechas de la misma solicitud se traslapan entre sí.';
  end if;

  if coalesce(array_length(p_refreshment_ids, 1), 0) > 0 then
    if not exists (
      select 1 from public.organizations o
      where o.id = v_profile.organization_id and o.can_request_refreshments and o.is_active
    ) then
      raise exception 'La refacción solo está disponible para usuarios de Cengicaña.';
    end if;
    if exists (
      select 1 from unnest(p_refreshment_ids) x(id)
      left join public.refreshments r on r.id = x.id and r.is_active
      where r.id is null
    ) then
      raise exception 'Una o más opciones de refacción son inválidas.';
    end if;
  end if;

  for v_item in
    select x.space_id, x.starts_at, x.ends_at
    from jsonb_to_recordset(p_occurrences) as x(space_id bigint, starts_at timestamptz, ends_at timestamptz)
  loop
    if v_item.space_id is null or v_item.starts_at is null or v_item.ends_at is null or v_item.ends_at <= v_item.starts_at then
      raise exception 'Cada fecha debe incluir space_id, starts_at y ends_at válidos.';
    end if;
    if p_recurrence_end_date is not null
       and (v_item.starts_at at time zone 'America/Guatemala')::date > p_recurrence_end_date then
      raise exception 'Una fecha excede la fecha final de recurrencia.';
    end if;
    perform private.validate_space_capacity(v_item.space_id, p_configuration_type_id, p_attendees);
    if not private.space_is_available(v_item.space_id, v_item.starts_at, v_item.ends_at, null) then
      raise exception 'El espacio % ya está ocupado entre % y %.', v_item.space_id, v_item.starts_at, v_item.ends_at;
    end if;
  end loop;

  v_request_code := 'SF-' || to_char(now() at time zone 'America/Guatemala', 'YYYYMMDD') || '-' || upper(substr(replace(v_meeting_id::text, '-', ''), 1, 8));

  insert into public.meetings (
    id, request_code, requested_by, requester_name, requester_email,
    request_organization_id, request_department_id, meeting_type_id,
    configuration_type_id, title, description, attendees,
    recurrence_type, recurrence_end_date
  ) values (
    v_meeting_id, v_request_code, auth.uid(), v_profile.full_name, auth.jwt() ->> 'email',
    v_profile.organization_id, v_profile.department_id, p_meeting_type_id,
    p_configuration_type_id, btrim(p_title), nullif(btrim(p_description), ''), p_attendees,
    p_recurrence_type, p_recurrence_end_date
  );

  insert into public.meeting_refreshments (meeting_id, refreshment_id)
  select v_meeting_id, distinct_ids.id
  from (select distinct unnest(p_refreshment_ids) as id) distinct_ids;

  for v_item in
    select x.space_id, x.starts_at, x.ends_at
    from jsonb_to_recordset(p_occurrences) as x(space_id bigint, starts_at timestamptz, ends_at timestamptz)
    order by x.starts_at
  loop
    declare v_occurrence_id uuid := gen_random_uuid();
    begin
      insert into public.meeting_occurrences (id, meeting_id, space_id, starts_at, ends_at)
      values (v_occurrence_id, v_meeting_id, v_item.space_id, v_item.starts_at, v_item.ends_at);

      insert into public.meeting_occurrence_status_history
        (occurrence_id, from_status, to_status, reason, changed_by, actor_name, actor_role)
      values
        (v_occurrence_id, null, 'pending', 'Solicitud creada', auth.uid(), v_profile.full_name, v_profile.role);

      insert into public.meeting_occurrence_space_history
        (occurrence_id, old_space_id, new_space_id, changed_by, actor_name, actor_role, reason)
      values
        (v_occurrence_id, null, v_item.space_id, auth.uid(), v_profile.full_name, v_profile.role, 'Espacio solicitado');
    end;
  end loop;

  insert into public.audit_log (actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), v_profile.role, 'create_request', 'meeting', v_meeting_id::text,
          jsonb_build_object('request_code', v_request_code, 'occurrences', v_count));
  insert into public.board_events(event_type,entity_type,entity_id)
  values ('request','meeting',v_meeting_id::text);
  return v_meeting_id;
end;
$$;

create or replace function public.approve_occurrence(
  p_occurrence_id uuid,
  p_apply_to_series boolean default false
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_target public.meeting_occurrences%rowtype;
  v_occ record;
  v_count integer := 0;
begin
  perform private.require_admin();
  select * into strict v_target from public.meeting_occurrences where id = p_occurrence_id for update;
  if v_target.status not in ('pending', 'rejected') then
    raise exception 'Solo se pueden aprobar solicitudes pendientes o rechazadas.';
  end if;

  for v_occ in
    select o.*, m.configuration_type_id, m.attendees
    from public.meeting_occurrences o
    join public.meetings m on m.id = o.meeting_id
    where (o.id = p_occurrence_id or (p_apply_to_series and o.meeting_id = v_target.meeting_id))
      and o.status in ('pending', 'rejected')
    order by o.starts_at
    for update of o
  loop
    perform private.validate_space_capacity(v_occ.space_id, v_occ.configuration_type_id, v_occ.attendees);

    begin
      insert into public.room_blocks (room_id, occurrence_id, block_type, starts_at, ends_at)
      select sr.room_id, v_occ.id, 'meeting', v_occ.starts_at, v_occ.ends_at
      from public.space_rooms sr where sr.space_id = v_occ.space_id;
    exception when exclusion_violation then
      raise exception 'No se puede aprobar: uno de los salones físicos ya está ocupado en ese horario.';
    end;

    update public.meeting_occurrences set status = 'approved' where id = v_occ.id;
    insert into public.meeting_occurrence_status_history
      (occurrence_id, from_status, to_status, reason, changed_by, actor_name, actor_role)
    values (v_occ.id, v_occ.status, 'approved', 'Aprobada por administración', auth.uid(), private.actor_name(), 'admin');
    insert into public.board_events(event_type, entity_type, entity_id)
    values ('upsert', 'meeting_occurrence', v_occ.id::text);
    v_count := v_count + 1;
  end loop;

  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), 'admin', 'approve', 'meeting_occurrence', p_occurrence_id::text, jsonb_build_object('affected', v_count));
  return v_count;
end;
$$;

create or replace function public.reject_occurrence(
  p_occurrence_id uuid,
  p_reason text,
  p_apply_to_series boolean default false
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_target public.meeting_occurrences%rowtype;
  v_occ record;
  v_count integer := 0;
begin
  perform private.require_admin();
  if nullif(btrim(p_reason), '') is null then raise exception 'Debe indicar el motivo del rechazo.'; end if;
  select * into strict v_target from public.meeting_occurrences where id = p_occurrence_id for update;
  if v_target.status <> 'pending' then raise exception 'Solo se pueden rechazar solicitudes pendientes.'; end if;

  for v_occ in
    select o.* from public.meeting_occurrences o
    where (o.id = p_occurrence_id or (p_apply_to_series and o.meeting_id = v_target.meeting_id))
      and o.status = 'pending'
    order by o.starts_at for update
  loop
    update public.meeting_occurrences set status = 'rejected' where id = v_occ.id;
    insert into public.meeting_occurrence_status_history
      (occurrence_id, from_status, to_status, reason, changed_by, actor_name, actor_role)
    values (v_occ.id, 'pending', 'rejected', btrim(p_reason), auth.uid(), private.actor_name(), 'admin');
    insert into public.board_events(event_type, entity_type, entity_id)
    values ('remove', 'meeting_occurrence', v_occ.id::text);
    v_count := v_count + 1;
  end loop;
  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), 'admin', 'reject', 'meeting_occurrence', p_occurrence_id::text,
          jsonb_build_object('affected', v_count, 'reason', btrim(p_reason)));
  return v_count;
end;
$$;

create or replace function public.cancel_occurrence(
  p_occurrence_id uuid,
  p_reason text default null,
  p_apply_to_series boolean default false
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_target public.meeting_occurrences%rowtype;
  v_owner uuid;
  v_occ record;
  v_role public.app_role;
  v_count integer := 0;
begin
  perform private.require_active_user();
  select o.* into strict v_target
  from public.meeting_occurrences o
  where o.id = p_occurrence_id for update;
  select m.requested_by into v_owner
  from public.meetings m where m.id = v_target.meeting_id;
  if not private.is_admin() and v_owner is distinct from auth.uid() then
    raise exception using errcode = '42501', message = 'Solo puede cancelar sus propias reuniones.';
  end if;
  if v_target.status not in ('pending', 'approved') then
    raise exception 'Solo se pueden cancelar solicitudes pendientes o aprobadas.';
  end if;
  v_role := private.current_role();

  for v_occ in
    select o.* from public.meeting_occurrences o
    where (o.id = p_occurrence_id or (p_apply_to_series and o.meeting_id = v_target.meeting_id))
      and o.status in ('pending', 'approved')
    order by o.starts_at for update
  loop
    update public.room_blocks set active = false where occurrence_id = v_occ.id and active;
    update public.meeting_occurrences set status = 'cancelled' where id = v_occ.id;
    insert into public.meeting_occurrence_status_history
      (occurrence_id, from_status, to_status, reason, changed_by, actor_name, actor_role)
    values (v_occ.id, v_occ.status, 'cancelled', coalesce(nullif(btrim(p_reason), ''), 'Cancelada'),
            auth.uid(), private.actor_name(), v_role);
    insert into public.board_events(event_type, entity_type, entity_id)
    values ('remove', 'meeting_occurrence', v_occ.id::text);
    v_count := v_count + 1;
  end loop;
  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), v_role, 'cancel', 'meeting_occurrence', p_occurrence_id::text,
          jsonb_build_object('affected', v_count, 'reason', p_reason));
  return v_count;
end;
$$;

create or replace function public.move_occurrence(
  p_occurrence_id uuid,
  p_new_space_id bigint,
  p_reason text default null,
  p_apply_to_series boolean default false
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_target public.meeting_occurrences%rowtype;
  v_occ record;
  v_count integer := 0;
begin
  perform private.require_admin();
  select * into strict v_target from public.meeting_occurrences where id = p_occurrence_id for update;
  if v_target.status = 'cancelled' then raise exception 'No se puede mover una reunión cancelada.'; end if;

  for v_occ in
    select o.*, m.configuration_type_id, m.attendees
    from public.meeting_occurrences o join public.meetings m on m.id = o.meeting_id
    where (o.id = p_occurrence_id or (p_apply_to_series and o.meeting_id = v_target.meeting_id))
      and o.status <> 'cancelled'
    order by o.starts_at for update of o
  loop
    perform private.validate_space_capacity(p_new_space_id, v_occ.configuration_type_id, v_occ.attendees);
    if v_occ.status = 'approved' then
      update public.room_blocks set active = false where occurrence_id = v_occ.id and active;
      begin
        insert into public.room_blocks (room_id, occurrence_id, block_type, starts_at, ends_at)
        select sr.room_id, v_occ.id, 'meeting', v_occ.starts_at, v_occ.ends_at
        from public.space_rooms sr where sr.space_id = p_new_space_id;
      exception when exclusion_violation then
        raise exception 'No se puede mover: uno de los salones físicos ya está ocupado en ese horario.';
      end;
    end if;

    update public.meeting_occurrences set space_id = p_new_space_id where id = v_occ.id;
    insert into public.meeting_occurrence_space_history
      (occurrence_id, old_space_id, new_space_id, changed_by, actor_name, actor_role, reason)
    values (v_occ.id, v_occ.space_id, p_new_space_id, auth.uid(), private.actor_name(), 'admin',
            coalesce(nullif(btrim(p_reason), ''), 'Cambio de salón'));
    insert into public.board_events(event_type, entity_type, entity_id)
    values ('upsert', 'meeting_occurrence', v_occ.id::text);
    v_count := v_count + 1;
  end loop;
  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), 'admin', 'move', 'meeting_occurrence', p_occurrence_id::text,
          jsonb_build_object('new_space_id', p_new_space_id, 'affected', v_count, 'reason', p_reason));
  return v_count;
end;
$$;

create or replace function public.schedule_maintenance(
  p_room_id bigint,
  p_starts_at timestamptz,
  p_ends_at timestamptz,
  p_reason text,
  p_all_day boolean default false,
  p_title text default 'Mantenimiento'
)
returns uuid
language plpgsql
security definer
set search_path = ''
as $$
declare v_id uuid := gen_random_uuid();
begin
  perform private.require_admin();
  if p_ends_at <= p_starts_at then raise exception 'La fecha final debe ser posterior a la inicial.'; end if;
  if nullif(btrim(p_reason), '') is null then raise exception 'Debe indicar el motivo del mantenimiento.'; end if;
  if not exists (select 1 from public.rooms where id = p_room_id and is_active) then raise exception 'Salón inválido o inactivo.'; end if;

  insert into public.maintenance_events(id, room_id, title, reason, starts_at, ends_at, all_day, created_by)
  values (v_id, p_room_id, coalesce(nullif(btrim(p_title), ''), 'Mantenimiento'), btrim(p_reason),
          p_starts_at, p_ends_at, p_all_day, auth.uid());
  begin
    insert into public.room_blocks(room_id, maintenance_id, block_type, starts_at, ends_at)
    values (p_room_id, v_id, 'maintenance', p_starts_at, p_ends_at);
  exception when exclusion_violation then
    raise exception 'No se puede agendar el mantenimiento: el salón ya está ocupado.';
  end;
  insert into public.board_events(event_type, entity_type, entity_id) values ('upsert', 'maintenance', v_id::text);
  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), 'admin', 'schedule', 'maintenance', v_id::text,
          jsonb_build_object('room_id', p_room_id, 'starts_at', p_starts_at, 'ends_at', p_ends_at));
  return v_id;
end;
$$;

create or replace function public.cancel_maintenance(p_maintenance_id uuid, p_reason text default null)
returns void
language plpgsql
security definer
set search_path = ''
as $$
begin
  perform private.require_admin();
  if not exists (select 1 from public.maintenance_events where id = p_maintenance_id and status = 'scheduled' for update) then
    raise exception 'El mantenimiento no existe o ya no está programado.';
  end if;
  update public.room_blocks set active = false where maintenance_id = p_maintenance_id and active;
  update public.maintenance_events
    set status = 'cancelled', cancelled_by = auth.uid(), cancelled_at = now()
    where id = p_maintenance_id;
  insert into public.board_events(event_type, entity_type, entity_id) values ('remove', 'maintenance', p_maintenance_id::text);
  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), 'admin', 'cancel', 'maintenance', p_maintenance_id::text, jsonb_build_object('reason', p_reason));
end;
$$;

create or replace function public.admin_create_room(
  p_name text,
  p_location text,
  p_u_capacity integer,
  p_school_capacity integer,
  p_description text default null
)
returns bigint
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_room_id bigint;
  v_space_id bigint;
  v_code text := 'ROOM_' || upper(substr(md5(gen_random_uuid()::text), 1, 8));
  v_u_id bigint;
  v_school_id bigint;
begin
  perform private.require_admin();
  if nullif(btrim(p_name), '') is null then raise exception 'El nombre es obligatorio.'; end if;
  if p_u_capacity <= 0 or p_school_capacity <= 0 then raise exception 'Las capacidades deben ser mayores que cero.'; end if;
  select id into strict v_u_id from public.configuration_types where code = 'U';
  select id into strict v_school_id from public.configuration_types where code = 'SCHOOL';
  insert into public.rooms(code, name, location, description, created_by)
  values (v_code, btrim(p_name), nullif(btrim(p_location), ''), nullif(btrim(p_description), ''), auth.uid())
  returning id into v_room_id;
  insert into public.spaces(code, name, space_type, created_by)
  values ('SPACE_' || substr(v_code, 6), btrim(p_name), 'single', auth.uid()) returning id into v_space_id;
  insert into public.space_rooms(space_id, room_id) values (v_space_id, v_room_id);
  insert into public.space_capacities(space_id, configuration_type_id, capacity)
  values (v_space_id, v_u_id, p_u_capacity), (v_space_id, v_school_id, p_school_capacity);
  insert into public.audit_log(actor_id, actor_role, action, entity_type, entity_id, new_data)
  values (auth.uid(), 'admin', 'create', 'room', v_room_id::text, jsonb_build_object('name', btrim(p_name)));
  insert into public.board_events(event_type,entity_type,entity_id)
  values ('upsert','room',v_room_id::text);
  return v_room_id;
end;
$$;

create or replace function public.admin_update_room(
  p_room_id bigint,
  p_name text,
  p_location text,
  p_description text,
  p_is_active boolean,
  p_u_capacity integer,
  p_school_capacity integer
)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare v_space_id bigint; v_u_id bigint; v_school_id bigint;
begin
  perform private.require_admin();
  if nullif(btrim(p_name), '') is null then raise exception 'El nombre es obligatorio.'; end if;
  if p_u_capacity <= 0 or p_school_capacity <= 0 then raise exception 'Las capacidades deben ser mayores que cero.'; end if;
  if not p_is_active and exists (
    select 1 from public.room_blocks where room_id = p_room_id and active and ends_at > now()
  ) then raise exception 'No puede desactivar un salón con reservas o mantenimientos futuros.'; end if;
  update public.rooms set name=btrim(p_name), location=nullif(btrim(p_location), ''),
    description=nullif(btrim(p_description), ''), is_active=p_is_active where id=p_room_id;
  if not found then raise exception 'Salón no encontrado.'; end if;
  select sr.space_id into strict v_space_id from public.space_rooms sr join public.spaces s on s.id=sr.space_id
    where sr.room_id=p_room_id and s.space_type='single';
  update public.spaces set name=btrim(p_name), is_active=p_is_active where id=v_space_id;
  -- Si el salón pertenece a un espacio combinado, su disponibilidad también se actualiza.
  update public.spaces s
  set is_active = not exists (
    select 1 from public.space_rooms sr
    join public.rooms r on r.id=sr.room_id
    where sr.space_id=s.id and not r.is_active
  )
  where exists (select 1 from public.space_rooms sr where sr.space_id=s.id and sr.room_id=p_room_id);
  select id into strict v_u_id from public.configuration_types where code='U';
  select id into strict v_school_id from public.configuration_types where code='SCHOOL';
  insert into public.space_capacities(space_id, configuration_type_id, capacity)
    values (v_space_id,v_u_id,p_u_capacity),(v_space_id,v_school_id,p_school_capacity)
    on conflict (space_id,configuration_type_id) do update set capacity=excluded.capacity;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
    values(auth.uid(),'admin','update','room',p_room_id::text,
      jsonb_build_object('name',btrim(p_name),'is_active',p_is_active));
  insert into public.board_events(event_type,entity_type,entity_id)
    values('upsert','room',p_room_id::text);
end;
$$;

-- Snapshot público del tablero. Devuelve una fila por salón físico; una reserva combinada ocupa dos filas.
create or replace function public.get_public_board(p_date date default ((now() at time zone 'America/Guatemala')::date))
returns table (
  item_type text, item_id uuid, room_id bigint, room_name text, space_name text,
  title text, details text, starts_at timestamptz, ends_at timestamptz, status text,
  configuration_name text, meeting_type_name text, attendees integer, requester_name text
)
language sql
stable
security definer
set search_path = ''
as $$
  with bounds as (
    select p_date::timestamp at time zone 'America/Guatemala' as day_start,
           (p_date + 1)::timestamp at time zone 'America/Guatemala' as day_end
  )
  select 'meeting', o.id, r.id, r.name, s.name, m.title, m.description,
         o.starts_at, o.ends_at, o.status::text, ct.name, mt.name, m.attendees, m.requester_name
  from public.meeting_occurrences o
  join public.meetings m on m.id=o.meeting_id
  join public.spaces s on s.id=o.space_id
  join public.space_rooms sr on sr.space_id=s.id
  join public.rooms r on r.id=sr.room_id
  join public.configuration_types ct on ct.id=m.configuration_type_id
  join public.meeting_types mt on mt.id=m.meeting_type_id
  cross join bounds b
  where o.status='approved' and o.starts_at < b.day_end and o.ends_at > b.day_start
  union all
  select 'maintenance', me.id, r.id, r.name, r.name, me.title, me.reason,
         me.starts_at, me.ends_at, me.status::text, null, null, null, null
  from public.maintenance_events me
  join public.rooms r on r.id=me.room_id
  cross join bounds b
  where me.status='scheduled' and me.starts_at < b.day_end and me.ends_at > b.day_start
  union all
  select 'room', null::uuid, r.id, r.name, r.name, null::text, r.location,
         null::timestamptz, null::timestamptz, case when r.is_active then 'active' else 'inactive' end,
         null::text, null::text, null::integer, null::text
  from public.rooms r
  where r.is_active
  order by 3, 8;
$$;

-- Agenda autenticada: administradores ven todo; usuarios ven aprobadas y sus propias solicitudes.
create or replace function public.get_agenda(p_date date default ((now() at time zone 'America/Guatemala')::date))
returns table (
  occurrence_id uuid, meeting_id uuid, request_code text, title text, space_id bigint,
  space_name text, starts_at timestamptz, ends_at timestamptz, status public.meeting_status,
  requester_name text, meeting_type_name text, configuration_name text, attendees integer
)
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  perform private.require_active_user();
  return query
  with bounds as (
    select p_date::timestamp at time zone 'America/Guatemala' as day_start,
           (p_date + 1)::timestamp at time zone 'America/Guatemala' as day_end
  )
  select o.id,m.id,m.request_code,m.title,s.id,s.name,o.starts_at,o.ends_at,o.status,
         m.requester_name,mt.name,ct.name,m.attendees
  from public.meeting_occurrences o
  join public.meetings m on m.id=o.meeting_id
  join public.spaces s on s.id=o.space_id
  join public.meeting_types mt on mt.id=m.meeting_type_id
  join public.configuration_types ct on ct.id=m.configuration_type_id
  cross join bounds b
  where o.starts_at < b.day_end and o.ends_at > b.day_start
    and (private.is_admin() or o.status='approved' or m.requested_by=auth.uid())
  order by o.starts_at,s.name;
end;
$$;

-- Permisos de ejecución explícitos: las tablas sensibles no se modifican directamente desde el cliente.
revoke all on function public.handle_new_user() from public, anon, authenticated;
revoke all on function public.check_space_availability(bigint,timestamptz,timestamptz,uuid) from public, anon, authenticated;
revoke all on function public.create_meeting_request(text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]) from public, anon, authenticated;
revoke all on function public.approve_occurrence(uuid,boolean) from public, anon, authenticated;
revoke all on function public.reject_occurrence(uuid,text,boolean) from public, anon, authenticated;
revoke all on function public.cancel_occurrence(uuid,text,boolean) from public, anon, authenticated;
revoke all on function public.move_occurrence(uuid,bigint,text,boolean) from public, anon, authenticated;
revoke all on function public.schedule_maintenance(bigint,timestamptz,timestamptz,text,boolean,text) from public, anon, authenticated;
revoke all on function public.cancel_maintenance(uuid,text) from public, anon, authenticated;
revoke all on function public.admin_create_room(text,text,integer,integer,text) from public, anon, authenticated;
revoke all on function public.admin_update_room(bigint,text,text,text,boolean,integer,integer) from public, anon, authenticated;
revoke all on function public.get_public_board(date) from public, anon, authenticated;
revoke all on function public.get_agenda(date) from public, anon, authenticated;

grant execute on function public.check_space_availability(bigint,timestamptz,timestamptz,uuid) to authenticated;
grant execute on function public.create_meeting_request(text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]) to authenticated;
grant execute on function public.approve_occurrence(uuid,boolean) to authenticated;
grant execute on function public.reject_occurrence(uuid,text,boolean) to authenticated;
grant execute on function public.cancel_occurrence(uuid,text,boolean) to authenticated;
grant execute on function public.move_occurrence(uuid,bigint,text,boolean) to authenticated;
grant execute on function public.schedule_maintenance(bigint,timestamptz,timestamptz,text,boolean,text) to authenticated;
grant execute on function public.cancel_maintenance(uuid,text) to authenticated;
grant execute on function public.admin_create_room(text,text,integer,integer,text) to authenticated;
grant execute on function public.admin_update_room(bigint,text,text,text,boolean,integer,integer) to authenticated;
grant execute on function public.get_public_board(date) to anon, authenticated;
grant execute on function public.get_agenda(date) to authenticated;

revoke all on function private.is_admin() from public, anon, authenticated;
revoke all on function private.current_role() from public, anon, authenticated;
revoke all on function private.actor_name() from public, anon, authenticated;
revoke all on function private.require_active_user() from public, anon, authenticated;
revoke all on function private.require_admin() from public, anon, authenticated;
revoke all on function private.validate_space_capacity(bigint,bigint,integer) from public, anon, authenticated;
revoke all on function private.space_is_available(bigint,timestamptz,timestamptz,uuid) from public, anon, authenticated;
grant usage on schema private to authenticated;
grant execute on function private.is_admin() to authenticated;

commit;
