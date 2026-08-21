<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEvaluacionesTable extends Migration
{
    public function up()
    {
        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('variable_id')->constrained('variables')->cascadeOnDelete();
            $table->foreignId('parcela_id')->constrained('parcelas')->cascadeOnDelete();
            $table->string('valor');
            $table->text('obs')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('evaluaciones');
    }
}
