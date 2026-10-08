<?php

declare(strict_types=1);

$attemptBootstrapOnly = true;
require __DIR__ . '/sync-attempts.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\AssetUuidOperation;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;

$fixture = attemptSetup(0);
AssetUuidOperation::bootstrap(PHP_INT_MAX);
$claims = [];
$GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields) use (&$claims): void {
    if (($fields['status'] ?? '') === 'running') {
        $claims[] = $table === AssetUuidOperation::TABLE ? 'uuid' : 'ordinary';
    }
};
foreach (['ordinary', 'uuid', 'ordinary', 'uuid'] as $turn => $first) {
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] = 'pending';
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['available_at'] = null;
    $GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = null;
    $claims = [];
    Toolbox::$entries = [];
    $ordinary = attemptOrdinarySteps($fixture);
    $uuid = attemptUuidSteps($fixture['remote']);
    if ($turn === 2) {
        $ordinary = [httpStep('initSession', [], 503)];
        $uuid = []; // Ordinary outage pauses this UUID participant for the rest of the run.
    } elseif ($turn === 3) {
        $uuid = [httpStep('initSession', [], 403)];
    }
    httpStart($first === 'ordinary' ? [...$ordinary, ...$uuid] : [...$uuid, ...$ordinary]);
    $volume = (new AssetSyncService())->run(1, 25);
    $summary = Toolbox::$entries[0];
    attemptCheck($claims === [$first, $first === 'ordinary' ? 'uuid' : 'ordinary'], 'Persisted priority alternates dispatch even after failed attempts.');
    attemptCheck(Config::$values['plugin:assetsync20']['asset_sync_next_priority'] === ($first === 'ordinary' ? 'uuid' : 'ordinary'), 'Next priority survives a new service instance.');
    attemptCheck($summary['jobs_attempted'] === 1 && $summary['uuid']['attempted'] === 1, 'Both classes can progress without exceeding either claim cap.');
    attemptCheck($volume === $summary['enqueued'] + 1 && !$GLOBALS['DB']->runLockHeld, 'Return volume excludes UUID work and every advisory lock is released.');
    attemptEnded();
}

// Due discovery must be read-only and agree with the atomic claim's exact SQL predicate.
$fixture = attemptSetup(0);
AssetUuidOperation::bootstrap(PHP_INT_MAX);
foreach ([[null, null, true], [3600, null, false], [null, 3600, false], [-60, -60, true], [3600, -60, false]] as [$next, $lease, $due]) {
    $GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = $next === null ? null : gmdate('Y-m-d H:i:s', time() + $next);
    $GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['lease_until'] = $lease === null ? null : gmdate('Y-m-d H:i:s', time() + $lease);
    $before = $GLOBALS['DB']->tables[AssetUuidOperation::TABLE];
    attemptCheck(AssetUuidOperation::hasDue() === $due && $GLOBALS['DB']->tables[AssetUuidOperation::TABLE] === $before, 'Discovery respects next_attempt and lease without changing operation state.');
}
$GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = null;
$GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['lease_until'] = null;
$GLOBALS['DB']->requests = [];
AssetUuidOperation::hasDue();
$condition = $GLOBALS['DB']->requests[0]['WHERE'][0]->expression;
AssetUuidOperation::claim();
attemptCheck($GLOBALS['DB']->requests[1]['WHERE'][0]->expression === $condition, 'hasDue and claim must share exactly one due predicate.');

// Future or leased UUID work must not shorten an ordinary attempt to the old 15-second phase.
foreach (['next_attempt', 'lease_until'] as $futureField) {
    $fixture = attemptSetup(21, true, true);
    AssetUuidOperation::bootstrap(PHP_INT_MAX);
    $GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0][$futureField] = gmdate('Y-m-d H:i:s', time() + 3600);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_next_priority' => 'uuid']);
    $steps = attemptMixedSteps($fixture);
    foreach ($steps as &$step) { $step['ms'] = 500; }
    unset($step);
    httpStart($steps);
    $fixture['service']->run(1, 25);
    $summary = Toolbox::$entries[0];
    attemptCheck($summary['jobs_succeeded'] === 1 && $summary['uuid']['attempted'] === 0 && $summary['total_ms'] === 21500, 'Non-due UUID work leaves the ordinary first attempt its full usable budget.');
    attemptCheck(Config::$values['plugin:assetsync20']['asset_sync_next_priority'] === 'uuid', 'One-class runs do not consume the other class priority.');
    attemptEnded();
}

