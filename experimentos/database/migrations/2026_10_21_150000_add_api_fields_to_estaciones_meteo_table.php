<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApiFieldsToEstacionesMeteoTable extends Migration
{
    /**
     * Enlace de cada estacion con la API de la red ICC: identificador de la
     * estacion en la API (si difiere del codigo interno) y ultima consulta.
     */
    public function up()
    {
        Schema::table('estaciones_meteo', function (Blueprint $table) {
            $table->string('codigo_api', 60)->nullable()->after('codigo');
            $table->timestamp('sincronizada_en')->nullable()->after('activa');
        });
    }

    public function down()
    {
        Schema::table('estaciones_meteo', function (Blueprint $table) {
            $table->dropColumn(['codigo_api', 'sincronizada_en']);
        });
    }
}
