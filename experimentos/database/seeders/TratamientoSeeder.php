<?php

namespace Database\Seeders;

use App\Models\Tratamiento;
use Illuminate\Database\Seeder;

class TratamientoSeeder extends Seeder
{
    /**
     * Portado de la constante TRATAMIENTOS del prototipo SIGEC_v12.html.
     */
    protected array $tratamientos = [
        ['id' => 1, 'ensayo_id' => 1, 'codigo' => 'T1', 'descripcion' => 'Densidad 8 yemas/m lineal', 'unidades' => '4'],
        ['id' => 2, 'ensayo_id' => 1, 'codigo' => 'T2', 'descripcion' => 'Densidad 10 yemas/m lineal', 'unidades' => '4'],
        ['id' => 3, 'ensayo_id' => 1, 'codigo' => 'T3', 'descripcion' => 'Densidad 12 yemas/m lineal', 'unidades' => '4'],
        ['id' => 4, 'ensayo_id' => 2, 'codigo' => 'T1', 'descripcion' => 'Testigo sin manejo', 'unidades' => '3'],
        ['id' => 5, 'ensayo_id' => 2, 'codigo' => 'T2', 'descripcion' => 'Manejo intensivo', 'unidades' => '3'],
    ];

    public function run()
    {
        foreach ($this->tratamientos as $datos) {
            Tratamiento::updateOrCreate(['id' => $datos['id']], $datos);
        }
    }
}
