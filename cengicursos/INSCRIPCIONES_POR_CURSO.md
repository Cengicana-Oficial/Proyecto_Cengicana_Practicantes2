# Inscripciones y cobros por curso

## Interesados, inscripción y autorizaciones

En pago propio hay dos responsabilidades: **Validado por** identifica a quien revisó inicialmente la constancia y dejó evidencia; **Verificado por** identifica a Administración al confirmar el depósito. Ambos roles deben contar con una constancia adjunta y observación escrita. Capacitacion registra “en revisión” y no marca el cobro pagado ni confirma la inscripción. Esos registros salen de Interesados y aparecen en **Validaciones y pagos → Verificación de pagos propios** hasta que Administración los confirme o rechace con motivo. La aceptación directa por Administración confirma el pago y la inscripción. Las acciones de proformas siguen disponibles como pestañas financieras independientes.

Si el participante sustituye la boleta mediante su enlace seguro, se conserva un historial y se requiere una nueva validación para evitar asociar la evidencia anterior al documento nuevo. Los registros pagados existentes conservan su estado. Aplicar `migrations/20261025_pago_validacion.sql` (init 39) antes de actualizar el código.

Las acciones de aceptación están en **Interesados**. Según quién paga se muestra Validar por ingenio / Validar por Capacitacion, o Validar por Administración / Validar pago por Capacitacion. Capacitacion requiere autorización escrita para actuar por el ingenio; para pago propio se registra el usuario que verifica la boleta o confirma el ingreso con referencia, fecha y monto. El comprobante puede recibirse en el formulario inicial, cargarse al revisar o compartirse mediante enlace seguro.

**Inscritos** presenta solo confirmados y estados Pago proforma, Pendiente de proforma o Pagado (Sin costo para exonerados). No contiene botones de aceptación ni gestión de proformas. Los inscritos históricos sin aceptación pendiente vuelven a la bandeja de revisión sin eliminar sus asignaciones académicas. **Validaciones y pagos → Vincular proformas** centraliza la asociación de participantes a sus documentos; **Proformas** conserva el repositorio y sus revisiones.

La entrada inicial es **Interesados**: reúne formularios y cargas masivas. **Inscripciones** contiene a quienes fueron aceptados por su ingenio, aunque el cobro de su proforma siga pendiente, y a quienes pagan propio cuyo comprobante fue aceptado por Administración. La inscripción gratuita conserva su condición Sin costo. La aceptación de una boleta por Administración registra el pago y habilita el paso a inscripción en una sola acción. El pago de una proforma no sustituye la aceptación del ingenio.

Cada registro muestra el usuario que validó y su comentario. Si Capacitacion o Administración acepta en nombre del ingenio, debe escribir quién autoriza y su cargo. Los participantes con pago propio sin boleta pueden utilizar el enlace firmado de Subir comprobante, también para cursos directos; enviar una boleta no significa que Administración ya la haya aceptado.

**Repositorio de proformas** permite consultar, descargar y compartir el documento vigente. Las pendientes admiten revisión de destinatario, correo, vencimiento, cantidades, precios, descuentos y observaciones con motivo obligatorio. Las cantidades deben cubrir los participantes ya vinculados. Cada revisión guarda su PDF reconstruible con los datos y cuentas de su versión y actualiza las cuotas vinculadas y sus grupos. Las proformas pagadas permanecen cerradas. Para documentos antiguos la primera copia histórica se guarda al hacer su primera actualización; las nuevas conservan su versión inicial desde la emisión.

Aplicar `migrations/20261024_proforma_versiones.sql` (init 38) antes de desplegar el código de revisiones.

Una persona conserva su ficha en Participantes. Cada curso tiene una inscripción independiente: responsable del pago, cuota, validación, asignación y cobro. Pagar un curso no liquida otros cursos de la misma persona.

La entrada del menú **Inscripciones y pagos** reúne las pestañas Inscripciones, Pagos y validación y Cobros pendientes. La vista financiera usa `inscripciones.php?vista=pagos` y conserva las opciones de órdenes de ingenios, proformas y resumen. Los enlaces antiguos a `pagos_inscripcion.php` siguen funcionando y llevan a esta vista. Los permisos de validación y registro de pagos se mantienen.

La bandeja muestra origen del registro, validación, asignación, estado de cobro, proforma y vencimiento. Sus accesos filtran Por validar, Validadas por asignar, Ingenio sin proforma, Pendientes de pago y Pago registrado. Los importes pendientes se presentan por moneda y por inscripción; no suman dos veces la proforma y sus participantes. El total completo de una proforma, incluidos cupos reservados y cargos, se consulta en el documento financiero.

