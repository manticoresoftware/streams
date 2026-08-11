<?php

namespace App\Services;


use App\Models\Processes;
use App\Models\Streams;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

class StreamsService
{

    protected $kubeService;

    protected $id;
    protected $stream;
    protected $config;
    protected $namespace;
    protected $releaseName;
    protected $fullPackageName;
    protected $packageName;
    protected $processId = '';
    protected $processName = '';
    protected $url;

    public const YAML_SOURCE = 'yamlSource';


    protected $generatedResources = [];
    protected $errors = [];


    protected $apiSections
        = [
            'horizontalpodautoscalers' => 'apis/autoscaling/v2beta1',
            'configmaps' => 'api/v1',
            'deployments' => 'apis/apps/v1',
            'services' => 'api/v1',
            'statefulsets' => 'apis/apps/v1',
            'persistentvolumeclaims' => 'api/v1',
            'secrets' => 'api/v1',
        ];

    /**
     * @var array|false|string
     */
    protected $columnarPort;
    /**
     * @var array|false|string
     */
    protected $releaseService;
    /**
     * @var array|false|string
     */
    protected $chartName;

    /**
     * SphinxQL constructor
     *
     * @param  KubeService  $kubeService
     * @param  null  $stream
     * @param  array  $config
     */
    public function __construct(
        KubeService $kubeService,
        Streams $stream,
        array $config = []
    )
    {
        $this->url = env('K8S_URL',
                'https://kubernetes.default.svc').'/{{API-VERSION}}/namespaces/{{NAMESPACE}}/{{API-SECTION}}';
        $this->kubeService = $kubeService;

        $this->stream = $stream;
        $this->id = $stream->id;

        $config['worker']['suspend'] = $this->stream->stopped;
        $this->config = $this->getDefaultConfig($config);
        $this->namespace = env('NAMESPACE', 'manticore-streams');
        $this->columnarPort = getenv('COLUMNAR_PORT');
        $this->releaseName = getenv('RELEASE_NAME');
        $this->fullPackageName = getenv('FULL_PACKAGE_NAME');
        $this->packageName = getenv('PACKAGE_NAME');
        $this->releaseService = getenv('RELEASE_SERVICE');
        $this->chartName = getenv('CHART_NAME');
    }

    public function makeStream(): void
    {
        $this->generateResources();
        $this->applyResources();
    }

    protected function generateResources(): void
    {
        $templates = Storage::disk(self::YAML_SOURCE)->allFiles();
        $this->generateYamls($templates);
    }

    protected function generateYamls($templates)
    {
        $i = 0;
        foreach ($templates as $template) {
            if ($template === "configmap-searchd.yaml") {
                continue;
            }
            $resourceTemplate = $this->generateResource($template);
            if (!$resourceTemplate) {
                continue;
            }
            $i++;
            $this->generatedResources[$i] = $resourceTemplate;
        }
    }

    protected function applyResources()
    {
        foreach ($this->generatedResources as $resource) {
            $this->applyYaml($resource);
        }
    }

    protected function prepareUrl($type)
    {
        return str_replace(['{{API-VERSION}}', '{{NAMESPACE}}', '{{API-SECTION}}'],
            [$this->apiSections[$type], $this->namespace, $type], $this->url);
    }

    /**
     * @throws FileNotFoundException
     */
    protected function applyYaml($yaml): bool
    {
        $url = $this->prepareUrl($yaml['type']);
        $result = $this->kubeService->sendFile($url, $yaml['content']);

        if ($result['status'] === CurlService::STATUS_ERROR) {
            if (strpos($result['message'], 'AlreadyExists') !== false) {
                return $this->replaceResource($url, $this->parseYaml($yaml['content']));
            }

            $this->errors[] = $this->kubeService->getError();
        }

        return true;
    }


    protected function replaceResource($url, $yaml): bool
    {
        $url .= '/'.$yaml['metadata']['name'];

        $existedResource = $this->kubeService->get($url);
        if ($existedResource['status'] === CurlService::STATUS_SUCCESS) {
            $existedResource = $existedResource['result'];

            $existedResource = array_merge($existedResource, $yaml);
            $result = $this->kubeService->replaceRequest($url, $this->emitYaml($existedResource));

            if ($result['status'] === CurlService::STATUS_ERROR) {
                $this->errors[] = $this->kubeService->getError();

                return false;
            }

            return true;
        }

        $this->errors[] = $this->kubeService->getError();

        return false;
    }

