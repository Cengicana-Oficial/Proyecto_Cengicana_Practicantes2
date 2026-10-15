-- CengiSalas / Supabase
-- 11: datos de contacto, salones exclusivos Tipo U y solicitudes públicas.
-- Ejecutar después de 10_fix_meeting_request_ordinality.sql.

begin;

alter table public.meetings add column if not exists contact_name text;
alter table public.meetings add column if not exists contact_email text;
alter table public.meetings add column if not exists contact_phone text;
alter table public.meetings add column if not exists request_source text not null default 'registered';

update public.meetings
set contact_name=coalesce(contact_name,requester_name),
    contact_email=coalesce(contact_email,requester_email)
where contact_name is null or contact_email is null;

do $$ begin
  alter table public.meetings add constraint meetings_request_source_check
    check (request_source in ('registered','public'));
exception when duplicate_object then null;
end $$;

-- Salones 5 y 8 solo admiten Tipo U.
delete from public.space_capacities sc
using public.spaces s,public.configuration_types ct
where sc.space_id=s.id and sc.configuration_type_id=ct.id
  and s.code in ('SPACE_5','SPACE_8') and ct.code='SCHOOL';

-- La regla también se valida en el servidor para que no pueda eludirse desde
-- otro cliente ni reactivando accidentalmente una capacidad Tipo Escuela.
create or replace function private.validate_space_capacity(
  p_space_id bigint,
  p_configuration_type_id bigint,
  p_attendees integer
)
returns void
language plpgsql
stable
security definer
set search_path=''
as $$
declare
  v_capacity integer;
begin
  if not exists(
    select 1
    from public.spaces s
    join public.space_rooms sr on sr.space_id=s.id
    where s.id=p_space_id and s.is_active
  ) or exists(
    select 1
    from public.space_rooms sr
    join public.rooms r on r.id=sr.room_id
    where sr.space_id=p_space_id and not r.is_active
  ) then
    raise exception 'El espacio no existe, está inactivo o contiene un salón físico inactivo.';
  end if;

  if exists(
    select 1
    from public.spaces s
    join public.configuration_types ct on ct.id=p_configuration_type_id
    where s.id=p_space_id
      and s.code in ('SPACE_5','SPACE_8')
      and ct.code='SCHOOL'
  ) then
    raise exception 'Los salones 5 y 8 únicamente admiten la configuración Tipo U.';
  end if;

  select sc.capacity into v_capacity
  from public.space_capacities sc
  join public.spaces s on s.id=sc.space_id
  where sc.space_id=p_space_id
    and sc.configuration_type_id=p_configuration_type_id
    and s.is_active;

  if v_capacity is null then
    raise exception 'El espacio no admite la configuración seleccionada.';
  end if;
  if p_attendees>v_capacity then
    raise exception 'La cantidad de asistentes (%) supera la capacidad del espacio (%).',p_attendees,v_capacity;
  end if;
end;
$$;

create or replace function private.create_request_core(
  p_requested_by uuid,
  p_requester_name text,
  p_requester_email text,
  p_organization_id bigint,
  p_department_id bigint,
  p_actor_role public.app_role,
  p_source text,
  p_contact_name text,
  p_contact_email text,
  p_contact_phone text,
  p_title text,
  p_description text,
  p_meeting_type_id bigint,
  p_configuration_type_id bigint,
  p_attendees integer,
  p_occurrences jsonb,
  p_recurrence_type public.recurrence_type,
  p_recurrence_end_date date,
  p_refreshment_ids bigint[]
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

  v_request_code:='SF-'||to_char(now() at time zone 'America/Guatemala','YYYYMMDD')||'-'||upper(substr(replace(v_meeting_id::text,'-',''),1,8));
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

drop function if exists public.create_meeting_request(text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]);
drop function if exists public.create_meeting_request(text,text,bigint,bigint,integer,text,text,text,jsonb,public.recurrence_type,date,bigint[]);

create function public.create_meeting_request(
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_contact_name text,p_contact_email text,p_contact_phone text,p_occurrences jsonb,
  p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_refreshment_ids bigint[] default '{}'::bigint[]
)
returns uuid
language plpgsql
security definer
set search_path=''
as $$
declare v_profile public.profiles%rowtype; v_id uuid;
begin
  perform private.require_active_user();
  select * into strict v_profile from public.profiles where id=auth.uid();
  if v_profile.organization_id is null then raise exception 'El usuario debe tener una organización asignada.'; end if;
  if coalesce(array_length(p_refreshment_ids,1),0)>0 and not exists(
    select 1 from public.organizations o where o.id=v_profile.organization_id and o.can_request_refreshments and o.is_active
  ) then raise exception 'La refacción solo está disponible para usuarios de Cengicaña.'; end if;
  select result.meeting_id into v_id
  from private.create_request_core(auth.uid(),v_profile.full_name,auth.jwt()->>'email',v_profile.organization_id,v_profile.department_id,
    v_profile.role,'registered',p_contact_name,p_contact_email,p_contact_phone,p_title,p_description,p_meeting_type_id,
    p_configuration_type_id,p_attendees,p_occurrences,p_recurrence_type,p_recurrence_end_date,p_refreshment_ids) result;
  return v_id;
end;
$$;

