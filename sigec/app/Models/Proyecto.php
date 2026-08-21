<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proyecto extends Model
{
    protected $fillable = [
        'codigo', 'nombre', 'programa_id', 'responsable_id',
        'objetivo', 'inicio', 'fin', 'estado',
    ];

    protected $casts = [
        'inicio' => 'date',
        'fin' => 'date',
    ];

    public function programa()
    {
        return $this->belongsTo(Programa::class, 'programa_id', 'id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }

    public function ensayos()
    {
        return $this->hasMany(Ensayo::class, 'proyecto_id', 'id');
    }

    public function asignaciones()
    {
        return $this->hasMany(ProyectoAsignacion::class, 'proyecto_id', 'id');
    }

    /**
     * Proyectos visibles para el usuario, portado de proyectosVisibles()
     * del prototipo: administrador/director ven todo; el resto ve los
     * proyectos de sus programas asignados o donde tiene una asignacion
     * directa (proyecto_asignaciones).
     */
    public static function visiblesPara(User $user)
    {
        if ($user->hasRole(['administrador', 'director'])) {
            return static::query();
        }

        $programaIds = Programa::visiblesPara($user)->pluck('id');

        return static::where(function ($query) use ($programaIds, $user) {
            $query->whereIn('programa_id', $programaIds)
                ->orWhereHas('asignaciones', fn ($q) => $q->where('usuario_id', $user->id));
        });
    }
}
