<?php

namespace App\Exports;

use App\Models\Ensayo;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Cartera de ensayos (gestion): una fila por ensayo con su programa, proyecto, responsables,
 * diseno, ciclo, avance de evaluaciones y muestras y ultima actividad.
 */
class EnsayosExport extends ReporteExcel implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $ensayoIds;

    public function __construct(Collection $ensayoIds)
    {
        $this->ensayoIds = $ensayoIds;
    }

    public function collection()
    {
        return Ensayo::with(['proyecto.programa', 'ingenio', 'responsables:users.id,users.name'])
            ->withCount(['parcelas', 'tratamientos', 'evaluaciones', 'muestras',
                'muestras as muestras_completadas_count' => fn ($q) => $q->where('estado', 'Completado'), 'bitacora'])
            ->withMax('evaluaciones as ultima_evaluacion', 'evaluaciones.fecha')
            ->withMax('muestras as ultimo_muestreo', 'fecha')
            ->withMax('bitacora as ultima_bitacora', 'fecha')
            ->whereIn('id', $this->ensayoIds)
            ->orderBy('codigo')
            ->get();
    }

    public function headings(): array
    {
        return ['Programa', 'Proyecto', 'Ensayo', 'Nombre', 'Estado', 'Responsables', 'Ingenio', 'Finca', 'Lote', 'Variedad', 'Diseno',
            'Tratamientos', 'Repeticiones', 'Parcelas', 'Ciclo', 'Siembra / corte', 'Edad (meses)', 'Cosecha prevista',
            'Valores evaluados', 'Muestras', 'Muestras con resultado', 'Anotaciones de bitacora', 'Ultima actividad'];
    }

    public function map($e): array
    {
        $proyecto = $e->proyecto;
        $ultima = collect([$e->ultima_evaluacion, $e->ultimo_muestreo, $e->ultima_bitacora])->filter()->map(fn ($f) => substr($f, 0, 10))->max();

        return [
            optional(optional($proyecto)->programa)->sigla,
            optional($proyecto)->codigo,
            $e->codigo,
            $e->nombre,
            $e->estado,
            $e->responsables->pluck('name')->implode(', '),
            optional($e->ingenio)->nombre,
            $e->finca,
            $e->lote,
            $e->variedad,
            $e->diseno,
            $e->tratamientos_count,
            $e->num_repeticiones,
            $e->parcelas_count,
            $e->ciclo,
            optional($e->fecha_siembra)->format('Y-m-d'),
            $e->fecha_siembra ? round($e->fecha_siembra->diffInDays(now()) / 30.44, 1) : null,
            optional($e->fecha_cosecha_prevista)->format('Y-m-d'),
            $e->evaluaciones_count,
            $e->muestras_count,
            $e->muestras_completadas_count,
            $e->bitacora_count,
            $ultima,
        ];
    }
}
