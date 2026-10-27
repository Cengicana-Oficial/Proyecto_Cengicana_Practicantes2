<?php

/*
 * Copia de solo lectura del catalogo de columnas de informe del modulo Laboratorio
 * (laboratorio/config/informes_formatos.php). Clave = 'analisis|campo' tal como se guarda
 * en lab_informe.resultados_json; h = nombre, u = unidad, d = decimales.
 * Si el Laboratorio agrega columnas, las nuevas se muestran con su clave hasta actualizar esta copia.
 */

return [
    // Tipo de muestra de SIGEC => formato del Laboratorio
    'equivalencias' => ['Suelo' => 'Suelo', 'Agua' => 'Agua', 'Tejido vegetal' => 'Foliar', 'Jugos' => 'Caña'],
    'formatos' => array (
  'Suelo' => 
  array (
    'Conductividad eléctrica|ceDs' => 
    array (
      'h' => 'Conductividad Eléctrica',
      'u' => 'dS/m',
      'd' => 3,
      'grupo' => NULL,
    ),
    'pH|ph' => 
    array (
      'h' => 'pH en agua',
      'u' => '',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Materia orgánica|mo' => 
    array (
      'h' => 'Materia Orgánica',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_ca' => 
    array (
      'h' => 'Calcio',
      'u' => 'Meq intercambiables/100 g suelo',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_mg' => 
    array (
      'h' => 'Magnesio',
      'u' => 'Meq intercambiables/100 g suelo',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_k' => 
    array (
      'h' => 'Potasio',
      'u' => 'Meq intercambiables/100 g suelo',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_na' => 
    array (
      'h' => 'Sodio',
      'u' => 'Meq intercambiables/100 g suelo',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Capacidad de intercambio catiónico (CIC)|cic' => 
    array (
      'h' => 'Capacidad de Intercambio Catiónico',
      'u' => 'Meq intercambiables/100 g suelo',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Fósforo disponible|res' => 
    array (
      'h' => 'Fósforo',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_cu' => 
    array (
      'h' => 'Cobre',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_zn' => 
    array (
      'h' => 'Cinc',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_fe' => 
    array (
      'h' => 'Hierro',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_mn' => 
    array (
      'h' => 'Manganeso',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Textura|arcilla' => 
    array (
      'h' => 'Arcilla',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Textura|limo' => 
    array (
      'h' => 'Limo',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Textura|arena' => 
    array (
      'h' => 'Arena',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Textura|clase' => 
    array (
      'h' => 'Tipo de Textura',
      'u' => '',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Punto de marchitez permanente (PMP)|pmp_pct' => 
    array (
      'h' => 'Punto de Marchitez Permanente',
      'u' => '% H',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Capacidad de campo (CC)|cc_pct' => 
    array (
      'h' => 'Capacidad de Campo',
      'u' => '% H',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Densidad aparente|da' => 
    array (
      'h' => 'Densidad Aparente',
      'u' => 'g/cm3',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Humedad gravimétrica|pct' => 
    array (
      'h' => 'Humedad Gravimétrica',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Nitrógeno total|n' => 
    array (
      'h' => 'Nitrógeno total',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Azufre|res' => 
    array (
      'h' => 'Azufre',
      'u' => 'ppm SO₄',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Boro|res' => 
    array (
      'h' => 'Boro',
      'u' => 'ppm',
      'd' => 3,
      'grupo' => NULL,
    ),
  ),
  'Agua' => 
  array (
    'Conductividad eléctrica|ceDs' => 
    array (
      'h' => 'Conductividad Eléctrica',
      'u' => 'dS/m',
      'd' => 3,
      'grupo' => NULL,
    ),
    'pH|ph' => 
    array (
      'h' => 'pH',
      'u' => '',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Sólidos totales disueltos|tds' => 
    array (
      'h' => 'Sólidos Totales',
      'u' => 'mg/L',
      'd' => 1,
      'grupo' => NULL,
    ),
    'Cloruros|cl' => 
    array (
      'h' => 'Cloruros',
      'u' => 'mg/L',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Dureza|dur' => 
    array (
      'h' => 'Dureza',
      'u' => 'mg/L',
      'd' => 0,
      'grupo' => NULL,
    ),
    'Alcalinidad total|alcR' => 
    array (
      'h' => 'Alcalinidad',
      'u' => 'mg/L',
      'd' => 1,
      'grupo' => NULL,
    ),
    'Carbonatos|co3' => 
    array (
      'h' => 'Carbonatos',
      'u' => 'mg/L',
      'd' => 1,
      'grupo' => NULL,
    ),
    'Bicarbonatos|hco3' => 
    array (
      'h' => 'Bicarbonatos',
      'u' => 'mg/L',
      'd' => 1,
      'grupo' => NULL,
    ),
    'Razón de adsorción de sodio (RAS)|ras' => 
    array (
      'h' => 'Razón de Adsorción Sodio',
      'u' => 'Un',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Resistividad|rk' => 
    array (
      'h' => 'Resistividad',
      'u' => 'Kohm-cm',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Salinidad|sal' => 
    array (
      'h' => 'Salinidad',
      'u' => 'UPS',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Fósforo|res' => 
    array (
      'h' => 'Fósforo',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Boro|res' => 
    array (
      'h' => 'Boro',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_ca' => 
    array (
      'h' => 'Calcio',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_mg' => 
    array (
      'h' => 'Magnesio',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_k' => 
    array (
      'h' => 'Potasio',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_na' => 
    array (
      'h' => 'Sodio',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_cu' => 
    array (
      'h' => 'Cobre',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_zn' => 
    array (
      'h' => 'Zinc',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_fe' => 
    array (
      'h' => 'Hierro',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_mn' => 
    array (
      'h' => 'Manganeso',
      'u' => 'mg/L',
      'd' => 3,
      'grupo' => NULL,
    ),
  ),
  'Foliar' => 
  array (
    'Nitrógeno|n' => 
    array (
      'h' => 'Nitrógeno',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_ca' => 
    array (
      'h' => 'Calcio',
      'u' => '%',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_mg' => 
    array (
      'h' => 'Magnesio',
      'u' => '%',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Macronutrientes|r_k' => 
    array (
      'h' => 'Potasio',
      'u' => '%',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Fósforo|res' => 
    array (
      'h' => 'Fósforo',
      'u' => '%',
      'd' => 3,
      'grupo' => NULL,
    ),
    'Boro|res' => 
    array (
      'h' => 'Boro',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_cu' => 
    array (
      'h' => 'Cobre',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_zn' => 
    array (
      'h' => 'Cinc',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_fe' => 
    array (
      'h' => 'Hierro',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Micronutrientes|r_mn' => 
    array (
      'h' => 'Manganeso',
      'u' => 'ppm',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Humedad|pct' => 
    array (
      'h' => 'Humedad',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
  ),
  'Caña' => 
  array (
    'Brix|brix' => 
    array (
      'h' => 'Brix jugo',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Pol|pol' => 
    array (
      'h' => 'Pol jugo',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Pol|pureza' => 
    array (
      'h' => 'Pureza jugo',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Rendimiento|jugo' => 
    array (
      'h' => 'Jugo',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Rendimiento|polc' => 
    array (
      'h' => 'Pol % caña',
      'u' => '',
      'd' => 2,
      'grupo' => NULL,
    ),
    'Rendimiento|rcl' => 
    array (
      'h' => 'Rendimiento comercial',
      'u' => 'libras/tonelada corta',
      'd' => 1,
      'grupo' => 'Rendimiento comercial',
    ),
    'Rendimiento|rck' => 
    array (
      'h' => 'Rendimiento comercial',
      'u' => 'kilogramos/tonelada métrica',
      'd' => 1,
      'grupo' => 'Rendimiento comercial',
    ),
    'Rendimiento|rrl' => 
    array (
      'h' => 'Rendimiento real',
      'u' => 'libras/tonelada',
      'd' => 1,
      'grupo' => 'Rendimiento real',
    ),
    'Rendimiento|rrk' => 
    array (
      'h' => 'Rendimiento real',
      'u' => 'kilogramos/tonelada métrica',
      'd' => 1,
      'grupo' => 'Rendimiento real',
    ),
    '% Fibra|fibra' => 
    array (
      'h' => 'Fibra',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
    'pH|ph' => 
    array (
      'h' => 'pH jugo',
      'u' => '',
      'd' => 2,
      'grupo' => NULL,
    ),
    '% Humedad|pct' => 
    array (
      'h' => 'Humedad',
      'u' => '%',
      'd' => 2,
      'grupo' => NULL,
    ),
  ),
),

    /*
     * API por la que el modulo Laboratorio ENVIA resultados a SIGEC (el Laboratorio
     * decide que y cuando). Cada envio va firmado con HMAC-SHA256 usando el secreto
     * compartido; ver docs/api-laboratorio-resultados.md.
     */
    'api' => [
        // Secreto compartido (vacio = API desactivada).
        'secreto' => env('LAB_API_SECRET', ''),
        // Antiguedad maxima de un envio (segundos) para evitar reenvios capturados.
        'ventana' => (int) env('LAB_API_VENTANA', 300),
        // Redes desde las que se aceptan envios (por defecto solo redes internas).
        'redes' => array_filter(array_map('trim', explode(',', env('LAB_API_REDES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1/32')))),
    ],
];
