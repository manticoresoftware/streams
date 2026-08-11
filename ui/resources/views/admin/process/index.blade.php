@extends('layouts.dashboard')

@include('admin.process.resume')
@include('admin.process.suspend')
@include('admin.process.assignuser')
@include('admin.process.unassignuser')
@include('admin.process.remove')
@section('content')

    <div class="row">
        <button type="button" class="btn btn-primary" id="add-new-process">
            Add process
        </button>
    </div>
    <hr>

    <style>
        table {
            word-break: break-all;
        }
    </style>

    <div class="row">
        <table id="users-table" class="display" style="width:100%">
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Assigned users</th>
                <th scope="col">Source</th>
                <th scope="col">Destination</th>
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
                    "ajax": "/admin/process/getList",
                    "order": [[0, "asc"]],
                    "language": {
                        "emptyTable": "No processes yet"
                    },
                    columns: [
                        {"data": 'name'},
                        {"data": 'user'},
                        {"data": 'source'},
                        {"data": 'destination'},
                        {"data": 'action', orderable: false, searchable: false}
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
            .on('click', '#add-new-process', function () {
                document.location.href = "/admin/process/new";
            })
            .on('click', '.j-assign-user', function () {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#assign_process_id').val(id);
                $('#assignModalLabel').html('Assign ' + name + ' to:');
                $.ajax({
                    url: '/admin/process/getToAssignUsersList',
                    type: 'POST',
                    data: {
                        process_id: id
                    },
                    success: function (data) {
                        $('#assign_user').empty();
                        $(data).each(function (id, row) {
                            $('#assign_user').append(new Option(row.email, row.id));
                        });

                        $('#assign-user-modal').modal('show');
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on('click', '.j-unassign-user', function () {

                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#unassign_process_id').val(id);
                $('#unassignModalLabel').html('Unassign process ' + name + ' from:');

                $.ajax({
                    url: '/admin/process/getToUnassignUsersList',
                    type: 'POST',
                    data: {
                        process_id: id
                    },
                    success: function (data) {
                        $('#unassign_user').empty();
                        $(data).each(function (id, row) {
                            $('#unassign_user').append(new Option(row.email, row.id));
                        });

                        $('#unassign-user-modal').modal('show');
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on("submit", '#j-assign-user-form', function (e) {
                e.preventDefault();
                $.ajax({
                    url: '/admin/process/assignUser',
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function (data) {
                        $('#assign-user-modal').modal('hide');
                        table.ajax.reload();
                        toast(data.message, false, false);
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on("submit", '#j-unassign-user-form', function (e) {
                e.preventDefault();

                $.ajax({
                    url: '/admin/process/unassignUser',
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function (data) {
                        $('#unassign-user-modal').modal('hide');
                        table.ajax.reload();
                        toast(data.message, false, false);
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on('show.bs.modal', '#confirm-delete', function (e) {
                $('#process-name').text($(e.relatedTarget).data('name'));
                $(this).find('.btn-delete-ok').attr('data-id', $(e.relatedTarget).data('id'));
            }).on("click", '.btn-delete-ok', function () {
                let id = $(this).attr('data-id');
                $.ajax({
                    url: '/admin/process/remove/' + id,
                    type: 'GET',
                    success: function (data) {
                        toast(data.message, false, false);
                        table.ajax.reload();
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on("click", '.j-streaming-actions', function () {
                var url,
                    id = $(this).data('id'),
                    action = $(this).data('action');

                if (action === "suspend") {
                    url = '/admin/process/getSuspendList'
                } else {
                    url = '/admin/process/getResumeList'
                }
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        process_id: id
                    },
                    success: function (data) {
                        $('#' + action + '_streaming').empty();
                        $(data).each(function (id, row) {
                            $('#' + action + '_streaming').append(new Option(row.name, row.streamId));
                        });

                        $('#' + action + '-modal').modal('show');
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on("click", '.j-edit-process', function (e) {
                var id = $(this).attr('data-id');
                document.location.href = "/admin/process/new?process_id=" + id;
            })
            .on("submit", '#j-streaming-actions-form', function (e) {
                e.preventDefault();
                var action = $(this).data('action');
                $.ajax({
                    url: '/admin/process/' + action,
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function (data) {
                        $('#' + action + '-modal').modal('hide');
                        table.ajax.reload();
                        toast(data.message, false, false);
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
    </script>


@stop
