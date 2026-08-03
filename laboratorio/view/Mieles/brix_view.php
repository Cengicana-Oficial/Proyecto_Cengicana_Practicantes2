<?php
if (isset($config) && is_array($config)) {
    foreach ($config['fields'] ?? [] as &$field) {
        if (($field['name'] ?? '') === 'brix_corr') {
            $field['computed'] = true;
        }
    }
    unset($field);
}

require __DIR__ . '/../analisis_generico_view.php';
