-- CengiSalas / Supabase
-- 18: consumo eléctrico estimado por hora para cada salón.

begin;

alter table public.rooms
  add column if not exists hourly_consumption_kwh numeric(10,2) not null default 0;

do $$
begin
  if not exists(select 1 from pg_constraint where conname='rooms_hourly_consumption_nonnegative') then
    alter table public.rooms add constraint rooms_hourly_consumption_nonnegative
      check(hourly_consumption_kwh>=0);
  end if;
end $$;

drop function if exists public.admin_update_room_capacities_and_costs(bigint,jsonb,numeric,numeric,numeric);

create or replace function public.admin_update_room_capacities_and_costs(
  p_room_id bigint,
  p_capacities jsonb,
  p_hourly_consumption_kwh numeric,
  p_cost_one_hour numeric default null,
  p_cost_four_to_eight_hours numeric default null,
  p_cost_extra_hour_after_eight numeric default null
)
returns void
language plpgsql
security definer
set search_path=''
as $$
declare
  v_room public.rooms%rowtype;
  v_space_id bigint;
  v_expected integer;
  v_received integer;
  v_item record;
begin
  perform private.require_admin();

  select * into strict v_room from public.rooms where id=p_room_id;
  select sr.space_id into strict v_space_id
  from public.space_rooms sr
  join public.spaces s on s.id=sr.space_id
  where sr.room_id=p_room_id and s.space_type='single';

  if p_capacities is null or jsonb_typeof(p_capacities)<>'object' then
    raise exception 'Las capacidades deben enviarse como un objeto.';
  end if;
  if p_hourly_consumption_kwh is null or p_hourly_consumption_kwh<0 then
    raise exception 'El consumo eléctrico por hora debe ser cero o mayor.';
  end if;

  select count(*) into v_expected from public.space_capacities where space_id=v_space_id;
  select count(*) into v_received from jsonb_object_keys(p_capacities);
  if v_expected=0 or v_received<>v_expected then
    raise exception 'Debes indicar todas las capacidades permitidas para el salón.';
  end if;

  for v_item in select key,value from jsonb_each_text(p_capacities)
  loop
    if v_item.value !~ '^[1-9][0-9]*$' then
      raise exception 'Cada capacidad debe ser un número entero mayor que cero.';
    end if;
    update public.space_capacities sc
    set capacity=v_item.value::integer
    from public.configuration_types ct
    where sc.space_id=v_space_id
      and ct.id=sc.configuration_type_id
      and ct.code=v_item.key;
    if not found then
      raise exception 'La configuración % no está permitida para este salón.',v_item.key;
    end if;
  end loop;

  if v_room.allows_usage_cost then
    if p_cost_one_hour is null or p_cost_four_to_eight_hours is null or p_cost_extra_hour_after_eight is null then
      raise exception 'Debes completar las tres tarifas del salón.';
    end if;
    if p_cost_one_hour<0 or p_cost_four_to_eight_hours<0 or p_cost_extra_hour_after_eight<0 then
      raise exception 'Las tarifas no pueden ser negativas.';
    end if;
    update public.rooms set
      hourly_consumption_kwh=p_hourly_consumption_kwh,
      cost_one_hour=p_cost_one_hour,
      cost_four_to_eight_hours=p_cost_four_to_eight_hours,
      cost_extra_hour_after_eight=p_cost_extra_hour_after_eight
    where id=p_room_id;
  else
    if p_cost_one_hour is not null or p_cost_four_to_eight_hours is not null or p_cost_extra_hour_after_eight is not null then
      raise exception 'Las tarifas de uso solo aplican a los salones 1, 2 y 3.';
    end if;
    update public.rooms set
      hourly_consumption_kwh=p_hourly_consumption_kwh,
      cost_one_hour=null,
      cost_four_to_eight_hours=null,
      cost_extra_hour_after_eight=null
    where id=p_room_id;
  end if;

  -- La capacidad de espacios combinados se deriva de sus salones físicos.
  update public.space_capacities target
  set capacity=totals.capacity
  from (
    select combined.id as space_id,combined_capacity.configuration_type_id,
      sum(single_capacity.capacity)::integer as capacity
    from public.spaces combined
    join public.space_capacities combined_capacity on combined_capacity.space_id=combined.id
    join public.space_rooms combined_rooms on combined_rooms.space_id=combined.id
    join public.space_rooms single_link on single_link.room_id=combined_rooms.room_id
    join public.spaces single_space on single_space.id=single_link.space_id and single_space.space_type='single'
    join public.space_capacities single_capacity
      on single_capacity.space_id=single_space.id
      and single_capacity.configuration_type_id=combined_capacity.configuration_type_id
    where combined.space_type='combined'
      and exists(
        select 1 from public.space_rooms changed_room
        where changed_room.space_id=combined.id and changed_room.room_id=p_room_id
      )
    group by combined.id,combined_capacity.configuration_type_id
  ) totals
  where target.space_id=totals.space_id
    and target.configuration_type_id=totals.configuration_type_id;

  insert into public.audit_log(actor_id,actor_role,action,entity_type,entity_id,new_data)
  values(auth.uid(),'admin','update_capacities_costs_and_consumption','room',p_room_id::text,
    jsonb_build_object(
      'capacities',p_capacities,
      'hourly_consumption_kwh',p_hourly_consumption_kwh,
      'cost_one_hour',p_cost_one_hour,
      'cost_four_to_eight_hours',p_cost_four_to_eight_hours,
      'cost_extra_hour_after_eight',p_cost_extra_hour_after_eight
    ));
  insert into public.board_events(event_type,entity_type,entity_id)
  values('upsert','room',p_room_id::text);
end;
$$;

revoke all on function public.admin_update_room_capacities_and_costs(bigint,jsonb,numeric,numeric,numeric,numeric) from public,anon,authenticated;
grant execute on function public.admin_update_room_capacities_and_costs(bigint,jsonb,numeric,numeric,numeric,numeric) to authenticated;

commit;
