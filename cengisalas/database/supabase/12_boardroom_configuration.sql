-- CengiSalas / Supabase
-- 12: distribución Tipo Juntas y matriz de configuraciones por salón.
-- Ejecutar después de 11_public_requests_and_contact.sql.

begin;

insert into public.configuration_types(code,name,description,image_path,is_active)
values('BOARDROOM','Tipo Juntas','Mesa central con sillas alrededor para reuniones de junta.','assets/tipo-juntas.svg',true)
on conflict(code) do update set
  name=excluded.name,
  description=excluded.description,
  image_path=excluded.image_path,
  is_active=excluded.is_active;

-- Capacidades iniciales de Tipo Juntas. Se toma como referencia la capacidad
-- anterior de Tipo U; pueden ajustarse posteriormente si cambia el mobiliario.
insert into public.space_capacities(space_id,configuration_type_id,capacity)
select s.id,c.id,x.capacity
from(values
  ('SPACE_3',12),
  ('SPACE_4',14),
  ('SPACE_5',10),
  ('SPACE_6',16),
  ('SPACE_7',18),
  ('SPACE_8',20)
) x(space_code,capacity)
join public.spaces s on s.code=x.space_code
join public.configuration_types c on c.code='BOARDROOM'
on conflict(space_id,configuration_type_id) do update set capacity=excluded.capacity;

-- Salones 1 y 2: U/Escuela. Salón 3: las tres. Salones 4 a 8: solo Juntas.
delete from public.space_capacities sc
using public.spaces s,public.configuration_types c
where sc.space_id=s.id
  and sc.configuration_type_id=c.id
  and(
    (s.code in ('SPACE_1','SPACE_2','SPACE_1_2') and c.code not in ('U','SCHOOL'))
    or (s.code='SPACE_3' and c.code not in ('U','SCHOOL','BOARDROOM'))
    or (s.code in ('SPACE_4','SPACE_5','SPACE_6','SPACE_7','SPACE_8') and c.code<>'BOARDROOM')
  );

-- La regla se aplica también en el servidor para impedir configuraciones
-- manipuladas desde el navegador u otro cliente.
create or replace function private.validate_space_capacity(
  p_space_id bigint,
  p_configuration_type_id bigint,
  p_attendees integer
)
returns void
language plpgsql
stable
security definer
set search_path=''
as $$
declare
  v_capacity integer;
  v_space_code text;
  v_configuration_code text;
begin
  if not exists(
    select 1
    from public.spaces s
    join public.space_rooms sr on sr.space_id=s.id
    where s.id=p_space_id and s.is_active
  ) or exists(
    select 1
    from public.space_rooms sr
    join public.rooms r on r.id=sr.room_id
    where sr.space_id=p_space_id and not r.is_active
  ) then
    raise exception 'El espacio no existe, está inactivo o contiene un salón físico inactivo.';
  end if;

  select s.code,c.code into v_space_code,v_configuration_code
  from public.spaces s
  cross join public.configuration_types c
  where s.id=p_space_id
    and c.id=p_configuration_type_id
    and c.is_active;

  if v_space_code is null or v_configuration_code is null then
    raise exception 'El espacio o la configuración seleccionada no existe o está inactiva.';
  end if;

  if v_space_code in ('SPACE_1','SPACE_2','SPACE_1_2') and v_configuration_code not in ('U','SCHOOL') then
    raise exception 'Los salones 1 y 2 únicamente admiten las configuraciones Tipo U y Tipo Escuela.';
  end if;
  if v_space_code='SPACE_3' and v_configuration_code not in ('U','SCHOOL','BOARDROOM') then
    raise exception 'El Salón 3 únicamente admite Tipo U, Tipo Escuela o Tipo Juntas.';
  end if;
  if v_space_code in ('SPACE_4','SPACE_5','SPACE_6','SPACE_7','SPACE_8') and v_configuration_code<>'BOARDROOM' then
    raise exception 'Los salones 4, 5, 6, 7 y 8 únicamente admiten la configuración Tipo Juntas.';
  end if;

  select sc.capacity into v_capacity
  from public.space_capacities sc
  join public.spaces s on s.id=sc.space_id
  where sc.space_id=p_space_id
    and sc.configuration_type_id=p_configuration_type_id
    and s.is_active;

  if v_capacity is null then
    raise exception 'El espacio no admite la configuración seleccionada.';
  end if;
  if p_attendees>v_capacity then
    raise exception 'La cantidad de asistentes (%) supera la capacidad del espacio (%).',p_attendees,v_capacity;
  end if;
end;
$$;

revoke all on function private.validate_space_capacity(bigint,bigint,integer) from public,anon,authenticated;

select pg_notify('pgrst','reload schema');

commit;
