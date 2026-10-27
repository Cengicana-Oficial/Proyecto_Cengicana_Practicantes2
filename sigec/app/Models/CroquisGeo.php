<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CroquisGeo extends Model
{
    protected $table = 'croquis_geo';

    protected $fillable = ['ensayo_id', 'nombre', 'path', 'mime', 'tamano', 'pagina', 'georef', 'subido_por'];

    protected $casts = ['georef' => 'array', 'pagina' => 'integer'];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }
}
