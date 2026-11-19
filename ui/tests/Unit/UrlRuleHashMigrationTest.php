<?php

namespace Tests\Unit;

use App\Models\Rule;
use App\Services\ManticoreService;
use Illuminate\Support\Facades\Artisan;
use mysqli;
use Tests\TestCase;

/**
 * @group application
 */
class UrlRuleHashMigrationTest extends TestCase
{
    public function testPlanApplyAndRollbackPreserveRuleMetadata(): void
    {
        $service = new ManticoreService(1);
        self::assertSame('', $service->getError());
        $db = new mysqli(config('manticore.host'), '', '', '');
        $ruleId = null;
        $plan = tempnam(sys_get_temp_dir(), 'pq-url-plan-');
        self::assertNotFalse($plan);
        unlink($plan); // The command deliberately creates a new file.

        try {
            $rule = new Rule();
            $rule->setQuery('@merged cisa.gov | sec.gov');
            self::assertSame('Rule added', $service->addRule($rule, null, false)['message']);
            $ruleId = $service->getlastInsertId();
            self::assertGreaterThan(0, $ruleId);

            $before = $this->storedRule($db, $ruleId);
            $broken = '(@merged_host_path '.md5('gov ').' '.md5('cisa.gov ').') | '
                .'(@merged_host_path '.md5('gov').' '.md5(' sec.gov').')';
            self::assertNotSame($before['query'], $broken);
            $this->replaceQuery($db, $ruleId, $broken, $before);

            self::assertSame(0, Artisan::call('url-rules:repair', [
                '--stream' => '1', '--plan' => $plan,
            ]));
            $records = array_map('json_decode', file($plan));
            $repairs = array_values(array_filter($records, fn ($r) => $r->type === 'repair'));
            self::assertCount(1, $repairs);
            self::assertSame((string) $ruleId, $repairs[0]->id);
            self::assertSame($broken, $repairs[0]->before->query);
            self::assertSame($before['query'], $repairs[0]->after_query);
            self::assertSame($broken, $this->storedRule($db, $ruleId)['query']);

            self::assertSame(0, Artisan::call('url-rules:repair', [
                '--plan' => $plan, '--apply' => true,
            ]));
            self::assertSame($before, $this->storedRule($db, $ruleId));

            // Re-applying a completed plan must do nothing.
            self::assertSame(0, Artisan::call('url-rules:repair', [
                '--plan' => $plan, '--apply' => true,
            ]));

            self::assertSame(0, Artisan::call('url-rules:repair', [
                '--plan' => $plan, '--rollback' => true,
            ]));
            self::assertSame($broken, $this->storedRule($db, $ruleId)['query']);
            self::assertSame($before['tags'], $this->storedRule($db, $ruleId)['tags']);
        } finally {
            if ($ruleId !== null) {
                $service->deleteRule($ruleId);
            }
            if (file_exists($plan)) {
                unlink($plan);
            }
            $db->close();
        }
    }

    private function storedRule(mysqli $db, int $id): array
    {
        $row = $db->query('SELECT query, filters, tags FROM pq WHERE id='.$id)->fetch_assoc();
        self::assertIsArray($row);
        return $row;
    }

    private function replaceQuery(mysqli $db, int $id, string $query, array $row): void
    {
        $db->query("REPLACE INTO m_cluster:pq (id, query, filters, tags) VALUES ("
            .$id.", '".$db->real_escape_string($query)."', '"
            .$db->real_escape_string($row['filters'])."', '"
            .$db->real_escape_string($row['tags'])."')");
    }
}
