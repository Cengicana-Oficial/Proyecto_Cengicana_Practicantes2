# Curvas y resultados de Químico Suelos 2026

Fuente: `Prototipos/Cálculo Químico Suelos 2026.xlsx`. Se inspeccionaron las fórmulas, valores guardados, referencias de las series y configuración de las líneas de tendencia de Boro, Azufre y Fósforo.

| Análisis | Curva del Excel | Ajuste aplicado | R² con los puntos del archivo |
| --- | --- | --- | ---: |
| Boro | Boro F7:G16, chart3, intercepto 0 | Por el origen; R² no centrado | 0.9278 |
| Azufre | Azufre F8:G19, chart1, intercepto libre | Pendiente 0.03932142857142856; intercepto −0.01019047619047612 | 0.9847 |
| Fósforo | Fósforo H5:I16, chart2, intercepto 0 | Por el origen; R² no centrado | 0.9996 |

El ajuste previo de Azufre forzaba el paso por el origen; esto alteraba su recta y R². Se corrigieron el cálculo, la ecuación visible y la recta dibujada. El resultado de Azufre usa la fórmula del Excel `(absorbancia − blanco) × 25 / 10 × (100 + 1.408 / 100)`; no depende de la pendiente de la curva. Los siete resultados de E7:E13 coinciden.

En Fósforo hay una inconsistencia en el archivo de referencia: los datos de H5:I16 producen pendiente **0.04893956043956044**, pero E5:E13 dividen por **0.0481**. Por ello, el control de D5 = 0.663 produce:

- Con pendiente automática: solución 13.547322330751095; resultado 67.73661165375547.
- Con el divisor fijo del Excel: solución 13.783783783783786; resultado 68.91891891891893.

Se corrigió R² según el intercepto de la gráfica. No se reemplazó automáticamente la pendiente de cada corrida por la constante 0.0481. La pendiente se puede ingresar en captura; dejarla vacía utiliza la pendiente calculada de cada corrida. En los métodos que contemplan intercepto, ese campo también permanece en captura. Los demás parámetros de corrida se confirman en validación técnica. Las pruebas verifican tanto la pendiente automática como la digitada y documentan por separado la diferencia del archivo Excel.

Los parámetros de las tres hojas, salvo pendiente e intercepto cuando corresponden, están ocultos en captura (`soloVal`) y se confirman/corrigen en validación (`editableVal`); el servidor conserva los valores anteriores o los valores por defecto durante la captura y registra las correcciones al validar. Boro se presenta con dos decimales.

Pruebas: `node laboratorio/tests/curvas_suelos_excel_test.cjs`, `node laboratorio/tests/boro_suelo_excel_test.cjs` y `php laboratorio/tests/parametros_validacion_test.php`. Se probaron los cálculos y la configuración; no se modificaron el Excel ni registros de producción. No se realizó una comprobación de la interfaz con una sesión real.
