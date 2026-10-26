<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MuestraLab extends Model
{
    protected $table = 'muestras_lab';

    protected $fillable = [
        'id_muestra', 'fecha', 'tipo', 'proyecto_id', 'ensayo_id', 'finca', 'lote',
        'tratamiento_id', 'parcela_id', 'repeticion', 'estado', 'analitos',
        'resultado_texto', 'obs', 'solicitante_id', 'analistas', 'fecha_resultado',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_resultado' => 'date',
        'analitos' => 'array',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id', 'id');
    }

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class, 'ensayo_id', 'id');
    }

    public function tratamiento()
    {
        return $this->belongsTo(Tratamiento::class, 'tratamiento_id', 'id');
    }

    public function parcela()
    {
        return $this->belongsTo(Parcela::class, 'parcela_id', 'id');
    }

    public function solicitante()
    {
        return $this->belongsTo(User::class, 'solicitante_id', 'id');
    }
}
