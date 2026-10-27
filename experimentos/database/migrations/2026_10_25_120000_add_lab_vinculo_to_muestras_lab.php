<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLabVinculoToMuestrasLab extends Migration
{
    /**
     * Como se enlazo la muestra con el modulo Laboratorio: 'escaneo' (el laboratorio
     * leyo la etiqueta QR de SIGEC) o 'manual' (un usuario de SIGEC confirmo la
     * correspondencia sugerida por ensayo, tipo, fecha y parcela).
     */
    public function up()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->string('lab_vinculo', 10)->nullable()->after('lab_muestra_id');
            $table->foreignId('lab_vinculado_por')->nullable()->after('lab_vinculo')->constrained('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lab_vinculado_por');
            $table->dropColumn('lab_vinculo');
        });
    }
}
