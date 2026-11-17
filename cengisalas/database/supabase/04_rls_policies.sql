-- SalaFlow / Supabase
-- 04: Row Level Security y permisos de tablas.

begin;

alter table public.organizations enable row level security;
alter table public.departments enable row level security;
alter table public.profiles enable row level security;
alter table public.meeting_types enable row level security;
alter table public.configuration_types enable row level security;
alter table public.refreshments enable row level security;
alter table public.rooms enable row level security;
alter table public.spaces enable row level security;
alter table public.space_rooms enable row level security;
alter table public.space_capacities enable row level security;
alter table public.meetings enable row level security;
alter table public.meeting_refreshments enable row level security;
alter table public.meeting_occurrences enable row level security;
alter table public.meeting_occurrence_status_history enable row level security;
alter table public.meeting_occurrence_space_history enable row level security;
alter table public.maintenance_events enable row level security;
alter table public.room_blocks enable row level security;
alter table public.audit_log enable row level security;
alter table public.board_events enable row level security;

-- Los permisos se revocan solo para las tablas de SalaFlow; no modifica otras apps del proyecto.
revoke all on public.organizations, public.departments, public.profiles,
  public.meeting_types, public.configuration_types, public.refreshments,
  public.rooms, public.spaces, public.space_rooms, public.space_capacities,
  public.meetings, public.meeting_refreshments, public.meeting_occurrences,
  public.meeting_occurrence_status_history, public.meeting_occurrence_space_history,
  public.maintenance_events, public.room_blocks, public.audit_log, public.board_events
  from anon, authenticated;
grant usage on schema public to anon, authenticated;
grant usage, select on sequence public.organizations_id_seq, public.departments_id_seq,
  public.meeting_types_id_seq, public.configuration_types_id_seq, public.refreshments_id_seq
  to authenticated;

grant select on public.organizations, public.departments, public.meeting_types,
  public.configuration_types, public.refreshments, public.rooms, public.spaces,
  public.space_rooms, public.space_capacities to authenticated;
grant select on public.profiles, public.meetings, public.meeting_refreshments,
  public.meeting_occurrences, public.meeting_occurrence_status_history,
  public.meeting_occurrence_space_history, public.maintenance_events,
  public.audit_log to authenticated;
grant select on public.board_events to anon, authenticated;

-- Administración de catálogos. Las reservas, bloqueos y mantenimientos solo cambian vía RPC.
grant insert, update, delete on public.organizations, public.departments,
  public.meeting_types, public.configuration_types, public.refreshments to authenticated;
grant update on public.profiles to authenticated;

drop policy if exists organizations_read on public.organizations;
create policy organizations_read on public.organizations for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists organizations_admin_write on public.organizations;
create policy organizations_admin_write on public.organizations for all to authenticated
  using (private.is_admin()) with check (private.is_admin());

drop policy if exists departments_read on public.departments;
create policy departments_read on public.departments for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists departments_admin_write on public.departments;
create policy departments_admin_write on public.departments for all to authenticated
  using (private.is_admin()) with check (private.is_admin());

drop policy if exists profiles_read on public.profiles;
create policy profiles_read on public.profiles for select to authenticated
  using (id = auth.uid() or private.is_admin());
drop policy if exists profiles_admin_update on public.profiles;
create policy profiles_admin_update on public.profiles for update to authenticated
  using (private.is_admin()) with check (private.is_admin());

drop policy if exists meeting_types_read on public.meeting_types;
create policy meeting_types_read on public.meeting_types for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists meeting_types_admin_write on public.meeting_types;
create policy meeting_types_admin_write on public.meeting_types for all to authenticated
  using (private.is_admin()) with check (private.is_admin());

drop policy if exists configuration_types_read on public.configuration_types;
create policy configuration_types_read on public.configuration_types for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists configuration_types_admin_write on public.configuration_types;
create policy configuration_types_admin_write on public.configuration_types for all to authenticated
  using (private.is_admin()) with check (private.is_admin());

drop policy if exists refreshments_read on public.refreshments;
create policy refreshments_read on public.refreshments for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists refreshments_admin_write on public.refreshments;
create policy refreshments_admin_write on public.refreshments for all to authenticated
  using (private.is_admin()) with check (private.is_admin());

drop policy if exists rooms_read on public.rooms;
create policy rooms_read on public.rooms for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists spaces_read on public.spaces;
create policy spaces_read on public.spaces for select to authenticated
  using (is_active or private.is_admin());
drop policy if exists space_rooms_read on public.space_rooms;
create policy space_rooms_read on public.space_rooms for select to authenticated using (true);
drop policy if exists capacities_read on public.space_capacities;
create policy capacities_read on public.space_capacities for select to authenticated using (true);

drop policy if exists meetings_read on public.meetings;
create policy meetings_read on public.meetings for select to authenticated
using (
  private.is_admin()
  or requested_by = auth.uid()
);

drop policy if exists occurrences_read on public.meeting_occurrences;
create policy occurrences_read on public.meeting_occurrences for select to authenticated
using (
  private.is_admin()
  or status = 'approved'
  or exists (
    select 1 from public.meetings m
    where m.id = meeting_occurrences.meeting_id and m.requested_by = auth.uid()
  )
);

drop policy if exists meeting_refreshments_read on public.meeting_refreshments;
create policy meeting_refreshments_read on public.meeting_refreshments for select to authenticated
using (
  private.is_admin()
  or exists (select 1 from public.meetings m where m.id = meeting_refreshments.meeting_id and m.requested_by = auth.uid())
);

drop policy if exists status_history_read on public.meeting_occurrence_status_history;
create policy status_history_read on public.meeting_occurrence_status_history for select to authenticated
using (
  private.is_admin()
  or exists (
    select 1 from public.meeting_occurrences o
    join public.meetings m on m.id = o.meeting_id
    where o.id = meeting_occurrence_status_history.occurrence_id and m.requested_by = auth.uid()
  )
);

drop policy if exists space_history_read on public.meeting_occurrence_space_history;
create policy space_history_read on public.meeting_occurrence_space_history for select to authenticated
using (
  private.is_admin()
  or exists (
    select 1 from public.meeting_occurrences o
    join public.meetings m on m.id = o.meeting_id
    where o.id = meeting_occurrence_space_history.occurrence_id and m.requested_by = auth.uid()
  )
);

drop policy if exists maintenance_read on public.maintenance_events;
create policy maintenance_read on public.maintenance_events for select to authenticated using (true);

-- room_blocks no tiene política ni GRANT: solo las funciones propietarias lo consultan/modifican.
drop policy if exists audit_admin_read on public.audit_log;
create policy audit_admin_read on public.audit_log for select to authenticated using (private.is_admin());

drop policy if exists board_events_public_read on public.board_events;
create policy board_events_public_read on public.board_events for select to anon, authenticated using (true);

commit;
