<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIngeniosTable extends Migration
{
    public function up()
    {
        Schema::create('ingenios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('ingenio_id')->references('id')->on('ingenios')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['ingenio_id']);
        });

        Schema::dropIfExists('ingenios');
    }
}
