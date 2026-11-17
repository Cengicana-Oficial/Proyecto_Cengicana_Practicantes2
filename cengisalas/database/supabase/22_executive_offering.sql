-- CengiSalas / Supabase
-- 22: agrega la oferta comercial Salón Ejecutivo (Salón 3).

do $$
declare c record;
begin
  for c in select conname from pg_constraint where conrelid='public.paid_space_offerings'::regclass and contype='c' and (pg_get_constraintdef(oid) like '%allocation_mode%' or pg_get_constraintdef(oid) like '%code%') loop
    execute format('alter table public.paid_space_offerings drop constraint %I',c.conname);
  end loop;
end $$;

alter table public.paid_space_offerings add constraint paid_space_offerings_code_check check(code in ('MAIN_HALL','TRAINING_HALL','EXECUTIVE_HALL'));
alter table public.paid_space_offerings add constraint paid_space_offerings_allocation_check check(allocation_mode in ('combined','either','single'));

insert into public.paid_space_offerings(code,name,description,allocation_mode,capacities,price_one_hour,price_four_to_eight_hours,price_extra_hour_after_eight)
select 'EXECUTIVE_HALL','Salón Ejecutivo','Salón 3 para reuniones ejecutivas de hasta 15 personas.','single',coalesce((select jsonb_object_agg(ct.code,sc.capacity) from public.space_capacities sc join public.configuration_types ct on ct.id=sc.configuration_type_id where sc.space_id=s.id),'{}'::jsonb),coalesce((select r.cost_one_hour from public.rooms r where r.code='ROOM_3'),0),coalesce((select r.cost_four_to_eight_hours from public.rooms r where r.code='ROOM_3'),0),coalesce((select r.cost_extra_hour_after_eight from public.rooms r where r.code='ROOM_3'),0)
from public.spaces s where s.code='SPACE_3' on conflict(code) do nothing;
