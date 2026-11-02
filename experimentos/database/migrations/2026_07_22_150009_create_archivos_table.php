<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArchivosTable extends Migration
{
    public function up()
    {
        Schema::create('archivos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('ensayo_id')->nullable()->constrained('ensayos')->cascadeOnDelete();
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->cascadeOnDelete();
            $table->string('carpeta')->nullable();
            $table->enum('tipo', ['PDF', 'Imagen', 'Excel', 'Word', 'ZIP', 'Otro'])->default('Otro');
            $table->unsignedBigInteger('tamano')->nullable();
            $table->date('fecha');
            $table->foreignId('subido_por')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('archivos');
    }
}
