<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Programa;
use App\Services\RolesPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Usuarios de Experimentos. Los usuarios se crean y editan una sola vez en el portal
 * (login/usuarios/usuarios.php); aqui solo se asigna o cambia su rol en Experimentos, se da o
 * quita el acceso al modulo y se asignan programas (ver App\Services\RolesPortal).
 */
class UsuariosController extends Controller
{
    public function index(RolesPortal $portal)
    {
        Gate::authorize('admin_only');

        $ids = $portal->asegurarRoles();
        $roles = collect(config('sso.roles_portal'))->map(fn ($r, $nombre) => [
            'id' => $ids[$nombre], 'nombre' => $nombre, 'descripcion' => $r['descripcion'], 'color' => $r['color'], 'rol' => $r['rol'],
        ])->values();

        $usuarios = $portal->usuarios();

        return view('component', [
            'title' => 'Usuarios y roles',
            'component' => 'usuarios-index',
            'params' => [
                'conAcceso' => $usuarios['conAcceso'],
                'sinAccesoInicial' => $usuarios['sinAcceso'],
                'soloSigecInicial' => $usuarios['soloSigec'],
                'roles' => $roles,
                'programas' => Programa::orderBy('nombre')->get(['id', 'nombre']),
                'correoActual' => mb_strtolower(Auth::user()->email),
            ],
        ]);
    }

    /** Da acceso a Experimentos con un rol, o cambia el rol y los programas. */
    public function asignar(Request $request, RolesPortal $portal)
    {
        Gate::authorize('admin_only');

        $data = $request->validate([
            'portal_id' => 'required|integer',
            'rol_id' => 'required|integer',
            'programas' => 'nullable|array',
            'programas.*' => 'exists:programas,id',
        ]);

        $resultado = $portal->asignar(Auth::user(), $data['portal_id'], $data['rol_id'], $data['programas'] ?? []);

        return response()->json($resultado + $portal->usuarios());
    }

    /** Quita el acceso a Experimentos (no borra al usuario del portal). */
    public function quitar(int $portalId, RolesPortal $portal)
    {
        Gate::authorize('admin_only');

        $resultado = $portal->quitar(Auth::user(), $portalId);

        return response()->json($resultado + $portal->usuarios());
    }
}
