<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Archivo extends Model
{
    protected $fillable = [
        'nombre', 'ensayo_id', 'proyecto_id', 'carpeta', 'tipo',
        'tamano', 'fecha', 'subido_por', 'path',
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
