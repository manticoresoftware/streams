<form method="POST" action="/">

    <div class="form-row">
        <div class="form-group col-md-6">
            <label class="control-label" for="daterange">Time</label>
            <div class="input-group mb-3">
                <div class="input-group-prepend">

                    <span class="input-group-text" id="basic-addon1"><i class="fas fa-calendar-alt"></i></span>
                </div>

                <input type='hidden' name='dateFrom' id='dateFrom' value="">
                <input type='hidden' name='dateTo' id='dateTo' value="">
                <div id="daterange" class="form-control">
                    &nbsp; <span> </span>
                </div>
            </div>
        </div>
        <div class="form-group col-md-6">
            <label class="control-label" for="refreshPeriod">Refresh each</label>

            <div class="input-group mb-3">

                <div class="input-group-prepend" id="refreshIcon">
                    <span class="input-group-text" id="basic-addon1"><i class="fas fa-sync-alt"></i></span>
                </div>
                <select id="refreshPeriod" name="refreshPeriod" class="form-control">
                    <option label="off" value="0" selected="selected">off</option>
                    <option label="5s" value="5000">5s</option>
                    <option label="10s" value="10000">10s</option>
                    <option label="30s" value="30000">30s</option>
                    <option label="1m" value="60000">1m</option>
                    <option label="5m" value="300000">5m</option>
                    <option label="15m" value="900000">15m</option>
                    <option label="30m" value="1800000">30m</option>
                    <option label="1h" value="3600000">1h</option>
                    <option label="2h" value="7200000">2h</option>
                    <option label="1d" value="86400000">1d</option>
                </select>
            </div>
        </div>
    </div>
</form>


<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js"></script>
<script src="https://momentjs.com/downloads/moment.js"></script>
<script src="https://momentjs.com/downloads/moment-timezone-with-data.js"></script>

<script type="text/javascript" src="/js/daterangepicker.js"></script>

<script>
    function getRanges() {
        return {
            'Last 2 days': [
                moment().subtract(2, 'days').toDate(),
                moment().toDate()
            ]
            ,
            'Last 7 Days': [
                moment().startOf('day').subtract(6, 'days').toDate(),
                moment().toDate()
            ],
            'Last 30 Days': [
                moment().startOf('day').subtract(29, 'days').toDate(),
                moment().toDate()
            ],
            'Last 90 days': [
                moment().subtract(90, 'days').toDate(),
                moment().toDate()
            ],
            'Last 6 months': [
                moment().subtract(6, 'months').toDate(),
                moment().toDate()
            ],
            'Last 1 year': [
                moment().subtract(1, 'year').toDate(),
                moment().toDate()
            ],
            'Last 2 years': [
                moment().subtract(2, 'years').toDate(),
                moment().toDate()
            ],
            'Last 5 years': [
                moment().subtract(5, 'years').toDate(),
                moment().toDate()
            ],
            'Yesterday': [
                moment().startOf('day').subtract(1, 'day').toDate(),
                moment().endOf('day').subtract(1, 'day').toDate()
            ],
            'Day before yesterday': [
                moment().subtract(2, 'day').endOf('day').toDate(),
                moment().subtract(1, 'day').startOf('day').toDate()
            ],
            'This day last week': [
                moment().subtract(6, 'day').startOf('day').toDate(),
                moment().subtract(7, 'day').endOf('day').toDate()
            ],
            'Previous week': [
                moment().subtract(1, 'month').startOf('day').toDate(),
                moment().subtract(1, 'month').endOf('day').toDate()
            ],
            'Previous month': [
                moment().startOf('month').subtract(1, 'month').toDate(),
                moment().endOf('month').subtract(1, 'month').toDate()
            ],
            'Previous year': [
                moment().startOf('year').subtract(1, 'year').toDate(),
                moment().endOf('year').subtract(1, 'year').toDate()
            ],
            'Today': [
                moment().startOf('day').toDate(),
                moment().toDate()
            ],
            'This week': [
                moment().startOf('week').toDate(),
                moment().toDate()
            ],
            'This month': [
                moment().startOf('month').toDate(),
                moment().toDate()
            ],
            'This year': [
                moment().startOf('year').toDate(),
                moment().toDate()
            ],
            'Last 5 min': [
                moment().subtract(5, 'minutes').toDate(),
                moment().toDate()
            ],
            'Last 15 min': [
                moment().subtract(15, 'minutes').toDate(),
                moment().toDate()
            ],
            'Last 30 min': [
                moment().subtract(30, 'minutes').toDate(),
                moment().toDate()
            ],
            'Last 1 hour': [
                moment().subtract(1, 'hour').toDate(),
                moment().toDate()
            ],
            'Last 3 hours': [
                moment().subtract(3, 'hours').toDate(),
                moment().toDate()
            ],
            'Last 6 hours': [
                moment().subtract(6, 'hours').toDate(),
                moment().toDate()
            ],
            'Last 12 hours': [
                moment().subtract(12, 'hours').toDate(),
                moment().toDate()
            ],
            'Last 24 hours': [
                moment().subtract(24, 'hours').toDate(),
                moment().toDate()
            ],
        }
    }

    function initDatePicker(callback) {
        var inputFrom = $("input#dateFrom");
        var inputTo = $("input#dateTo");

        moment.tz.setDefault("{{date_default_timezone_get()}}");

        var cookieStart = getCookie('dateStart');
        var cookieEnd = getCookie('dateEnd');
        var start, end;

        if (cookieStart !== undefined) {
            start = moment(cookieStart);
        } else {
            start = moment().startOf('day');
        }

        if (cookieEnd !== undefined) {
            end = moment(cookieEnd);
        } else {
            end = moment();
        }

        inputFrom.val(start.format('YYYY-MM-DD HH:mm:ss'));
        inputTo.val(end.format('YYYY-MM-DD HH:mm:ss'));

        $('#daterange span').html(moment(start).format('YYYY-MM-DD HH:mm:ss') + ' - ' + moment(end).format('YYYY-MM-DD HH:mm:ss'));

        $('#daterange')
            .daterangepicker({
                    startDate: start.format('YYYY-MM-DD HH:mm:ss'),
                    endDate: end.format('YYYY-MM-DD HH:mm:ss'),
                    timePicker: true,
                    timePicker24Hour: true,
                    timePickerIncrement: 15,
                    timePickerSeconds: false,
                    ranges: getRanges(),
                    locale: {
                        format: 'YYYY-MM-DD HH:mm:ss'
                    }
                },
                function (start, end) {
                    $("input#dateFrom").val(start.format('YYYY-MM-DD HH:mm:ss'));
                    $("input#dateTo").val(end.format('YYYY-MM-DD HH:mm:ss'));
                    $('#daterange span').html(start.format('YYYY-MM-DD HH:mm:ss') + ' - ' + end.format('YYYY-MM-DD HH:mm:ss'));
                })
            .on('apply.daterangepicker', function () {
                callback();
            })
        callback();
    }


    setInterval(
        function () {
            $('#daterange').data().daterangepicker.updateRanges(getRanges());
        }, 5000);

</script>
