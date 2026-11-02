<?php

namespace App\Exports;

use App\Models\MuestraLab;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MuestrasLabExport implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $ensayoIds;

    public function __construct(Collection $ensayoIds)
    {
        $this->ensayoIds = $ensayoIds;
    }

    public function collection()
    {
        return MuestraLab::with(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante'])
            ->whereIn('ensayo_id', $this->ensayoIds)
            ->orderBy('fecha')
            ->get();
    }

    public function headings(): array
    {
        return ['ID Muestra', 'Fecha', 'Tipo', 'Proyecto', 'Ensayo', 'Tratamiento', 'Parcela', 'Estado', 'Analitos', 'Resultado', 'Analistas', 'Fecha resultado', 'Solicitante'];
    }

    public function map($muestra): array
    {
        return [
            $muestra->id_muestra,
            optional($muestra->fecha)->format('Y-m-d'),
            $muestra->tipo,
            optional($muestra->proyecto)->codigo,
            optional($muestra->ensayo)->codigo,
            optional($muestra->tratamiento)->codigo,
            optional($muestra->parcela)->codigo,
            $muestra->estado,
            $muestra->analitos ? json_encode($muestra->analitos) : null,
            $muestra->resultado_texto,
            $muestra->analistas,
            optional($muestra->fecha_resultado)->format('Y-m-d'),
            optional($muestra->solicitante)->name,
        ];
    }
}
