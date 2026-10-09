<?php
/**
 * Formatos de informe de resultados por tipo de muestra (Prototipos/laboratorio-workflow-v3.html,
 * FORMATO_REPORTE / COLS_* / ID_FORMATO, y los PDF de referencia Informe_Suelos_S-12341,
 * Informe_Aguas_A-3101, Informe_Foliar_F-5231 e Informe_Cana_C-2201).
 *
 * Columnas de resultado:
 *   h  = título · u = unidad (una celda) · g = unidad agrupada (celdas contiguas con el mismo g se unen)
 *   hg = título agrupado (celdas contiguas con el mismo hg se unen, p. ej. "Rendimiento comercial")
 *   an = análisis de la hoja de trabajo (nombres de hojas_definiciones.js) · k = campo calculado de la hoja
 *   d  = decimales · extra = no forma parte del formato vigente (se marca con * y se agrega si el lote lo pidió)
 * Solo se imprimen las columnas cuyos análisis se solicitaron en Recepción; cualquier otro análisis
 * solicitado que el formato no contemple se agrega al final como columna extra (*), salvo los de
 * 'no_reportar' (análisis auxiliares que Recepción agrega como dependencia de otro cálculo).
 */
$sm = 'Standard Methods for the Examination of Water and Wastewater, 23th.ed.';
$mgl = 'mg/L';
$meq = 'Meq intercambiables/100 g suelo';