    public function removeStream(): array
    {
        $status = 'success';
        $forRemoving = [];
        foreach (['deployments', 'services', 'statefulsets', 'persistentvolumeclaims'] as $item) {
            $url = $this->prepareUrl($item);
            $allResourceList = $this->kubeService->get($url);

            if ($allResourceList['status'] === CurlService::STATUS_SUCCESS) {
                foreach ($allResourceList['result']['items'] as $resource) {
                    if (isset($resource['metadata']['labels']['streamID'])
                        && $resource['metadata']['labels']['streamID'] == $this->id
                    ) {
                        $forRemoving[$item][] = $resource["metadata"]["name"];
                    }
                }
            }
        }


        foreach ($forRemoving as $type => $names) {
            foreach ($names as $name) {
                $result = $this->removeObject($type, $name);

                if ($result['status'] === CurlService::STATUS_ERROR) {
                    $status = 'error';
                    $this->errors[] = $this->kubeService->getError();
                }
            }
        }

        return ['status' => $status, 'message' => $this->errors];
    }

    protected function removeObject($type, $name): array
    {
        $url = $this->prepareUrl($type);
        $url .= '/'.$name;

        return $this->kubeService->remove($url);
    }

    public function suspendHandler()
    {
        return $this->suspend(1);
    }

    public function resumeHandler()
    {
        return $this->suspend(0);
    }

