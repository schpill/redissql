@php
$error ??= null;
$is_auth = ($authuser ?? null) ? true : false;

$tables = $self->emptyCollection();

if ($is_auth) {
    $tables = $self->tables()->all()->sortBy('name');
}
@endphp
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <title>redisMyAdmin - @yield('title', 'Dashboard')</title>

    <link
      href="//cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.css" media="screen"
      rel="stylesheet"
      type="text/css"
    />

    <link
        href="//fonts.googleapis.com/css?family=Oswald:400,300,700|Questrial:400,300,700,900,600&amp;subset=latin,latin-ext"
        rel="stylesheet"
        type="text/css"
    />

    <link
        href="//netdna.bootstrapcdn.com/bootstrap/3.1.0/css/bootstrap.min.css"
        rel="stylesheet"
        type="text/css"
    />

    <link
        href="//cdnjs.cloudflare.com/ajax/libs/bootstrap3-wysiwyg/0.3.3/bootstrap3-wysihtml5.min.css"
        rel="stylesheet"
        type="text/css"
    />

    <style>
        {!! rsqlasset('css/app.css') !!}
    </style>

</head>

<body>

    <div class="container">
        @if (!$is_auth)
            <h1 class="text-center">
                <span onclick="document.location.href = '{!! go('home') !!}';" class="title">
                    <i class="fa fa-cogs fa-3x"></i>&nbsp;&nbsp;redisMyAdmin
                </span>
            </h1>
            @yield('content')
        @else
            <div class="row">
                <h5 class="text-left">
                    <span onclick="document.location.href = '{!! go('home') !!}';" class="title">
                        <i class="fa fa-cogs fa-3x"></i>&nbsp;&nbsp;redisMyAdmin
                    </span>
                </h5>
                @if ($error)
                    <div class="alert alert-danger mb-3 mt-3">{!! $error !!}</div>
                @endif
                <div class="text-right">
                    <a href="{!! go('home') !!}">
                        <i rel="tooltip-b" title="Dashboard" class="fa fa-home"></i>
                    </a> |
                    <a href="{!! go('add_table') !!}">
                        <i rel="tooltip-b" title="Add a new table" class="fa fa-plus"></i>
                    </a> |
                    <form id="app_logout" action="{!! go('logout') !!}" method="post" style="display: inline;">
                        @csrf
                        <a
                                href="javascript:void(0);"
                                onclick="document.getElementById('app_logout').submit(); return false;"
                        >
                            <i rel="tooltip-b" title="Logout" class="fa fa-power-off"></i>
                        </a>
                    </form>
                </div>
            </div>

            <div class="row">
                <div class="col-md-2">
                    <h2>Tables</h2>
                    @if ($tables->isNotEmpty())
                        <div id="tables">
                            <ul class="listTable">
                                @foreach($tables as $table)
                                    <li>
                                        <i
                                           rel="tooltip-t"
                                           title="Edit structure"
                                           onclick="document.location.href = '{!! go('table', ['id' => $table['id']])!!}';"
                                           class="linkTable link fa fa-cog">
                                        </i>

                                        <i rel="tooltip-t" title="Show data" onclick="document.location.href = '{!!
                                        go('display_data', ['id' => $table['id']]) !!}';" class="linkTable
                        link fa fa-list"></i>

                                        <a class="linkTable" href="{!! go('table', ['id' => $table['id']]) !!}">
                                            {!! $self->truncate($table['name']) !!}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="alert alert-warning">
                            <i class="fa fa-warning"></i>
                            No tables found
                        </div>
                    @endif
                </div>

                <div class="col-md-10">
                    @yield('content')
                </div>
            </div>
        @endif

        <div class="row copyright">
            &copy; redisMyAdmin 2008 - {{ date('Y')  }}
        </div>
    </div>

</body>

<script src="//ajax.googleapis.com/ajax/libs/jquery/3.7.0/jquery.min.js"></script>
<script src="//netdna.bootstrapcdn.com/bootstrap/3.1.0/js/bootstrap.min.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/bootstrap3-wysiwyg/0.3.3/bootstrap3-wysihtml5.all.min.js"></script>

<script>
    {!! rsqlasset('js/app.js') !!}
</script>

</html>
