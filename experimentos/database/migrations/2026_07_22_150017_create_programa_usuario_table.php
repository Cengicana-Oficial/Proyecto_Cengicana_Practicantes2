<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProgramaUsuarioTable extends Migration
{
    public function up()
    {
        Schema::create('programa_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['programa_id', 'usuario_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('programa_usuario');
    }
}
