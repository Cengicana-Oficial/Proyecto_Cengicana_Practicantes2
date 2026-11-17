<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddIdMuestraAnteriorYEquivalenciasCodigos extends Migration
{
    /**
     * AUD-2026-003/M-4. La recodificacion (2026_10_28_090000) renombra el id_muestra de muestras
     * cuya etiqueta ya pudo imprimirse, y al escanear la etiqueta vieja el Laboratorio no las
     * encontraba. Aqui:
     *  - muestras_lab.id_muestra_anterior: el ID con que se imprimio la etiqueta (el Laboratorio lo busca tambien).
     *  - codificacion_equivalencias: cada cambio de codigo (proyecto, ensayo, muestra) queda en BD, no solo en el log.
     *  - Si la recodificacion original ya corrio, se reconstruye lo posible desde las lineas
     *    "[codificacion] ANTERIOR -> NUEVO" de storage/logs. Lo que el log no permita
     *    reconstruir sin ambiguedad (log rotado/borrado, cadenas de cambios) queda sin anterior.
     */
    public function up()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->string('id_muestra_anterior')->nullable()->after('id_muestra');
            $table->index('id_muestra_anterior');
        });

        Schema::create('codificacion_equivalencias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->unsignedBigInteger('registro_id');
            $table->string('codigo_anterior');
            $table->string('codigo_nuevo');
            $table->string('origen', 20)->default('recodificacion');
            $table->timestamp('created_at')->nullable();
            $table->index(['tipo', 'codigo_anterior']);
            $table->index(['tipo', 'registro_id']);
        });

        $this->reconstruirDesdeLog();
    }

    public function down()
    {
        Schema::dropIfExists('codificacion_equivalencias');
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->dropIndex(['id_muestra_anterior']);
            $table->dropColumn('id_muestra_anterior');
        });
    }

    /** [codigo nuevo => ['anterior' => ..., 'cuando' => 'Y-m-d H:i:s']] sin ambiguedades. */
    protected function cambiosDelLog(): array
    {
        $porNuevo = [];
        $anteriores = [];
        foreach (glob(storage_path('logs/*.log')) ?: [] as $archivo) {
            $fh = @fopen($archivo, 'r');
            if (! $fh) {
                continue;
            }
            while (($linea = fgets($fh)) !== false) {
                if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[^\]]*\].*?\[codificacion\] (\S+) -> (\S+)/', $linea, $m)) {
                    continue;
                }
                [, $cuando, $antes, $despues] = $m;
                $porNuevo[$despues][$antes] = $cuando;
                $anteriores[$antes] = true;
            }
            fclose($fh);
        }

        $cambios = [];
        foreach ($porNuevo as $nuevo => $origenes) {
            // Ambiguo: mas de un origen, o el anterior es a su vez resultado de otro cambio (cadena).
            if (count($origenes) !== 1) {
                continue;
            }
            $antes = array_key_first($origenes);
            if (isset($porNuevo[$antes]) || $antes === $nuevo) {
                continue;
            }
            $cambios[$nuevo] = ['anterior' => $antes, 'cuando' => $origenes[$antes]];
        }

        return $cambios;
    }

    protected function reconstruirDesdeLog(): void
    {
        $cambios = $this->cambiosDelLog();
        if (! $cambios) {
            return;
        }

        $registrar = fn (string $tipo, $id, string $antes, string $despues, string $cuando) => DB::table('codificacion_equivalencias')->insert([
            'tipo' => $tipo, 'registro_id' => $id, 'codigo_anterior' => $antes, 'codigo_nuevo' => $despues,
            'origen' => 'log', 'created_at' => $cuando,
        ]);

        foreach (DB::table('proyectos')->whereIn('codigo', array_keys($cambios))->get(['id', 'codigo']) as $p) {
            $registrar('proyecto', $p->id, $cambios[$p->codigo]['anterior'], $p->codigo, $cambios[$p->codigo]['cuando']);
        }

        foreach (DB::table('ensayos')->whereIn('codigo', array_keys($cambios))->get(['id', 'codigo']) as $e) {
            $c = $cambios[$e->codigo];
            $registrar('ensayo', $e->id, $c['anterior'], $e->codigo, $c['cuando']);

            // Solo muestras no vinculadas (la migracion original no toca las vinculadas) y creadas
            // antes del cambio: las posteriores ya nacieron con el codigo nuevo.
            $muestras = DB::table('muestras_lab')
                ->where('ensayo_id', $e->id)
                ->whereNull('lab_muestra_id')->whereNull('lab_vinculo')
                ->whereNull('id_muestra_anterior')
                ->where('created_at', '<=', $c['cuando'])
                ->get(['id', 'id_muestra']);
            foreach ($muestras as $m) {
                foreach (["SIGEC-{$e->codigo}-" => "SIGEC-{$c['anterior']}-", "{$e->codigo}-" => "{$c['anterior']}-"] as $actual => $previo) {
                    if (Str::startsWith($m->id_muestra, $actual)) {
                        $viejo = $previo . substr($m->id_muestra, strlen($actual));
                        // No pisar el ID de otra muestra (id_muestra_anterior solo se usa para buscar).
                        if (DB::table('muestras_lab')->where('id_muestra', $viejo)->exists()) {
                            break;
                        }
                        DB::table('muestras_lab')->where('id', $m->id)->update(['id_muestra_anterior' => $viejo]);
                        $registrar('muestra', $m->id, $viejo, $m->id_muestra, $c['cuando']);
                        break;
                    }
                }
            }
        }
    }
}
