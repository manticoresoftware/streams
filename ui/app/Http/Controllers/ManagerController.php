<?php

namespace App\Http\Controllers;


use App\Http\Requests\ManagerSection\CreateRuleRequest;
use App\Http\Requests\ManagerSection\GetRulesRequest;
use App\Http\Requests\ManagerSection\TsvFileRequest;
use App\Models\Processes;
use App\Models\Role;
use App\Models\Rule;
use App\Services\Curl\CurlService;
use App\Services\FileCacheService;
use App\Services\KafkaConnection;
use App\Services\ManticoreService;
use App\Services\TsvParser;
use Auth;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use \Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpFoundation\Response;


class ManagerController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:' . Role::ROLE_MANAGER);
        $this->middleware('streamsSelector');
        $this->middleware('hasStreams');
    }

    public function home(ManticoreService $manticoreService): View|Application|Factory|ApplicationContract
    {
        $fields    = [];
        $rawFields = $manticoreService->getFields();

        foreach ($rawFields as $field) {
            $fields[] = '@' . $field['Field'];
        }

        return view('manager.home')->with('fields', $fields)->with('stream', Auth::user()->process);
    }

    public function getRulesList(GetRulesRequest $request, ManticoreService $manticoreService): JsonResponse
    {
        $validated = $request->validated();
        if ($request->input('search.value')) {
            return response()->json($manticoreService->searchRule(
                $request->input('search.value'),
                $request->input('length'),
                $request->input('start'),
                $request->input('order.0.column'),
                $request->input('order.0.dir')
            ), $manticoreService->getStatusCode());
        }

        return response()->json($manticoreService
            ->getRules(
                $validated['length'],
                $validated['start'],
                $validated['order'][0]['column'] ?? 0,
                $validated['order'][0]['dir'] ?? "desc",
            ), $manticoreService->getStatusCode());
    }

    public function deleteRule($id, ManticoreService $manticoreService): JsonResponse
    {
        if ( ! empty($id)) {
            FileCacheService::increase(Auth::user()->id, FileCacheService::RULE_DELETE);
            return response()->json(
                $manticoreService->deleteRule($id),
                $manticoreService->getStatusCode()
            );
        }

        return response()->json(
            ['message' => 'Rule not found'],
            Response::HTTP_NOT_FOUND
        );
    }

    public function deleteRulesList(Request $request, ManticoreService $manticoreService): JsonResponse
    {
        if ( ! empty($request->get('rules_id'))) {
            if (is_array($request->get('rules_id'))) {
                $rulesList = $request->get('rules_id');
                FileCacheService::increase(
                    Auth::user()->id,
                    FileCacheService::RULE_DELETE,
                    count($rulesList)
                );

                return response()->json(
                    $manticoreService->deleteRulesList($rulesList),
                    $manticoreService->getStatusCode()
                );
            }

            return response()->json(
                ['message' => 'rules_id must be array'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return response()->json(
            ['message' => 'Rules were not found'],
            Response::HTTP_NOT_FOUND
        );
    }

    public function addRule(CreateRuleRequest $request, ManticoreService $manticoreService): JsonResponse
    {
        $validated = $request->validated();

        $highlighting = false;
        if (isset($validated['rule_highlighting']) &&
            $validated['rule_highlighting'] === 'true') {
            $highlighting = true;
        }

        $rule = new Rule();
        $rule->setId($validated['rule_id'] ?? 0);
        $rule->setQuery($validated['rule_text'] ?? "");
        $rule->setFilters($validated['rule_filters'] ?? "");
        $rule->getTags()->setTag($validated['rule_tags'] ?? "");
        $rule->getTags()->setExternalQuery($validated['rule_external'] ?? "");
        $rule->getTags()->setHighlighting($highlighting);


        FileCacheService::increase(Auth::user()->id, FileCacheService::RULE_ADD);

        $result = $manticoreService->addRule(
            $rule,
            null,
                $validated['duplication_check'] ?? true
        );
        return response()->json($result, $manticoreService->getStatusCode());
    }

    public function replaceRules(Request $request, ManticoreService $manticoreService): JsonResponse
    {
        if ( ! is_array($request->input('newData'))) {
            return response()->json([
                'message' => 'Request key newData must be array'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $rule = new Rule();
        $rule->init($request->input('newData'));

        FileCacheService::increase(Auth::user()->id, FileCacheService::RULE_REPLACE);

        return response()->json($manticoreService->update(
            $rule,
            $request->input('id'),
            $request->input('query'),
            $request->input('weakQuery', false),
            $request->input('tags'),
            $request->input('weakTags'),
            $request->input('filters')
        ), $manticoreService->getStatusCode());
    }

    public function searchRuleExtended(Request $request, ManticoreService $manticoreService): JsonResponse
    {
        return response()->json($manticoreService->searchRuleExtended(
            $request->input('limit', 50),
            $request->input('offset', 0),
            $request->input('sortColumn', 0),
            $request->input('sortDirection', 'desc'),
            $request->input('id'),
            $request->input('query'),
            (bool)$request->input('weakQuery', false),
            $request->input('tags'),
            $request->input('weakTags'),
            $request->input('filters'),
            $request->input('externalQuery'),
            $request->input('variableName'),
        ), $manticoreService->getStatusCode());
    }

    public function importJson(Request $request, ManticoreService $manticoreService): JsonResponse
    {
        $queryValidation = $request->has('enable_validation') ?? $request->get('enable_validation');
        if ( ! $queryValidation) {
            $queryValidation = 0;
        }
        $json = $request->get('import');

        $json = json_decode($json, true);
        if (json_last_error()) {
            return response()->json(
                ['message' => 'Error JSON decoding'],
                Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (empty($json)) {
            return response()->json(
                ['message' => 'Error JSON decoding'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $importErrors = [];
        $statusCode = 200;

        foreach ($json as $row) {
            if ( ! empty($row)) {
                $rule = new Rule();
                $rule->setId($row['id']);
                $rule->setQuery($row['query']);
                $rule->setFilters($row['filters']);
                $rule->getTags()->setTag($row['tags']);
                $rule->getTags()->setExternalQuery($row['external']);
                $rule->getTags()->setHighlighting(isset($row['highlighting']) && ((bool)$row['highlighting'] === true));

                $duplicationCheck = isset($row['duplication_check']) && ((bool)$row['duplication_check'] === true);

                $result = $manticoreService->addRule($rule, $queryValidation, $duplicationCheck);
                $statusCode = $manticoreService->getStatusCode();

                if ($statusCode !== Response::HTTP_OK){
                    $importErrors['errors'][] = [
                        'query'   => $rule->getQuery(),
                        'filters' => $rule->getFilters(),
                        'tags'    => $rule->getTags()->getTag(),
                        'message' => $result['message'],
                        'statusCode' => $statusCode
                    ];
                } else {
                    FileCacheService::increase(Auth::user()->id, FileCacheService::RULE_ADD);
                }
            }
        }

        if ( ! empty($importErrors)) {
            return response()->json([
                'status' => CurlService::STATUS_SUCCESS,
                'message' => $importErrors
            ], $statusCode);
        }

        return response()->json(['status' => CurlService::STATUS_SUCCESS]);
    }

    public function importRules(TsvFileRequest $request, ManticoreService $manticoreService, TsvParser $tsvParser): JsonResponse
    {
        $file = $request->file('import');

        try {
            $content = file_get_contents($file->getPathname());
            [$processedRows, $importErrors] = $tsvParser->parse(
                $content,
                $manticoreService,
                Auth::user()->id
            );

            if (!empty($importErrors)) {
                return response()->json([
                    'status' => CurlService::STATUS_SUCCESS,
                    'message' => ['errors' => $importErrors]
                ], $processedRows > 0 ? Response::HTTP_MULTI_STATUS : Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'status' => CurlService::STATUS_SUCCESS
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process TSV: ' . $e->getMessage()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function getProcessingInfo(): JsonResponse
    {
        $streams = \Auth::user()->streams;
        $ids     = [];
        foreach ($streams as $stream) {
            $ids[] = $stream->process->id;
        }

        $processes = Processes::findMany($ids);

        return response()->json(['data' => $processes]);
    }

    public function getStreams()
    {
        $streams = \Auth::user()->streams()->get();
        if ($streams->count()) {
            return response()->json($streams);
        }

        return response()->json(
            ["message" => 'You don\'t have assigned streams'],
            Response::HTTP_BAD_REQUEST
        );
    }

    public function setStream($id): Application|Redirector|RedirectResponse|ApplicationContract
    {
        foreach (\Auth::user()->streams()->get() as $process) {
            if ($process->id == $id) {
                \Auth::user()->process = $id;
                \Auth::user()->save();

                return redirect('/manager/home');
            }
        }

        return abort(403, 'You don\'t assigned to this process');
    }

    public function emptyAssigns(): View|Application|Factory|ApplicationContract
    {
        return view('manager.unassigned');
    }

    public function results(): View|Application|Factory|ApplicationContract
    {
        $user    = Auth::user();
        $process = $user->streams()
                        ->where(['id' => $user->process])
                        ->first()
                        ->process()
                        ->first();


        $process = unserialize($process->values);

        $host  = $process['kafka']['outputHost'];
        $topic = $process['kafka']['outputTopic'];

        if (strpos($topic, '{')) {
            $topic = str_replace('{username}', $user->name, $topic);
        }

        return view('manager.results')
            ->with('host', $host)
            ->with('topic', $topic)
            ->with('group', 'manticoreviewer')
            ->with('stream', $user->process);
    }

    public function kafkaResults(KafkaConnection $kafkaConnection, Request $request): JsonResponse
    {
        $messages = [];
        $kafkaConnection->connect($request->get('host'), $request->get('topic'), $request->get('group'));

        $message = $kafkaConnection->consume(10 * 1000);
        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                $messages[] = $message->payload;

                break;
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                break;
            default:
                return response()->json(['message' => $message->errstr()], 500);
        }

        return response()->json($messages);
    }
}
