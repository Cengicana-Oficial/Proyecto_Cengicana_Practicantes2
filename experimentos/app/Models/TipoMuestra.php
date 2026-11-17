<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de muestra (Sistema > Catalogos): codigo para el ID de la etiqueta, equivalencia con
 * el catalogo del Laboratorio y formato de su informe. muestras_lab.tipo guarda el nombre.
 */
class TipoMuestra extends Model
{
    protected $table = 'tipos_muestra';

    protected $fillable = ['nombre', 'codigo', 'tipo_lab', 'formato_informe', 'icono', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer'];

    /** Todos los tipos por nombre (cache por peticion). */
    public static function porNombre()
    {
        static $cache = null;

        return $cache ??= static::orderBy('orden')->get()->keyBy('nombre');
    }

    public static function codigoDe(string $nombre): string
    {
        return optional(static::porNombre()->get($nombre))->codigo ?: 'OT';
    }

    /** Nombre del Laboratorio (sin tildes, minusculas) => tipo de Experimentos. */
    public static function desdeLaboratorio(): array
    {
        $norm = fn ($s) => strtr(mb_strtolower(trim((string) $s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return static::porNombre()->filter(fn ($t) => $t->tipo_lab)
            ->mapWithKeys(fn ($t) => [$norm($t->tipo_lab) => $t->nombre])->all();
    }
}
