<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAnalitosPorTipoTable extends Migration
{
    public function up()
    {
        Schema::create('analitos_por_tipo', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_muestra');
            $table->string('clave');
            $table->string('label');
            $table->string('unidad')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('analitos_por_tipo');
    }
}
