<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;
use Yajra\DataTables\Facades\DataTables;


class LogsController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:'.Role::ROLE_ADMIN);
    }

    public function index(): View|Application|Factory|ApplicationContract
    {
        return view('admin.logs.index');
    }


    /**
     * https://datatables.yajrabox.com/eloquent/custom-filter
     */
    public function view(\Request $request): JsonResponse
    {
        $users = Activity::select(
            [
                'id',
                'description',
                'subject_type',
                'subject_id',
                'causer_type',
                'causer_id',
                'properties',
                'created_at'
            ]
        );

        return Datatables::of($users)
            ->filterColumn('subject_type', function ($query, $keyword) {
                $query->whereRaw("subject_type like ?", ["%{$keyword}%"]);
            })
            ->filterColumn('causer_id', function ($query, $keyword) {
                $users = User::where('email', 'like', '%'.$keyword.'%')->get();

                $searchUsers = [];
                foreach ($users as $user) {
                    $searchUsers[] = $user->id;
                }

                $query->whereIn("causer_id", $searchUsers);
            })
            ->editColumn('subject_type', function ($row) {
                $append = '';
                if ($row['subject_id'] !== null) {
                    $append = " (#".$row['subject_id'].')';
                }

                return str_replace('App\\Models\\', '', $row['subject_type']).$append;
            })
            ->editColumn('causer_id', function ($row) {
                $causer = $row->causer;
                if ($causer !== null && $causer->email) {
                    return $causer->email;
                }

                return '';
            })->removeColumn('subject_id')
            ->make();
    }
}
