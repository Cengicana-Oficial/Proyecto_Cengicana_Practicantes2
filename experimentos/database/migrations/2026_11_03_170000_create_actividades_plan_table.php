<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de actividades del ensayo (muestreos, evaluaciones, fertilizacion, cosecha...): lo que
 * SE VA a hacer, con fecha, responsable y dias de aviso para la campanita. Al marcarla como
 * realizada se registra en la bitacora (lo que SE HIZO) y queda enlazada con bitacora_id.
 */
class CreateActividadesPlanTable extends Migration
{
    public function up()
    {
        Schema::create('actividades_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->string('tipo', 60); // mismos tipos de la bitacora (BitacoraController::TIPOS)
            $table->string('descripcion', 500)->nullable();
            $table->date('fecha'); // fecha programada
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('aviso_dias')->default(3); // dias antes en que avisa la campanita
            $table->string('estado', 20)->default('Pendiente'); // Pendiente | Realizada | Cancelada
            $table->date('fecha_realizada')->nullable();
            $table->foreignId('bitacora_id')->nullable()->constrained('bitacora')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['estado', 'fecha']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('actividades_plan');
    }
}
