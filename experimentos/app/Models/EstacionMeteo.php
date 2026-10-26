<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstacionMeteo extends Model
{
    protected $table = 'estaciones_meteo';

    protected $fillable = ['codigo', 'nombre', 'red', 'lat', 'lng', 'altitud', 'ubicacion', 'activa'];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'activa' => 'boolean'];

    public function registros()
    {
        return $this->hasMany(ClimaDiario::class, 'estacion_id', 'id');
    }
}
