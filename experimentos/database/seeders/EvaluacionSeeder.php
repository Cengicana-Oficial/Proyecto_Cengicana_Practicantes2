<?php

namespace Database\Seeders;

use App\Models\Evaluacion;
use App\Models\User;
use Illuminate\Database\Seeder;

class EvaluacionSeeder extends Seeder
{
    /**
     * Portado de la constante EVALUACIONES del prototipo SIGEC_v12.html. El
     * campo "responsable" del mockup era siempre el texto libre
     * "Investigador Demo" (no corresponde a ningun usuario real de
     * USUARIOS); aqui se asigna al usuario sembrado con rol investigador.
     */
    protected array $evaluaciones = [
        ['id' => 1, 'fecha' => '2026-05-02', 'variable_id' => 1, 'parcela_id' => 1, 'valor' => '182', 'obs' => 'Buen desarrollo'],
        ['id' => 2, 'fecha' => '2026-05-02', 'variable_id' => 1, 'parcela_id' => 2, 'valor' => '191', 'obs' => 'Sin novedad'],
        ['id' => 3, 'fecha' => '2026-05-09', 'variable_id' => 4, 'parcela_id' => 1, 'valor' => '118.4', 'obs' => 'Muestreo de madurez'],
        ['id' => 4, 'fecha' => '2026-05-09', 'variable_id' => 5, 'parcela_id' => 1, 'valor' => '19.8', 'obs' => null],
        ['id' => 5, 'fecha' => '2026-06-01', 'variable_id' => 6, 'parcela_id' => 2, 'valor' => '14.6', 'obs' => null],
        ['id' => 6, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 1, 'valor' => '118.4', 'obs' => 'Corte de zafra'],
        ['id' => 7, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 4, 'valor' => '121.7', 'obs' => null],
        ['id' => 8, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 7, 'valor' => '115.9', 'obs' => null],
        ['id' => 9, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 2, 'valor' => '131.2', 'obs' => null],
        ['id' => 10, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 5, 'valor' => '128.6', 'obs' => null],
        ['id' => 11, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 8, 'valor' => '134.0', 'obs' => null],
        ['id' => 12, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 3, 'valor' => '142.5', 'obs' => null],
        ['id' => 13, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 6, 'valor' => '139.8', 'obs' => null],
        ['id' => 14, 'fecha' => '2026-06-10', 'variable_id' => 8, 'parcela_id' => 9, 'valor' => '145.1', 'obs' => null],
        ['id' => 15, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 1, 'valor' => '13.1', 'obs' => null],
        ['id' => 16, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 4, 'valor' => '13.5', 'obs' => null],
        ['id' => 17, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 7, 'valor' => '12.8', 'obs' => null],
        ['id' => 18, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 2, 'valor' => '14.6', 'obs' => null],
        ['id' => 19, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 5, 'valor' => '14.2', 'obs' => null],
        ['id' => 20, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 8, 'valor' => '14.9', 'obs' => null],
        ['id' => 21, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 3, 'valor' => '15.8', 'obs' => null],
        ['id' => 22, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 6, 'valor' => '15.5', 'obs' => null],
        ['id' => 23, 'fecha' => '2026-06-10', 'variable_id' => 9, 'parcela_id' => 9, 'valor' => '16.1', 'obs' => null],
    ];

    public function run()
    {
        $responsableId = User::where('email', 'investigador@sigec.local')->value('id');

        foreach ($this->evaluaciones as $datos) {
            Evaluacion::updateOrCreate(['id' => $datos['id']], [
                'fecha' => $datos['fecha'],
                'variable_id' => $datos['variable_id'],
                'parcela_id' => $datos['parcela_id'],
                'valor' => $datos['valor'],
                'obs' => $datos['obs'],
                'responsable_id' => $responsableId,
            ]);
        }
    }
}
