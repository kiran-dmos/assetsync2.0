<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function hrtime(bool $asNumber = false): int { return \PauseClock::$nanoseconds; }
    function time(): int { return \PauseClock::$wall; }
}

namespace {
    $smokeBootstrapOnly = true;
    require __DIR__ . '/smoke.php';

    use GlpiPlugin\Assetsync20\AssetSyncLink;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20\AssetSyncService;
    use GlpiPlugin\Assetsync20\EntitySyncRoute;
    use GlpiPlugin\Assetsync20\FieldMapping;
    use GlpiPlugin\Assetsync20\GlpiBConnection;

    final class PauseClock
    {
        public static int $nanoseconds = 0;
        public static int $wall;
    }
    final class Toolbox
    {
        public static array $summaries = [];
        public static function logInFile(string $file, string $line): void
        {
            self::$summaries[] = json_decode(substr($line, strlen('AssetSync2.0 run ')), true, 512, JSON_THROW_ON_ERROR);
        }
    }
    final class PauseClient
    {
        public array $reads = [];
        public array $failures = [];
        public FakeGlpiBClient $client;
        public function __construct() { $this->client = new FakeGlpiBClient(); }
        public function getItem(array $connection, string $type, int $id, bool $timezone = true): array
        {
            $this->reads[] = $connection['id'];
            if (!empty($this->failures[$connection['id']])) { return array_shift($this->failures[$connection['id']]); }
            return $this->client->getItem($connection, $type, $id, $timezone);
        }
        public function updateItem(array $connection, string $type, int $id, array $input): array
        {
            return $this->client->updateItem($connection, $type, $id, $input);
        }
    }
    $checks = 0;
    function pauseCheck(bool $condition, string $message): void
    {
        global $checks;
        $checks++;
        if (!$condition) { throw new RuntimeException($message); }
    }
    function pauseSetup(array $counts = ['a' => 3, 'b' => 2], ?callable $scanClock = null): array
    {
        PauseClock::$nanoseconds = 0;
        PauseClock::$wall = time();
        Toolbox::$summaries = [];
        Config::$values = Config::$reads = [];
        Config::$beforeRead = Config::$beforeWrite = Config::$beforeDelete = null;
        PluginFieldsContainer::$options = [];
        $GLOBALS['DB'] = new FakeDB();
        AssetSyncQueue::install();
        AssetSyncLink::install();
        $remote = new PauseClient();
        $entity = 0;
        foreach ($counts as $connection => $count) {
            $entity++;
            $GLOBALS['DB']->insert('glpi_entities', ['id' => $entity, 'entities_id' => 0]);
            GlpiBConnection::save(['id' => $connection, 'active' => true]);
            EntitySyncRoute::save(['id' => 'route-' . $connection, 'glpi_b_connection_id' => $connection,
                'glpi_a_source_entity_id' => (string) $entity, 'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
            FieldMapping::save($connection, 'Computer', ['name' => ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a']]);
            for ($index = 1; $index <= $count; $index++) {
                $id = ($connection === 'a' ? 100 : 200) + $index;
                $GLOBALS['DB']->insert('glpi_computers', ['id' => $id, 'entities_id' => $entity, 'is_deleted' => 0,
                    'name' => 'Current-' . $id, 'serial' => 'SERIAL-' . $id, 'date_mod' => '2026-01-01 00:00:00']);
                AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => $id, 'glpi_b_connection_id' => $connection,
                    'route_id' => 'route-' . $connection, 'remote_items_id' => $id, 'status' => AssetSyncLink::STATUS_SYNCED]);
                $remote->client->records[$connection . ':Computer'][$id] = ['id' => $id, 'name' => 'Old', 'serial' => 'SERIAL-' . $id, 'entities_id' => 100];
                AssetSyncQueue::notify('Computer', $id, $connection);
            }
        }
        return [new AssetSyncService($remote, null, $scanClock ?? static fn (): float => 0.0), $remote];
    }
    function pauseFailure(string $cause = 'http', int $status = 503, bool $executed = true, bool $transient = true): array
    {
        return ['success' => false, 'transient' => $transient, 'message' => 'Scripted failure',
            'cause' => $cause, 'status_code' => $status, 'executed' => $executed];
    }
    function pauseSummary(): array { return Toolbox::$summaries[array_key_last(Toolbox::$summaries)]; }
    function pauseEnded(AssetSyncService $service): void
    {
        foreach (['runDeadlineNs', 'phaseDeadlineNs', 'pausedConnections'] as $property) {
            pauseCheck((new ReflectionProperty(AssetSyncService::class, $property))->getValue($service) === null, 'Run/standalone state must be cleared in finally: ' . $property);
        }
        pauseCheck((new ReflectionProperty(GlpiBConnection::class, 'httpDeadlineNs'))->getValue() === null, 'HTTP deadline context must not leak.');
        pauseCheck(!$GLOBALS['DB']->runLockHeld, 'A run must release its advisory lock.');
    }
    function pauseSelections(string $connection): int
    {
        return count(array_filter($GLOBALS['DB']->requests, static fn (array $query): bool => ($query['FROM'] ?? '') === AssetSyncQueue::TABLE
            && ($query['SELECT'][0] ?? '') === '*' && ($query['WHERE']['glpi_b_connection_id'] ?? '') === $connection));
    }

    foreach ([['transport', 0], ['http', 408], ['http', 429], ['http', 500], ['http', 503], ['http', 599]] as [$cause, $status]) {
        [$service, $remote] = pauseSetup();
        $remote->failures['a'] = [pauseFailure($cause, $status)];
        $result = $service->run(5);
        $summary = pauseSummary();
        pauseCheck($remote->reads === ['a', 'b', 'b'], 'Executed outages must stop only their connection across both queue phases.');
        pauseCheck(pauseSelections('a') === 1, 'Paused connections must be skipped before selection/claim.');
        pauseCheck($summary['jobs_attempted'] === 3 && $summary['jobs_retried'] === 1 && $summary['jobs_succeeded'] === 2 && $summary['stop_reason'] === 'connections_paused', 'Healthy jobs must continue and outage stop must be explicit.');
        pauseCheck($result === $summary['enqueued'] + 3, 'Return must remain enqueued plus processed.');
        $rows = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
        pauseCheck($rows[0]['status'] === 'retry' && $rows[0]['attempts'] === 1 && $rows[1]['status'] === 'pending' && $rows[1]['attempts'] === 0, 'Only the attempted outage job may retry; untouched jobs must retain their attempts.');
        pauseEnded($service);
        $remote->reads = [];
        $service->run(2);
        pauseCheck($remote->reads === ['a', 'a'] && pauseSummary()['jobs_succeeded'] === 2, 'A reused service must clear pauses on the next invocation without bypassing retry backoff.');
        pauseEnded($service);
    }

    foreach ([['catalog_changed', 206, true, true], ['budget_deadline', 0, false, true], ['budget_deadline', 0, true, true],
        ['', 0, false, true], ['transport', 0, false, true], ['', 0, false, false],
        ['http', 403, true, false], ['http', 404, true, false], ['http', 600, true, true]] as [$cause, $status, $executed, $transient]) {
        [$service, $remote] = pauseSetup(['a' => 2]);
        $remote->failures['a'] = [pauseFailure($cause, $status, $executed, $transient)];
        pauseCheck($service->processQueue(2) === 2 && $remote->reads === ['a', 'a'], 'Budget, semantic, setup, permission, missing and catalog changes must not pause a connection.');
        pauseCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] === ($transient ? 'retry' : 'blocked'), 'No-pause failures must retain their retry/block classification.');
        pauseEnded($service);
    }

