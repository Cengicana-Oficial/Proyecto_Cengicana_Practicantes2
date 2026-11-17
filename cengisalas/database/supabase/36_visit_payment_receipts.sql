-- CengiSalas / Visitas: enlaces y comprobantes de pago.

begin;

alter table visits.solicitud_museo
  add column if not exists pago_token varchar(64),
  add column if not exists ruta_comprobante_pago varchar(500),
  add column if not exists nombre_comprobante_pago varchar(255),
  add column if not exists fecha_comprobante_pago timestamptz,
  add column if not exists fecha_envio_formulario_pago timestamptz,
  add column if not exists correo_formulario_pago_enviado smallint not null default 0
    check (correo_formulario_pago_enviado in (0,1));

create unique index if not exists ux_solicitud_museo_pago_token
  on visits.solicitud_museo(pago_token)
  where pago_token is not null;

create index if not exists ix_solicitud_museo_comprobante
  on visits.solicitud_museo(id_estado_pago,fecha_comprobante_pago);

commit;
