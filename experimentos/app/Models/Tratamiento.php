<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tratamiento extends Model
{
    protected $fillable = ['ensayo_id', 'codigo', 'descripcion', 'unidades'];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function parcelas()
    {
        return $this->hasMany(Parcela::class, 'tratamiento_id', 'id');
    }
}
