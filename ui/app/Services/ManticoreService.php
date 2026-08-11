<?php

namespace App\Services;


use App;
use App\Models\Rule;
use App\Models\Streams;
use App\Models\Variable;
use App\Services\Curl\CurlService;
use Exception;
use mysqli;
use Symfony\Component\HttpFoundation\Response;


class ManticoreService extends BaseManticoreService
{
    public const BATCH_SIZE = 20000;

    private array $fields = ['id', 'query', 'filters'];

    private $queryValidation;

    private $urlFields = null;

    private $lockId = 0;

    private int $statusCode = 200;

    private ColumnarService $columnarService;

    private $upperCaseOperators
        = [
            'AND',
            'OR',
            'MAYBE',
            'NEAR',
            'NOTNEAR',
            'SENTENCE',
            'PARAGRAPH',
            'ZONE',
            'ZONESPAN',
        ];

    private string $streamId;

    /**
     * SphinxQL constructor
     *
     * @param $streamId
     */
    public function __construct($streamId)
    {
        $this->setStream($streamId);
    }

    public function setStream($streamId): void
    {
        $this->streamId = "m".$streamId;

        $environment = app()->environment();
        if ($environment === 'dev' || $environment === 'testing') {
            $this->streamId        = 'm';
            $this->queryValidation = false;
        } else {
            $stream = Streams::find($streamId);
            if ( ! $stream) {
                $this->error = "Selected user don't have any processes. Can't init Manticore";

                return;
            }
            $process         = $stream->process;
            $process->values = unserialize($process->values);

            if (isset($process->values['rulesChecker']['enabled'])) {
                $this->queryValidation = $process->values['rulesChecker']['enabled'];
            }
        }

        $this->columnarService = app(ColumnarService::class);
        $this->columnarService->setStream($this->streamId);

        mysqli_report(MYSQLI_REPORT_STRICT);
        $this->connect();
    }

    public function connect(): bool
    {
        $this->error = '';

        $host = str_replace('{{ STREAM_ID }}', $this->streamId, config('manticore.host'));
        try {
            $this->connection = new mysqli($host, '', '', '');
            $this->connection->set_charset("utf8");
            return true;
        } catch (Exception $exception) {
            $this->error = $exception->getMessage();
            return false;
        }
    }

    public function lock($timeout = 0): bool
    {
        if (\App::environment() === 'testing'){
            return true;
        }
        $query = \DB::select(
            'SELECT GET_LOCK(:name, :timeout) as locked ',
            ['name' => $this->streamId.'lock', 'timeout' => $timeout]
        );

        $lock = $query[0]->locked ?? false;

        if ($lock) {
            $this->lockId = $this->isLocked();
        }

        return $lock;
    }

    public function isLocked(): bool
    {
        if (\App::environment() === 'testing'){
            return false;
        }

        $query = \DB::select('SELECT IS_USED_LOCK(:name) as locked ', ['name' => $this->streamId.'lock']);
        $lock = $query[0]->locked ?? false;

        if ($lock && $lock !== $this->lockId) {
            return $lock;
        }

        return false;
    }

    public function unlock(): bool
    {
        $query = \DB::select('SELECT RELEASE_LOCK(:name) as locked ', ['name' => $this->streamId.'lock']);
        return $query[0]->locked ?? false;
    }


    public function getFields()
    {
        $query = 'desc '.config('manticore.index').' table ';
        if ( ! is_null($this->connection)) {
            $result = $this->query($query);
            if ( ! empty($result)) {
                $fields = $result->fetch_all(MYSQLI_ASSOC);
                foreach ($fields as $k => $field) {
                    $position = strpos($field['Field'], "_host_path");
                    if ($position !== false) {
                        $name         = substr($field['Field'], 0, $position);
                        $unsetNames[] = $name."_host_path";
                        $unsetNames[] = $name."_query";
                        $unsetNames[] = $name."_anchor";

                        $this->urlFields[$name] = $unsetNames;
                        $fields[]               = ['Field' => $name, 'Type' => 'text', 'Properties' => 'indexed'];
                    }
                }

                if ( ! empty($unsetNames)) {
                    foreach ($fields as $k => $field) {
                        if (in_array($field['Field'], $unsetNames)) {
                            unset($fields[$k]);
                        }
                    }
                }

                return $fields;
            }
        }

        return [];
    }

