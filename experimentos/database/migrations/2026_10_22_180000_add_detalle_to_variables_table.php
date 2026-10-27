<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDetalleToVariablesTable extends Migration
{
    /**
     * Ficha de la variable: descripcion / metodo de medicion, rango esperado
     * y decimales (numericas), opciones (categoricas) y estado activa.
     */
    public function up()
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->after('nombre');
            $table->decimal('valor_min', 12, 4)->nullable()->after('categoria');
            $table->decimal('valor_max', 12, 4)->nullable()->after('valor_min');
            $table->unsignedTinyInteger('decimales')->nullable()->after('valor_max');
            $table->json('opciones')->nullable()->after('decimales');
            $table->boolean('activa')->default(true)->after('opciones');
        });
    }

    public function down()
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'valor_min', 'valor_max', 'decimales', 'opciones', 'activa']);
        });
    }
}
