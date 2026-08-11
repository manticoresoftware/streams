<?php

namespace App\Http\Controllers;

use App\Console\Commands\KafkaConsumeMessages;
use App\Http\Requests\ProcessCreateRequest;
use App\Models\Destination;
use App\Models\Processes;
use App\Models\Role;
use App\Models\Source;
use App\Models\Streams;
use App\Models\User;
use App\Providers\ProcessCreationService;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Artisan;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class ProcessController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:' . Role::ROLE_ADMIN);
    }

    public function index(): View|Application|Factory|ApplicationContract
    {
        return view('admin.process.index');
    }

    public function getGoals(): View|Application|Factory|ApplicationContract
    {
        $sources = Source::select(['id', 'name'])->get();
        $destinations = Destination::select(['id', 'name'])->get();

        return view('admin.process.goals', [
            'sources' => $sources,
            'destinations' => $destinations
        ]);
    }

    public function resolveHosts(Request $request): JsonResponse
    {
        $source = Source::select([
            'id',
            'name',
            'host',
            'topic',
            'group',
        ])->find($request->get('source'));

        $destination = Destination::select([
            'id',
            'name',
            'host',
            'topic',
            'group',
        ])->find($request->get('destination'));

        return response()->json(
            [
                'source' => $source,
                'destination' => $destination
            ]
        );
    }

    public function newProcess(Request $request
    ): View|Application|Factory|ApplicationContract {
        $conf = [];
        if ($request->has('process_id')) {
            $process = Processes::where(['id' => $request->get('process_id')])
                ->with(['source', 'destination'])->get()->first();
            $process = $process->toArray();
            $process['values'] = unserialize($process['values']);
            $conf['process'] = $process;
        }

        return view('admin.process.new', $conf);
    }

    public function getProgress(): View|Application|Factory|ApplicationContract
    {
        return view('admin.process.progress');
    }

    public function parseSchema(Request $request): JsonResponse
    {
        ini_set('max_execution_time', 120);

        $execute = Artisan::call('kafka:consume', [
            'host' => $request->get('host'),
            'group' => $request->get('group'),
            'topic' => $request->get('topic'),
        ]);

        return response()->json($execute);
    }

    public function getSchema(): JsonResponse
    {
        $results = [];
        if (Storage::exists(KafkaConsumeMessages::RESULTS_FILENAME)) {
            $results = \Storage::get(KafkaConsumeMessages::RESULTS_FILENAME);
            $results = json_decode($results, true);
        }

        return response()->json($results);
    }

    public function getList(): JsonResponse
    {
        $data = Processes::select([
            'id', 'name', 'source_id', 'destination_id'
        ]);

        return Datatables::of($data)
            ->addColumn('user', function ($row) {
                $users = "-";
                if (!$row->streams()->get()->isEmpty()) {
                    $multiplied = $row->streams()->get()->map(function ($row) {
                        return $row->user->email;
                    });

                    $users = implode("<br>", $multiplied->all());
                }

                return $users;
            })
            ->addColumn('source', function ($row) {
                if (isset($row->source->name)) {
                    return $row->source->name;
                }

                return "<span class='text-danger'>Not setted</span>";
            })
            ->addColumn('destination', function ($row) {
                if (isset($row->destination->name)) {
                    return $row->destination->name;
                }

                return "<span class='text-danger'>Not setted</span>";
            })
            ->addColumn('action', function ($row) {
                $html = '';

                if (isset($row->source->name)
                    && isset($row->destination->name)
                ) {
                    $html .= '<button type="button" class="btn btn-success btn-sm j-assign-user" data-id="'
                        .
                        $row->id . '" data-name="' . $row->name
                        . '">Assign</button>';
                }

                if (!$row->streams()->get()->isEmpty()) {
                    $html .= '<button type="button" class="btn btn-primary btn-sm j-unassign-user"
                                 data-id="' . $row->id . '" data-name="'
                        . $row->name . '">Unassign</button>';

                    $stoppedStreams = 0;
                    $streamings = $row->streams()->get();
                    foreach ($streamings as $streaming) {
                        if ($streaming->stopped) {
                            $stoppedStreams++;
                        }
                    }

                    if ($stoppedStreams < $streamings->count()) {
                        $html .= '<button type="button" class="btn btn-danger btn-sm j-streaming-actions"
                                 data-id="' . $row->id . '" data-name="'
                            . $row->name
                            . '" data-action="suspend">Suspend</button>';
                    }

                    if ($stoppedStreams > 0) {
                        $html .= '<button type="button" class="btn btn-success btn-sm j-streaming-actions"
                                 data-id="' . $row->id . '" data-name="'
                            . $row->name
                            . '" data-action="resume">Resume</button>';
                    }
                }

                $html .= '<button type="button" class="btn btn-warning btn-sm j-edit-process" data-id="'
                    .
                    $row->id . '">Edit</button>';

                $html .= '<button type="button" data-name="' . $row->name
                    . '" data-id="' . $row->id . '" data-toggle="modal" ' .
                    'data-target="#confirm-delete" class="btn btn-danger btn-sm j-delete-process">Delete</button>';

                return $html;
            })
            ->removeColumn('user_id')
            ->removeColumn('source_id')
            ->removeColumn('destination_id')
            ->rawColumns(['user', 'source', 'destination', 'action'])
            ->make();
    }

    /**
     * @throws \Exception
     */
    public function add(
        ProcessCreateRequest $request,
        ProcessCreationService $processCreationService
    ): JsonResponse {
        $kafkaConfig = $request->getKafkaConfig();

        $backupConf = [];
        $attrs = $request->get('attrs');
        $attrs = urldecode($attrs);
        $backupConf['attrs'] = $attrs;
        $attrs = json_decode($attrs, true);

        $jsltConf = '';
        if ($request->exists('jslt_conf')) {
            $jsltConf = $request->get('jslt_conf');
            if (!empty(trim($jsltConf))) {
                $backupConf['jslt_conf'] = $jsltConf;
                $jsltConf = urldecode($jsltConf);
                $jsltConf = str_replace(['"', "\n"], ['\"', "\\n"], $jsltConf);
                $jsltConf = trim($jsltConf);
            }
        }

        $source = Source::find($request->get('source_id'));
        $destination = Destination::find($request->get('destination_id'));

        if (!$source) {
            $errors['source_id'] = ['The selected source is invalid'];
        }
        if (!$destination) {
            $errors['destination_id'] = ['The selected destination is invalid'];
        }
        if (!empty($errors)) {
            return response()->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $types = [
            'string' => 'text',
            'bool' => 'bool',
            'float' => 'float',
            'int' => 'integer',
            'bigint' => 'bigint',
            'timestamp' => 'timestamp',
            'json' => 'json',
            'url' => 'url',
            'json[]' => 'json',
        ];

        $formatRules = [];
        $manticoreRules = [];
        foreach ($attrs as $attr) {
            $name = $attr['name'];
            $path = $attr['path'];

            if (str_ends_with($path, "&&")) {
                $path = substr($path, 0, -2);
            }

            $path = preg_replace('[^a-zA-Z0-9\-_\[\].]', '', $path);
            if (preg_match('/^(([a-zA-Z0-9\-_\[\]])+\.?)+$/', $name) !== false) {
                $name = str_replace('.', '_', $name);
                $formatRules[] = $path . ' => ' . $name;
                $manticoreRules[$name] = $types[$attr['type']] . '=' . $name;
            }
        }

        $outputDocs = $request->get('output_docs');
        if (!preg_match('/[0-1]{4}/', $outputDocs)) {
            $outputDocs = '0010';
        }

        $backupConf['output_docs'] = $outputDocs;

        $config = [
            'kafka' => [
                'inputHost' => $source->host,
                'outputHost' => $destination->host,
                'inputTopic' => $source->topic,
                'outputTopic' => $destination->topic,
                'groupName' => $source->group,
                'fetch.min.bytes' => $kafkaConfig['fetch_min_bytes'],
                'fetch.max.wait.ms' => $kafkaConfig['fetch_max_wait_ms'],
                'fetch.max.bytes' => $kafkaConfig['fetch_max_bytes'],
                'max.poll.records' => $kafkaConfig['max_poll_records'],
            ],
            'worker' => [
                'outputDocs' => $outputDocs,
                'jsltEnabled' => !empty($jsltConf),
                'jsltConf' => $jsltConf,
            ],
        ];

        // Store Kafka config in backupConf for user_request
        $backupConf['kafka_config'] = [
            'fetch.min.bytes' => $config['kafka']['fetch.min.bytes'],
            'fetch.max.wait.ms' => $config['kafka']['fetch.max.wait.ms'],
            'fetch.max.bytes' => $config['kafka']['fetch.max.bytes'],
            'max.poll.records' => $config['kafka']['max.poll.records'],
        ];

        $queryComplexityValidation = $request->get('query_complexity_validation');
        if ($queryComplexityValidation) {
            $config['rulesChecker']['enabled'] = true;
            $config['rulesChecker']['maxMatchedPercent'] = (int) $request->get('max_matches_percent');
            $backupConf['query_complexity_validation']['enabled'] = true;
            $backupConf['query_complexity_validation']['max_matches_percent'] = $config['rulesChecker']['maxMatchedPercent'];
        }

        if (!empty($formatRules)) {
            $config['worker']['handlerRules'] = implode("|", $formatRules);
        }

        if (!empty($manticoreRules)) {
            $config['manticore']['configAdditiveFields'] = implode("|", $manticoreRules);
        }

        $searchdConfig = $request->get('searchd_settings');
        if (!empty($searchdConfig)) {
            $backupConf['searchd_settings'] = urldecode($searchdConfig);
            $config['manticore']['searchd'] = json_decode($backupConf['searchd_settings'], true);
        }

        $morphology = config('morphology');
        $language = $request->get('language');
        $backupConf['language'] = $language;
        $language = explode(',', $language);

        if (in_array('custom', $language)) {
            $language = ['custom'];
        }

        $morphology = $processCreationService->formatMorphology($language, $morphology);

        $config['worker']['minThreads'] = $request->get('min_threads') ?? 1;
        $config['worker']['maxThreads'] = $request->get('max_threads');
        $config['worker']['maxBatchSize'] = $request->get('max_batch_size');

        $backupConf['min_threads'] = $config['worker']['minThreads'];
        $backupConf['max_threads'] = $config['worker']['maxThreads'];
        $backupConf['max_batch_size'] = $config['worker']['maxBatchSize'];

        $nlpSettings = '';
        $stopWords = '';
        $exceptions = '';

        if ($language === ['custom']) {
            if ($nlpSettings = $request->get('nlp_settings')) {
                if (!empty(trim($nlpSettings))) {
                    $nlpSettings = $processCreationService->decodeQuote($nlpSettings);
                    $backupConf['nlp_settings'] = $nlpSettings;
                }
            }

            if ($stopWords = $request->get('stopwords')) {
                if (!empty(trim($stopWords))) {
                    $stopWords = $processCreationService->decodeQuote($stopWords);
                    $backupConf['stopwords'] = $stopWords;
                    $nlpSettings = $processCreationService->formatStopWords($nlpSettings);
                }
            }

            if ($exceptions = $request->get('exceptions')) {
                if (!empty(trim($exceptions))) {
                    $exceptions = $processCreationService->decodeQuote($exceptions);
                    $backupConf['exceptions'] = $exceptions;
                    $nlpSettings = $processCreationService->formatExceptions($nlpSettings);
                }
            }
        }

        if (empty($nlpSettings)) {
            $nlpSettings = implode(" ", $morphology);
        }

        $config['user_request'] = $backupConf;

        $updated = false;
        if ($request->has('id')) {
            $updated = true;
            $newProcess = Processes::find($request->get('id'));

            $newProcess->name = $request->get('name');
            $newProcess->source_id = $request->get('source_id');
            $newProcess->destination_id = $request->get('destination_id');
            $newProcess->values = serialize($config);
            $newProcess->save();
        } else {
            $newProcess = Processes::create([
                'name' => $request->get('name'),
                'source_id' => $request->get('source_id'),
                'destination_id' => $request->get('destination_id'),
                'values' => serialize($config),
            ]);
        }

        /** @var StreamsService $streamsService */
        $streamsService = app(StreamsService::class);
        $streamsService->setProcessId($newProcess->id);
        $streamsService->createConfigmap($nlpSettings, '', $stopWords, $exceptions);
        $errors = $streamsService->getErrors();
        if ($errors !== [] && \App::environment() !== 'dev') {
            return response()->json([
                'errors' => [
                    'nlp_settings' => 'NLP Configmap creation error:' . implode("\n", $errors)
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($updated) {
            Artisan::call('process:update', [
                'process_id' => $newProcess->id,
                'force' => 1
            ]);
        }

        return response()->json(
            ['status' => 'success', 'id' => $newProcess->id]
        );
    }

    public function getToAssignUsersList(Request $request): JsonResponse
    {
        $roleManager = Role::where('name', Role::ROLE_MANAGER)->first();

        $unassignedUsers = DB::table('users')->select(['id', 'email'])
            ->where(['role_id' => $roleManager->id])
            ->whereNull('deleted_at')
            ->whereNotIn('id',
                Streams::select('user_id')
                    ->where(['process_id' => $request->get('process_id')]))
            ->distinct()->get();

        return response()->json($unassignedUsers);
    }

    public function getToUnassignUsersList(Request $request): JsonResponse
    {
        $assignedContexts = Streams::where(['process_id' => $request->get('process_id')])
            ->get();

        $assignedUsers = $assignedContexts->map(function ($row) {
            return ['id' => $row->user->id, 'email' => $row->user->email];
        });

        return response()->json($assignedUsers->all());
    }

    public function assignUser(
        KubeService $curlService,
        Request $request
    ): JsonResponse {
        $streamId = '';
        $processId = $request->get('process_id');
        $statusCode = Response::HTTP_OK;
        $message = 'The user has been assigned to process';
        $user = User::find($request->get('assign_user'));
        $process = Processes::find($processId);
        if (empty($user)) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = 'Can\'t find user';
        } elseif (empty($process)) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = 'Can\'t find process';
        } elseif ($user->streams()->get()
            ->firstWhere('process_id', '=', $processId)
        ) {
            $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;
            $message = 'Selected user already have assigned to this process';
        } else {
            DB::beginTransaction();
            try {
                $values = unserialize($process->values);

                // Merge kafka_config from user_request into main config for StreamsService
                if (isset($values['user_request']['kafka_config']) && is_array($values['user_request']['kafka_config'])) {
                    $kafkaConfig = $values['user_request']['kafka_config'];

                    // Map the kafka_config values to the expected structure for StreamsService
                    // Use underscores instead of dots to work with strToValue method
                    $values['kafka']['fetch_min_bytes'] = $kafkaConfig['fetch.min.bytes'] ?? $values['kafka']['fetch_min_bytes'];
                    $values['kafka']['fetch_max_wait_ms'] = $kafkaConfig['fetch.max.wait.ms'] ?? $values['kafka']['fetch_max_wait_ms'];
                    $values['kafka']['fetch_max_bytes'] = $kafkaConfig['fetch.max.bytes'] ?? $values['kafka']['fetch_max_bytes'];
                    $values['kafka']['max_poll_records'] = $kafkaConfig['max.poll.records'] ?? $values['kafka']['max_poll_records'];
                }

                if (str_contains($values['kafka']['inputTopic'], "{username}")
                ) {
                    $values['kafka']['inputTopic'] = str_replace("{username}",
                        $user->name,
                        $values['kafka']['inputTopic']);
                }

                if (str_contains($values['kafka']['outputTopic'], "{username}")
                ) {
                    $values['kafka']['outputTopic'] = str_replace("{username}",
                        $user->name,
                        $values['kafka']['outputTopic']);
                }

                if (str_contains($values['kafka']['groupName'], "{username}")
                ) {
                    $values['kafka']['groupName'] = str_replace("{username}",
                        $user->name,
                        $values['kafka']['groupName']);
                }

                $stream = Streams::create([
                    'user_id' => $user->id,
                    'process_id' => $process->id,
                    'stopped' => 0
                ]);
                $streamId = $stream->id;

                $user->process = $streamId;
                $user->save();

                $streamService = new StreamsService($curlService, $stream, $values);
                $streamService->setProcessId($process->id);

                $streamService->makeStream();
                if ($streamService->getErrors()) {
                    throw new \Exception('Error user assigning: <br>' . implode('<br>',
                            $streamService->getErrors()));
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = $e->getMessage() . ' ' . $e->getTraceAsString();

                if (isset($streamService)) {
                    $streamServiceErrors = $streamService->getErrors();
                    if ($streamServiceErrors !== []) {
                        $errors = array_merge($errors, $streamServiceErrors);
                    }
                }
                $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
                $message = implode('Uncaught exception <br>', $errors);
            }
        }

        return response()->json([
            'message' => $message,
            'id' => $streamId,
        ], $statusCode);
    }

    public function unassignUser(
        KubeService $kubeService,
        Request $request
    ): JsonResponse {
        $statusCode = Response::HTTP_OK;
        $message = 'User was successfully unassigned';
        $contextRecord = null;
        $user = User::find($request->get('unassign_user'));
        $process = Processes::find($request->get('process_id'));

        if ($process && $user) {
            if ($process->has('streams')) {
                $contextRecord = $process->streams()->get()
                    ->firstWhere('user_id', '=', $user->id);
            }
        }

        if (empty($process)) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = "Can't find process";
        } elseif (empty($user)) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = "Can't find user";
        } elseif (!$contextRecord) {
            $statusCode = Response::HTTP_FORBIDDEN;
            $message = "Selected user doesn't allocated to this process";
        } else {
            $context = new StreamsService($kubeService, $contextRecord, []);
            $context->removeStream();

            if (!empty($context->getErrors())
                && env('APP_ENV') === 'production'
            ) {
                $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
                $message = 'Error user while user unassigning: <br>'
                    . implode('<br>', $context->getErrors());
            } else {
                if ($contextRecord->id == $user->process) {
                    $assignedProcess = $user->streams()->first();
                    if ($assignedProcess) {
                        $user->process = $assignedProcess->id;
                    } else {
                        $user->process = null;
                    }
                    $user->save();
                }

                $contextRecord->delete();
            }
        }

        return response()->json([
            'message' => $message,
        ], $statusCode);
    }

    public function remove($id, KubeService $curlService): JsonResponse
    {
        $statusCode = Response::HTTP_OK;
        $message = 'Process was successfully removed';
        $process = Processes::find($id);
        if (empty($process)) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = 'Can\'t find process';
        } else {
            $errors = [];
            foreach ($process->streams()->get() as $stream) {
                $context = new StreamsService($curlService, $stream, []);
                $context->removeStream();

                if (!empty($context->getErrors())
                    && env('APP_ENV') === 'production'
                ) {
                    $errors[] = implode('<br>', $context->getErrors());
                } else {
                    $stream->delete();
                    $user = $stream->user;
                    if ($stream->id == $user->process) {
                        $assignedProcess = $user->streams()->first();
                        if ($assignedProcess) {
                            $user->process = $assignedProcess->id;
                        } else {
                            $user->process = null;
                        }
                        $user->save();
                    }
                }
            }

            /** @var StreamsService $streamsService */
            $streamsService = app(StreamsService::class);

            $streamsService->removeConfigmap("configmap-p" . $process->id);

            if ($streamsService->getErrors() !== []) {
                $errors[] = $streamsService->getErrors();
            }

            if (!empty($errors)) {
                $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
                $message = 'Error while process removing: <br>'
                    . implode('<br><hr><br>', $errors);
            } else {
                $process->delete();
            }
        }

        return response()->json(['message' => $message], $statusCode);
    }

    public function getExtendedProcessInfo($id): JsonResponse
    {
        $process = Processes::findOrFail($id);

        $processArray = $process->toArray();
        $processArray['values'] = unserialize($process->values);
        $processArray['streams'] = $process->streams()->get();
        $processArray['source'] = $process->source()->get();
        $processArray['destination'] = $process->destination()->get();

        return response()->json($processArray);
    }

    public function getUserStreams(Request $request): JsonResponse
    {
        if (empty($request->get('user_id'))) {
            return response()->json([
                'message' => "Pass the user id",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contexts = Streams::where(['user_id' => $request->get('user_id')]);
        if (!empty($request->get('process_id'))) {
            $contexts = $contexts->where([
                'process_id' => $request->get('process_id')
            ]);
        }
        $contexts = $contexts->with('process')->get();
        $results = [];

        foreach ($contexts as $context) {
            $results[] = [
                'user_id' => $context->user_id,
                'process_id' => $context->process_id,
                'process_name' => $context->process->name,
                'streamId' => $context->id,
            ];
        }

        return response()->json($results);
    }

    public function getSuspendList(Request $request): JsonResponse
    {
        $contexts = [];
        $rawContexts = Streams::where(['stopped' => 0])
            ->where(['process_id' => $request->get('process_id')])->get();
        foreach ($rawContexts as $item) {
            $userName = $item->user()->get('name')->toArray();
            $contexts[] = [
                'streamId' => $item->id, 'name' => $userName[0]['name']
            ];
        }

        return response()->json($contexts);
    }

    public function getResumeList(Request $request): JsonResponse
    {
        $contexts = [];
        $rawContexts = Streams::where(['stopped' => 1])
            ->where(['process_id' => $request->get('process_id')])->get();
        foreach ($rawContexts as $item) {
            $userName = $item->user()->get('name')->toArray();
            $contexts[] = [
                'streamId' => $item->id, 'name' => $userName[0]['name']
            ];
        }

        return response()->json($contexts);
    }

    public function suspend(Request $request, KubeService $curlService): JsonResponse
    {
        if (!$request->get('streamId')) {
            return response()->json([
                'message' => 'No stream id was specified',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stream = Streams::find($request->get('streamId'));

        if (!$stream) {
            return response()->json([
                'message' => 'Can\'t find streaming',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($stream->stopped) {
            return response()->json([
                'message' => 'Streaming already suspended',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contextService = new StreamsService($curlService, $stream, []);
        $result = $contextService->suspendHandler();

        if ($result['status'] === CurlService::STATUS_SUCCESS) {
            $stream->stopped = 1;
            $stream->save();
            $message = "Success";
        } else {
            $message = json_encode($result);
        }

        return response()->json([
            'message' => $message,
        ]);
    }

    public function resume(Request $request, KubeService $curlService): JsonResponse
    {
        if (!$request->get('streamId')) {
            return response()->json([
                'message' => 'No stream id was specified',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $streams = Streams::find($request->get('streamId'));

        if (!$streams) {
            return response()->json([
                'message' => 'Can\'t find streaming',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$streams->stopped) {
            return response()->json([
                'message' => 'Streaming already resumed',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contextService = new StreamsService($curlService, $streams, []);
        $result = $contextService->resumeHandler();

        if ($result['status'] === CurlService::STATUS_SUCCESS) {
            $streams->stopped = 0;
            $streams->save();
            $message = "Success";
        } else {
            $message = json_encode($result);
        }

        return response()->json([
            'message' => $message,
        ]);
    }
}
