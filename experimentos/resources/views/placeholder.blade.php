@extends('layouts.app')

@section('page_title', $title ?? 'Modulo')

@section('app_content')
    <div class="card card-outline card-success">
        <div class="card-body text-center py-5">
            <i class="fas fa-tools fa-3x text-muted mb-3"></i>
            <h4>{{ $title ?? 'Modulo' }}</h4>
            <p class="text-muted mb-0">Modulo en construccion.</p>
        </div>
    </div>
@stop
