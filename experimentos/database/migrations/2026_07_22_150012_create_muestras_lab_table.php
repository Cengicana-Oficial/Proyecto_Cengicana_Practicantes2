<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMuestrasLabTable extends Migration
{
    public function up()
    {
        Schema::create('muestras_lab', function (Blueprint $table) {
            $table->id();
            $table->string('id_muestra')->unique();
            $table->date('fecha');
            $table->string('tipo');
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->string('finca')->nullable();
            $table->string('lote')->nullable();
            $table->foreignId('tratamiento_id')->nullable()->constrained('tratamientos')->nullOnDelete();
            $table->foreignId('parcela_id')->nullable()->constrained('parcelas')->nullOnDelete();
            $table->unsignedInteger('repeticion')->nullable();
            $table->enum('estado', ['Recibida', 'Pendiente', 'En proceso', 'Completado'])->default('Recibida');
            $table->json('analitos')->nullable();
            $table->text('resultado_texto')->nullable();
            $table->text('obs')->nullable();
            $table->foreignId('solicitante_id')->constrained('users')->cascadeOnDelete();
            $table->string('analistas')->nullable();
            $table->date('fecha_resultado')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('muestras_lab');
    }
}
