@extends('layouts.dashboard')

@include('manager.variables.add')
@section('content')
    <style>
        .loader,
        .loader:before,
        .loader:after {
            background: #836e6e;
            -webkit-animation: load1 1s infinite ease-in-out;
            animation: load1 1s infinite ease-in-out;
            width: 1em;
            height: 4em;
        }

        .loader {
            color: #836e6e;
            text-indent: -9999em;
            margin: 0 auto;
            position: relative;
            font-size: 3px;
            -webkit-transform: translateZ(0);
            -ms-transform: translateZ(0);
            transform: translateZ(0);
            -webkit-animation-delay: -0.16s;
            animation-delay: -0.16s;
        }

        .loader:before,
        .loader:after {
            position: absolute;
            top: 0;
            content: '';
        }

        .loader:before {
            left: -1.5em;
            -webkit-animation-delay: -0.32s;
            animation-delay: -0.32s;
        }

        .loader:after {
            left: 1.5em;
        }

        @-webkit-keyframes load1 {
            0%,
            80%,
            100% {
                box-shadow: 0 0;
                height: 3em;
            }
            40% {
                box-shadow: 0 -1em;
                height: 4em;
            }
        }

        @keyframes load1 {
            0%,
            80%,
            100% {
                box-shadow: 0 0;
                height: 3em;
            }
            40% {
                box-shadow: 0 -1em;
                height: 4em;
            }
        }
    </style>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <div class="row">
        <button type="button" id="add-variable-button" class="btn btn-primary" data-toggle="modal">
            Add Variable
        </button>
    </div>
    <hr>

    <div class="row">

        <table id="variables-table" style="width: 100%;">
            <thead>
            <tr>
                <th scope="col">Id</th>
                <th scope="col">Name</th>
                <th scope="col">Text</th>
                <th scope="col">Actions</th>
            </tr>
            </thead>
        </table>
    </div>

    <script>
        const currentStream = {{$stream}};

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        var table = null;
        $(document)
            .ready(function () {
                table = $('#variables-table').DataTable({
                    "pageLength": 50,
                    "lengthChange": false,
                    "processing": false,
                    "serverSide": true,
                    "ajax": {
                        url: "variables/getList", data: function (d) {
                            d.streamId = currentStream;
                        }
                    },
                    "order": [[0, "desc"]],
                    "language": {
                        "emptyTable": "No variables yet"
                    },
                    columns: [
                        {"data": 'id'},
                        {"data": 'name'},
                        {"data": 'text'},
                        {
                            "data": 'action', orderable: false, searchable: false
                        }
                    ],
                    "fnDrawCallback": function (oSettings) {
                        if (oSettings._iDisplayLength > oSettings.fnRecordsDisplay()) {
                            $(oSettings.nTableWrapper).find('.dataTables_paginate').hide();
                        } else {
                            $(oSettings.nTableWrapper).find('.dataTables_paginate').show();
                        }
                    }
                });
            })
            .on('click', '#add-variable-button', function () {
                $('#exampleModalLabel').text("Add variable");
                $('#j-add-new-variable-form').attr('data-name', '').attr('data-method', 'put');
                $('#j-add-new-variable-form #name').val('').prop('disabled', false).next().text('').hide();
                $('#j-add-new-variable-form #text').val('').prop('disabled', false).next().text('').hide();
                $('#modal-button-submit').text('Add variable');
                $('#add-new-variable').modal('show');
            })
            .on('click', '.j-edit-variable', function () {
                var name = $(this).data('name');
                $.ajax({
                    url: 'variables/' + name + '/?streamId=' + currentStream,
                    type: 'GET',
                    success: function (data) {
                        $('#exampleModalLabel').text("Edit variable");
                        $('#modal-button-submit').text('Save variable');
                        $('#j-add-new-variable-form').attr('data-name', data.name).attr('data-method', 'post');

                        $('#j-add-new-variable-form #name').val(data.name)
                            .prop('disabled', true).next().text('').hide();
                        $('#j-add-new-variable-form #text').val(data.text)
                            .prop('disabled', false).next().text('').hide();


                        $('#add-new-variable').modal('show')
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on('submit', '#j-add-new-variable-form', function (e) {
                e.preventDefault();
                var fields = ['name', 'text'];
                var method = $(this).attr('data-method')
                let name = $(this).attr('data-name')
                $.ajax({
                    url: 'variables/' + name+ '/?streamId=' + currentStream,
                    type: method,
                    data: $(this).serialize(),
                    success: function (data) {
                        $.each(fields, function (index, value) {
                            $('.j-invalid-' + value).text('').hide();
                            $('#' + value).removeClass('is-invalid').val('');
                        });
                        $('#add-new-variables').modal('hide');

                        if (data.errors) {
                            toast(data.errors);
                        }

                        if (data.success) {
                            toast(data.success);
                        }
                        $('#add-new-variable').modal('hide')
                        table.ajax.reload();

                    },
                    error: function (error) {
                        error = JSON.parse(error.responseText);
                        $.each(fields, function (index, value) {
                            if (error.errors[value] != null) {
                                $('.j-invalid-' + value).text(error.errors[value]).show();
                                $('#' + value).addClass('is-invalid');
                            } else {
                                $('.j-invalid-' + value).text('').hide();
                                $('#' + value).removeClass('is-invalid');
                            }
                        });
                    }
                });
            })
            .on("click", '.j-delete-variable', function () {
                var name = $(this).data('name');
                $.ajax({
                    url: 'variables/' + name+ '/?streamId=' + currentStream,
                    type: 'DELETE',
                    success: function (data) {
                        toast(data.success);
                        table.ajax.reload();
                    },

                    error: function (error) {
                        toast(error.responseJSON.errors.message, false, true);
                    }
                });
            })

    </script>

@stop
