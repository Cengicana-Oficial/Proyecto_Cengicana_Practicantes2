-- CengiSalas / Supabase
-- 25: salón, distribución y oferta comercial configurables por cada fecha de una serie.

begin;

alter table public.meeting_occurrences
  add column if not exists configuration_type_id bigint references public.configuration_types(id),
  add column if not exists paid_offering_code text references public.paid_space_offerings(code),
  add column if not exists quoted_amount numeric(12,2);

update public.meeting_occurrences o
set configuration_type_id=m.configuration_type_id
from public.meetings m
where m.id=o.meeting_id and o.configuration_type_id is null;

update public.meeting_occurrences o
set paid_offering_code=m.paid_offering_code
from public.meetings m
where m.id=o.meeting_id and o.paid_offering_code is null and m.request_source='paid';

alter table public.meeting_occurrences alter column configuration_type_id set not null;

do $$ begin
  if not exists(select 1 from pg_constraint where conname='meeting_occurrences_quoted_amount_nonnegative') then
    alter table public.meeting_occurrences add constraint meeting_occurrences_quoted_amount_nonnegative
      check(quoted_amount is null or quoted_amount>=0);
  end if;
end $$;

create or replace function private.create_request_core(
  p_requested_by uuid,p_requester_name text,p_requester_email text,p_organization_id bigint,p_department_id bigint,
  p_actor_role public.app_role,p_source text,p_contact_name text,p_contact_email text,p_contact_phone text,
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type,p_recurrence_end_date date,p_refreshment_ids bigint[]
)
returns table(meeting_id uuid,request_code text)
language plpgsql security definer set search_path='' as $$
declare v_meeting_id uuid:=gen_random_uuid();v_request_code text;v_item record;v_count integer;v_occurrence_id uuid;
begin
  if p_source not in ('registered','public') then raise exception 'Origen de solicitud inválido.'; end if;
  if not exists(select 1 from public.organizations where id=p_organization_id and is_active) then raise exception 'Organización inválida o inactiva.'; end if;
  if nullif(btrim(p_contact_name),'') is null or length(btrim(p_contact_name))<3 then raise exception 'Debe indicar el nombre del responsable del salón.'; end if;
  if nullif(btrim(p_contact_email),'') is null or btrim(p_contact_email)!~* '^[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+$' then raise exception 'Debe indicar un correo electrónico válido.'; end if;
  if nullif(btrim(p_contact_phone),'') is null or length(regexp_replace(p_contact_phone,'[^0-9]','','g')) not between 7 and 15 then raise exception 'Debe indicar un teléfono válido de 7 a 15 dígitos.'; end if;
  if nullif(btrim(p_title),'') is null or length(btrim(p_title)) not between 3 and 180 then raise exception 'El título debe contener entre 3 y 180 caracteres.'; end if;
  if p_attendees is null or p_attendees<=0 then raise exception 'La cantidad de asistentes debe ser mayor que cero.'; end if;
  if not exists(select 1 from public.meeting_types where id=p_meeting_type_id and is_active) then raise exception 'Tipo de reunión inválido o inactivo.'; end if;
  if not exists(select 1 from public.configuration_types where id=p_configuration_type_id and is_active) then raise exception 'Configuración inválida o inactiva.'; end if;
  if jsonb_typeof(p_occurrences)<>'array' then raise exception 'Las fechas deben enviarse como un arreglo JSON.'; end if;
  v_count:=jsonb_array_length(p_occurrences);
  if v_count<1 or v_count>100 then raise exception 'La solicitud debe incluir entre 1 y 100 fechas.'; end if;
  if p_recurrence_type='none' and v_count<>1 then raise exception 'Una reunión no recurrente debe contener exactamente una fecha.'; end if;
  if p_recurrence_type<>'none' and v_count<2 then raise exception 'Una reunión recurrente debe contener al menos dos fechas.'; end if;
  if exists(with parsed as(select item.ord,(item.value->>'starts_at')::timestamptz starts_at,(item.value->>'ends_at')::timestamptz ends_at from jsonb_array_elements(p_occurrences) with ordinality item(value,ord)) select 1 from parsed a join parsed b on b.ord>a.ord where a.ends_at>b.starts_at and b.ends_at>a.starts_at) then raise exception 'Dos fechas de la misma solicitud se traslapan entre sí.'; end if;
  if coalesce(array_length(p_refreshment_ids,1),0)>0 and exists(select 1 from unnest(p_refreshment_ids) x(id) left join public.refreshments r on r.id=x.id and r.is_active where r.id is null) then raise exception 'Una o más opciones de refacción son inválidas.'; end if;

  for v_item in select x.space_id,coalesce(x.configuration_type_id,p_configuration_type_id) configuration_type_id,x.starts_at,x.ends_at from jsonb_to_recordset(p_occurrences) as x(space_id bigint,configuration_type_id bigint,starts_at timestamptz,ends_at timestamptz)
  loop
    if v_item.space_id is null or v_item.configuration_type_id is null or v_item.starts_at is null or v_item.ends_at is null or v_item.ends_at<=v_item.starts_at then raise exception 'Cada fecha debe incluir espacio, distribución, inicio y finalización válidos.'; end if;
    if not exists(select 1 from public.configuration_types where id=v_item.configuration_type_id and is_active) then raise exception 'Una distribución seleccionada es inválida o está inactiva.'; end if;
    if p_recurrence_end_date is not null and (v_item.starts_at at time zone 'America/Guatemala')::date>p_recurrence_end_date then raise exception 'Una fecha excede la fecha final de recurrencia.'; end if;
    perform private.validate_space_capacity(v_item.space_id,v_item.configuration_type_id,p_attendees);
    if not private.space_is_available(v_item.space_id,v_item.starts_at,v_item.ends_at,null) then raise exception 'Uno de los salones seleccionados ya está ocupado en su horario.'; end if;
  end loop;

  v_request_code:=private.generate_request_code();
  insert into public.meetings(id,request_code,requested_by,requester_name,requester_email,request_organization_id,request_department_id,meeting_type_id,configuration_type_id,title,description,attendees,recurrence_type,recurrence_end_date,contact_name,contact_email,contact_phone,request_source)
  values(v_meeting_id,v_request_code,p_requested_by,btrim(p_requester_name),lower(btrim(p_requester_email)),p_organization_id,p_department_id,p_meeting_type_id,p_configuration_type_id,btrim(p_title),nullif(btrim(p_description),''),p_attendees,p_recurrence_type,p_recurrence_end_date,btrim(p_contact_name),lower(btrim(p_contact_email)),btrim(p_contact_phone),p_source);
  insert into public.meeting_refreshments(meeting_id,refreshment_id) select v_meeting_id,ids.id from(select distinct unnest(p_refreshment_ids) id) ids;

  for v_item in select x.space_id,coalesce(x.configuration_type_id,p_configuration_type_id) configuration_type_id,x.starts_at,x.ends_at from jsonb_to_recordset(p_occurrences) as x(space_id bigint,configuration_type_id bigint,starts_at timestamptz,ends_at timestamptz) order by x.starts_at
  loop
    v_occurrence_id:=gen_random_uuid();
    insert into public.meeting_occurrences(id,meeting_id,space_id,configuration_type_id,starts_at,ends_at) values(v_occurrence_id,v_meeting_id,v_item.space_id,v_item.configuration_type_id,v_item.starts_at,v_item.ends_at);
    insert into public.meeting_occurrence_status_history(occurrence_id,from_status,to_status,reason,changed_by,actor_name,actor_role) values(v_occurrence_id,null,'pending',case when p_source='public' then 'Solicitud pública creada' else 'Solicitud creada' end,p_requested_by,btrim(p_contact_name),p_actor_role);
    insert into public.meeting_occurrence_space_history(occurrence_id,old_space_id,new_space_id,changed_by,actor_name,actor_role,reason) values(v_occurrence_id,null,v_item.space_id,p_requested_by,btrim(p_contact_name),p_actor_role,'Espacio solicitado');
  end loop;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data) values(p_requested_by,p_actor_role,case when p_source='public' then 'create_public_request' else 'create_request' end,'meeting',v_meeting_id::text,jsonb_build_object('request_code',v_request_code,'occurrences',v_count,'source',p_source));
  insert into public.board_events(event_type,entity_type,entity_id) values('request','meeting',v_meeting_id::text);
  return query select v_meeting_id,v_request_code;
