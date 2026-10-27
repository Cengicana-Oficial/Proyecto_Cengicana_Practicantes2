<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Respuesta extends Model
{
    protected $fillable = [
        'formulario_id', 'asignacion_id', 'ensayo_id', 'parcela_codigo', 'parcela_id',
        'fecha', 'responsable_id', 'valores', 'observaciones', 'promovido', 'promovido_en', 'promovido_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valores' => 'array',
        'promovido' => 'boolean',
        'promovido_en' => 'datetime',
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

    public function parcela()
    {
        return $this->belongsTo(Parcela::class, 'parcela_id', 'id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }

    public function promotor()
    {
        return $this->belongsTo(User::class, 'promovido_por', 'id');
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class, 'respuesta_id', 'id');
    }
}
