@extends('layouts.dashboard')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">API access tokens</div>

                    <div class="card-body">
                        <div class="row">

                            @if (Auth::user()->api_token)

                                <div class="col-10">
                                    @if (isset($newToken))
                                        <h5 class="m-2">{{ $newToken }}</h5>
                                    @else
                                        <p class="m-2">Default token</p>
                                    @endif
                                </div>
                                <div class="col-2 text-right">
                                    <a href="/tokens/remove" class="btn btn-danger">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>

                            @else
                                <div class="col-12 text-center">
                                    <a href="/tokens/update" class="btn btn-success">
                                        <i class="fas fa-check-circle"></i> Generate
                                    </a>
                                </div>
                            @endif
                        </div>
                        @if (isset($newToken))
                            <div class="row">
                                <div class="col-12 mt-2 text-center">
                                    <div class="alert alert-danger" role="alert">
                                        Save this token in a safe place, as it is shown once and can <b>only</b> be regenerated in
                                        the future
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
