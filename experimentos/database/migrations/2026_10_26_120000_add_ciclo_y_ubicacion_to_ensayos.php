<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCicloYUbicacionToEnsayos extends Migration
{
    /**
     * Datos del ensayo que necesitan las demas pantallas:
     * - nombre y objetivo (el codigo solo no describe el ensayo);
     * - ciclo del cultivo y fechas: siembra o corte anterior (base para la edad del
     *   cultivo / dias despues de siembra), cosecha prevista y real;
     * - estrato y zona (los mismos de las estaciones de clima), area total y suelo.
     */
    public function up()
    {
        Schema::table('ensayos', function (Blueprint $table) {
            $table->string('nombre', 200)->nullable()->after('codigo');
            $table->text('objetivo')->nullable()->after('nombre');
            $table->string('ciclo', 30)->nullable()->after('variedad');
            $table->date('fecha_siembra')->nullable()->after('ciclo');
            $table->date('fecha_cosecha_prevista')->nullable()->after('fecha_siembra');
            $table->date('fecha_cosecha')->nullable()->after('fecha_cosecha_prevista');
            $table->string('estrato', 20)->nullable()->after('lng');
            $table->string('zona', 20)->nullable()->after('estrato');
            $table->decimal('area_total', 10, 2)->nullable()->after('zona');
            $table->string('tipo_suelo', 100)->nullable()->after('area_total');
        });
    }

    public function down()
    {
        Schema::table('ensayos', function (Blueprint $table) {
            $table->dropColumn(['nombre', 'objetivo', 'ciclo', 'fecha_siembra', 'fecha_cosecha_prevista', 'fecha_cosecha', 'estrato', 'zona', 'area_total', 'tipo_suelo']);
        });
    }
}
