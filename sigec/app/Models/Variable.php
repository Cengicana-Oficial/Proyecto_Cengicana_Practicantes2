<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variable extends Model
{
    protected $table = 'variables';

    protected $fillable = ['nombre', 'unidad', 'tipo', 'categoria'];

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class, 'variable_id', 'id');
    }
}
