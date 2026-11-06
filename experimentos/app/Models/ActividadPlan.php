<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Actividad programada del ensayo (ver la migracion create_actividades_plan_table). */
class ActividadPlan extends Model
{
    protected $table = 'actividades_plan';

    protected $fillable = [
        'ensayo_id', 'tipo', 'descripcion', 'fecha', 'responsable_id', 'aviso_dias',
        'estado', 'fecha_realizada', 'bitacora_id', 'creado_por',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'fecha_realizada' => 'date:Y-m-d',
        'aviso_dias' => 'integer',
    ];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }
}