La carga masiva del formulario público acepta cursos y convocatorias, con cuota de asociado, externo o extranjero. Vincula cada persona a la planificación, calcula su cuota y registra el grupo del ingenio. Las entradas de formularios, carga pública, carga interna y altas internas se identifican en Origen; los registros anteriores sin evidencia conservan “Registro anterior”.

Administración revisa el comprobante de pago propio antes de marcar el ingreso como pagado. Validar habilita la asignación según cupo; registrar el ingreso liquida el cobro. Los pagos de ingenios se registran desde la proforma, que liquida sus grupos. Las validaciones rechazadas explícitamente por un ingenio se conservan al pagar la proforma y requieren revisión antes de asignar.

Para cursos directos que no tienen cuotas, **Preparar proforma** permite confirmar fechas y tarifas e integrarlos a la gestión de cobro conservando el curso existente, sus participantes y pagos. No crea sesiones. Las cuotas registradas permanecen; al emitir una proforma se aplica la tarifa y descuentos del documento, por lo que se debe revisar su importe final. También se pueden emitir proformas de cursos finalizados que todavía tengan pendientes.

Aplicar además `migrations/20261023_inscripciones_seguimiento.sql` (espejo init 37) en bases existentes. Corrige solicitudes que omitieron la planificación y agrega origen; no inventa validaciones o pagos históricos. Reconstruir la imagen para conservar todos los cambios al recrear el contenedor.

**Eventos y pagos** reúne inscripción, validación, confirmación, ingreso QR y cobros en las pestañas del mismo módulo. Ya no aparecen pagos y cobros de eventos como entradas duplicadas en Administración. **Liquidaciones de instructores** permanece en Administración: es un egreso por sesiones impartidas, con tarifas, firma y aprobación, separado de los ingresos de participantes. El resumen económico de convocatorias permite contrastar los ingresos con los costos docentes existentes.

1. Registrar al participante en cada curso desde el directorio, el formulario de alta o la carga de participantes. Los registros nuevos quedan pendientes de validación; los gratuitos pueden asignarse si hay cupo.
2. Abrir **Validación y cobro** desde Inscripciones. Para convocatorias se usa Pagos de inscripción, con cuotas y órdenes grupales existentes. Para cursos directos se define la cuota y quién paga en la ficha de inscripción.
3. El ingenio valida su patrocinio. Para pago propio, Administración valida el comprobante o registra el pago recibido con fecha y referencia.
4. Las convocatorias mantienen la asignación automática por validación y cupo. En cursos directos se utiliza Asignar al curso después de validar.
5. Revisar **Cobros pendientes**: incluye cursos directos y planificados, incluso participantes ya asignados. Patrocinio validado y participante asignado pueden seguir pendientes de pago.

Se mantienen secciones separadas: Inscripciones para el proceso por curso; Participantes para las fichas y seguimiento académico; Pagos de inscripción para comprobantes, órdenes y cobranza de convocatorias. La ficha de cursos directos conserva un historial de cuota, responsable, patrocinio, pago y asignación. Los cobros directos son por inscripción; no generan automáticamente proformas ni órdenes grupales.

Aplicar `migrations/20261021_inscripciones_por_curso.sql` después de las migraciones anteriores. Su espejo de instalación está en `deploy/mysql/init/35-cengicursos-inscripciones-por-curso.sql`. La conciliación recupera asignaciones sin inscripción y mantiene su acceso previo, sin marcar pagos ni validaciones inexistentes. Las cuotas de convocatorias se toman de su tarifa actual; las de cursos directos quedan por definir. Revisar cuotas y pagadores históricos antes de cobrar: no se puede deducir si pagó la persona o su ingenio a partir de una asignación. Las personas sin CUI reciben un identificador administrativo correlativo con el formato existente NNNND.

Pruebas: `C:/xampp/php/php.exe cengicursos/tests/inscripciones_test.php`. Utiliza una base temporal `codex_ins_test_*`, comprueba registros por curso, duplicados, separación de pagos, bloqueo de asignación y conciliación idempotente, y elimina exclusivamente esa base. Para desplegar al recrear los contenedores, reconstruir la imagen desde el repositorio; los scripts de init solo corren en volúmenes nuevos, por lo que en bases existentes se aplica la migración explícitamente.
