@extends('layouts.dashboard')

@include('admin.goals.add')
@section('content')
    <style>
        table td:nth-child(2){
            word-break: break-all;
        }
    </style>

    <div class="row">
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#add-new-{{$type}}">
            Add Kafka {{$type}}
        </button>
    </div>
    <hr>


    <div class="row">

        <table id="users-table" class="display" style="width:100%">
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Host</th>
                <th scope="col">Topic</th>
                @if($type == 'source')
                    <th scope="col">Group</th>
                @endif
                <th scope="col">Actions</th>
            </tr>
            </thead>
        </table>
    </div>

    <script>
        var table = null;
        $(document)
            .ready(function () {

                table = $('#users-table').DataTable({
                    "pageLength": 50,
                    "lengthChange": false,
                    "processing": false,
                    "serverSide": true,
                    "ajax": "{{$type}}/getList",
                    "order": [[0, "desc"]],
                    "language": {
                        "emptyTable": "No {{$type}}s yet"
                    },
                    columns: [
                        {"data": 'name'},
                        {"data": 'host'},
                        {"data": 'topic'},
                            @if($type == 'source')
                        {
                            "data": 'group'
                        },
                            @endif
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
            .on('submit', '#j-add-new-{{$type}}-form', function (e) {
                e.preventDefault();
                var fields = ['name', 'host', 'topic', 'group'];
                $.ajax({
                    url: '{{$type}}/add',
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function (data) {
                        $.each(fields, function (index, value) {
                            $('.j-invalid-' + value).text('').hide();
                            $('#' + value).removeClass('is-invalid').val('');
                        });
                        $('#add-new-{{$type}}').modal('hide');

                        if(data.errors){
                            toast(data.errors, true, false);
                        }
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
            .on("click", '.j-delete-{{$type}}', function () {
                var id = $(this).data('id');
                $.ajax({
                    url: '{{$type}}/delete',
                    type: 'POST',
                    data: {
                        id: id
                    },

                    success: function (data) {
                        toast(data.message, false, false);
                        table.ajax.reload();
                    },

                    error: function (jqXHR, textStatus, errorThrown ) {
                        toast(jqXHR.responseJSON.message, true, false);
                    }
                });
            })
    </script>

@stop
