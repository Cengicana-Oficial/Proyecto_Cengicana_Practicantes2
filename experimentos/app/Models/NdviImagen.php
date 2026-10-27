<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NdviImagen extends Model
{
    protected $table = 'ndvi_imagenes';

    protected $fillable = [
        'ensayo_id', 'imagen_geo_id', 'fecha', 'fuente', 'tipo', 'nombre', 'imagen_path', 'imagen_ancho', 'imagen_alto',
        'raster_path', 'esquinas', 'meta', 'valores', 'subido_por',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'esquinas' => 'array',
        'meta' => 'array',
        'valores' => 'array',
    ];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por', 'id');
    }
}