end;
$$;

create or replace function public.get_public_space_availability_by_occurrence(p_occurrences jsonb)
returns jsonb language plpgsql stable security definer set search_path='' as $$
declare v_item record;v_spaces jsonb;v_result jsonb:='[]'::jsonb;v_starts timestamptz;v_ends timestamptz;
begin
  if p_occurrences is null or jsonb_typeof(p_occurrences)<>'array' or jsonb_array_length(p_occurrences)=0 or jsonb_array_length(p_occurrences)>100 then return v_result; end if;
  for v_item in select value,ord from jsonb_array_elements(p_occurrences) with ordinality item(value,ord) order by ord loop
    v_starts:=(v_item.value->>'starts_at')::timestamptz;v_ends:=(v_item.value->>'ends_at')::timestamptz;
    if v_ends<=v_starts then v_spaces:='[]'::jsonb; else
      select coalesce(jsonb_agg(jsonb_build_object('id',s.id,'code',s.code,'name',s.name) order by s.name),'[]'::jsonb) into v_spaces
      from public.spaces s where s.is_active and private.space_is_available(s.id,v_starts,v_ends,null);
    end if;
    v_result:=v_result||jsonb_build_object('date',(v_starts at time zone 'America/Guatemala')::date,'startsAt',v_starts,'endsAt',v_ends,'spaces',v_spaces);
  end loop;
  return v_result;