// A UUID-only queue takes first priority even when the saved preference says ordinary.
$fixture = attemptSetup(0);
$GLOBALS['DB']->tables[AssetSyncLink::TABLE][0]['last_payload_hash'] = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['payload_hash'];
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE] = [];
httpStart(attemptUuidSteps($fixture['remote']));
$fixture['service']->run(1, 25);
$summary = Toolbox::$entries[0];
attemptCheck($summary['jobs_attempted'] === 0 && $summary['uuid']['attempted'] === 1, 'Only-due UUID work is dispatched without a phantom ordinary reservation.');
attemptEnded();

// Keep both queues due across runs whose first class nearly consumes the usable budget.
$fixture = attemptSetup(21, true, true);
AssetUuidOperation::bootstrap(PHP_INT_MAX);
foreach (['ordinary', 'uuid'] as $first) {
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] = 'pending';
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['available_at'] = null;
    $GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = null;
    Toolbox::$entries = [];
    if ($first === 'ordinary') {
        $steps = attemptMixedSteps($fixture);
        foreach ($steps as &$step) { $step['ms'] = 500; }
        unset($step);
        // The remaining phase cannot finish UUID discovery, but it still resolves its claim.
        $steps[] = httpStep('initSession', ['session_token' => 'uuid-session'], 200, 500);
        $steps[] = httpStep('getFullSession', ['glpitimezone' => 'UTC'], 200, 500);
        $steps[] = httpStep('Computer/201', $fixture['remote'], 200, 500);
        $steps[] = httpStep('Computer/201/Log', [], 200, 500);
        $steps[] = httpStep('killSession', [], 200, 500);
    } else {
        $steps = attemptUuidSteps($fixture['remote']);
        foreach ([5000, 5000, 6000, 5000, 500] as $index => $ms) { $steps[$index]['ms'] = $ms; }
        $steps[] = httpStep('initSession', [], 503, 500);
    }
    httpStart($steps);
    (new AssetSyncService())->run(1, 25);
    $summary = Toolbox::$entries[0];
    attemptCheck($summary['total_ms'] <= 25000 && $summary['uuid']['attempted'] === 1, 'Slow first-priority dispatch stays within the original total and UUID cap.');
    attemptCheck($first === 'ordinary' ? $summary['jobs_succeeded'] === 1 : $summary['uuid']['outcome'] === 'equal', 'Each continuously due class completes when its first-priority turn arrives.');
    attemptCheck(isset(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors']), 'Slow classes must leave a turn for ordinary scans.');
    attemptEnded();
}

// UUID-first primary and cleanup outages must use the ordinary connection pause classifier.
foreach (['429', '503', 'transport', 'cleanup503', 'cleanupTransport', 'finishFailure'] as $failure) {
    $fixture = attemptSetup(0);
    GlpiBConnection::save(array_replace($fixture['connection'], ['id' => 'b', 'base_url' => 'https://healthy.invalid']));
    EntitySyncRoute::save(['id' => 'route-b', 'glpi_b_connection_id' => 'b', 'glpi_a_source_entity_id' => '1',
        'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    saveTestMappings('b', 'Computer', [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name',
        'glpi_b_field_uid' => 'Computer.name', 'source_of_truth' => 'glpi_a']]);
    AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'b',
        'route_id' => 'route-b', 'remote_items_id' => 201, 'status' => AssetSyncLink::STATUS_SYNCED]);
    $fixture['service']->queueAssetIfNeeded('Computer', 101, 'b');
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_next_priority' => 'uuid']);
    if ($failure === 'finishFailure') {
        $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields): void {
            if ($table === AssetUuidOperation::TABLE && isset($fields['status']) && $fields['status'] !== 'running') {
                throw new RuntimeException('UUID result storage unavailable');
            }
        };
    }
    if (str_starts_with($failure, 'cleanup')) {
        $steps = attemptUuidSteps($fixture['remote']);
        $steps[4] = $failure === 'cleanup503' ? httpStep('killSession', [], 503) : httpStep('killSession', false, 0) + ['errno' => 7];
    } else {
        $steps = [$failure === 'transport' ? httpStep('initSession', false, 0) + ['errno' => 7]
            : httpStep('initSession', [], $failure === 'finishFailure' ? 503 : (int) $failure)];
    }
    httpStart([...$steps, ...attemptOrdinarySteps($fixture)]);
    $fixture['service']->run(2, 25);
    $summary = Toolbox::$entries[0];
    $rows = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
    attemptCheck($rows[0]['status'] === 'pending' && $rows[0]['attempts'] === 0 && $rows[1]['status'] === 'done', 'UUID-first failure leaves the failed connection unclaimed and lets the healthy connection finish: ' . $failure);
    attemptCheck($summary['jobs_succeeded'] === 1 && $summary['stop_reason'] === 'connections_paused', 'UUID failures propagate the existing run pause classification.');
    $reported = $summary['uuid']['remote_failures'];
    attemptCheck(count($reported) === 1 && $reported[0]['connection_id'] === 'a' && $reported[0]['executed']
        && array_keys($reported[0]) === ['connection_id', 'status_code', 'cause', 'executed'], 'UUID failure reporting contains only connection identity and sanitized failure facts.');
    attemptEnded();
}

