@extends('rsql::layout')
@section('title', 'Display structures')
@php
    $error ??= null;
@endphp
@section('content')
    <div class="rightContent">
        {!! $self->table() !!}
    </div>
@endsection
