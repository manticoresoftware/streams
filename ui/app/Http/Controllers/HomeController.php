<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @param Request $request
     *
     * @return Application|Redirector|RedirectResponse|ApplicationContract
     */
    public function index(Request $request): Application|Redirector|RedirectResponse|ApplicationContract
    {

        $request->user()->authorizeRoles(['admin', 'manager']);

        // Logic that determines where to send the user
        if ($request->user()->hasRole('manager')) {
            return redirect('/manager/home');
        }

        return redirect('/admin/home');

    }
}
