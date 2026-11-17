<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un ensayo puede tener varios responsables. ensayos.responsable_id se conserva como el
 * responsable principal (el primero de la lista), para ordenar, el dashboard y los informes.
 */
class CreateEnsayoResponsablesTable extends Migration
{
    public function up()
    {
        Schema::create('ensayo_responsables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensayo_id')->constrained('ensayos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0); // 0 = principal
            $table->timestamps();
            $table->unique(['ensayo_id', 'user_id']);
        });

        $ahora = now();
        $filas = DB::table('ensayos')->whereNotNull('responsable_id')->get(['id', 'responsable_id'])
            ->map(fn ($e) => ['ensayo_id' => $e->id, 'user_id' => $e->responsable_id, 'orden' => 0, 'created_at' => $ahora, 'updated_at' => $ahora])
            ->all();
        if ($filas) {
            DB::table('ensayo_responsables')->insert($filas);
        }
    }

    public function down()
    {
        Schema::dropIfExists('ensayo_responsables');
    }
}