// Repeated one-second bootstraps must leave a full two seconds for a 1.5-second recursive scan.
$fixture = attemptSetup(0);
for ($id = 102; $id <= 131; $id++) {
    $GLOBALS['DB']->insert('glpi_computers', ['id' => $id, 'entities_id' => 1, 'name' => 'Asset ' . $id,
        'serial' => 'SCAN-' . $id, 'is_deleted' => 0, 'date_mod' => '2026-01-01 00:00:00']);
    AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => $id, 'glpi_b_connection_id' => 'a', 'route_id' => 'route', 'remote_items_id' => $id + 100]);
}
$GLOBALS['DB']->afterInsert = static function (string $table): void {
    if ($table === AssetUuidOperation::TABLE) {
        HttpMetricsCurl::$nanoseconds += 200_000_000;
        $index = count($GLOBALS['DB']->tables[$table]) - 1;
        $GLOBALS['DB']->tables[$table][$index]['next_attempt'] = gmdate('Y-m-d H:i:s', time() + 3600);
    }
};
$GLOBALS['DB']->afterRequest = static function (array $query): void {
    if (($query['SELECT'] ?? null) === ['id', 'entities_id']) { HttpMetricsCurl::$nanoseconds += 500_000_000; }
};
for ($turn = 0; $turn < 3; $turn++) {
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] = 'pending';
    Toolbox::$entries = [];
    httpStart(attemptOrdinarySteps($fixture));
    $fixture['service']->run(1, 25);
    $summary = Toolbox::$entries[0];
    attemptCheck($summary['uuid']['seeded'] === 5 && count($GLOBALS['DB']->tables[AssetUuidOperation::TABLE]) === 5 * ($turn + 1), 'Bounded bootstrap advances without restarting or running twice.');
    attemptCheck($summary['uuid']['bootstrap_ms'] === 1000 && $summary['scan_ms'] === 1500 && $summary['total_ms'] <= 25000, 'Each run retains a 1.5-second ordinary scan after a one-second bootstrap inside 25 seconds.');
    $cursors = json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'], true);
    attemptCheck($cursors['route:Computer'] === (string) (101 + $turn), 'The recursive scan advances on every invocation despite continuous UUID bootstrap work.');
    attemptEnded();
}

// UUID storage/discovery failures must not abort an otherwise valid ordinary job.
foreach (['bootstrap', 'first_probe', 'second_probe'] as $stage) {
    $fixture = attemptSetup(0);
    if ($stage === 'bootstrap') {
        Config::$beforeRead = static function (string $context, array $names): void {
            if (in_array('uuid_link_cursor', $names, true)) {
                HttpMetricsCurl::$nanoseconds += 200_000_000;
                throw new RuntimeException('UUID discovery unavailable');
            }
        };
    } else {
        AssetUuidOperation::bootstrap(PHP_INT_MAX);
        $GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = gmdate('Y-m-d H:i:s', time() + 3600);
        $probes = 0;
        $GLOBALS['DB']->afterRequest = static function (array $query) use ($stage, &$probes): void {
            if (($query['FROM'] ?? '') === AssetUuidOperation::TABLE
                && str_contains(($query['WHERE'][0]->expression ?? ''), 'next_attempt')) {
                $probes++;
                if ($probes === ($stage === 'first_probe' ? 1 : 2)) {
                    throw new RuntimeException('UUID due query unavailable');
                }
            }
        };
    }
    httpStart(attemptOrdinarySteps($fixture));
    $fixture['service']->run(1, 25);
    $summary = Toolbox::$entries[0];
    attemptCheck($summary['jobs_succeeded'] === 1 && $summary['uuid']['outcome'] === 'unknown', 'UUID-only failure preserves ordinary completion and is explicitly unknown: ' . $stage);
    attemptCheck($summary['uuid']['attempted'] === 0 && !$GLOBALS['DB']->runLockHeld, 'A failed discovery/probe neither claims UUID work nor leaks the run lock.');
    if ($stage === 'bootstrap') {
        attemptCheck((float) $summary['uuid']['bootstrap_ms'] === 200.0, 'Failed bootstrap time remains charged to the fixed run budget.');
    }
    attemptEnded();
}
Config::$beforeRead = null;

AssetSyncService::uninstall();
attemptCheck(!isset(Config::$values['plugin:assetsync20']['asset_sync_next_priority']), 'Uninstall removes the scheduling preference.');
echo "Ordinary/UUID scheduling tests passed ($attemptChecks checks).\n";
