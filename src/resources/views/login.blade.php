@extends('rsql::layout')
@section('title', 'Login')
@php
$error ??= null;
@endphp
@section('content')
    @if ($error)
        <div class="alert alert-danger mb-3 mt-3">{!! $error !!}</div>
    @endif
    <section id="auth" class="row text-center">

        <form action="{!! route('redis-sql-admin.crud', ['login']) !!}" method="post" class="col-md-4 col-md-offset-4">
            @csrf

            <div class="form-group">
                <label for="username">@lang('Username')</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="@lang('Username')" required>
            </div>

            <div class="form-group">
                <label for="password">@lang('Password')</label>
                <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control"
                        placeholder="@lang('Password')"
                       required
                >
            </div>

            <div class="form-group">
                <button type="submit">@lang('Login')</button>
            </div>
        </form>

    </section>

@endsection
