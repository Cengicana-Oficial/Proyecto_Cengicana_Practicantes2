<?php

namespace Database\Seeders;

use App\Models\Programa;
use Illuminate\Database\Seeder;

class ProgramaSeeder extends Seeder
{
    /**
     * Portado de la constante PROGRAMAS del prototipo SIGEC_v12.html.
     */
    protected array $programas = [
        ['id' => 1, 'nombre' => 'Agronomía', 'descripcion' => 'Manejo agronómico del cultivo de caña', 'lider' => 'Ing. Carlos Mejía', 'color' => '#73BC25'],
        ['id' => 2, 'nombre' => 'Fitopatología', 'descripcion' => 'Estudio y control de enfermedades', 'lider' => 'Dra. Ana Lucía Pérez', 'color' => '#FF6B00'],
        ['id' => 3, 'nombre' => 'Entomología', 'descripcion' => 'Manejo de plagas e insectos', 'lider' => 'Ing. Mario Solórzano', 'color' => '#FFCC00'],
        ['id' => 4, 'nombre' => 'Suelos', 'descripcion' => 'Fertilidad y manejo de suelos', 'lider' => 'Ing. Patricia Ramírez', 'color' => '#A3D300'],
        ['id' => 5, 'nombre' => 'Riego', 'descripcion' => 'Eficiencia y manejo del riego', 'lider' => 'Ing. Eduardo Castillo', 'color' => '#1f6fbf'],
        ['id' => 6, 'nombre' => 'Biotecnología', 'descripcion' => 'Mejoramiento genético y biotecnología', 'lider' => 'Dra. Sofía Hernández', 'color' => '#73BC25'],
        ['id' => 7, 'nombre' => 'Agromecánica Digital', 'descripcion' => 'Tecnología y mecanización agrícola', 'lider' => 'Ing. Luis Donis', 'color' => '#FF6B00'],
        ['id' => 8, 'nombre' => 'Fábrica', 'descripcion' => 'Procesos de fábrica e ingenio', 'lider' => 'Ing. Roberto Aguilar', 'color' => '#6a7466'],
        ['id' => 9, 'nombre' => 'Nutrición', 'descripcion' => 'Nutrición vegetal y fertilización', 'lider' => 'Ing. Diana López', 'color' => '#A3D300'],
    ];

    public function run()
    {
        foreach ($this->programas as $datos) {
            Programa::updateOrCreate(['id' => $datos['id']], $datos);
        }
    }
}
