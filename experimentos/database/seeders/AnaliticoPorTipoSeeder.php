<?php

namespace Database\Seeders;

use App\Models\AnaliticoPorTipo;
use Illuminate\Database\Seeder;

class AnaliticoPorTipoSeeder extends Seeder
{
    /**
     * Portado de la constante ANALITOS_POR_TIPO del prototipo SIGEC_v12.html.
     */
    protected array $catalogo = [
        'Suelo' => [
            ['clave' => 'pH', 'label' => 'pH', 'unidad' => ''],
            ['clave' => 'MO', 'label' => 'M.O.', 'unidad' => '%'],
            ['clave' => 'N', 'label' => 'N tot.', 'unidad' => '%'],
            ['clave' => 'P', 'label' => 'P disp.', 'unidad' => 'ppm'],
            ['clave' => 'K', 'label' => 'K', 'unidad' => 'cmol/L'],
            ['clave' => 'Ca', 'label' => 'Ca', 'unidad' => 'cmol/L'],
            ['clave' => 'Mg', 'label' => 'Mg', 'unidad' => 'cmol/L'],
            ['clave' => 'CE', 'label' => 'C.E.', 'unidad' => 'dS/m'],
        ],
        'Tejido vegetal' => [
            ['clave' => 'N', 'label' => 'N', 'unidad' => '%'],
            ['clave' => 'P', 'label' => 'P', 'unidad' => '%'],
            ['clave' => 'K', 'label' => 'K', 'unidad' => '%'],
            ['clave' => 'Ca', 'label' => 'Ca', 'unidad' => '%'],
            ['clave' => 'Mg', 'label' => 'Mg', 'unidad' => '%'],
            ['clave' => 'S', 'label' => 'S', 'unidad' => '%'],
            ['clave' => 'Fe', 'label' => 'Fe', 'unidad' => 'ppm'],
            ['clave' => 'Mn', 'label' => 'Mn', 'unidad' => 'ppm'],
            ['clave' => 'Zn', 'label' => 'Zn', 'unidad' => 'ppm'],
        ],
        'Jugos' => [
            ['clave' => 'Brix', 'label' => 'Brix', 'unidad' => '°Bx'],
            ['clave' => 'Pol', 'label' => 'Pol', 'unidad' => '%'],
            ['clave' => 'Pureza', 'label' => 'Pureza', 'unidad' => '%'],
            ['clave' => 'AR', 'label' => 'A.R.', 'unidad' => '%'],
            ['clave' => 'Fibra', 'label' => 'Fibra', 'unidad' => '%'],
            ['clave' => 'TCH', 'label' => 'TCH', 'unidad' => 't/ha'],
            ['clave' => 'TAH', 'label' => 'TAH', 'unidad' => 't/ha'],
        ],
        'Mieles' => [
            ['clave' => 'Brix', 'label' => 'Brix', 'unidad' => '°Bx'],
            ['clave' => 'Pol', 'label' => 'Pol', 'unidad' => '%'],
            ['clave' => 'Pureza', 'label' => 'Pureza', 'unidad' => '%'],
            ['clave' => 'pH', 'label' => 'pH', 'unidad' => ''],
            ['clave' => 'Color', 'label' => 'Color', 'unidad' => 'IU'],
        ],
        'Agua' => [
            ['clave' => 'pH', 'label' => 'pH', 'unidad' => ''],
            ['clave' => 'CE', 'label' => 'C.E.', 'unidad' => 'dS/m'],
            ['clave' => 'Cl', 'label' => 'Cl', 'unidad' => 'meq/L'],
            ['clave' => 'SO4', 'label' => 'SO4', 'unidad' => 'meq/L'],
            ['clave' => 'Na', 'label' => 'Na', 'unidad' => 'meq/L'],
            ['clave' => 'Ca', 'label' => 'Ca', 'unidad' => 'meq/L'],
            ['clave' => 'RAS', 'label' => 'R.A.S.', 'unidad' => ''],
        ],
        'Fertilizantes' => [
            ['clave' => 'N', 'label' => 'N', 'unidad' => '%'],
            ['clave' => 'P2O5', 'label' => 'P2O5', 'unidad' => '%'],
            ['clave' => 'K2O', 'label' => 'K2O', 'unidad' => '%'],
            ['clave' => 'CaO', 'label' => 'CaO', 'unidad' => '%'],
            ['clave' => 'MgO', 'label' => 'MgO', 'unidad' => '%'],
            ['clave' => 'S', 'label' => 'S', 'unidad' => '%'],
            ['clave' => 'Humedad', 'label' => 'Humedad', 'unidad' => '%'],
        ],
    ];

    public function run()
    {
        foreach ($this->catalogo as $tipoMuestra => $analitos) {
            foreach ($analitos as $analito) {
                AnaliticoPorTipo::updateOrCreate([
                    'tipo_muestra' => $tipoMuestra,
                    'clave' => $analito['clave'],
                ], [
                    'label' => $analito['label'],
                    'unidad' => $analito['unidad'],
                ]);
            }
        }
    }
}
