-- CengiSalas / Supabase
-- 39: incidencias, segunda etapa.
--   * Tipo "other" (Otro) con descripción obligatoria.
--   * Revisión por sesión: "sin novedades" o "con incidencias", con quién y cuándo.
--   * Seguimiento por incidencia: abierta / resuelta, con quién, cuándo y nota de cierre.
--   * Guardar ya no borra y recrea todo: conserva el seguimiento de las incidencias que siguen
--     registradas y solo elimina las que se quitaron.
-- Requiere 27_meeting_incidents.sql. Se puede ejecutar más de una vez.

begin;

-- 1) Columnas de seguimiento
alter table public.meeting_incidents
  add column if not exists status text not null default 'open',
  add column if not exists resolved_at timestamptz,
  add column if not exists resolved_by uuid references auth.users(id) on delete set null,
  add column if not exists resolved_by_name text,
  add column if not exists resolution_notes text;

-- 2) Reglas de la tabla: se reemplazan las restricciones CHECK (sin nombre en la versión 27)
do $$
declare r record;
begin
  for r in select conname from pg_constraint
           where conrelid = 'public.meeting_incidents'::regclass and contype = 'c' loop
    execute format('alter table public.meeting_incidents drop constraint %I', r.conname);
  end loop;
end $$;

alter table public.meeting_incidents
  add constraint meeting_incidents_type_check
    check (incident_type in ('screen_on','ac_unattended','missing_equipment','other')),
  add constraint meeting_incidents_item_allowed_check
    check (incident_type in ('missing_equipment','other') or item_name is null),
  add constraint meeting_incidents_item_required_check
    check (incident_type not in ('missing_equipment','other') or nullif(btrim(item_name),'') is not null),
  add constraint meeting_incidents_status_check
    check (status in ('open','resolved')),
  add constraint meeting_incidents_resolution_check
    check (status = 'resolved' or (resolved_at is null and resolution_notes is null));

create index if not exists ix_meeting_incidents_status on public.meeting_incidents(status, created_at desc);

-- 3) Revisión de cada sesión
create table if not exists public.meeting_incident_reviews (
  occurrence_id uuid primary key references public.meeting_occurrences(id) on delete cascade,
  result text not null check (result in ('no_issues','with_issues')),
  reviewed_by uuid references auth.users(id) on delete set null,
  reviewed_by_name text not null default 'Sistema',
  reviewed_at timestamptz not null default now()
);

alter table public.meeting_incident_reviews enable row level security;
revoke all on public.meeting_incident_reviews from anon, authenticated;
grant select on public.meeting_incident_reviews to authenticated;

drop policy if exists meeting_incident_reviews_read on public.meeting_incident_reviews;
create policy meeting_incident_reviews_read on public.meeting_incident_reviews for select to authenticated
using (
  private.is_admin()
  or exists (
    select 1 from public.meeting_occurrences o
    join public.meetings m on m.id = o.meeting_id
    where o.id = meeting_incident_reviews.occurrence_id and m.requested_by = auth.uid()
  )
);

-- Las sesiones que ya tienen incidencias quedan como revisadas "con incidencias".
insert into public.meeting_incident_reviews(occurrence_id, result, reviewed_by, reviewed_by_name, reviewed_at)
select i.occurrence_id, 'with_issues',
       (array_agg(i.reported_by order by i.created_at))[1],
       (array_agg(i.reported_by_name order by i.created_at))[1],
       min(i.created_at)
from public.meeting_incidents i
group by i.occurrence_id
on conflict (occurrence_id) do nothing;

-- 4) Consulta: agrega la revisión y el seguimiento (cambia el tipo de retorno)
drop function if exists public.get_incident_occurrences();
create function public.get_incident_occurrences()
returns table (
  occurrence_id uuid, meeting_id uuid, request_code text, title text,
  requester_name text, requested_by uuid, organization_name text,
  space_id bigint, space_name text, starts_at timestamptz, ends_at timestamptz,
  status public.meeting_status, configuration_name text, attendees integer,
  incidents jsonb,
  review_result text, reviewed_by_name text, reviewed_at timestamptz
)
language sql stable security definer set search_path = ''
as $$
  select o.id,m.id,m.request_code,m.title,m.requester_name,m.requested_by,org.name,
    s.id,s.name,o.starts_at,o.ends_at,o.status,ct.name,m.attendees,
    coalesce((select jsonb_agg(jsonb_build_object(
      'id',i.id,'type',i.incident_type,'itemName',i.item_name,'notes',i.notes,
      'reportedBy',i.reported_by_name,'createdAt',i.created_at,'updatedAt',i.updated_at,
      'status',i.status,'resolvedAt',i.resolved_at,'resolvedBy',i.resolved_by_name,
      'resolutionNotes',i.resolution_notes
    ) order by i.incident_type,i.item_name nulls first,i.id)
    from public.meeting_incidents i where i.occurrence_id=o.id),'[]'::jsonb),
    r.result, r.reviewed_by_name, r.reviewed_at
  from public.meeting_occurrences o
  join public.meetings m on m.id=o.meeting_id
  join public.organizations org on org.id=m.request_organization_id
  join public.spaces s on s.id=o.space_id
  join public.configuration_types ct on ct.id=o.configuration_type_id
  left join public.meeting_incident_reviews r on r.occurrence_id=o.id
  where o.status='approved' and (private.is_admin() or m.requested_by=auth.uid())
  order by o.starts_at desc,s.name;
