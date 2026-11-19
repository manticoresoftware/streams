<?php

namespace App\Console\Commands;

use App\Models\Streams;
use App\Services\ManticoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * One-off, reversible repair for pre-fix URL OR rules in percolate tables.
 * The plan is both a reviewable diff and a complete backup of changed rows.
 */
class RepairUrlRuleHashes extends Command
{
    protected $signature = 'url-rules:repair
        {--stream= : Stream ID to inspect when creating the plan}
        {--all : Inspect every stream when creating the plan}
        {--plan= : Absolute path to a JSONL plan/backup file}
        {--apply : Apply an existing, validated plan}
        {--rollback : Roll back an existing, validated plan}
        {--batch=200 : Rules to read per SELECT during planning}
        {--max-updates=0 : Stop after N changes (0 = unlimited)}
        {--delay-ms=0 : Delay between REPLACE statements}';

    protected $description = 'Plan, apply or roll back repairs of URL OR-list hashes in PQ rules';

    public function handle(): int
    {
        try {
            $path = (string) $this->option('plan');
            if ($path === '' || $path[0] !== '/') {
                throw new RuntimeException('Supply an absolute --plan path.');
            }

            $apply = (bool) $this->option('apply');
            $rollback = (bool) $this->option('rollback');
            if ($apply && $rollback) {
                throw new RuntimeException('Choose --apply or --rollback, not both.');
            }

            $batch = $this->numberOption('batch', 1, 1000);
            $maxUpdates = $this->numberOption('max-updates', 0, 1000000000);
            $delay = $this->numberOption('delay-ms', 0, 60000);

            if ($apply || $rollback) {
                if ($this->option('stream') !== null || $this->option('all')) {
                    throw new RuntimeException('--stream and --all are for plan creation only.');
                }
                $this->validatePlan($path); // Validate the entire file BEFORE the first write.
                return $this->executePlan($path, $rollback, $maxUpdates, $delay);
            }

            $all = (bool) $this->option('all');
            $stream = $this->option('stream');
            if ($all === ($stream !== null)) {
                throw new RuntimeException('Choose exactly one of --stream=ID or --all.');
            }
            $ids = $all
                ? Streams::query()->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all()
                : [(string) $stream];
            foreach ($ids as $id) {
                $this->assertId($id);
                if (!Streams::find($id)) {
                    throw new RuntimeException("Stream {$id} does not exist in MySQL.");
                }
            }
            if ($ids === []) {
                throw new RuntimeException('No streams found.');
            }
            if (file_exists($path)) {
                throw new RuntimeException('Plan already exists; refusing to overwrite: '.$path);
            }
            return $this->createPlan($path, $ids, $batch);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function createPlan(string $path, array $ids, int $batch): int
    {
        $oldUmask = umask(0077);
        try {
            $fp = fopen($path, 'x');
        } finally {
            umask($oldUmask);
        }
        if ($fp === false) {
            throw new RuntimeException('Cannot create plan: '.$path);
        }
        chmod($path, 0600);
        $sha = hash_init('sha256');
        $stats = ['scanned' => 0, 'unchanged' => 0, 'repair' => 0, 'review' => 0];
        $finished = false;
        try {
            $this->writeLine($fp, [
                'type' => 'header', 'format' => 1, 'streams' => $ids,
                'created_at' => gmdate('c'),
            ], $sha);
            foreach ($ids as $id) {
                $svc = new ManticoreService($id);
                if ($svc->getError() !== '') {
                    throw new RuntimeException("Stream {$id}: ".$svc->getError());
                }
                $db = $this->connectManticore($id);
                try {
                    $lastId = '0';
                    do {
                        $sql = 'SELECT id, query, filters, tags FROM '.config('manticore.index')
                            .' WHERE id > '.$lastId.' ORDER BY id ASC LIMIT '.$batch
                            .' OPTION max_matches='.$batch;
                        $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
                        foreach ($rows as $row) {
                            $lastId = (string) $row['id'];
                            $stats['scanned']++;
                            $entry = $this->planRow($svc, $id, $row);
                            if ($entry === null) {
                                $stats['unchanged']++;
                            } else {
                                $stats[$entry['type'] === 'repair' ? 'repair' : 'review']++;
                                $this->writeLine($fp, $entry, $sha);
                                if ($entry['type'] === 'review') {
                                    $this->warn("Stream {$id}, rule {$lastId}: ".$entry['reason']);
                                }
                            }
                        }
                    } while (count($rows) === $batch);
                    $this->info("Stream {$id}: scan completed.");
                } finally {
                    $db->close();
                }
            }
            $this->writeLine($fp, [
                'type' => 'end', 'counts' => $stats, 'sha256' => hash_final($sha),
            ]);
            fflush($fp);
            if (function_exists('fsync') && !fsync($fp)) {
                throw new RuntimeException('Failed to flush the plan to disk.');
            }
            $finished = true;
        } finally {
            fclose($fp);
            if (!$finished) {
                unlink($path); // Never leave a partial plan that looks usable.
            }
        }

        $this->line('Plan/backup: '.$path);
        $this->line(json_encode($stats, JSON_THROW_ON_ERROR));
        if ($stats['review'] > 0) {
            $this->warn('Some rules require manual review and are NOT included in automatic repair.');
        }
        $this->info('No PQ rules were changed. Review and copy the backup off the pod before --apply.');
        return self::SUCCESS;
    }

    private function planRow(ManticoreService $svc, string $streamId, array $row): ?array
    {
        $query = (string) $row['query'];
        if (!str_contains($query, '_host_path') || !str_contains($query, '|')) {
            return null;
        }
        $before = [
            'query' => $query,
            'filters' => (string) $row['filters'],
            'tags' => (string) $row['tags'],
        ];
        $base = [
            'stream' => $streamId,
            'id' => (string) $row['id'],
            'before' => $before,
        ];
        try {
            $tags = json_decode($before['tags'], true, 512, JSON_THROW_ON_ERROR);
            $original = $tags['originalQuery'] ?? null;
            if (!is_string($original) || $original === '' || !str_contains($original, '|')) {
                return ['type' => 'review'] + $base + ['reason' => 'Missing original URL OR query'];
            }
            if (!empty($tags['variables']) || str_contains($original, '{{') || str_contains($original, '}}')) {
                return ['type' => 'review'] + $base + ['reason' => 'Variable-based rule; manual reconstruction required'];
            }
            $rebuilt = $svc->rebuildUrlQueryFromOriginal($original);
            if ($rebuilt === $query) {
                return null;
            }
            if ($this->withoutHashes($rebuilt) !== $this->withoutHashes($query)) {
                return ['type' => 'review'] + $base + ['reason' => 'Query differs beyond MD5 tokens'];
            }
            return ['type' => 'repair'] + $base + ['after_query' => $rebuilt];
        } catch (Throwable $e) {
            return ['type' => 'review'] + $base + ['reason' => 'Unable to safely rewrite: '.$e->getMessage()];
        }
    }

    private function withoutHashes(string $query): string
    {
        return preg_replace('/(?<![a-f0-9])[a-f0-9]{32}(?![a-f0-9])/i', '<md5>', $query);
    }

    private function validatePlan(string $path): void
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Cannot read plan: '.$path);
        }
        $sha = hash_init('sha256');
        $num = 0;
        $headerSeen = false;
        $footerSeen = false;
        $counts = ['repair' => 0, 'review' => 0];
        try {
            while (($line = fgets($fp)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($record)) {
                    throw new RuntimeException('Invalid JSONL record.');
                }
                if (!$headerSeen) {
                    if (($record['type'] ?? '') !== 'header' || ($record['format'] ?? null) !== 1) {
                        throw new RuntimeException('Unsupported plan header.');
                    }
                    $headerSeen = true;
                } elseif (($record['type'] ?? '') === 'end') {
                    if ($footerSeen || !hash_equals((string) ($record['sha256'] ?? ''), hash_final($sha))) {
                        throw new RuntimeException('Plan is incomplete or checksum does not match.');
                    }
                    foreach ($counts as $type => $n) {
                        if (($record['counts'][$type] ?? -1) !== $n) {
                            throw new RuntimeException('Plan row count mismatch.');
                        }
                    }
                    $footerSeen = true;
                    if (fgets($fp) !== false) {
                        throw new RuntimeException('Unexpected data after plan footer.');
                    }
                    break;
                } else {
                    if (!in_array($record['type'] ?? '', ['repair', 'review'], true)
                        || !is_array($record['before'] ?? null)
                        || !isset($record['stream'], $record['id'])
                    ) {
                        throw new RuntimeException('Malformed plan row.');
                    }
                    $this->assertId((string) $record['stream']);
                    $this->assertId((string) $record['id']);
                    foreach (['query', 'filters', 'tags'] as $key) {
                        if (!is_string($record['before'][$key] ?? null)) {
                            throw new RuntimeException('Malformed backup field: '.$key);
                        }
                    }
                    if ($record['type'] === 'repair' && !is_string($record['after_query'] ?? null)) {
                        throw new RuntimeException('Missing replacement query.');
                    }
                    $counts[$record['type']]++;
                    $num++;
                }
                if (!$footerSeen) {
                    hash_update($sha, $line);
                }
            }
        } finally {
            fclose($fp);
        }
        if (!$headerSeen || !$footerSeen) {
            throw new RuntimeException('Missing plan header or footer.');
        }
        $this->info('Verified plan checksum and '.$num.' plan rows.');
    }

