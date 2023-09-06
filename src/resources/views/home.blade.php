@extends('rsql::layout')
@section('title', 'Dashboard')
@php
    $error ??= null;
@endphp
@section('content')
    @if ($error)
        <div class="alert alert-danger mb-3 mt-3">{!! $error !!}</div>
    @endif
    <a href="{!! go('add_table') !!}">
        <i class="fa fa-plus"></i> @lang('Add a new table')
    </a>
@endsection