end;
$$;

create or replace function public.get_paid_offering_availability_by_occurrence(p_occurrences jsonb)
returns jsonb language plpgsql stable security definer set search_path='' as $$
declare v_item record;v_offering record;v_space_id bigint;v_codes jsonb;v_result jsonb:='[]'::jsonb;v_starts timestamptz;v_ends timestamptz;
begin
  if p_occurrences is null or jsonb_typeof(p_occurrences)<>'array' or jsonb_array_length(p_occurrences)=0 or jsonb_array_length(p_occurrences)>100 then return v_result; end if;
  for v_item in select value,ord from jsonb_array_elements(p_occurrences) with ordinality item(value,ord) order by ord loop
    v_starts:=(v_item.value->>'starts_at')::timestamptz;v_ends:=(v_item.value->>'ends_at')::timestamptz;v_codes:='[]'::jsonb;
    if v_ends>v_starts then
      for v_offering in select code,allocation_mode from public.paid_space_offerings where is_active order by id loop
        v_space_id:=null;
        if v_offering.allocation_mode='combined' then select id into v_space_id from public.spaces where code='SPACE_1_2' and is_active;
        elsif v_offering.allocation_mode='single' then select id into v_space_id from public.spaces where code='SPACE_3' and is_active;
        else select id into v_space_id from public.spaces where code in('SPACE_1','SPACE_2') and is_active and private.space_is_available(id,v_starts,v_ends,null) order by case code when 'SPACE_1' then 1 else 2 end limit 1;
        end if;
        if v_space_id is not null and private.space_is_available(v_space_id,v_starts,v_ends,null) then v_codes:=v_codes||to_jsonb(v_offering.code); end if;
      end loop;
    end if;
    v_result:=v_result||jsonb_build_object('date',(v_starts at time zone 'America/Guatemala')::date,'startsAt',v_starts,'endsAt',v_ends,'offeringCodes',v_codes);
  end loop;
  return v_result;
end;
$$;

