@extends('layouts.dashboard')

@section('content')
    <style>
        .results-window {
            border: 1px solid #c3c3c3;
            padding: 10px;
            border-radius: 5px;
            background-color: beige;
            overflow-y: scroll;
            max-height: 800px;
        }

        .results-message {
            border: 1px solid #c3c3c3;
            padding: 10px;
            margin: 10px;
            border-radius: 5px;
            font-size: 12px;
            background-color: whitesmoke;
            word-break: break-all;
        }

        .error-result {
            border: 1px solid #ff0000;
            background-color: #f5dcdc;
            font-weight: bold;
            overflow: auto;
        }
    </style>
    <div class="row">
        <div class="col results-window">
        </div>
    </div>
    <script>

        const currentStream = {{$stream}};

        function getKafkaResults() {
            $.ajax({
                url: '/manager/kafkaResults/',
                type: 'POST',
                data: {
                    host: "{{$host}}",
                    topic: "{{$topic}}",
                    group: "{{$group}}",
                    streamId: currentStream
                },

                success: function (data) {

                    if (data.length > 0) {

                        var resultsWindow = $('.results-window'), needToScroll = false;

                        if (resultsWindow[0].scrollTop + resultsWindow[0].clientHeight === resultsWindow[0].scrollHeight) {
                            needToScroll = true;
                        }
                        resultsWindow.append('<div class="results-message">' + htmlspecialchars(data) + '</div>')


                        if (resultsWindow.length && needToScroll)
                            resultsWindow.scrollTop(resultsWindow[0].scrollHeight - resultsWindow.height());

                        while (resultsWindow[0].innerHTML.length > 100000) {
                            resultsWindow[0].childNodes[0].remove()
                        }

                        setTimeout(getKafkaResults, 1000);
                    }

                },

                error: function (xhr, ajaxOptions, thrownError) {
                    let response = JSON.parse(xhr.responseText)
                    if (response.message != null) {
                        $('.results-window').text(response.message).addClass('error-result');
                    } else {
                        $('.results-window').text(xhr.responseText).addClass('error-result');
                    }

                }
            });
        }

        function htmlspecialchars(str) {
            if (typeof (str) == "string") {
                str = str.replace(/&/g, "&amp;"); /* must do &amp; first */
                str = str.replace(/"/g, '"');
                str = str.replace(/'/g, "&#039;");
                str = str.replace(/</g, "<");
                str = str.replace(/>/g, ">");
            }
            return str;
        }

        getKafkaResults();

    </script>
@stop
