# Identificación de muestras: comparativo con el prototipo v3

Referencia: `Prototipos/laboratorio-workflow-v3.html`.
Página actualizada: `laboratorio/view/listar_lotes.php`.

| Función | Sistema anterior | Actualización basada en el prototipo |
| --- | --- | --- |
| Organización | Búsqueda y ficha individual | Tres pestañas: registrar etiquetas, base de muestras y etiquetas del laboratorio. Se conserva la ficha técnica individual. |
| Captura | Una muestra por formulario | Captura por lote, avance y estados: con información, solo ID y pendiente. |
| Plantillas | Campos fijos | Seis plantillas: precosecha, suelos, foliar, agua, caña y todos los campos. |
| Datos tabulares | Ingreso individual | Pegado desde Excel con celdas y líneas entrecomilladas; relleno de campos vacíos desde la primera fila. Se rechaza el pegado que excede las filas disponibles. |
| Lecturas | Búsqueda de una muestra por código | Asignación secuencial de información de etiquetas, lector y cámara; selección por código interno y rechazo de códigos de otros lotes. |
| Recuperación | Sin borrador de lote | Borrador por usuario y lote en la sesión del navegador; comprobación de versión al recuperarlo. |
| Correcciones | Edición directa | Versiones, fecha, usuario y motivo obligatorio para correcciones. Protección contra sobrescritura de versiones anteriores. |
| Consulta | Tabla limitada de muestras | Base con búsqueda y filtros para suelos, foliares, aguas, cañas y mieles, estados y análisis solicitados. Exportación CSV. |
| Etiquetas | Flujo centrado en ficha individual | Selección de muestras, vista previa e impresión con código de barras y QR opcional. |

## Integración

- Se conservan los identificadores y códigos de laboratorio existentes.
- Los campos compartidos se sincronizan con la ficha individual. Después de guardar un lote, las correcciones de esos campos se realizan desde Registrar etiquetas para conservar el historial; los otros datos técnicos siguen disponibles en la ficha.
- El guardado parcial exige confirmación explícita. Se validan las muestras del lote, duplicados, campos y versión en el servidor.
- Se utiliza el permiso existente `laboratorio.lotes.ver` y protección CSRF.
- Las tablas `lab_identificacion_lote` y `lab_identificacion_historial` se preparan al abrir la página. Actualizar el módulo no asigna etiquetas nuevas a las muestras existentes.
- El borrador utiliza `sessionStorage`: está limitado a la sesión y pestaña del navegador.

## Verificación realizada

- Sintaxis PHP y JavaScript.
- Pruebas funcionales de JavaScript con DOM simulado: clasificación, pegado, CSV, captura, borradores, guardado, motivo y vista previa.
- Prueba PHP con base de datos y reversión transaccional: guardado completo y parcial, historial, conflictos, validaciones, lectura completa e integridad de códigos e identificadores. Se verificó que no persistieran los datos de prueba.
- Renderizado PHP completo con datos reales, tres pestañas, JSON válido y ausencia de identificadores HTML duplicados.
- El endpoint redirige al acceso cuando no existe una sesión autenticada.

Pruebas reproducibles:

```text
node laboratorio/tests/identificacion_lote.test.js
php laboratorio/tests/identificacion_lote_test.php
```

La prueba PHP requiere la conexión configurada y un lote con al menos dos muestras. Prepara el esquema antes de iniciar la transacción y revierte las escrituras de prueba.

No se pudo realizar inspección visual interactiva porque no había un navegador disponible en la sesión. La cámara requiere permisos y soporte de BarcodeDetector; la impresión física y las dimensiones finales deben comprobarse con la impresora utilizada.
## Mejora de captura para lotes grandes

- Se unificaron Contenido y Estado en una sola columna de Identificación; en lecturas permite corregir la clasificación detectada.
- El formato se asigna por tipo de muestra y se conserva con el lote y su historial. Puede cambiarse al editar, sin borrar los campos que quedan fuera del formato visible.
- Búsqueda por código o información dentro del lote, filtro por identificación, Ver todas e Ir a pendiente.
- Encabezados y códigos fijos, área de tabla desplazable y acciones de guardado visibles.
- Enter avanza a la siguiente muestra visible en la misma columna; Shift+Enter retrocede.
- Pegado de Excel y relleno respetan las filas filtradas. El relleno solicita confirmación e indica la cantidad de celdas vacías que actualizará. El guardado siempre incluye todo el lote.
- Se verificaron 100 muestras en la prueba funcional, edición de una muestra filtrada, protección de filas ocultas, relleno y navegación con teclado. La prueba PHP verifica la persistencia del formato y su historial con reversión de los datos de prueba.