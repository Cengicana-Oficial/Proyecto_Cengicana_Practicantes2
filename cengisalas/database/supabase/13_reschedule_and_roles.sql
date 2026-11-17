-- CengiSalas / Supabase
-- 13: cambio administrativo de horario y de rol de usuarios activos.
-- Ejecutar después de 12_boardroom_configuration.sql.

begin;

create or replace function public.admin_reschedule_occurrence(
  p_occurrence_id uuid,
  p_start_time time without time zone,
  p_end_time time without time zone,
  p_reason text default 'Cambio de horario por administración',
  p_apply_to_series boolean default false
)
returns integer
language plpgsql
security definer
set search_path=''
as $$
declare
  v_target public.meeting_occurrences%rowtype;
  v_occ record;
  v_new_starts_at timestamptz;
  v_new_ends_at timestamptz;
  v_count integer:=0;
begin
  perform private.require_admin();
  if p_start_time is null or p_end_time is null or p_end_time<=p_start_time then
    raise exception 'La hora final debe ser posterior a la hora de inicio.';
  end if;
  if nullif(btrim(p_reason),'') is null then
    raise exception 'Debe indicar el motivo del cambio de horario.';
  end if;

  select * into strict v_target
  from public.meeting_occurrences
  where id=p_occurrence_id
  for update;

  if v_target.status='cancelled' then
    raise exception 'No se puede cambiar el horario de una reunión cancelada.';
  end if;

  for v_occ in
    select o.*,m.configuration_type_id,m.attendees
    from public.meeting_occurrences o
    join public.meetings m on m.id=o.meeting_id
    where (o.id=p_occurrence_id or (p_apply_to_series and o.meeting_id=v_target.meeting_id))
      and o.status<>'cancelled'
    order by o.starts_at
    for update of o
  loop
    v_new_starts_at:=(((v_occ.starts_at at time zone 'America/Guatemala')::date+p_start_time) at time zone 'America/Guatemala');
    v_new_ends_at:=(((v_occ.starts_at at time zone 'America/Guatemala')::date+p_end_time) at time zone 'America/Guatemala');

    perform private.validate_space_capacity(v_occ.space_id,v_occ.configuration_type_id,v_occ.attendees);
    if not private.space_is_available(v_occ.space_id,v_new_starts_at,v_new_ends_at,v_occ.id) then
      raise exception 'No se puede cambiar el horario: el salón está ocupado en una de las fechas seleccionadas.';
    end if;

    if v_occ.status='approved' then
      begin
        update public.room_blocks
        set starts_at=v_new_starts_at,ends_at=v_new_ends_at
        where occurrence_id=v_occ.id and active;
      exception when exclusion_violation then
        raise exception 'No se puede cambiar el horario: uno de los salones físicos ya está ocupado.';
      end;
    end if;

    update public.meeting_occurrences
    set starts_at=v_new_starts_at,ends_at=v_new_ends_at
    where id=v_occ.id;

    insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,old_data,new_data)
    values(
      auth.uid(),'admin','reschedule','meeting_occurrence',v_occ.id::text,
      jsonb_build_object('starts_at',v_occ.starts_at,'ends_at',v_occ.ends_at),
      jsonb_build_object('starts_at',v_new_starts_at,'ends_at',v_new_ends_at,'reason',btrim(p_reason))
    );
    insert into public.board_events(event_type,entity_type,entity_id)
    values('upsert','meeting_occurrence',v_occ.id::text);
    v_count:=v_count+1;
  end loop;

  return v_count;
end;
$$;

create or replace function public.admin_change_user_role(
  p_user_id uuid,
  p_role public.app_role
)
returns void
language plpgsql
security definer
set search_path=''
as $$
declare
  v_old_role public.app_role;
  v_status public.user_account_status;
  v_is_active boolean;
begin
  perform private.require_admin();
  if p_role is null then raise exception 'Debe seleccionar un rol.'; end if;

  select p.role,p.account_status,p.is_active
  into strict v_old_role,v_status,v_is_active
  from public.profiles p
  where p.id=p_user_id
  for update;

  if v_status<>'active' or not v_is_active then
    raise exception 'Solo se puede cambiar el rol de usuarios activos.';
  end if;
  if v_old_role=p_role then return; end if;
  if p_user_id=auth.uid() then
    raise exception 'No puede cambiar el rol de su propia sesión.';
  end if;
  if v_old_role='admin' and p_role='user' and(
    select count(*) from public.profiles where role='admin' and account_status='active' and is_active
  )<=1 then
    raise exception 'No puede quitar el rol al último administrador activo.';
  end if;

  update public.profiles
  set role=p_role,reviewed_by=auth.uid(),reviewed_at=now()
  where id=p_user_id;

  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,old_data,new_data)
  values(
    auth.uid(),'admin','change_role','user',p_user_id::text,
    jsonb_build_object('role',v_old_role),jsonb_build_object('role',p_role)
  );
  insert into public.board_events(event_type,entity_type,entity_id)
  values('upsert','user',p_user_id::text);
end;
$$;

revoke all on function public.admin_reschedule_occurrence(uuid,time without time zone,time without time zone,text,boolean) from public,anon,authenticated;
revoke all on function public.admin_change_user_role(uuid,public.app_role) from public,anon,authenticated;
grant execute on function public.admin_reschedule_occurrence(uuid,time without time zone,time without time zone,text,boolean) to authenticated;
grant execute on function public.admin_change_user_role(uuid,public.app_role) to authenticated;

select pg_notify('pgrst','reload schema');

commit;
