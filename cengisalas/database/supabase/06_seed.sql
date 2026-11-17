-- SalaFlow / Supabase
-- 06: catálogos iniciales, ocho salones, espacio combinado 1+2 y capacidades configuradas.

begin;

insert into public.organizations(code,name,can_request_refreshments,is_active) values
  ('CENGICANA','Cengicaña',true,true),
  ('ICC','ICC',false,true),
  ('ATAGUA','Atagua',false,true),
  ('ASAZGUA','Asazgua',false,true),
  ('FUNDAZUCAR','Fundazucar',false,true),
  ('INNOVAHUB','Innovahub',false,true),
  ('EXTERNAL','Externo',false,true)
on conflict (code) do update set
  name=excluded.name, can_request_refreshments=excluded.can_request_refreshments, is_active=excluded.is_active;

insert into public.departments(organization_id,name,is_active)
select o.id,d.name,true
from public.organizations o
cross join (values ('Administración'),('Tecnología'),('Dirección'),('Operaciones')) d(name)
where o.code='CENGICANA'
on conflict (organization_id,name) do update set is_active=true;

insert into public.departments(organization_id,name,is_active)
select o.id,'Externo',true from public.organizations o where o.code='EXTERNAL'
on conflict (organization_id,name) do update set is_active=true;

insert into public.meeting_types(code,name,is_active) values
  ('TEAM','Reunión de Equipo de Trabajo',true),
  ('COMMITTEE','Comité',true),
  ('VISIT','Visita',true)
on conflict (code) do update set name=excluded.name,is_active=excluded.is_active;

insert into public.configuration_types(code,name,description,image_path,is_active) values
  ('U','Tipo U','Mesas formando una U para facilitar la conversación.','assets/tipo-u.svg',true),
  ('SCHOOL','Tipo Escuela','Mesas en espina de pescado orientadas al instructor.','assets/tipo-escuela.svg',true),
  ('BOARDROOM','Tipo Juntas','Mesa central con sillas alrededor para reuniones de junta.','assets/tipo-juntas.svg',true)
on conflict (code) do update set
  name=excluded.name,description=excluded.description,image_path=excluded.image_path,is_active=excluded.is_active;

insert into public.refreshments(code,name,is_active) values
  ('COFFEE','Café',true),('SODA','Gaseosa',true),
  ('COOKIES','Galletas',true),('WATER','Agua Pura',true)
on conflict (code) do update set name=excluded.name,is_active=excluded.is_active;

insert into public.rooms(code,name,location,is_active) values
  ('ROOM_1','Salón 1','Nivel 1',true),('ROOM_2','Salón 2','Nivel 1',true),
  ('ROOM_3','Salón 3','Nivel 1',true),('ROOM_4','Salón 4','Nivel 2',true),
  ('ROOM_5','Salón 5','Nivel 2',true),('ROOM_6','Salón 6','Nivel 2',true),
  ('ROOM_7','Salón 7','Nivel 3',true),('ROOM_8','Salón 8','Nivel 3',true)
on conflict (code) do update set name=excluded.name,location=excluded.location,is_active=excluded.is_active;

insert into public.spaces(code,name,space_type,is_active) values
  ('SPACE_1','Salón 1','single',true),('SPACE_2','Salón 2','single',true),
  ('SPACE_3','Salón 3','single',true),('SPACE_4','Salón 4','single',true),
  ('SPACE_5','Salón 5','single',true),('SPACE_6','Salón 6','single',true),
  ('SPACE_7','Salón 7','single',true),('SPACE_8','Salón 8','single',true),
  ('SPACE_1_2','Salón 1 + Salón 2','combined',true)
on conflict (code) do update set name=excluded.name,space_type=excluded.space_type,is_active=excluded.is_active;

insert into public.space_rooms(space_id,room_id)
select s.id,r.id
from (values
  ('SPACE_1','ROOM_1'),('SPACE_2','ROOM_2'),('SPACE_3','ROOM_3'),('SPACE_4','ROOM_4'),
  ('SPACE_5','ROOM_5'),('SPACE_6','ROOM_6'),('SPACE_7','ROOM_7'),('SPACE_8','ROOM_8'),
  ('SPACE_1_2','ROOM_1'),('SPACE_1_2','ROOM_2')
) x(space_code,room_code)
join public.spaces s on s.code=x.space_code
join public.rooms r on r.code=x.room_code
on conflict do nothing;

insert into public.space_capacities(space_id,configuration_type_id,capacity)
select s.id,c.id,x.capacity
from (values
  ('SPACE_1','U',14),('SPACE_1','SCHOOL',24),
  ('SPACE_2','U',16),('SPACE_2','SCHOOL',24),
  ('SPACE_3','U',12),('SPACE_3','SCHOOL',20),('SPACE_3','BOARDROOM',12),
  ('SPACE_4','BOARDROOM',14),
  ('SPACE_5','BOARDROOM',10),
  ('SPACE_6','BOARDROOM',16),
  ('SPACE_7','BOARDROOM',18),
  ('SPACE_8','BOARDROOM',20),
  ('SPACE_1_2','U',30),('SPACE_1_2','SCHOOL',48)
) x(space_code,configuration_code,capacity)
join public.spaces s on s.code=x.space_code
join public.configuration_types c on c.code=x.configuration_code
on conflict (space_id,configuration_type_id) do update set capacity=excluded.capacity;

delete from public.space_capacities sc
using public.spaces s,public.configuration_types c
where sc.space_id=s.id and sc.configuration_type_id=c.id
  and (
    (s.code in ('SPACE_1','SPACE_2','SPACE_1_2') and c.code not in ('U','SCHOOL'))
    or (s.code='SPACE_3' and c.code not in ('U','SCHOOL','BOARDROOM'))
    or (s.code in ('SPACE_4','SPACE_5','SPACE_6','SPACE_7','SPACE_8') and c.code<>'BOARDROOM')
  );

-- Crea perfiles básicos para cuentas que ya existían antes de instalar estos scripts.
-- El rol nunca se toma de metadata y permanece como user hasta que un admin lo cambie.
insert into public.profiles(id,organization_id,full_name,role)
select u.id,o.id,
       coalesce(nullif(btrim(u.raw_user_meta_data ->> 'full_name'),''),split_part(u.email,'@',1),'Usuario'),
       'user'::public.app_role
from auth.users u
cross join public.organizations o
where o.code='EXTERNAL'
on conflict (id) do update
set organization_id=coalesce(public.profiles.organization_id,excluded.organization_id);

commit;
