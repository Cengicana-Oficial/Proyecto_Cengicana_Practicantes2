<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnlazarFormulariosConVariables extends Migration
{
    /**
     * Formularios de campo conectados con Variables y Evaluaciones:
     * - formularios: descripcion y modo de promocion (revision | automatica).
     * - respuestas: parcela_id, observaciones y quien/cuando se promovio.
     * - evaluaciones: respuesta_id para saber que el dato vino de un formulario.
     * Los campos existentes reciben un id estable (los valores se guardan por id).
     */
    public function up()
    {
        Schema::table('formularios', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->after('nombre');
            $table->string('promocion', 20)->default('revision')->after('estado');
        });

        Schema::table('respuestas', function (Blueprint $table) {
            $table->foreignId('parcela_id')->nullable()->after('parcela_codigo')->constrained('parcelas')->nullOnDelete();
            $table->text('observaciones')->nullable()->after('valores');
            $table->timestamp('promovido_en')->nullable()->after('promovido');
            $table->foreignId('promovido_por')->nullable()->after('promovido_en')->constrained('users')->nullOnDelete();
            $table->index(['asignacion_id', 'fecha']);
        });

        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->foreignId('respuesta_id')->nullable()->after('responsable_id')->constrained('respuestas')->nullOnDelete();
        });

        foreach (DB::table('formularios')->get(['id', 'campos']) as $f) {
            $campos = json_decode($f->campos, true) ?: [];
            foreach ($campos as $i => &$c) {
                $c['id'] = $c['id'] ?? 'c' . ($i + 1);
                $c['variable_id'] = $c['variable_id'] ?? null;
            }
            DB::table('formularios')->where('id', $f->id)->update(['campos' => json_encode($campos)]);
        }
    }

    public function down()
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('respuesta_id');
        });
        Schema::table('respuestas', function (Blueprint $table) {
            $table->dropIndex(['asignacion_id', 'fecha']);
            $table->dropConstrainedForeignId('parcela_id');
            $table->dropConstrainedForeignId('promovido_por');
            $table->dropColumn(['observaciones', 'promovido_en']);
        });
        Schema::table('formularios', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'promocion']);
        });
    }
}
