<?php

namespace Database\Seeders;

use App\Models\Parcela;
use App\Models\Tratamiento;
use Illuminate\Database\Seeder;

class ParcelaSeeder extends Seeder
{
    /**
     * Portado de la constante PARCELAS del prototipo SIGEC_v12.html.
     */
    protected array $parcelas = [
        ['id' => 1, 'ensayo_id' => 1, 'codigo' => 'P-01', 'tratamiento_codigo' => 'T1', 'repeticion' => 1, 'area' => 50],
        ['id' => 2, 'ensayo_id' => 1, 'codigo' => 'P-02', 'tratamiento_codigo' => 'T2', 'repeticion' => 1, 'area' => 50],
        ['id' => 3, 'ensayo_id' => 1, 'codigo' => 'P-03', 'tratamiento_codigo' => 'T3', 'repeticion' => 1, 'area' => 50],
        ['id' => 4, 'ensayo_id' => 1, 'codigo' => 'P-04', 'tratamiento_codigo' => 'T1', 'repeticion' => 2, 'area' => 50],
        ['id' => 5, 'ensayo_id' => 1, 'codigo' => 'P-05', 'tratamiento_codigo' => 'T2', 'repeticion' => 2, 'area' => 50],
        ['id' => 6, 'ensayo_id' => 1, 'codigo' => 'P-06', 'tratamiento_codigo' => 'T3', 'repeticion' => 2, 'area' => 50],
        ['id' => 7, 'ensayo_id' => 1, 'codigo' => 'P-07', 'tratamiento_codigo' => 'T1', 'repeticion' => 3, 'area' => 50],
        ['id' => 8, 'ensayo_id' => 1, 'codigo' => 'P-08', 'tratamiento_codigo' => 'T2', 'repeticion' => 3, 'area' => 50],
        ['id' => 9, 'ensayo_id' => 1, 'codigo' => 'P-09', 'tratamiento_codigo' => 'T3', 'repeticion' => 3, 'area' => 50],
        ['id' => 10, 'ensayo_id' => 2, 'codigo' => 'P-01', 'tratamiento_codigo' => 'T1', 'repeticion' => 1, 'area' => 40],
    ];

    public function run()
    {
        foreach ($this->parcelas as $datos) {
            $tratamientoId = Tratamiento::where('ensayo_id', $datos['ensayo_id'])
                ->where('codigo', $datos['tratamiento_codigo'])
                ->value('id');

            Parcela::updateOrCreate(['id' => $datos['id']], [
                'ensayo_id' => $datos['ensayo_id'],
                'tratamiento_id' => $tratamientoId,
                'codigo' => $datos['codigo'],
                'repeticion' => $datos['repeticion'],
                'area' => $datos['area'],
            ]);
        }
    }
}
