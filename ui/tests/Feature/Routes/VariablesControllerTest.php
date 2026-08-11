<?php

namespace Tests\Feature\Routes;

use App\Models\Rule;
use App\Models\Variable;
use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use App\Services\VariablesService;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class VariablesControllerTest extends TestCase
{
    use AuthTrait;

    /**
     * @var ManticoreService|Mockery\LegacyMockInterface|Mockery\MockInterface
     */

    private function mockService(\Closure $closure)
    {
        $this->instance(
            ManticoreService::class,
            Mockery::mock(ManticoreService::class, $closure)
        );
    }

    /**
     * @test
     */
    public function nonLoggedCantGetVariables()
    {
        $response = $this->get("/manager/variables");
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }


    /**
     * @test
     */
    public function adminCantGetVariables()
    {
        $admin = $this->getAdmin();
        $response = $this->actingAs($admin)->get("/manager/variables");
        $response->assertStatus(302);
        $response->assertRedirect('/home');
    }


    /**
     * @test
     */
    public function managerCanGetVariables()
    {
        $manager = $this->getManager();
        $response = $this->actingAs($manager)->get("/manager/variables");
        $response->assertStatus(200);
        $response->assertSee('Add new variable');
    }


    /**
     * @test
     */
    public function managerCanGetSingleVariable()
    {
        $manager = $this->getManager();
        $variable = Variable::factory()
            ->create(['stream_id' => $manager->process]);
        $response = $this->actingAs($manager)->get("/manager/variables/"
            . $variable->name);

        $response->assertStatus(200);
        $response->assertExactJson($variable->toArray());
    }


    /**
     * @test
     */
    public function managerCanAddVariable()
    {
        $manager = $this->getManager();
        $variable = Variable::factory()->make();
        $response = $this->actingAs($manager)->put("/manager/variables/",
            [
                'name' => $variable->name, 'type' => $variable->type,
                'text' => $variable->text
            ]);

        $response->assertStatus(200);

        $model = Variable::latest('id')->first();

        self::assertSame($variable->name, $model->name);
        self::assertSame($variable->text, $model->text);
        self::assertSame($manager->process, $model->stream_id);
    }


    /**
     * @test
     */
    public function managerCantAddWrongVariable()
    {
        $variable = Variable::factory()->make([
            'name' => 'name+++', 'text' => 'sa'
        ]);

        $response = $this->actingAs($this->getManager())
            ->json('put', "/manager/variables/",
                ['name' => $variable->name, 'text' => $variable->text]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'text']);
    }

    /**
     * @test
     */
    public function managerCantAddDuplicatedVariable()
    {
        $manager = $this->getManager();
        $variable1 = Variable::factory()
            ->create(['stream_id' => $manager->process]);
        $variable2 = Variable::factory()->make(['name' => $variable1->name]);

        $response = $this->actingAs($manager)
            ->json('put', "/manager/variables/",
                ['name' => $variable2->name, 'text' => $variable2->text]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }


    /**
     * @test
     */
    public function managerCanEditVariableNoRules()
    {
        Variable::truncate();

        $manager = $this->getManager();
        $ms = new ManticoreService($manager->process, app(CurlService::class));
        self::assertEmpty($ms->getError());
        $ms->truncateRules();

        $variable = Variable::factory()
            ->create(['stream_id' => $manager->process]);
        $variable->text .= " Editted variable";

        $response = $this->actingAs($manager)
            ->json('POST', "/manager/variables/" . $variable->name,
                ['text' => $variable->text]);

        $response->assertStatus(200);

        $model = Variable::find($variable->id);

        self::assertSame($variable->name, $model->name);
        self::assertSame($variable->text, $model->text);
    }


    /**
     * @test
     */
    public function managerCanEditVariableWithRules()
    {
        Variable::truncate();

        $manager = $this->getManager();
        \Auth::setUser($manager);
        $variable = Variable::factory()
            ->create(['stream_id' => $manager->process]);


        $ms = new ManticoreService('');
        $this->instance(ManticoreService::class, $ms);

        self::assertEmpty($ms->getError());
        $ms->truncateRules();

        $rule = new Rule();
        $rule->setQuery('{{' . $variable->name . '}}');
        $rule->setFilters('json.filter = "{{' . $variable->name . '}}"');

        $newRule = $ms->addRule($rule, null, false);
        self::assertSame("Rule added", $newRule['message']);
        $insertedRuleBefore = $ms->getRuleById($newRule['data']);


        self::assertSame($rule->getQuery(), $insertedRuleBefore->getQuery());
        self::assertSame($rule->getFilters(),
            $insertedRuleBefore->getFilters());
        self::assertSame($rule->getQueryWithVariableSubstituted(),
            $insertedRuleBefore->getQueryWithVariableSubstituted());
        self::assertSame($rule->getFiltersWithVariableSubstituted(),
            $insertedRuleBefore->getFiltersWithVariableSubstituted());

        $variable->text .= " Edited variable";

        $response = $this->actingAs($manager)
            ->json('POST', "/manager/variables/" . $variable->name,
                ['text' => $variable->text]);

        $response->assertStatus(200);

        $model = Variable::find($variable->id);

        self::assertSame($variable->name, $model->name);
        self::assertSame($variable->text, $model->text);

        VariablesService::getInstance()->clean();
        $insertedRuleAfter = $ms->getRuleById($newRule['data']);
        self::assertSame($insertedRuleAfter->getQuery(),
            $insertedRuleBefore->getQuery());
        self::assertSame($insertedRuleAfter->getFilters(),
            $insertedRuleBefore->getFilters());
        self::assertNotSame($insertedRuleAfter->getQueryWithVariableSubstituted(),
            $insertedRuleBefore->getQueryWithVariableSubstituted());
        self::assertNotSame($insertedRuleAfter->getFiltersWithVariableSubstituted(),
            $insertedRuleBefore->getFiltersWithVariableSubstituted());
    }

    /**
     * @test
     */
    public function wrongVariableRollbackChanges()
    {
        Variable::truncate();

        $manager = $this->getManager();
        \Auth::setUser($manager);
        $variable = Variable::factory()
            ->create(['stream_id' => $manager->process]);
        $ms = new ManticoreService('');
        self::assertEmpty($ms->getError());
        $ms->truncateRules();

        $rule = new Rule();
        $rule->setQuery('{{' . $variable->name . '}}');

        $newRule = $ms->addRule($rule, null, false);
        self::assertSame("Rule added", $newRule['message']);
        $insertedRuleBefore = $ms->getRuleById($newRule['data']);

        self::assertSame($rule->getQuery(), $insertedRuleBefore->getQuery());
        self::assertSame($rule->getQueryWithVariableSubstituted(),
            $insertedRuleBefore->getQueryWithVariableSubstituted());

        $variable->text .= " -";

        $response = $this->actingAs($manager)
            ->json('POST', "/manager/variables/" . $variable->name,
                ['text' => $variable->text]);

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

        $model = Variable::find($variable->id);

        self::assertSame($variable->name, $model->name);
        self::assertNotSame($variable->text, $model->text);

        VariablesService::getInstance()->clean();
        $insertedRuleAfter = $ms->getRuleById($newRule['data']);
        self::assertSame($insertedRuleAfter->getQuery(),
            $insertedRuleBefore->getQuery());
        self::assertSame($insertedRuleAfter->getQueryWithVariableSubstituted(),
            $insertedRuleBefore->getQueryWithVariableSubstituted());
    }

    /**
     * @test
     */
    public function managerGetNonOwnedVariable()
    {
        $manager = $this->getManager();
        $variable = Variable::factory()->create(['stream_id' => 2]);
        $response = $this->actingAs($manager)->get("/manager/variables/"
            . $variable->name . "/?streamId=2");

        $response->assertRedirect(route('emptyAssigns'));
    }


    /**
     * @test
     */
    public function managerEditNonOwnedVariable()
    {
        Variable::truncate();

        $manager = $this->getManager();

        $variable = Variable::factory()->create(['stream_id' => 2]);
        $variable->text .= " Editted variable";

        $response = $this->actingAs($manager)
            ->json('POST', "/manager/variables/" . $variable->name,
                ['text' => $variable->text]);

        $response->assertStatus(404);
    }

    /**
     * @test
     */
    public function managerCanDeleteVariable()
    {
        Variable::truncate();
        $manager = $this->getManager();
        \Auth::setUser($manager);
        $ms = new ManticoreService($manager->process, app(CurlService::class));
        self::assertEmpty($ms->getError());
        $ms->truncateRules();

        $variable = Variable::factory()
            ->create(['stream_id' => $manager->process]);
        $response = $this->actingAs($this->getManager())
            ->json('DELETE', "/manager/variables/" . $variable->name);

        $response->assertStatus(200);
        $model = Variable::find($variable->id);
        self::assertNull($model);
    }

}
