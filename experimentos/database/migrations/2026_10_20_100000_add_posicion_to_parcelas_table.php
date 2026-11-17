<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPosicionToParcelasTable extends Migration
{
    /**
     * Posicion fisica de la parcela dentro de su repeticion (bloque), de
     * izquierda a derecha en campo. Permite tratamientos aleatorizados: el
     * croquis se ordena por posicion y no por tratamiento.
     */
    public function up()
    {
        Schema::table('parcelas', function (Blueprint $table) {
            $table->unsignedSmallInteger('posicion')->nullable()->after('repeticion');
        });

        // Parcelas existentes: el orden en que se crearon dentro de cada repeticion.
        $grupos = DB::table('parcelas')->orderBy('id')->get(['id', 'ensayo_id', 'repeticion'])
            ->groupBy(fn ($p) => $p->ensayo_id.'#'.$p->repeticion);
        foreach ($grupos as $parcelas) {
            foreach ($parcelas->values() as $i => $p) {
                DB::table('parcelas')->where('id', $p->id)->update(['posicion' => $i + 1]);
            }
        }
    }

    public function down()
    {
        Schema::table('parcelas', function (Blueprint $table) {
            $table->dropColumn('posicion');
        });
    }
}
