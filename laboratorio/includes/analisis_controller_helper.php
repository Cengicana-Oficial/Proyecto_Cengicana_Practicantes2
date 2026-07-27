<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analisis_post_helper.php';
require_once __DIR__ . '/analisis_generico_config.php';
require_once __DIR__ . '/shared_lot_controls_helper.php';
require_once __DIR__ . '/../models/analisis_generico_model.php';

if (!function_exists('lab_controller_config_or_forbidden')) {
    function lab_controller_config_or_forbidden(string $slug): array
    {
        $config = lab_generic_analysis_config($slug);
        if (!$config) {
            lab_forbidden('El formulario solicitado no esta configurado.');
        }

        return $config;
    }
}

if (!function_exists('lab_controller_validate_config')) {
    function lab_controller_validate_config(?array $config): array
    {
        if (!$config || !is_array($config)) {
            lab_forbidden('El formulario solicitado no esta configurado.');
        }

        return $config;
    }
}

if (!function_exists('lab_controller_prepare_generic_context')) {
    function lab_controller_prepare_generic_context(array $config): array
    {
        lab_require_analysis_access($config['key']);

        $contexto = [
            'tipos' => $config['tipos'],
            'analisis' => $config['analisis'],
            'label' => $config['elemento'] . ' en ' . $config['tipo'],
        ];

        $GLOBALS['labAnalysisContexto'] = $contexto;
        $GLOBALS['labSkipFooterBaseSave'] = true;

        return [
            'resultado' => lab_analysis_take_flash(),
            'contexto' => $contexto,
        ];
    }
}

