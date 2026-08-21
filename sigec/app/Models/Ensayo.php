<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ensayo extends Model
{
    protected $fillable = [
        'codigo', 'proyecto_id', 'ingenio_id', 'finca', 'lote', 'diseno',
        'cultivo', 'variedad', 'responsable_id', 'estado', 'lat', 'lng',
        'num_tratamientos', 'num_repeticiones', 'area_parcela',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id', 'id');
    }

    public function ingenio()
    {
        return $this->belongsTo(Ingenio::class, 'ingenio_id', 'id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }

    public function tratamientos()
    {
        return $this->hasMany(Tratamiento::class, 'ensayo_id', 'id');
    }

    public function parcelas()
    {
        return $this->hasMany(Parcela::class, 'ensayo_id', 'id');
    }

    public function bitacora()
    {
        return $this->hasMany(Bitacora::class, 'ensayo_id', 'id');
    }

    /**
     * Ensayos visibles para el usuario, portado de ensayosVisibles() del
     * prototipo: se acota primero por los proyectos visibles y, para el
     * rol "ingenio", ademas se filtra por su ingenio asignado.
     */
    public static function visiblesPara(User $user)
    {
        $proyectoIds = Proyecto::visiblesPara($user)->pluck('id');

        $query = static::whereIn('proyecto_id', $proyectoIds);

        if ($user->hasRole('ingenio') && $user->ingenio_id) {
            $query->where('ingenio_id', $user->ingenio_id);
        }

        return $query;
    }
}
