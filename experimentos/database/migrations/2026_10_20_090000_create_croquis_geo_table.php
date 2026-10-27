<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCroquisGeoTable extends Migration
{
    /**
     * Croquis georreferenciado del ensayo (GeoPDF tipo Avenza Maps, o PDF /
     * imagen georreferenciado a mano) y poligono de cada parcela, para ubicar
     * al usuario en campo con el GPS del dispositivo.
     *
     * georef = {metodo: geopdf|manual, puntos: [{x, y, lat, lng}]}, con x/y
     * normalizados (0..1) sobre la pagina renderizada, origen arriba-izquierda.
     */
    public function up()
    {
        Schema::create('croquis_geo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->unique()->constrained('ensayos')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedBigInteger('tamano')->nullable();
            $table->unsignedSmallInteger('pagina')->default(1);
            $table->json('georef')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('parcelas', function (Blueprint $table) {
            // Poligono GeoJSON (coordenadas [lng, lat]) de la parcela en campo.
            $table->json('geometria')->nullable()->after('notas');
        });
    }

    public function down()
    {
        Schema::table('parcelas', function (Blueprint $table) {
            $table->dropColumn('geometria');
        });
        Schema::dropIfExists('croquis_geo');
    }
}
