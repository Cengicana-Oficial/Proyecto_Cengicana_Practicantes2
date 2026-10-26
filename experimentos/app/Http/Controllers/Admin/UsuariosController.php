<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingenio;
use App\Models\Programa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuariosController extends Controller
{
    /**
     * Roles de SIGEC portados del prototipo SIGEC_v12.html (constante
     * ROLES). Se mantienen fuera de la tabla roles de spatie los datos de
     * presentacion (label/icon/color) usados solo en la UI.
     */
    const ROLES_UI = [
        'administrador' => ['label' => 'Administrador', 'color' => '#73BC25'],
        'director' => ['label' => 'Director de Investigación', 'color' => '#1f6fbf'],
        'encargado' => ['label' => 'Encargado de Programa', 'color' => '#FF6B00'],
        'experto' => ['label' => 'Investigador Experto', 'color' => '#7B2FBE'],
        'investigador' => ['label' => 'Investigador', 'color' => '#A3D300'],
        'muestreador' => ['label' => 'Muestreador de campo', 'color' => '#00897B'],
        'ingenio' => ['label' => 'Usuario de Ingenio', 'color' => '#c4a000'],
    ];

    public function index()
    {
        Gate::authorize('admin_only');

        $usuarios = User::with(['roles', 'programas', 'ingenio'])->orderBy('name')->get();

        return view('component', [
            'title' => 'Usuarios y permisos',
            'component' => 'usuarios-index',
            'params' => [
                'usuarios' => $usuarios,
                'roles' => self::ROLES_UI,
                'programas' => Programa::orderBy('nombre')->get(['id', 'nombre']),
                'ingenios' => Ingenio::orderBy('nombre')->get(['id', 'nombre']),
                'usuarioActualId' => Auth::id(),
            ],
        ]);
    }

    protected function reglas(?User $usuario = null): array
    {
        return [
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($usuario ? $usuario->id : null)],
            'password' => $usuario ? 'nullable|string|min:8' : 'required|string|min:8',
            'rol' => ['required', Rule::in(array_keys(self::ROLES_UI))],
            'programas' => 'nullable|array',
            'programas.*' => 'exists:programas,id',
            'ingenio_id' => 'nullable|exists:ingenios,id',
        ];
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $request->validate($this->reglas());

        $usuario = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'ingenio_id' => $data['rol'] === 'ingenio' ? ($data['ingenio_id'] ?? null) : null,
            'email_verified_at' => now(),
        ]);

        $usuario->syncRoles([$data['rol']]);
        $usuario->programas()->sync($data['programas'] ?? []);

        return response()->json($usuario->load(['roles', 'programas', 'ingenio']), 201);
    }

    public function update(Request $request, User $usuario)
    {
        Gate::authorize('admin_only');

        $data = $request->validate($this->reglas($usuario));

        $usuario->name = $data['name'];
        $usuario->email = $data['email'];
        $usuario->ingenio_id = $data['rol'] === 'ingenio' ? ($data['ingenio_id'] ?? null) : null;

        if (! empty($data['password'])) {
            $usuario->password = Hash::make($data['password']);
        }

        $usuario->save();
        $usuario->syncRoles([$data['rol']]);
        $usuario->programas()->sync($data['programas'] ?? []);

        return response()->json($usuario->load(['roles', 'programas', 'ingenio']));
    }

    public function destroy(User $usuario)
    {
        Gate::authorize('admin_only');

        if ($usuario->id === Auth::id()) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario.'], 422);
        }

        $usuario->delete();

        return response()->json(['ok' => true]);
    }
}
