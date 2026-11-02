<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Respuesta extends Model
{
    protected $fillable = [
        'formulario_id', 'asignacion_id', 'ensayo_id', 'parcela_codigo',
        'fecha', 'responsable_id', 'valores', 'promovido',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valores' => 'array',
        'promovido' => 'boolean',
    ];

    public function formulario()
    {
        return $this->belongsTo(Formulario::class, 'formulario_id', 'id');
    }

    public function asignacion()
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'id');
    }

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }
}
