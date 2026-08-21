<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEnsayosTable extends Migration
{
    public function up()
    {
        Schema::create('ensayos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('ingenio_id')->nullable()->constrained('ingenios')->nullOnDelete();
            $table->string('finca')->nullable();
            $table->string('lote')->nullable();
            $table->string('diseno')->nullable();
            $table->string('cultivo')->nullable();
            $table->string('variedad')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('estado', ['Planificado', 'En campo', 'Finalizado'])->default('Planificado');
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            $table->unsignedInteger('num_tratamientos')->default(0);
            $table->unsignedInteger('num_repeticiones')->default(0);
            $table->decimal('area_parcela', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ensayos');
    }
}