    private function executePlan(string $path, bool $rollback, int $maxUpdates, int $delay): int
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Cannot open plan.');
        }
        $activeStream = null;
        $manticore = null;
        $dbConnectionId = null;
        $changed = 0;
        $already = 0;
        $skipped = 0;
        $review = 0;
        $partial = false;
        try {
            while (($line = fgets($fp)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($record['type'] === 'end') {
                    break;
                }
                if ($record['type'] === 'header') {
                    continue;
                }
                if ($record['type'] === 'review') {
                    $review++;
                    continue;
                }
                if ($maxUpdates > 0 && $changed >= $maxUpdates) {
                    $partial = true;
                    $this->warn('Stopped at --max-updates; run again to continue.');
                    break;
                }
                $stream = (string) $record['stream'];
                if ($activeStream !== $stream) {
                    if ($manticore !== null) {
                        $manticore->close();
                        $manticore = null;
                        $this->releaseLock($activeStream);
                    }
                    $activeStream = null;
                    $this->acquireLock($stream);
                    $activeStream = $stream;
                    $manticore = $this->connectManticore($stream);
                    $dbConnectionId = $this->lockOwner();
                }
                $this->checkLock($stream, $dbConnectionId);
                $id = (string) $record['id'];
                $before = $record['before'];
                $after = ['query' => $record['after_query'], 'filters' => $before['filters'], 'tags' => $before['tags']];
                $expected = $rollback ? $after : $before;
                $target = $rollback ? $before : $after;
                $current = $this->getRule($manticore, $id);
                if ($current === $target) {
                    $already++;
                    continue;
                }
                if ($current !== $expected) {
                    $skipped++;
                    $this->error("Stream {$stream}, rule {$id}: changed since plan, missing, or unexpected content; stopping.");
                    break;
                }
                $this->replaceRule($manticore, $stream, $id, $target);
                if ($this->getRule($manticore, $id) !== $target) {
                    throw new RuntimeException("Stream {$stream}, rule {$id}: verification failed; stopping.");
                }
                $changed++;
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        } finally {
            if ($manticore !== null) {
                $manticore->close();
            }
            if ($activeStream !== null) {
                $this->releaseLock($activeStream);
            }
            fclose($fp);
        }
        $this->line(json_encode(compact('changed', 'already', 'skipped', 'review'), JSON_THROW_ON_ERROR));
        if ($skipped > 0) {
            return self::FAILURE;
        }
        $this->info($partial ? 'Canary pass finished; more rows remain.'
            : ($rollback ? 'Rollback pass finished.' : 'Apply pass finished.'));
        return self::SUCCESS;
    }

    private function acquireLock(string $stream): void
    {
        $row = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', ['m'.$stream.'lock']);
        if ((int) ($row->acquired ?? 0) !== 1) {
            throw new RuntimeException('Unable to lock rules for stream '.$stream);
        }
    }

    private function lockOwner(): int
    {
        return (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    }

    private function checkLock(string $stream, int $owner): void
    {
        $row = DB::selectOne('SELECT IS_USED_LOCK(?) AS holder', ['m'.$stream.'lock']);
        if ((int) ($row->holder ?? 0) !== $owner) {
            throw new RuntimeException('Lost MySQL advisory lock for stream '.$stream);
        }
    }

    private function releaseLock(string $stream): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['m'.$stream.'lock']);
    }

    private function connectManticore(string $stream): mysqli
    {
        $host = str_replace('{{ STREAM_ID }}', 'm'.$stream, config('manticore.host'));
        $connection = new mysqli($host, '', '', '');
        $connection->set_charset('utf8');
        return $connection;
    }

    private function getRule(mysqli $db, string $id): ?array
    {
        $rs = $db->query('SELECT query, filters, tags FROM '.config('manticore.index').' WHERE id='.$id.' LIMIT 1');
        $row = $rs->fetch_assoc();
        $rs->free();
        return !$row ? null : [
            'query' => (string) $row['query'],
            'filters' => (string) $row['filters'],
            'tags' => (string) $row['tags'],
        ];
    }

    private function replaceRule(mysqli $db, string $stream, string $id, array $rule): void
    {
        $prefix = app()->environment('testing', 'dev') ? 'm' : 'm'.$stream;
        $sql = 'REPLACE INTO '.$prefix.'_cluster:'.config('manticore.index')
            .' (id, query, filters, tags) VALUES ('.$id
            .", '".$db->real_escape_string($rule['query'])."'"
            .", '".$db->real_escape_string($rule['filters'])."'"
            .", '".$db->real_escape_string($rule['tags'])."')";
        $db->query($sql);
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]*$/D', $id) || strlen($id) > 20) {
            throw new RuntimeException('Invalid stream or rule ID: '.$id);
        }
    }

    private function numberOption(string $name, int $min, int $max): int
    {
        $value = (string) $this->option($name);
        if (!preg_match('/^[0-9]+$/D', $value) || strlen($value) > 11 || (int) $value < $min || (int) $value > $max) {
            throw new RuntimeException('Invalid --'.$name.' value.');
        }
        return (int) $value;
    }

    private function writeLine($fp, array $record, $sha = null): void
    {
        $text = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (fwrite($fp, $text) !== strlen($text)) {
            throw new RuntimeException('Failed writing plan.');
        }
        if ($sha !== null) {
            hash_update($sha, $text);
        }
    }
}
