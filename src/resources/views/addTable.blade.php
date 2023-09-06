@extends('rsql::layout')
@section('title', 'Add a new table')
@php
    $error ??= null;
@endphp
@section('content')
    <div class="rightContent">
        @if ($error)
            <div class="alert alert-danger mb-3 mt-3">{!! $error !!}</div>
        @endif
        <h3>@lang('Add a new table')</h3>

        <form action="{!! go('add_table') !!}" method="post">
            @csrf

            <div class="form-group">
                <input
                        autocomplete="off"
                        type="text"
                        name="name"
                        id="name"
                        class="form-control input-sm"
                        placeholder="@lang('Name of the table')"
                        required>
            </div>

            <div class="form-group">
                <button type="submit">@lang('Add')</button>
            </div>
        </form>
    </div>
@endsection
