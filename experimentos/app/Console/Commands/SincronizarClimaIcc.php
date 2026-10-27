<?php

namespace App\Console\Commands;

use App\Http\Controllers\Investigacion\ClimaController;
use App\Services\IccClima;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Descarga de la API agromet del ICC los datos diarios de todas las
 * estaciones (ultimos dias o un rango), para tenerlos guardados en SIGEC.
 * Programado en App\Console\Kernel (requiere `php artisan schedule:run` en cron).
 */
class SincronizarClimaIcc extends Command
{
    protected $signature = 'clima:sincronizar-icc
        {--dias=3 : Dias hacia atras (si no se indica --desde)}
        {--desde= : Fecha inicial Y-m-d (carga historica)}
        {--hasta= : Fecha final Y-m-d (por defecto hoy)}
        {--forzar : Volver a pedir dias ya descargados}';

    protected $description = 'Descarga de la API del ICC los datos diarios de sus estaciones';

    public function handle(IccClima $icc, ClimaController $clima): int
    {
        if (! $icc->configurada()) {
            $this->error('La API del ICC no esta configurada (ICC_API_URL).');

            return 1;
        }

        $hasta = $this->option('hasta') ? Carbon::parse($this->option('hasta'))->startOfDay() : Carbon::today();
        $desde = $this->option('desde')
            ? Carbon::parse($this->option('desde'))->startOfDay()
            : $hasta->copy()->subDays(max(1, (int) $this->option('dias')) - 1);

        try {
            $r = $clima->completarIcc($icc, $desde, $hasta, (bool) $this->option('forzar'), 'programada');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info($r['consultada']
            ? "{$r['registros']} registros de {$r['dias']} dia(s) ({$r['desde']} a {$r['hasta']}); {$r['estaciones_nuevas']} estacion(es) nueva(s)."
            : 'Sin dias pendientes en el rango.');

        return 0;
    }
}
