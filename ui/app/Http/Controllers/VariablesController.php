<?php

namespace App\Http\Controllers;

use App\Http\Requests\VariableCreateRequest;
use App\Http\Requests\VariableEditRequest;
use App\Models\Role;
use App\Services\ManticoreService;
use App\Models\Variable;
use Auth;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;


class VariablesController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:' . Role::ROLE_MANAGER);
        $this->middleware('streamsSelector');
        $this->middleware('hasStreams');
    }

    public function index(): View|Application|Factory|ApplicationContract
    {
        return view('manager.variables.index')
            ->with('stream', Auth::user()->process);
    }

    public function getList(ManticoreService $manticoreService): JsonResponse
    {
        $data = Variable::select(['id', 'name', 'text'])
            ->where(['stream_id' => Auth::user()->process]);
        $lock = $manticoreService->isLocked();

        return Datatables::of($data)
            ->addColumn('action', function ($row) use ($lock) {
                $html = "<div class='loader'>Loading...</div>";
                if (!$lock) {
                    $html
                        = '<button type="button" class="btn btn-warning btn-sm j-edit-variable" data-name="'
                        .
                        $row->name
                        . '">Edit</button><button type="button" class="btn btn-danger btn-sm j-delete-variable" data-name="'
                        .
                        $row->name . '">Delete</button>';
                }

                return $html;
            })
            ->make();
    }

    public function get(Variable $variable): JsonResponse
    {
        /** @var Collection $streams */
        $streams = Auth::user()->streams()->pluck('id');
        if (!in_array($variable->stream_id, $streams->toArray())) {
            return response()->json(
                ['errors' => ['text' => "You can't edit non owned variable"]],
                Response::HTTP_FORBIDDEN);
        }

        return response()->json($variable);
    }

    public function add(VariableCreateRequest $request): JsonResponse
    {
        $attrs = $request->all();
        $attrs['stream_id'] = Auth::user()->process;
        if ($attrs['stream_id'] === null) {
            return response()->json(
                ['errors' => ['variable' => "You don't have default stream"]],
                Response::HTTP_FORBIDDEN);
        }
        $new = Variable::create($attrs);

        $response = [
            'success' => 'Record is successfully added', 'id' => $new->id
        ];

        return response()->json($response);
    }

    /**
     * @throws \JsonException
     */
    public function edit(
        Variable $variable,
        VariableEditRequest $request,
        ManticoreService $service
    ): JsonResponse {
        $variableBefore = $variable->replicate();

        $streams = Auth::user()->streams()->pluck('id');
        if (!in_array($variable->stream_id, $streams->toArray())) {
            return response()->json(
                ['errors' => ['text' => "You can't edit non owned variable"]],
                Response::HTTP_FORBIDDEN
            );
        }

        $variable->text = $request->text;

        $status = $service->updateRuleVariables($variableBefore, $variable);

        if ($status === false) {
            return response()->json(
                [
                    'errors' =>
                        ['text' => $service->getError()]
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['success' => 'Record is successfully edited']);
    }

    public function delete(
        Variable $variable,
        ManticoreService $service
    ): JsonResponse {
        $streams = Auth::user()->streams()->pluck('id');
        if (!in_array($variable->stream_id, $streams->toArray())) {
            return response()->json(
                [
                    'errors' => [
                        'message' => "You can't remove non owned variable"
                    ]
                ],
                Response::HTTP_FORBIDDEN);
        }

        try {
            $status = $service->removeRuleVariable($variable);
        } catch (\Exception $e) {
            return response()->json(
                [
                    'errors' => [
                        'message' => 'Exception at variable removing. Contact administrator'
                    ]
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        if ($status === false) {
            return response()->json(
                [
                    'errors' => [
                        'message' => 'Error during variable removing. Rollback changes'
                    ]
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['success' => 'Record is successfully removed']);
    }
}
