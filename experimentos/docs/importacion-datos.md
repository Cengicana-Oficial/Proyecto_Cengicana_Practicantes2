# Importación de datos de Experimentos

La sección `/importar` sustituye a la página de importación y análisis. La ruta
anterior `/analisis` redirige a ella. El prefijo de despliegue del portal se conserva.
El análisis estadístico permanece en **Evaluaciones y Análisis**.

## Flujo

1. Seleccionar proyecto, ensayo y tipo de datos.
2. Descargar una plantilla Excel del ensayo. Para evaluaciones, elegir una o varias
   variables y una fecha. Se genera una fila por parcela y variable, con los valores
   existentes de cada combinación para corregirlos. Pueden agregarse más fechas
   en filas adicionales del mismo archivo. Laboratorio incluye todas las muestras
   manuales y los analitos de sus tipos; parcelas incluye área y notas de todas ellas.
3. Completar la primera hoja, **Datos**. Las hojas de parcelas, tratamientos,
   variables e instrucciones son referencias; no se importan.
4. Subir Excel/CSV y revisar los cambios. La revisión no escribe en la base.
5. Aplicar las filas válidas. Si cambian los datos desde la revisión, debe revisarse
   nuevamente el archivo. Los errores de escritura revierten toda la aplicación.

## Tipos admitidos

| Tipo | Identificador | Comportamiento |
| --- | --- | --- |
| Evaluaciones | Parcela + Variable + Fecha | Crea o actualiza una evaluación. Normaliza con el catálogo de variables. Si ya existen varios registros para esa combinación, omite la fila para revisión manual. |
| Resultados de laboratorio | ID_muestra + Analito | Actualiza analitos de muestras manuales existentes y asociadas a parcelas. Conserva los demás analitos. No cambia el estado de la muestra. |
| Datos de parcelas | Parcela | Actualiza área en m² y notas. No crea parcelas ni reasigna tratamientos o repeticiones. |

Las muestras vinculadas al módulo Laboratorio o con resultados de informes no
se modifican ni aparecen en las plantillas de carga manual. Se actualizan desde
ese módulo. Los códigos del archivo deben corresponder al ensayo seleccionado.
Los campos de datos vacíos conservan la información actual; no permiten borrarla.
Si un archivo repite un identificador, se acepta la primera fila válida y se omiten
las siguientes. El resumen cuenta filas, no muestras: una muestra puede tener
varios analitos.

Máximo: 5 MB y 10 000 filas. Las fechas admiten `AAAA-MM-DD` o fechas de Excel.
Las plantillas escriben códigos y nombres como texto, conservando ceros iniciales
y evitando interpretarlos como fórmulas.

## Permisos y comprobación

Además de `run_analysis`, cada tipo requiere su permiso de escritura:
`register_evaluacion`, `create_muestra` o `edit_ensayo`. Se respeta la visibilidad
de ensayos del usuario en descargas, revisiones y aplicaciones.

`php tests/importacion_check.php` ejecuta comprobaciones de integración con SQLite
en memoria. No modifica los datos de la aplicación. Comprueba plantillas reales,
lectura de la primera hoja, revisión sin escritura, actualizaciones sin duplicados,
validación de referencias, resultados de muestras, permisos y revisiones obsoletas.
