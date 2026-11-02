<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVariablesTable extends Migration
{
    public function up()
    {
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('unidad')->nullable();
            $table->enum('tipo', ['Numerica', 'Categorica', 'Texto'])->default('Numerica');
            $table->enum('categoria', ['Desarrollo', 'Cosecha'])->default('Desarrollo');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('variables');
    }
}
