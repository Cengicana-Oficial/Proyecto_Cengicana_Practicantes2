<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            RolePermissionSeeder::class,
            IngenioSeeder::class,
            ProgramaSeeder::class,
            UserSeeder::class,
            ProyectoSeeder::class,
            EnsayoSeeder::class,
            TratamientoSeeder::class,
            ParcelaSeeder::class,
            VariableSeeder::class,
            EvaluacionSeeder::class,
            AnaliticoPorTipoSeeder::class,
        ]);
    }
}
