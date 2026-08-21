<?php

namespace App\Exports;

use App\Models\Evaluacion;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EvaluacionesExport implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $ensayoIds;

    public function __construct(Collection $ensayoIds)
    {
        $this->ensayoIds = $ensayoIds;
    }

    public function collection()
    {
        return Evaluacion::with(['variable', 'parcela.ensayo', 'parcela.tratamiento', 'responsable'])
            ->whereHas('parcela', fn ($q) => $q->whereIn('ensayo_id', $this->ensayoIds))
            ->orderBy('fecha')
            ->get();
    }

    public function headings(): array
    {
        return ['Fecha', 'Ensayo', 'Parcela', 'Tratamiento', 'Categoria', 'Variable', 'Valor', 'Unidad', 'Responsable', 'Observaciones'];
    }

    public function map($evaluacion): array
    {
        return [
            optional($evaluacion->fecha)->format('Y-m-d'),
            optional($evaluacion->parcela->ensayo ?? null)->codigo,
            optional($evaluacion->parcela)->codigo,
            optional(optional($evaluacion->parcela)->tratamiento)->codigo,
            optional($evaluacion->variable)->categoria,
            optional($evaluacion->variable)->nombre,
            $evaluacion->valor,
            optional($evaluacion->variable)->unidad,
            optional($evaluacion->responsable)->name,
            $evaluacion->obs,
        ];
    }
}
