@extends('rsql::layout')
@section('title', $title ?? 'Page')
@php
    $error ??= null;
@endphp
@section('content')
    <div class="rightContent">
        @isset($h1)
        <h1>{!! $h1 !!}</h1>
        @endisset
        {!! $content !!}
    </div>
@endsection
