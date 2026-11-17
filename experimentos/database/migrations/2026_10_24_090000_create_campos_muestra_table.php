<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateCamposMuestraTable extends Migration
{
    /**
     * Campos personalizados por tipo de muestra que llena el muestreador al
     * generar los ID (profundidad en suelos, pante en foliares, etc.). Los datos
     * base (fecha, finca, lote, parcela, tratamiento, repeticion, observaciones)
     * son columnas propias de la muestra. Los valores quedan en muestras_lab.datos.
     */
    public function up()
    {
        Schema::create('campos_muestra', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_muestra', 60);
            $table->string('clave', 40);
            $table->string('etiqueta', 100);
            $table->enum('tipo_dato', ['texto', 'numero', 'fecha', 'opciones'])->default('texto');
            $table->string('unidad', 30)->nullable();
            $table->json('opciones')->nullable();
            $table->string('ayuda', 200)->nullable();
            $table->boolean('obligatorio')->default(false);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(10);
            $table->timestamps();
            $table->unique(['tipo_muestra', 'clave']);
        });

        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->json('datos')->nullable()->after('obs');
        });

        $ahora = now();
        $base = ['opciones' => null, 'ayuda' => null, 'obligatorio' => false, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora];
        DB::table('campos_muestra')->insert([
            ['tipo_muestra' => 'Suelo', 'clave' => 'profundidad', 'etiqueta' => 'Profundidad', 'tipo_dato' => 'texto', 'unidad' => 'cm', 'orden' => 10, 'ayuda' => 'Ej. 0-20, 20-40'] + $base,
            ['tipo_muestra' => 'Tejido vegetal', 'clave' => 'pante', 'etiqueta' => 'Pante', 'tipo_dato' => 'texto', 'unidad' => null, 'orden' => 10] + $base,
            ['tipo_muestra' => 'Tejido vegetal', 'clave' => 'variedad', 'etiqueta' => 'Variedad', 'tipo_dato' => 'texto', 'unidad' => null, 'orden' => 20, 'activo' => false] + $base,
            ['tipo_muestra' => 'Agua', 'clave' => 'descripcion', 'etiqueta' => 'Descripcion', 'tipo_dato' => 'texto', 'unidad' => null, 'orden' => 10, 'ayuda' => 'Fuente o punto de toma'] + $base,
        ]);
    }

    public function down()
    {
        Schema::table('muestras_lab', function (Blueprint $table) {
            $table->dropColumn('datos');
        });
        Schema::dropIfExists('campos_muestra');
    }
}
