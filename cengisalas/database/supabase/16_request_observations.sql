-- CengiSalas / Supabase
-- 16: observaciones opcionales en solicitudes internas y públicas.

begin;

alter table public.meetings add column if not exists observations text;

do $$
begin
  if not exists(select 1 from pg_constraint where conname='meetings_observations_length') then
    alter table public.meetings add constraint meetings_observations_length
      check(observations is null or length(observations)<=1000);
  end if;
end $$;

drop function if exists public.create_meeting_request(
  text,text,bigint,bigint,integer,text,text,text,jsonb,public.recurrence_type,date,bigint[]
);

create function public.create_meeting_request(
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_contact_name text,p_contact_email text,p_contact_phone text,p_occurrences jsonb,
  p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_refreshment_ids bigint[] default '{}'::bigint[],p_observations text default null
)
returns uuid
language plpgsql
security definer
set search_path=''
as $$
declare v_profile public.profiles%rowtype; v_id uuid; v_observations text:=nullif(btrim(p_observations),'');
begin
  perform private.require_active_user();
  if v_observations is not null and length(v_observations)>1000 then
    raise exception 'Las observaciones no pueden superar 1,000 caracteres.';
  end if;
  select * into strict v_profile from public.profiles where id=auth.uid();
  if v_profile.organization_id is null then raise exception 'El usuario debe tener una organización asignada.'; end if;
  if coalesce(array_length(p_refreshment_ids,1),0)>0 and not exists(
    select 1 from public.organizations o where o.id=v_profile.organization_id and o.can_request_refreshments and o.is_active
  ) then raise exception 'La refacción solo está disponible para usuarios de Cengicaña.'; end if;
  select result.meeting_id into v_id
  from private.create_request_core(auth.uid(),v_profile.full_name,auth.jwt()->>'email',v_profile.organization_id,v_profile.department_id,
    v_profile.role,'registered',p_contact_name,p_contact_email,p_contact_phone,p_title,p_description,p_meeting_type_id,
    p_configuration_type_id,p_attendees,p_occurrences,p_recurrence_type,p_recurrence_end_date,p_refreshment_ids) result;
  update public.meetings set observations=v_observations where id=v_id;
  return v_id;
end;
$$;

drop function if exists public.create_external_meeting_request(
  text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text
);

create function public.create_external_meeting_request(
  p_contact_name text,p_contact_email text,p_contact_phone text,p_organization_id bigint,
  p_title text,p_description text,p_meeting_type_id bigint,p_configuration_type_id bigint,p_attendees integer,
  p_occurrences jsonb,p_recurrence_type public.recurrence_type default 'none',p_recurrence_end_date date default null,
  p_observations text default null,p_website text default null
)
returns text
language plpgsql
security definer
set search_path=''
as $$
declare v_code text; v_id uuid; v_observations text:=nullif(btrim(p_observations),'');
begin
  if nullif(btrim(p_website),'') is not null then raise exception 'No fue posible procesar la solicitud.'; end if;
  if v_observations is not null and length(v_observations)>1000 then
    raise exception 'Las observaciones no pueden superar 1,000 caracteres.';
  end if;
  if(select count(*) from public.meetings m where m.request_source='public'
      and lower(m.contact_email)=lower(btrim(p_contact_email)) and m.created_at>now()-interval '1 hour')>=5 then
    raise exception 'Se alcanzó el límite de solicitudes para este correo. Intenta nuevamente más tarde.';
  end if;
  select result.meeting_id,result.request_code into v_id,v_code
  from private.create_request_core(null,p_contact_name,p_contact_email,p_organization_id,null,'user','public',
    p_contact_name,p_contact_email,p_contact_phone,p_title,p_description,p_meeting_type_id,p_configuration_type_id,
    p_attendees,p_occurrences,p_recurrence_type,p_recurrence_end_date,'{}'::bigint[]) result;
  update public.meetings set observations=v_observations where id=v_id;
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
  m.contact_name,m.contact_email,m.contact_phone,m.request_source,m.observations
from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id
join public.organizations org on org.id=m.request_organization_id left join public.departments d on d.id=m.request_department_id
join public.meeting_types mt on mt.id=m.meeting_type_id join public.configuration_types ct on ct.id=m.configuration_type_id
join public.spaces s on s.id=o.space_id
left join lateral(select h.changed_at from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='approved' order by h.changed_at desc limit 1) approved on true
left join lateral(select h.changed_at,h.reason,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='rejected' order by h.changed_at desc limit 1) rejected on true
left join lateral(select h.changed_at,h.reason,h.actor_role,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='cancelled' order by h.changed_at desc limit 1) cancelled on true
left join lateral(select sh.old_space_id,sh.changed_at,sh.reason from public.meeting_occurrence_space_history sh where sh.occurrence_id=o.id and sh.old_space_id is not null order by sh.changed_at desc limit 1) moved on true
left join public.spaces old_space on old_space.id=moved.old_space_id;

revoke all on function public.create_meeting_request(text,text,bigint,bigint,integer,text,text,text,jsonb,public.recurrence_type,date,bigint[],text) from public,anon,authenticated;
revoke all on function public.create_external_meeting_request(text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text) from public,anon,authenticated;
revoke all on public.vw_request_occurrences from public,anon,authenticated;
grant execute on function public.create_meeting_request(text,text,bigint,bigint,integer,text,text,text,jsonb,public.recurrence_type,date,bigint[],text) to authenticated;
grant execute on function public.create_external_meeting_request(text,text,text,bigint,text,text,bigint,bigint,integer,jsonb,public.recurrence_type,date,text,text) to anon,authenticated;
grant select on public.vw_request_occurrences to authenticated;

commit;
