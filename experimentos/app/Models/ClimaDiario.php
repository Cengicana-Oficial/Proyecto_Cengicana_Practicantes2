<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClimaDiario extends Model
{
    protected $table = 'clima_diario';

    protected $fillable = ['estacion_id', 'fecha', 'lluvia', 'tmax', 'tmin', 'tmed', 'hr', 'viento', 'radiacion', 'eto'];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'lluvia' => 'float', 'tmax' => 'float', 'tmin' => 'float', 'tmed' => 'float',
        'hr' => 'float', 'viento' => 'float', 'radiacion' => 'float', 'eto' => 'float',
    ];

    public function estacion()
    {
        return $this->belongsTo(EstacionMeteo::class, 'estacion_id', 'id');
    }
}
