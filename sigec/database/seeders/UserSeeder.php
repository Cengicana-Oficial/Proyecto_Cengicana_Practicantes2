<?php

namespace Database\Seeders;

use App\Models\Ingenio;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Portado de la constante USUARIOS del prototipo SIGEC_v12.html (un
     * usuario demo por rol, mismo password para todos: "password").
     * Los "usuario" del mockup (login sin contraseña real) se convierten
     * aqui en email {usuario}@sigec.local.
     */
    protected array $usuarios = [
        ['usuario' => 'admin', 'nombre' => 'Administrador General', 'rol' => 'administrador', 'programas' => [], 'ingenio' => null],
        ['usuario' => 'director', 'nombre' => 'Dr. Ricardo Maldonado', 'rol' => 'director', 'programas' => [], 'ingenio' => null],
        ['usuario' => 'juan.perez', 'nombre' => 'Ing. Juan Pérez', 'rol' => 'encargado', 'programas' => [1], 'ingenio' => null],
        ['usuario' => 'maria.lopez', 'nombre' => 'Ing. María López', 'rol' => 'encargado', 'programas' => [2, 5], 'ingenio' => null],
        ['usuario' => 'investigador', 'nombre' => 'Carlos Mendoza', 'rol' => 'investigador', 'programas' => [1], 'ingenio' => null],
        ['usuario' => 'ing.magdalena', 'nombre' => 'Usuario Ingenio Magdalena', 'rol' => 'ingenio', 'programas' => [1], 'ingenio' => 'Ingenio Magdalena'],
        ['usuario' => 'experto', 'nombre' => 'Dra. Ana Lucía Pérez', 'rol' => 'experto', 'programas' => [1, 2, 4], 'ingenio' => null],
        ['usuario' => 'muestreador', 'nombre' => 'Luis García', 'rol' => 'muestreador', 'programas' => [], 'ingenio' => null],
        ['usuario' => 'muestreador2', 'nombre' => 'Pedro Ajú', 'rol' => 'muestreador', 'programas' => [], 'ingenio' => null],
    ];

    public function run()
    {
        foreach ($this->usuarios as $datos) {
            $ingenioId = $datos['ingenio']
                ? Ingenio::where('nombre', $datos['ingenio'])->value('id')
                : null;

            $user = User::updateOrCreate(
                ['email' => $datos['usuario'].'@sigec.local'],
                [
                    'name' => $datos['nombre'],
                    'password' => bcrypt('password'),
                    'ingenio_id' => $ingenioId,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$datos['rol']]);

            if (! empty($datos['programas'])) {
                $user->programas()->sync($datos['programas']);
            }
        }
    }
}
