<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNdviImagenesTable extends Migration
{
    /**
     * Imagenes NDVI georreferenciadas del ensayo (una por fecha de captura):
     * el GeoTIFF se procesa en el navegador y aqui se guarda la imagen
     * coloreada para el Mapa GPS, el raster reducido (para recalcular) y el
     * NDVI medio de cada parcela.
     */
    public function up()
    {
        Schema::create('ndvi_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('fuente', 60)->nullable();
            $table->string('tipo', 20); // geotiff | imagen
            $table->string('nombre');
            $table->string('imagen_path');
            $table->unsignedInteger('imagen_ancho');
            $table->unsignedInteger('imagen_alto');
            $table->string('raster_path')->nullable();
            // Esquinas {tl, tr, bl, br: {lat, lng}} de la imagen.
            $table->json('esquinas');
            // CRS, transformacion pixel -> CRS, resolucion, bandas usadas.
            $table->json('meta')->nullable();
            // {parcela_id: {media, min, max, n}}
            $table->json('valores')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ensayo_id', 'fecha']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ndvi_imagenes');
    }
}
