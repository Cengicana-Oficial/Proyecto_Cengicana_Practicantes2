<?php

namespace App\Services;

use App\Models\Ingenio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios y roles de Experimentos con el portal (base usuarios_menu) como unica fuente:
 *  - Los usuarios se crean y editan solo en el portal (login/usuarios/usuarios.php).
 *  - El rol de cada usuario en Experimentos es usuario_modulo.rol_id (rol por modulo, ver
 *    login/config/roles_modulo.php); desde SIGEC solo se asigna, cambia o quita ese acceso.
 *  - Los roles del modulo (config('sso.roles_portal')) se crean en el portal si faltan, ligados
 *    al modulo con rol_modulo y con el permiso sigec.acceder, para que aparezcan en
 *    login/usuarios y login/roles.
 * El usuario local de SIGEC (por correo) se mantiene al dia para poder asignarle programas y
 * ensayos aunque aun no haya entrado; al entrar por SSO recibe el mismo rol.
 */
class RolesPortal
{
    const CONEXION = 'portal';

    protected function portal()
    {
        return DB::connection(self::CONEXION);
    }

    public static function normalizar(?string $texto): string
    {
        $texto = mb_strtolower(trim((string) $texto), 'UTF-8');

        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /** Rol spatie de SIGEC que corresponde a un rol del portal (por nombre); null si no aplica. */
    public static function rolSigec(?string $nombreRol): ?string
    {
        $clave = self::normalizar($nombreRol);
        if (in_array($clave, ['superadmin', 'administrador'], true)) {
            return 'administrador';
        }
        foreach (config('sso.roles_portal') as $nombre => $rol) {
            if (self::normalizar($nombre) === $clave) {
                return $rol['rol'];
            }
        }

        return null;
    }

    public function moduloId(): int
    {
        return (int) $this->portal()->table('modulos')
            ->whereRaw('LOWER(nombre) IN (?, ?)', [mb_strtolower(config('sso.modulo_portal')), 'sigec'])
            ->value('id');
    }

    /**
     * Crea en el portal los roles de Experimentos que falten, los liga al modulo y les da el
     * permiso de acceso. Idempotente. Devuelve [nombre => id].
     */
    public function asegurarRoles(): array
    {
        $portal = $this->portal();
        $modulo = $this->moduloId();
        $permiso = $portal->table('permisos')->where('nombre_permiso', 'sigec.acceder')->value('id');
        $ids = [];
        foreach (array_keys(config('sso.roles_portal')) as $nombre) {
            $portal->statement('INSERT IGNORE INTO roles (nombre_rol) VALUES (?)', [$nombre]);
            $id = (int) $portal->table('roles')->where('nombre_rol', $nombre)->value('id');
            if ($modulo) {
                $portal->statement('INSERT IGNORE INTO rol_modulo (rol_id, modulo_id) VALUES (?, ?)', [$id, $modulo]);
            }
            if ($permiso) {
                $portal->statement('INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES (?, ?)', [$id, $permiso]);
            }
            $ids[$nombre] = $id;
        }

        return $ids;
    }

    /** Usuarios del portal con su rol en Experimentos, divididos en con y sin acceso. */
    public function usuarios(): array
    {
        $modulo = $this->moduloId();
        $filas = $this->portal()->table('usuarios as u')
            ->leftJoin('usuario_modulo as um', function ($j) use ($modulo) {
                $j->on('um.usuario_id', '=', 'u.id')->where('um.modulo_id', '=', $modulo);
            })
            ->leftJoin('roles as r', 'r.id', '=', 'um.rol_id')
            ->leftJoin('ingenios as i', 'i.id', '=', 'u.ingenio_id')
            ->orderBy('u.nombre')
            ->get(['u.id', 'u.nombre', 'u.correo', 'u.es_superadmin', 'u.ingenio_id', 'i.nombre_ingenio',
                'um.usuario_id as tiene_modulo', 'um.rol_id', 'r.nombre_rol']);

        $locales = User::with(['programas:id,nombre', 'roles:id,name'])->get()->keyBy(fn ($u) => mb_strtolower($u->email));
        $con = $sin = [];
        foreach ($filas as $f) {
            $correo = mb_strtolower(trim($f->correo));
            $local = $locales[$correo] ?? null;
            $super = (int) $f->es_superadmin === 1;
            $fila = [
                'portal_id' => (int) $f->id,
                'nombre' => $f->nombre,
                'correo' => $correo,
                'superadmin' => $super,
                'rol_id' => $super ? null : ($f->rol_id ? (int) $f->rol_id : null),
                'rol' => $super ? 'Superadmin' : ($f->nombre_rol ?: null),
                'rol_sigec' => $super ? 'administrador' : self::rolSigec($f->nombre_rol),
                'ingenio' => $f->nombre_ingenio,
                'ingenio_id' => $f->ingenio_id ? (int) $f->ingenio_id : null,
                'local_id' => $local ? $local->id : null,
                'programas' => $local ? $local->programas->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])->values() : [],
            ];
            if ($super || $f->tiene_modulo) {
                $con[] = $fila;
            } else {
                $sin[] = ['portal_id' => $fila['portal_id'], 'nombre' => $fila['nombre'], 'correo' => $correo, 'ingenio' => $fila['ingenio']];
            }
        }

