@extends('layouts.dashboard')

@section('content')

    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="matching-docs-tab" data-toggle="tab"
               href="#matching-docs" role="tab" aria-controls="home" aria-selected="true">Matching docs</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="processed-docs-tab" data-toggle="tab"
               href="#processed-docs" role="tab" aria-controls="home" aria-selected="true">Processed docs</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="rules-tab" data-toggle="tab"
               href="#rules" role="tab" aria-controls="home" aria-selected="true">Rules</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="processing-lag-tab" data-toggle="tab"
               href="#processing-lag" role="tab" aria-controls="home" aria-selected="true">Process lag</a>
        </li>
    </ul>

    <div class="tab-content" id="myTabContent">
        <div class="tab-pane fade show active" id="matching-docs" role="tabpanel" aria-labelledby="matching-docs-tab">
            <div class="chart-caption"></div>
            <canvas class="chart-container"></canvas>
        </div>
        <div class="tab-pane fade" id="processed-docs" role="tabpanel" aria-labelledby="processed-docs-tab">
            <div class="chart-caption"></div>
            <canvas class="chart-container"></canvas>
        </div>
        <div class="tab-pane fade" id="rules" role="tabpanel" aria-labelledby="rules-tab">
            <div class="chart-caption"></div>
            <canvas class="chart-container"></canvas>
        </div>
        <div class="tab-pane fade" id="processing-lag" role="tabpanel" aria-labelledby="processing-lag-tab">
            <div class="chart-caption"></div>
            <canvas class="chart-container"></canvas>
        </div>
    </div>

    @include('manager.datepicker')
    <script>
        var chart = null;
        var timerId = null;
        var lastSection = null;
        const currentStream = {{$stream}};

        initDatePicker(function () {
                loadChartData(
                    $('.nav-tabs .nav-link.active').attr('id'))
            }
        );


        $('.nav-link').click(function () {
            loadChartData($(this).attr('id'));
        });

        $('#refreshPeriod').change(function () {
            var value = $(this).val();
            if (timerId != null) {
                clearInterval(timerId);
            }
            if (value != 0) {
                timerId = setInterval(
                    function () {
                        loadChartData(lastSection)
                    }, value);
            }
        });

        function loadChartData(section) {

            var dateFrom = $("input#dateFrom").val();
            var dateEnd = $("input#dateTo").val();

            setCookie('dateStart', dateFrom);
            setCookie('dateEnd', dateEnd);

            lastSection = section;
            $.ajax({
                url: '/manager/getGraph',
                type: 'POST',
                data: {
                    section: section,
                    dateFrom: dateFrom,
                    dateTo: dateEnd,
                    streamId: currentStream,
                    _token: "{{csrf_token()}}"
                },

                success: function (data) {
                    if (chart != null) {
                        chart.destroy();
                    }

                    var canvas = document.querySelector('.active .chart-container');
                    var ctx = canvas.getContext('2d');

                    ctx.clearRect(0, 0, canvas.width, canvas.height);


                    chart = new Chart(ctx, data);

                    if (data.append != null) {
                        var caption = document.querySelector('.active .chart-caption');
                        caption.innerHTML = "Average per sec: " + data.append.average + "<br>Sum (per range):" + data.append.sum;
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    if (jqXHR.responseJSON.message != null) {
                        toast(jqXHR.responseJSON.message, true, false);
                        $('.container-fluid').children().not('#j-toast').hide();
                    }
                }
            });
        }


        $('#refreshIcon').on('click', function (e) {
            loadChartData(
                $('.nav-tabs .nav-link.active').attr('id'));
        });
    </script>
@stop
