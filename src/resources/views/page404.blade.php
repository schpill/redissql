@extends('rsql::layout')
@section('title', 'Page 404')

@section('content')
{{--Page 404--}}
<section class="row text-center">
    <div class="col-md-12">
        <div class="error-template">
            <h1>Oops!</h1>
            <h2>404 Not Found</h2>
            <div class="error-details">
                Sorry, an error has occured, Requested page not found!
            </div>
            <div class="error-actions" style="margin-top: 20px;">
                <a href="{!! go('home') !!}" class="btn btn-primary btn-lg">
                    <span class="fa fa-home"></span>
                    Take Me Home
                </a>
            </div>
        </div>
    </div>
</section>
@endsection
