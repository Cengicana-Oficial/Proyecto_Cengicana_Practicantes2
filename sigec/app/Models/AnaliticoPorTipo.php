<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnaliticoPorTipo extends Model
{
    protected $table = 'analitos_por_tipo';

    protected $fillable = ['tipo_muestra', 'clave', 'label', 'unidad'];
}
