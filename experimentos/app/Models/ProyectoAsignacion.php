<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoAsignacion extends Model
{
    protected $table = 'proyecto_asignaciones';

    protected $fillable = ['proyecto_id', 'usuario_id', 'rol'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }
}
