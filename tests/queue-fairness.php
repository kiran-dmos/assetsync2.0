<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function time(): int
    {
        return \FairQueueClock::$wall;
    }
}

namespace {
    use GlpiPlugin\Assetsync20\AssetSyncLink;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20\AssetSyncService;
    use GlpiPlugin\Assetsync20\BillingFieldConfig;
    use GlpiPlugin\Assetsync20\EntitySyncRoute;
    use GlpiPlugin\Assetsync20\FieldMapping;
    use GlpiPlugin\Assetsync20\FieldsText;
    use GlpiPlugin\Assetsync20\GlpiBConnection;

    final class FairQueueClock
    {
        public static int $wall;
    }

    final class Toolbox
    {
        public static array $entries = [];
        public static bool $fail = false;

        public static function logInFile(string $file, string $entry): void
        {
            self::$entries[] = json_decode(substr($entry, strlen('AssetSync2.0 run ')), true, 512, JSON_THROW_ON_ERROR);
            if (self::$fail) {
                throw new RuntimeException('log failure');
            }
        }
    }

    FairQueueClock::$wall = time();
    $smokeBootstrapOnly = true;
    require __DIR__ . '/smoke.php';
    $checks = 0;

    function fairCheck(bool $condition, string $message): void
    {
        global $checks;
        $checks++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function fairSetup(array $counts = ['a' => 1, 'b' => 1, 'c' => 1], ?callable $scanClock = null): AssetSyncService
    {
        FairQueueClock::$wall = time();
        Toolbox::$entries = [];
        Toolbox::$fail = false;
        Config::$values = [];
        Config::$beforeRead = Config::$beforeWrite = Config::$beforeDelete = null;
        PluginFieldsContainer::$options = [];
        $GLOBALS['DB'] = new FakeDB();
        AssetSyncQueue::install();
        AssetSyncLink::install();
        // Reverse saved order; queue turns must use SQL order, not configuration order.
        foreach (array_reverse(array_keys($counts)) as $id) {
            GlpiBConnection::save(['id' => $id, 'name' => $id, 'active' => true]);
        }
        $entity = 0;
        foreach ($counts as $connectionId => $count) {
            $entity++;
            EntitySyncRoute::save([
                'id' => 'route-' . $connectionId, 'glpi_b_connection_id' => $connectionId,
                'glpi_a_source_entity_id' => (string) $entity, 'glpi_a_source_entity_name' => 'Source',
                'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true,
            ]);
            $GLOBALS['DB']->insert('glpi_entities', ['id' => $entity, 'entities_id' => 0]);
            for ($index = 1; $index <= $count; $index++) {
                fairAsset($entity * 100 + $index, $entity);
            }
        }
        $remote = new FakeGlpiBClient();
        $service = new AssetSyncService($remote, null, $scanClock ?? static fn (): float => 0.0);
        $entity = 0;
        foreach ($counts as $connectionId => $count) {
            $entity++;
            for ($index = 1; $index <= $count; $index++) {
                fairCheck($service->queueAssetIfNeeded('Computer', $entity * 100 + $index, $connectionId), 'Fixture preparation must enqueue matching hashes.');
            }
        }
        $GLOBALS['DB']->requests = [];
        $GLOBALS['DB']->sqlQueries = [];
        return $service;
    }

    function fairAsset(int $id, int $entity): void
    {
        $GLOBALS['DB']->insert('glpi_computers', [
            'id' => $id, 'entities_id' => $entity, 'is_deleted' => 0,
            'name' => 'Asset ' . $id, 'serial' => 'FAIR-' . $id, 'date_mod' => '2026-01-01 00:00:00',
        ]);
    }

    function fairSummary(): array
    {
        $summary = array_pop(Toolbox::$entries);
        fairCheck(is_array($summary), 'A run must retain its sanitized summary.');
        return $summary;
    }

    function fairDueQueries(): array
    {
        return array_values(array_filter($GLOBALS['DB']->requests, static fn (array $query): bool => isset($query['GROUPBY'])));
    }

    function fairCursor(string $connectionId): ?string
    {
        return json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'] ?? '{}', true)['route-' . $connectionId . ':Computer'] ?? null;
    }

    function fairEnded(): void
    {
        foreach ([GlpiBConnection::class, EntitySyncRoute::class, FieldMapping::class, BillingFieldConfig::class, FieldsText::class] as $owner) {
            fairCheck((new ReflectionProperty($owner, 'runCache'))->getValue() === null, 'Run cache must clear on every exit: ' . $owner);
        }
        fairCheck((new ReflectionProperty(GlpiBConnection::class, 'httpMetrics'))->getValue() === null, 'HTTP observations must clear on every exit.');
    }

    $service = fairSetup(['a' => 50, 'b' => 1, 'c' => 1]);
    $claims = [];
    $events = [];
    $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields, array $where) use (&$claims, &$events): bool {
        if ($table === AssetSyncQueue::TABLE && ($fields['status'] ?? '') === 'running') {
            $row = $GLOBALS['DB']->firstRow($table, ['id' => $where['id']]);
            $claims[] = [$row['glpi_b_connection_id'], $row['items_id']];
            $events[] = 'claim';
        }
        return true;
    };
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$events): void {
        if (($query['SELECT'] ?? []) === ['id', 'entities_id']) {
            $events[] = 'scan';
        }
    };
    foreach (['a', 'b', 'c'] as $index => $id) {
        // New service instances prove that turns persist, not just rotate in memory.
        $service = new AssetSyncService(new FakeGlpiBClient(), null, static fn (): float => 0.0);
        fairCheck($service->run(1) === 1, 'Already prepared full queues must process exactly one job.');
        $summary = fairSummary();
        fairCheck($summary['jobs_attempted'] === 1 && $summary['jobs_succeeded'] === 1 && $summary['enqueued'] === 0, 'Fair turns must preserve the batch cap and job outcomes.');
        fairCheck(Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === $id, 'Each connection must get its persisted turn within three batch-one runs.');
        fairCheck(fairCursor('a') === (string) (101 + $index), 'A full queue must still advance scan cursors each run.');
        fairEnded();
    }
    fairCheck($claims === [['a', 101], ['b', 201], ['c', 301]], 'Fifty older jobs on A must not starve B or C.');
    fairCheck($events[0] === 'claim' && in_array('scan', $events, true), 'Run must remain queue-first with bounded scans afterwards.');
    fairCheck(count(fairDueQueries()) === 3, 'Batch-one runs must fetch due IDs once per first pass, not cache them across runs.');
    fairCheck(!$GLOBALS['DB']->runLockHeld, 'Successful runs must release their advisory lock.');
    $service = fairSetup(['a' => 2, 'B' => 1, 'c' => 1]);
    foreach (['B', 'a', 'c'] as $id) {
        fairCheck($service->run(1) === 1 && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === $id, 'Mixed-case IDs must use the same bytewise order in SQL and PHP after a connection drains.');
    }

    $service = fairSetup(['a' => 3, 'b' => 3, 'c' => 3]);
    $claims = [];
    $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields, array $where) use (&$claims): bool {
        if ($table === AssetSyncQueue::TABLE && ($fields['status'] ?? '') === 'running') {
            $claims[] = $GLOBALS['DB']->firstRow($table, ['id' => $where['id']])['items_id'];
        }
        return true;
    };
    fairCheck($service->processQueue(9) === 9 && $claims === [101, 201, 301, 102, 202, 302, 103, 203, 303], 'Each round must take one job per connection in per-connection ID order.');
    fairCheck(count(fairDueQueries()) === 1, 'A queue pass must fetch due IDs only once.');

    foreach ([null, [], '{}', 'removed', ' a ', 42] as $last) {
        $service = fairSetup();
        if ($last !== null) {
            Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] = $last;
        }
        fairCheck($service->processQueue(1) === 1 && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === 'a', 'Missing, malformed or removed turns must predictably restart at the first due ID.');
    }
    $service = fairSetup();
    Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] = 'c';
    fairCheck($service->processQueue(1) === 1 && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === 'a', 'The last sorted turn must wrap to the first.');
    $service = fairSetup(['a' => 1, 'b' => 0, 'c' => 1]);
    Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] = 'b';
    fairCheck($service->processQueue(1) === 1 && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === 'c', 'A drained or deleted saved ID must resume at its sorted successor, not restart at A.');

    foreach (['empty', 'failed'] as $case) {
        $service = fairSetup();
        $GLOBALS['DB']->afterRequest = static function (array $query) use ($case): void {
            if ($case === 'empty' && isset($query['GROUPBY'])) {
                foreach ($GLOBALS['DB']->tables[AssetSyncQueue::TABLE] as &$row) {
                    if ($row['glpi_b_connection_id'] === 'a') {
                        $row['status'] = 'done';
                    }
                }
                unset($row);
            }
        };
        $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields, array $where) use ($case): bool {
            return $case !== 'failed' || $table !== AssetSyncQueue::TABLE || ($fields['status'] ?? '') !== 'running'
                || $GLOBALS['DB']->firstRow($table, ['id' => $where['id']])['glpi_b_connection_id'] !== 'a';
        };
        fairCheck($service->processQueue(1) === 1 && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === 'b', 'An empty or failed claim must move to the next ready connection.');
    }
    $service = fairSetup(['a' => 1]);
    $GLOBALS['DB']->beforeUpdate = static fn (): bool => false;
    fairCheck($service->processQueue(10) === 0 && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === 'a', 'An all-failed pass must advance its turn and terminate without spinning.');

    $service = fairSetup(['a' => 0, 'disabled' => 0]);
    GlpiBConnection::save(['id' => 'disabled', 'active' => false]);
    AssetSyncQueue::enqueue('Computer', 501, 'removed', 'removed', 'hash', null);
    AssetSyncQueue::enqueue('Computer', 502, 'disabled', 'disabled', 'hash', null);
    fairCheck(AssetSyncQueue::dueConnectionIds() === ['disabled', 'removed'], 'Queue discovery must include disabled and orphan IDs, with no config join.');
    fairCheck($service->processQueue(2) === 2 && array_column($GLOBALS['DB']->tables[AssetSyncQueue::TABLE], 'status') === ['blocked', 'blocked'], 'Missing/inactive connections must be handled and blocked, not stranded.');

    $service = fairSetup(['a' => 1, 'b' => 0, 'c' => 0]);
    fairAsset(201, 2);
    fairAsset(301, 3);
    fairCheck($service->run(2) === 4, 'Return must remain enqueued plus processed with an independent full-batch scan allowance.');
    $summary = fairSummary();
    fairCheck($summary['enqueued'] === 2 && $summary['jobs_attempted'] === 2 && $summary['jobs_succeeded'] === 2, 'First queued job plus one later fair job must not exceed batch two.');
    fairCheck(array_column($GLOBALS['DB']->tables[AssetSyncQueue::TABLE], 'status') === ['done', 'done', 'pending'], 'The refreshed second pass must see new scan jobs and leave excess work pending.');
    fairCheck(count(fairDueQueries()) === 2, 'Due IDs must refresh between the two queue phases.');

    $service = fairSetup(['a' => 1]);
    $row = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
    AssetSyncQueue::retry($row['id'], 1, 'cooldown');
    $before = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
    fairCheck($service->run(1) === 0 && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === $before, 'Full-queue scanning must leave an unchanged retry and its backoff intact.');

    foreach (['status', 'attempts', 'route_id', 'payload_hash'] as $changed) {
        $service = fairSetup(['a' => 1]);
        $original = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
        $winner = [];
        $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields) use ($changed, &$winner): bool {
            if ($table === AssetSyncQueue::TABLE && ($fields['status'] ?? '') === 'pending') {
                $row = &$GLOBALS['DB']->tables[$table][0];
                $row[$changed] = match ($changed) {
                    'status' => 'running', 'attempts' => $row['attempts'] + 1,
                    'route_id' => 'concurrent-route', 'payload_hash' => 'concurrent-hash',
                };
                $winner = $row;
                unset($row);
            }
            return true;
        };
        fairCheck(!AssetSyncQueue::enqueue('Computer', 101, 'a', $original['route_id'], 'new-preparation', null), 'A prepared enqueue must fail when its selected ' . $changed . ' changed concurrently.');
        fairCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0] === $winner, 'A lost enqueue race must not overwrite the winning worker.');
    }
    $service = fairSetup(['a' => 1]);
    $row = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
    fairCheck(AssetSyncQueue::enqueue('Computer', 101, 'a', $row['route_id'], 'changed-payload', null), 'A current non-running row may still be re-prepared.');
    $row = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
    fairCheck(!AssetSyncQueue::enqueue('Computer', 101, 'a', $row['route_id'], $row['payload_hash'], null) && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0] === $row, 'Unchanged prepared rows must remain untouched.');

    // Independently expected eligibility, not derived from FakeDB's rowIsDue delegation.
    $service = fairSetup(['a' => 0]);
    $now = time();
    $fixtures = [
        [1, 'orphan', 'pending', null, null, null],
        [2, 'waiting', 'retry', $now, $now - 100, null],
        [3, 'early', 'retry', $now - 61, $now + 100, null],
        [4, 'active', 'running', null, null, $now - 10],
        [5, 'stale', 'running', null, null, $now - 1801],
        [6, 'legacy', 'retry', $now + 1000, null, null],
        [7, 'done', 'done', null, null, null],
        [8, 'blocked', 'blocked', null, null, null],
        [9, 'disabled', 'pending', null, null, null],
        [10, 'orphan', 'pending', null, null, null],
        [11, 'no-dates', 'retry', null, null, null],
    ];
    foreach ($fixtures as [$id, $connection, $status, $finished, $available, $started]) {
        $GLOBALS['DB']->insert(AssetSyncQueue::TABLE, [
            'id' => $id, 'glpi_b_connection_id' => $connection, 'itemtype' => 'Computer', 'items_id' => $id,
            'status' => $status, 'attempts' => 1, 'route_id' => 'route', 'payload_hash' => 'hash',
            'finished_at' => $finished === null ? null : date('Y-m-d H:i:s', $finished),
            'available_at' => $available === null ? null : date('Y-m-d H:i:s', $available),
            'started_at' => $started === null ? null : date('Y-m-d H:i:s', $started),
        ]);
    }
    fairCheck(AssetSyncQueue::dueConnectionIds() === ['disabled', 'early', 'legacy', 'no-dates', 'orphan', 'stale'], 'Discovery must share exact retry/stale/legacy due rules and deduplicate IDs.');
    $discovery = fairDueQueries()[0];
    fairCheck(array_column(AssetSyncQueue::claimDue(2, 'orphan'), 'id') === [1, 10], 'Connection selection must retain oldest due job order and exclude other connections.');
    $claimQuery = array_values(array_filter($GLOBALS['DB']->requests, static fn (array $query): bool => ($query['SELECT'][0] ?? '') === '*'))[0];
    fairCheck($claimQuery['WHERE']['glpi_b_connection_id'] === 'orphan' && $claimQuery['ORDER'] === 'id ASC', 'Connection filter must be part of the claim selection SQL.');
    if (extension_loaded('pdo_sqlite')) {
        $sql = new PDO('sqlite::memory:');
        $sql->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($value) => $value, 1);
        $sql->sqliteCreateFunction('LEAST', static fn (...$values) => min($values));
        $sql->sqliteCreateFunction('GREATEST', static fn (...$values) => max($values));
        $sql->sqliteCreateFunction('POW', static fn ($base, $power) => pow($base, $power), 2);
        $sql->exec('CREATE TABLE jobs (id INTEGER, glpi_b_connection_id TEXT, status TEXT, attempts INTEGER, finished_at INTEGER, available_at INTEGER, started_at INTEGER, date_mod INTEGER)');
        $insert = $sql->prepare('INSERT INTO jobs VALUES (?, ?, ?, 1, ?, ?, ?, NULL)');
        foreach ($fixtures as $fixture) {
            $insert->execute($fixture);
        }
        // SQLite BLOB sorting corresponds to MySQL's bytewise BINARY cast.
        $order = str_replace(' AS BINARY', ' AS BLOB', $discovery['ORDER']->expression);
        $ids = $sql->query('SELECT glpi_b_connection_id FROM jobs WHERE ' . $discovery['WHERE'][0]->expression . ' GROUP BY glpi_b_connection_id ORDER BY ' . $order)->fetchAll(PDO::FETCH_COLUMN);
        fairCheck($ids === ['disabled', 'early', 'legacy', 'no-dates', 'orphan', 'stale'], 'Real discovery SQL must match independently expected connection IDs.');
        $selected = $sql->prepare('SELECT id FROM jobs WHERE ' . $claimQuery['WHERE'][0]->expression . ' AND glpi_b_connection_id = ? ORDER BY id ASC');
        $selected->execute(['orphan']);
        fairCheck(array_map('intval', $selected->fetchAll(PDO::FETCH_COLUMN)) === [1, 10], 'Real connection-filtered claim SQL must match independently expected IDs.');
    } else {
        echo "Independent fairness SQL checks skipped (PDO SQLite unavailable).\n";
    }

    foreach (['busy', 'null', 'query-failed', 'exception', 'malformed'] as $failure) {
        $service = fairSetup(['a' => 1]);
        $beforeQueue = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
        $beforeConfig = Config::$values;
        if ($failure === 'busy') {
            $GLOBALS['DB']->runLockHeld = true;
        } elseif ($failure === 'null') {
            $GLOBALS['DB']->runLockResult = null;
        } else {
            $GLOBALS['DB']->queryResult = static function (string $sql) use ($failure) {
                if (!str_contains($sql, 'GET_LOCK(')) {
                    return null;
                }
                if ($failure === 'exception') {
                    throw new RuntimeException('lock API failed');
                }
                return $failure === 'malformed' ? [['acquired' => true]] : false;
            };
        }
        fairCheck($service->run(1) === 0 && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === $beforeQueue && Config::$values === $beforeConfig, 'A busy/unavailable lock must fail closed with zero claims or scan progress.');
        $summary = fairSummary();
        fairCheck($summary['jobs_attempted'] === 0 && $summary['examined'] === 0 && $summary['stop_reason'] === ($failure === 'busy' ? 'lock_busy' : 'lock_unavailable'), 'Lock exits must have clean fixed reasons and zero work metrics.');
        fairCheck(count(array_filter($GLOBALS['DB']->sqlQueries, static fn (string $sql): bool => str_contains($sql, 'RELEASE_LOCK('))) === 0, 'A run must never release a lock it did not acquire.');
        fairEnded();
    }
    $service = fairSetup(['a' => 1]);
    $GLOBALS['DB'] = new class {
    };
    fairCheck($service->run(1) === 0 && fairSummary()['stop_reason'] === 'lock_unavailable', 'Missing database lock APIs must fail closed rather than silently bypass serialization.');
    fairEnded();

    $service = fairSetup();
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') {
            throw new RuntimeException('original processing failure');
        }
    };
    try {
        $service->run(1);
        throw new LogicException('The original processing failure was swallowed.');
    } catch (RuntimeException $error) {
        fairCheck($error->getMessage() === 'original processing failure', 'Lock cleanup must preserve the original exception.');
    }
    fairCheck(!$GLOBALS['DB']->runLockHeld && Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited'] === 'a', 'Processing exceptions must release the lock and preserve visited rotation.');
    fairEnded();
    $GLOBALS['DB']->afterRequest = null;
    Toolbox::$fail = true;
    fairCheck($service->run(1) === 1 && !$GLOBALS['DB']->runLockHeld, 'A later run must acquire the released lock; logging failure must not change work or lock cleanup.');
    fairEnded();

    $service = fairSetup(['a' => 1]);
    fairCheck($service->run(1, 1) === 1 && fairSummary()['jobs_attempted'] === 1, 'A one-second limit must not reserve all available time away from the first queue job.');
    fairCheck(!$GLOBALS['DB']->runLockHeld, 'Short runs must release their advisory lock.');

    $service = fairSetup(['a' => 1]);
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') {
            FairQueueClock::$wall += 30;
        }
    };
    fairCheck($service->run(2, 25) === 1 && fairCursor('a') === null, 'An in-flight first job may finish after the soft deadline, but must not start scanning or another job.');
    fairCheck(fairSummary()['stop_reason'] === 'deadline' && !$GLOBALS['DB']->runLockHeld, 'Deadline exits must still release the run lock.');
    fairEnded();

    $service = fairSetup(['a' => 1]);
    fairCheck($service->processQueue(0) === 0 && $GLOBALS['DB']->requests === [], 'Zero queue allowance must not fetch or claim jobs.');
    $service->processQueue(1);
    Config::$values['plugin:assetsync20']['unrelated'] = 'keep';
    AssetSyncService::uninstall();
    fairCheck(!isset(Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited']) && Config::$values['plugin:assetsync20']['unrelated'] === 'keep', 'Uninstall must remove the new rotation key only with the existing scan keys.');

    echo "Queue fairness tests passed ($checks checks).\n";
}
