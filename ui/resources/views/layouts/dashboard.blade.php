<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ config('app.name') }}</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.2.1/dist/css/bootstrap.min.css"
          integrity="sha384-GJzZqFGwb1QTTN6wy59ffF1BuGJpLSa9DkKMp0DgiMDm4iYMj70gZWKYbI706tWS" crossorigin="anonymous">
    <script
        src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4="
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.14.6/dist/umd/popper.min.js"
            integrity="sha384-wHAiFfRlMFy6i5SRaxvfOCifBUQy1xHdJ/yoi7FRNXMRBu5WHdZYu1hA6ZOblgut"
            crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.2.1/dist/js/bootstrap.min.js"
            integrity="sha384-B0UglyR+jN6CkvvICOB2joaf5I4l3gm9GU6Hc1og6Ls7i6U/mkkaduKaBhlAXv9k"
            crossorigin="anonymous"></script>


    <script type="text/javascript" src="/js/typeahead.js"></script>
    <script type="text/javascript" src="/js/jquery.sparkline.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css"/>
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.8.1/css/all.css"
          integrity="sha384-50oBUHEmvpQ+1lW4y57PTFmhCaXp0ML5d60M1M7uH2+nqUivzIebhndOJK28anvf" crossorigin="anonymous">


    <script>

        function toast(message, danger = false, hide = true) {
            var toast_div = $('#j-toast');
            $('#j-toast-text').html(message);

            toast_div.removeClass('alert-danger');
            toast_div.removeClass('alert-info');

            if(danger){
                toast_div.addClass('alert-danger');
            }else{
                toast_div.addClass('alert-info');
            }

            toast_div.show('slow');

            if (hide) {
                setTimeout(function () {
                    toast_div.hide('slow');
                }, 3000)
            }
        }

        function getCookie(name) {
            let matches = document.cookie.match(new RegExp(
                "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
            ));
            return matches ? decodeURIComponent(matches[1]) : undefined;
        }

        function setCookie(name, value, options = {}) {

            options = {
                path: '/',
                ...options
            };

            if (options.expires && options.expires.toUTCString) {
                options.expires = options.expires.toUTCString();
            }

            let updatedCookie = encodeURIComponent(name) + "=" + encodeURIComponent(value);

            for (let optionKey in options) {
                updatedCookie += "; " + optionKey;
                let optionValue = options[optionKey];
                if (optionValue !== true) {
                    updatedCookie += "=" + optionValue;
                }
            }

            document.cookie = updatedCookie;
        }
    </script>
</head>
<body>

<nav class="navbar navbar-expand-sm bg-dark navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="http://manticoresearch.com" target="_blank">
            <img class="manticore-logo"
                 src="/img/logo-manticore-h-for-black.png" width="150" alt="manticore logo">
        </a>

        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#collapsibleNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="collapsibleNavbar">
            <ul class="navbar-nav ml-auto">

                @auth
                    @if(Auth::user()->hasRole(\App\Models\Role::ROLE_MANAGER))

                        @if(Auth::user()->streams()->get()->count())
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button"
                                   data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    Stream
                                </a>
                                <div class="dropdown-menu" aria-labelledby="navbarDropdown">

                                    @foreach(Auth::user()->streams()->get() as $k=>$stream)
                                        <a class="dropdown-item"
                                           href="{{ url('/manager/setStream/'.$stream->id) }}">
                                            @if(Auth::user()->process == $stream->id)
                                                <i class="fas fa-check"></i>
                                            @endif
                                            {{$stream->process->name}}
                                        </a>
                                    @endforeach
                                </div>
                            </li>
                        @endif

                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/home') }}">Rules</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/manager/graphs') }}">Graphs</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/manager/results') }}">Results</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/manager/variables') }}">Variables</a>
                        </li>
                    @else
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/admin/home') }}">Users</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/admin/source') }}">Sources</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/admin/destination') }}">Destinations</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/admin/process') }}">Process</a>
                        </li>
                        <li class="nav-item text-nowrap">
                            <a class="nav-link" href="{{ url('/admin/logs') }}">Logs</a>
                        </li>
                    @endif
                    <li class="nav-item text-nowrap">
                        <a class="nav-link" href="{{ url('/tokens') }}">Tokens</a>
                    </li>
                    <li class="nav-item text-nowrap">
                        <a class="nav-link" href="{{ url('/logout') }}">Logout</a>
                    </li>
                @else
                    <li class="nav-item text-nowrap">
                        <a class="nav-link" href="{{ route('login') }}">Login</a>
                    </li>
                @endauth

            </ul>
        </div>
    </div>
</nav>

<main role="main">


    <div class="album py-3 bg-light">
        <div class="container-fluid pl-5 pr-5 full-height">
            <div id="j-toast" class="alert alert-info hidden">
                <a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                <div id="j-toast-text">

                </div>
            </div>


            @yield('content')


        </div>

    </div>

</main>

<footer class="text-muted bg-dark">
    <div class="container">
        <p>&nbsp;</p>
    </div>
</footer>

<!-- Bootstrap core JavaScript
================================================== -->
<!-- Placed at the end of the document so the pages load faster -->

<style>
    .full-height {
        min-height: 86.8vh;
    }

    #rules-table_wrapper, #users-table_wrapper, #variables-table_wrapper {
        width: 100%;
    }

    footer {
        width: 100%;
        padding: 7px;
    }

    .manticore-logo {
        width: 150px;
    }

    .totop {
        color: white;
        font-weight: bold;
    }

    #j-toast {
        margin-top: 15px;
    }

    .hidden {
        display: none;
    }

    .btn {
        margin: 3px;
    }

    .daterangepicker .ranges {
        columns: 4;
    }

    .j-add-rules-form .btn {
        margin: 0;
    }

    #refreshIcon {
        cursor: pointer;
    }

    .chart-container {
        height: 400px !important;
    }

    .chart-caption {
        position: relative;
        right: 10px;
        top: 9px;
        font-family: monospace;
        font-weight: bold;
        font-size: smaller;
        color: coral;
        height: 0;
        line-height: initial;
        text-align: right;
    }
</style>
</body>
</html>
