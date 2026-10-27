<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLabEnviosTable extends Migration
{
    /**
     * API de resultados del modulo Laboratorio (el Laboratorio decide que envia):
     * - lab_envios: bitacora de cada envio recibido (aceptado o rechazado).
     * - muestras_lab.lab_informe_version: version del informe aplicada, para que un
     *   envio repetido o una version anterior no sobrescriba una correccion.
     */
    public function up()
    {
        Schema::create('lab_envios', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->nullable();
            $table->string('informe', 60)->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->string('estado', 20)->nullable();
            $table->unsignedSmallInteger('respuesta_http');
            $table->unsignedInteger('muestras')->default(0);
            $table->unsignedInteger('aplicadas')->default(0);
            $table->json('rechazadas')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('recibido_en')->useCurrent();
            $table->index('recibido_en');
        });

        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->unsignedInteger('lab_informe_version')->nullable()->after('lab_informe');
        });
    }

    public function down()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->dropColumn('lab_informe_version');
        });
        Schema::dropIfExists('lab_envios');
    }
}
