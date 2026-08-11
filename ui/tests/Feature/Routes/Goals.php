<?php

namespace Tests\Feature\Routes;

use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class Goals extends TestCase
{
    use AuthTrait;

    protected $model;
    protected $section;


    protected function setUp(): void
    {
        parent::setUp();
        $this->model::factory(3)->create();
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function testIndex()
    {

        $response = $this->actingAs($this->getAdmin())->get("/admin/$this->section");

        $response->assertStatus(200);
        $response->assertSee("Add Kafka $this->section");
    }

    public function testGetList()
    {
        $response = $this->actingAs($this->getAdmin())->get("/admin/$this->section/getList");
        $response->assertStatus(200);

        $countInDatabase = call_user_func_array([$this->model, 'count'], ['id']);
        if ($countInDatabase > 50) {
            $countInDatabase = 50;
        }
        $content = json_decode($response->content());
        $this->assertTrue(count($content->data) == $countInDatabase);
    }


    public function testAddGoal()
    {
        $data = $this->model::factory()->make();
        $name = Str::random(10);
        $response = $this->actingAs($this->getAdmin())->post("/admin/$this->section/add",
            [

                'name' => $name,
                'host' => $data->host,
                'topic' => $data->topic
            ]);

        $newGoals = call_user_func_array([$this->model, 'where'], [['name' => $name]])->first();

        $response->assertStatus(200);

        $this->assertNotNull($newGoals->group);

        $response->assertExactJson([
            "errors" => "Can't connect to dev.manticoresearch.com:22",
            "success" => "Record is successfully added",
            "id" => $newGoals->id,
        ]);
    }

    public function testAddNonExistHostGoal()
    {
        $data = $this->model::factory()->make();
        $name = Str::random(10);
        $response = $this->actingAs($this->getAdmin())->post("/admin/$this->section/add",
            [

                'name' => $name,
                'host' => 'localhost:290929',
                'topic' => $data->topic
            ]);

        $newGoals = call_user_func_array([$this->model, 'where'], [['name' => $name]])->first();

        $response->assertStatus(200);

        $this->assertNotNull($newGoals->group);

        $response->assertExactJson([
            "success" => "Record is successfully added",
            "id" => $newGoals->id,
            "errors" => "Can't connect to localhost:290929"
        ]);
    }

    public function testAddSourceForgetSpecifyPort()
    {
        $data = $this->model::factory()->make();
        $response = $this->actingAs($this->getAdmin())->post("/admin/$this->section/add",
            [

                'name' => Str::random(10),
                'host' => 'localhost',
                'topic' => $data->topic
            ]);


        $response->assertStatus(422);
        $response->assertExactJson(['errors' => ['host' => 'You didn\'t enter the port']]);
    }

    public function testAddGoalsWrongInputData()
    {
        $response = $this->actingAs($this->getAdmin())->post("/admin/$this->section/add",
            [
                'name' => null,
                'host' => null,
                'topic' => null
            ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'errors' => [
                "name" => "The name field is required.",
                "host" => "The host field is required.",
                "topic" => "The topic field is required."
            ]
        ]);
    }

    public function testRemoveGoals()
    {
        $goal = call_user_func([$this->model, 'first']);
        $response = $this->actingAs($this->getAdmin())->post("/admin/$this->section/delete",
            [
                'id' => $goal->id
            ]);
        $response->assertStatus(200);

        $response->assertExactJson(['message' => 'Record deleted!']);
    }

    public function testRemoveForgotId()
    {
        $response = $this->actingAs($this->getAdmin())->post("/admin/$this->section/delete",
            [
                'id' => null
            ]);
        $response->assertStatus(422);

        $response->assertExactJson(['message' => 'Pass the ID']);
    }
}
