<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstacionMeteo extends Model
{
    protected $table = 'estaciones_meteo';

    protected $fillable = ['codigo', 'codigo_api', 'nombre', 'red', 'lat', 'lng', 'altitud', 'estrato', 'zona', 'ubicacion', 'activa', 'sincronizada_en'];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'activa' => 'boolean', 'sincronizada_en' => 'datetime'];

    /** Identificador de la estacion en la API ICC. */
    public function getIdApiAttribute(): string
    {
        return $this->codigo_api ?: $this->codigo;
    }

    public function registros()
    {
        return $this->hasMany(ClimaDiario::class, 'estacion_id', 'id');
    }
}