create or replace function public.create_paid_meeting_request(
  p_contact_name text,p_contact_email text,p_contact_phone text,p_organization_id bigint,
  p_offering_code text,p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_observations text default null,p_website text default null
)
returns text language plpgsql security definer set search_path='' as $$
declare v_id uuid:=gen_random_uuid();v_code text;v_offering public.paid_space_offerings%rowtype;v_item jsonb;v_space_id bigint;v_occ_id uuid;v_quote numeric:=0;v_item_quote numeric;v_hours numeric;v_config_code text;v_config_id bigint;v_offering_code text;
begin
  if nullif(btrim(p_website),'') is not null then raise exception 'No fue posible procesar la solicitud.'; end if;
  if p_occurrences is null or jsonb_typeof(p_occurrences)<>'array' or jsonb_array_length(p_occurrences)<1 or jsonb_array_length(p_occurrences)>100 then raise exception 'Las fechas son inválidas.'; end if;
  if p_recurrence_type='none' and jsonb_array_length(p_occurrences)<>1 then raise exception 'Una reunión no recurrente debe contener una fecha.'; end if;
  if p_recurrence_type<>'none' and jsonb_array_length(p_occurrences)<2 then raise exception 'Una reunión recurrente debe contener al menos dos fechas.'; end if;
  if not exists(select 1 from public.organizations where id=p_organization_id and is_active) then raise exception 'Organización inválida.'; end if;
  if not exists(select 1 from public.meeting_types where id=p_meeting_type_id and is_active) then raise exception 'Tipo de reunión inválido.'; end if;
  if p_attendees is null or p_attendees<=0 then raise exception 'La cantidad de asistentes debe ser mayor que cero.'; end if;
  if nullif(btrim(p_contact_name),'') is null or nullif(btrim(p_contact_email),'') is null or nullif(btrim(p_contact_phone),'') is null then raise exception 'Completa los datos de contacto.'; end if;

  for v_item in select value from jsonb_array_elements(p_occurrences) loop
    v_offering_code:=coalesce(nullif(upper(v_item->>'offering_code'),''),upper(btrim(p_offering_code)));
    v_config_id:=coalesce((v_item->>'configuration_type_id')::bigint,p_configuration_type_id);
    select * into strict v_offering from public.paid_space_offerings where code=v_offering_code and is_active;
    select code into strict v_config_code from public.configuration_types where id=v_config_id and is_active;
    if coalesce((v_offering.capacities->>v_config_code)::integer,0)<p_attendees then raise exception 'La capacidad del espacio comercial es insuficiente en una de las fechas.'; end if;
    v_space_id:=null;
    if v_offering.allocation_mode='combined' then select id into strict v_space_id from public.spaces where code='SPACE_1_2' and is_active;
    elsif v_offering.allocation_mode='single' then select id into strict v_space_id from public.spaces where code='SPACE_3' and is_active;
    else select s.id into v_space_id from public.spaces s where s.code in('SPACE_1','SPACE_2') and s.is_active and private.space_is_available(s.id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) and exists(select 1 from public.space_capacities sc join public.configuration_types c on c.id=sc.configuration_type_id where sc.space_id=s.id and c.code=v_config_code and sc.capacity>=p_attendees) order by case s.code when 'SPACE_1' then 1 else 2 end limit 1;
    end if;
    if v_space_id is null then raise exception 'El espacio comercial no está disponible en una de las fechas.'; end if;
    perform private.validate_space_capacity(v_space_id,v_config_id,p_attendees);
    if not private.space_is_available(v_space_id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) then raise exception 'El espacio solicitado ya está ocupado en una de las fechas.'; end if;
    v_hours:=extract(epoch from((v_item->>'ends_at')::timestamptz-(v_item->>'starts_at')::timestamptz))/3600;
    if v_hours<=0 then raise exception 'La hora final debe ser posterior a la hora de inicio.'; end if;
    v_item_quote:=case when v_hours<=3 then v_offering.price_one_hour*v_hours when v_hours<=8 then v_offering.price_four_to_eight_hours else v_offering.price_four_to_eight_hours+(v_hours-8)*v_offering.price_extra_hour_after_eight end;
    v_quote:=v_quote+v_item_quote;
  end loop;

  v_code:=private.generate_request_code();
  select * into strict v_offering from public.paid_space_offerings where code=upper(btrim(p_offering_code)) and is_active;
  insert into public.meetings(id,request_code,requester_name,requester_email,request_organization_id,meeting_type_id,configuration_type_id,title,description,observations,attendees,recurrence_type,recurrence_end_date,contact_name,contact_email,contact_phone,request_source,paid_offering_code,quoted_amount,payment_status)
  values(v_id,v_code,btrim(p_contact_name),lower(btrim(p_contact_email)),p_organization_id,p_meeting_type_id,p_configuration_type_id,btrim(p_title),nullif(btrim(p_description),''),nullif(btrim(p_observations),''),p_attendees,p_recurrence_type,p_recurrence_end_date,btrim(p_contact_name),lower(btrim(p_contact_email)),btrim(p_contact_phone),'paid',v_offering.code,round(v_quote,2),'quoted');

  for v_item in select value from jsonb_array_elements(p_occurrences) loop
    v_offering_code:=coalesce(nullif(upper(v_item->>'offering_code'),''),upper(btrim(p_offering_code)));v_config_id:=coalesce((v_item->>'configuration_type_id')::bigint,p_configuration_type_id);
    select * into strict v_offering from public.paid_space_offerings where code=v_offering_code and is_active;
    select code into strict v_config_code from public.configuration_types where id=v_config_id and is_active;v_space_id:=null;
    if v_offering.allocation_mode='combined' then select id into strict v_space_id from public.spaces where code='SPACE_1_2' and is_active;
    elsif v_offering.allocation_mode='single' then select id into strict v_space_id from public.spaces where code='SPACE_3' and is_active;
    else select s.id into strict v_space_id from public.spaces s where s.code in('SPACE_1','SPACE_2') and s.is_active and private.space_is_available(s.id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) and exists(select 1 from public.space_capacities sc join public.configuration_types c on c.id=sc.configuration_type_id where sc.space_id=s.id and c.code=v_config_code and sc.capacity>=p_attendees) order by case s.code when 'SPACE_1' then 1 else 2 end limit 1;
    end if;
    v_hours:=extract(epoch from((v_item->>'ends_at')::timestamptz-(v_item->>'starts_at')::timestamptz))/3600;v_item_quote:=case when v_hours<=3 then v_offering.price_one_hour*v_hours when v_hours<=8 then v_offering.price_four_to_eight_hours else v_offering.price_four_to_eight_hours+(v_hours-8)*v_offering.price_extra_hour_after_eight end;
    v_occ_id:=gen_random_uuid();
    insert into public.meeting_occurrences(id,meeting_id,space_id,configuration_type_id,paid_offering_code,quoted_amount,starts_at,ends_at) values(v_occ_id,v_id,v_space_id,v_config_id,v_offering.code,round(v_item_quote,2),(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz);
    insert into public.meeting_occurrence_status_history(occurrence_id,to_status,reason,actor_name,actor_role) values(v_occ_id,'pending','Solicitud externa comercial creada',btrim(p_contact_name),'user');
    insert into public.room_blocks(room_id,occurrence_id,block_type,starts_at,ends_at) select sr.room_id,v_occ_id,'payment_hold',(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz from public.space_rooms sr where sr.space_id=v_space_id;
  end loop;
  insert into public.audit_log(actor_role,action,entity_type,entity_id,new_data) values('user','create_paid_request','meeting',v_id::text,jsonb_build_object('request_code',v_code,'quoted_amount',round(v_quote,2)));
  insert into public.board_events(event_type,entity_type,entity_id) values('request','meeting',v_id::text);
  return v_code;
end;
$$;

create or replace function public.approve_occurrence(p_occurrence_id uuid,p_apply_to_series boolean default false)
returns integer language plpgsql security definer set search_path='' as $$
declare v_target public.meeting_occurrences%rowtype;v_occ record;v_count integer:=0;
begin
  perform private.require_admin();select * into strict v_target from public.meeting_occurrences where id=p_occurrence_id for update;
  if v_target.status not in('pending','rejected') then raise exception 'Solo se pueden aprobar solicitudes pendientes o rechazadas.'; end if;
  for v_occ in select o.*,m.attendees from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id where(o.id=p_occurrence_id or(p_apply_to_series and o.meeting_id=v_target.meeting_id)) and o.status in('pending','rejected') order by o.starts_at for update of o loop
    perform private.validate_space_capacity(v_occ.space_id,v_occ.configuration_type_id,v_occ.attendees);
    begin insert into public.room_blocks(room_id,occurrence_id,block_type,starts_at,ends_at) select sr.room_id,v_occ.id,'meeting',v_occ.starts_at,v_occ.ends_at from public.space_rooms sr where sr.space_id=v_occ.space_id; exception when exclusion_violation then raise exception 'No se puede aprobar: uno de los salones físicos ya está ocupado en ese horario.'; end;
    update public.meeting_occurrences set status='approved' where id=v_occ.id;
    insert into public.meeting_occurrence_status_history(occurrence_id,from_status,to_status,reason,changed_by,actor_name,actor_role) values(v_occ.id,v_occ.status,'approved','Aprobada por administración',auth.uid(),private.actor_name(),'admin');
    insert into public.board_events(event_type,entity_type,entity_id) values('upsert','meeting_occurrence',v_occ.id::text);v_count:=v_count+1;
  end loop;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data) values(auth.uid(),'admin','approve','meeting_occurrence',p_occurrence_id::text,jsonb_build_object('affected',v_count));return v_count;
end;
$$;

create or replace function public.reject_occurrence(p_occurrence_id uuid,p_reason text,p_apply_to_series boolean default false)
returns integer language plpgsql security definer set search_path='' as $$
declare v_target public.meeting_occurrences%rowtype;v_occ record;v_count integer:=0;
begin
  perform private.require_admin();if nullif(btrim(p_reason),'') is null then raise exception 'Debe indicar el motivo del rechazo.';end if;
  select * into strict v_target from public.meeting_occurrences where id=p_occurrence_id for update;if v_target.ends_at<=now() then raise exception 'La reunión ya finalizó y no admite modificaciones.';end if;if v_target.status<>'pending' then raise exception 'Solo se pueden rechazar solicitudes pendientes.';end if;
  for v_occ in select o.* from public.meeting_occurrences o where(o.id=p_occurrence_id or(p_apply_to_series and o.meeting_id=v_target.meeting_id)) and o.status='pending' and o.ends_at>now() order by o.starts_at for update loop
    update public.room_blocks set active=false where occurrence_id=v_occ.id and block_type='payment_hold' and active;
    update public.meeting_occurrences set status='rejected' where id=v_occ.id;
    insert into public.meeting_occurrence_status_history(occurrence_id,from_status,to_status,reason,changed_by,actor_name,actor_role) values(v_occ.id,'pending','rejected',btrim(p_reason),auth.uid(),private.actor_name(),'admin');
    insert into public.board_events(event_type,entity_type,entity_id) values('remove','meeting_occurrence',v_occ.id::text);v_count:=v_count+1;
  end loop;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data) values(auth.uid(),'admin','reject','meeting_occurrence',p_occurrence_id::text,jsonb_build_object('affected',v_count,'reason',btrim(p_reason)));return v_count;
