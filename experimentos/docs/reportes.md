# Reportes de Experimentos

La sección `/soporte/reportes` permite filtrar por programa, proyecto y ensayo,
descargar Excel y abrir un informe técnico por ensayo. Los enlaces conservan el
prefijo de despliegue del portal. `/soporte/reportes/resumen/{ensayo}` redirige al
informe técnico y conserva el periodo.

## Exportaciones

- Evaluaciones: registros de campo y resultados de laboratorio asociados a parcelas.
- Muestras: identificación, resultados, estado y vínculo con el módulo Laboratorio.
- Cartera de ensayos: configuración, responsables, avance y última actividad.
- Parcelas: tratamiento, repetición, área y notas.
- Bitácora: fecha, actividad, descripción y responsable.
- NDVI: valores calculados de cada imagen por parcela, incluidos los ceros válidos.

El rango `desde` / `hasta` es inclusivo y se aplica a evaluaciones, fecha de muestreo,
bitácora y fecha de imagen NDVI. Se rechazan fechas inexistentes o periodos invertidos.
La cartera y las parcelas muestran la información actual, sin recorte por fechas.
Los indicadores de la pantalla muestran totales del ensayo completo y lo explicitan.
Los Excel incluyen encabezados, autofiltro y primera fila fija.

## Informe técnico

Incluye ficha, objetivo, tratamientos, cobertura, variables de campo y laboratorio,
ANOVA, comparación de medias con Tukey, gráficas, resumen de muestras, NDVI y bitácora.
Permite seleccionar variables, agrupación de muestreos, significancia y apartados.
La descarga PDF se realiza con **Imprimir / Guardar como PDF** en el navegador.
No se simulan resultados ni se generan conclusiones narrativas mediante IA.

El cálculo utiliza `resources/js/estadistica.js`, el mismo motor de Evaluaciones y
Análisis. Las lecturas repetidas de una parcela se promedian por muestreo antes de
calcular ANOVA. El área bajo la curva usa parcelas con datos en todos los muestreos.
Se muestran el diseño utilizado, las advertencias, el valor p, el CV, el error estándar
y los grupos de Tukey cuando hay diferencias significativas. Variables no numéricas
se presentan como frecuencias por tratamiento. Cada ensayo se analiza por separado.

Todo exige `view_reports_menu` y respeta `Ensayo::visiblesPara`, incluidas asignaciones
directas de responsabilidad. Las descargas y los informes son de lectura; no modifican
registros ni sincronizan muestras de Laboratorio.

## Comprobaciones

- `php tests/reportes_check.php`: seis exportaciones, filtros, permisos y contenido
  del informe, usando exclusivamente SQLite en memoria.
- `node tests/reportes_estadistica_check.cjs`: coherencia con el motor de Evaluaciones,
  agrupación por parcela, área bajo la curva, estados vacíos y filtros del navegador.
