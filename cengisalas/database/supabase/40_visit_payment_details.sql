-- CengiSalas / Visitas: monto pagado y observación del pago.
-- El total cotizado (total) no se modifica; monto_pagado registra lo que
-- realmente se cobró para reflejar descuentos, pagos parciales o ajustes.

begin;

alter table visits.solicitud_museo
  add column if not exists monto_pagado numeric(10,2)
    check (monto_pagado is null or monto_pagado >= 0),
  add column if not exists observacion_pago varchar(1000),
  add column if not exists fecha_pago timestamptz,
  add column if not exists pago_actualizado_por varchar(150),
  add column if not exists pago_actualizado_en timestamptz;

-- Los pagos que ya estaban marcados como pagados se cobraron por el total cotizado.
update visits.solicitud_museo
   set monto_pagado = total
 where id_estado_pago = 2
   and monto_pagado is null;

commit;
