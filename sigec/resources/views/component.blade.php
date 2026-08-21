@extends('layouts.app')

@section('page_title', $title ?? '')

@section('app_content')
    <{{ $component }}
        @foreach(($params ?? []) as $param => $val)
            @php($attr = \Illuminate\Support\Str::kebab($param))
            @if(is_object($val) || is_array($val))
                :{{ $attr }}="{{ json_encode($val) }}"
            @else
                {{ $attr }}="{{ $val }}"
            @endif
        @endforeach
    />
@stop
