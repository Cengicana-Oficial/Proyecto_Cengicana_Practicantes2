<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un ciclo de cultivo (plantia, soca...) de un ensayo; ver la migracion create_ensayo_ciclos_table. */
class EnsayoCiclo extends Model
{
    protected $table = 'ensayo_ciclos';

    protected $fillable = ['ensayo_id', 'orden', 'ciclo', 'fecha_inicio', 'fecha_cosecha_prevista', 'fecha_cosecha', 'obs'];

    protected $casts = [
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_cosecha_prevista' => 'date:Y-m-d',
        'fecha_cosecha' => 'date:Y-m-d',
    ];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }
}
