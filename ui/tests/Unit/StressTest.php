<?php

namespace Tests\Unit;


use App\Models\Rule;
use App\Models\User;
use App\Models\Variable;
use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class StressTest extends TestCase
{
    use AuthTrait;

    /** @var ManticoreService */
    private $manticoreService;
    /** @var \App\Services\Curl\CurlService */
    private $curl;

    protected \Faker\Generator $faker;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->curl  = Mockery::mock(CurlService::class);
        $this->faker = \Faker\Factory::create();
    }

    /**
     * @test
     *
     * @throws \JsonException
     */
    public function inflateRulesDevelopment()
    {

        $this->markTestSkipped();
        $start = microtime(true);
        $user  = User::find(26);
        \Auth::setUser( $user);

        Variable::truncate();
        $this->manticoreService = new ManticoreService($user->process, $this->curl);
        self::assertEmpty($this->manticoreService->getError());
        $this->manticoreService->truncateRules();

        for ($i = 1; $i <= 5; $i++) {
            Variable::factory()->create(['stream_id' => $user->process]);
        }
        $variables = Variable::all();

        for ($i = 1; $i <= 1000000; $i++) {
            $rule = new Rule();
            $rule->init($this->fakeRule($variables, ['id' => 0]));
            $this->manticoreService->addRule($rule, null, false);
        }

        self::assertSame(1000000, (int) $this->manticoreService->countRules()[0]['count']);
        $time = microtime(true) - $start;
        echo "inflateRules $time \n";
    }


    /**
     * @test
     *
     * @throws \JsonException
     */
    public function inflateRuleslocal()
    {

        $this->markTestSkipped();
        $start = microtime(true);
        $user  = User::find(2);
        \Auth::setUser( $user);

        Variable::truncate();
        $this->manticoreService = new ManticoreService('', $this->curl);
        self::assertEmpty($this->manticoreService->getError());
        $this->manticoreService->truncateRules();

        for ($i = 1; $i <= 5; $i++) {
            Variable::factory()->create(['stream_id' => $user->process]);
        }
        $variables = Variable::all();

        for ($i = 1; $i <= 200000; $i++) {
            $rule = new Rule();
            $rule->init($this->fakeRule($variables, ['id' => 0]));
            $this->manticoreService->addRule($rule, null, false);
        }

        self::assertSame(200000, (int) $this->manticoreService->countRules()[0]['count']);
        $time = microtime(true) - $start;
        echo "inflateRules $time \n";
    }

    /**
     * @test
     */

    public function replaceVariable(){

        $this->markTestSkipped();
        $start = microtime(true);
        $manager = User::find(26);
        \Auth::setUser($manager);

        $this->curl->shouldReceive('post')->with()
            ->once()
            ->andReturn(["ok"]);

        $variable = Variable::find(10);

        $this->manticoreService = new ManticoreService($manager->process, $this->curl);
        self::assertEmpty($this->manticoreService->getError());
        $this->manticoreService->searchRuleExtended(500, 0, 0, 'desc',
            null, null, false, null, $variable->name);


        $variable->text .= " klim";

        $response = $this->actingAs($manager)->json('POST', "/manager/variables/".$variable->id,
            ['text' => $variable->text]);

        $response->assertStatus(200);
        $time = microtime(true) - $start;

        echo "replaceVariable $time \n";
        self::assertTrue($time < 300000);
    }


    /**
     * @throws \JsonException
     * @throws \Exception
     */
    private function fakeRule(Collection $variables, $data = null): array
    {
        $useVariables  = false;
        $variableNames = '';
        if (random_int(1, 2) === 2) {
            $useVariables      = true;
            $variableForQuery  = $variables->random();
            $variableForFilter = $variables->random();

            if ($variableForQuery->name === $variableForFilter->name) {
                $variableNames = '-'.$variableForQuery->name.'-';
            } else {
                $variableNames = '-'.$variableForQuery->name.'-|-'.$variableForFilter->name.'-';
            }
        }

        $query   = ($useVariables ? $this->faker->words(3, true)." {{".$variableForQuery->name."}}" : $this->faker->words(3, true));
        $filters = 'json.'.$this->faker->word().' = "'.($useVariables ? "{{".$variableForFilter->name."}}" : $this->faker->word()).'"';

        $tags = [
            'tag'           => 'myCustomTag',
            'inserted'      => '2021-11-13 11:47:43',
            'updated'       => '2021-11-13 11:47:43',
            'originalQuery' => 'myOriginalQuery',
            'externalQuery' => 'myExternalQuery',
            'ownQuery'      => $query,
            'ownFilters'    => $filters,
            "highlighting"  => true,
            "variables"     => $variableNames,
        ];

        if (isset($data['tags'])) {
            $tags = array_merge($tags, $data['tags']);
            unset($data['tags']);
        }


        $init = [
            'id'      => 123,
            'query'   => $query,
            'tags'    => json_encode($tags, JSON_THROW_ON_ERROR),
            'filters' => $filters,
        ];


        if ($data !== null) {
            $init = array_merge($init, $data);
        }

        return $init;
    }
}
