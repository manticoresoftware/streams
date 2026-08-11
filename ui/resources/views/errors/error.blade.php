@extends('layouts.dashboard')

@section('content')
    <script>
        let toast_div = $('#j-toast');
        $('#j-toast-text').html('Error message: {{ $exception->getMessage() }}');

        setTimeout(() => {
            toast_div.show();
        }, 1000)
    </script>
@stop
