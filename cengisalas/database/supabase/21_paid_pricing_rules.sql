-- CengiSalas / Supabase
-- 21: regla de cotización comercial: 1-3 horas por hora, 4-8 tarifa fija y horas extra.

create or replace function public.create_paid_meeting_request(
  p_contact_name text,p_contact_email text,p_contact_phone text,p_organization_id bigint,
  p_offering_code text,p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_observations text default null,p_website text default null
)
returns text language plpgsql security definer set search_path='' as $$
declare v_id uuid:=gen_random_uuid();v_code text;v_offering public.paid_space_offerings%rowtype;v_item jsonb;v_space_id bigint;v_occ_id uuid;v_quote numeric:=0;v_hours numeric;v_config_code text;
begin
  if nullif(btrim(p_website),'') is not null then raise exception 'No fue posible procesar la solicitud.'; end if;
  select * into strict v_offering from public.paid_space_offerings where code=upper(btrim(p_offering_code)) and is_active;
  select code into strict v_config_code from public.configuration_types where id=p_configuration_type_id and is_active;
  if p_occurrences is null or jsonb_typeof(p_occurrences)<>'array' or jsonb_array_length(p_occurrences)<1 or jsonb_array_length(p_occurrences)>100 then raise exception 'Las fechas son inválidas.'; end if;
  if p_recurrence_type='none' and jsonb_array_length(p_occurrences)<>1 then raise exception 'Una reunión no recurrente debe contener una fecha.'; end if;
  if p_recurrence_type<>'none' and jsonb_array_length(p_occurrences)<2 then raise exception 'Una reunión recurrente debe contener al menos dos fechas.'; end if;
  if not exists(select 1 from public.organizations where id=p_organization_id and is_active) then raise exception 'Organización inválida.'; end if;
  if not exists(select 1 from public.meeting_types where id=p_meeting_type_id and is_active) then raise exception 'Tipo de reunión inválido.'; end if;
  if p_attendees is null or p_attendees<=0 then raise exception 'La cantidad de asistentes debe ser mayor que cero.'; end if;
  if coalesce((v_offering.capacities->>v_config_code)::integer,0)<p_attendees then raise exception 'La capacidad del espacio comercial es insuficiente.'; end if;
  if nullif(btrim(p_contact_name),'') is null or nullif(btrim(p_contact_email),'') is null or nullif(btrim(p_contact_phone),'') is null then raise exception 'Completa los datos de contacto.'; end if;

  for v_item in select value from jsonb_array_elements(p_occurrences) loop
    if v_offering.allocation_mode='combined' then select id into strict v_space_id from public.spaces where code='SPACE_1_2' and is_active;
    elsif v_offering.allocation_mode='single' then select id into strict v_space_id from public.spaces where code='SPACE_3' and is_active;
    else select s.id into v_space_id from public.spaces s where s.code in ('SPACE_1','SPACE_2') and s.is_active and private.space_is_available(s.id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) and exists(select 1 from public.space_capacities sc join public.configuration_types c on c.id=sc.configuration_type_id where sc.space_id=s.id and c.code=v_config_code and sc.capacity>=p_attendees) order by case when s.code='SPACE_1' then 1 else 2 end limit 1; if v_space_id is null then raise exception 'Los salones de formación no están disponibles en una de las fechas.'; end if; end if;
    perform private.validate_space_capacity(v_space_id,p_configuration_type_id,p_attendees);
    if not private.space_is_available(v_space_id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) then raise exception 'El espacio solicitado ya está ocupado en una de las fechas.'; end if;
    v_hours:=extract(epoch from ((v_item->>'ends_at')::timestamptz-(v_item->>'starts_at')::timestamptz))/3600;
    v_quote:=v_quote+case when v_hours<=3 then v_offering.price_one_hour*v_hours when v_hours<=8 then v_offering.price_four_to_eight_hours else v_offering.price_four_to_eight_hours+(v_hours-8)*v_offering.price_extra_hour_after_eight end;
  end loop;
  v_code:=private.generate_request_code();
  insert into public.meetings(id,request_code,requester_name,requester_email,request_organization_id,meeting_type_id,configuration_type_id,title,description,observations,attendees,recurrence_type,recurrence_end_date,contact_name,contact_email,contact_phone,request_source,paid_offering_code,quoted_amount,payment_status)
  values(v_id,v_code,btrim(p_contact_name),lower(btrim(p_contact_email)),p_organization_id,p_meeting_type_id,p_configuration_type_id,btrim(p_title),nullif(btrim(p_description),''),nullif(btrim(p_observations),''),p_attendees,p_recurrence_type,p_recurrence_end_date,btrim(p_contact_name),lower(btrim(p_contact_email)),btrim(p_contact_phone),'paid',v_offering.code,round(v_quote,2),'quoted');
  for v_item in select value from jsonb_array_elements(p_occurrences) loop
    if v_offering.allocation_mode='combined' then select id into strict v_space_id from public.spaces where code='SPACE_1_2' and is_active;
    elsif v_offering.allocation_mode='single' then select id into strict v_space_id from public.spaces where code='SPACE_3' and is_active;
    else select s.id into strict v_space_id from public.spaces s where s.code in ('SPACE_1','SPACE_2') and s.is_active and private.space_is_available(s.id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) and exists(select 1 from public.space_capacities sc join public.configuration_types c on c.id=sc.configuration_type_id where sc.space_id=s.id and c.code=v_config_code and sc.capacity>=p_attendees) order by case when s.code='SPACE_1' then 1 else 2 end limit 1; end if;
    v_occ_id:=gen_random_uuid();
    insert into public.meeting_occurrences(id,meeting_id,space_id,starts_at,ends_at) values(v_occ_id,v_id,v_space_id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz);
    insert into public.meeting_occurrence_status_history(occurrence_id,to_status,reason,actor_name,actor_role) values(v_occ_id,'pending','Solicitud externa comercial creada',btrim(p_contact_name),'user');
    insert into public.room_blocks(room_id,occurrence_id,block_type,starts_at,ends_at) select sr.room_id,v_occ_id,'payment_hold',(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz from public.space_rooms sr where sr.space_id=v_space_id;
  end loop;
  insert into public.audit_log(actor_role,action,entity_type,entity_id,new_data) values('user','create_paid_request','meeting',v_id::text,jsonb_build_object('request_code',v_code,'quoted_amount',round(v_quote,2)));
  insert into public.board_events(event_type,entity_type,entity_id) values('request','meeting',v_id::text);
  return v_code;
end;
$$;

revoke all on function public.create_paid_meeting_request(text,text,text,bigint,text,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text) from public,anon,authenticated;
grant execute on function public.create_paid_meeting_request(text,text,text,bigint,text,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text) to anon,authenticated;
