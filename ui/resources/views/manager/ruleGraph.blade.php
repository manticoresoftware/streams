@extends('layouts.dashboard')

@section('content')

    <div>
        <canvas class="chart-container" data-id="{{$id}}"></canvas>
    </div>

    @include('manager.datepicker')

    <script>
        var chart = null;
        var timerId = null;
        var lastSection = null;
        const currentStream = {{$stream}};

        initDatePicker(function () {
                loadChartData(
                    $('.chart-container').attr('data-id'))
            }
        );

        $('#refreshPeriod').change(function () {
            var value = $(this).val();
            if (timerId != null) {
                clearInterval(timerId);
            }
            if(value != 0){
                timerId = setInterval(
                    function () {
                        loadChartData(lastSection)
                    }, value);
            }
        });

        function loadChartData(id) {

            var dateFrom = $("input#dateFrom").val();
            var dateEnd = $("input#dateTo").val();

            setCookie('dateStart', dateFrom);
            setCookie('dateEnd', dateEnd);


            lastSection = id;
            $.ajax({
                url: '/manager/getRuleStatData/' + id,
                type: 'POST',
                data: {
                    dateFrom: dateFrom,
                    dateTo: dateEnd,
                    streamId: currentStream,
                    _token: "{{csrf_token()}}"
                },

                success: function (data) {
                    if(chart != null){
                        chart.destroy();
                    }

                    var canvas = document.querySelector('.chart-container');
                    var ctx = canvas.getContext('2d');

                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    chart = new Chart(ctx, data);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    if (jqXHR.responseJSON.message != null) {
                        toast(jqXHR.responseJSON.message);
                    }
                }
            })
        }

    </script>
@stop
