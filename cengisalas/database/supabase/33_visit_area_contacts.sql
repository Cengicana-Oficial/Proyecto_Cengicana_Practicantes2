-- CengiSalas / Supabase
-- 33: contacto responsable de cada área disponible para las visitas.

begin;

alter table visits.areas_interes
  add column if not exists profesional_area varchar(150) not null default '';

comment on column visits.areas_interes.profesional_area is
  'Profesional responsable o contacto principal del área.';

commit;
