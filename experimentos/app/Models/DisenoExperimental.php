<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Diseno experimental del catalogo (Sistema > Catalogos). ensayos.diseno guarda su nombre. */
class DisenoExperimental extends Model
{
    protected $table = 'disenos_experimentales';

    protected $fillable = ['nombre', 'descripcion', 'usa_bloques', 'activo', 'orden'];

    protected $casts = ['usa_bloques' => 'boolean', 'activo' => 'boolean', 'orden' => 'integer'];

    /** Nombres para el formulario de ensayos: los activos y los que ya usa algun ensayo. */
    public static function opciones(): array
    {
        $activos = static::where('activo', true)->orderBy('orden')->orderBy('nombre')->pluck('nombre');
        $usados = Ensayo::whereNotNull('diseno')->where('diseno', '<>', '')->distinct()->pluck('diseno');

        return $activos->merge($usados->diff($activos))->values()->all();
    }
}
