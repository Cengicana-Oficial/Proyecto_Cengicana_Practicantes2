-- CengiSalas / Supabase
-- 15: códigos cortos y seguimiento público únicamente por código.
-- Conserva compatibilidad con códigos SF anteriores.

begin;

create or replace function private.generate_request_code()
returns text
language plpgsql
volatile
security definer
set search_path=''
as $$
declare v_code text;
begin
  loop
    v_code:='CS-'||upper(substr(replace(gen_random_uuid()::text,'-',''),1,12));
    exit when not exists(select 1 from public.meetings where request_code=v_code);
  end loop;
  return v_code;
end;
$$;

create or replace function private.create_request_core(
  p_requested_by uuid,p_requester_name text,p_requester_email text,p_organization_id bigint,p_department_id bigint,
  p_actor_role public.app_role,p_source text,p_contact_name text,p_contact_email text,p_contact_phone text,
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type,p_recurrence_end_date date,p_refreshment_ids bigint[]
)
returns table(meeting_id uuid,request_code text)
language plpgsql
security definer
set search_path=''
as $$
declare
  v_meeting_id uuid:=gen_random_uuid();
  v_request_code text;
  v_item record;
  v_count integer;
  v_occurrence_id uuid;
begin
  if p_source not in ('registered','public') then raise exception 'Origen de solicitud inválido.'; end if;
  if not exists(select 1 from public.organizations o where o.id=p_organization_id and o.is_active) then
    raise exception 'Organización inválida o inactiva.';
  end if;
  if nullif(btrim(p_contact_name),'') is null or length(btrim(p_contact_name))<3 then
    raise exception 'Debe indicar el nombre del responsable del salón.';
  end if;
  if nullif(btrim(p_contact_email),'') is null or btrim(p_contact_email)!~* '^[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+$' then
    raise exception 'Debe indicar un correo electrónico válido.';
  end if;
  if nullif(btrim(p_contact_phone),'') is null or length(regexp_replace(p_contact_phone,'[^0-9]','','g')) not between 7 and 15 then
    raise exception 'Debe indicar un teléfono válido de 7 a 15 dígitos.';
  end if;
  if nullif(btrim(p_title),'') is null or length(btrim(p_title)) not between 3 and 180 then
    raise exception 'El título debe contener entre 3 y 180 caracteres.';
  end if;
  if p_attendees is null or p_attendees<=0 then raise exception 'La cantidad de asistentes debe ser mayor que cero.'; end if;
  if not exists(select 1 from public.meeting_types where id=p_meeting_type_id and is_active) then
    raise exception 'Tipo de reunión inválido o inactivo.';
  end if;
  if not exists(select 1 from public.configuration_types where id=p_configuration_type_id and is_active) then
    raise exception 'Configuración inválida o inactiva.';
  end if;
  if jsonb_typeof(p_occurrences)<>'array' then raise exception 'Las fechas deben enviarse como un arreglo JSON.'; end if;
  v_count:=jsonb_array_length(p_occurrences);
  if v_count<1 or v_count>100 then raise exception 'La solicitud debe incluir entre 1 y 100 fechas.'; end if;
  if p_recurrence_type='none' and v_count<>1 then raise exception 'Una reunión no recurrente debe contener exactamente una fecha.'; end if;
  if p_recurrence_type<>'none' and v_count<2 then raise exception 'Una reunión recurrente debe contener al menos dos fechas.'; end if;

  if exists(
    with parsed_occurrences as(
      select item.ord,(item.value->>'starts_at')::timestamptz starts_at,(item.value->>'ends_at')::timestamptz ends_at
      from jsonb_array_elements(p_occurrences) with ordinality as item(value,ord)
    )
    select 1 from parsed_occurrences a join parsed_occurrences b on b.ord>a.ord
    where a.ends_at>b.starts_at and b.ends_at>a.starts_at
  ) then raise exception 'Dos fechas de la misma solicitud se traslapan entre sí.'; end if;

  if coalesce(array_length(p_refreshment_ids,1),0)>0 and exists(
    select 1 from unnest(p_refreshment_ids) x(id)
    left join public.refreshments r on r.id=x.id and r.is_active where r.id is null
  ) then raise exception 'Una o más opciones de refacción son inválidas.'; end if;

  for v_item in
    select x.space_id,x.starts_at,x.ends_at
    from jsonb_to_recordset(p_occurrences) as x(space_id bigint,starts_at timestamptz,ends_at timestamptz)
  loop
    if v_item.space_id is null or v_item.starts_at is null or v_item.ends_at is null or v_item.ends_at<=v_item.starts_at then
      raise exception 'Cada fecha debe incluir espacio, inicio y finalización válidos.';
    end if;
    if p_recurrence_end_date is not null and (v_item.starts_at at time zone 'America/Guatemala')::date>p_recurrence_end_date then
      raise exception 'Una fecha excede la fecha final de recurrencia.';
    end if;
    perform private.validate_space_capacity(v_item.space_id,p_configuration_type_id,p_attendees);
    if not private.space_is_available(v_item.space_id,v_item.starts_at,v_item.ends_at,null) then
      raise exception 'El espacio solicitado ya está ocupado en una de las fechas.';
    end if;
  end loop;

  v_request_code:=private.generate_request_code();
  insert into public.meetings(
    id,request_code,requested_by,requester_name,requester_email,request_organization_id,request_department_id,
    meeting_type_id,configuration_type_id,title,description,attendees,recurrence_type,recurrence_end_date,
    contact_name,contact_email,contact_phone,request_source
  ) values(
    v_meeting_id,v_request_code,p_requested_by,btrim(p_requester_name),lower(btrim(p_requester_email)),p_organization_id,p_department_id,
    p_meeting_type_id,p_configuration_type_id,btrim(p_title),nullif(btrim(p_description),''),p_attendees,p_recurrence_type,p_recurrence_end_date,
    btrim(p_contact_name),lower(btrim(p_contact_email)),btrim(p_contact_phone),p_source
  );

  insert into public.meeting_refreshments(meeting_id,refreshment_id)
  select v_meeting_id,ids.id from(select distinct unnest(p_refreshment_ids) id) ids;

  for v_item in
    select x.space_id,x.starts_at,x.ends_at
    from jsonb_to_recordset(p_occurrences) as x(space_id bigint,starts_at timestamptz,ends_at timestamptz)
    order by x.starts_at
  loop
    v_occurrence_id:=gen_random_uuid();
    insert into public.meeting_occurrences(id,meeting_id,space_id,starts_at,ends_at)
    values(v_occurrence_id,v_meeting_id,v_item.space_id,v_item.starts_at,v_item.ends_at);
    insert into public.meeting_occurrence_status_history(occurrence_id,from_status,to_status,reason,changed_by,actor_name,actor_role)
    values(v_occurrence_id,null,'pending',case when p_source='public' then 'Solicitud pública creada' else 'Solicitud creada' end,
           p_requested_by,btrim(p_contact_name),p_actor_role);
    insert into public.meeting_occurrence_space_history(occurrence_id,old_space_id,new_space_id,changed_by,actor_name,actor_role,reason)
    values(v_occurrence_id,null,v_item.space_id,p_requested_by,btrim(p_contact_name),p_actor_role,'Espacio solicitado');
  end loop;

  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(p_requested_by,p_actor_role,case when p_source='public' then 'create_public_request' else 'create_request' end,
         'meeting',v_meeting_id::text,jsonb_build_object('request_code',v_request_code,'occurrences',v_count,'source',p_source));
  insert into public.board_events(event_type,entity_type,entity_id) values('request','meeting',v_meeting_id::text);
  return query select v_meeting_id,v_request_code;
