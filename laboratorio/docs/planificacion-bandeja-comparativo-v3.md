# Planificación y Bandeja del analista frente al prototipo v3

Referencia funcional: `Prototipos/laboratorio-workflow-v3.html`. Se adoptó la planificación visible por lotes; el bloque `ordenesLegacy` del HTML está oculto y no corresponde a la pantalla vigente del prototipo.

| Sección | Sistema anterior | Implementación actual |
| --- | --- | --- |
| Planificación | Colas globales, matriz y Kanban con movimientos de estado | Lotes desplegables, avance, fechas, filtros y matriz muestra × análisis dentro de cada lote. |
| Asignación | Responsable ligado al formulario ya capturado | Asignación persistente por muestra y análisis pendiente; permite asignar un análisis o todo lo pendiente del lote. |
| Prioridad | Inferida desde la fecha de entrega | Alta, Media o Baja para todo el lote, reflejada en la bandeja. Los lotes sin configuración comienzan con Media, como el prototipo. |
| Bandeja | Listado de análisis con enlaces a formularios antiguos | Colas agrupadas por hoja de trabajo, filtros de analista/tipo/búsqueda, lotes y muestras pendientes ordenados por prioridad. |
| Captura | Formularios individuales incrustados y algunos análisis sin formulario | Grilla y tablet con los encabezados, columnas, blancos, controles y curvas definidos en el prototipo. |
| Selección | Rangos de formularios anteriores | Selección de muestras individuales, lotes o todas las pendientes; el lote se obtiene de cada muestra. |
| Trabajo masivo | Dependía de cada formulario | Búsqueda dentro de la hoja, pegado desde Excel y avance con Enter; el pegado respeta las filas visibles. |
| Avances | Sin una corrida de hoja persistida común | Guardar avance en base de datos y recuperarlo por usuario y hoja; control de versión para evitar sobrescrituras entre pestañas. |
| Envío parcial | Un formulario podía ocultar otras muestras del mismo lote | Solo las muestras seleccionadas pasan a revisión; las demás continúan pendientes. Se crean rangos contiguos exactos. |
| Validación técnica | Detalles de tablas heredadas | Las nuevas hojas tienen detalle de lecturas, parámetros, blancos, resultados calculados y curva; conservan una copia de la corrida enviada. |

## Campos y cálculos

`js/hojas_definiciones.js` conserva los constructores, formatos y fórmulas del prototipo. `config/hojas_trabajo_v3.json` conserva su esquema para validar la captura en PHP. Los cálculos se presentan en la revisión; el analista captura las lecturas originales.

Se incluyen 30 formatos específicos y los formatos genéricos del prototipo. Cinco análisis heredados que no tienen formato específico en ese HTML conservan una hoja genérica de resultado: coliformes, nitratos/nitritos, densidad real, porosidad total y potasio intercambiable. Esto no introduce fórmulas técnicas nuevas para esos análisis.

Las hojas compartidas respetan los análisis solicitados: por ejemplo, pH/conductividad, CC/PMP, lecturas y titulaciones de aguas, y Brix/Pol/rendimiento de caña. Los campos no aplicables aparecen como «—». Los vínculos permiten recuperar pesos y humedad residual de hojas guardadas o enviadas, conservando la posibilidad de captura manual.

## Persistencia e integración

Se agregan cinco tablas: `lab_plan_lote`, `lab_plan_asignacion`, `lab_hoja_borrador`, `lab_hoja_formulario` y `lab_workflow_auditoria`. Se preparan al abrir las nuevas pantallas, sin eliminar datos anteriores. El envío utiliza `formulario`, `lote_rango`, `estado_formulario` e `historial_formulario` existentes.

La asignación requiere el permiso de gestión de planificación. La captura conserva los permisos de análisis y restringe las muestras a su responsable o a quien gestiona el trabajo. El servidor verifica CSRF, pertenencia, versiones, campos obligatorios, blancos, curvas y duplicados. La identidad que queda en el formulario proviene de la sesión.

Las lecturas originales se guardan en la copia de la corrida; los cálculos del detalle se obtienen con las fórmulas del prototipo. Esta actualización no migra los resultados a las tablas técnicas heredadas de cada análisis ni rediseña los informes finales.

## Verificación

- Sintaxis de los archivos PHP y JavaScript modificados.
- Correspondencia de las columnas del motor y del esquema PHP, ejecución de todos los formatos y comprobación de cálculos conocidos.
- Captura simulada de 100 muestras: pegado, búsqueda, protección de filas ocultas, guardado y envío con versión.
- Prueba de integración con base de datos: asignación, prioridad, avance, envío parcial, copia de corrida, conflictos, duplicados e integridad. Las escrituras de prueba se revierten.

Comandos reproducibles:

```text
node laboratorio/tests/workflow_trabajo.test.js
php laboratorio/tests/workflow_trabajo_test.php
```

La prueba PHP requiere conexión configurada, usuarios del módulo y al menos dos tareas pendientes de la hoja de pH de suelos. No se realizó inspección visual en un navegador durante esta sesión.
