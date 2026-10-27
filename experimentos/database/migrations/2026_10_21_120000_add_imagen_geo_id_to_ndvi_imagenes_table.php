<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImagenGeoIdToNdviImagenesTable extends Migration
{
    /**
     * Imagen del modulo "Imagenes Geoespaciales" de la que se proceso el NDVI
     * (se consulta el TIFF ya cargado en lugar de subirlo de nuevo).
     */
    public function up()
    {
        Schema::table('ndvi_imagenes', function (Blueprint $table) {
            $table->foreignId('imagen_geo_id')->nullable()->after('ensayo_id')->constrained('imagenes_geo')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('ndvi_imagenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imagen_geo_id');
        });
    }
}
