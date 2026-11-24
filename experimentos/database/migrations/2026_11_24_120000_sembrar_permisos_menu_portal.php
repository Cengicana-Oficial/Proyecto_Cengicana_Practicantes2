<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea en el portal (usuarios_menu.permisos) los permisos "sigec.menu.<clave>" de las opciones
 * del menu de Experimentos y "sigec.clima_api.gestionar", y da a cada rol de Experimentos los
 * del menu que ya veia (config('sso.menu')[clave]['roles']). Corre UNA sola vez: despues los
 * permisos se editan en login/roles/roles.php y un nuevo despliegue no los vuelve a poner.
 */
class SembrarPermisosMenuPortal extends Migration
{
    public function up()
    {
        $portal = DB::connection('portal');
        $permisos = ['sigec.clima_api.gestionar' => 'Conexion con la API del ICC en Clima (configurar, probar y sincronizar)'];
        foreach (array_keys(config('sso.menu')) as $clave) {
            $permisos["sigec.menu.{$clave}"] = "Opcion del menu de Experimentos: {$clave}";
        }
        foreach ($permisos as $nombre => $descripcion) {
            $portal->statement('INSERT IGNORE INTO permisos (nombre_permiso, descripcion) VALUES (?, ?)', [$nombre, $descripcion]);
        }

        // Rol spatie -> id del rol del portal (por nombre, config('sso.roles_portal')).
        $nombres = collect(config('sso.roles_portal'))->mapWithKeys(fn ($r, $nombre) => [$r['rol'] => $nombre]);
        $ids = $portal->table('roles')->whereIn('nombre_rol', $nombres->values())->pluck('id', 'nombre_rol');
        $permisoIds = $portal->table('permisos')->where('nombre_permiso', 'like', 'sigec.menu.%')->pluck('id', 'nombre_permiso');

        foreach (config('sso.menu') as $clave => $opcion) {
            $permisoId = $permisoIds["sigec.menu.{$clave}"] ?? null;
            foreach ($opcion['roles'] as $rolSpatie) {
                $rolId = $ids[$nombres[$rolSpatie] ?? ''] ?? null;
                if ($permisoId && $rolId) {
                    $portal->statement('INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES (?, ?)', [$rolId, $permisoId]);
                }
            }
        }
    }

    public function down()
    {
        // No se revierte: los permisos pudieron editarse ya desde Roles y permisos del portal.
    }
}
