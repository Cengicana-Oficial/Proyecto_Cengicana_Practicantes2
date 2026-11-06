<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de tipos de muestra (antes una lista fija en el codigo), administrable en
 * Sistema > Catalogos. Se alinea con el Laboratorio: "Tejido vegetal" pasa a "Foliares".
 * Activos: Agua, Suelo, Foliares y Jugos. Mieles y Fertilizantes quedan inactivos (sus
 * muestras existentes conservan tipo e ID; se pueden reactivar en el catalogo).
 */
class CreateTiposMuestraTable extends Migration
{
    public function up()
    {
        Schema::create('tipos_muestra', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->string('codigo', 3)->unique();          // tramo del ID: COS-26-01-E01-AG025
            $table->string('tipo_lab', 60)->nullable();     // nombre en el catalogo del Laboratorio (tipo_muestra.nombre)
            $table->string('formato_informe', 60)->nullable(); // clave en config('laboratorio.formatos')
            $table->string('icono', 40)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Renombre "Tejido vegetal" -> "Foliares" donde ya se usa.
        foreach (['analitos_por_tipo' => 'tipo_muestra', 'campos_muestra' => 'tipo_muestra', 'muestras_lab' => 'tipo'] as $tabla => $col) {
            if (Schema::hasTable($tabla)) {
                DB::table($tabla)->where($col, 'Tejido vegetal')->update([$col => 'Foliares']);
            }
        }

        $ahora = now();
        DB::table('tipos_muestra')->insert(collect([
            ['Agua', 'AG', 'agua', 'Agua', 'fas fa-tint', true],
            ['Suelo', 'SU', 'suelos', 'Suelo', 'fas fa-mountain', true],
            ['Foliares', 'FO', 'foliares', 'Foliar', 'fas fa-leaf', true],
            ['Jugos', 'JU', 'cañas', 'Caña', 'fas fa-glass-whiskey', true],
            ['Mieles', 'MI', 'mieles', null, 'fas fa-flask', false],
            ['Fertilizantes', 'FE', null, null, 'fas fa-seedling', false],
        ])->map(fn ($t, $i) => [
            'nombre' => $t[0], 'codigo' => $t[1], 'tipo_lab' => $t[2], 'formato_informe' => $t[3], 'icono' => $t[4],
            'activo' => $t[5], 'orden' => $i + 1, 'created_at' => $ahora, 'updated_at' => $ahora,
        ])->all());
    }

    public function down()
    {
        Schema::dropIfExists('tipos_muestra');
        foreach (['analitos_por_tipo' => 'tipo_muestra', 'campos_muestra' => 'tipo_muestra', 'muestras_lab' => 'tipo'] as $tabla => $col) {
            if (Schema::hasTable($tabla)) {
                DB::table($tabla)->where($col, 'Foliares')->update([$col => 'Tejido vegetal']);
            }
        }
    }
}
