<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function hrtime(bool $asNumber = false): int
    {
        return \RunMetricsClock::$nanoseconds += 1_000_000;
    }

    function time(): int
    {
        return \RunMetricsClock::$wall;
    }
}

namespace {
    use GlpiPlugin\Assetsync20\AssetSyncService;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20\AssetSyncLink;
    use GlpiPlugin\Assetsync20\GlpiBConnection;
    use GlpiPlugin\Assetsync20\EntitySyncRoute;

    final class RunMetricsClock
    {
        public static int $nanoseconds = 0;
        public static int $wall;
        public static int $snapshots = 0;
        public static bool $failSnapshot = false;
    }

    final class Toolbox
    {
        public static array $entries = [];
        public static bool $fail = false;

        public static function logInFile(string $file, string $entry): void
        {
            self::$entries[] = [$file, $entry];
            if (self::$fail) {
                throw new RuntimeException('private logging error');
            }
        }
    }

    $smokeBootstrapOnly = true;
    require __DIR__ . '/smoke.php';

    function runMetricsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function runMetricsSetup(int $assetCount = 1, ?object $remote = null, ?callable $scanClock = null, int $routeCount = 1): AssetSyncService
    {
        RunMetricsClock::$wall = time();
        RunMetricsClock::$snapshots = 0;
        RunMetricsClock::$failSnapshot = false;
        Toolbox::$entries = [];
        Toolbox::$fail = false;
        Config::$values = [];
        $GLOBALS['DB'] = new FakeDB();
        AssetSyncQueue::install();
        AssetSyncLink::install();
        // Snapshot SQL itself is tested independently in queue-metrics.php.
        $GLOBALS['DB']->queryResult = static function (string $sql) {
            if (!str_starts_with($sql, 'SELECT category,')) {
                return null;
            }
            RunMetricsClock::$snapshots++;
            if (RunMetricsClock::$failSnapshot) {
                return false;
            }
            if (!extension_loaded('pdo_sqlite')) {
                return false;
            }
            return (new PDO('sqlite::memory:'))->query("SELECT 'pending' AS category, 0 AS count, NULL AS oldest_age_s, 0 AS unknown_age WHERE 0");
        };
        GlpiBConnection::save([
            'id' => 'metrics', 'name' => 'private connection name', 'active' => true,
            'base_url' => 'https://private-url.invalid', 'app_token' => 'private-app-token', 'user_token' => 'private-user-token',
        ]);
        for ($route = 1; $route <= $routeCount; $route++) {
            EntitySyncRoute::save([
                'id' => sprintf('route-%02d', $route), 'glpi_b_connection_id' => 'metrics',
                'glpi_a_source_entity_id' => (string) $route, 'glpi_b_target_entity_id' => '100',
                'asset_types' => ['Computer'], 'active' => true,
            ]);
            $GLOBALS['DB']->insert('glpi_entities', ['id' => $route, 'entities_id' => 0]);
            for ($index = 1; $index <= $assetCount; $index++) {
                $id = $route * 100 + $index;
                $GLOBALS['DB']->insert('glpi_computers', [
                    'id' => $id, 'entities_id' => $route, 'is_deleted' => 0,
                    'serial' => 'private-serial-' . $id, 'name' => 'private-payload', 'date_mod' => '2026-01-01 00:00:00',
                ]);
            }
        }
        return new AssetSyncService($remote ?? new FakeGlpiBClient(), null, $scanClock ?? static fn (): float => 0.0);
    }

    function runMetricsSummary(): array
    {
        runMetricsCheck(count(Toolbox::$entries) === 1, 'Each run must emit exactly one summary.');
        [$file, $entry] = array_pop(Toolbox::$entries);
        runMetricsCheck($file === 'assetsync20' && str_starts_with($entry, 'AssetSync2.0 run ') && substr_count($entry, "\n") === 1, 'The existing log must receive one compact JSON line.');
        foreach (['private-', 'https://', 'App-Token', 'Session-Token', 'Authorization'] as $secret) {
            runMetricsCheck(!str_contains($entry, $secret), 'The summary must exclude credentials, payloads, identity and raw errors.');
        }
        $summary = json_decode(substr($entry, strlen('AssetSync2.0 run ')), true, 512, JSON_THROW_ON_ERROR);
        $outcomes = 0;
        foreach (['succeeded', 'retried', 'blocked', 'skipped', 'write_failed', 'unresolved'] as $outcome) {
            $outcomes += $summary['jobs_' . $outcome];
        }
        runMetricsCheck($outcomes === $summary['jobs_attempted'], 'Job outcomes must reconcile with attempted jobs.');
        runMetricsCheck($summary['total_ms'] >= $summary['queue_ms'] + $summary['scan_ms'] && $summary['queue_ms'] >= 0 && $summary['scan_ms'] >= 0, 'Monotonic phase durations must fit within total duration.');
        runMetricsCheck((new ReflectionProperty(AssetSyncService::class, 'runMetrics'))->getValue($GLOBALS['metricsService']) === null, 'Run state must be cleared in finally.');
        runMetricsCheck((new ReflectionProperty(GlpiBConnection::class, 'httpMetrics'))->getValue() === null && (new ReflectionProperty(GlpiBConnection::class, 'httpMetricsConnection'))->getValue() === null, 'HTTP state must be cleared in finally.');
        return $summary;
    }

