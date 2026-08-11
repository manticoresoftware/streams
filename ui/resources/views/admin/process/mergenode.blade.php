<div class="modal fade" id="merge-modal" tabindex="-1"
     role="dialog" aria-labelledby="mergeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mergeModalLabel">Merge nodes</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">

                <div class="form-group row">

                    <label for="select-merge-node"
                           class="col-md-4 col-form-label text-md-right">{{ __('Select node for merging') }}</label>

                    <div class="col-md-6">
                        <select class="form-control" id="select-merge-node">

                        </select>
                    </div>
                </div>

                <div class="form-group row mb-0">
                    <div class="col-md-6 offset-md-4">
                        <button id="merge-nodes" type="button" data-source="origin" class="btn btn-primary">{{ __('Merge') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
