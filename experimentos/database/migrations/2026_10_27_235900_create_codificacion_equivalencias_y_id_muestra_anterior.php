<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCodificacionEquivalenciasYIdMuestraAnterior extends Migration
{
    /**
     * AUD-2026-003/M-4. Va antes de la recodificacion (2026_10_28_090000) para que esta guarde
     * directamente el ID con que se imprimio cada etiqueta (muestras_lab.id_muestra_anterior) y cada
     * cambio de codigo (codificacion_equivalencias), sin depender del log.
     *
     * En entornos donde la recodificacion ya corrio, esta migracion queda pendiente y se aplica despues;
     * 2026_11_04_090000 reconstruye entonces lo posible desde el log "[codificacion]".
     * Idempotente: no falla si la columna o la tabla ya existen.
     */
    public function up()
    {
        if (! Schema::hasColumn('muestras_lab', 'id_muestra_anterior')) {
            Schema::table('muestras_lab', function (Blueprint $table) {
                $table->string('id_muestra_anterior')->nullable()->after('id_muestra');
                $table->index('id_muestra_anterior');
            });
        }

        if (! Schema::hasTable('codificacion_equivalencias')) {
            Schema::create('codificacion_equivalencias', function (Blueprint $table) {
                $table->id();
                $table->string('tipo', 20);
                $table->unsignedBigInteger('registro_id');
                $table->string('codigo_anterior');
                $table->string('codigo_nuevo');
                $table->string('origen', 20)->default('recodificacion');
                $table->timestamp('created_at')->nullable();
                $table->index(['tipo', 'codigo_anterior']);
                $table->index(['tipo', 'registro_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('codificacion_equivalencias');
        if (Schema::hasColumn('muestras_lab', 'id_muestra_anterior')) {
            Schema::table('muestras_lab', function (Blueprint $table) {
                $table->dropIndex(['id_muestra_anterior']);
                $table->dropColumn('id_muestra_anterior');
            });
        }
    }
}
