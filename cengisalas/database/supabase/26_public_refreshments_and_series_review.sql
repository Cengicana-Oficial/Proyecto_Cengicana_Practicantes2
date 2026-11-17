-- CengiSalas / Supabase
-- 26: refacciones en solicitudes públicas y protección de aprobación por sesión.

begin;

create or replace function public.get_public_request_catalogs()
returns jsonb
language sql
stable
security definer
set search_path=''
as $$
select jsonb_build_object(
  'organizations',coalesce((
    select jsonb_agg(jsonb_build_object(
      'id',o.id,'code',o.code,'name',o.name,'canRequestRefreshments',o.can_request_refreshments
    ) order by o.name)
    from public.organizations o where o.is_active
  ),'[]'::jsonb),
  'meetingTypes',coalesce((
    select jsonb_agg(jsonb_build_object('id',m.id,'code',m.code,'name',m.name) order by m.id)
    from public.meeting_types m where m.is_active
  ),'[]'::jsonb),
  'configurations',coalesce((
    select jsonb_agg(jsonb_build_object('id',c.id,'code',c.code,'name',c.name,'description',c.description) order by c.id)
    from public.configuration_types c where c.is_active
  ),'[]'::jsonb),
  'refreshments',coalesce((
    select jsonb_agg(jsonb_build_object('id',r.id,'code',r.code,'name',r.name) order by r.id)
    from public.refreshments r where r.is_active
  ),'[]'::jsonb),
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

drop function if exists public.create_external_meeting_request(
  text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text
);

create function public.create_external_meeting_request(
  p_contact_name text,p_contact_email text,p_contact_phone text,p_organization_id bigint,
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_observations text default null,p_website text default null,p_refreshment_ids bigint[] default '{}'::bigint[]
)
returns text
language plpgsql
security definer
set search_path=''
as $$
declare v_code text;v_id uuid;v_observations text:=nullif(btrim(p_observations),'');
begin
  if nullif(btrim(p_website),'') is not null then raise exception 'No fue posible procesar la solicitud.'; end if;
  if v_observations is not null and length(v_observations)>1000 then
    raise exception 'Las observaciones no pueden superar 1,000 caracteres.';
  end if;
  if coalesce(array_length(p_refreshment_ids,1),0)>0 and not exists(
    select 1 from public.organizations o
    where o.id=p_organization_id and o.is_active and o.can_request_refreshments
  ) then
    raise exception 'La refacción solo está disponible para solicitudes de Cengicaña.';
  end if;
  if(select count(*) from public.meetings m where m.request_source='public'
      and lower(m.contact_email)=lower(btrim(p_contact_email)) and m.created_at>now()-interval '1 hour')>=5 then
    raise exception 'Se alcanzó el límite de solicitudes para este correo. Intenta nuevamente más tarde.';
  end if;
  select result.meeting_id,result.request_code into v_id,v_code
  from private.create_request_core(null,p_contact_name,p_contact_email,p_organization_id,null,'user','public',
    p_contact_name,p_contact_email,p_contact_phone,p_title,p_description,p_meeting_type_id,p_configuration_type_id,
    p_attendees,p_occurrences,p_recurrence_type,p_recurrence_end_date,coalesce(p_refreshment_ids,'{}'::bigint[])) result;
  update public.meetings set observations=v_observations where id=v_id;
  return v_code;
end;
$$;

create or replace function public.approve_occurrence(p_occurrence_id uuid,p_apply_to_series boolean default false)
returns integer
language plpgsql
security definer
set search_path=''
as $$
declare v_target public.meeting_occurrences%rowtype;v_occ record;v_count integer:=0;
begin
  perform private.require_admin();
  select * into strict v_target from public.meeting_occurrences where id=p_occurrence_id for update;
  if v_target.ends_at<=now() then raise exception 'La reunión ya finalizó y no admite modificaciones.'; end if;
  if v_target.status not in('pending','rejected') then
    raise exception 'Solo se pueden aprobar solicitudes pendientes o rechazadas.';
  end if;
  for v_occ in
    select o.*,m.attendees from public.meeting_occurrences o
    join public.meetings m on m.id=o.meeting_id
    where(o.id=p_occurrence_id or(p_apply_to_series and o.meeting_id=v_target.meeting_id))
      and o.status in('pending','rejected') and o.ends_at>now()
    order by o.starts_at for update of o
  loop
    perform private.validate_space_capacity(v_occ.space_id,v_occ.configuration_type_id,v_occ.attendees);
    update public.room_blocks set active=false
      where occurrence_id=v_occ.id and block_type='payment_hold' and active;
    begin
      insert into public.room_blocks(room_id,occurrence_id,block_type,starts_at,ends_at)
      select sr.room_id,v_occ.id,'meeting',v_occ.starts_at,v_occ.ends_at
      from public.space_rooms sr where sr.space_id=v_occ.space_id;
    exception when exclusion_violation then
      raise exception 'No se puede aprobar: uno de los salones físicos ya está ocupado en ese horario.';
    end;
    update public.meeting_occurrences set status='approved' where id=v_occ.id;
    insert into public.meeting_occurrence_status_history
      (occurrence_id,from_status,to_status,reason,changed_by,actor_name,actor_role)
    values(v_occ.id,v_occ.status,'approved','Aprobada por administración',auth.uid(),private.actor_name(),'admin');
    insert into public.board_events(event_type,entity_type,entity_id)
    values('upsert','meeting_occurrence',v_occ.id::text);
    v_count:=v_count+1;
  end loop;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','approve','meeting_occurrence',p_occurrence_id::text,jsonb_build_object('affected',v_count));
  return v_count;
end;
$$;

revoke all on function public.get_public_request_catalogs() from public,anon,authenticated;
revoke all on function public.create_external_meeting_request(
  text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text,bigint[]
) from public,anon,authenticated;
revoke all on function public.approve_occurrence(uuid,boolean) from public,anon,authenticated;

grant execute on function public.get_public_request_catalogs() to anon,authenticated;
grant execute on function public.create_external_meeting_request(
  text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text,bigint[]
) to anon,authenticated;
grant execute on function public.approve_occurrence(uuid,boolean) to authenticated;

commit;
