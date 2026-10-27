<?php

namespace Database\Seeders;

use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProyectoSeeder extends Seeder
{
    /**
     * Portado de la constante PROYECTOS del prototipo SIGEC_v12.html. El campo
     * "responsable" del mockup era texto libre; aqui se enlaza a un usuario
     * real via responsable_id cuando el nombre coincide con uno sembrado por
     * UserSeeder, y queda null en caso contrario (el mockup nombraba a varios
     * lideres de programa que no existen como usuarios del sistema).
     */
    protected array $proyectos = [
        ['id' => 1, 'codigo' => 'AG-001', 'nombre' => 'Evaluación de densidades de siembra', 'programa_id' => 1, 'responsable' => 'Ing. Carlos Mejía', 'objetivo' => 'Determinar la densidad óptima de siembra para variedad CG98-78.', 'inicio' => '2025-02-01', 'fin' => '2026-01-31', 'estado' => 'Activo'],
        ['id' => 2, 'codigo' => 'AG-004', 'nombre' => 'Manejo de malezas en zona alta', 'programa_id' => 1, 'responsable' => 'Ing. Carlos Mejía', 'objetivo' => 'Evaluar herbicidas pre-emergentes en zona alta.', 'inicio' => '2025-05-10', 'fin' => '2025-12-15', 'estado' => 'Activo'],
        ['id' => 3, 'codigo' => 'AG-010', 'nombre' => 'Comparación de variedades CG', 'programa_id' => 1, 'responsable' => 'Ing. Juan Pérez', 'objetivo' => 'Comparar rendimiento de 6 variedades CG en finca comercial.', 'inicio' => '2024-11-01', 'fin' => '2025-10-30', 'estado' => 'Finalizado'],
        ['id' => 4, 'codigo' => 'FT-002', 'nombre' => 'Control de roya naranja', 'programa_id' => 2, 'responsable' => 'Dra. Ana Lucía Pérez', 'objetivo' => 'Evaluar fungicidas para control de roya naranja.', 'inicio' => '2025-03-01', 'fin' => '2025-11-30', 'estado' => 'Activo'],
        ['id' => 5, 'codigo' => 'RG-003', 'nombre' => 'Riego deficitario controlado', 'programa_id' => 5, 'responsable' => 'Ing. Eduardo Castillo', 'objetivo' => 'Evaluar respuesta del cultivo a riego deficitario en época seca.', 'inicio' => '2025-01-15', 'fin' => '2025-12-31', 'estado' => 'Activo'],
        ['id' => 6, 'codigo' => 'BT-001', 'nombre' => 'Selección clonal generación F3', 'programa_id' => 6, 'responsable' => 'Dra. Sofía Hernández', 'objetivo' => 'Seleccionar clones sobresalientes de la generación F3.', 'inicio' => '2024-06-01', 'fin' => '2026-06-01', 'estado' => 'Activo'],
        ['id' => 7, 'codigo' => 'SU-002', 'nombre' => 'Fertilización nitrogenada fraccionada', 'programa_id' => 4, 'responsable' => 'Ing. Patricia Ramírez', 'objetivo' => 'Determinar el mejor fraccionamiento de N en suelos francos.', 'inicio' => '2025-04-01', 'fin' => '2025-10-01', 'estado' => 'Pausado'],
    ];

    public function run()
    {
        foreach ($this->proyectos as $datos) {
            $responsableId = User::where('name', $datos['responsable'])->value('id');

            Proyecto::updateOrCreate(['id' => $datos['id']], [
                'codigo' => $datos['codigo'],
                'nombre' => $datos['nombre'],
                'programa_id' => $datos['programa_id'],
                'responsable_id' => $responsableId,
                'objetivo' => $datos['objetivo'],
                'inicio' => $datos['inicio'],
                'fin' => $datos['fin'],
                'estado' => $datos['estado'],
            ]);
        }
    }
}
