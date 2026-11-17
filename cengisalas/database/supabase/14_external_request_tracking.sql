-- CengiSalas / Supabase
-- 14: seguimiento público seguro de solicitudes externas por código y correo.

begin;

create or replace function public.track_external_request(p_request_code text,p_contact_email text)
returns jsonb
language plpgsql
stable
security definer
set search_path=''
as $$
declare v_result jsonb;
begin
  if length(btrim(coalesce(p_request_code,''))) not between 12 and 40
     or length(btrim(coalesce(p_contact_email,''))) not between 5 and 254 then
    return jsonb_build_object('found',false);
  end if;

  select jsonb_build_object(
    'found',true,'requestCode',m.request_code,'title',m.title,'organization',org.name,
    'meetingType',mt.name,'configuration',ct.name,'attendees',m.attendees,
    'recurrenceType',m.recurrence_type,'createdAt',m.created_at,
    'updatedAt',greatest(m.updated_at,coalesce(summary.last_change,m.updated_at)),
    'overallStatus',summary.overall_status,'summary',summary.status_counts,'occurrences',summary.occurrences
  ) into v_result
  from public.meetings m
  join public.organizations org on org.id=m.request_organization_id
  join public.meeting_types mt on mt.id=m.meeting_type_id
  join public.configuration_types ct on ct.id=m.configuration_type_id
  cross join lateral (
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
        'startsAt',o.starts_at,'endsAt',o.ends_at,'status',o.status,'spaceName',s.name,
        'configuration',ct.name,'statusAt',coalesce(last_status.changed_at,o.created_at),
        'reason',case when o.status in ('rejected','cancelled') then last_status.reason else null end
      ) order by o.starts_at) occurrences
    from public.meeting_occurrences o
    join public.spaces s on s.id=o.space_id
    left join lateral (
      select h.changed_at,h.reason from public.meeting_occurrence_status_history h
      where h.occurrence_id=o.id and h.to_status=o.status order by h.changed_at desc limit 1
    ) last_status on true
    where o.meeting_id=m.id
  ) summary
  where m.request_source='public'
    and upper(m.request_code)=upper(btrim(p_request_code))
    and lower(m.contact_email)=lower(btrim(p_contact_email))
  limit 1;

  return coalesce(v_result,jsonb_build_object('found',false));
end;
$$;

revoke all on function public.track_external_request(text,text) from public,anon,authenticated;
grant execute on function public.track_external_request(text,text) to anon,authenticated;

commit;