    private function suspend($value)
    {
        $name = "$this->releaseName-m$this->id-pipeline";
        $host = "https://kubernetes.default.svc/apis/apps/v1/namespaces/$this->namespace/statefulsets/$name";

        $content = [
            'spec' => [
                'template' => [
                    'spec' => [
                        'containers' => [
                            [
                                'env' => [
                                    [
                                        "name" => 'SUSPEND',
                                        'value' => (string) $value,
                                    ],
                                ],
                                'name' => $this->packageName.'-m'.$this->id.'-worker',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->kubeService->patchRequest($host, json_encode($content));
    }


    /**
     */
    protected function generateResource($file): false|array
    {
        $exists = Storage::disk(self::YAML_SOURCE)->exists($file);

        if ($exists) {
            $content = Storage::disk(self::YAML_SOURCE)->get($file);

            $content = $this->substituteContent($content);
            if (empty($content)) {
                return false;
            }

            $content = $this->parseYaml($content);

            $result['type'] = strtolower($content['kind']).'s';
            $result['content'] = $this->emitYaml($content);

            return $result;
        }

        return false;
    }


    protected function parseLogicBlocs($content): string
    {
        $result = "";
        $endRegex = '/{{-\s?if\s?Values\.([a-z0-9.]+)\s?}}/usi';

        if (preg_match($endRegex, $content, $match, PREG_OFFSET_CAPTURE)) {
            $ifBeforePosition = (int) $match[0][1];
            $ifAfterPosition = $ifBeforePosition + strlen($match[0][0]);

            $result .= substr($content, 0, $ifBeforePosition);
            $blockEndPosition = $this->getEndConditionPosition($content,
                $ifAfterPosition);


            if ($this->strToValue(trim($match[1][0]))) {
                $ifBlockContext = substr($content, $ifAfterPosition,
                    $blockEndPosition['position'] - $ifAfterPosition);
                $result .= $this->parseLogicBlocs($ifBlockContext);
            }

            $result .= $this->parseLogicBlocs(substr($content,
                $blockEndPosition['position'] + $blockEndPosition['length']));
        } else {
            $result .= $content;
        }

        return $result;
    }

    protected function getEndConditionPosition($content, $index): array
    {
        $currentIndex = $index;
        $nestedBlocksCount = 0;
        $endRegex = '/{{-\s?end\s?}}/usi';
        $ifRegex = '/{{-\s?if\s?Values\.([a-z0-9.]+)\s?}}/usi';

        $nextEndPos = preg_match($endRegex, $content, $endMatch,
            PREG_OFFSET_CAPTURE, $currentIndex)
            ? (int) $endMatch[0][1] + strlen($endMatch[0][0])
            : throw new \RuntimeException("Can't get end of the block");
        $endLength = strlen($endMatch[0][0]);

        while (true) {
            $croppedContent = substr($content, $currentIndex,
                $nextEndPos - $currentIndex);
            if (preg_match_all($ifRegex, $croppedContent, $ifMatch,
                    PREG_OFFSET_CAPTURE)
                || $nestedBlocksCount > 0
            ) {
                $nestedBlocksCount += count($ifMatch[0]);


                $currentIndex = $nextEndPos;
                $nextEndPos = preg_match($endRegex, $content, $endMatch,
                    PREG_OFFSET_CAPTURE, $nextEndPos)
                    ? (int) $endMatch[0][1] + $endLength
                    : throw new \RuntimeException("Can't get end of the block");

                $nestedBlocksCount--;
            } else {
                break;
            }
        }

        return ['position' => $nextEndPos - $endLength, 'length' => $endLength];
    }



    protected function substituteContent($content)
    {
        $content = $this->parseLogicBlocs($content);

        $lines = explode("\n", $content);
        foreach ($lines as $k => $line) {
            if (empty(trim($line))) {
                unset($lines[$k]);
            }

            $line = str_replace([
                '{{ FULLNAME }}',
                '{{ STREAM_ID }}',
                '{{ NAME }}',
                '{{ RELEASE_NAME }}',
                '{{ NAMESPACE }}',
                '{{ METRICS_STORAGE_SERVICE_PORT }}',
                '{{ RELEASE_SERVICE }}',
                '{{ CHART_NAME }}',
                '{{ PROCESS }}',
                '{{ PROCESSING_NAME }}',
            ],
                [
                    $this->fullPackageName,
                    $this->id,
                    $this->packageName,
                    $this->releaseName,
                    $this->namespace,
                    $this->columnarPort,
                    $this->releaseService,
                    $this->chartName,
                    $this->processId,
                    $this->processName,
                ], $line);

            $lines[$k] = $line;

            if (preg_match_all('/{{\s?Values\.(.*?)}}/usi', $lines[$k], $matches)) {
                if (!empty($matches[1][0])) {
                    foreach ($matches[1] as $kk => $match) {
                        $val = $this->strToValue($match);
                        $lines[$k] = str_replace($matches[0][$kk], $val, $lines[$k]);
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function getDefaultConfig($defaultConfig)
    {
        $config = Storage::disk('config')->get('process.json');

        if (!empty($defaultConfig)) {
            $config = json_decode($config, true);
            foreach ($config as $section => $data) {
                if (isset($defaultConfig[$section])) {
                    $config[$section] = array_merge($config[$section], $defaultConfig[$section]);
                }
            }
        }

        return $config;
    }


    protected function emitYaml($data): string
    {
        return Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    protected function parseYaml($data)
    {
        return Yaml::parse($data);
    }

    public function getId()
    {
        return $this->id;
    }

    private function strToValue($text)
    {
        $format = false;
        $offset = false;
        $separator = '| toYaml';
        if (strpos($text, $separator) !== false) {
            $exploded = explode($separator, $text);
            $text = trim($exploded[0]);

            if ($exploded[1]) {
                $format = true;
                $offset = (int) $exploded[1];
            }
        }

        $keys = explode('.', $text);

        $val = null;
        foreach ($keys as $key) {
            $key = trim($key);
            if (is_null($val)) {
                $val = $this->config[$key];
            } else {
                if (!isset($val[$key])) {
                    $val = '{}';
                    continue;
                }
                $val = $val[$key];
            }
        }

        if ($format && $offset) {
            if (is_array($val)) {
                $val = $this->encodeYaml($val, $offset);
            }
        }

        return $val;
    }

    public function encodeYaml($array, $indent)
    {
        $result = '';

        foreach ($array as $key => $item) {
            if (is_array($item)) {
                $result .= "\n".str_repeat(" ", $indent).$key.': ';
                $result .= $this->encodeYaml($item, $indent + 2);
                continue;
            }

            $result .= "\n".str_repeat(" ", $indent).$key.': '.$item;
        }

        return $result;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function upgradeStream()
    {
        $this->generateResources();
        $this->applyResources();
    }

    public function setProcessId(int $processId)
    {
        $this->processId = $processId;
        $this->processName = Processes::find($processId)->name;
    }

    public function createConfigmap($rtInclude, $searchdInclude = '', $stopwords = '', $exceptions = ''): void
    {
        if (!$this->processId) {
            throw new \Exception("You don't specify process id");
        }

        $this->config['manticore']['include']['searchd'] = str_replace("\n", "\n      ", $searchdInclude);
        $this->config['manticore']['include']['rt'] = str_replace("\n", "\n      ", $rtInclude);
        $this->config['manticore']['include']['stopwords'] = str_replace("\n", "\n      ", $stopwords);
        $this->config['manticore']['include']['exceptions'] = str_replace("\n", "\n      ", $exceptions);
        $resourceTemplate = $this->generateResource('configmap-searchd.yaml');
        $this->applyYaml($resourceTemplate);
    }

    public function getConfigmap($processId)
    {
        $url = $this->prepareUrl('configmaps');
        $configmap = $this->kubeService->get($url.'/configmap-p'.$processId);

        if (isset($configmap['result']['data'])) {
            return $configmap['result']['data'];
        }

        return [];
    }

    public function removeConfigmap($name): void
    {
        $this->removeObject('configmaps', $name);
    }

    public function redeployPipeline(): array
    {
        /// {"spec":{"template":{"metadata":{"annotations":{"kubectl.kubernetes.io/restartedAt":"2022-05-18T14:49:55+03:00"}}}}}
        $url = $this->prepareUrl('statefulsets')."/$this->releaseName-m$this->id-pipeline";
        $content = [
            'spec' => [
                'template' => [
                    'metadata' => [
                        'annotations' => [
                            'kubectl.kubernetes.io/restartedAt' => Carbon::now()->toIso8601String()
                        ]
                    ]
                ],
            ],
        ];

        $request = $this->kubeService->patchRequest($url, json_encode($content));
        if ($request['status'] !== CurlService::STATUS_SUCCESS) {
            $this->errors[] = $request['message'];
        }
        return $request;
    }
}
