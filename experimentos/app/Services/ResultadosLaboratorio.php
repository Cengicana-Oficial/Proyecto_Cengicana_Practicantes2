<?php

namespace App\Services;

use App\Models\AnaliticoPorTipo;
use App\Models\MuestraLab;

/**
 * Resultados de laboratorio vistos como evaluaciones (parcela x variable x fecha), para que
 * Evaluaciones los muestre en la categoria "Laboratorio" junto a los datos de campo.
 * Son de solo lectura: vienen del informe firmado y enviado por el Laboratorio (o de la
 * captura de resultados en Muestras). La fecha es la del muestreo.
 */
class ResultadosLaboratorio
{
    /**
     * @return array{variables: array, registros: array}
     *   variables: [{id: 'lab:Suelo:ph', nombre, unidad, tipo, categoria: 'Laboratorio', origen: 'lab', tipo_muestra}]
     *   registros: [{id: 'lab-12-ph', fecha, parcela_id, variable_id, valor, origen: 'lab', muestra, muestra_id, informe, responsable}]
     */
    public function porEnsayos($ensayoIds): array
    {
        $catalogo = AnaliticoPorTipo::get()->keyBy(fn ($a) => "{$a->tipo_muestra}|{$a->clave}");
        $variables = [];
        $registros = [];

        MuestraLab::whereIn('ensayo_id', $ensayoIds)
            ->whereNotNull('analitos')->whereNotNull('parcela_id')
            ->orderBy('fecha')
            ->get(['id', 'id_muestra', 'fecha', 'tipo', 'parcela_id', 'analitos', 'resultados_lab', 'lab_informe', 'lab_firmado_por', 'analistas'])
            ->each(function ($m) use (&$variables, &$registros, $catalogo) {
                $lab = collect($m->resultados_lab ?: [])->keyBy('clave');
                foreach ($m->analitos ?: [] as $clave => $valor) {
                    $valor = trim((string) $valor);
                    if ($valor === '') {
                        continue;
                    }
                    $vid = "lab:{$m->tipo}:{$clave}";
                    if (! isset($variables[$vid])) {
                        $cat = $catalogo->get("{$m->tipo}|{$clave}");
                        $r = $lab->get($clave);
                        $variables[$vid] = [
                            'id' => $vid,
                            'nombre' => $r['nombre'] ?? ($cat->label ?? $clave),
                            'unidad' => $r['unidad'] ?? ($cat->unidad ?? ''),
                            'tipo' => is_numeric(str_replace(',', '.', $valor)) ? 'Numerica' : 'Texto',
                            'categoria' => 'Laboratorio',
                            'origen' => 'lab',
                            'tipo_muestra' => $m->tipo,
                            'valor_min' => null, 'valor_max' => null, 'decimales' => null, 'opciones' => null, 'activa' => true,
                        ];
                    }
                    $registros[] = [
                        'id' => "lab-{$m->id}-{$clave}",
                        'fecha' => $m->fecha->format('Y-m-d'),
                        'parcela_id' => $m->parcela_id,
                        'variable_id' => $vid,
                        'valor' => $valor,
                        'origen' => 'lab',
                        'muestra' => $m->id_muestra,
                        'muestra_id' => $m->id,
                        'informe' => $m->lab_informe,
                        'responsable' => $m->lab_firmado_por ?: $m->analistas,
                    ];
                }
            });

        // Mismo nombre de analito en dos tipos de muestra (p. ej. pH de suelo y de agua): se aclara.
        $nombres = collect($variables)->countBy('nombre');
        foreach ($variables as &$v) {
            if ($nombres[$v['nombre']] > 1) {
                $v['nombre'] .= " ({$v['tipo_muestra']})";
            }
        }
        unset($v);

        return ['variables' => array_values($variables), 'registros' => $registros];
    }
}