    [$service, $remote] = pauseSetup();
    $remote->failures['a'] = [pauseFailure()];
    pauseCheck($service->processQueue(5) === 3 && $remote->reads === ['a', 'b', 'b'], 'Standalone processQueue must own a temporary pause context.');
    pauseEnded($service);
    $remote->reads = [];
    pauseCheck($service->processQueue(2) === 2 && $remote->reads === ['a', 'a'], 'Standalone pauses must clear between invocations.');
    pauseEnded($service);

    // Cleanup outages pause subsequent jobs without retrying a successful mutation.
    [$service, $remote] = pauseSetup(['a' => 2, 'b' => 1]);
    $remote->failures['a'] = [['success' => true, 'item' => $remote->client->records['a:Computer'][101],
        'cleanup_failure' => ['cause' => 'http', 'status_code' => 503, 'executed' => true]]];
    $service->run(3);
    pauseCheck($remote->reads === ['a', 'b'] && pauseSummary()['jobs_succeeded'] === 2 && pauseSummary()['jobs_retried'] === 0, 'An executed cleanup outage must isolate the connection without retrying successful work.');
    pauseEnded($service);

    [$service, $remote] = pauseSetup(['a' => 2, 'b' => 1]);
    $remote->failures['a'] = [pauseFailure('', 0, false, false) +
        ['cleanup_failure' => ['cause' => 'transport', 'status_code' => 0, 'executed' => true]]];
    $service->run(3);
    pauseCheck($remote->reads === ['a', 'b'] && pauseSummary()['jobs_blocked'] === 1 && pauseSummary()['jobs_retried'] === 0, 'A primary semantic block must stay permanent while its separate executed cleanup outage pauses the connection.');
    pauseEnded($service);

