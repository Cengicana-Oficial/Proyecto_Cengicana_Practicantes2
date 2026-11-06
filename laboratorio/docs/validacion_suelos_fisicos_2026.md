# Comparación de Humedad residual y Textura con Excel

Referencia: `Prototipos/Cálculo Físico Suelos 2026 -Para calculo.xlsx`.
Se revisaron las fórmulas y los valores guardados de las hojas `%Humedad Residual (N) ` y `Textura`, incluidos sus dos ejemplos y su control.

## Procedimiento comprobado

| Paso | Excel | Procedimiento del sistema |
| --- | --- | --- |
| Caja + muestra húmeda | HR: E6 = C6+D6 | caja + peso de muestra húmeda |
| Humedad residual | HR: G6 = (E6-F6)*100/D6 | pérdida de agua × 100 / peso de muestra húmeda |
| Factor de textura | Textura: D4 = 200/(100-C4) | 200 / (100 − HR), método de 50 g |
| Primera lectura corregida | G4 = (F4-60)*0.2+E4 | lectura 40 s + (temperatura °F − 60) × 0.2 |
| Limo + arcilla | H4 = G4*D4 | primera lectura corregida × factor |
| Segunda lectura corregida | K4 = (J4-60)*0.2+I4 | lectura 2 h + (temperatura °F − 60) × 0.2 |
| Arcilla | M4 = K4*D4 | segunda lectura corregida × factor |
| Limo | N4 = H4-M4 | limo + arcilla − arcilla |
| Arena | O4 = 100-H4 | 100 − (limo + arcilla) |
| Total | L4 = M4+N4+O4 | arcilla + limo + arena |

Las fórmulas base ya coincidían con Excel cuando se utilizaban 50 g. Se corrigió el redondeo prematuro de HR en el vínculo con Textura y se fijó el procedimiento de 50 g para evitar que un peso editable produjera resultados distintos del Excel.

La HR conserva toda su precisión para calcular el factor y los porcentajes; se presenta con **dos decimales**. No se redondean los pasos intermedios. Por ejemplo, el primer caso utiliza HR = 2.1956087824351185, aunque muestra 2.20. Usar 2.20 como entrada cambia el valor interno de arena de 45.81020408163266 a aproximadamente 45.8078. En los tres ejemplos del archivo ese redondeo previo no cambia los porcentajes mostrados a dos decimales; no se reprodujo una discrepancia mayor con esos datos.

La hoja oculta `%humedad residual ` corresponde a un formato de 2021 que usa `(caja+húmeda − caja+seca) × 20`, equivalente a suponer una muestra exacta de 5 g. No se sustituyó el procedimiento vigente por esa fórmula: la hoja HR (N) utiliza el peso real (5.01 y 5.03 g en los ejemplos). En Textura, las celdas C4:C6 contienen valores numéricos que coinciden con los resultados completos de HR (N), sin fórmulas de vínculo entre las hojas; el sistema sí los vincula por muestra.

## Resultados de referencia

| Registro | HR mostrada (%) | Arcilla (%) | Limo (%) | Arena (%) | Total interno (%) | Clase |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Ejemplo 1 | 2.20 | 22.25 | 31.94 | 45.81 | 100 | Franco |
| Ejemplo 2 | 1.19 | 12.19 | 42.10 | 45.71 | 100 | Franco |
| CONTROL | 2.39 | 12.42 | 24.30 | 63.28 | 100 | Franco arenoso |

La prueba compara todos los valores intermedios con los valores guardados en el Excel, con tolerancia absoluta de 1e-10. Las clases texturales del archivo son texto escrito manualmente; su coincidencia en estos tres casos no constituye una comprobación exhaustiva del triángulo textural.

## Validación técnica

- Los pesos de HR deben estar completos: caja >= 0, muestra húmeda > 0 y caja < caja + seca <= caja + húmeda. Una HR de 0 es válida.
- Textura requiere HR entre 0 inclusive y 100 exclusive, lecturas y temperaturas en °F; no convierte automáticamente °C.
- Arena, limo y arcilla deben estar entre 0 y 100 %. Una suma de 100 % por sí sola no demuestra que las fracciones sean válidas: incluso con limo negativo la suma algebraica puede seguir siendo 100.
- Se revisan muestras y controles al enviar y al aprobar. Los errores aparecen en el detalle de validación técnica aunque el catálogo no tenga un rango numérico para Textura.
- Se puede devolver una muestra inválida a reanálisis y aprobar las demás; un control inválido requiere corregir/repetir la corrida.
- La HR proviene de una hoja enviada no rechazada o de un avance guardado. Los pesos de origen se congelan en la captura de Textura para su trazabilidad. No se mezclan muestras con controles de HR distintos en una misma corrida.
- Las capturas históricas sin pesos de origen no recuperan precisión automáticamente: si guardaron una HR redondeada, deben volver a capturarse desde el análisis de HR para compararlas con toda la precisión del Excel.

## Pruebas

### Corrección de clase en validación técnica

En `hoja_revision.php`, la opción **Editar**, dentro de la celda **Textura**, permite elegir una clase manual por muestra y escribir ahí mismo el motivo. La opción **Usar automática** restaura la clasificación calculada. Se conserva la clase automática para comparación; la clase elegida y su equivalente USDA se utilizan en el informe y en el CSV. Los porcentajes se mantienen calculados y las comprobaciones de consistencia siguen siendo obligatorias.

La edición requiere el permiso existente `laboratorio.validacion_tecnica.aprobar` y formularios pendientes de revisión. Un motivo es obligatorio para cada cambio. Al pulsar **Validar**, clase, motivo, usuario y fecha se guardan junto con la aprobación, dentro de la misma transacción; el historial registra la clase anterior y la nueva. Las hojas aprobadas se muestran en modo lectura. La prueba `php laboratorio/tests/textura_revision_test.php` cubre las correcciones, la restauración automática, el historial y el rechazo de valores o muestras ajenos al formulario.

`node laboratorio/tests/suelos_fisicos_excel_test.cjs`

`php laboratorio/tests/suelos_fisicos_test.php`

Se comprobaron JavaScript y PHP contra los tres casos del Excel y los casos de pesos inconsistentes, datos faltantes, HR cero, HR fuera de rango, peso de Textura distinto de 50 g, fracciones imposibles, controles y devolución parcial a reanálisis. No se modificó el archivo Excel ni se alteraron registros históricos de la base de datos. La revisión de interfaz con una sesión real y el flujo transaccional contra la base de datos quedan pendientes.