end;
$$;

create or replace function public.move_occurrence(p_occurrence_id uuid,p_new_space_id bigint,p_reason text default null,p_apply_to_series boolean default false)
returns integer language plpgsql security definer set search_path='' as $$
declare v_target public.meeting_occurrences%rowtype;v_occ record;v_count integer:=0;
begin
  perform private.require_admin();select * into strict v_target from public.meeting_occurrences where id=p_occurrence_id for update;if v_target.ends_at<=now() then raise exception 'La reunión ya finalizó y no admite modificaciones.'; end if;if v_target.status='cancelled' then raise exception 'No se puede mover una reunión cancelada.'; end if;
  for v_occ in select o.*,m.attendees from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id where(o.id=p_occurrence_id or(p_apply_to_series and o.meeting_id=v_target.meeting_id)) and o.status<>'cancelled' and o.ends_at>now() order by o.starts_at for update of o loop
    perform private.validate_space_capacity(p_new_space_id,v_occ.configuration_type_id,v_occ.attendees);
    if v_occ.status='approved' then update public.room_blocks set active=false where occurrence_id=v_occ.id and active;begin insert into public.room_blocks(room_id,occurrence_id,block_type,starts_at,ends_at) select sr.room_id,v_occ.id,'meeting',v_occ.starts_at,v_occ.ends_at from public.space_rooms sr where sr.space_id=p_new_space_id;exception when exclusion_violation then raise exception 'No se puede mover: uno de los salones físicos ya está ocupado en ese horario.';end;end if;
    update public.meeting_occurrences set space_id=p_new_space_id where id=v_occ.id;
    insert into public.meeting_occurrence_space_history(occurrence_id,old_space_id,new_space_id,changed_by,actor_name,actor_role,reason) values(v_occ.id,v_occ.space_id,p_new_space_id,auth.uid(),private.actor_name(),'admin',coalesce(nullif(btrim(p_reason),''),'Cambio de salón'));
    insert into public.board_events(event_type,entity_type,entity_id) values('upsert','meeting_occurrence',v_occ.id::text);v_count:=v_count+1;
  end loop;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data) values(auth.uid(),'admin','move','meeting_occurrence',p_occurrence_id::text,jsonb_build_object('new_space_id',p_new_space_id,'affected',v_count,'reason',p_reason));return v_count;
