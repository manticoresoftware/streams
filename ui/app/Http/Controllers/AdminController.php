<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use App\Models\User;
use Auth;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use \Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;


class AdminController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:' . Role::ROLE_ADMIN);
    }

    public function home(): View|Application|Factory|ApplicationContract
    {
        return view('admin.home');
    }

    public function getUsersList(): JsonResponse
    {

        $users = User::select(['id', 'name', 'email', 'role_id', 'deleted_at'])
            ->withTrashed()->with('role');

        return Datatables::of($users)
                         ->addColumn('action', function ($user) {

                             $html = '<div class="btn-group" role="group" aria-label="Basic example">';

                             if ( ! is_null($user->deleted_at)) {
                                 $html .= '<button type="button" class="btn btn-primary btn-sm j-change-role-btn" data-id="' .
                                          $user->id . '" data-role="1">Admin</button>' .
                                          '<button type="button" class="btn btn-primary btn-sm j-change-role-btn" data-id="' .
                                          $user->id . '" data-role="2">User</button>' .
                                          '<button type="button" class="btn btn-success btn-sm j-ban-btn" data-id="' .
                                          $user->id . '">Delete</button>';
                             } else {

                                 if ($user->role['name'] == \App\Models\Role::ROLE_MANAGER) {
                                     $html .= '<button type="button" class="btn btn-primary btn-sm j-change-role-btn" data-id="' .
                                              $user->id . '" data-role="1">Admin</button>' .
                                              '<button type="button" class="btn btn-success btn-sm j-change-role-btn" data-id="' .
                                              $user->id . '" data-role="2">User</button>';
                                 } else {
                                     $html .= '<button type="button" class="btn btn-success btn-sm j-change-role-btn" data-id="' .
                                              $user->id . '" data-role="1">Admin</button>' .
                                              '<button type="button" class="btn btn-primary btn-sm j-change-role-btn" data-id="' .
                                              $user->id . '" data-role="2">User</button>';
                                 }

                                 $html .= '<button type="button" class="btn btn-primary btn-sm j-ban-btn" data-id="' .
                                          $user->id . '">Delete</button>';
                             }

                             $html .= '</div>';

                             return $html;
                         })
                         ->make();
    }

    public function addUser(Request $request): JsonResponse
    {

        $validator = \Validator::make($request->all(), [
            'email'    => 'required|email|unique:users',
            'name'     => 'required|min:2|unique:users|regex:/^[a-zA-Z0-9]+$/i',
            'token'    => 'min:32',
            'password' => 'required|min:5'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $return = [];

            if ($errors->has('email')) {
                $return['email'] = $errors->first('email');
            }

            if ($errors->has('name')) {
                $return['name'] = $errors->first('name');
            }

            if ($errors->has('password')) {
                $return['password'] = $errors->first('password');
            }

            if ($errors->has('token')) {
                $return['token'] = $errors->first('token');
            }

            return response()->json(
                ['errors' => $return],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $role = Role::where('name', Role::ROLE_MANAGER)->first();

        $request->request->add(['role_id' => $role['id']]);

        $request              = $request->all();
        $request['password']  = bcrypt($request['password']);
        $request['api_token'] = ! empty($request['token'])
            ? $request['token']
            : Str::random(80);
        $newUser              = User::create($request);

        return response()->json([
            'success' => 'Record is successfully added',
            'token'   => $request['api_token'],
            'id'      => $newUser->id
        ]);
    }

    public function editUser(Request $request): JsonResponse
    {

        $newRole = $request->input('role_id');

        $userId = $request->input('user_id');
        if (empty($userId)) {
            return response()->json(
                ['message' => 'Pass the user ID'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($userId == Auth::user()->id) {
            return response()->json(
                ['message' => 'You can\'t update yourself'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        $roleAdmin   = Role::where('name', Role::ROLE_ADMIN)->first();
        $roleManager = Role::where('name', Role::ROLE_MANAGER)->first();
        if (in_array($newRole, [$roleAdmin['id'], $roleManager['id']])) {

            $user = User::withTrashed()->find($request->input('user_id'));
            if ($user->trashed()) {
                $user->restore();
            }
            $user->role_id = $newRole;
            $user->save();

            return response()->json(['message' => 'Account has been updated!']);
        }

        return response()->json(
            ['message' => 'Wrong role'],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }


    public function banUser(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        if (empty($userId)) {
            return response()->json(
                ['message' => 'Pass the user ID'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($userId == Auth::user()->id) {
            return response()->json(
                ['message' => 'You can\'t update yourself'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = User::withTrashed()->find($request->input('user_id'));
        if ( ! $user->trashed()) {
            $kubeService = resolve(KubeService::class);

            foreach ($user->streams()->get() as $process) {
                $context = new StreamsService($kubeService, $process, []);
                $context->removeStream();
                $process->delete();
            }

            $user->process = null;
            $user->save();
            $user->delete();

            return response()->json(['message' => 'Account has been banned!']);
        }

        $user->restore();

        return response()->json(['message' => 'Account has been restored!']);
    }

    public function deleteUser(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        if (empty($userId)) {
            return response()->json(
                ['message' => 'Pass the user ID'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($userId == Auth::user()->id) {
            return response()->json(
                ['message' => "You can't update yourself"],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = User::withTrashed()->find($request->input('user_id'));

        if ($user) {
            $kubeService = resolve(KubeService::class);
            foreach ($user->streams()->get() as $process) {
                $context = new StreamsService($kubeService, $process, []);
                $context->removeStream();
                $process->delete();
            }

            if ($user->forceDelete()) {
                return response()->json(
                    ['message' => 'User removed successfully']
                );
            }

            return response()->json(
                ['message' => 'Something wrong'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return response()->json(
            ['message' => "Can't find requested user"],
            Response::HTTP_NOT_FOUND
        );
    }

    public function reissueToken(Request $request): JsonResponse
    {
        $validator = \Validator::make($request->all(), [
            'user_id' => 'required',
            'token'   => 'required|min:32'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $return = [];

            if ($errors->has('user_id')) {
                $return['user_id'] = $errors->first('user_id');
            }

            if ($errors->has('token')) {
                $return['token'] = $errors->first('token');
            }

            return response()->json(
                ['errors' => $return],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = User::find($request->input('user_id'));
        if ($user) {
            $user->api_token = $request->get('token');
            $user->save();

            return response()->json(
                ['message' => 'User token successfully changed']
            );
        }

        return response()->json(
            ['message' => 'Can\'t find user'],
            Response::HTTP_NOT_FOUND
        );
    }
}
