<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Formulario extends Model
{
    protected $fillable = ['nombre', 'proyecto_id', 'ensayo_id', 'campos', 'creado_por', 'fecha', 'estado'];

    protected $casts = [
        'fecha' => 'date',
        'campos' => 'array',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id', 'id');
    }

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(User::class, 'creado_por', 'id');
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class, 'formulario_id', 'id');
    }
}
