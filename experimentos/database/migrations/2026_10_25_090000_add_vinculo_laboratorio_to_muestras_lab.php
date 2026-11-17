<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVinculoLaboratorioToMuestrasLab extends Migration
{
    /**
     * Vinculo con el modulo Laboratorio del portal (solo lectura desde SIGEC):
     * la muestra se enlaza cuando el Laboratorio escanea la etiqueta de SIGEC y
     * recibe los resultados cuando el informe del lote esta firmado.
     */
    public function up()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->unsignedInteger('lab_muestra_id')->nullable()->after('datos');
            $table->string('lab_codigo', 60)->nullable()->after('lab_muestra_id');
            $table->string('lab_estado', 40)->nullable()->after('lab_codigo');
            $table->string('lab_informe', 40)->nullable()->after('lab_estado');
            $table->string('lab_firmado_por', 150)->nullable()->after('lab_informe');
            $table->dateTime('lab_firmado_en')->nullable()->after('lab_firmado_por');
            $table->json('resultados_lab')->nullable()->after('lab_firmado_en');
            $table->timestamp('lab_sincronizado_en')->nullable()->after('resultados_lab');
            $table->index('lab_muestra_id');
        });
    }

    public function down()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->dropIndex(['lab_muestra_id']);
            $table->dropColumn(['lab_muestra_id', 'lab_codigo', 'lab_estado', 'lab_informe', 'lab_firmado_por', 'lab_firmado_en', 'resultados_lab', 'lab_sincronizado_en']);
        });
    }
}
