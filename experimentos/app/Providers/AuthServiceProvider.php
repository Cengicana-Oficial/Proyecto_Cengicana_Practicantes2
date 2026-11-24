<?php

namespace App\Providers;

use App\Services\RolesPortal;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            return $user->hasRole('administrador') ?: null;
        });

        // Conexion con la API agromet del ICC (Clima): configurarla, probarla, sincronizar y ver
        // su estado. Administrador/Superadmin siempre (Gate::before); otro rol de Experimentos
        // solo si en el portal (Roles y permisos) tiene el permiso sigec.clima_api.gestionar.
        Gate::define('clima_api', function ($user) {
            return (bool) app(RolesPortal::class)->tienePermiso($user, 'sigec.clima_api.gestionar');
        });

        // Catalogo de programas (Sistema › Catalogos › Programas): crear, editar y eliminar.
        // Administrador/Superadmin siempre; otro rol con sigec.programas.gestionar en el portal.
        Gate::define('programas', function ($user) {
            return (bool) app(RolesPortal::class)->tienePermiso($user, 'sigec.programas.gestionar');
        });

        // Opciones del menu (y acceso a su pagina): "menu.<clave>" segun el permiso del portal
        // sigec.menu.<clave> del rol del usuario en Experimentos (config('sso.menu')). Si el
        // portal no responde se usan los permisos spatie de siempre para no dejar a nadie sin menu.
        foreach (config('sso.menu', []) as $clave => $opcion) {
            Gate::define("menu.{$clave}", function ($user) use ($clave, $opcion) {
                $tiene = app(RolesPortal::class)->tienePermiso($user, "sigec.menu.{$clave}");

                return $tiene ?? $user->hasAnyPermission($opcion['respaldo'] ?? []);
            });
        }
    }
}
