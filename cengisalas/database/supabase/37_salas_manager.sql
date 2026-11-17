-- El Gestor consulta solicitudes comerciales y reportes sin privilegios de administrador.
begin;

alter table public.app_module_roles drop constraint if exists app_module_roles_code_check;
alter table public.app_module_roles add constraint app_module_roles_code_check
  check (code in ('admin','user','manager'));

insert into public.app_module_roles(module_id,code,name)
select id,'manager','Gestor' from public.app_modules where code='salas'
on conflict (module_id,code) do update set name=excluded.name;

insert into public.app_module_role_permissions(role_id,permission_id)
select r.id,p.id from public.app_module_roles r
join public.app_modules m on m.id=r.module_id and m.code='salas'
join public.app_module_permissions p on p.module_id=m.id
where r.code='manager' and p.code in
  ('ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes')
on conflict do nothing;

create or replace function private.has_salas_permission(p_code text)
returns boolean language sql stable security definer set search_path=''
as $$
  select coalesce((
    select p.is_active and p.account_status::text='active' and
      (p.is_superadmin or coalesce((
        select pp.allowed from public.profile_module_permissions pp
        join public.app_module_permissions ap on ap.id=pp.permission_id
        join public.app_modules am on am.id=ap.module_id
        where pp.profile_id=p.id and am.code='salas' and ap.code=p_code
      ),false))
    from public.profiles p where p.id=auth.uid()
  ),false);
$$;

create or replace function private.has_approved_salas_occurrence(p_meeting_id uuid)
returns boolean language sql stable security definer set search_path=''
as $$
  select exists(select 1 from public.meeting_occurrences o
    where o.meeting_id=p_meeting_id and o.status='approved');
$$;

create or replace function private.is_paid_salas_meeting(p_meeting_id uuid)
returns boolean language sql stable security definer set search_path=''
as $$
  select exists(select 1 from public.meetings m
    where m.id=p_meeting_id and m.request_source='paid');
$$;

create or replace function private.can_read_salas_occurrence(p_occurrence_id uuid)
returns boolean language sql stable security definer set search_path=''
as $$
  select exists(
    select 1 from public.meeting_occurrences o
    join public.meetings m on m.id=o.meeting_id
    where o.id=p_occurrence_id and
      ((m.request_source='paid' and private.has_salas_permission('ver_solicitudes_pago'))
       or (o.status='approved' and private.has_salas_permission('ver_reportes')))
  );
$$;

drop policy if exists meetings_read on public.meetings;
create policy meetings_read on public.meetings for select to authenticated using (
  private.is_admin() or requested_by=auth.uid()
  or (request_source='paid' and private.has_salas_permission('ver_solicitudes_pago'))
  or (private.has_salas_permission('ver_reportes') and private.has_approved_salas_occurrence(id))
);

drop policy if exists occurrences_read on public.meeting_occurrences;
create policy occurrences_read on public.meeting_occurrences for select to authenticated using (
  private.is_admin() or status='approved'
  or exists(select 1 from public.meetings m where m.id=meeting_id and m.requested_by=auth.uid())
  or private.can_read_salas_occurrence(id)
);

drop policy if exists meeting_refreshments_read on public.meeting_refreshments;
create policy meeting_refreshments_read on public.meeting_refreshments for select to authenticated using (
  private.is_admin()
  or exists(select 1 from public.meetings m where m.id=meeting_id and m.requested_by=auth.uid())
  or (private.has_salas_permission('ver_solicitudes_pago') and private.is_paid_salas_meeting(meeting_id))
);

drop policy if exists status_history_read on public.meeting_occurrence_status_history;
create policy status_history_read on public.meeting_occurrence_status_history for select to authenticated using (
  private.is_admin() or private.can_read_salas_occurrence(occurrence_id)
  or exists(select 1 from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id
    where o.id=occurrence_id and m.requested_by=auth.uid())
);

drop policy if exists space_history_read on public.meeting_occurrence_space_history;
create policy space_history_read on public.meeting_occurrence_space_history for select to authenticated using (
  private.is_admin() or private.can_read_salas_occurrence(occurrence_id)
  or exists(select 1 from public.meeting_occurrences o join public.meetings m on m.id=o.meeting_id
    where o.id=occurrence_id and m.requested_by=auth.uid())
);

-- Conserva nombres y consumo energético de catálogos archivados en reportes históricos.
drop policy if exists organizations_read on public.organizations;
create policy organizations_read on public.organizations for select to authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_reportes'));
drop policy if exists departments_read on public.departments;
create policy departments_read on public.departments for select to authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_reportes'));
drop policy if exists meeting_types_read on public.meeting_types;
create policy meeting_types_read on public.meeting_types for select to authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_reportes'));
drop policy if exists configuration_types_read on public.configuration_types;
create policy configuration_types_read on public.configuration_types for select to authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_reportes'));
drop policy if exists rooms_read on public.rooms;
create policy rooms_read on public.rooms for select to authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_reportes'));
drop policy if exists spaces_read on public.spaces;
create policy spaces_read on public.spaces for select to authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_reportes'));
drop policy if exists paid_offerings_read on public.paid_space_offerings;
create policy paid_offerings_read on public.paid_space_offerings for select to anon,authenticated
  using (is_active or private.is_admin() or private.has_salas_permission('ver_solicitudes_pago'));

revoke all on function private.has_salas_permission(text) from public,anon,authenticated;
revoke all on function private.has_approved_salas_occurrence(uuid) from public,anon,authenticated;
revoke all on function private.is_paid_salas_meeting(uuid) from public,anon,authenticated;
revoke all on function private.can_read_salas_occurrence(uuid) from public,anon,authenticated;
grant execute on function private.has_salas_permission(text) to authenticated;
grant execute on function private.has_approved_salas_occurrence(uuid) to authenticated;
grant execute on function private.is_paid_salas_meeting(uuid) to authenticated;
grant execute on function private.can_read_salas_occurrence(uuid) to authenticated;

commit;
