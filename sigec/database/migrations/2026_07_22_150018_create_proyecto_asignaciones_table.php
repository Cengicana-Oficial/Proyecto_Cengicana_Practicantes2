<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProyectoAsignacionesTable extends Migration
{
    public function up()
    {
        Schema::create('proyecto_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('rol');
            $table->timestamps();

            $table->unique(['proyecto_id', 'usuario_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('proyecto_asignaciones');
    }
}
