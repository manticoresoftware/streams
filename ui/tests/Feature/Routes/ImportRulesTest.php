<?php

namespace Tests\Feature\Routes;

use Illuminate\Http\UploadedFile;
use App\Services\ManticoreService;
use App\Services\FileCacheService;
use Spatie\FlareClient\Http\Exceptions\NotFound;
use Symfony\Component\HttpFoundation\Response;
use Mockery;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class ImportRulesTest extends TestCase
{
    use AuthTrait;

    protected $user;

    public function setUp(): void
    {
        parent::setUp();
        $this->user = $this->getManager();
        if (!$this->user) {
            throw new NotFound("Can't find manager. Run DatabaseTest first");
        }
        $this->actingAs($this->user);
    }

    public function test_empty_file_returns_error()
    {
        $response = $this->postJson('/manager/importRules', []);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson([
                'message' => 'Please upload a TSV file',
                'errors' => [
                    'import' => ['Please upload a TSV file']
                ]
            ]);
    }

    public function test_empty_tsv_document_returns_error()
    {
        $tsvContent = "";
        $file = UploadedFile::fake()->createWithContent('rules.tsv', $tsvContent);

        $response = $this->postJson('/manager/importRules', [
            'import' => $file
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson([
                'message' => 'The TSV file is empty',
                'errors' => [
                    'import' => ['The TSV file is empty']
                ]
            ]);
    }

    public function test_invalid_file_type_returns_validation_error()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);
        $response = $this->postJson('/manager/importRules', [
            'import' => $file
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['import'])
            ->assertJson([
                'message' => 'The file must be a TSV or TXT file (and 1 more error)',
                'errors' => [
                    'import' => [
                        'The file must be a TSV or TXT file',
                        "The TSV file is empty"
                    ]
                ]
            ]);
    }

    public function test_successful_import_with_valid_tsv()
    {
        $tsvContent = "query1\tfilter1\ttag1\nquery2\tfilter2\ttag2\text2\t1\t0";
        $file = UploadedFile::fake()->createWithContent('rules.tsv', $tsvContent);

        $manticoreService = Mockery::mock(ManticoreService::class);
        $manticoreService->shouldReceive('addRule')->twice()->andReturn(['status' => 'success']);
        $manticoreService->shouldReceive('getStatusCode')->twice()->andReturn(Response::HTTP_OK);
        $this->app->instance(ManticoreService::class, $manticoreService);

        $this->partialMock(FileCacheService::class, function ($mock) {
            $mock->shouldReceive('increase')
                ->twice()
                ->with($this->user->id, FileCacheService::RULE_ADD)
                ->andReturn(true);
        });

        $response = $this->postJson('/manager/importRules', [
            'import' => $file
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success'
            ]);
    }

    public function test_partial_success_with_manticore_errors()
    {
        $tsvContent = "query1\tfilter1\ttag1\nquery2\tfilter2\ttag2";
        $file = UploadedFile::fake()->createWithContent('rules.tsv', $tsvContent);

        $manticoreService = Mockery::mock(ManticoreService::class);
        $manticoreService->shouldReceive('addRule')->once()->andReturn(['status' => 'success']);
        $manticoreService->shouldReceive('getStatusCode')->once()->andReturn(Response::HTTP_OK);
        $manticoreService->shouldReceive('addRule')->once()->andReturn(['message' => 'Invalid rule']);
        $manticoreService->shouldReceive('getStatusCode')->once()->andReturn(Response::HTTP_BAD_REQUEST);
        $this->app->instance(ManticoreService::class, $manticoreService);

        $this->partialMock(FileCacheService::class, function ($mock) {
            $mock->shouldReceive('increase')
                ->once()
                ->with($this->user->id, FileCacheService::RULE_ADD)
                ->andReturn(true);
        });

        $response = $this->postJson('/manager/importRules', [
            'import' => $file
        ]);

        $response->assertStatus(Response::HTTP_MULTI_STATUS)
            ->assertJsonStructure([
                'status',
                'message' => ['errors' => [
                    '*' => ['query', 'filters', 'tags', 'message', 'statusCode']
                ]]
            ])
            ->assertJsonFragment([
                'message' => 'Invalid rule',
                'statusCode' => Response::HTTP_BAD_REQUEST
            ])
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_exceeds_max_columns()
    {
        $tsvContent = "query\tfilter\ttag\textra1\textra2\textra3\textra4\nquery\tfilter\ttag";
        $file = UploadedFile::fake()->createWithContent('rules.tsv', $tsvContent);

        $manticoreService = Mockery::mock(ManticoreService::class);
        $manticoreService->shouldReceive('addRule')->once()->andReturn(['status' => 'success']);
        $manticoreService->shouldReceive('getStatusCode')->once()->andReturn(Response::HTTP_OK);
        $this->app->instance(ManticoreService::class, $manticoreService);

        $this->partialMock(FileCacheService::class, function ($mock) {
            $mock->shouldReceive('increase')
                ->once()
                ->with($this->user->id, FileCacheService::RULE_ADD)
                ->andReturn(true);
        });

        $response = $this->postJson('/manager/importRules', [
            'import' => $file
        ]);

        $response->assertStatus(Response::HTTP_MULTI_STATUS)
            ->assertJsonStructure([
                'status',
                'message' => ['errors' => [
                    '*' => ['message', 'statusCode', 'line']
                ]]
            ])
            ->assertJsonFragment([
                'message' => "Row 1 exceeds maximum of 6 columns",
                'statusCode' => Response::HTTP_UNPROCESSABLE_ENTITY
            ])
            ->assertJsonFragment(['status' => 'success']);
    }
}
