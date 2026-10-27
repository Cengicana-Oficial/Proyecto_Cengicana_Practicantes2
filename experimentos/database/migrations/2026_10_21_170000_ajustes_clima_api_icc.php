<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AjustesClimaApiIcc extends Migration
{
    /**
     * API agromet del ICC (/api/agromet/datosdiarios): devuelve los datos
     * diarios de todas las estaciones por rango de fechas, sin coordenadas.
     * - Las estaciones creadas desde la API pueden no tener lat/lng aun.
     * - Se guardan la humedad minima y maxima diarias.
     * - clima_icc_fechas registra que dias ya se descargaron (con o sin datos).
     */
    public function up()
    {
        DB::statement('ALTER TABLE estaciones_meteo MODIFY lat DECIMAL(10,6) NULL, MODIFY lng DECIMAL(10,6) NULL');

        Schema::table('clima_diario', function (Blueprint $table) {
            $table->decimal('hr_min', 5, 2)->nullable()->after('hr');
            $table->decimal('hr_max', 5, 2)->nullable()->after('hr_min');
        });

        Schema::create('clima_icc_fechas', function (Blueprint $table) {
            $table->date('fecha')->primary();
            $table->unsignedSmallInteger('estaciones')->default(0);
            $table->timestamp('consultado_en');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clima_icc_fechas');
        Schema::table('clima_diario', function (Blueprint $table) {
            $table->dropColumn(['hr_min', 'hr_max']);
        });
        DB::statement('UPDATE estaciones_meteo SET lat = 0 WHERE lat IS NULL');
        DB::statement('UPDATE estaciones_meteo SET lng = 0 WHERE lng IS NULL');
        DB::statement('ALTER TABLE estaciones_meteo MODIFY lat DECIMAL(10,6) NOT NULL, MODIFY lng DECIMAL(10,6) NOT NULL');
    }
}
