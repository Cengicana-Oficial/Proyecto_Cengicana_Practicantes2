<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEstratoZonaToEstacionesMeteoTable extends Migration
{
    /**
     * Agrupacion de las estaciones como en los tableros de clima de Cengicaña:
     * estrato altitudinal (ALTO, MEDIO, BAJO, LITORAL) y zona longitudinal
     * (OESTE, CENTRO, ESTE).
     */
    public function up()
    {
        Schema::table('estaciones_meteo', function (Blueprint $table) {
            $table->string('estrato', 20)->nullable()->after('altitud');
            $table->string('zona', 20)->nullable()->after('estrato');
        });
    }

    public function down()
    {
        Schema::table('estaciones_meteo', function (Blueprint $table) {
            $table->dropColumn(['estrato', 'zona']);
        });
    }
}
