<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro fotografico por parcela y fecha de muestreo (p. ej. control de malezas por
 * tratamiento). Opcional en cada formulario de campo: formularios.fotos guarda
 * { activo, indicacion, maximo }.
 */
class CreateFotosParcelaTable extends Migration
{
    public function up()
    {
        Schema::create('fotos_parcela', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->foreignId('parcela_id')->constrained('parcelas')->cascadeOnDelete();
            $table->date('fecha');
            $table->foreignId('formulario_id')->nullable()->constrained('formularios')->nullOnDelete();
            $table->string('nombre')->nullable();
            $table->string('path');
            $table->string('miniatura')->nullable();
            $table->unsignedInteger('ancho')->nullable();
            $table->unsignedInteger('alto')->nullable();
            $table->string('nota', 500)->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['ensayo_id', 'fecha']);
        });

        Schema::table('formularios', function (Blueprint $table) {
            $table->json('fotos')->nullable()->after('campos');
        });
    }

    public function down()
    {
        Schema::table('formularios', function (Blueprint $table) {
            $table->dropColumn('fotos');
        });
        Schema::dropIfExists('fotos_parcela');
    }
}
