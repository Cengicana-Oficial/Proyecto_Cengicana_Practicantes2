<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Programa extends Model
{
    protected $fillable = ['nombre', 'descripcion', 'lider', 'color'];

    public function proyectos()
    {
        return $this->hasMany(Proyecto::class, 'programa_id', 'id');
    }

    public function usuarios()
    {
        return $this->belongsToMany(User::class, 'programa_usuario', 'programa_id', 'usuario_id');
    }

    /**
     * Programas visibles para el usuario, portado de programasVisibles()
     * del prototipo SIGEC_v12.html: administrador/director ven todo,
     * el resto solo los programas que tiene asignados directamente.
     */
    public static function visiblesPara(User $user)
    {
        if ($user->hasRole(['administrador', 'director'])) {
            return static::query();
        }

        $ids = $user->programas()->pluck('programas.id');

        return static::whereIn('id', $ids);
    }
}
