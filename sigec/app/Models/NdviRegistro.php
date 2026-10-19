<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NdviRegistro extends Model
{
    protected $table = 'ndvi_registros';

    protected $fillable = ['ensayo_id', 'tratamiento_id', 'periodo', 'valor', 'fuente', 'registrado_por'];

    protected $casts = ['valor' => 'float'];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function tratamiento()
    {
        return $this->belongsTo(Tratamiento::class, 'tratamiento_id', 'id');
    }
}
