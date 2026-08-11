<div class="modal fade" id="add-new-variable" tabindex="-1" role="dialog" aria-labelledby="add-new-variable"
     aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">Add new variable</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="j-add-new-variable-form" method="POST" action="add">
                    @csrf
                    <div class="form-group row">
                        <label for="name" class="col-12 col-form-label">{{ __('Name') }}</label>

                        <div class="col-12">
                            <input id="name" type="text" class="form-control" name="name" value="{{ old('name') }}"
                                   required autofocus>

                            <span class="invalid-feedback j-invalid-name hidden" role="alert"></span>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="text" class="col-12 col-form-label">{{ __('Text') }}</label>

                        <div class="col-12">
                            <textarea rows="12" id="text" type="text" class="form-control" name="text"
                                      value="{{ old('text') }}" required></textarea>

                            <span class="invalid-feedback j-invalid-text hidden" role="alert"></span>
                        </div>
                    </div>

                    <div class="form-group row mb-0">
                        <div class="col-md-6 offset-md-4">
                            <button id="modal-button-submit" type="submit" class="btn btn-primary">
                                {{ __('Add variable') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
