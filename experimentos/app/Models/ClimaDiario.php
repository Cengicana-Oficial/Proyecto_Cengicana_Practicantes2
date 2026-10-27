<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClimaDiario extends Model
{
    protected $table = 'clima_diario';

    protected $fillable = ['estacion_id', 'fecha', 'lluvia', 'tmax', 'tmin', 'tmed', 'hr', 'hr_min', 'hr_max', 'viento', 'radiacion', 'eto'];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'lluvia' => 'float', 'tmax' => 'float', 'tmin' => 'float', 'tmed' => 'float',
        'hr' => 'float', 'hr_min' => 'float', 'hr_max' => 'float', 'viento' => 'float', 'radiacion' => 'float', 'eto' => 'float',
    ];

    public function estacion()
    {
        return $this->belongsTo(EstacionMeteo::class, 'estacion_id', 'id');
    }
}