$$;

-- 5) Guardar: conserva el seguimiento y registra la revisión.
--    Lista vacía = sesión revisada "sin novedades".
create or replace function public.admin_save_meeting_incidents(
  p_occurrence_id uuid, p_incidents jsonb default '[]'::jsonb
)
returns void language plpgsql security definer set search_path = ''
as $$
declare
  v_occ public.meeting_occurrences%rowtype;
  v_item jsonb; v_type text; v_item_name text; v_notes text;
  v_keys text[] := '{}';
begin
  perform private.require_admin();
  select * into strict v_occ from public.meeting_occurrences where id=p_occurrence_id for update;
  if v_occ.status<>'approved' then raise exception 'Solo se pueden registrar incidencias en reuniones aprobadas.'; end if;
  if p_incidents is null or jsonb_typeof(p_incidents)<>'array' or jsonb_array_length(p_incidents)>100 then
    raise exception 'La lista de incidencias no es válida.';
  end if;

  for v_item in select value from jsonb_array_elements(p_incidents) loop
    v_type := lower(nullif(btrim(v_item->>'type'),''));
    v_item_name := nullif(btrim(v_item->>'itemName'),'');
    v_notes := nullif(btrim(v_item->>'notes'),'');
    if v_type is null or v_type not in ('screen_on','ac_unattended','missing_equipment','other') then
      raise exception 'Tipo de incidencia inválido.';
    end if;
    if v_type='missing_equipment' and v_item_name is null then raise exception 'Cada equipo faltante debe tener un nombre.'; end if;
    if v_type='other' and v_item_name is null then raise exception 'Describe la incidencia marcada como Otro.'; end if;
    if v_type not in ('missing_equipment','other') then v_item_name := null; end if;
    if length(coalesce(v_item_name,''))>200 then raise exception 'La descripción no puede superar 200 caracteres.'; end if;
    if length(coalesce(v_notes,''))>1000 then raise exception 'La observación no puede superar 1000 caracteres.'; end if;

    v_keys := v_keys || (v_type || ':' || coalesce(lower(v_item_name),''));
    insert into public.meeting_incidents(occurrence_id,incident_type,item_name,notes,reported_by,reported_by_name)
    values(p_occurrence_id,v_type,v_item_name,v_notes,auth.uid(),private.actor_name())
    on conflict (occurrence_id,incident_key) do update
      set notes=excluded.notes, updated_at=now();
  end loop;

  delete from public.meeting_incidents
  where occurrence_id=p_occurrence_id and not (incident_key = any(v_keys));

  insert into public.meeting_incident_reviews(occurrence_id,result,reviewed_by,reviewed_by_name,reviewed_at)
  values(p_occurrence_id, case when cardinality(v_keys)=0 then 'no_issues' else 'with_issues' end,
         auth.uid(), private.actor_name(), now())
  on conflict (occurrence_id) do update
    set result=excluded.result, reviewed_by=excluded.reviewed_by,
        reviewed_by_name=excluded.reviewed_by_name, reviewed_at=now();

  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','save_incidents','meeting_occurrence',p_occurrence_id::text,
         jsonb_build_object('incidents',p_incidents));
  insert into public.board_events(event_type,entity_type,entity_id) values('incident','meeting_occurrence',p_occurrence_id::text);
end;
$$;

-- 6) Seguimiento: marcar una incidencia como resuelta o reabrirla
create or replace function public.admin_set_incident_status(
  p_incident_id bigint, p_status text, p_notes text default null
)
returns void language plpgsql security definer set search_path = ''
as $$
declare v_status text := lower(nullif(btrim(p_status),'')); v_notes text := nullif(btrim(p_notes),'');
        v_occurrence uuid;
begin
  perform private.require_admin();
  if v_status is null or v_status not in ('open','resolved') then raise exception 'Estado de seguimiento inválido.'; end if;
  if length(coalesce(v_notes,''))>1000 then raise exception 'La nota no puede superar 1000 caracteres.'; end if;
  update public.meeting_incidents
     set status=v_status,
         resolved_at=case when v_status='resolved' then now() end,
         resolved_by=case when v_status='resolved' then auth.uid() end,
         resolved_by_name=case when v_status='resolved' then private.actor_name() end,
         resolution_notes=case when v_status='resolved' then v_notes end,
         updated_at=now()
   where id=p_incident_id
  returning occurrence_id into v_occurrence;
  if v_occurrence is null then raise exception 'No se encontró la incidencia.'; end if;
  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','set_incident_status','meeting_incident',p_incident_id::text,
         jsonb_build_object('status',v_status,'notes',v_notes));
  insert into public.board_events(event_type,entity_type,entity_id) values('incident','meeting_occurrence',v_occurrence::text);
end;
$$;

revoke all on function public.get_incident_occurrences() from public,anon,authenticated;
grant execute on function public.get_incident_occurrences() to authenticated;
revoke all on function public.admin_save_meeting_incidents(uuid,jsonb) from public,anon,authenticated;
grant execute on function public.admin_save_meeting_incidents(uuid,jsonb) to authenticated;
revoke all on function public.admin_set_incident_status(bigint,text,text) from public,anon,authenticated;
grant execute on function public.admin_set_incident_status(bigint,text,text) to authenticated;

commit;
