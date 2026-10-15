-- CengiSalas / Supabase
-- 19: protección de reuniones finalizadas y registro del último cambio de horario.

begin;

create or replace function private.prevent_finished_occurrence_changes()
returns trigger
language plpgsql
set search_path=''
as $$
begin
  if old.ends_at <= now()
     and (new.starts_at is distinct from old.starts_at
       or new.ends_at is distinct from old.ends_at
       or new.space_id is distinct from old.space_id
       or new.status is distinct from old.status) then
    raise exception 'La reunión ya finalizó y no admite modificaciones.';
  end if;
  return new;
end;
$$;

drop trigger if exists prevent_finished_occurrence_changes on public.meeting_occurrences;
create trigger prevent_finished_occurrence_changes
before update on public.meeting_occurrences
for each row execute function private.prevent_finished_occurrence_changes();

drop view if exists public.vw_request_occurrences;
create view public.vw_request_occurrences with(security_invoker=true) as
select o.id occurrence_id,m.id meeting_id,m.request_code,m.title,m.description,m.requested_by,m.requester_name,m.requester_email,
  org.name organization_name,d.name department_name,mt.name meeting_type_name,ct.name configuration_name,m.attendees,
  m.recurrence_type,m.recurrence_end_date,s.id space_id,s.name space_name,o.starts_at,o.ends_at,o.status,o.created_at requested_at,
  approved.changed_at approved_at,rejected.changed_at rejected_at,rejected.reason rejection_reason,rejected.actor_name rejected_by_name,
  cancelled.changed_at cancelled_at,cancelled.reason cancellation_reason,cancelled.actor_role cancelled_by_role,
  cancelled.actor_name cancelled_by_name,moved.changed_at last_moved_at,old_space.name previous_space_name,moved.reason movement_reason,
  rescheduled.changed_at last_rescheduled_at,
  m.contact_name,m.contact_email,m.contact_phone,m.request_source,m.observations
from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id
join public.organizations org on org.id=m.request_organization_id left join public.departments d on d.id=m.request_department_id
join public.meeting_types mt on mt.id=m.meeting_type_id join public.configuration_types ct on ct.id=m.configuration_type_id
join public.spaces s on s.id=o.space_id
left join lateral(select h.changed_at from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='approved' order by h.changed_at desc limit 1) approved on true
left join lateral(select h.changed_at,h.reason,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='rejected' order by h.changed_at desc limit 1) rejected on true
left join lateral(select h.changed_at,h.reason,h.actor_role,h.actor_name from public.meeting_occurrence_status_history h where h.occurrence_id=o.id and h.to_status='cancelled' order by h.changed_at desc limit 1) cancelled on true
left join lateral(select sh.old_space_id,sh.changed_at,sh.reason from public.meeting_occurrence_space_history sh where sh.occurrence_id=o.id and sh.old_space_id is not null order by sh.changed_at desc limit 1) moved on true
left join lateral(select a.created_at changed_at from public.audit_log a where a.entity_type='meeting_occurrence' and a.entity_id=o.id::text and a.action='reschedule' order by a.created_at desc limit 1) rescheduled on true
left join public.spaces old_space on old_space.id=moved.old_space_id;

revoke all on public.vw_request_occurrences from public,anon,authenticated;
grant select on public.vw_request_occurrences to authenticated;

commit;
