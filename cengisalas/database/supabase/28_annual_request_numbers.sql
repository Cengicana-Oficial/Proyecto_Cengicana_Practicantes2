-- CengiSalas / Supabase
-- 28: correlativos anuales para solicitudes: AAAA-NNN.
-- Conserva la consulta de códigos históricos CS-* y SF-*.

begin;

create table if not exists private.request_code_sequences (
  request_year integer primary key check (request_year between 2020 and 9999),
  last_number bigint not null check (last_number >= 0),
  updated_at timestamptz not null default now()
);

create table if not exists private.request_code_aliases (
  old_code text primary key,
  meeting_id uuid not null unique references public.meetings(id) on delete cascade,
  created_at timestamptz not null default now()
);

-- Convierte también las solicitudes existentes la primera vez que se instala.
-- El código anterior queda guardado como alias para que los enlaces y
-- comprobantes ya entregados continúen funcionando.
do $migration$
begin
  if exists(select 1 from public.meetings)
     and not exists(select 1 from public.meetings where request_code~'^[0-9]{4}-[0-9]{3,}$') then
    insert into private.request_code_aliases(old_code,meeting_id)
    select m.request_code,m.id
    from public.meetings m
    on conflict do nothing;

    with ranked as(
      select
        m.id,
        extract(year from timezone('America/Guatemala',m.created_at))::integer request_year,
        row_number() over(
          partition by extract(year from timezone('America/Guatemala',m.created_at))::integer
          order by m.created_at,m.id
        ) request_number
      from public.meetings m
    )
    update public.meetings m
    set request_code=r.request_year::text||'-'||
      case when r.request_number<1000 then lpad(r.request_number::text,3,'0') else r.request_number::text end
    from ranked r
    where r.id=m.id;
  end if;
end;
$migration$;

-- El siguiente número continúa después del correlativo mayor de cada año.
insert into private.request_code_sequences(request_year,last_number,updated_at)
select
  extract(year from timezone('America/Guatemala',m.created_at))::integer,
  greatest(
    count(*)::bigint,
    max(case
      when m.request_code~'^[0-9]{4}-[0-9]{3,}$'
        then split_part(m.request_code,'-',2)::bigint
      else 0
    end)
  ),
  now()
from public.meetings m
group by extract(year from timezone('America/Guatemala',m.created_at))::integer
on conflict(request_year) do update
set last_number=greatest(private.request_code_sequences.last_number,excluded.last_number),
    updated_at=now();

create or replace function private.generate_request_code()
returns text
language plpgsql
volatile
security definer
set search_path=''
as $$
declare
  v_year integer:=extract(year from timezone('America/Guatemala',now()))::integer;
  v_number bigint;
  v_code text;
begin
  loop
    insert into private.request_code_sequences(request_year,last_number,updated_at)
    values(v_year,1,now())
    on conflict(request_year) do update
    set last_number=private.request_code_sequences.last_number+1,
        updated_at=now()
    returning last_number into v_number;

    v_code:=v_year::text||'-'||
      case when v_number<1000 then lpad(v_number::text,3,'0') else v_number::text end;

    exit when not exists(
      select 1 from public.meetings m where upper(m.request_code)=upper(v_code)
    );
  end loop;

  return v_code;
end;
$$;

-- Se conserva la compatibilidad con los códigos entregados antes de esta
-- migración, además del nuevo correlativo anual.
create or replace function public.track_external_request(p_request_code text)
returns jsonb
language plpgsql
stable
security definer
set search_path=''
as $$
declare
  v_result jsonb;
  v_code text:=upper(btrim(coalesce(p_request_code,'')));
begin
  if v_code!~ '^([0-9]{4}-[0-9]{3,}|CS-[0-9A-F]{12}|SF-[0-9]{8}-[0-9A-F]{8})$' then
    return jsonb_build_object('found',false);
  end if;

  select jsonb_build_object(
    'found',true,
    'requestCode',m.request_code,
    'title',m.title,
    'organization',org.name,
    'meetingType',mt.name,
    'configuration',first_config.name,
    'attendees',m.attendees,
    'recurrenceType',m.recurrence_type,
    'createdAt',m.created_at,
    'updatedAt',greatest(m.updated_at,coalesce(summary.last_change,m.updated_at)),
    'overallStatus',summary.overall_status,
    'summary',summary.status_counts,
    'occurrences',summary.occurrences
  )
  into v_result
  from public.meetings m
  join public.organizations org on org.id=m.request_organization_id
  join public.meeting_types mt on mt.id=m.meeting_type_id
  join public.configuration_types first_config on first_config.id=m.configuration_type_id
  left join private.request_code_aliases legacy_code on legacy_code.meeting_id=m.id
  cross join lateral(
    select
      case when count(distinct o.status)=1 then min(o.status::text) else 'mixed' end overall_status,
      max(coalesce(last_status.changed_at,o.updated_at)) last_change,
      jsonb_build_object(
        'pending',count(*) filter(where o.status='pending'),
        'approved',count(*) filter(where o.status='approved'),
        'rejected',count(*) filter(where o.status='rejected'),
        'cancelled',count(*) filter(where o.status='cancelled')
      ) status_counts,
      jsonb_agg(jsonb_build_object(
        'startsAt',o.starts_at,
        'endsAt',o.ends_at,
        'status',o.status,
        'spaceName',s.name,
        'configuration',ct.name,
        'statusAt',coalesce(last_status.changed_at,o.created_at),
        'reason',case when o.status in('rejected','cancelled') then last_status.reason else null end
      ) order by o.starts_at) occurrences
    from public.meeting_occurrences o
    join public.spaces s on s.id=o.space_id
    join public.configuration_types ct on ct.id=o.configuration_type_id
    left join lateral(
      select h.changed_at,h.reason
      from public.meeting_occurrence_status_history h
      where h.occurrence_id=o.id and h.to_status=o.status
      order by h.changed_at desc
      limit 1
    ) last_status on true
    where o.meeting_id=m.id
  ) summary
  where m.request_source in('public','paid')
    and (upper(m.request_code)=v_code or upper(legacy_code.old_code)=v_code)
  limit 1;

  return coalesce(v_result,jsonb_build_object('found',false));
end;
$$;

revoke all on table private.request_code_sequences from public,anon,authenticated;
revoke all on table private.request_code_aliases from public,anon,authenticated;
revoke all on function private.generate_request_code() from public,anon,authenticated;
revoke all on function public.track_external_request(text) from public,anon,authenticated;
grant execute on function public.track_external_request(text) to anon,authenticated;

commit;
