-- SalaFlow / Supabase
-- 07: vistas de consulta para solicitudes e historial administrativo.
-- security_invoker hace que las vistas respeten las políticas RLS del usuario conectado.

begin;

create or replace view public.vw_request_occurrences
with (security_invoker = true)
as
select
  o.id as occurrence_id,
  m.id as meeting_id,
  m.request_code,
  m.title,
  m.description,
  m.requested_by,
  m.requester_name,
  m.requester_email,
  org.name as organization_name,
  d.name as department_name,
  mt.name as meeting_type_name,
  ct.name as configuration_name,
  m.attendees,
  m.recurrence_type,
  m.recurrence_end_date,
  s.id as space_id,
  s.name as space_name,
  o.starts_at,
  o.ends_at,
  o.status,
  o.created_at as requested_at,
  approved.changed_at as approved_at,
  rejected.changed_at as rejected_at,
  rejected.reason as rejection_reason,
  rejected.actor_name as rejected_by_name,
  cancelled.changed_at as cancelled_at,
  cancelled.reason as cancellation_reason,
  cancelled.actor_role as cancelled_by_role,
  cancelled.actor_name as cancelled_by_name,
  moved.changed_at as last_moved_at,
  old_space.name as previous_space_name,
  moved.reason as movement_reason,
  m.contact_name,
  m.contact_email,
  m.contact_phone,
  m.request_source
from public.meeting_occurrences o
join public.meetings m on m.id = o.meeting_id
join public.organizations org on org.id = m.request_organization_id
left join public.departments d on d.id = m.request_department_id
join public.meeting_types mt on mt.id = m.meeting_type_id
join public.configuration_types ct on ct.id = m.configuration_type_id
join public.spaces s on s.id = o.space_id
left join lateral (
  select h.changed_at
  from public.meeting_occurrence_status_history h
  where h.occurrence_id = o.id and h.to_status = 'approved'
  order by h.changed_at desc limit 1
) approved on true
left join lateral (
  select h.changed_at,h.reason,h.actor_name
  from public.meeting_occurrence_status_history h
  where h.occurrence_id = o.id and h.to_status = 'rejected'
  order by h.changed_at desc limit 1
) rejected on true
left join lateral (
  select h.changed_at,h.reason,h.actor_role,h.actor_name
  from public.meeting_occurrence_status_history h
  where h.occurrence_id = o.id and h.to_status = 'cancelled'
  order by h.changed_at desc limit 1
) cancelled on true
left join lateral (
  select sh.old_space_id,sh.changed_at,sh.reason
  from public.meeting_occurrence_space_history sh
  where sh.occurrence_id = o.id and sh.old_space_id is not null
  order by sh.changed_at desc limit 1
) moved on true
left join public.spaces old_space on old_space.id = moved.old_space_id;

revoke all on public.vw_request_occurrences from public, anon, authenticated;
grant select on public.vw_request_occurrences to authenticated;

comment on view public.vw_request_occurrences is
  'Lista de solicitudes con fechas de aprobación, rechazo, cancelación y último movimiento. RLS limita los datos al administrador o propietario.';

commit;
