-- CengiSalas / Supabase
-- 31: catalogos minimos para una instalacion nueva del modulo de Visitas.
-- La herramienta de migracion actualiza estos registros conservando los IDs de Aiven.

begin;
set local search_path to visits,public;

insert into visits.estados(id_estado,nombre_estado) values
  (1,'ENVIADO'),(2,'APROBADO'),(3,'RECHAZADO'),(4,'PENDIENTE'),(5,'CANCELADO')
on conflict (id_estado) do update set nombre_estado=excluded.nombre_estado;

insert into visits.estado_pago(id_estado_pago,nombre_estado_pago) values
  (1,'PENDIENTE'),(2,'PAGADO'),(3,'ANULADO')
on conflict (id_estado_pago) do update set nombre_estado_pago=excluded.nombre_estado_pago;

insert into visits.niveles_academicos(id_nivel,nombre_nivel,estado) values
  (1,'Maestria',1),(2,'Basico',1),(3,'Diversificado',1),(4,'Licenciatura',1),(5,'N/A',1)
on conflict (id_nivel) do update set nombre_nivel=excluded.nombre_nivel,estado=excluded.estado;

select setval(pg_get_serial_sequence('visits.estados','id_estado'),coalesce(max(id_estado),1),true) from visits.estados;
select setval(pg_get_serial_sequence('visits.estado_pago','id_estado_pago'),coalesce(max(id_estado_pago),1),true) from visits.estado_pago;
select setval(pg_get_serial_sequence('visits.niveles_academicos','id_nivel'),coalesce(max(id_nivel),1),true) from visits.niveles_academicos;

commit;
