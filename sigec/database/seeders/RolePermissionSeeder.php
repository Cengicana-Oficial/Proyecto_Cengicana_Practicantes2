<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Matriz de permisos portada de la funcion can() del prototipo SIGEC_v12.html,
     * mas los permisos "*_menu"/"menu_general" que replican la logica de
     * canSeeMenu()/NAV del prototipo (visibilidad de la barra lateral, que en el
     * mockup no siempre coincide 1:1 con los permisos de accion). El rol
     * "administrador" no aparece aqui: tiene bypass total via Gate::before
     * (ver App\Providers\AuthServiceProvider) y por eso ve todo el menu.
     */
    protected array $matrix = [
        'director' => [
            'view_reports', 'run_analysis', 'view_reports_menu', 'menu_general',
        ],
        'encargado' => [
            'create_proyecto', 'edit_proyecto', 'create_ensayo', 'edit_ensayo',
            'register_evaluacion', 'upload_files', 'run_analysis', 'write_bitacora',
            'manage_memberships', 'manage_formularios', 'create_muestra', 'view_lab',
            'view_reports_menu', 'menu_general',
        ],
        'experto' => [
            'register_evaluacion', 'upload_files', 'run_analysis', 'write_bitacora',
            'view_reports', 'create_muestra', 'view_lab',
            'view_reports_menu', 'menu_general',
        ],
        'investigador' => [
            'register_evaluacion', 'upload_files', 'write_bitacora', 'create_muestra',
            'view_lab', 'menu_general',
        ],
        'muestreador' => [
            'ver_formulario_asignado',
        ],
        'ingenio' => [
            'view_lab', 'menu_general',
        ],
    ];

    /**
     * Permisos que deben existir pero no se asignan a ningun rol (solo el
     * bypass de administrador via Gate::before los concede).
     */
    protected array $adminOnlyPermissions = [
        'admin_only',
    ];

    public function run()
    {
        $permisos = collect($this->matrix)->flatten()
            ->merge($this->adminOnlyPermissions)
            ->unique()->values();

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'administrador', 'guard_name' => 'web']);

        foreach ($this->matrix as $rol => $permisosRol) {
            $role = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
            $role->syncPermissions($permisosRol);
        }
    }
}
