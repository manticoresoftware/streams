<div class="modal fade" id="suspend-modal" tabindex="-1"
     role="dialog" aria-labelledby="suspendModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="suspendModalLabel">Suspend streaming</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="j-streaming-actions-form" data-action="suspend" method="POST"
                      action="{{ url('/admin/process/suspend') }}">
                    @csrf
                    <div class="form-group row">

                        <label for="suspend_streaming"
                               class="col-md-4 col-form-label text-md-right">{{ __('Select user') }}</label>

                        <div class="col-md-6">
                            <select class="form-control" name="streamId" id="suspend_streaming"></select>
                        </div>
                    </div>

                    <div class="form-group row mb-0">
                        <div class="col-md-6 offset-md-4">
                            <button type="submit" class="btn btn-primary">
                                {{ __('Suspend') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
