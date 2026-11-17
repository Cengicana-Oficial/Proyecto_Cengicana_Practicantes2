<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclos de cultivo de un ensayo (plantia, soca 1, soca 2...): el mismo ensayo, con sus
 * parcelas y tratamientos, se evalua durante varios ciclos en la misma ubicacion. Las
 * evaluaciones y muestras pertenecen al ciclo por fecha (desde su siembra o corte hasta el
 * inicio del siguiente). ensayos.ciclo / fecha_siembra / fecha_cosecha(_prevista) se conservan
 * como el ciclo ACTUAL (el ultimo), que es lo que usan las demas pantallas.
 */
class CreateEnsayoCiclosTable extends Migration
{
    public function up()
    {
        Schema::create('ensayo_ciclos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(1); // 1 = primer ciclo evaluado
            $table->string('ciclo', 30)->nullable();
            $table->date('fecha_inicio'); // siembra (plantia) o corte del ciclo anterior
            $table->date('fecha_cosecha_prevista')->nullable();
            $table->date('fecha_cosecha')->nullable();
            $table->text('obs')->nullable();
            $table->timestamps();
            $table->unique(['ensayo_id', 'orden']);
        });

        // El ciclo actual de cada ensayo pasa a ser su primer ciclo registrado.
        $ahora = now();
        $filas = DB::table('ensayos')->whereNotNull('fecha_siembra')
            ->get(['id', 'ciclo', 'fecha_siembra', 'fecha_cosecha_prevista', 'fecha_cosecha'])
            ->map(fn ($e) => [
                'ensayo_id' => $e->id, 'orden' => 1, 'ciclo' => $e->ciclo, 'fecha_inicio' => $e->fecha_siembra,
                'fecha_cosecha_prevista' => $e->fecha_cosecha_prevista, 'fecha_cosecha' => $e->fecha_cosecha,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ])->all();
        if ($filas) {
            DB::table('ensayo_ciclos')->insert($filas);
        }
    }

    public function down()
    {
        Schema::dropIfExists('ensayo_ciclos');
    }
}
