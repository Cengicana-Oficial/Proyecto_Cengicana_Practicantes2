<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de disenos experimentales (antes una lista fija en EnsayosController::DISENOS).
 * ensayos.diseno sigue guardando el nombre; usa_bloques indica al analisis estadistico si la
 * repeticion se analiza como bloque (DBCA) o no (DCA).
 */
class CreateDisenosExperimentalesTable extends Migration
{
    public function up()
    {
        Schema::create('disenos_experimentales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->string('descripcion', 500)->nullable();
            $table->boolean('usa_bloques')->default(true);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        $ahora = now();
        $base = [
            ['Bloques al azar', 'Bloques completos al azar (DBCA): cada repeticion es un bloque con todos los tratamientos.', true],
            ['Completamente al azar', 'DCA: tratamientos asignados al azar sin bloques; para condiciones homogeneas.', false],
            ['Parcelas divididas', 'Un factor en la parcela grande y otro en la subparcela, dentro de bloques.', true],
            ['Cuadro latino', 'Control de dos fuentes de variacion (filas y columnas).', false],
            ['Factorial en bloques al azar', 'Combinacion de dos o mas factores, en bloques completos.', true],
            ['Franjas (parcelas en franjas)', 'Dos factores aplicados en franjas perpendiculares, dentro de bloques.', true],
            ['Latice / alfa-latice', 'Bloques incompletos para muchos tratamientos (p. ej. pruebas de variedades).', true],
        ];
        $existentes = DB::table('ensayos')->whereNotNull('diseno')->where('diseno', '<>', '')->distinct()->pluck('diseno');
        foreach ($existentes as $d) {
            if (! collect($base)->contains(fn ($b) => mb_strtolower($b[0]) === mb_strtolower($d))) {
                $base[] = [$d, null, (bool) preg_match('/bloque/i', $d)];
            }
        }
        DB::table('disenos_experimentales')->insert(collect($base)->values()->map(fn ($b, $i) => [
            'nombre' => $b[0], 'descripcion' => $b[1], 'usa_bloques' => $b[2], 'activo' => true, 'orden' => $i + 1,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ])->all());
    }

    public function down()
    {
        Schema::dropIfExists('disenos_experimentales');
    }
}
