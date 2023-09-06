@extends('rsql::layout')
@section('title', 'Remove table')
@php
    $error ??= null;
@endphp
@section('content')
    <div class="rightContent">
        <h1>@lang('Remove table') &laquo; <span class="yellowText">{{ $table->getName() }}</span> &raquo;</h1>
        {!! $content !!}
    </div>
@endsection
