<div class="modal fade" id="assign-user-modal" tabindex="-1"
     role="dialog" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalLabel"></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="j-assign-user-form" method="POST" action="{{ url('/admin/process/assignUser') }}">
                    <input type="hidden" id="assign_process_id" name="process_id" value="">
                    @csrf

                    <div class="form-group row">

                        <label for="assign_user"
                               class="col-md-4 col-form-label text-md-right">{{ __('Select user') }}</label>

                        <div class="col-md-6">
                            <select class="form-control" name="assign_user" id="assign_user">

                            </select>
                            <span class="invalid-feedback j-invalid-assign-user hidden" role="alert"></span>

                        </div>
                    </div>

                    <div class="form-group row mb-0">
                        <div class="col-md-6 offset-md-4">
                            <button type="submit" class="btn btn-primary">
                                {{ __('Assign') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