end;
$$;

create or replace function public.admin_reschedule_occurrence(p_occurrence_id uuid,p_start_time time without time zone,p_end_time time without time zone,p_reason text default 'Cambio de horario por administración',p_apply_to_series boolean default false)
returns integer language plpgsql security definer set search_path='' as $$
declare v_target public.meeting_occurrences%rowtype;v_occ record;v_new_starts_at timestamptz;v_new_ends_at timestamptz;v_count integer:=0;
begin
  perform private.require_admin();if p_start_time is null or p_end_time is null or p_end_time<=p_start_time then raise exception 'La hora final debe ser posterior a la hora de inicio.';end if;if nullif(btrim(p_reason),'') is null then raise exception 'Debe indicar el motivo del cambio de horario.';end if;
  select * into strict v_target from public.meeting_occurrences where id=p_occurrence_id for update;if v_target.ends_at<=now() then raise exception 'La reunión ya finalizó y no admite modificaciones.';end if;if v_target.status='cancelled' then raise exception 'No se puede cambiar el horario de una reunión cancelada.';end if;
  for v_occ in select o.*,m.attendees from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id where(o.id=p_occurrence_id or(p_apply_to_series and o.meeting_id=v_target.meeting_id)) and o.status<>'cancelled' and o.ends_at>now() order by o.starts_at for update of o loop
    v_new_starts_at:=(((v_occ.starts_at at time zone 'America/Guatemala')::date+p_start_time) at time zone 'America/Guatemala');v_new_ends_at:=(((v_occ.starts_at at time zone 'America/Guatemala')::date+p_end_time) at time zone 'America/Guatemala');perform private.validate_space_capacity(v_occ.space_id,v_occ.configuration_type_id,v_occ.attendees);if not private.space_is_available(v_occ.space_id,v_new_starts_at,v_new_ends_at,v_occ.id) then raise exception 'No se puede cambiar el horario: el salón está ocupado en una de las fechas seleccionadas.';end if;
    if v_occ.status='approved' then begin update public.room_blocks set starts_at=v_new_starts_at,ends_at=v_new_ends_at where occurrence_id=v_occ.id and active;exception when exclusion_violation then raise exception 'No se puede cambiar el horario: uno de los salones físicos ya está ocupado.';end;end if;
    update public.meeting_occurrences set starts_at=v_new_starts_at,ends_at=v_new_ends_at where id=v_occ.id;
    insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,old_data,new_data) values(auth.uid(),'admin','reschedule','meeting_occurrence',v_occ.id::text,jsonb_build_object('starts_at',v_occ.starts_at,'ends_at',v_occ.ends_at),jsonb_build_object('starts_at',v_new_starts_at,'ends_at',v_new_ends_at,'reason',btrim(p_reason)));
    insert into public.board_events(event_type,entity_type,entity_id) values('upsert','meeting_occurrence',v_occ.id::text);v_count:=v_count+1;
  end loop;return v_count;