return [
    'firmante_cargo' => 'Jefe Laboratorio Agroindustrial',
    'pie' => [
        'Los resultados de este informe son válidos únicamente para las muestras como fueron recibidas en el Laboratorio.',
        'En el Laboratorio Agroindustrial, no nos hacemos responsables por el uso inadecuado que se le dé a este Informe. Está prohibida su reproducción sin autorización del Laboratorio Agroindustrial.',
    ],
    'direccion' => 'Finca Camantulul, Km 92.5, Santa Lucía Cotzumalguapa, Escuintla · teléfonos 78281001-78281014',
    'nota_extra' => 'Las columnas marcadas con * no forman parte del formato vigente; se incluyen porque el lote las solicitó.',

    'tipos' => [
        'Suelo' => [
            'servicio' => 'Servicios analíticos',
            'encabezado' => 'Reporte de Análisis de Físico y Químico del Suelo',
            'codigo' => 'SAL-LAG-FOR-005', 'version' => 'Versión 16', 'fecha' => '04/02/2026',
            'titulo' => 'Reporte de Análisis Químico y Físico de Suelo',
            'datos' => ['codigo_muestreo', 'institucion', 'fecha_ingreso', 'fecha_entrega'],
            'revisado_cargo' => 'Técnico revisor · validación técnica',
            'identificacion' => null, // columnas según los campos que trae la etiqueta
            'no_reportar' => ['Humedad residual'], // solo alimenta el % H.R. de Textura
            'metodos' => 'Conductividad Eléctrica (CE): en agua relación 1:4, pH en Agua relación 1:4, Materia Orgánica: Walkey-Black; Capacidad de Intercambio Catiónico (CIC) y Bases Intercambiables: extracción con acetato de amonio 1 N; Micronutrientes y Fósforo: extracción con solución de Carolina del Norte, lectura por absorción atómica y espectrofotometría visible, respectivamente. Textura: método de Bouyoucos; Retención de Humedad: a 1/3 y 15 atmósferas; Densidad Aparente: método de la probeta; Humedad Gravimétrica: secado a 105 °C y cálculo por diferencia de peso.',
            'columnas' => [
                ['h' => 'Conductividad Eléctrica', 'u' => 'dS/m', 'an' => 'Conductividad eléctrica', 'k' => 'ceDs', 'd' => 3],
                ['h' => 'pH en agua', 'u' => '', 'an' => 'pH', 'k' => 'ph', 'd' => 2],
                ['h' => 'Materia Orgánica', 'u' => '%', 'an' => 'Materia orgánica', 'k' => 'mo', 'd' => 2],
                ['h' => 'Calcio', 'g' => $meq, 'an' => 'Macronutrientes', 'k' => 'r_ca', 'd' => 2],
                ['h' => 'Magnesio', 'g' => $meq, 'an' => 'Macronutrientes', 'k' => 'r_mg', 'd' => 2],
                ['h' => 'Potasio', 'g' => $meq, 'an' => 'Macronutrientes', 'k' => 'r_k', 'd' => 2],
                ['h' => 'Sodio', 'g' => $meq, 'an' => 'Macronutrientes', 'k' => 'r_na', 'd' => 2],
                ['h' => 'Capacidad de Intercambio Catiónico', 'g' => $meq, 'an' => 'Capacidad de intercambio catiónico (CIC)', 'k' => 'cic', 'd' => 2],
                ['h' => 'Fósforo', 'g' => 'ppm', 'an' => 'Fósforo disponible', 'k' => 'res', 'd' => 2],
                ['h' => 'Cobre', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_cu', 'd' => 2],
                ['h' => 'Cinc', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_zn', 'd' => 2],
                ['h' => 'Hierro', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_fe', 'd' => 2],
                ['h' => 'Manganeso', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_mn', 'd' => 2],
                ['h' => 'Arcilla', 'g' => '%', 'an' => 'Textura', 'k' => 'arcilla', 'd' => 2],
                ['h' => 'Limo', 'g' => '%', 'an' => 'Textura', 'k' => 'limo', 'd' => 2],
                ['h' => 'Arena', 'g' => '%', 'an' => 'Textura', 'k' => 'arena', 'd' => 2],
                ['h' => 'Tipo de Textura', 'u' => '', 'an' => 'Textura', 'k' => 'clase'],
                ['h' => 'Punto de Marchitez Permanente', 'g' => '% H', 'an' => 'Punto de marchitez permanente (PMP)', 'k' => 'pmp_pct', 'd' => 2],
                ['h' => 'Capacidad de Campo', 'g' => '% H', 'an' => 'Capacidad de campo (CC)', 'k' => 'cc_pct', 'd' => 2],
                ['h' => 'Densidad Aparente', 'u' => 'g/cm3', 'an' => 'Densidad aparente', 'k' => 'da', 'd' => 2],
                ['h' => 'Humedad Gravimétrica', 'u' => '%', 'an' => 'Humedad gravimétrica', 'k' => 'pct', 'd' => 2],
                ['h' => 'Nitrógeno total', 'u' => '%', 'an' => 'Nitrógeno total', 'k' => 'n', 'd' => 2, 'extra' => true],
                ['h' => 'Azufre', 'u' => 'ppm SO₄', 'an' => 'Azufre', 'k' => 'res', 'd' => 2, 'extra' => true],
                ['h' => 'Boro', 'u' => 'ppm', 'an' => 'Boro', 'k' => 'res', 'd' => 3, 'extra' => true],
            ],
        ],
        'Agua' => [
            'servicio' => 'Laboratorio Agroindustrial',
            'encabezado' => 'Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar',
            'codigo' => 'VF 015', 'lote_arriba' => true,
            'titulo' => 'Reporte de Análisis Químico y Físico de Aguas',
            'datos' => ['codigo_muestreo', 'ingenio', 'fecha_ingreso', 'fecha_entrega'],
            'revisado_cargo' => 'Técnico de Laboratorio Agroindustrial',
            'identificacion' => ['grupo' => 'Identificación de la etiqueta', 'cols' => [['k' => 'finca', 'h' => 'Finca'], ['k' => 'descripcion', 'h' => 'Muestra']]],
            'metodos_por_analisis' => [
                'Conductividad eléctrica' => $sm . ' Determinación por potenciometría.',
                'pH' => $sm . ' Determinación por potenciometría.',
                'Sólidos totales disueltos' => $sm . ' Determinación por potenciometría.',
                'Cloruros' => $sm . ' Método Argentométrico - Mohr',
                'Dureza' => $sm . ' Método Titrimétrico con EDTA',
                'Alcalinidad total' => $sm . ' Método Titrimétrico B',
                'Carbonatos' => $sm . ' Método de Titulación',
                'Bicarbonatos' => $sm . ' Método de Titulación',
                'Razón de adsorción de sodio (RAS)' => 'Cálculo Matemático',
                'Resistividad' => $sm . ' Determinación por potenciometría.',
                'Salinidad' => $sm . ' Determinación por potenciometría.',
                'Fósforo' => $sm . ' Determinación por espectrofotometría',
                'Boro' => $sm . ' Determinación por espectrofotometría',
                'Macronutrientes' => $sm . ' Espectrometría de Absorción Atómica (Ca, Mg, K y Na)',
                'Micronutrientes' => $sm . ' Espectrometría de Absorción Atómica (Cu, Zn, Fe y Mn)',
            ],
            'metodos_etiqueta' => ['Macronutrientes' => 'Ca, Mg, K y Na', 'Micronutrientes' => 'Cu, Zn, Fe y Mn', 'Sólidos totales disueltos' => 'Sólidos totales disueltos'],
            'columnas' => [
                ['h' => 'Conductividad Eléctrica', 'u' => 'dS/m', 'an' => 'Conductividad eléctrica', 'k' => 'ceDs', 'd' => 3],
                ['h' => 'pH', 'u' => '', 'an' => 'pH', 'k' => 'ph', 'd' => 2],
                ['h' => 'Sólidos Totales', 'u' => $mgl, 'an' => 'Sólidos totales disueltos', 'k' => 'tds', 'd' => 1],
                ['h' => 'Cloruros', 'u' => $mgl, 'an' => 'Cloruros', 'k' => 'cl', 'd' => 2],
                ['h' => 'Dureza', 'u' => $mgl, 'an' => 'Dureza', 'k' => 'dur', 'd' => 0],
                ['h' => 'Alcalinidad', 'u' => $mgl, 'an' => 'Alcalinidad total', 'k' => 'alcR', 'd' => 1],
                ['h' => 'Carbonatos', 'u' => $mgl, 'an' => 'Carbonatos', 'k' => 'co3', 'd' => 1],
                ['h' => 'Bicarbonatos', 'u' => $mgl, 'an' => 'Bicarbonatos', 'k' => 'hco3', 'd' => 1],
                ['h' => 'Razón de Adsorción Sodio', 'u' => 'Un', 'an' => 'Razón de adsorción de sodio (RAS)', 'k' => 'ras', 'd' => 2],
                ['h' => 'Resistividad', 'u' => 'Kohm-cm', 'an' => 'Resistividad', 'k' => 'rk', 'd' => 3],
                ['h' => 'Salinidad', 'u' => 'UPS', 'an' => 'Salinidad', 'k' => 'sal', 'd' => 3],
                ['h' => 'Fósforo', 'u' => $mgl, 'an' => 'Fósforo', 'k' => 'res', 'd' => 3],
                ['h' => 'Boro', 'u' => $mgl, 'an' => 'Boro', 'k' => 'res', 'd' => 3],
                ['h' => 'Calcio', 'u' => $mgl, 'an' => 'Macronutrientes', 'k' => 'r_ca', 'd' => 3],
                ['h' => 'Magnesio', 'u' => $mgl, 'an' => 'Macronutrientes', 'k' => 'r_mg', 'd' => 3],
                ['h' => 'Potasio', 'u' => $mgl, 'an' => 'Macronutrientes', 'k' => 'r_k', 'd' => 3],
                ['h' => 'Sodio', 'u' => $mgl, 'an' => 'Macronutrientes', 'k' => 'r_na', 'd' => 3],
                ['h' => 'Cobre', 'u' => $mgl, 'an' => 'Micronutrientes', 'k' => 'r_cu', 'd' => 3],
                ['h' => 'Zinc', 'u' => $mgl, 'an' => 'Micronutrientes', 'k' => 'r_zn', 'd' => 3],
                ['h' => 'Hierro', 'u' => $mgl, 'an' => 'Micronutrientes', 'k' => 'r_fe', 'd' => 3],
                ['h' => 'Manganeso', 'u' => $mgl, 'an' => 'Micronutrientes', 'k' => 'r_mn', 'd' => 3],
            ],
        ],
        'Foliar' => [
            'servicio' => 'Servicios analíticos',
            'encabezado' => 'Reporte de Análisis de Tejido Foliar',
            'codigo' => 'SAL-LAG-FOR-003', 'version' => 'Versión 15', 'fecha' => '04/02/2026',
            'titulo' => 'Reporte de Análisis de Tejido Foliar',
            'datos' => ['ingenio', 'fecha_ingreso', 'fecha_entrega'],
            'revisado_cargo' => 'Técnico revisor · validación técnica',
            'identificacion' => ['grupo' => null, 'cols' => [['k' => '__ident', 'h' => 'Identificación'], ['k' => 'finca', 'h' => 'Finca'], ['k' => 'loteCampo', 'h' => 'Lote'], ['k' => 'pante', 'h' => 'Pante']]],
            'metodos' => 'Nitrógeno: digestión húmeda y destilación por método Kjeldahl, Humedad por gravimetría. Nutrientes: digestión vía seca, solución en ácido clorhídrico 6 M y lectura por Absorción Atómica, Fósforo y Boro: lectura por espectrometría visible.',
            'columnas' => [
                ['h' => 'Nitrógeno', 'u' => '%', 'an' => 'Nitrógeno', 'k' => 'n', 'd' => 2],
                ['h' => 'Calcio', 'g' => '%', 'an' => 'Macronutrientes', 'k' => 'r_ca', 'd' => 3],
                ['h' => 'Magnesio', 'g' => '%', 'an' => 'Macronutrientes', 'k' => 'r_mg', 'd' => 3],
                ['h' => 'Potasio', 'g' => '%', 'an' => 'Macronutrientes', 'k' => 'r_k', 'd' => 3],
                ['h' => 'Fósforo', 'g' => '%', 'an' => 'Fósforo', 'k' => 'res', 'd' => 3],
                ['h' => 'Boro', 'g' => 'ppm', 'an' => 'Boro', 'k' => 'res', 'd' => 2],
                ['h' => 'Cobre', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_cu', 'd' => 2],
                ['h' => 'Cinc', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_zn', 'd' => 2],
                ['h' => 'Hierro', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_fe', 'd' => 2],
                ['h' => 'Manganeso', 'g' => 'ppm', 'an' => 'Micronutrientes', 'k' => 'r_mn', 'd' => 2],
                ['h' => 'Humedad', 'u' => '%', 'an' => 'Humedad', 'k' => 'pct', 'd' => 2],
            ],
        ],
        'Caña' => [
            'servicio' => 'Servicios analíticos',
            'encabezado' => 'Reporte de Análisis de Caña',
            'codigo' => 'SAL-LAG-FOR-004', 'version' => 'Versión 15', 'fecha' => '04/02/2026',
            'titulo' => 'Reporte de Análisis de Caña',
            'datos' => ['ensayo', 'fecha_ingreso_mayus', 'ingenio_mayus', 'fecha_reporte', 'finca_mayus', 'lote_mayus'],
            'nota' => 'LOTE: número de muestreo asignado a este grupo de muestras',
            'revisado_cargo' => 'Técnico de Laboratorio Agroindustrial',
            'identificacion' => ['grupo' => 'Identificación', 'cols' => [['k' => 'parcela', 'h' => 'Parcela']]],
            'metodos' => 'Brix jugo: lectura refractométrica directa, Pol Jugo: lectura polarimétrica de una solución normal, Pol % caña: consultar cálculo en el laboratorio; %Fibra: método Tanimoto; Humedad: Horno a 105 °C hasta peso constante.',
            'columnas' => [
                ['h' => 'Brix jugo', 'u' => '%', 'an' => 'Brix', 'k' => 'brix', 'd' => 2],
                ['h' => 'Pol jugo', 'u' => '%', 'an' => 'Pol', 'k' => 'pol', 'd' => 2],
                ['h' => 'Pureza jugo', 'u' => '%', 'an' => 'Pol', 'k' => 'pureza', 'd' => 2],
                ['h' => 'Jugo', 'u' => '%', 'an' => 'Rendimiento', 'k' => 'jugo', 'd' => 2],
                ['h' => 'Pol % caña', 'u' => '', 'an' => 'Rendimiento', 'k' => 'polc', 'd' => 2],
                ['h' => 'Rendimiento comercial', 'hg' => 'Rendimiento comercial', 'u' => 'libras/tonelada corta', 'an' => 'Rendimiento', 'k' => 'rcl', 'd' => 1],
                ['h' => 'Rendimiento comercial', 'hg' => 'Rendimiento comercial', 'u' => 'kilogramos/tonelada métrica', 'an' => 'Rendimiento', 'k' => 'rck', 'd' => 1],
                ['h' => 'Rendimiento real', 'hg' => 'Rendimiento real', 'u' => 'libras/tonelada', 'an' => 'Rendimiento', 'k' => 'rrl', 'd' => 1],
                ['h' => 'Rendimiento real', 'hg' => 'Rendimiento real', 'u' => 'kilogramos/tonelada métrica', 'an' => 'Rendimiento', 'k' => 'rrk', 'd' => 1],
                ['h' => 'Fibra', 'u' => '%', 'an' => '% Fibra', 'k' => 'fibra', 'd' => 2, 'extra' => true],
                ['h' => 'pH jugo', 'u' => '', 'an' => 'pH', 'k' => 'ph', 'd' => 2, 'extra' => true],
                ['h' => 'Humedad', 'u' => '%', 'an' => '% Humedad', 'k' => 'pct', 'd' => 2],
            ],
        ],
        // Otros tipos (p. ej. Mieles): mismo encabezado y pie, una columna por análisis solicitado.
        '_' => [
            'servicio' => 'Servicios analíticos',
            'encabezado' => 'Reporte de Análisis',
            'codigo' => 'SAL-LAG-FOR-000', 'version' => 'Versión 1', 'fecha' => '—',
            'titulo' => 'Reporte de Análisis',
            'datos' => ['codigo_muestreo', 'institucion', 'fecha_ingreso', 'fecha_entrega'],
            'revisado_cargo' => 'Técnico revisor',
            'identificacion' => null,
            'columnas' => [],
        ],
    ],
];
