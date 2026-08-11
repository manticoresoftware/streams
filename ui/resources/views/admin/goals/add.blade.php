<div class="modal fade" id="add-new-{{$type}}" tabindex="-1" role="dialog" aria-labelledby="add-new-{{$type}}" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">Add new Kafka {{$type}}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="j-add-new-{{$type}}-form" method="POST" action="{{ url('admin/'.$type.'/add') }}">
                    @csrf

                    <div class="form-group row">
                        <label for="name" class="col-md-4 col-form-label text-md-right">{{ __('Name') }}</label>

                        <div class="col-md-6">
                            <input id="name" type="text" class="form-control" name="name" value="{{ old('name') }}" required autofocus>

                            <span class="invalid-feedback j-invalid-name hidden" role="alert"></span>
                        </div>
                    </div>


                    <div class="form-group row">
                        <label for="host" class="col-md-4 col-form-label text-md-right">{{ __('Kafka host(s)') }}</label>

                        <div class="col-md-6">
                            <input id="host" type="text" class="form-control" name="host" value="{{ old('host') }}" required>

                            <span class="invalid-feedback j-invalid-host hidden" role="alert"></span>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="topic" class="col-md-4 col-form-label text-md-right">{{ __('Kafka topic') }}</label>

                        <div class="col-md-6">
                            <input id="topic" type="text" class="form-control" name="topic" value="{{ old('topic') }}" required>

                            <span class="invalid-feedback j-invalid-topic hidden" role="alert"></span>

                            @if($type != 'source')
                                <p><small>Available placeholders: {username}</small></p>
                            @endif
                        </div>
                    </div>


                    @if($type == 'source')
                    <div class="form-group row">
                        <label for="group" class="col-md-4 col-form-label text-md-right">{{ __('Consumer group') }}</label>

                        <div class="col-md-6">
                            <input id="group" type="text" class="form-control" name="group" value="{{ old('group') }}">

                            <span class="invalid-feedback j-invalid-group hidden" role="alert"></span>
                            @if($type == 'source')
                                <p><small>Available placeholders: {username}</small></p>
                            @endif
                        </div>
                    </div>
                    @endif


                    <div class="form-group row mb-0">
                        <div class="col-md-6 offset-md-4">
                            <button type="submit" class="btn btn-primary">
                                {{ __('Add '.$type) }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
