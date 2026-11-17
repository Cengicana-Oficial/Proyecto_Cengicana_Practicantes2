<?php

namespace App\Console\Commands;

use App\Services\RolesPortal;
use Illuminate\Console\Command;

/**
 * Crea en el portal (usuarios_menu) los roles de Experimentos, su ligadura al modulo y el permiso
 * de acceso. Idempotente. Antes lo hacia la pantalla Administracion > Usuarios en cada GET.
 */
class AsegurarRolesPortal extends Command
{
    protected $signature = 'experimentos:asegurar-roles';

    protected $description = 'Crea (si faltan) los roles de Experimentos en el portal y los liga al modulo con su permiso de acceso';

    public function handle(RolesPortal $portal)
    {
        if (! $portal->moduloId()) {
            $this->warn('No existe el modulo Experimentos en el portal: los roles se crean, pero sin ligarlos al modulo.');
        }
        foreach ($portal->asegurarRoles() as $nombre => $id) {
            $this->line("{$nombre} (id {$id})");
        }
        $this->info('Roles de Experimentos asegurados en el portal.');

        return 0;
    }
}