    /**
     * @param $whereString
     * @param  string  $limitsString
     * @param  int  $maxMatches
     *
     * @return Rule[]
     */
    private function selectQuery($whereString, string $limitsString = '', int $maxMatches = 1000): array
    {
        $query = 'SELECT * FROM '.config('manticore.index').' '.
            $whereString.' '.$limitsString.' OPTION max_matches='.$maxMatches;

        if ( ! empty($query)) {
            $result = $this->query($query);
        }

        if ( ! empty($result)) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);

            return $this->initRules($rows);
        }

        return [];
    }


    /**
     * @param  Rule[]  $rules
     *
     *
     * @throws \JsonException
     */
    private function replaceQuery(array $rules): array
    {
        if ($this->isLocked()) {
            $this->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
            return [
                'message' => 'Current stream are locked for write. Try again later'
            ];
        }

        if (empty($rules)) {
            $this->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
            return [
                'message' => 'Update list are empty'
            ];
        }
        $insertCond = [];

        foreach ($rules as $rule) {
            $insertCond[] = '('.$rule->getId().", '"
                .$this->toLowerExcludeOperators($this->connection->escape_string($rule->getQueryWithVariableSubstituted()))
                ."', '".
                $this->toLowerExcludeOperators($this->connection->escape_string($rule->getFiltersWithVariableSubstituted()))."', '"
                .$this->connection->escape_string($rule->getJsonTags())."')";
        }

        $sqlInsert = 'REPLACE INTO '.$this->streamId.'_cluster:'.config('manticore.index').' (id, query, filters, tags) '.
            'VALUES '.implode(',', $insertCond);

        $this->query($sqlInsert, -1);

        if ($this->connection->affected_rows > 0) {
            $this->setStatusCode(Response::HTTP_OK);
            return ['message' => "Rules updated"];
        }

        $this->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        return ['message' => $this->connection->error];
    }

    /**
     * @param  array  $rows
     *
     * @return Rule[]
     *
     * @throws \JsonException
     */

    private function initRules(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = (new Rule())->init($row, true);
        }

        return $result;
    }


    /**
     * @throws \JsonException
     */
    private function selectQueryExtended(
        $whereString,
        $limitsString = '',
        $sortColumn = 0,
        $sortDirection = 'desc',
        $maxMatches = 1000
    ): array {
        $query = '';
        if ( ! in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }
        if ( ! isset($this->fields[$sortColumn]) && (int)$sortColumn !== 5) {
            $sortColumn = 0;
        }


        if ((int)$sortColumn === 5) {
            $hitsStat = $this->columnarService->getProcessedSum($limitsString, $sortDirection);
            if ( ! empty($hitsStat)) {
                $rulesID = [];
                foreach ($hitsStat as $rule) {
                    $rulesID[] = $rule['tag'];
                }

                $query = 'SELECT * FROM '.config('manticore.index').' '.
                    ($whereString ? $whereString." AND " : "WHERE ").' id IN('.implode(
                        ',',
                        $rulesID
                    ).') OPTION max_matches='.$maxMatches;
            }
        } else {
            $order = 'ORDER BY '.$this->fields[$sortColumn].' '.$sortDirection;
            $query = 'SELECT * FROM '.config('manticore.index').' '.
                $whereString.' '.$order.' '.$limitsString.' OPTION max_matches='.$maxMatches;
        }

        if ( ! empty($query)) {
            $result = $this->query($query);
        }

        if ( ! empty($result)) {
            $data['data'] = $this->initRules($result->fetch_all(MYSQLI_ASSOC));

            $ids = [];
            if ( ! empty($data['data'])) {
                foreach ($data['data'] as $rule) {
                    $ids[] = $rule->getId();
                }

                try {
                    $stats = $this->columnarService->getRuleStats($ids);
                    foreach ($data['data'] as $k => $rule) {
                        $data['data'][$k]->setStatistic($stats[$rule->getId()]);
                    }

                    if ((int)$sortColumn === 5) {
                        $weight = [];
                        foreach ($stats as $rule => $statsArray) {
                            $sum           = array_sum($statsArray);
                            $weight[$rule] = $sum;
                        }

                        if ($sortDirection === 'asc') {
                            asort($weight);
                        } else {
                            arsort($weight);
                        }

                        $results = $data['data'];
                        unset($data['data']);
                        foreach ($weight as $key => $sum) {
                            foreach ($results as $k => $rule) {
                                if ($rule->getId() === $key) {
                                    $data['data'][] = $rule;
                                }
                            }
                        }
                    }
                } catch (\RuntimeException $exception) {
                    $data['message'] = $exception->getMessage();
                }
            }


            if ( ! empty($limitsString)) {
                $unlimitedResults = $this
                    ->connection
                    ->query('SELECT count(*) FROM '.config('manticore.index').' '.$whereString)
                    ->fetch_all(MYSQLI_ASSOC);

                $data['recordsTotal']    = $unlimitedResults[0]['count(*)'];
                $data['recordsFiltered'] = $unlimitedResults[0]['count(*)'];
            }

            return $data;
        }

        return ['data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0];
    }

    /**
     * @throws \JsonException
     */
    public function getRules(string $limit, string $offset, $sortColumn = 0, $sortDirection = 'desc'): array
    {
        return $this->selectQueryExtended(
            '',
            'LIMIT '.$offset.' , '.$limit,
            $sortColumn,
            $sortDirection,
            ($offset + $limit)
        );
    }


    private function prepareExtendedSearchQuery(
        $id = null,
        $query = null,
        $weakQuery = false,
        $tags = null,
        $weakTags = null,
        $filters = null,
        $externalQuery = null,
        $variable = null
    ): string {
        $fullQuery = [];

        $weakTagsSearch = false;
        if ( ! empty($weakTags)) {
            $tags           = $weakTags;
            $weakTagsSearch = true;
        }

        if ($this->urlFields === null) {
            $this->getFields();
        }

        if ($weakQuery) {
            $weakAffix      = ".*?";
            $weakQueryStart = "";
            $weakQueryEnd   = "";
        } else {
            $weakAffix      = "";
            $weakQueryStart = "^";
            $weakQueryEnd   = "$";
        }

        foreach (['id', 'query', 'tags', 'filters', 'externalQuery', 'variable'] as $parameter) {
            if ( ! empty($$parameter) || isset($_REQUEST[$parameter])) {
                if (empty($$parameter) && (in_array($parameter, ['query', 'tags', 'externalQuery']))) {
                    $$parameter = '';
                }
                $chunks = [];
                if (is_array($$parameter)) {
                    foreach ($$parameter as $chunk) {
                        if (in_array($parameter, ['query', 'filters'])) {
                            $chunk = $this->toLowerExcludeOperators($chunk);
                        }

                        if ($parameter === 'query' && $this->hasQueryUrl($chunk)) {
                            $chunks[] = $this->prepareUrlQuery($chunk, $weakAffix);
                            continue;
                        }

                        if (in_array($parameter, ['query', 'tags', 'externalQuery', 'variable'])) {
                            if ($parameter === 'query') {
                                $chunk = $this->escapeStringRegex($chunk);
                                $chunk = $this->connection->escape_string($chunk);

                                $chunks[] = " REGEX(query, '".$weakQueryStart.$chunk.$weakQueryEnd."') ";
                            } else {
                                if ($parameter === 'externalQuery') {
                                    $in = 'externalQuery';
                                } elseif ($parameter === 'variable') {
                                    $chunk = '-'.$chunk.'-';
                                    $in    = 'variables';
                                } else {
                                    $in = 'tag';
                                }

                                // Escape manticore
                                $chunk = $this->connection->escape_string($chunk);
                                // Escape json
                                $chunk = $this->connection->escape_string($chunk);
                                // Escape regex
                                $chunk = $this->escapeStringRegex($chunk);
                                // Escape json REGEX
                                $chunk = str_replace("/", "\\\\/", $chunk);
                                // Escape ql
                                $chunk = $this->connection->escape_string($chunk);

                                if (($parameter === 'tags' && $weakTagsSearch) || $parameter === 'variable') {
                                    $chunks[] = " REGEX(tags, '\"$in\":\".*?".$chunk.".*?\"') ";
                                } else {
                                    $chunks[] = " REGEX(tags, '\"$in\":\"".$chunk."\"') ";
                                }
                            }
                        } else {
                            $chunk    = $this->connection->escape_string($chunk);
                            $chunks[] = $chunk;
                        }
                    }
                } else {
                    if (in_array($parameter, ['query', 'filters'])) {
                        $$parameter = $this->toLowerExcludeOperators($$parameter);
                    }

                    if ($parameter === 'query' && $this->hasQueryUrl($query)) {
                        $chunks[] = $this->prepareUrlQuery($query, $weakAffix);
                    } else {
                        if (in_array($parameter, ['query', 'tags', 'externalQuery', 'variable'])) {
                            if ($parameter === 'query') {
                                $$parameter = $this->escapeStringRegex($$parameter);
                                $$parameter = $this->connection->escape_string($$parameter);

                                $chunks[] = " REGEX(query, '".$weakQueryStart.$$parameter.$weakQueryEnd."') ";
                            } else {
                                if ($parameter === 'externalQuery') {
                                    $in = 'externalQuery';
                                } elseif ($parameter === 'variable') {
                                    $$parameter = '-'.$$parameter.'-';
                                    $in         = 'variables';
                                } else {
                                    $in = 'tag';
                                }


                                // Escape manticore
                                $$parameter = $this->connection->escape_string($$parameter);
                                // Escape json
                                $$parameter = $this->connection->escape_string($$parameter);
                                // Escape regex
                                $$parameter = $this->escapeStringRegex($$parameter);
                                // Escape json REGEX
                                $$parameter = str_replace("/", "\\\\/", $$parameter);
                                // Escape ql
                                $$parameter = $this->connection->escape_string($$parameter);

                                if (($parameter === 'tags' && $weakTagsSearch) || $parameter === 'variable') {
                                    $chunks[] = " REGEX(tags, '\"$in\":\".*?".$$parameter.".*?\"') ";
                                } else {
                                    $chunks[] = " REGEX(tags, '\"$in\":\"".$$parameter."\"') ";
                                }
                            }
                        } else {
                            $$parameter = $this->connection->escape_string($$parameter);
                            $chunks[]   = $$parameter;
                        }
                    }
                }

                if ($parameter === 'filters') {
                    $fullQuery[] = $parameter." in ('".implode("','", $chunks)."')";
                } elseif ($parameter === 'id') {
                    $fullQuery[] = $parameter." in (".implode(",", $chunks).")";
                } else {
                    $fullQuery[] = implode(' OR ', $chunks);
                }
            }
        }

        if ( ! empty($fullQuery)) {
            $fullQuery = 'WHERE '.implode(' AND ', $fullQuery);
        } else {
            $fullQuery = '';
        }

        return $fullQuery;
    }

    public function searchRuleExtended(
        $limit,
        $offset,
        $sortColumn,
        $sortDirection = 'desc',
        $id = null,
        $query = null,
        $weakQuery = false,
        $tag = null,
        $weakTags = null,
        $filters = null,
        $externalQuery = null,
        $variable = null
    ): array {
        $fullQuery = $this->prepareExtendedSearchQuery(
            $id,
            $query,
            $weakQuery,
            $tag,
            $weakTags,
            $filters,
            $externalQuery,
            $variable
        );

        $results = $this->selectQueryExtended(
            $fullQuery,
            'LIMIT '.$offset.' , '.$limit,
            $sortColumn,
            $sortDirection,
            ($offset + $limit)
        );

        $count = $this->countRules();
        if (isset($count[0]['count'])) {
            $results['all_rules_count'] = $count[0]['count'];
        }

        return $results;
    }

    /**
     * @throws \JsonException
     */
    public function update(
        Rule $newRule,
        $id,
        $query = null,
        $weakQuery = false,
        $tag = null,
        $weakTags = null,
        $filters = null
    ): array {
        if ($this->isLocked()) {
            $this->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
            return [
                'message' => 'Current stream are locked for write. Try again later',
            ];
        }

        $rules = $this->selectQuery(
            $this->prepareExtendedSearchQuery(
                $id,
                $query,
                $weakQuery,
                $tag,
                $weakTags,
                $filters
            )
        );

        foreach ($rules as $rule) {
            if ($newRule->getQuery() !== '') {
                $rule->setQuery($newRule->getQuery());
            }

            if ($newRule->getFilters() !== '') {
                $rule->setFilters($newRule->getFilters());
            }

            if ($newRule->getTags()->getTag() !== '') {
                $rule->getTags()->setTag($newRule->getTags()->getTag());
            }

            if ($newRule->getTags()->getExternalQuery() !== ''){
                $rule->getTags()->setExternalQuery($newRule->getTags()->getExternalQuery());
            }

            if ($newRule->getTags()->getHighlighting() !== ''){
                $rule->getTags()->setHighlighting($newRule->getTags()->getHighlighting());
            }

            $rule->getTags()->setUpdated(date('Y-m-d H:i:s'));

            if ($this->queryValidation && ($newRule->getQuery() !== '' || $newRule->getFilters() !== '')) {
                $check = $this->checkRuleMatches($rule->getQuery(), $rule->getFilters());
                if ($check['status'] !== CurlService::STATUS_ERROR) {

                    $this->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
                    return ['message' => $check['message']];
                }
            }
        }

        return $this->replaceQuery($rules);
    }

    /**
     * @throws \JsonException
     */
    public function searchRule($string, $limit, $offset, $sortColumn = 0, $sortDirection = 'desc'): array
    {
        return $this->selectQueryExtended(
            ' WHERE REGEX(query, \''.$this->toLowerExcludeOperators($this->escapeString($string)).'\')',
            'LIMIT '.$offset.' , '.$limit,
            $sortColumn,
            $sortDirection,
            ($offset + $limit)
        );
    }

    /**
     * @throws \JsonException
     */
    public function addRule(
        Rule $rule,
        $queryValidation = null,
        $duplicatesCheck = true
    ): array {
        if ($this->isLocked()) {
            $this->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
            return [
                'message' => 'Current stream are locked for write. Try again later'
            ];
        }

        if (is_array($rule->getQuery()) || is_array($rule->getFilters())
            || is_array($rule->getTags()->getTag())
            || is_array($rule->getTags()->getExternalQuery())
        ) {
            $this->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
            return [
                'message' => 'Variable can\'t be array type'
            ];
        }
        if (empty($rule->getQuery()) && empty($rule->getFilters())) {
            $this->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
            return [
                'message' => 'You must specify query or filters'
            ];
        }

        $nowDate = date('Y-m-d H:i:s');
        $rule->getTags()->setInserted($nowDate);
        $rule->getTags()->setUpdated($nowDate);

        if ($this->urlFields === null) {
            $this->getFields();
        }

        $modified = false;
        $fields = $this->getFields();
        $fieldNames = [];
        foreach ($fields as $field) {
            $fieldNames[] = '[\s|^]+@' . $field['Field'];
        }

        $fieldNames[] = "[\s|^]+@\(";


        $regex = '/((?!' . implode('|', $fieldNames) . ').)*/usi';
        preg_match_all($regex, $rule->getQuery(), $matches);


        if ($matches[0] === []) {
            $explodedText[] = $rule->getQuery();
        } else {
            $explodedText = $matches[0];
        }

        foreach ($explodedText as $k => $v) {
            if ($v === "") {
                unset($explodedText[$k]);
            }
        }

        if (!empty($this->urlFields)) {
            foreach ($this->urlFields as $field => $v) {
                if (strpos($rule->getQuery(), "@$field ") !== false
                    || preg_match(
                        '/@\(.*?' . $field . '.*?\) ([^\s]*)/usi',
                        $rule->getQuery()
                    )
                ) {
                    $modified = true;
                    foreach ($explodedText as $k => $value) {
                        if ($value === "") {
                            continue;
                        }

                        if (trim($value[1]) === '('
                            && preg_match_all(
                                '/\(.*?' . $field . '.*?\) (.*)/usi',
                                $value,
                                $matches
                            )
                        ) {
                            preg_match('/\((.*?' . $field . '.*?)\)/', $value,
                                $offsetMatches, PREG_OFFSET_CAPTURE);
                            $matchStart = $offsetMatches[0][1];
                            $matchEnd = $offsetMatches[0][1]
                                + strlen($offsetMatches[0][0]);
                            $foundMatch = substr($value, $matchStart + 1,
                                $matchEnd - 3);
                            $replaced = explode(',', $foundMatch);


                            $replaced = array_map(static function ($row) {
                                return trim($row);
                            }, $replaced);

                            $result = '';
                            foreach ($replaced as $fieldName) {
                                $result .= ' ' . $this->urlizeQuery($fieldName,
                                        $matches[1][0]);
                            }
                            $explodedText[$k] = $result;
                            continue;
                        }

                        if (strpos($value, $field) === 1) {
                            // если совпало название поля с самого начала
                            $query = substr($value, mb_strlen($field) + 1);

                            $urlized = $this->urlizeQuery($field, $query);
                            if ($urlized !== '') {
                                $explodedText[$k] = $urlized;
                            }
                        }
                    }
                }
            }
        }


        if ($modified) {
            foreach ($explodedText as $k => $value) {
                $value = trim($value);
                if ($value === "") {
                    unset($explodedText[$k]);
                    continue;
                }
                if ($k > 0 && $value[0] !== '@' && $value[0] !== '('
                    && $value[0] !== '-'
                ) {
                    $explodedText[$k] = '@' . $value;
                }
            }

            $rule->getTags()
                ->setOriginalQuery($this->toLowerExcludeOperators(trim($rule->getQuery())));
            $rule->setQuery(implode(" ", $explodedText));
        }

        $rule->decodeEscaping();

        if ($queryValidation !== null) {
            $this->queryValidation = (bool) $queryValidation;
        }
        if ($this->queryValidation) {
            $check = $this->checkRuleMatches($rule->getQuery(),
                $rule->getFilters());
        } else {
            $check = [
                'status' => CurlService::STATUS_SUCCESS,
                'message' => 'Rule added'
            ];
        }

        if ($check['status'] === CurlService::STATUS_SUCCESS) {
            $sql = 'INSERT INTO ' . $this->streamId . '_cluster:'
                . config('manticore.index')
                . ' (id, query, filters, tags) VALUES ' .
                '(' . $rule->getId() . ', \''
                . $this->toLowerExcludeOperators($this->connection->escape_string($rule->getQueryWithVariableSubstituted()))
                . '\', \''
                . $this->toLowerExcludeOperators($this->connection->escape_string($rule->getFiltersWithVariableSubstituted()))
                .
                '\', \''
                . $this->connection->escape_string($rule->getJsonTags())
                . '\')';

            $this->query($sql, -1);

            if ($this->connection->affected_rows > 0) {
                if ($duplicatesCheck) {
                    $newRule
                        = $this->getDuplicate($this->connection->insert_id);
                } else {
                    $newRule = [
                        'status' => CurlService::STATUS_SUCCESS,
                        'id' => $this->connection->insert_id,
                        'result' => $this->connection->insert_id,
                    ];
                }

                if ($newRule['status'] === CurlService::STATUS_ERROR) {
                    $this->setStatusCode(Response::HTTP_CREATED);
                    return [
                        'data' => $newRule['result'],
                        'message' => 'This rule already added'
                    ];
                }

                $this->setStatusCode(Response::HTTP_OK);
                return [
                    'data' => $newRule['result'],
                    'message' => $check['message'],
                ];
            }

            if ($this->connection->errno === 2006) {
                $this->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
            } else {
                $this->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return ['message' => $this->connection->error];
        }

        $this->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        return ['message' => $check['message']];
    }

    private function toLowerExcludeOperators($str): string
    {
        $pattern = '/('.implode('|', $this->upperCaseOperators).')/';
        $matches = preg_split($pattern, $str, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result = [];
        foreach ($matches as $match) {
            if ( ! in_array($match, $this->upperCaseOperators)) {
                $match = mb_strtolower($match);
            }
            $result[] = $match;
        }

        return implode("", $result);
    }

    public function deleteRulesList($list): array
    {
        if ( ! empty($list)) {
            $list = array_map(function ($id) {
                return (int)$id;
            }, $list);
            $sql  = 'DELETE FROM '.$this->streamId.'_cluster:'.config('manticore.index').' WHERE id in ('.implode(
                    ',',
                    $list
                ).')';
            $this->query($sql);
            if ($this->connection->affected_rows > 0) {
                $this->setStatusCode(Response::HTTP_OK);
                return ['message' => 'Rules removed'];
            }
        }

        $this->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        return ['message' => $this->connection->error];
    }

    public function deleteRule($ruleId): array
    {
        $sql = 'DELETE FROM '.$this->streamId.'_cluster:'.config('manticore.index').' WHERE id = '.(int)$ruleId;
        $this->query($sql);
        if ($this->connection->affected_rows > 0) {
            $this->setStatusCode(Response::HTTP_OK);
            return ['message' => 'Rules removed'];
        }

        $this->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        return ['message' => $this->connection->error];
    }

    public function countRules()
    {
        $sql    = 'SELECT count(*) as count FROM '.config('manticore.index');
        $result = $this->query($sql);
        if ( ! empty($result)) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    public function countVariable($variableName)
    {
        $sql    = 'SELECT count(*) as count FROM '.config('manticore.index')
            ." WHERE REGEX(tags, '\"variables\":\".*?-$variableName-.*?\"')";
        $result = $this->query($sql);
        if ( ! empty($result)) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    public function truncateRules()
    {
        $sql = 'TRUNCATE TABLE '.$this->streamId.'_cluster:'.config('manticore.index');
        return $this->query($sql);
    }

    /**
     * @throws \JsonException
     * @throws Exception
     */
    public function updateRuleVariables(Variable $variableBefore, Variable $variableAfter): bool
    {
        $this->lock();
        $allCount = $this->countVariable($variableBefore->name);
        \Log::info('Update variable stream #'.$this->streamId.' Count '.$allCount[0]['count']);
        $offset = 0;

        $this->beginTransaction();

        $allTimeStart = microtime(true);
        while ($offset < (int)$allCount[0]['count']) {
            $start = microtime(true);

            $rows = $this->selectQuery(
                'WHERE REGEX(tags, \'"variables":".*?-'.$variableBefore->name.'-.*?"\') ',
                'LIMIT '.$offset.' , '.self::BATCH_SIZE,
                self::BATCH_SIZE + $offset
            );

            $cnt = count($rows);
            if ($cnt > 0) {
                $chunks = array_chunk($rows, (self::BATCH_SIZE / 20));
                unset($rows);

                foreach ($chunks as $chunk) {
                    /** @var Rule $row */
                    foreach ($chunk as $row) {
                        $row->replaceVariable($variableAfter);
                    }

                    $replace = $this->replaceQuery($chunk);

                    // If replace fallen with error
                    if ($this->statusCode !== Response::HTTP_OK) {
                        $this->rollbackTransaction();
                        $this->error = $replace['message'];
                        $variableAfter->text = $variableBefore->text;
                        $variableAfter->save();

                        return false;
                    }
                    unset($chunk);
                }

                unset($chunks);
            }


            $timeElapsedSecs = microtime(true) - $start;
            \Log::info(
                'Update stream #'.$this->streamId.' Rows '.$cnt.' Offset '.$offset.' Executed time '
                .$timeElapsedSecs
            );

            $offset += self::BATCH_SIZE;
        }

        $timeElapsedSecs = microtime(true) - $allTimeStart;
        \Log::info(
            'Update variable stream #'.$this->streamId.' done. Executed time '
            .$timeElapsedSecs.' For '.$allCount[0]['count'].' rows'
        );

        $this->commitTransaction();
        $variableBefore->delete();
        $variableAfter->save();

        $this->unlock();

        return true;
    }


    /**
     * @throws \JsonException
     * @throws Exception
     */
    public function removeRuleVariable(Variable $variable): bool
    {
        $this->lock();
        $allCount = $this->countVariable($variable->name);
        $offset   = 0;

        $this->beginTransaction();
        while ($offset < (int)$allCount[0]['count']) {
            $start = microtime(true);

            $rows = $this->selectQuery(
                'WHERE REGEX(tags, \'"variables":".*?-'.$variable->name.'-.*?"\') ',
                'LIMIT '.$offset.' , '.self::BATCH_SIZE,
                self::BATCH_SIZE + $offset
            );

            $cnt = count($rows);
            if ($cnt > 0) {
                $chunks = array_chunk($rows, (self::BATCH_SIZE / 20));
                unset($rows);

                foreach ($chunks as $chunk) {
                    /** @var Rule $row */
                    foreach ($chunk as $row) {
                        $row->removeVariable($variable);
                    }

                    $replace = $this->replaceQuery($chunk);

                    // If replace falls
                    if ($this->statusCode !== Response::HTTP_OK) {
                        $this->rollbackTransaction();

                        return false;
                    }
                    unset($chunk);
                }

                unset($chunks);
            }


            $timeElapsedSecs = microtime(true) - $start;
            \Log::info(
                'Update stream #'.$this->streamId.' Rows '.$cnt.' Offset '.$offset.' Executed time '
                .$timeElapsedSecs
            );

            $offset += self::BATCH_SIZE;
        }
        $this->commitTransaction();
        $this->unlock();
        $variable->delete();

        return true;
    }


    /**
     * Rules checker returns error in case this rule exceeds limit
     *
     * @param $ruleText
     * @param $filters
     *
     * @return array
     */
    private function checkRuleMatches($ruleText, $filters = ''): array
    {
        /** @var CurlService $curl */
        $curl = resolve(CurlService::class);

        $query = ['check' => 1, 'query' => $ruleText, 'filters' => $filters];
        $query = http_build_query($query);


        if (in_array(App::environment(), ['production', 'cluster_testing'])) {
            $url = $this->streamId."-pipeline/?".$query;
        } else {
            $url = "localhost:8809/?".$query;
        }

        $result = $curl->get($url);
        if (empty($result['status']) || $result['status'] === CurlService::STATUS_ERROR) {
            return [
                'status'  => CurlService::STATUS_ERROR,
                'message' => "Can't connect to rules checker service",
                'result'  => $result,
            ];
        }

        if ($result['result']['status'] === CurlService::STATUS_SUCCESS) {
            return [
                'status'  => CurlService::STATUS_SUCCESS,
                'message' => $result['result']['message'],
                'result'  => $result,
            ];
        }

        return ['status' => CurlService::STATUS_ERROR, 'message' => $result['result']['message'], 'result' => $result];
    }


    private function urlize($fieldName, $in): string
    {
        $out = [];

        if ( ! parse_url($in, PHP_URL_SCHEME) && $in[0] !== "/") {
            $in = "https://"."$in";
        }

        $s = '';
        if ($host = parse_url($in, PHP_URL_HOST)) {
            $ar = preg_split('/(\.)/', $host);
            $ar = array_reverse($ar);
            foreach ($ar as $k => $v) {
                $s                              = $v.($s ? (".".$s) : '');
                $out[$fieldName.'_host_path'][] = md5($s);
            }
        }
        $s = '';
        if ($path = parse_url($in, PHP_URL_PATH)) {
            $ar = preg_split('/(\/)/', $path);
            foreach ($ar as $k => $v) {
                if ( ! $v) {
                    continue;
                }
                $s                              .= "/".$v;
                $out[$fieldName.'_host_path'][] = md5($s);
            }
        }
        $out[$fieldName.'_host_path'] = implode(' ', $out[$fieldName.'_host_path']);
        if ($query = parse_url($in, PHP_URL_QUERY)) {
            $out[$fieldName.'_query'] = md5(preg_replace('/&/', ' ', $query));
        }
        if ($anchor = parse_url($in, PHP_URL_FRAGMENT)) {
            $out[$fieldName.'_anchor'] = md5($anchor);
        }

        $result = [];
        foreach ($out as $keys => $hash) {
            $result[] = '@'.$keys." ".$hash." ";
        }

        return trim(implode(' ', $result));
    }

    private function urlizeQuery($fieldName, $query): string
    {
        $query = trim($query);
        $group = false;
        if (strpos($query, "|") !== false) {
            $group = true;
            $query = explode("|", $query);
        } else {
            $query = [$query];
        }

        $stopwords    = [];
        $queryResults = [];
        foreach ($query as $key => $in) {
            $hashedUrl = '';
            if ($in === '') {
                continue;
            }

            if (strpos($in, '-') !== false) {
                $wordUrls = explode(" ", $in);

                foreach ($wordUrls as $k => $word) {
                    if ($word === '') {
                        continue;
                    }

                    $word = trim($word);
                    if ($word[0] === "-") {
                        $word        = substr($word, 1); // remove -
                        $stopwords[] = $word;
                    } else {
                        $hashedUrl = $this->urlize($fieldName, trim($word));
                    }
                }
            } else {
                $hashedUrl = $this->urlize($fieldName, $in);
            }


            if ($group) {
                $queryResults[] = "(".$hashedUrl.")";
            } else {
                $queryResults[] = $hashedUrl;
            }
        }

        if ($group) {
            $results = implode(" | ", $queryResults);
        } else {
            $results = implode(" ", $queryResults);
        }

        // find stopwords
        foreach ($stopwords as $k => $v) {
            $stopwords[$k] = $this->urlize($fieldName, $v);
        }


        if ( ! empty($stopwords)) {
            $results .= " -(".implode(" ", $stopwords).")";
        }

        return $results;
    }

    private function hasQueryUrl($query): bool
    {
        if ( ! empty($this->urlFields)) {
            foreach ($this->urlFields as $field => $v) {
                if (strpos($query, "@$field") !== false
                    || preg_match_all('/@\(.*?'.$field.'.*?\) (.*)/usi', $query, $matches)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function prepareUrlQuery($query, $weakAffix): string
    {
        $query = str_replace('/', '\\\\/', $query);
        $query = $this->escapeStringRegex($query);
        $query = $this->connection->escape_string($query);

        $query = preg_replace("/\s+/", "\\\\\s", $query);

        return " REGEX(tags, '\"originalQuery\":\"".$weakAffix.$query.$weakAffix."\"') ";
    }


    private function getDuplicate($queryId)
    {
        $result = $this->getRuleById($queryId);
        if ($result) {
            $_REQUEST['query']   = $result->getQuery();
            $_REQUEST['filters'] = $result->getFilters();

            $query = $this->prepareExtendedSearchQuery(
                null,
                $result->getQuery(),
                null,
                null,
                null,
                $result->getFilters()
            );

            $_REQUEST   = [];
            $duplicates = $this->query('SELECT * FROM '.config('manticore.index').' '.$query);

            if ($duplicates) {
                $duplicates = $duplicates->fetch_all(MYSQLI_ASSOC);


                foreach ($duplicates as $row) {
                    if ((int)$row['id'] !== $queryId) {
                        $this->deleteRule($queryId);

                        return ['status' => CurlService::STATUS_ERROR, 'result' => (new Rule())->init($row, true)];
                    }
                }
            }
        }

        return ['status' => CurlService::STATUS_SUCCESS, 'result' => $result];
    }

    /**
     * @throws \JsonException
     */
    public function getRuleById($queryId)
    {
        $result = $this->query('SELECT * FROM '.config('manticore.index').' WHERE id = '.$queryId);
        if ( ! empty($result)) {
            return (new Rule())->init($result->fetch_array(MYSQLI_ASSOC), true);
        }

        return false;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    private function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }
}
