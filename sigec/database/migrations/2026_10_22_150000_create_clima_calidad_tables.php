<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateClimaCalidadTables extends Migration
{
    /**
     * Base de datos climatica (pestaña "Base de datos"):
     * - clima_rangos: rango valido (fuera = error, se excluye) y rango de
     *   alerta (fuera = atipico, se revisa) por variable, unicos para la red.
     * - clima_calidad: hallazgos del control de calidad con el valor
     *   original; excluir deja el valor en NULL en clima_diario (reversible).
     * - clima_sync_log: bitacora de sincronizaciones con la API del ICC.
     */
    public function up()
    {
        Schema::create('clima_rangos', function (Blueprint $table) {
            $table->id();
            $table->string('variable', 20)->unique();
            $table->decimal('valido_min', 8, 2)->nullable();
            $table->decimal('valido_max', 8, 2)->nullable();
            $table->decimal('alerta_min', 8, 2)->nullable();
            $table->decimal('alerta_max', 8, 2)->nullable();
            $table->boolean('estadistico')->default(true);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Valores iniciales para la zona cañera de la costa sur (editables en pantalla).
        $ahora = now();
        $rangos = [
            ['lluvia', 0, 500, null, 150, false],
            ['tmax', 10, 48, 20, 40, true],
            ['tmin', 0, 35, 10, 28, true],
            ['tmed', 5, 40, 16, 33, true],
            ['hr', 5, 100, 35, null, true],
            ['hr_min', 1, 100, 15, null, true],
            ['hr_max', 10, 100, 50, null, true],
            ['viento', 0, 150, null, 30, true],
            ['radiacion', 0, 40, 4, 32, true],
            ['eto', 0, 15, 0.5, 8, true],
        ];
        DB::table('clima_rangos')->insert(array_map(fn ($r) => [
            'variable' => $r[0], 'valido_min' => $r[1], 'valido_max' => $r[2], 'alerta_min' => $r[3], 'alerta_max' => $r[4],
            'estadistico' => $r[5], 'created_at' => $ahora, 'updated_at' => $ahora,
        ], $rangos));

        Schema::create('clima_calidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones_meteo')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('variable', 20);
            $table->decimal('valor', 10, 3);
            $table->string('nivel', 10);   // error | atipico
            $table->string('regla', 20);   // rango | alerta | estadistico
            $table->string('detalle', 150)->nullable();
            $table->string('estado', 12)->default('pendiente'); // pendiente | validado | excluido
            $table->boolean('automatico')->default(false);     // excluido por el sistema (no por una persona)
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->string('nota', 255)->nullable();
            $table->timestamps();

            $table->unique(['estacion_id', 'fecha', 'variable']);
            $table->index(['estado', 'nivel']);
            $table->index('fecha');
        });

        Schema::create('clima_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20); // actualizar | historial | redescarga | automatica | programada | consulta
            $table->date('desde');
            $table->date('hasta');
            $table->unsignedInteger('dias')->default(0);
            $table->unsignedInteger('registros')->default(0);
            $table->unsignedSmallInteger('estaciones_nuevas')->default(0);
            $table->unsignedInteger('excluidos')->default(0);
            $table->string('error', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('clima_sync_log');
        Schema::dropIfExists('clima_calidad');
        Schema::dropIfExists('clima_rangos');
    }
}
