<?php

namespace App\Exports;

use App\Models\MuestraLab;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Muestras de los ensayos con su vinculo con el Laboratorio y sus resultados (opcional: rango de fechas de muestreo). */
class MuestrasLabExport extends ReporteExcel implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $ensayoIds;

    protected ?string $desde;

    protected ?string $hasta;

    public function __construct(Collection $ensayoIds, ?string $desde = null, ?string $hasta = null)
    {
        $this->ensayoIds = $ensayoIds;
        $this->desde = $desde;
        $this->hasta = $hasta;
    }

    public function collection()
    {
        return MuestraLab::with(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante'])
            ->whereIn('ensayo_id', $this->ensayoIds)
            ->when($this->desde, fn ($q) => $q->whereDate('fecha', '>=', $this->desde))
            ->when($this->hasta, fn ($q) => $q->whereDate('fecha', '<=', $this->hasta))
            ->orderBy('fecha')->orderBy('id_muestra')
            ->get();
    }

    public function headings(): array
    {
        return ['ID Muestra', 'Fecha muestreo', 'Tipo', 'Proyecto', 'Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'Estado',
            'Codigo Laboratorio', 'Informe', 'Estado en Laboratorio', 'Firmado por', 'Fecha resultado', 'Resultados', 'Solicitante', 'Observaciones'];
    }

    public function map($m): array
    {
        // "pH en agua: 6.65; Materia Organica: 1.16 %" (del informe), o los analitos capturados.
        $resultados = collect($m->resultados_lab ?: [])->map(fn ($r) => trim("{$r['nombre']}: {$r['valor']} " . ($r['unidad'] ?? '')))->implode('; ')
            ?: collect($m->analitos ?: [])->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ');

        return [
            $m->id_muestra,
            optional($m->fecha)->format('Y-m-d'),
            $m->tipo,
            optional($m->proyecto)->codigo,
            optional($m->ensayo)->codigo,
            optional($m->parcela)->codigo,
            optional($m->tratamiento)->codigo,
            $m->repeticion ?? optional($m->parcela)->repeticion,
            $m->estado,
            $m->lab_codigo,
            $m->lab_informe,
            $m->lab_estado,
            $m->lab_firmado_por,
            optional($m->fecha_resultado)->format('Y-m-d'),
            $resultados ?: $m->resultado_texto,
            optional($m->solicitante)->name,
            $m->obs,
        ];
    }
}
