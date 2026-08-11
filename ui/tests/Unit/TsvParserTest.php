<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\TsvParser;
use App\Services\ManticoreService;
use App\Services\FileCacheService;
use Mockery;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group application
 */
class TsvParserTest extends TestCase
{
    protected $manticoreService;
    protected $fileCacheService;
    protected $parser;

    public function setUp(): void
    {
        parent::setUp();
        $this->manticoreService = Mockery::mock(ManticoreService::class);
        $this->fileCacheService = Mockery::mock(FileCacheService::class);
        $this->parser = new TsvParser($this->fileCacheService);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_parse_empty_content_throws_exception()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Empty TSV content');

        $this->parser->parse("", $this->manticoreService, 1);
    }

    public function test_parse_exceeds_max_columns()
    {
        $tsvContent = "query1\tfilter1\ttag1\textra1\textra2\textra3\textra4\nquery2\tfilter2\ttag2";

        $this->manticoreService->shouldReceive('addRule')->once()->andReturn(['status' => 'success']);
        $this->manticoreService->shouldReceive('getStatusCode')->once()->andReturn(Response::HTTP_OK);

        $this->fileCacheService->shouldReceive('increase')
            ->once()
            ->with(1, FileCacheService::RULE_ADD)
            ->andReturn(true);

        [$processedRows, $importErrors] = $this->parser->parse($tsvContent, $this->manticoreService, 1);

        $this->assertEquals(1, $processedRows);
        $this->assertCount(1, $importErrors);
        $this->assertEquals('Row 1 exceeds maximum of 6 columns', $importErrors[0]['message']);
        $this->assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $importErrors[0]['statusCode']);
        $this->assertEquals("query1\tfilter1\ttag1\textra1\textra2\textra3\textra4", $importErrors[0]['line']);
    }

    public function test_parse_less_than_three_columns_processes_normally()
    {
        $tsvContent = "query\tfilter\nincomplete";

        $this->manticoreService->shouldReceive('addRule')
            ->twice()
            ->andReturn(['status' => 'success']);
        $this->manticoreService->shouldReceive('getStatusCode')
            ->twice()
            ->andReturn(Response::HTTP_OK);

        $this->fileCacheService->shouldReceive('increase')
            ->twice()
            ->with(1, FileCacheService::RULE_ADD)
            ->andReturn(true);

        [$processedRows, $importErrors] = $this->parser->parse($tsvContent, $this->manticoreService, 1);

        $this->assertEquals(2, $processedRows); // Both rows should process
        $this->assertEmpty($importErrors);      // No errors expected
    }

    public function test_parse_successful_rows_with_defaults()
    {
        $tsvContent = "query1\tfilter1\ttag1\nquery2\tfilter2\ttag2\text2\t1";

        $this->manticoreService->shouldReceive('addRule')
            ->twice()
            ->andReturn(['status' => 'success']);
        $this->manticoreService->shouldReceive('getStatusCode')
            ->twice()
            ->andReturn(Response::HTTP_OK);

        $this->fileCacheService->shouldReceive('increase')
            ->twice()
            ->with(1, FileCacheService::RULE_ADD)
            ->andReturn(true);

        [$processedRows, $importErrors] = $this->parser->parse($tsvContent, $this->manticoreService, 1);

        $this->assertEquals(2, $processedRows);
        $this->assertEmpty($importErrors);
    }

    public function test_parse_with_manticore_error()
    {
        $tsvContent = "query1\tfilter1\ttag1\nquery2\tfilter2\ttag2";

        $this->manticoreService->shouldReceive('addRule')->once()->andReturn(['status' => 'success']);
        $this->manticoreService->shouldReceive('getStatusCode')->once()->andReturn(Response::HTTP_OK);
        $this->manticoreService->shouldReceive('addRule')->once()->andReturn(['message' => 'Duplicate rule']);
        $this->manticoreService->shouldReceive('getStatusCode')->once()->andReturn(Response::HTTP_CONFLICT);

        $this->fileCacheService->shouldReceive('increase')
            ->once()
            ->with(1, FileCacheService::RULE_ADD)
            ->andReturn(true);

        [$processedRows, $importErrors] = $this->parser->parse($tsvContent, $this->manticoreService, 1);

        $this->assertEquals(1, $processedRows);
        $this->assertCount(1, $importErrors);
        $this->assertEquals('Duplicate rule', $importErrors[0]['message']);
        $this->assertEquals(Response::HTTP_CONFLICT, $importErrors[0]['statusCode']);
        $this->assertEquals('query2', $importErrors[0]['query']);
        $this->assertEquals('filter2', $importErrors[0]['filters']);
        $this->assertEquals('tag2', $importErrors[0]['tags']);
    }
}
