<?php

require_once __DIR__ . '/catalogo_analisis_helper.php';
require_once __DIR__ . '/catalogo_muestras_helper.php';

if (!function_exists('lab_captura_variables_registry')) {
    function lab_captura_variables_registry(): array
    {
        static $registry = null;
        if ($registry !== null) {
            return $registry;
        }

        $rows = [
            ['suelos.textura', 'suelos', 'Textura', 'Suelos/textura_controller.php', []],
            ['suelos.humedad_residual', 'suelos', 'Humedad residual', 'Suelos/humedad_residual_controller.php', []],
            ['suelos.humedad_gravimetrica', 'suelos', 'Humedad gravimetrica', 'Suelos/humedad_gravimetrica_controller.php', []],
            ['suelos.porosidad_total', 'suelos', 'Porosidad total', null, []],
            ['suelos.dap', 'suelos', 'Densidad aparente (DAP)', 'Suelos/dap_controller.php', ['Densidad aparente']],
            ['suelos.densidad_real', 'suelos', 'Densidad real', null, []],
            ['suelos.cc', 'suelos', 'Capacidad de Campo', 'Suelos/cc_controller.php', ['Capacidad campo']],
            ['suelos.pmp', 'suelos', 'Punto de Marchitez Permanente', 'Suelos/pmp_controller.php', ['Marchitez Permanente', 'PMP']],
            ['suelos.ph', 'suelos', 'pH', 'Suelos/ph_controller.php', []],
            ['suelos.mo', 'suelos', 'Materia organica', 'Suelos/mo_controller.php', ['%MO', 'Materia orgánica']],
            ['suelos.macroscic', 'suelos', 'Macronutrientes y CIC', 'Suelos/macroscic_controller.php', ['Macronutrientes', 'CIC', 'CIC (capacidad de intercambio catiónico)']],
            ['suelos.potasio_intercambiable', 'suelos', 'Potasio intercambiable', null, []],
            ['suelos.micros', 'suelos', 'Micro Nutrientes (Cu, Zn, Fe, Mn, K)', 'Suelos/micros_controller.php', ['Micro Nutrientes', 'Micronutrientes']],
            ['suelos.nitrogeno', 'suelos', 'Nitrogeno', 'Suelos/nitrogeno_controller.php', ['Nitrógeno', 'Nitrógeno total', 'Nitrogeno total']],
            ['suelos.boro', 'suelos', 'Boro', 'Suelos/boro_controller.php', []],
            ['suelos.azufre', 'suelos', 'Azufre', 'Suelos/azufre_controller.php', ['SO4']],
            ['suelos.fosforo', 'suelos', 'Fosforo', 'Suelos/fosforo_controller.php', ['Fósforo', 'Fósforo disponible', 'Fosforo disponible']],
            ['suelos.conductividad_electrica', 'suelos', 'Conductividad Electrica', 'Suelos/conductividad_electrica_controller.php', ['Conductividad Eléctrica', 'CE']],

            ['aguas.macros', 'agua', 'Macronutrientes', 'Aguas/macros_controller.php', []],
            ['aguas.ras', 'agua', 'RAS', 'Aguas/ras_controller.php', []],
            ['aguas.boro', 'agua', 'Boro', 'Aguas/boro_controller.php', []],
            ['aguas.ph', 'agua', 'pH', 'Aguas/ph_controller.php', []],
            ['aguas.salinidad', 'agua', 'Salinidad', 'Aguas/salinidad_controller.php', []],
            ['aguas.dureza', 'agua', 'Dureza', 'Aguas/dureza_controller.php', ['Dureza total', 'Dureza total (CaCO₃)']],
            ['aguas.carbonatos', 'agua', 'Carbonatos', 'Aguas/carbonatos_controller.php', []],
            ['aguas.micros', 'agua', 'Micro Nutrientes (Cu, Zn, Fe, Mn)', 'Aguas/micros_controller.php', ['Micro Nutrientes', 'Micronutrientes']],
            ['aguas.fosforo', 'agua', 'Fosforo', 'Aguas/fosforo_controller.php', ['Fósforo']],
            ['aguas.conductividad', 'agua', 'Conductividad Electrica', 'Aguas/conductividad_controller.php', ['Conductividad Eléctrica', 'Conductividad eléctrica (CE)', 'CE']],
            ['aguas.tds', 'agua', 'TDS', 'Aguas/tds_controller.php', ['Sólidos totales disueltos', 'Solidos totales disueltos', 'Sólidos totales disueltos (STD)', 'STD']],
            ['aguas.coliformes', 'agua', 'Coliformes totales y fecales', null, []],
            ['aguas.nitratos_nitritos', 'agua', 'Nitratos / Nitritos', null, ['Nitratos', 'Nitritos']],
            ['aguas.resistividad', 'agua', 'Resistividad', 'Aguas/resistividad_controller.php', []],
            ['aguas.cloruros', 'agua', 'Cloruros', 'Aguas/cloruros_controller.php', []],
            ['aguas.alcanilidad', 'agua', 'Alcalinidad', 'Aguas/alcanilidad_controller.php', ['Alcanilidad']],
            ['aguas.bicarbonato', 'agua', 'Bicarbonatos', 'Aguas/bicarbonato_controller.php', ['Bicarbonato']],

            ['foliares.macros', 'foliares', 'Macronutrientes', 'Foliares/macros_controller.php', []],
            ['foliares.nitrogeno', 'foliares', 'Nitrogeno', 'Foliares/nitrogeno_controller.php', ['Nitrógeno']],
            ['foliares.boro', 'foliares', 'Boro', 'Foliares/boro_controller.php', []],
            ['foliares.micros', 'foliares', 'Micro Nutrientes (Cu, Zn, Fe, Mn, K)', 'Foliares/micros_controller.php', ['Micro Nutrientes', 'Micronutrientes']],
            ['foliares.fosforo', 'foliares', 'Fosforo', 'Foliares/fosforo_controller.php', ['Fósforo', 'Fósforo foliar', 'Fosforo foliar']],

            ['cana.peso_seco', 'cana', 'Peso seco', 'Cana/peso_seco_controller.php', []],
            ['cana.fibra', 'cana', 'Fibra', 'Cana/fibra_controller.php', ['Fibra bruta']],
            ['cana.humedad', 'cana', 'Porcentaje de Humedad', 'Cana/humedad_controller.php', ['% de Humedad', 'Humedad']],
            ['cana.brixpol', 'cana', 'Determinacion de Brix y Pol', 'Cana/brixpol_controller.php', ['Determinación de Brix y Pol', 'Brix y Pol']],

            ['mieles.brix', 'mieles', 'Brix', 'Mieles/brix_controller.php', []],
        ];

        $registry = [];
        foreach ($rows as [$key, $tipo, $label, $controller, $aliases]) {
            $registry[$key] = [
                'key' => $key,
                'tipo' => $tipo,
                'label' => $label,
                'controller' => $controller,
                'aliases' => array_values(array_unique(array_merge([$label], $aliases))),
            ];
        }

        return $registry;
    }
}

if (!function_exists('lab_captura_variables_normalizar')) {
    function lab_captura_variables_normalizar(string $value): string
    {
        return labCatalogoAnalisisNormalizarTexto($value);
    }
}

if (!function_exists('lab_captura_variables_por_clave')) {
    function lab_captura_variables_por_clave(string $key): ?array
    {
        $registry = lab_captura_variables_registry();
        return $registry[$key] ?? null;
    }
}

if (!function_exists('lab_captura_variables_resolver')) {
    function lab_captura_variables_resolver(string $tipo, string $analisis): ?array
    {
        $tipo = labCatalogoMuestrasClaveDesdePrefijo(null, $tipo);
        $analisis = lab_captura_variables_normalizar($analisis);

        foreach (lab_captura_variables_registry() as $entry) {
            $entryTipo = labCatalogoMuestrasClaveDesdePrefijo(null, (string) $entry['tipo']);
            if ($entryTipo !== $tipo) {
                continue;
            }

            foreach ($entry['aliases'] as $alias) {
                if (lab_captura_variables_normalizar((string) $alias) === $analisis) {
                    return $entry;
                }
            }
        }

        return null;
    }
}
