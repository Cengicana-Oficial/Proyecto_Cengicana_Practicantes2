<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/** Ajustes clave-valor editables desde SIGEC; los secretos se cifran. */
class Configuracion extends Model
{
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor', 'cifrado', 'actualizado_por'];

    protected $casts = ['cifrado' => 'boolean'];

    /** Valores con el prefijo dado: ['icc.url' => ...] -> ['url' => ...]. */
    public static function grupo(string $prefijo): array
    {
        // Antes de migrar (o en pruebas sin tabla) no hay ajustes guardados.
        if (! Schema::hasTable('configuraciones')) {
            return [];
        }

        return static::where('clave', 'like', $prefijo.'.%')->get()->mapWithKeys(function ($c) use ($prefijo) {
            $v = $c->valor;
            if ($c->cifrado && $v !== null) {
                try {
                    $v = Crypt::decryptString($v);
                } catch (\Throwable $e) {
                    $v = null;
                }
            }

            return [substr($c->clave, strlen($prefijo) + 1) => $v];
        })->all();
    }

    public static function poner(string $clave, $valor, bool $cifrar = false): void
    {
        if ($valor === null || $valor === '') {
            static::where('clave', $clave)->delete();

            return;
        }
        static::updateOrCreate(['clave' => $clave], [
            'valor' => $cifrar ? Crypt::encryptString((string) $valor) : (string) $valor,
            'cifrado' => $cifrar,
            'actualizado_por' => Auth::id(),
        ]);
    }
}
