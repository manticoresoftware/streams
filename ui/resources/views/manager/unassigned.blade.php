@extends('layouts.dashboard')

@section('content')


    <script>
        $(document).ready(function () {
            toast('No streams available. Contact administrator.', true, false);
        })
    </script>
@stop