end;
$$;

create or replace function public.get_public_board(p_date date default((now() at time zone 'America/Guatemala')::date))
returns table(item_type text,item_id uuid,room_id bigint,room_name text,space_name text,title text,details text,starts_at timestamptz,ends_at timestamptz,status text,configuration_name text,meeting_type_name text,attendees integer,requester_name text)
language sql stable security definer set search_path='' as $$
with bounds as(select p_date::timestamp at time zone 'America/Guatemala' day_start,(p_date+1)::timestamp at time zone 'America/Guatemala' day_end)
select 'meeting',o.id,r.id,r.name,s.name,m.title,m.description,o.starts_at,o.ends_at,o.status::text,ct.name,mt.name,m.attendees,m.requester_name from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id join public.spaces s on s.id=o.space_id join public.space_rooms sr on sr.space_id=s.id join public.rooms r on r.id=sr.room_id join public.configuration_types ct on ct.id=o.configuration_type_id join public.meeting_types mt on mt.id=m.meeting_type_id cross join bounds b where o.status='approved' and o.starts_at<b.day_end and o.ends_at>b.day_start
union all select 'maintenance',me.id,r.id,r.name,r.name,me.title,me.reason,me.starts_at,me.ends_at,me.status::text,null,null,null,null from public.maintenance_events me join public.rooms r on r.id=me.room_id cross join bounds b where me.status='scheduled' and me.starts_at<b.day_end and me.ends_at>b.day_start
union all select 'room',null::uuid,r.id,r.name,r.name,null::text,r.location,null::timestamptz,null::timestamptz,case when r.is_active then 'active' else 'inactive' end,null::text,null::text,null::integer,null::text from public.rooms r where r.is_active order by 3,8;
$$;

create or replace function public.get_agenda(p_date date default((now() at time zone 'America/Guatemala')::date))
returns table(occurrence_id uuid,meeting_id uuid,request_code text,title text,space_id bigint,space_name text,starts_at timestamptz,ends_at timestamptz,status public.meeting_status,requester_name text,meeting_type_name text,configuration_name text,attendees integer)
language plpgsql stable security definer set search_path='' as $$
begin perform private.require_active_user();return query with bounds as(select p_date::timestamp at time zone 'America/Guatemala' day_start,(p_date+1)::timestamp at time zone 'America/Guatemala' day_end) select o.id,m.id,m.request_code,m.title,s.id,s.name,o.starts_at,o.ends_at,o.status,m.requester_name,mt.name,ct.name,m.attendees from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id join public.spaces s on s.id=o.space_id join public.meeting_types mt on mt.id=m.meeting_type_id join public.configuration_types ct on ct.id=o.configuration_type_id cross join bounds b where o.starts_at<b.day_end and o.ends_at>b.day_start and(private.is_admin() or o.status='approved' or m.requested_by=auth.uid()) order by o.starts_at,s.name;end;
$$;

