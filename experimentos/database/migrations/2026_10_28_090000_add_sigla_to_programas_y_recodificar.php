<?php

use App\Models\Programa;
use App\Models\Proyecto;
use App\Services\Codificacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AddSiglaToProgramasYRecodificar extends Migration
{
    /**
     * Codigos jerarquicos (ver App\Services\Codificacion): cada programa recibe una
     * sigla y los proyectos, ensayos e IDs de muestra existentes pasan al formato
     * SIGLA-AA-NN / SIGLA-AA-NN-ENN. Las muestras ya registradas en el Laboratorio
     * bloquean el cambio de su ensayo (queda en el log y se conserva el codigo).
     */
    public function up()
    {
        Schema::table('programas', function (Blueprint $table) {
            $table->string('sigla', 5)->nullable()->unique()->after('nombre');
        });

        foreach (Programa::orderBy('id')->get() as $programa) {
            $programa->forceFill(['sigla' => Codificacion::siglaSugerida($programa->nombre, $programa->id)])->save();
        }

        $proyectos = Proyecto::with('programa')->orderBy('programa_id')->orderByRaw('COALESCE(inicio, DATE(created_at))')->orderBy('id')->get();
        foreach ($proyectos as $proyecto) {
            try {
                foreach (Codificacion::recodificarProyecto($proyecto) as $antes => $despues) {
                    Log::info("[codificacion] {$antes} -> {$despues}");
                }
            } catch (RuntimeException $e) {
                Log::warning('[codificacion] ' . $e->getMessage());
            }
        }
    }

    public function down()
    {
        // Los codigos anteriores eran libres: no se restauran.
        Schema::table('programas', function (Blueprint $table) {
            $table->dropUnique(['sigla']);
            $table->dropColumn('sigla');
        });
    }
}
