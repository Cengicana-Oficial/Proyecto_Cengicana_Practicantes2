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
     *
     * La columna y la tabla las crea 2026_10_27_235900 (antes de la recodificacion, que en una
     * instalacion donde aun no corrio las llena directamente). Aqui solo se completan desde el log
     * los entornos donde la recodificacion ya habia corrido; lo ya registrado no se duplica.
     */
    public function up()
    {
        // 2026_10_27_235900 siempre corre antes (orden por nombre); si faltara algo, no hay donde reconstruir.
        if (! Schema::hasColumn('muestras_lab', 'id_muestra_anterior') || ! Schema::hasTable('codificacion_equivalencias')) {
            return;
        }

        $this->reconstruirDesdeLog();
    }

    public function down()
    {
        // La columna y la tabla son de 2026_10_27_235900; las filas reconstruidas desde el log se quitan.
        if (Schema::hasTable('codificacion_equivalencias')) {
            DB::table('codificacion_equivalencias')->where('origen', 'log')->delete();
        }
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

        // No duplica lo que la recodificacion ya registro directamente (instalaciones donde corrio despues de 2026_10_27_235900).
        $registrar = function (string $tipo, $id, string $antes, string $despues, string $cuando) {
            $clave = ['tipo' => $tipo, 'registro_id' => $id, 'codigo_anterior' => $antes, 'codigo_nuevo' => $despues];
            if (! DB::table('codificacion_equivalencias')->where($clave)->exists()) {
                DB::table('codificacion_equivalencias')->insert($clave + ['origen' => 'log', 'created_at' => $cuando]);
            }
        };

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
