<?php

namespace Tests\Unit;

use App\Models\Rule;
use App\Models\User;
use App\Models\Variable;
use App\Services\VariablesService;
use Auth;
use Carbon\Carbon;
use Faker\Factory;
use Tests\TestCase;

/**
 * @group application
 */
class RuleTest extends TestCase
{

    protected \Faker\Generator $faker;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->faker = Factory::create();
    }

    /**
     * @test
     *
     * @throws \JsonException
     */
    public function createRuleFromArrayNoVariables(): void
    {
        $ruleData = $this->fakeRule([
            'id' => 1234,
            'query' => '{{pwd}} query',
            'tags' => [
                'updated' => Carbon::now()->format('Y-m-d H:i:s'),
                'ownQuery' => '\{\{pwd\}\} query'
            ],
        ]);

        $rule = new Rule();
        $rule->init($ruleData, true);

        self::assertSame('\{\{pwd\}\} query', $rule->getQuery());
        self::assertSame($rule->getFilters(), $ruleData['filters']);
        self::assertSame($rule->getId(), $ruleData['id']);

        $tags = json_decode($ruleData['tags']);
        self::assertSame($rule->getTags()->getTag(), $tags->tag);
        self::assertSame($rule->getTags()->getInserted(), $tags->inserted);
        self::assertSame($rule->getTags()->getUpdated(), $tags->updated);
        self::assertSame($rule->getTags()->getOriginalQuery(), $tags->originalQuery);
        self::assertSame($rule->getTags()->getExternalQuery(), $tags->externalQuery);
        self::assertSame($rule->getTags()->getHighlighting(), $tags->highlighting);
        self::assertSame($rule->getTags()->getOwnQuery(), $tags->ownQuery);
        self::assertSame($rule->getTags()->getOwnFilters(), $ruleData['filters']);
        self::assertSame($rule->getTags()->getVariables(), []);
    }


    /**
     * @test
     *
     * @throws \JsonException
     */
    public function createRule(): void
    {
        $ruleData = $this->fakeRule();
        $tags = json_decode($ruleData['tags']);

        $rule = new Rule();
        $rule->setId($ruleData['id']);
        $rule->setQuery($ruleData['query']);
        $rule->setFilters($ruleData['filters']);
        $rule->setStatistic([1, 2, 3]);
        $rule->getTags()->setTag($tags->tag);


        self::assertSame($rule->getId(), $ruleData['id']);
        self::assertSame($rule->getQuery(), $ruleData['query']);
        self::assertSame($rule->getFilters(), $ruleData['filters']);
        self::assertSame($rule->getTags()->getTag(), $tags->tag);

        $serializedRule = json_encode($rule);

        self::assertSame(json_encode([
            'id' => (string)$ruleData['id'],
            'query' => $ruleData['query'],
            'tags' => $rule->getJsonTags(),
            'filters' => $ruleData['filters'],
            'statistic' => [1, 2, 3],
        ], JSON_THROW_ON_ERROR), $serializedRule);
    }


    /**
     * @test
     */
    public function setIdNull(): void
    {
        $rule = new Rule();
        $rule->setId(null);
        self::assertSame($rule->getId(), 0);
    }

    /**
     * @test
     */
    public function setQueryNull(): void
    {
        $rule = new Rule();
        $rule->setQuery(null);
        self::assertSame($rule->getQuery(), '');
    }

    /**
     * @test
     */
    public function setFiltersNull(): void
    {
        $rule = new Rule();
        $rule->setFilters(null);
        self::assertSame($rule->getFilters(), '');
    }


    /**
     * @test
     */
    public function setTagNull(): void
    {
        $rule = new Rule();
        $rule->getTags()->setTag(null);
        self::assertSame($rule->getTags()->getTag(), '');
    }

    /**
     * @test
     */
    public function setOriginalQueryNull(): void
    {
        $rule = new Rule();
        $rule->getTags()->setOriginalQuery(null);
        self::assertSame($rule->getTags()->getOriginalQuery(), '');
    }


    /**
     * @test
     */
    public function setExternalQueryNull(): void
    {
        $rule = new Rule();
        $rule->getTags()->setExternalQuery(null);
        self::assertSame($rule->getTags()->getExternalQuery(), '');
    }

    /**
     * @test
     */
    public function setAddOwn(): void
    {
        $ruleData = $this->fakeRule();
        $rule = new Rule();
        $rule->setQuery($ruleData['query']);
        $rule->setFilters($ruleData['filters']);

        self::assertSame($rule->getTags()->getOwnQuery(), $ruleData['query']);
        self::assertSame($rule->getTags()->getOwnFilters(), $ruleData['filters']);
    }

    /**
     * @test
     */
    public function createRuleWithVariables(): void
    {
        $user = User::find(2);
        Auth::setUser($user);
        VariablesService::getInstance()->clean();
        $variable = Variable::factory()->create(['stream_id' => $user->process]);
        $ruleData = $this->fakeRule();

        $queryWithVariable = $ruleData['query'] . ' {{' . $variable->name . '}} \{\{BMW\}\}';
        $filtersWithVariable = 'json.tag = "{{' . $variable->name . '}}" AND json.tag = "\{\{BMW\}\}"';


        $rule = new Rule();
        $rule->setQuery($queryWithVariable);
        $rule->setFilters($filtersWithVariable);


        self::assertSame($rule->getQuery(), $queryWithVariable);
        self::assertSame($rule->getFilters(), $filtersWithVariable);

        $rule->decodeEscaping();
        $queryWithVariable = str_replace(['\{\{', '\}\}'], ['{{', '}}'], $queryWithVariable);
        $filtersWithVariable = str_replace(['\{\{', '\}\}'], ['{{', '}}'], $filtersWithVariable);

        self::assertSame($rule->getQuery(), $ruleData['query'] . ' {{' . $variable->name . '}} {{BMW}}');
        self::assertSame($rule->getFilters(), 'json.tag = "{{' . $variable->name . '}}" AND json.tag = "{{BMW}}"');

        self::assertSame($ruleData['query'] . ' ' . $variable->text . ' {{BMW}}', $rule->getQueryWithVariableSubstituted());
        self::assertSame($rule->getFiltersWithVariableSubstituted(), 'json.tag = "' . $variable->text . '" AND json.tag = "{{BMW}}"');

        self::assertSame($rule->getTags()->getVariables(), [$variable->name => $variable->text]);

        $e = json_encode($rule, JSON_THROW_ON_ERROR);
        self::assertSame(json_encode([
            'id' => (string)0,
            'query' => $queryWithVariable,
            'tags' => $rule->getJsonTags(),
            'filters' => $filtersWithVariable,
            'statistic' => [],
        ], JSON_THROW_ON_ERROR), $e);
    }

    /**
     * @test
     *
     * @throws \JsonException
     */

    public function initBackwardCompatibility(): void
    {

        $ruleData = [
            'query' => "abc",
            'filters' => "json.ln = 'en'",
            'tags' => json_encode([
                'tag' => 'dbc'
            ], JSON_THROW_ON_ERROR)
        ];
        $rule = new Rule();
        $rule->init($ruleData, true);

        self::assertSame(json_encode([
            'id' => "0",
            'query' => "abc",
            'tags' => json_encode([
                'tag' => 'dbc',
                'inserted' => '',
                'updated' => '',
                'originalQuery' => '',
                'externalQuery' => '',
                'ownQuery' => '',
                'ownFilters' => '',
                'highlighting' => false,
                'variables' => '',
            ], JSON_THROW_ON_ERROR),
            'filters' => "json.ln = 'en'",
            "statistic" => []

        ], JSON_THROW_ON_ERROR), json_encode($rule));
    }

    /**
     * @test
     *
     * @throws \JsonException
     */
    public function createRuleWithVariablesFromInit(): void
    {
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);
        VariablesService::getInstance()->clean();
        $variable1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable2 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable3 = Variable::factory()->create(['stream_id' => $user->process]);

        $queryWithVariablePlaceholders = 'myQuery {{' . $variable1->name . '}} {{' . $variable2->name . '}} {{BMW}} {{' . $variable3->name . '}}';
        $filtersWithVariablePlaceholders = 'json.tag = "{{' . $variable2->name . '}}" AND json.tag = "{{BMW}}"';
        $queryWithVariableSubstituted = 'myQuery ' . $variable1->text . ' ' . $variable2->text . ' {{BMW}} ' . $variable3->text;
        $filtersWithVariableSubstituted = 'json.tag = "' . $variable2->text . '" AND json.tag = "{{BMW}}"';
        $escapedQueryWithVariablePlaceholders = str_replace('{{BMW}}', '\{\{BMW\}\}', $queryWithVariablePlaceholders);
        $escapedFiltersWithVariablePlaceholders = str_replace('{{BMW}}', '\{\{BMW\}\}', $filtersWithVariablePlaceholders);
        $escapedQueryWithVariableSubstituted = str_replace('{{BMW}}', '\{\{BMW\}\}', $queryWithVariableSubstituted);
        $escapedFiltersWithVariableSubstituted = str_replace('{{BMW}}', '\{\{BMW\}\}', $filtersWithVariableSubstituted);

        $ruleData = $this->fakeRule([
            'query' => $queryWithVariableSubstituted,
            'filters' => $filtersWithVariableSubstituted,
            'tags' => [
                'variables' => '-' . $variable1->name . '-|-' . $variable2->name . '-|-' . $variable3->name . '-',
                'ownQuery' => $escapedQueryWithVariablePlaceholders,
                'ownFilters' => $escapedFiltersWithVariablePlaceholders,
            ],
        ]);

        $rule = new Rule();
        $rule->init($ruleData, true);

        self::assertSame($rule->getQuery(), $escapedQueryWithVariablePlaceholders);
        self::assertSame($rule->getFilters(), $escapedFiltersWithVariablePlaceholders);

        self::assertSame($escapedQueryWithVariableSubstituted, $rule->getQueryWithVariableSubstituted());
        self::assertSame($escapedFiltersWithVariableSubstituted, $rule->getFiltersWithVariableSubstituted());

        self::assertSame($rule->getId(), $ruleData['id']);


        $tags = json_decode($ruleData['tags']);
        self::assertSame($rule->getTags()->getTag(), $tags->tag);
        self::assertSame($rule->getTags()->getInserted(), $tags->inserted);
        self::assertSame($rule->getTags()->getUpdated(), $tags->updated);
        self::assertSame($rule->getTags()->getOriginalQuery(), $tags->originalQuery);
        self::assertSame($rule->getTags()->getExternalQuery(), $tags->externalQuery);
        self::assertSame($rule->getTags()->getHighlighting(), $tags->highlighting);
        self::assertSame($tags->ownQuery, $rule->getTags()->getOwnQuery());
        self::assertSame($tags->ownFilters, $rule->getTags()->getOwnFilters());
        self::assertSame($rule->getTags()->getVariables(), [
            $variable1->name => $variable1->text,
            $variable2->name => $variable2->text,
            $variable3->name => $variable3->text,
        ]);
    }

    /**
     * @test
     *
     * @throws \JsonException
     */

    public function createRuleWithVariablesFromInitNoPlaceholders(): void
    {
        VariablesService::getInstance()->clean();
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);
        $variable1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable2 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable3 = Variable::factory()->create(['stream_id' => $user->process]);

        $queryWithVariablePlaceholders = 'myQuery {{' . $variable1->name . '}} {{' . $variable2->name . '}} {{' . $variable3->name . '}}';
        $filtersWithVariablePlaceholders = 'json.tag = "{{' . $variable2->name . '}}"';
        $queryWithVariableSubstituted = 'myQuery ' . $variable1->text . ' ' . $variable2->text . ' ' . $variable3->text;
        $filtersWithVariableSubstituted = 'json.tag = "' . $variable2->text . '"';


        $ruleData = $this->fakeRule([
            'query' => $queryWithVariableSubstituted,
            'filters' => $filtersWithVariableSubstituted,
            'tags' => [
                'variables' => '-' . $variable1->name . '-|-' . $variable2->name . '-|-' . $variable3->name . '-',
                'ownQuery' => $queryWithVariablePlaceholders,
                'ownFilters' => $filtersWithVariablePlaceholders,
            ],
        ]);

        $rule = new Rule();
        $rule->init($ruleData, true);

        self::assertSame($queryWithVariablePlaceholders, $rule->getQuery());
        self::assertSame($filtersWithVariablePlaceholders, $rule->getFilters());

        self::assertSame($queryWithVariableSubstituted, $rule->getQueryWithVariableSubstituted());
        self::assertSame($filtersWithVariableSubstituted, $rule->getFiltersWithVariableSubstituted());

        self::assertSame($rule->getId(), $ruleData['id']);


        $tags = json_decode($ruleData['tags']);
        self::assertSame($rule->getTags()->getTag(), $tags->tag);
        self::assertSame($rule->getTags()->getInserted(), $tags->inserted);
        self::assertSame($rule->getTags()->getUpdated(), $tags->updated);
        self::assertSame($rule->getTags()->getOriginalQuery(), $tags->originalQuery);
        self::assertSame($rule->getTags()->getExternalQuery(), $tags->externalQuery);
        self::assertSame($rule->getTags()->getHighlighting(), $tags->highlighting);
        self::assertSame($queryWithVariablePlaceholders, $rule->getTags()->getOwnQuery());
        self::assertSame($filtersWithVariablePlaceholders, $rule->getTags()->getOwnFilters());
        self::assertSame($rule->getTags()->getVariables(), [
            $variable1->name => $variable1->text,
            $variable2->name => $variable2->text,
            $variable3->name => $variable3->text,
        ]);
    }


    /**
     * @test
     *
     * @throws \JsonException
     */
    public function updateVariable()
    {
        VariablesService::getInstance()->clean();
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);
        $variable1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable2 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable3 = Variable::factory()->create(['stream_id' => $user->process]);

        $queryWithVariablePlaceholders = 'myQuery {{' . $variable1->name . '}} {{' . $variable2->name . '}} {{' . $variable3->name . '}}';
        $filtersWithVariablePlaceholders = 'json.tag = "{{' . $variable2->name . '}}"';
        $queryWithVariableSubstituted = 'myQuery ' . $variable1->text . ' ' . $variable2->text . ' ' . $variable3->text;
        $filtersWithVariableSubstituted = 'json.tag = "' . $variable2->text . '"';


        $ruleData = $this->fakeRule([
            'query' => $queryWithVariableSubstituted,
            'filters' => $filtersWithVariableSubstituted,
            'tags' => [
                'variables' => '-' . $variable1->name . '-|-' . $variable2->name . '-|-' . $variable3->name . '-',
                'ownQuery' => $queryWithVariablePlaceholders,
                'ownFilters' => $filtersWithVariablePlaceholders,
            ],
        ]);

        $rule = new Rule();
        $rule->init($ruleData, true);

        self::assertSame($queryWithVariablePlaceholders, $rule->getQuery());
        self::assertSame($filtersWithVariablePlaceholders, $rule->getFilters());

        self::assertSame($queryWithVariableSubstituted, $rule->getQueryWithVariableSubstituted());
        self::assertSame($filtersWithVariableSubstituted, $rule->getFiltersWithVariableSubstituted());

        $variable2->text = "my eddited variable";
        $queryWithVariableSubstituted = 'myQuery ' . $variable1->text . ' ' . $variable2->text . ' ' . $variable3->text;
        $filtersWithVariableSubstituted = 'json.tag = "' . $variable2->text . '"';

        $rule->replaceVariable($variable2);

        self::assertSame($queryWithVariablePlaceholders, $rule->getQuery());
        self::assertSame($filtersWithVariablePlaceholders, $rule->getFilters());

        self::assertSame($queryWithVariableSubstituted, $rule->getQueryWithVariableSubstituted());
        self::assertSame($filtersWithVariableSubstituted, $rule->getFiltersWithVariableSubstituted());
    }


    /**
     * @test
     *
     * @throws \JsonException
     */
    public function removeVariable()
    {
        Variable::truncate();
        $user = User::find(2);
        Auth::setUser($user);
        VariablesService::getInstance()->clean();

        $variable1 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable2 = Variable::factory()->create(['stream_id' => $user->process]);
        $variable3 = Variable::factory()->create(['stream_id' => $user->process]);

        $queryWithVariablePlaceholders = 'myQuery {{' . $variable1->name . '}} {{' . $variable2->name . '}} {{' . $variable3->name . '}}';
        $filtersWithVariablePlaceholders = 'json.tag = "{{' . $variable2->name . '}}"';
        $queryWithVariableSubstituted = 'myQuery ' . $variable1->text . ' ' . $variable2->text . ' ' . $variable3->text;
        $filtersWithVariableSubstituted = 'json.tag = "' . $variable2->text . '"';


        $ruleData = $this->fakeRule([
            'query' => $queryWithVariableSubstituted,
            'filters' => $filtersWithVariableSubstituted,
            'tags' => [
                'variables' => '-' . $variable1->name . '-|-' . $variable2->name . '-|-' . $variable3->name . '-',
                'ownQuery' => $queryWithVariablePlaceholders,
                'ownFilters' => $filtersWithVariablePlaceholders,
            ],
        ]);

        $rule = new Rule();
        $rule->init($ruleData, true);

        self::assertSame($queryWithVariablePlaceholders, $rule->getQuery());
        self::assertSame($filtersWithVariablePlaceholders, $rule->getFilters());

        self::assertSame($queryWithVariableSubstituted, $rule->getQueryWithVariableSubstituted());
        self::assertSame($filtersWithVariableSubstituted, $rule->getFiltersWithVariableSubstituted());

        $queryWithVariableSubstituted = 'myQuery ' . $variable1->text . '  ' . $variable3->text;
        $filtersWithVariableSubstituted = 'json.tag = ""';
        $queryWithVariablePlaceholders = 'myQuery {{' . $variable1->name . '}}  {{' . $variable3->name . '}}';
        $filtersWithVariablePlaceholders = 'json.tag = ""';

        $rule->removeVariable($variable2);

        self::assertSame($queryWithVariablePlaceholders, $rule->getQuery());
        self::assertSame($filtersWithVariablePlaceholders, $rule->getFilters());

        self::assertSame($queryWithVariableSubstituted, $rule->getQueryWithVariableSubstituted());
        self::assertSame($filtersWithVariableSubstituted, $rule->getFiltersWithVariableSubstituted());
    }

    private function fakeRule($data = null): array
    {
        $query = $this->faker->words(3, true);
        $filters = 'json.tag = "' . $this->faker->word() . '"';

        $tags = [
            'tag' => 'myCustomTag',
            'inserted' => '2021-11-13 11:47:43',
            'updated' => '2021-11-13 11:47:43',
            'originalQuery' => 'myOriginalQuery',
            'externalQuery' => 'myExternalQuery',
            'ownQuery' => $query,
            'ownFilters' => $filters,
            "highlighting" => true,
            "variables" => "",
        ];

        if (isset($data['tags'])) {
            $tags = array_merge($tags, $data['tags']);
            unset($data['tags']);
        }


        $init = [
            'id' => 123,
            'query' => $query,
            'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
            'filters' => $filters,
        ];


        if ($data !== null) {
            $init = array_merge($init, $data);
        }

        return $init;
    }
}
