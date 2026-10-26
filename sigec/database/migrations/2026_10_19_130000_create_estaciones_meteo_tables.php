<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEstacionesMeteoTables extends Migration
{
    /**
     * Catalogo de estaciones meteorologicas (red ICC / REDMET u otras) y sus
     * registros diarios, para la pestana "Clima" del detalle de ensayo.
     * Los datos se cargan desde los archivos que exporta REDMET (diarios o
     * cada 15 min, agregados a diario al importar).
     */
    public function up()
    {
        Schema::create('estaciones_meteo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
            $table->string('red', 60)->default('ICC REDMET');
            $table->decimal('lat', 10, 6);
            $table->decimal('lng', 10, 6);
            $table->unsignedInteger('altitud')->nullable();
            $table->string('ubicacion', 150)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('clima_diario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones_meteo')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('lluvia', 7, 2)->nullable();
            $table->decimal('tmax', 5, 2)->nullable();
            $table->decimal('tmin', 5, 2)->nullable();
            $table->decimal('tmed', 5, 2)->nullable();
            $table->decimal('hr', 5, 2)->nullable();
            $table->decimal('viento', 6, 2)->nullable();
            $table->decimal('radiacion', 6, 2)->nullable();
            $table->decimal('eto', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'fecha']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('clima_diario');
        Schema::dropIfExists('estaciones_meteo');
    }
}
