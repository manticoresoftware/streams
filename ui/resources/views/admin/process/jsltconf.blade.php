<div class="modal fade" id="jslt-conf-modal" tabindex="-1"
     role="dialog" aria-labelledby="jslt-conf-label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="jslt-conf-label">Advanced output JSON transformation</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group row">
                    <div class="col-md-12">
                        <textarea type="text" class="form-control" id="jslt-config" rows="10"
                                  placeholder="Enter JSLT transformation configuration..."></textarea>
                        <small>The transformation must be in accordance with the
                            <a href="https://github.com/schibsted/jslt" target="_blank">JSLT documentation</a></small>
                    </div>

                </div>

                <div class="form-group row mb-0">
                    <div class="col text-center">
                        <button id="add-jslt-config" type="button" class="btn btn-primary">{{ __('Add') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
