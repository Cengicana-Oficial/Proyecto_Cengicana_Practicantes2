-- SalaFlow / Supabase
-- 10: corrige el procesamiento de fechas recurrentes en create_meeting_request.
-- Ejecutar una vez después de 09_user_management.sql. Es seguro volver a ejecutarlo.

begin;

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

revoke all on function public.create_meeting_request(
  text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]
) from public,anon,authenticated;
grant execute on function public.create_meeting_request(
  text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]
) to authenticated;

commit;