end;
$$;

drop function if exists public.track_external_request(text,text);

create or replace function public.track_external_request(p_request_code text)
returns jsonb
language plpgsql
stable
security definer
set search_path=''
as $$
declare v_result jsonb; v_code text:=upper(btrim(coalesce(p_request_code,'')));
begin
  if v_code!~ '^(CS-[0-9A-F]{12}|SF-[0-9]{8}-[0-9A-F]{8})$' then
    return jsonb_build_object('found',false);
  end if;

  select jsonb_build_object(
    'found',true,'requestCode',m.request_code,'title',m.title,'organization',org.name,
    'meetingType',mt.name,'configuration',ct.name,'attendees',m.attendees,
    'recurrenceType',m.recurrence_type,'createdAt',m.created_at,
    'updatedAt',greatest(m.updated_at,coalesce(summary.last_change,m.updated_at)),
    'overallStatus',summary.overall_status,'summary',summary.status_counts,'occurrences',summary.occurrences
  ) into v_result
  from public.meetings m
  join public.organizations org on org.id=m.request_organization_id
  join public.meeting_types mt on mt.id=m.meeting_type_id
  join public.configuration_types ct on ct.id=m.configuration_type_id
  cross join lateral(
    select case when count(distinct o.status)=1 then min(o.status::text) else 'mixed' end overall_status,
      max(coalesce(last_status.changed_at,o.updated_at)) last_change,
      jsonb_build_object(
        'pending',count(*) filter(where o.status='pending'),'approved',count(*) filter(where o.status='approved'),
        'rejected',count(*) filter(where o.status='rejected'),'cancelled',count(*) filter(where o.status='cancelled')
      ) status_counts,
      jsonb_agg(jsonb_build_object(
        'startsAt',o.starts_at,'endsAt',o.ends_at,'status',o.status,'spaceName',s.name,
        'configuration',ct.name,'statusAt',coalesce(last_status.changed_at,o.created_at),
        'reason',case when o.status in ('rejected','cancelled') then last_status.reason else null end
      ) order by o.starts_at) occurrences
    from public.meeting_occurrences o
    join public.spaces s on s.id=o.space_id
    left join lateral(
      select h.changed_at,h.reason from public.meeting_occurrence_status_history h
      where h.occurrence_id=o.id and h.to_status=o.status order by h.changed_at desc limit 1
    ) last_status on true
    where o.meeting_id=m.id
  ) summary
  where m.request_source='public' and upper(m.request_code)=v_code
  limit 1;

  return coalesce(v_result,jsonb_build_object('found',false));
end;
$$;

revoke all on function private.generate_request_code() from public,anon,authenticated;
revoke all on function private.create_request_core(uuid,text,text,bigint,bigint,public.app_role,text,text,text,text,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]) from public,anon,authenticated;
revoke all on function public.track_external_request(text) from public,anon,authenticated;
grant execute on function public.track_external_request(text) to anon,authenticated;

commit;
