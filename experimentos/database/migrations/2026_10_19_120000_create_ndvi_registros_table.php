<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNdviRegistrosTable extends Migration
{
    /**
     * Valores NDVI por tratamiento y periodo (pestana "NDVI" del detalle de
     * ensayo del prototipo SIGEC_v11: Sentinel-2, dron, Landsat, GEE).
     */
    public function up()
    {
        Schema::create('ndvi_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->foreignId('tratamiento_id')->constrained('tratamientos')->cascadeOnDelete();
            $table->string('periodo', 10);
            $table->decimal('valor', 4, 3);
            $table->string('fuente', 60)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tratamiento_id', 'periodo']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ndvi_registros');
    }
}
