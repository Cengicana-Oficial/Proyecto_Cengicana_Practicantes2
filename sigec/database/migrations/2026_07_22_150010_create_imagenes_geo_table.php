<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImagenesGeoTable extends Migration
{
    public function up()
    {
        Schema::create('imagenes_geo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->cascadeOnDelete();
            $table->date('fecha');
            $table->enum('tipo', ['NDVI', 'RGB', 'Termica', 'Multiespectral'])->default('RGB');
            $table->string('sensor')->nullable();
            $table->string('resolucion')->nullable();
            $table->string('bandas')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('subido_por')->constrained('users')->cascadeOnDelete();
            $table->text('notas')->nullable();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('imagenes_geo');
    }
}
