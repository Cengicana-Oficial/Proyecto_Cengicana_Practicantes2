<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImagenGeo extends Model
{
    protected $table = 'imagenes_geo';

    protected $fillable = [
        'nombre', 'ensayo_id', 'proyecto_id', 'fecha', 'tipo', 'sensor',
        'resolucion', 'bandas', 'width', 'height', 'subido_por', 'notas', 'path',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id', 'id');
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por', 'id');
    }
}
