@extends('layouts.app')

@section('page_title', $title ?? '')

@section('app_content')
    <{{ $component }}
        @foreach(($params ?? []) as $param => $val)
            @php($attr = \Illuminate\Support\Str::kebab($param))
            @if(is_object($val) || is_array($val))
                :{{ $attr }}="{{ json_encode($val) }}"
            {{-- Booleanos como expresion: false impreso daria attr="" y Vue 2 lo toma como true. --}}
            @elseif(is_bool($val))
                :{{ $attr }}="{{ $val ? 'true' : 'false' }}"
            @else
                {{ $attr }}="{{ $val }}"
            @endif
        @endforeach
    />
@stop
