<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPredefinidoToCamposMuestra extends Migration
{
    /**
     * Ajustes por tipo de muestra de los datos base que llena el muestreador
     * (parcela, tratamiento, repeticion, observaciones): mostrar u ocultar,
     * obligatorio y etiqueta. Sin registro se usa el comportamiento por defecto.
     */
    public function up()
    {
        Schema::table('campos_muestra', function (Blueprint $table) {
            $table->boolean('predefinido')->default(false)->after('orden');
        });
    }

    public function down()
    {
        Schema::table('campos_muestra', function (Blueprint $table) {
            $table->dropColumn('predefinido');
        });
    }
}