        // Usuarios creados antes directamente en SIGEC que no existen en el portal: ya no pueden entrar.
        $correosPortal = $filas->map(fn ($f) => mb_strtolower(trim($f->correo)))->flip();
        $huerfanos = $locales->filter(fn ($u, $correo) => ! isset($correosPortal[$correo]))
            ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name, 'correo' => $u->email, 'rol' => optional($u->roles->first())->name])
            ->values();

        return ['conAcceso' => $con, 'sinAcceso' => $sin, 'soloSigec' => $huerfanos];
    }

    /**
     * Da acceso a Experimentos con un rol (o lo cambia) y asigna programas. Escribe el rol en el
     * portal y lo refleja en el usuario local. Reglas: nadie cambia su propio rol, los superadmin
     * no se modifican desde aqui y solo se asignan roles de Experimentos.
     */
    public function asignar(User $actor, int $portalId, int $rolId, array $programas): array
    {
        $usuario = $this->usuarioPortal($portalId, $actor);
        $roles = $this->asegurarRoles();
        $nombreRol = array_search($rolId, $roles, true);
        if ($nombreRol === false) {
            throw ValidationException::withMessages(['rol_id' => 'Seleccione un rol de Experimentos.']);
        }
        $modulo = $this->moduloId();
        if (! $modulo) {
            throw ValidationException::withMessages(['rol_id' => 'No existe el módulo Experimentos en el portal.']);
        }

        DB::transaction(function () use ($usuario, $modulo, $rolId, $nombreRol, $programas) {
            $this->portal()->statement(
                'INSERT INTO usuario_modulo (usuario_id, modulo_id, rol_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rol_id = VALUES(rol_id)',
                [$usuario->id, $modulo, $rolId]
            );
            $local = $this->usuarioLocal($usuario);
            $local->syncRoles([config('sso.roles_portal')[$nombreRol]['rol']]);
            $local->programas()->sync($programas);
        });

        return ['mensaje' => "{$usuario->nombre} tiene el rol {$nombreRol} en Experimentos."];
    }

    /** Quita el acceso a Experimentos (el usuario sigue existiendo en el portal). */
    public function quitar(User $actor, int $portalId): array
    {
        $usuario = $this->usuarioPortal($portalId, $actor);
        $this->portal()->table('usuario_modulo')->where('usuario_id', $usuario->id)->where('modulo_id', $this->moduloId())->delete();
        if ($local = User::where('email', mb_strtolower(trim($usuario->correo)))->first()) {
            $local->syncRoles([]);
        }

        return ['mensaje' => "{$usuario->nombre} ya no tiene acceso a Experimentos."];
    }

    protected function usuarioPortal(int $portalId, User $actor)
    {
        $usuario = $this->portal()->table('usuarios')->where('id', $portalId)->first(['id', 'nombre', 'correo', 'es_superadmin', 'ingenio_id']);
        if (! $usuario) {
            throw ValidationException::withMessages(['portal_id' => 'El usuario ya no existe en el portal.']);
        }
        if (mb_strtolower(trim($usuario->correo)) === mb_strtolower(trim($actor->email))) {
            throw ValidationException::withMessages(['portal_id' => 'No puede cambiar su propio rol; pídaselo a otro administrador.']);
        }
        if ((int) $usuario->es_superadmin === 1) {
            throw ValidationException::withMessages(['portal_id' => 'Los superadmin tienen acceso total; se administran en el portal.']);
        }

        return $usuario;
    }

    /** Usuario local de SIGEC (por correo); se crea si aun no ha entrado. */
    protected function usuarioLocal($usuario): User
    {
        $local = User::firstOrNew(['email' => mb_strtolower(trim($usuario->correo))]);
        if (! $local->exists) {
            // La contrasena local nunca se usa: el ingreso siempre es por el SSO del portal.
            $local->password = bcrypt(bin2hex(random_bytes(16)));
        }
        $local->name = $usuario->nombre;
        $local->ingenio_id = $usuario->ingenio_id && Ingenio::whereKey($usuario->ingenio_id)->exists() ? $usuario->ingenio_id : null;
        $local->save();

        return $local;
    }
}
