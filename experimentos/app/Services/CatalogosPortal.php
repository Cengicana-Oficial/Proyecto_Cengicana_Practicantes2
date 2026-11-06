<?php

namespace App\Services;

use App\Models\Ingenio;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Catalogos que vienen del portal (base usuarios_menu), sin duplicarlos a mano:
 *  - Ingenios: se administran en el portal (Administracion > Ingenios). Se copian con el
 *    MISMO id, asi el ingenio del usuario que llega por SSO tambien coincide.
 *  - Responsables: personas con el modulo Experimentos asignado en el portal (o superadmin).
 *    Se crea su usuario local aunque aun no hayan entrado, para poder asignarles ensayos;
 *    al entrar por SSO se usa ese mismo usuario (por correo) y recibe su rol.
 * Solo lectura del portal; nunca escribe en usuarios_menu.
 */
class CatalogosPortal
{
    const CONEXION = 'portal';

    const CACHE = 'experimentos.catalogos_portal';

    /** Sincroniza si la ultima vez fue hace mas de $minutos (para abrir paginas sin esperar). */
    public function sincronizarSiCorresponde(int $minutos = 10): ?array
    {
        $u = Cache::get(self::CACHE);
        if ($u && isset($u['en']) && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($u['en'])) < $minutos) {
            return $u;
        }

        return $this->sincronizar();
    }

    public function sincronizar(): array
    {
        $r = ['ok' => true, 'en' => now()->toDateTimeString(), 'ingenios' => 0, 'responsables' => 0, 'nuevos' => 0, 'error' => null];
        try {
            $portal = DB::connection(self::CONEXION);

            foreach ($portal->table('ingenios')->get(['id', 'nombre_ingenio', 'estado']) as $i) {
                $local = Ingenio::find($i->id) ?? new Ingenio();
                $local->id = $i->id;
                $local->nombre = trim($i->nombre_ingenio);
                $local->save();
                $r['ingenios']++;
            }

            $modulo = $portal->table('modulos')->whereRaw("LOWER(nombre) IN ('experimentos', 'sigec')")->value('id');
            $personas = $portal->table('usuarios as u')
                ->where(function ($q) use ($modulo) {
                    $q->where('u.es_superadmin', 1);
                    if ($modulo) {
                        $q->orWhereExists(fn ($s) => $s->from('usuario_modulo as um')->whereColumn('um.usuario_id', 'u.id')->where('um.modulo_id', $modulo));
                    }
                })
                ->get(['u.nombre', 'u.correo', 'u.ingenio_id']);

            foreach ($personas as $p) {
                $correo = mb_strtolower(trim($p->correo));
                if ($correo === '') {
                    continue;
                }
                $user = User::firstOrNew(['email' => $correo]);
                if (! $user->exists) {
                    // La contrasena local nunca se usa: el ingreso siempre es por el SSO del portal.
                    $user->password = bcrypt(bin2hex(random_bytes(16)));
                    $r['nuevos']++;
                }
                $user->name = trim($p->nombre) ?: $user->name;
                $user->ingenio_id = $p->ingenio_id && Ingenio::whereKey($p->ingenio_id)->exists() ? $p->ingenio_id : null;
                $user->save();
                $r['responsables']++;
            }
        } catch (Throwable $e) {
            report($e);
            $r['ok'] = false;
            $r['error'] = 'No se pudo leer el catalogo del portal: ' . $e->getMessage();
        }

        try {
            Cache::forever(self::CACHE, $r);
        } catch (Throwable $e) {
            report($e);
        }

        return $r;
    }

    public static function ultima(): ?array
    {
        try {
            return Cache::get(self::CACHE);
        } catch (Throwable $e) {
            return null;
        }
    }
}
