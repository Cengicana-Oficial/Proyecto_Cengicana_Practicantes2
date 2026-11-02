<?php

namespace Database\Seeders;

use App\Models\Variable;
use Illuminate\Database\Seeder;

class VariableSeeder extends Seeder
{
    /**
     * Portado de la constante VARIABLES del prototipo SIGEC_v12.html.
     */
    protected array $variables = [
        ['id' => 1, 'nombre' => 'Altura', 'unidad' => 'cm', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo'],
        ['id' => 2, 'nombre' => 'Diámetro', 'unidad' => 'cm', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo'],
        ['id' => 3, 'nombre' => 'Biomasa', 'unidad' => 'kg', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo'],
        ['id' => 4, 'nombre' => 'Rendimiento', 'unidad' => 'TC/ha', 'tipo' => 'Numerica', 'categoria' => 'Cosecha'],
        ['id' => 5, 'nombre' => 'Brix', 'unidad' => '°Bx', 'tipo' => 'Numerica', 'categoria' => 'Cosecha'],
        ['id' => 6, 'nombre' => 'Pol', 'unidad' => '%', 'tipo' => 'Numerica', 'categoria' => 'Cosecha'],
        ['id' => 7, 'nombre' => 'Azúcar recuperable', 'unidad' => 'lbs/TC', 'tipo' => 'Numerica', 'categoria' => 'Cosecha'],
        ['id' => 8, 'nombre' => 'TCH', 'unidad' => 't caña/ha', 'tipo' => 'Numerica', 'categoria' => 'Cosecha'],
        ['id' => 9, 'nombre' => 'TAH', 'unidad' => 't azúcar/ha', 'tipo' => 'Numerica', 'categoria' => 'Cosecha'],
        ['id' => 10, 'nombre' => 'Población de tallos', 'unidad' => 'tallos/m', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo'],
        ['id' => 11, 'nombre' => 'Severidad de enfermedad', 'unidad' => '% área foliar', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo'],
    ];

    public function run()
    {
        foreach ($this->variables as $datos) {
            Variable::updateOrCreate(['id' => $datos['id']], $datos);
        }
    }
}