    foreach ([0, 1] as $seconds) {
        [$service, $remote] = pauseSetup(['a' => 1]);
        $service->run(1, $seconds);
        pauseCheck(pauseSummary()['jobs_attempted'] === 0 && $remote->reads === [] && pauseSelections('a') === 0, 'Only cleanup reserve remains in a one-second run; do not claim.');
        pauseCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] === 'pending', 'Reserve exhaustion must leave untouched jobs pending.');
        pauseEnded($service);
    }
    [$service, $remote] = pauseSetup(['a' => 1]);
    (new ReflectionProperty(AssetSyncService::class, 'phaseDeadlineNs'))->setValue($service, 1_000_999_999);
    pauseCheck($service->processQueue(1) === 0 && pauseSelections('a') === 0, 'Submillisecond work cutoff must reject before selection.');
    (new ReflectionProperty(AssetSyncService::class, 'phaseDeadlineNs'))->setValue($service, null);
    pauseEnded($service);

    // Slow selection/configuration must not create an untouched running lease.
    foreach (['selection', 'utc_setup'] as $stage) {
        [$service, $remote] = pauseSetup(['a' => 1]);
        if ($stage === 'selection') {
            $GLOBALS['DB']->afterRequest = static function (array $query): void {
                if (($query['SELECT'][0] ?? '') === '*' && ($query['FROM'] ?? '') === AssetSyncQueue::TABLE) { PauseClock::$nanoseconds = 30_000_000_000; }
            };
        } else {
            $GLOBALS['DB']->queryResult = static function (string $sql) {
                if (str_starts_with($sql, "SET SESSION time_zone = '+00:00'")) { PauseClock::$nanoseconds = 30_000_000_000; }
                return null;
            };
        }
        pauseCheck($service->processQueue(1) === 0 && $remote->reads === [] && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] === 'pending', 'SQL before conditional ownership must stop without claiming: ' . $stage);
        pauseEnded($service);
    }

    // Once ownership changes, do not discard the job even when the soft DB deadline passed.
    foreach (['update', 'readback', 'fresh_asset'] as $stage) {
        [$service, $remote] = pauseSetup(['a' => 1]);
        if ($stage === 'update') {
            $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields): bool {
                if ($table === AssetSyncQueue::TABLE && ($fields['status'] ?? '') === 'running') { PauseClock::$nanoseconds = 30_000_000_000; }
                return true;
            };
        } else {
            $GLOBALS['DB']->afterRequest = static function (array $query) use ($stage): void {
                if (($stage === 'readback' && ($query['SELECT'][0] ?? '') === 'started_at')
                    || ($stage === 'fresh_asset' && ($query['FROM'] ?? '') === 'glpi_computers')) { PauseClock::$nanoseconds = 30_000_000_000; }
            };
        }
        pauseCheck($service->processQueue(1) === 1 && $remote->reads === [], 'An already claimed job must resolve with zero HTTP after SQL overrun: ' . $stage);
        $row = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
        pauseCheck($row['status'] === 'retry' && $row['attempts'] === 1 && $row['payload_hash'] !== '', 'Budget exhaustion after claim must persist preparation and retry, never abandon the running lease.');
        pauseEnded($service);
    }

    foreach ([-86400, 86400] as $jump) {
        [$service, $remote] = pauseSetup(['a' => 1]);
        $GLOBALS['DB']->afterRequest = static function (array $query) use ($jump): void {
            if (($query['FROM'] ?? '') === 'glpi_computers') { PauseClock::$wall += $jump; }
        };
        $service->run(1);
        pauseCheck($remote->reads === ['a'] && pauseSummary()['jobs_succeeded'] === 1 && pauseSummary()['stop_reason'] === 'batch_limit', 'Wall clock jumps must not change monotonic run/phase budgets.');
        pauseEnded($service);
    }

    [$service, $remote] = pauseSetup(['a' => 1]);
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') { throw new RuntimeException('original processing error'); }
    };
    try { $service->run(1); throw new LogicException('Original error swallowed.'); }
    catch (RuntimeException $error) { pauseCheck($error->getMessage() === 'original processing error', 'Finally cleanup must preserve original processing errors.'); }
    pauseEnded($service);

    // Convert a standalone epoch deadline once; later wall jumps cannot replenish/consume it.
    [$service, $remote] = pauseSetup(['a' => 1]);
    $GLOBALS['DB']->afterRequest = static function (array $query): void {
        if (($query['FROM'] ?? '') === 'glpi_computers') { PauseClock::$wall += 86400; }
    };
    pauseCheck($service->processQueue(1, PauseClock::$wall + 25) === 1 && $remote->reads === ['a'], 'Standalone public epoch deadline must become one monotonic deadline at entry.');
    pauseEnded($service);

    echo "Connection pause tests passed ($checks checks).\n";
}
