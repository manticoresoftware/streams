<?php

namespace Tests\Unit;

use App\Services\ManticoreService;
use PHPUnit\Framework\TestCase;

/**
 * @group application
 */
class QuerySplitTest extends TestCase
{
    /** @var string[] */
    private array $fieldNames = ['json', 'title', 'body', 'tags', 'ext_key', 'url', '\('];

    /** @test */
    public function splitsAtFieldMarkersPrecededByWhitespace(): void
    {
        $this->assertSame(
            ['((((testing | news))))', '@url hi.com'],
            ManticoreService::splitQueryByFieldMarkers('((((testing | news)))) @url hi.com', $this->fieldNames)
        );
        $this->assertSame(
            ['@(title,body) foo', '@url a.com | b.com -c.com'],
            ManticoreService::splitQueryByFieldMarkers('@(title,body) foo  @url a.com | b.com -c.com', $this->fieldNames)
        );
        $this->assertSame(
            ['@title foo', '@url x -y.com', '@json.a=1'],
            ManticoreService::splitQueryByFieldMarkers('@title foo @url x -y.com @json.a=1', $this->fieldNames)
        );
        $this->assertSame(
            ['@URL upper', '@Url mixed'],
            ManticoreService::splitQueryByFieldMarkers('@URL upper @Url mixed', $this->fieldNames)
        );
    }

    /** @test */
    public function keepsQueriesWithoutMarkersIntact(): void
    {
        $this->assertSame(['plain words only'], ManticoreService::splitQueryByFieldMarkers('plain words only', $this->fieldNames));
        $this->assertSame(['a@url b'], ManticoreService::splitQueryByFieldMarkers('a@url b', $this->fieldNames));
        $this->assertSame(['@url leading'], ManticoreService::splitQueryByFieldMarkers(' @url leading', $this->fieldNames));
        $this->assertSame([], ManticoreService::splitQueryByFieldMarkers('', $this->fieldNames));
    }

    /** @test */
    public function splitsQueriesLongerThanThePcreJitStackAllows(): void
    {
        // ~22 KB; the previous /((?!...).)*/ scan failed with PREG_JIT_STACKLIMIT_ERROR above ~8 KB
        $query = '((((testing | news | ' . implode(' | ', array_fill(0, 2000, 'padwordx')) . ')))) @url hi.com';

        $parts = ManticoreService::splitQueryByFieldMarkers($query, $this->fieldNames);

        $this->assertNotNull($parts);
        $this->assertCount(2, $parts);
        $this->assertSame('@url hi.com', $parts[1]);
    }
}