if (!function_exists('lab_controller_collect_generic_rows')) {
    function lab_controller_apply_generic_formulas(array $config, array $row): array
    {
        $analysisKey = (string) ($config['key'] ?? '');

        switch ($analysisKey) {
            case 'suelos.cic':
                $pesoBase = isset($row['peso']) && (float) $row['peso'] > 0 ? (float) $row['peso'] : 5.0;
                $row['cic_meq'] = (($row['cic_muestra'] ?? 0) - ($row['cic_blanco'] ?? 0)) * 0.0298039 * 1000 / $pesoBase;
                break;

            case 'suelos.mo':
                $row['m1_dicromato'] = 1.04;
                $row['m2_dicromato'] = 1.04;
                $row['val_solucion_ferroso'] = (($row['m1_dicromato'] ?? 0) + ($row['m2_dicromato'] ?? 0)) / 2;
                $row['ml_util_sulfato_ferroso1N'] = 10.50;
                $row['normalidad_sulfato_ferroso'] = ($row['val_solucion_ferroso'] ?? 0) != 0
                    ? ((1.0 * 1.0) / $row['val_solucion_ferroso'])
                    : 0;
                $row['dicromato_consumido'] = $row['val_solucion_ferroso'] ?? 0;
                $row['porcentaje_carbono_organico'] = ($row['peso_muestra'] ?? 0) != 0
                    ? ((($row['ml_util_sulfato_ferroso1N'] ?? 0) - ($row['sulfato_ferroso_consumido'] ?? 0))
                        * ($row['normalidad_sulfato_ferroso'] ?? 0) * 0.39) / $row['peso_muestra']
                    : 0;
                $row['porcentaje_materia_organica'] = ($row['porcentaje_carbono_organico'] ?? 0) * 1.724;
                break;

            case 'suelos.textura':
                $factor = (100 - ($row['porcentaje_hr'] ?? 0)) != 0 ? 200 / (100 - ($row['porcentaje_hr'] ?? 0)) : 0;
                $row['lectura_corregida_1'] = ((($row['temp_1'] ?? 0) - 60) * 0.2) + ($row['lectura_1'] ?? 0);
                $row['porcentaje_l_a'] = ($row['lectura_corregida_1'] ?? 0) * $factor;
                $row['lectura_corregida_2'] = ((($row['temp_2'] ?? 0) - 60) * 0.2) + ($row['lectura_2'] ?? 0);
                $row['porcentaje_arcilla'] = ($row['lectura_corregida_2'] ?? 0) * $factor;
                $row['porcentaje_limo'] = ($row['porcentaje_l_a'] ?? 0) - ($row['porcentaje_arcilla'] ?? 0);
                $row['porcentaje_arena'] = 100 - ($row['porcentaje_l_a'] ?? 0);
                $row['total'] = ($row['porcentaje_arcilla'] ?? 0) + ($row['porcentaje_limo'] ?? 0) + ($row['porcentaje_arena'] ?? 0);
                break;

            case 'suelos.dap':
                $row['peso_suelo_seco'] = ($row['peso_muestra_seca'] ?? 0) - ($row['peso_caja'] ?? 0);
                $row['densidad'] = ($row['volumen_final'] ?? 0) != 0
                    ? ($row['peso_suelo_seco'] ?? 0) / $row['volumen_final']
                    : 0;
                break;

            case 'suelos.humedad_residual':
            case 'suelos.humedad_gravimetrica':
                $row['PesoCajaMHumeda'] = ($row['PesoCaja'] ?? 0) + ($row['PesoHumedo'] ?? 0);
                $row['PesoSeco'] = ($row['PesoCajaMseca'] ?? 0) - ($row['PesoCaja'] ?? 0);
                $row['PorHGrav'] = ($row['PesoHumedo'] ?? 0) != 0
                    ? ((($row['PesoCajaMHumeda'] ?? 0) - ($row['PesoCajaMseca'] ?? 0)) * 100) / $row['PesoHumedo']
                    : 0;
                break;
        }

        return $row;
    }

    function lab_controller_shared_field_names(array $config): array
    {
        $shared = [];

        foreach ($config['fields'] as $field) {
            $name = (string) ($field['name'] ?? '');
            $label = (string) ($field['label'] ?? '');
            $search = strtolower($name . ' ' . $label);

            if (!empty($field['shared_per_lot']) || strpos($search, 'control') !== false || strpos($search, 'blanco') !== false) {
                $shared[] = $name;
            }
        }

        return array_values(array_unique(array_filter($shared)));
    }

    function lab_controller_collect_generic_rows(array $config): array
    {
        $fieldNames = array_map(fn($field) => $field['name'], $config['fields']);
        $rowCountFields = array_merge($fieldNames, ['lote', 'numero_laboratorio']);
        $rows = [];
        $sharedFieldNames = lab_controller_shared_field_names($config);
        $sharedByLote = $sharedFieldNames ? labSharedControlRowsByLote($sharedFieldNames) : [];

        for ($fila = 0, $total = lab_post_row_count($rowCountFields); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($rowCountFields, $fila)) {
                continue;
            }

            $row = [
                'lote' => lab_post_string('lote', $fila),
                'numero_laboratorio' => lab_post_string('numero_laboratorio', $fila),
            ];
            if (labSharedControlKeyFromNumero($row['numero_laboratorio']) !== null) {
                continue;
            }

            foreach ($config['fields'] as $field) {
                $name = $field['name'];
                $type = $field['type'] ?? 'number';
                if (!empty($field['computed'])) {
                    continue;
                }
                $sharedValue = $sharedByLote[$row['lote']][$name] ?? null;

                if ($sharedValue !== null && in_array($name, $sharedFieldNames, true)) {
                    $row[$name] = $sharedValue;
                    continue;
                }

                $row[$name] = $type === 'text'
                    ? lab_post_string($name, $fila)
                    : lab_post_float($name, $fila);
            }

            $row = lab_controller_apply_generic_formulas($config, $row);
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('lab_controller_handle_generic_post')) {
    function lab_controller_handle_generic_post(array $config, ?array $resultadoActual): array
    {
        $resultadoActual = is_array($resultadoActual) ? $resultadoActual : [];

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $resultadoActual;
        }

        return labGenericGuardarAnalisis(
            $config,
            lab_controller_collect_generic_rows($config),
            trim((string) ($_POST['fecha'] ?? date('Y-m-d'))),
            trim((string) ($_POST['analista'] ?? ''))
        );
    }
}

if (!function_exists('lab_controller_render_generic_analysis')) {
    function lab_controller_render_generic_analysis_from_config(array $config, string $viewPath): void
    {
        $config = lab_controller_validate_config($config);
        $prepared = lab_controller_prepare_generic_context($config);
        $resultado = lab_controller_handle_generic_post($config, $prepared['resultado']);

        lab_analysis_redirect_after_success($resultado);
        require $viewPath;
    }

    function lab_controller_render_generic_analysis(string $slug, string $viewPath): void
    {
        $config = lab_controller_config_or_forbidden($slug);
        lab_controller_render_generic_analysis_from_config($config, $viewPath);
    }
}
