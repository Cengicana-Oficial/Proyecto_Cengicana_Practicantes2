<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parcela extends Model
{
    protected $fillable = ['ensayo_id', 'tratamiento_id', 'codigo', 'repeticion', 'posicion', 'area', 'notas', 'geometria'];

    protected $casts = ['geometria' => 'array'];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function tratamiento()
    {
        return $this->belongsTo(Tratamiento::class, 'tratamiento_id', 'id');
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class, 'parcela_id', 'id');
    }
}