drop view if exists public.vw_request_occurrences;
create view public.vw_request_occurrences with(security_invoker=true) as
select o.id occurrence_id,m.id meeting_id,m.request_code,m.title,m.description,m.requested_by,m.requester_name,m.requester_email,org.name organization_name,d.name department_name,mt.name meeting_type_name,ct.name configuration_name,m.attendees,m.recurrence_type,m.recurrence_end_date,s.id space_id,s.name space_name,o.starts_at,o.ends_at,o.status,o.created_at requested_at,approved.changed_at approved_at,rejected.changed_at rejected_at,rejected.reason rejection_reason,rejected.actor_name rejected_by_name,cancelled.changed_at cancelled_at,cancelled.reason cancellation_reason,cancelled.actor_role cancelled_by_role,cancelled.actor_name cancelled_by_name,moved.changed_at last_moved_at,old_space.name previous_space_name,moved.reason movement_reason,rescheduled.changed_at last_rescheduled_at,m.contact_name,m.contact_email,m.contact_phone,m.request_source,m.observations,coalesce(o.paid_offering_code,m.paid_offering_code) paid_offering_code,m.quoted_amount,m.payment_status,o.quoted_amount occurrence_quoted_amount
from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id join public.organizations org on org.id=m.request_organization_id left join public.departments d on d.id=m.request_department_id join public.meeting_types mt on mt.id=m.meeting_type_id join public.configuration_types ct on ct.id=o.configuration_type_id join public.spaces s on s.id=o.space_id
left join lateral(select h.changed_at from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='approved' order by h.changed_at desc limit 1) approved on true
left join lateral(select h.changed_at,h.reason,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='rejected' order by h.changed_at desc limit 1) rejected on true
left join lateral(select h.changed_at,h.reason,h.actor_role,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='cancelled' order by h.changed_at desc limit 1) cancelled on true
left join lateral(select sh.old_space_id,sh.changed_at,sh.reason from public.meeting_occurrence_space_history sh where sh.occurrence_id=o.id and sh.old_space_id is not null order by sh.changed_at desc limit 1) moved on true
left join lateral(select a.created_at changed_at from public.audit_log a where a.entity_type='meeting_occurrence' and a.entity_id=o.id::text and a.action='reschedule' order by a.created_at desc limit 1) rescheduled on true left join public.spaces old_space on old_space.id=moved.old_space_id;

create or replace function public.track_external_request(p_request_code text)
returns jsonb language plpgsql stable security definer set search_path='' as $$
declare v_result jsonb;v_code text:=upper(btrim(coalesce(p_request_code,'')));
begin
  if v_code!~ '^(CS-[0-9A-F]{12}|SF-[0-9]{8}-[0-9A-F]{8})$' then return jsonb_build_object('found',false);end if;
  select jsonb_build_object('found',true,'requestCode',m.request_code,'title',m.title,'organization',org.name,'meetingType',mt.name,'configuration',first_config.name,'attendees',m.attendees,'recurrenceType',m.recurrence_type,'createdAt',m.created_at,'updatedAt',greatest(m.updated_at,coalesce(summary.last_change,m.updated_at)),'overallStatus',summary.overall_status,'summary',summary.status_counts,'occurrences',summary.occurrences) into v_result
  from public.meetings m join public.organizations org on org.id=m.request_organization_id join public.meeting_types mt on mt.id=m.meeting_type_id join public.configuration_types first_config on first_config.id=m.configuration_type_id
  cross join lateral(select case when count(distinct o.status)=1 then min(o.status::text) else 'mixed' end overall_status,max(coalesce(last_status.changed_at,o.updated_at)) last_change,jsonb_build_object('pending',count(*) filter(where o.status='pending'),'approved',count(*) filter(where o.status='approved'),'rejected',count(*) filter(where o.status='rejected'),'cancelled',count(*) filter(where o.status='cancelled')) status_counts,jsonb_agg(jsonb_build_object('startsAt',o.starts_at,'endsAt',o.ends_at,'status',o.status,'spaceName',s.name,'configuration',ct.name,'statusAt',coalesce(last_status.changed_at,o.created_at),'reason',case when o.status in('rejected','cancelled') then last_status.reason else null end) order by o.starts_at) occurrences from public.meeting_occurrences o join public.spaces s on s.id=o.space_id join public.configuration_types ct on ct.id=o.configuration_type_id left join lateral(select h.changed_at,h.reason from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status=o.status order by h.changed_at desc limit 1) last_status on true where o.meeting_id=m.id) summary
  where m.request_source in('public','paid') and upper(m.request_code)=v_code limit 1;return coalesce(v_result,jsonb_build_object('found',false));
end;
$$;

revoke all on function public.get_public_space_availability_by_occurrence(jsonb) from public,anon,authenticated;
revoke all on function public.get_paid_offering_availability_by_occurrence(jsonb) from public,anon,authenticated;
grant execute on function public.get_public_space_availability_by_occurrence(jsonb) to anon,authenticated;
grant execute on function public.get_paid_offering_availability_by_occurrence(jsonb) to anon,authenticated;
revoke all on public.vw_request_occurrences from public,anon,authenticated;
grant select on public.vw_request_occurrences to authenticated;
grant execute on function public.create_paid_meeting_request(text,text,text,bigint,text,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text) to anon,authenticated;
grant execute on function public.track_external_request(text) to anon,authenticated;
grant execute on function public.get_public_board(date) to anon,authenticated;
grant execute on function public.get_agenda(date) to authenticated;

commit;
