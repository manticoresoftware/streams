<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use \Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;


class GoalsController extends Controller
{

    protected string $model;
    protected string $section;

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:' . Role::ROLE_ADMIN);

        if ($this->section == 'source') {
            $this->model = '\App\Models\Source';
        } else {
            $this->model = '\App\Models\Destination';
        }
    }

    public function index(): View|Application|Factory|ApplicationContract
    {
        return view('admin.goals.index', ['type' => $this->section]);
    }

    public function getList(): JsonResponse
    {

        $fields = ['id', 'name', 'host', 'topic', 'group'];
        $data   = call_user_func_array([$this->model, 'select'], [$fields]);

        return Datatables::of($data)
                         ->addColumn('action', function ($row) {

                             $html = '<button type="button" class="btn btn-danger btn-sm j-delete-' . $this->section . '" data-id="' .
                                     $row->id . '">Delete</button>';

                             return $html;
                         })
                         ->make();
    }

    public function add(Request $request): JsonResponse
    {

        $validator = \Validator::make($request->all(), [
            'name'  => 'required|unique:' . $this->section . 's',
            'host'  => 'required|min:3',
            'topic' => 'required|min:1'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $return = [];

            if ($errors->has('name')) {
                $return['name'] = $errors->first('name');
            }

            if ($errors->has('host')) {
                $return['host'] = $errors->first('host');
            }

            if ($errors->has('topic')) {
                $return['topic'] = $errors->first('topic');
            }

            return response()->json(
                ['errors' => $return],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        if ( ! $request->get('group')) {
            $group = md5(microtime());
            $group = substr($group, 0, 8);
            $request->request->add(['group' => 'MS_' . $group]);
        }

        $errors = [];
        $hosts  = explode(',', $request->get('host'));
        foreach ($hosts as $host) {
            $host = trim($host);

            $exploded = explode(':', $host);
            $host     = $exploded[0];
            if (empty($exploded[1])) {
                return response()->json(
                    ['errors' => ['host' => "You didn't enter the port"]],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            } else {
                $port = $exploded[1];
            }

            try {
                $fp = fsockopen($host, $port, $errCode, $errStr, 1);
                fclose($fp);
            } catch (\Exception $e) {
                $errors[] = "Can't connect to $host:$port";
            }
        }


        $new = call_user_func_array([$this->model, 'create'], [$request->all()]);


        $response = ['success' => 'Record is successfully added', 'id' => $new->id];
        if ( ! empty($errors)) {
            $response['errors'] = implode('<br>', $errors);
        }

        return response()->json($response);
    }

    public function delete(Request $request): JsonResponse
    {

        $id = $request->input('id');
        if (empty($id)) {
            return response()->json(
                ['message' => 'Pass the ID'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!is_numeric($id)){
            return response()->json(
                ['message' => 'ID should be the numeric'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        call_user_func_array(
            [$this->model, 'destroy'],
            [$request->input('id')]
        );

        return response()->json(['message' => 'Record deleted!']);
    }
}
