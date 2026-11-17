-- CengiSalas / Supabase
-- 27: registro de incidencias encontradas al finalizar una reunión.

begin;

create table if not exists public.meeting_incidents (
  id bigint generated always as identity primary key,
  occurrence_id uuid not null references public.meeting_occurrences(id) on delete cascade,
  incident_type text not null check (incident_type in ('screen_on','ac_unattended','missing_equipment')),
  item_name text,
  notes text,
  reported_by uuid references auth.users(id) on delete set null,
  reported_by_name text not null default 'Sistema',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  check (incident_type = 'missing_equipment' or item_name is null),
  check (incident_type <> 'missing_equipment' or nullif(btrim(item_name),'') is not null),
  incident_key text generated always as (
    incident_type || ':' || coalesce(lower(btrim(item_name)), '')
  ) stored,
  unique (occurrence_id, incident_key)
);

create index if not exists ix_meeting_incidents_occurrence on public.meeting_incidents(occurrence_id, created_at desc);
create index if not exists ix_meeting_incidents_type on public.meeting_incidents(incident_type, created_at desc);

alter table public.meeting_incidents enable row level security;
revoke all on public.meeting_incidents from anon, authenticated;
grant select on public.meeting_incidents to authenticated;

drop policy if exists meeting_incidents_read on public.meeting_incidents;
create policy meeting_incidents_read on public.meeting_incidents for select to authenticated
using (
  private.is_admin()
  or exists (
    select 1 from public.meeting_occurrences o
    join public.meetings m on m.id = o.meeting_id
    where o.id = meeting_incidents.occurrence_id and m.requested_by = auth.uid()
  )
);

create or replace function public.get_incident_occurrences()
returns table (
  occurrence_id uuid, meeting_id uuid, request_code text, title text,
  requester_name text, requested_by uuid, organization_name text,
  space_id bigint, space_name text, starts_at timestamptz, ends_at timestamptz,
  status public.meeting_status, configuration_name text, attendees integer,
  incidents jsonb
)
language sql stable security definer set search_path = ''
as $$
  select o.id,m.id,m.request_code,m.title,m.requester_name,m.requested_by,org.name,
    s.id,s.name,o.starts_at,o.ends_at,o.status,ct.name,m.attendees,
    coalesce((select jsonb_agg(jsonb_build_object(
      'id',i.id,'type',i.incident_type,'itemName',i.item_name,'notes',i.notes,
      'reportedBy',i.reported_by_name,'createdAt',i.created_at
    ) order by i.incident_type,i.item_name nulls first,i.id)
    from public.meeting_incidents i where i.occurrence_id=o.id),'[]'::jsonb)
  from public.meeting_occurrences o
  join public.meetings m on m.id=o.meeting_id
  join public.organizations org on org.id=m.request_organization_id
  join public.spaces s on s.id=o.space_id
  join public.configuration_types ct on ct.id=o.configuration_type_id
  where o.status='approved' and (private.is_admin() or m.requested_by=auth.uid())
  order by o.starts_at desc,s.name;
$$;

create or replace function public.admin_save_meeting_incidents(
  p_occurrence_id uuid, p_incidents jsonb default '[]'::jsonb
)
returns void language plpgsql security definer set search_path = ''
as $$
declare v_occ public.meeting_occurrences%rowtype;v_item jsonb;v_type text;v_item_name text;v_notes text;
begin
  perform private.require_admin();
  select * into strict v_occ from public.meeting_occurrences where id=p_occurrence_id for update;
  if v_occ.status<>'approved' then raise exception 'Solo se pueden registrar incidencias en reuniones aprobadas.'; end if;
  if p_incidents is null or jsonb_typeof(p_incidents)<>'array' or jsonb_array_length(p_incidents)>100 then raise exception 'La lista de incidencias no es válida.'; end if;
  delete from public.meeting_incidents where occurrence_id=p_occurrence_id;
  for v_item in select value from jsonb_array_elements(coalesce(p_incidents,'[]'::jsonb)) loop
    v_type:=lower(nullif(btrim(v_item->>'type'),''));v_item_name:=nullif(btrim(v_item->>'itemName'),'');v_notes:=nullif(btrim(v_item->>'notes'),'');
    if v_type not in ('screen_on','ac_unattended','missing_equipment') then raise exception 'Tipo de incidencia inválido.'; end if;
    if v_type='missing_equipment' and v_item_name is null then raise exception 'Cada equipo faltante debe tener un nombre.'; end if;
    if v_type<>'missing_equipment' then v_item_name:=null; end if;
    insert into public.meeting_incidents(occurrence_id,incident_type,item_name,notes,reported_by,reported_by_name)
    values(p_occurrence_id,v_type,v_item_name,v_notes,auth.uid(),private.actor_name())
    on conflict (occurrence_id,incident_key) do update set notes=excluded.notes,reported_by=excluded.reported_by,reported_by_name=excluded.reported_by_name,updated_at=now();
  end loop;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','save_incidents','meeting_occurrence',p_occurrence_id::text,jsonb_build_object('incidents',coalesce(p_incidents,'[]'::jsonb)));
  insert into public.board_events(event_type,entity_type,entity_id) values('incident','meeting_occurrence',p_occurrence_id::text);
end;
$$;

revoke all on function public.get_incident_occurrences() from public,anon,authenticated;
grant execute on function public.get_incident_occurrences() to authenticated;
revoke all on function public.admin_save_meeting_incidents(uuid,jsonb) from public,anon,authenticated;
grant execute on function public.admin_save_meeting_incidents(uuid,jsonb) to authenticated;

commit;
