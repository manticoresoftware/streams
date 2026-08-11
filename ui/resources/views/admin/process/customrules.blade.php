<div class="modal fade" id="custom-rules-modal" tabindex="-1"
     role="dialog" aria-labelledby="customRulesModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customRulesModalLabel">Add custom node</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group row">

                    <label for="custom-rule-path"
                           class="col-md-4 col-form-label text-md-right">{{ __('Path for the node') }}</label>

                    <div class="col-md-6">
                        <input type="text" class="form-control" id="custom-rule-path"
                               value="" placeholder="Sample: data.text[*].id">
                    </div>
                </div>

                <div class="form-group row">

                    <label for="custom-rule-type"
                           class="col-md-4 col-form-label text-md-right">{{ __('Type') }}</label>

                    <div class="col-md-6">
                        <select class="form-control" id="custom-rule-type">
                            <option value="string" selected>String</option>
                            <option value="int">Integer</option>
                            <option value="bigint">Big integer</option>
                            <option value="float">Float</option>
                            <option value="bool">Boolean</option>
                            <option value="timestamp">Timestamp</option>
                            <option value="url">URL</option>
                        </select>

                    </div>
                </div>

                <div class="form-group row mb-0">
                    <div class="col-md-6 offset-md-4">
                        <button id="add-custom-rule" type="button" class="btn btn-primary">{{ __('Add') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