create or replace function public.get_public_request_catalogs()
returns jsonb
language sql
stable
security definer
set search_path=''
as $$
select jsonb_build_object(
  'organizations',coalesce((select jsonb_agg(jsonb_build_object('id',o.id,'code',o.code,'name',o.name) order by o.name)
    from public.organizations o where o.is_active),'[]'::jsonb),
  'meetingTypes',coalesce((select jsonb_agg(jsonb_build_object('id',m.id,'code',m.code,'name',m.name) order by m.id)
    from public.meeting_types m where m.is_active),'[]'::jsonb),
  'configurations',coalesce((select jsonb_agg(jsonb_build_object('id',c.id,'code',c.code,'name',c.name,'description',c.description) order by c.id)
    from public.configuration_types c where c.is_active),'[]'::jsonb),
  'rooms',coalesce((select jsonb_agg(room_item order by room_item->>'name') from(
    select jsonb_build_object('id',r.id,'code',r.code,'name',r.name,'location',r.location,'spaceId',s.id,
      'capacities',coalesce((select jsonb_object_agg(ct.code,sc.capacity) from public.space_capacities sc
        join public.configuration_types ct on ct.id=sc.configuration_type_id where sc.space_id=s.id),'{}'::jsonb)) room_item
    from public.rooms r join public.space_rooms sr on sr.room_id=r.id join public.spaces s on s.id=sr.space_id and s.space_type='single'
    where r.is_active and s.is_active
  ) room_rows),'[]'::jsonb),
  'combinedSpace',(select jsonb_build_object('id',s.id,'code',s.code,'name',s.name,
    'capacities',coalesce((select jsonb_object_agg(ct.code,sc.capacity) from public.space_capacities sc
      join public.configuration_types ct on ct.id=sc.configuration_type_id where sc.space_id=s.id),'{}'::jsonb))
    from public.spaces s where s.code='SPACE_1_2' and s.is_active limit 1)
);
$$;

create or replace function public.create_external_meeting_request(
  p_contact_name text,p_contact_email text,p_contact_phone text,p_organization_id bigint,
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_website text default null
)
returns text
language plpgsql
security definer
set search_path=''
as $$
declare v_code text;
begin
  if nullif(btrim(p_website),'') is not null then raise exception 'No fue posible procesar la solicitud.'; end if;
  if (select count(*) from public.meetings m where m.request_source='public'
      and lower(m.contact_email)=lower(btrim(p_contact_email)) and m.created_at>now()-interval '1 hour')>=5 then
    raise exception 'Se alcanzó el límite de solicitudes para este correo. Intenta nuevamente más tarde.';
  end if;
  select result.request_code into v_code
  from private.create_request_core(null,p_contact_name,p_contact_email,p_organization_id,null,'user','public',
    p_contact_name,p_contact_email,p_contact_phone,p_title,p_description,p_meeting_type_id,p_configuration_type_id,
    p_attendees,p_occurrences,p_recurrence_type,p_recurrence_end_date,'{}'::bigint[]) result;
  return v_code;
end;
$$;

drop view if exists public.vw_request_occurrences;
create view public.vw_request_occurrences with(security_invoker=true) as
select o.id occurrence_id,m.id meeting_id,m.request_code,m.title,m.description,m.requested_by,m.requester_name,m.requester_email,
  org.name organization_name,d.name department_name,mt.name meeting_type_name,ct.name configuration_name,m.attendees,
  m.recurrence_type,m.recurrence_end_date,s.id space_id,s.name space_name,o.starts_at,o.ends_at,o.status,o.created_at requested_at,
  approved.changed_at approved_at,rejected.changed_at rejected_at,rejected.reason rejection_reason,rejected.actor_name rejected_by_name,
  cancelled.changed_at cancelled_at,cancelled.reason cancellation_reason,cancelled.actor_role cancelled_by_role,
  cancelled.actor_name cancelled_by_name,moved.changed_at last_moved_at,old_space.name previous_space_name,moved.reason movement_reason,
  m.contact_name,m.contact_email,m.contact_phone,m.request_source
from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id
join public.organizations org on org.id=m.request_organization_id left join public.departments d on d.id=m.request_department_id
join public.meeting_types mt on mt.id=m.meeting_type_id join public.configuration_types ct on ct.id=m.configuration_type_id
join public.spaces s on s.id=o.space_id
left join lateral(select h.changed_at from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='approved' order by h.changed_at desc limit 1) approved on true
left join lateral(select h.changed_at,h.reason,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='rejected' order by h.changed_at desc limit 1) rejected on true
left join lateral(select h.changed_at,h.reason,h.actor_role,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='cancelled' order by h.changed_at desc limit 1) cancelled on true
left join lateral(select sh.old_space_id,sh.changed_at,sh.reason from public.meeting_occurrence_space_history sh where sh.occurrence_id=o.id and sh.old_space_id is not null order by sh.changed_at desc limit 1) moved on true
left join public.spaces old_space on old_space.id=moved.old_space_id;

revoke all on function private.create_request_core(uuid,text,text,bigint,bigint,public.app_role,text,text,text,text,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,bigint[]) from public,anon,authenticated;
revoke all on function private.validate_space_capacity(bigint,bigint,integer) from public,anon,authenticated;
revoke all on function public.create_meeting_request(text,text,bigint,bigint,integer,text,text,text,jsonb,public.recurrence_type,date,bigint[]) from public,anon,authenticated;
revoke all on function public.get_public_request_catalogs() from public,anon,authenticated;
revoke all on function public.create_external_meeting_request(text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text) from public,anon,authenticated;
revoke all on public.vw_request_occurrences from public,anon,authenticated;
grant execute on function public.create_meeting_request(text,text,bigint,bigint,integer,text,text,text,jsonb,public.recurrence_type,date,bigint[]) to authenticated;
grant execute on function public.get_public_request_catalogs() to anon,authenticated;
grant execute on function public.create_external_meeting_request(text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text) to anon,authenticated;
grant select on public.vw_request_occurrences to authenticated;

commit;
