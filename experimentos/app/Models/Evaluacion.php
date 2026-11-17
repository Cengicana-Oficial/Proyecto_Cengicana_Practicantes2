<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluacion extends Model
{
    protected $table = 'evaluaciones';

    protected $fillable = ['fecha', 'variable_id', 'parcela_id', 'valor', 'obs', 'responsable_id', 'respuesta_id'];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function variable()
    {
        return $this->belongsTo(Variable::class, 'variable_id', 'id');
    }

    public function parcela()
    {
        return $this->belongsTo(Parcela::class, 'parcela_id', 'id');
    }

    /** Respuesta de formulario de campo de la que se promovio (null si se registro directo). */
    public function respuesta()
    {
        return $this->belongsTo(Respuesta::class, 'respuesta_id', 'id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }
}
