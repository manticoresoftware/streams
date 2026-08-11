<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;

class ApiTokenController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function get()
    {
        return view('token');
    }

    /**
     * Update the authenticated user's API token.
     *
     */
    public function update(Request $request): View|Application|Factory|ApplicationContract
    {

        $token = Str::random(80);

        $request->user()->forceFill([
            'api_token' => $token,
        ])->save();

        return view('token')->with(['newToken' => $token]);
    }

    public function remove(Request $request): Application|Redirector|RedirectResponse|ApplicationContract
    {
        $request->user()->forceFill([
            'api_token' => null,
        ])->save();

        return redirect('/tokens');
    }
}
