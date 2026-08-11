@extends('layouts.dashboard')

@section('content')
    <style>
        table td:nth-child(2) {
            word-break: break-all;
        }

        td.details-control {
            background: url('/img/details_open.png') no-repeat center center;
            cursor: pointer;
        }

        tr.details td.details-control {
            background: url('/img/details_close.png') no-repeat center center;
        }

        .selection {
            background-color: #c5ffbe;
            margin: 10px;
            padding: 0 5px;
        }
    </style>


    <div class="row">
        <div class="col">
            <table id="logs-table" class="display" style="width:100%">
                <thead>
                <tr>
                    <th scope="col"></th>
                    <th scope="col">ID</th>
                    <th scope="col">Type</th>
                    <th scope="col">Description</th>
                    <th scope="col">Causer</th>
                    <th scope="col">Date</th>
                </tr>
                </thead>
            </table>
        </div>

    </div>

    <script>
        var table = null;
        $(document)
            .ready(function () {

                table = $('#logs-table').DataTable({
                    "pageLength": 20,
                    "lengthChange": false,
                    "processing": true,
                    "serverSide": true,
                    "ajax": "/admin/logs/view",
                    "order": [[1, "desc"]],
                    "language": {
                        "emptyTable": "No logs yet"
                    },
                    columns: [
                        {
                            class: 'details-control',
                            orderable: false,
                            data: null,
                            defaultContent: '',
                        },
                        {data: 'id', searchable: false},
                        {data: 'subject_type', searchable: true},
                        {data: 'description', searchable: false},
                        {data: 'causer_id', searchable: true},
                        {data: 'created_at', searchable: false},
                    ],
                    "fnDrawCallback": function (oSettings) {
                        if (oSettings._iDisplayLength > oSettings.fnRecordsDisplay()) {
                            $(oSettings.nTableWrapper).find('.dataTables_paginate').hide();
                        } else {
                            $(oSettings.nTableWrapper).find('.dataTables_paginate').show();
                        }
                    },
                    initComplete: function () {
                        var filters = $('#logs-table_filter');
                        filters.empty();
                        filters.text('Filters')
                        this.api().columns().every(function () {
                            var column = this;
                            var columns = this.settings().init().columns;


                            if (columns[column[0][0]].searchable === true) {
                                var input = document.createElement("input");


                                if (columns[column[0][0]].data === 'subject_type') {
                                    input.placeholder = 'Type';
                                } else {
                                    input.placeholder = 'Causer email';
                                }

                                $(input).appendTo(filters)
                                    .on('change', function () {
                                        column.search($(this).val()).draw();
                                    });
                            }

                        });
                    }
                }).on('draw', function () {
                    detailRows.forEach(function (id, i) {
                        $('#' + id + ' td.details-control').trigger('click');
                    });
                });

                // Array to track the ids of the details displayed rows
                var detailRows = [];

                $('#logs-table tbody').on('click', 'tr td.details-control', function () {
                    var tr = $(this).closest('tr');
                    var row = table.row(tr);
                    var idx = detailRows.indexOf(tr.attr('id'));

                    if (row.child.isShown()) {
                        tr.removeClass('details');
                        row.child.hide();

                        // Remove from the 'open' array
                        detailRows.splice(idx, 1);
                    } else {
                        tr.addClass('details');
                        row.child(formatProps(row.data())).show();

                        // Add to the 'open' array
                        if (idx === -1) {
                            detailRows.push(tr.attr('id'));
                        }
                    }
                });

            })


        function formatProps(d) {
            let props = d.properties;
            let view = '<ul>';
            for (let row in props.attributes) {

                view += '<li><strong>' + row + ':</strong> ';

                if (props.old !== undefined && props.attributes[row] === props.old[row]) {
                    view += props.old[row]
                } else if (props.old === undefined) {
                    view += '<span class="selection">' + props.attributes[row] + '</span>'
                } else {
                    view += props.attributes[row] + '<span class="selection"> -> ' + props.attributes[row] + '</span>'

                }

                view += '</li>';
            }

            view += '</ul>'

            return view;
        }

    </script>

@stop
