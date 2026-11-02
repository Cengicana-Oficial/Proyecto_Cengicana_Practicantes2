@extends('layouts.app')

@section('page_title', $title)

@section('app_content')
    <div class="mb-3 no-print">
        <button class="btn btn-success" onclick="window.print()"><i class="fas fa-print"></i> Imprimir / Guardar como PDF</button>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ $ensayo->codigo }} — {{ $ensayo->variedad }}</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><b>Proyecto:</b> {{ $ensayo->proyecto->codigo }} — {{ $ensayo->proyecto->nombre }}</p>
                    <p><b>Programa:</b> {{ optional($ensayo->proyecto->programa)->nombre }}</p>
                    <p><b>Ingenio:</b> {{ optional($ensayo->ingenio)->nombre ?? '-' }}</p>
                    <p><b>Finca / Lote:</b> {{ $ensayo->finca }} / {{ $ensayo->lote }}</p>
                </div>
                <div class="col-md-6">
                    <p><b>Diseño:</b> {{ $ensayo->diseno }}</p>
                    <p><b>Cultivo:</b> {{ $ensayo->cultivo }}</p>
                    <p><b>Responsable:</b> {{ optional($ensayo->responsable)->name ?? '-' }}</p>
                    <p><b>Estado:</b> {{ $ensayo->estado }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="small-box bg-sigec">
                <div class="inner"><h3>{{ $ensayo->tratamientos->count() }}</h3><p>Tratamientos</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-sigec">
                <div class="inner"><h3>{{ $ensayo->parcelas->count() }}</h3><p>Parcelas</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-sigec">
                <div class="inner"><h3>{{ $totalEvaluaciones }}</h3><p>Evaluaciones registradas</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-sigec">
                <div class="inner"><h3>{{ $muestras->count() }}</h3><p>Muestras de laboratorio</p></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Muestras de laboratorio</h3></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>ID Muestra</th><th>Tipo</th><th>Fecha</th><th>Estado</th></tr></thead>
                <tbody>
                    @forelse($muestras as $m)
                        <tr>
                            <td>{{ $m->id_muestra }}</td>
                            <td>{{ $m->tipo }}</td>
                            <td>{{ $m->fecha->format('Y-m-d') }}</td>
                            <td>{{ $m->estado }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">Sin muestras registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Bitácora</h3></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th></tr></thead>
                <tbody>
                    @forelse($bitacora as $b)
                        <tr>
                            <td>{{ $b->fecha->format('Y-m-d') }}</td>
                            <td>{{ $b->tipo }}</td>
                            <td>{{ $b->descripcion }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">Sin entradas de bitácora.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@stop
