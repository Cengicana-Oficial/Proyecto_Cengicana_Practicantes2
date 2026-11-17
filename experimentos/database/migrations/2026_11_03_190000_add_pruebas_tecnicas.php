<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pruebas tecnicas: estudios sin diseno experimental ni cosecha (calibracion de sensores,
 * pruebas de laboratorio, de productos o materiales...). Se guardan en ensayos con
 * tipo_estudio = 'prueba' (codigo PROYECTO-Pnn) para reutilizar plan, muestras, archivos,
 * fotos y bitacora. Sus datos van en una tabla libre por prueba (prueba_datos): columnas
 * definidas por el usuario y filas importables desde Excel.
 */
class AddPruebasTecnicas extends Migration
{
    public function up()
    {
        Schema::table('ensayos', function (Blueprint $table) {
            $table->string('tipo_estudio', 20)->default('ensayo')->after('codigo'); // ensayo | prueba
            $table->string('subtipo', 60)->nullable()->after('tipo_estudio');
            $table->date('fecha_inicio')->nullable()->after('fecha_cosecha');
            $table->date('fecha_fin')->nullable()->after('fecha_inicio');
            $table->string('lugar', 30)->nullable()->after('fecha_fin'); // Laboratorio | Campo | Invernadero...
            $table->text('materiales')->nullable()->after('lugar'); // equipos, sensores, productos, lotes
            $table->index('tipo_estudio');
        });

        Schema::create('prueba_datos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->unique()->constrained('ensayos')->cascadeOnDelete();
            $table->json('columnas'); // [{clave, nombre, unidad, tipo: numero|texto|fecha}]
            $table->json('filas');    // [{clave: valor, ...}]
            $table->json('grafico')->nullable(); // {x, y}: columnas de la grafica de calibracion
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('prueba_datos');
        Schema::table('ensayos', function (Blueprint $table) {
            $table->dropIndex(['tipo_estudio']);
            $table->dropColumn(['tipo_estudio', 'subtipo', 'fecha_inicio', 'fecha_fin', 'lugar', 'materiales']);
        });
    }
}
