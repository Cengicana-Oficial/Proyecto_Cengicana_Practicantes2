<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MuestraLab extends Model
{
    protected $table = 'muestras_lab';

    protected $fillable = [
        'id_muestra', 'fecha', 'tipo', 'proyecto_id', 'ensayo_id', 'finca', 'lote',
        'tratamiento_id', 'parcela_id', 'repeticion', 'estado', 'analitos',
        'resultado_texto', 'obs', 'datos', 'solicitante_id', 'analistas', 'fecha_resultado',
        'lab_muestra_id', 'lab_vinculo', 'lab_vinculado_por', 'lab_codigo', 'lab_estado', 'lab_informe', 'lab_informe_version', 'lab_firmado_por', 'lab_firmado_en', 'resultados_lab', 'lab_sincronizado_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_resultado' => 'date',
        'analitos' => 'array',
        'datos' => 'array',
        'resultados_lab' => 'array',
        'lab_firmado_en' => 'datetime',
        'lab_sincronizado_en' => 'datetime',
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
