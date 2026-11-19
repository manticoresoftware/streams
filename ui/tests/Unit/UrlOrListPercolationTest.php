<?php

namespace Tests\Unit;

use App\Models\Rule;
use App\Services\ManticoreService;
use mysqli;
use Tests\TestCase;

/**
 * @group application
 */
class UrlOrListPercolationTest extends TestCase
{
    public function testUrlOrListMatchesEachHost(): void
    {
        $service = new ManticoreService('');
        self::assertSame('', $service->getError());

        $connection = new mysqli(config('manticore.host'), '', '', '');
        $ruleId = null;

        try {
            $ruleId = $this->insertRule(
                $service,
                '@merged cisa.gov | sec.gov | bleepingcomputer.com'
            );

            // These tokens represent the transformed document, not the rule.
            foreach ([
                'cisa.gov' => ['gov', 'cisa.gov'],
                'sec.gov' => ['gov', 'sec.gov'],
                'bleepingcomputer.com' => ['com', 'bleepingcomputer.com'],
            ] as $host => $tokens) {
                self::assertContains($ruleId, $this->matchingRuleIds($connection, $tokens), $host);
            }

            self::assertNotContains(
                $ruleId,
                $this->matchingRuleIds($connection, ['org', 'example.org']),
                'An unrelated host must not match'
            );
        } finally {
            if ($ruleId !== null) {
                $service->deleteRule($ruleId);
            }
            $connection->close();
        }
    }

    public function testUrlExclusionStillWorks(): void
    {
        $service = new ManticoreService('');
        self::assertSame('', $service->getError());

        $connection = new mysqli(config('manticore.host'), '', '', '');
        $ruleId = null;

        try {
            $ruleId = $this->insertRule($service, '@merged cisa.gov -example.com');

            self::assertContains(
                $ruleId,
                $this->matchingRuleIds($connection, ['gov', 'cisa.gov'])
            );
            self::assertNotContains(
                $ruleId,
                $this->matchingRuleIds($connection, ['gov', 'cisa.gov', 'com', 'example.com']),
                'A document containing an excluded host must not match'
            );
        } finally {
            if ($ruleId !== null) {
                $service->deleteRule($ruleId);
            }
            $connection->close();
        }
    }

    private function insertRule(ManticoreService $service, string $query): int
    {
        $rule = new Rule();
        $rule->setQuery($query);
        $result = $service->addRule($rule, null, false);
        self::assertSame('Rule added', $result['message'] ?? null);

        $id = $service->getlastInsertId();
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function matchingRuleIds(mysqli $connection, array $tokens): array
    {
        $document = json_encode([
            'merged_host_path' => implode(' ', array_map('md5', $tokens)),
        ], JSON_THROW_ON_ERROR);

        $sql = "CALL PQ ('pq', ('" . $connection->real_escape_string($document)
            . "'), 1 as docs, 1 as query)";
        $result = $connection->query($sql);
        $ids = array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id'));
        $result->free();

        return $ids;
    }
}