    $GLOBALS['metricsService'] = $service = runMetricsSetup(2);
    runMetricsCheck($service->run(2) === 4, 'The existing enqueued plus processed return must be preserved.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['examined'] === 2 && $summary['enqueued'] === 2 && $summary['jobs_attempted'] === 2 && $summary['jobs_succeeded'] === 2, 'Same-run backfill must count examined, enqueued and successful jobs separately.');
    runMetricsCheck((float) $summary['queue_ms'] >= 2.0 && (float) $summary['scan_ms'] >= 1.0 && $summary['stop_reason'] === 'batch_limit' && $summary['scan_stop_reason'] === 'enqueue_limit', 'Both queue passes, including monotonic deadline checks, must contribute to duration with fixed stop codes.');
    runMetricsCheck(RunMetricsClock::$snapshots === 1 && $summary['http'] === [], 'One snapshot must be taken; fake remote operations must not count as HTTP.');
    runMetricsCheck($service->run(2) === 0, 'Unchanged assets must remain no-op work.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['enqueued'] === 0 && $summary['jobs_attempted'] === 0 && $summary['jobs_succeeded'] === 0 && $summary['stop_reason'] === 'no_due_jobs' && $summary['scan_stop_reason'] === 'visits_complete', 'A reused service must reset counters and describe visits without claiming inventory exhaustion.');
    runMetricsCheck(RunMetricsClock::$snapshots === 2, 'Each successive run must take one snapshot.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup(2);
    $service->queueAssetIfNeeded('Computer', 101, 'metrics');
    runMetricsCheck($service->run(1) === 1, 'A full queue batch must retain its return value.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['examined'] === 1 && $summary['enqueued'] === 0 && (float) $summary['scan_ms'] >= 1.0 && $summary['scan_stop_reason'] === 'visits_complete', 'A full queue batch must still report bounded backfill progress.');

    $remote = new class {
        public function nativeMappingContext(...$arguments): array { return (new FakeGlpiBClient())->nativeMappingContext(...$arguments); }
        public function searchBySerial(array $connection, string $itemtype, string $serial): array
        {
            return $serial === 'RETRY'
                ? ['success' => false, 'transient' => true, 'message' => 'private-remote-error']
                : ['success' => true, 'items' => [], 'total_count' => 0];
        }
        public function createItem(array $connection, string $itemtype, array $input): array
        {
            return ['success' => true, 'id' => 777];
        }
    };
    $GLOBALS['metricsService'] = $service = runMetricsSetup(5, $remote);
    $GLOBALS['DB']->tables['glpi_computers'][1]['serial'] = 'RETRY';
    $GLOBALS['DB']->tables['glpi_computers'][2]['serial'] = '';
    $GLOBALS['DB']->tables['glpi_computers'][3]['is_deleted'] = 1;
    $GLOBALS['DB']->tables['glpi_computers'][4]['entities_id'] = 99;
    foreach (range(101, 105) as $id) {
        AssetSyncQueue::enqueue('Computer', $id, 'metrics', 'route-01', 'hash', null);
    }
    runMetricsCheck($service->run(5) === 7, 'Mixed outcomes must consume the queue batch and report the two freshly prepared scan enqueues.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['jobs_attempted'] === 5 && $summary['jobs_succeeded'] === 1 && $summary['jobs_retried'] === 1 && $summary['jobs_blocked'] === 1 && $summary['jobs_skipped'] === 2, 'Success, retry, block and out-of-scope exits must be counted accurately.');
    runMetricsCheck(array_column($GLOBALS['DB']->tables[AssetSyncQueue::TABLE], 'status') === ['done', 'retry', 'blocked', 'done', 'done'], 'Observation must preserve job transitions.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup();
    $service->queueAssetIfNeeded('Computer', 101, 'metrics');
    $GLOBALS['DB']->beforeUpdate = static fn (string $table, array $fields): bool => ($fields['status'] ?? '') !== 'done';
    runMetricsCheck($service->run(1) === 1, 'A failed finish write must preserve the existing processed count.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['jobs_write_failed'] === 1 && $summary['jobs_succeeded'] === 0, 'A rejected finish write must not be reported as success.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup();
    $service->queueAssetIfNeeded('Computer', 101, 'metrics');
    RunMetricsClock::$failSnapshot = true;
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') {
            throw new RuntimeException('private-original-error');
        }
    };
    try {
        $service->run(1);
        throw new LogicException('The original exception was swallowed.');
    } catch (RuntimeException $error) {
        runMetricsCheck($error->getMessage() === 'private-original-error', 'Reporting must preserve the original exception.');
    }
    $summary = runMetricsSummary();
    runMetricsCheck($summary['stop_reason'] === 'error' && $summary['jobs_unresolved'] === 1 && $summary['queue'] === ['available' => false], 'Error runs must reconcile and disclose unavailable snapshots.');
    $GLOBALS['DB']->afterRequest = null;
    RunMetricsClock::$failSnapshot = false;
    runMetricsCheck($service->run(1) === 0, 'A fresh run must preserve the recent running claim.');
    runMetricsCheck(runMetricsSummary()['jobs_attempted'] === 0, 'Error state must not leak into the next run.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup();
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') {
            throw new RuntimeException('private-scan-error');
        }
    };
    runMetricsCheck($service->run(1) === 0, 'A failed candidate query must report an incomplete scope without partial scan work.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['jobs_attempted'] === 0 && $summary['examined'] === 0 && $summary['incomplete_scope_count'] === 1
        && $summary['incomplete_scopes'] === [['route_id' => 'route-01', 'reason' => 'query_failed']], 'Candidate query failures must be explicitly reported without raw errors.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup(1, null, static function (): float {
        throw new RuntimeException('private-scan-error');
    });
    try {
        $service->run(1);
        throw new LogicException('The scan exception was swallowed.');
    } catch (RuntimeException $error) {
        runMetricsCheck($error->getMessage() === 'private-scan-error', 'The original scan exception must propagate.');
    }
    $summary = runMetricsSummary();
    runMetricsCheck($summary['stop_reason'] === 'error' && $summary['scan_stop_reason'] === 'error' && $summary['jobs_attempted'] === 0, 'An interrupted scan must not report completed visits.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup();
    Toolbox::$fail = true;
    runMetricsCheck($service->run(1) === 2, 'A logging exception must not change the run result.');
    runMetricsSummary();

    $scanCalls = 0;
    $GLOBALS['metricsService'] = $service = runMetricsSetup(1, null, static function () use (&$scanCalls): float {
        return ++$scanCalls === 1 ? 0.0 : 2.0;
    });
    runMetricsCheck($service->run(1) === 0, 'An expired scan budget must enqueue nothing.');
    $summary = runMetricsSummary();
    runMetricsCheck($scanCalls === 2 && $summary['scan_stop_reason'] === 'scan_budget' && $summary['examined'] === 0, 'Metric timing must not make extra calls to the injected scan clock.');

    $budget = 0.0;
    $GLOBALS['metricsService'] = $service = runMetricsSetup(1, null, static function () use (&$budget): float { return $budget; });
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$budget): void {
        $column = $query['SELECT'][0] ?? null;
        if ($column instanceof \Glpi\DBAL\QueryExpression && $column->alias === 'date_mod_epoch') {
            $budget = 2.0;
        }
    };
    $service->run(1);
    $summary = runMetricsSummary();
    runMetricsCheck($summary['examined'] === 1 && $summary['enqueued'] === 0 && $summary['scan_stop_reason'] === 'scan_budget', 'A lookup stopped before preparation must still count as examined.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup(0, null, null, 12);
    $service->run(100);
    runMetricsCheck(runMetricsSummary()['scan_stop_reason'] === 'visit_limit', 'Empty visits must report the ten-visit cap.');

    $GLOBALS['metricsService'] = $service = runMetricsSetup();
    $service->queueAssetIfNeeded('Computer', 101, 'metrics');
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') {
            RunMetricsClock::$wall += 30;
        }
    };
    runMetricsCheck($service->run(2) === 1, 'Wall clock changes must not cancel in-flight work.');
    $summary = runMetricsSummary();
    runMetricsCheck($summary['stop_reason'] === 'no_due_jobs' && $summary['scan_stop_reason'] === 'visits_complete' && $summary['jobs_succeeded'] === 1, 'Monotonic phase timing must continue despite a wall clock jump.');

    echo "Run metrics tests passed.\n";
}
