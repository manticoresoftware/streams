@extends('layouts.dashboard')

@include('admin.adduser')
@section('content')


    <div class="modal" id="modal-delete-user" tabindex="-1" role="dialog" aria-labelledby="modal-label-delete-user"
         aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-label-delete-user">Remove user?</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>User and all its streams will be removed. Are you sure you want to proceed?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="j-ban-process">Delete</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>


    <div class="row">
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#add-user-modal">
            Add user
        </button>
    </div>
    <hr>


    <div class="row">

        <table id="users-table" class="display" style="width:100%">
            <thead>
            <tr>
                <th scope="col">Email</th>
                <th scope="col">Username</th>
                <th scope="col">Role</th>
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
                    "ajax": "getUsersList",
                    "order": [[1, "desc"]],
                    columns: [
                        {"data": 'email'},
                        {"data": 'name'},
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
            .on("click", '.j-change-role-btn', function () {
                var user_id = $(this).data('id');
                var role = $(this).data('role');
                $.ajax({
                    url: 'editUser',
                    type: 'POST',
                    data: {
                        user_id: user_id,
                        role_id: role,
                        _token: "{{csrf_token()}}"
                    },

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
            .on("click", '#j-ban-process', function () {
                var user_id = $(this).data('id');
                $.ajax({
                    url: 'banUser',
                    type: 'POST',
                    data: {
                        user_id: user_id,
                        _token: "{{csrf_token()}}"
                    },

                    success: function (data) {
                        $('#modal-delete-user').modal('hide');
                        toast(data.message);
                        table.ajax.reload();
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            })
            .on("click", '.j-ban-btn', function () {
                $('#j-ban-process').attr('data-id', $(this).data('id'));
                $('#modal-delete-user').modal('show');
            })
            .on('submit', '#j-add-new-user-form', function (e) {
                e.preventDefault();
                var fields = ['email', 'name', 'password'];
                $.ajax({
                    url: 'addUser',
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function (data) {
                        $.each(fields, function (index, value) {
                            $('.j-invalid-' + value).text('').hide();
                            $('#' + value).removeClass('is-invalid').val('');
                        });
                        $('#add-user-modal').modal('hide');
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
            });
    </script>

@stop
