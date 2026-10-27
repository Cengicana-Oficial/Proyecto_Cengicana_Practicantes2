<?php

namespace App\Console\Commands;

use App\Services\LaboratorioModulo;
use Illuminate\Console\Command;

/** Lee del modulo Laboratorio el avance y los resultados firmados de las muestras de SIGEC. */
class SincronizarLaboratorio extends Command
{
    protected $signature = 'laboratorio:sincronizar';

    protected $description = 'Trae del modulo Laboratorio (solo lectura) el avance y los resultados de las muestras de SIGEC';

    public function handle(LaboratorioModulo $laboratorio)
    {
        $r = $laboratorio->sincronizar();
        if (! $r['ok']) {
            $this->error($r['error']);

            return 1;
        }
        $this->info("Vinculadas: {$r['vinculadas']} · con resultado: {$r['con_resultado']} · actualizadas: {$r['actualizadas']} · codigos SIGEC sin coincidencia: {$r['sin_coincidencia']}");

        return 0;
    }
}
