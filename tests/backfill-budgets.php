<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;

// Reuse smoke's fakes without running its assertions when invoked directly.
if (!class_exists(FakeDB::class, false)) {
    $smokeBootstrapOnly = true;
    require __DIR__ . '/smoke.php';
}

$backfillChecks = 0;

function backfillCheck(bool $condition, string $message): void
{
    global $backfillChecks;
    $backfillChecks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function backfillSetup(int $routeCount, int $assetsPerRoute = 0, ?callable $clock = null): AssetSyncService
{
    $GLOBALS['DB'] = new FakeDB();
    Config::$values = [];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    GlpiBConnection::save([
        'id' => 'scan',
        'name' => 'Scan',
        'base_url' => 'https://scan.example.com',
        'app_token' => 'app',
        'user_token' => 'user',
        'active' => '1',
    ]);

    // Reverse configuration order to exercise deterministic sorting.
    for ($number = $routeCount; $number >= 1; $number--) {
        EntitySyncRoute::save([
            'id' => sprintf('route-%02d', $number),
            'glpi_b_connection_id' => 'scan',
            'glpi_a_source_entity_id' => (string) $number,
            'glpi_a_source_entity_name' => 'Entity ' . $number,
            'glpi_b_target_entity_id' => '100',
            'asset_types' => ['Computer'],
            'active' => '1',
        ]);
        $GLOBALS['DB']->insert('glpi_entities', ['id' => $number, 'entities_id' => 0]);
        for ($index = 1; $index <= $assetsPerRoute; $index++) {
            $id = $number * 100 + $index;
            $GLOBALS['DB']->insert('glpi_computers', [
                'id' => $id,
                'entities_id' => $number,
                'is_deleted' => 0,
                'name' => 'Asset ' . $id,
                'serial' => 'SCAN-' . $id,
                'date_mod' => '2026-01-01 00:00:00',
            ]);
        }
    }

    $GLOBALS['DB']->requests = [];

    return new AssetSyncService(new FakeGlpiBClient(), null, $clock ?? static fn (): float => 0.0);
}

function backfillQueries(): array
{
    return array_values(array_filter(
        $GLOBALS['DB']->requests,
        static fn (array $query): bool => ($query['SELECT'] ?? []) === ['id', 'entities_id'] && isset($query['WHERE']['is_deleted'])
    ));
}

function backfillLoadedIds(): array
{
    $ids = [];
    foreach ($GLOBALS['DB']->requests as $query) {
        $column = $query['SELECT'][0] ?? null;
        if ($column instanceof \Glpi\DBAL\QueryExpression && $column->alias === 'date_mod_epoch') {
            $ids[] = $query['WHERE']['id'];
        }
    }

    return $ids;
}

function backfillCursor(string $routeId, string $itemtype = 'Computer'): ?string
{
    $cursors = json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'] ?? '{}', true);

    return $cursors[$routeId . ':' . $itemtype] ?? null;
}

function backfillLastVisit(): ?array
{
    return json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_last_visited'] ?? 'null', true);
}

function backfillJob(int $itemsId): ?array
{
    return $GLOBALS['DB']->firstRow(AssetSyncQueue::TABLE, ['itemtype' => 'Computer', 'items_id' => $itemsId, 'glpi_b_connection_id' => 'scan']);
}

$backfillMainDb = $GLOBALS['DB'];
$backfillMainConfig = Config::$values;

try {
    $service = backfillSetup(12, 6);
    foreach ($GLOBALS['DB']->tables['glpi_computers'] as $asset) {
        $id = (int) $asset['id'];
        backfillCheck($service->queueAssetIfNeeded('Computer', $id, 'scan'), 'Candidate setup must enqueue each asset.');
        $queued = backfillJob($id);
        if ($id % 3 !== 0) {
            AssetSyncLink::save([
                'itemtype' => 'Computer',
                'items_id' => $id,
                'glpi_b_connection_id' => 'scan',
                'route_id' => $queued['route_id'],
                'status' => $id % 3 === 1 ? AssetSyncLink::STATUS_SYNCED : AssetSyncLink::STATUS_BLOCKED_CONFIGURATION,
                'last_payload_hash' => $queued['payload_hash'],
            ]);
        }
    }
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE] = array_values(array_filter(
        $GLOBALS['DB']->tables[AssetSyncQueue::TABLE],
        static fn (array $job): bool => (int) $job['items_id'] % 3 === 0
    ));
    $beforeQueue = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(100) === 0, 'Unchanged, rejected, and already queued candidates must enqueue zero jobs.');
    $queries = backfillQueries();
    backfillCheck(count($queries) === 10 && count(backfillLoadedIds()) === 50, 'Zero-enqueue scans must stop at 10 visits and 50 examined occurrences; got ' . count($queries) . ' queries and ' . count(backfillLoadedIds()) . ' lookups.');
    backfillCheck(array_column($queries, 'LIMIT') === array_fill(0, 10, 5), 'Every candidate query must cap each visit at five rows.');
    backfillCheck(array_map(static fn (array $query): int => $query['WHERE']['entities_id'][0], $queries) === range(1, 10), 'Visits must follow sorted tuple order.');
    backfillCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === $beforeQueue, 'Scanning unchanged queued candidates must preserve their queue rows.');
    backfillCheck(backfillLastVisit() === ['scan', 'route-10', 'Computer'], 'No-enqueue visits must advance the saved tuple.');
    for ($number = 1; $number <= 10; $number++) {
        backfillCheck(backfillCursor(sprintf('route-%02d', $number)) === (string) ($number * 100 + 5), 'Every examined rejection must advance only its asset cursor.');
    }
    backfillCheck(backfillCursor('route-11') === null, 'Unvisited routes must retain their cursors.');

    unset(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'], Config::$values['plugin:assetsync20']['asset_sync_scan_last_visited']);
    $budgetNow = 0.0;
    $startedLookups = 0;
    $service = new AssetSyncService(new FakeGlpiBClient(), null, static function () use (&$budgetNow): float { return $budgetNow; });
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$startedLookups, &$budgetNow): void {
        $column = $query['SELECT'][0] ?? null;
        if ($column instanceof \Glpi\DBAL\QueryExpression && $column->alias === 'date_mod_epoch' && ++$startedLookups === 50) {
            $budgetNow = 2.0;
        }
    };
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(100) === 0 && $startedLookups === 50 && count(backfillLoadedIds()) === 50, 'The 50-occurrence limit must include a started lookup stopped before preparation.');
    backfillCheck(backfillCursor('route-10') === '1004' && backfillCursor('route-11') === null, 'A stop on lookup 50 must preserve that row for retry and never begin visit 11.');
    backfillCheck(backfillLastVisit() === ['scan', 'route-10', 'Computer'] && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === $beforeQueue, 'Stopped preparation must advance rotation without changing queue work.');
    $GLOBALS['DB']->afterRequest = null;
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(100) === 0, 'The next no-enqueue scan must remain bounded.');
    backfillCheck(array_map(static fn (array $query): int => $query['WHERE']['entities_id'][0], backfillQueries()) === [11, 12, 1, 2, 3, 4, 5, 6, 7, 8], 'A later scan must begin after the saved visit and visit each tuple once.');
    $GLOBALS['DB']->requests = [];
    $service->enqueueBackfill(100);
    backfillCheck(in_array(1005, backfillLoadedIds(), true) && backfillCursor('route-10') === '1006', 'Rotation must return to the deferred 50th candidate without skipping it.');

    $service = backfillSetup(12);
    backfillCheck($service->enqueueBackfill(100) === 0 && count(backfillQueries()) === 10, 'Empty visits must count toward the ten-visit limit.');
    backfillCheck(backfillLastVisit() === ['scan', 'route-10', 'Computer'], 'Empty visits must persist rotation progress.');
    $GLOBALS['DB']->requests = [];
    $service->enqueueBackfill(100);
    backfillCheck(backfillQueries()[0]['WHERE']['entities_id'] === [11], 'An empty scan must rotate on the next invocation.');

    $service = backfillSetup(1, 20);
    backfillCheck($service->enqueueBackfill(100) === 5, 'One visit must enqueue at most five candidates.');
    backfillCheck(backfillLoadedIds() === range(101, 105) && count(backfillQueries()) === 1, 'A lone tuple must be visited only once per invocation.');
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(100) === 5 && backfillLoadedIds() === range(106, 110), 'A later scan must resume after the last examined asset.');

    $service = backfillSetup(3, 5);
    backfillCheck($service->enqueueBackfill(7) === 7, 'Backfill must respect the remaining enqueue allowance.');
    backfillCheck(array_column(backfillQueries(), 'LIMIT') === [5, 2], 'Queries must shrink to the remaining batch allowance.');
    backfillCheck(backfillCursor('route-02') === '202' && backfillCursor('route-03') === null, 'Batch exhaustion must leave later rows and routes untouched.');

    $service = backfillSetup(2, 3);
    foreach ([101, 201, 102, 202, 103, 203] as $id) {
        $GLOBALS['DB']->requests = [];
        // Recreate the service to prove rotation is persisted in Config.
        $service = new AssetSyncService(new FakeGlpiBClient(), null, static fn (): float => 0.0);
        backfillCheck($service->enqueueBackfill(1) === 1 && backfillLoadedIds() === [$id], 'Successive batch-one scans must rotate and resume without skipping assets.');
        backfillCheck(array_column(backfillQueries(), 'LIMIT') === [1], 'Batch-one queries must request only one candidate.');
    }
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(1) === 0 && count(backfillQueries()) === 4, 'Each exhausted tuple may issue exactly one initial and one wrap query.');
    backfillCheck(backfillLoadedIds() === [101, 201], 'Wrapped already queued occurrences must still count as examined.');

    $service = backfillSetup(0);
    $GLOBALS['DB']->tables['glpi_printers'] = [];
    foreach (['z', 'a'] as $id) {
        GlpiBConnection::save(['id' => $id, 'name' => $id, 'active' => '1']);
    }
    $routes = [
        ['id' => 'route-a', 'glpi_b_connection_id' => 'z', 'glpi_a_source_entity_id' => '1', 'asset_types' => ['Computer']],
        ['id' => 'route-z', 'glpi_b_connection_id' => 'a', 'glpi_a_source_entity_id' => '3', 'asset_types' => ['Computer']],
        ['id' => 'route-a', 'glpi_b_connection_id' => 'a', 'glpi_a_source_entity_id' => '2', 'asset_types' => ['Printer', 'Computer']],
    ];
    foreach ($routes as &$route) {
        $route['active'] = true;
    }
    unset($route);
    $routes[] = $routes[2];
    $routes[] = ['id' => 'disabled', 'glpi_b_connection_id' => 'a', 'active' => false, 'asset_types' => ['Computer']];
    $routes[] = ['id' => 'missing', 'glpi_b_connection_id' => 'missing', 'active' => true, 'asset_types' => ['Computer']];
    Config::setConfigurationValues('plugin:assetsync20', ['entity_sync_routes' => json_encode($routes)]);
    $GLOBALS['DB']->requests = [];
    $service->enqueueBackfill(100);
    backfillCheck(array_map(static fn (array $query): array => [$query['FROM'], $query['WHERE']['entities_id'][0]], backfillQueries()) === [
        ['glpi_computers', 2], ['glpi_printers', 2], ['glpi_computers', 3], ['glpi_computers', 1],
    ], 'The flat list must sort by connection, route, and type and exclude duplicate and ineligible tuples.');
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_last_visited' => '["a","route-a","Computer"]']);
    $GLOBALS['DB']->requests = [];
    $service->enqueueBackfill(100);
    backfillCheck(backfillQueries()[0]['FROM'] === 'glpi_printers' && count(backfillQueries()) === 4, 'A saved tuple must resume at the next asset type and wrap the flat list once.');

    foreach ([null, '', '{bad', 'null', '{}', '[]', '["scan","route-01"]', '["scan",1,"Computer"]', '["scan","route-01",""]', ['bad'], '["scan","removed","Computer"]', '["scan","route-01","Printer"]'] as $saved) {
        $service = backfillSetup(12);
        if ($saved !== null) {
            Config::$values['plugin:assetsync20']['asset_sync_scan_last_visited'] = $saved;
        }
        $service->enqueueBackfill(100);
        backfillCheck(backfillQueries()[0]['WHERE']['entities_id'] === [1] && count(backfillQueries()) === 10, 'Missing, malformed, removed, or changed-type tuples must restart at the first eligible tuple.');
    }
    $service = backfillSetup(12);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_last_visited' => ' [ "scan", "route-05", "Computer" ] ']);
    $service->enqueueBackfill(100);
    backfillCheck(backfillQueries()[0]['WHERE']['entities_id'] === [6], 'Valid saved JSON must tolerate whitespace.');

    foreach (['deleted', 'disabled'] as $change) {
        $service = backfillSetup(12);
        Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_last_visited' => '["scan","route-05","Computer"]']);
        if ($change === 'deleted') {
            EntitySyncRoute::delete('route-05');
        } else {
            $route = EntitySyncRoute::find('route-05');
            $route['active'] = false;
            EntitySyncRoute::save($route);
        }
        $GLOBALS['DB']->requests = [];
        $service->enqueueBackfill(100);
        $entities = array_map(static fn (array $query): int => $query['WHERE']['entities_id'][0], backfillQueries());
        backfillCheck($entities === [1, 2, 3, 4, 6, 7, 8, 9, 10, 11], 'Deleted or disabled saved routes must fall back predictably and never consume a visit.');
    }
    foreach (['deleted', 'disabled'] as $change) {
        $service = backfillSetup(1);
        Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_last_visited' => '["scan","route-01","Computer"]']);
        if ($change === 'deleted') {
            GlpiBConnection::delete('scan');
        } else {
            $connection = GlpiBConnection::find('scan');
            $connection['active'] = false;
            GlpiBConnection::save($connection);
        }
        GlpiBConnection::save(['id' => 'other', 'active' => true]);
        EntitySyncRoute::save(['id' => 'other-route', 'glpi_b_connection_id' => 'other', 'glpi_a_source_entity_id' => '2', 'glpi_a_source_entity_name' => 'Other', 'asset_types' => ['Computer'], 'active' => true]);
        $GLOBALS['DB']->requests = [];
        $service->enqueueBackfill(100);
        backfillCheck(count(backfillQueries()) === 1 && backfillLastVisit() === ['other', 'other-route', 'Computer'], 'Deleted or disabled saved connections must fall back to remaining eligible tuples.');
    }
    $service = backfillSetup(0);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_last_visited' => '["scan","gone","Computer"]']);
    backfillCheck($service->enqueueBackfill(100) === 0 && backfillQueries() === [] && backfillLastVisit() === ['scan', 'gone', 'Computer'], 'No eligible tuples must perform no scan or overwrite progress.');

    foreach (['not-json', '{"route-01:Computer":"bad"}', '{"route-01:Computer":"-5"}', '{"route-01:Computer":[]}'] as $saved) {
        $service = backfillSetup(1, 3);
        Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => $saved]);
        backfillCheck($service->enqueueBackfill(1) === 1 && backfillCursor('route-01') === '101', 'Malformed asset cursors must resume from zero.');
    }
    $service = backfillSetup(1, 6);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => '{"route-01:Computer":"999"}']);
    backfillCheck($service->enqueueBackfill(3) === 3 && backfillLoadedIds() === [101, 102, 103], 'An exhausted asset cursor must wrap to the first rows.');
    backfillCheck(array_column(backfillQueries(), 'LIMIT') === [3, 3] && backfillCursor('route-01') === '103', 'The single wrap query must obey all remaining allowances and save only examined rows.');
    $service = backfillSetup(1);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => '{"route-01:Computer":"999"}']);
    $service->enqueueBackfill(100);
    backfillCheck(count(backfillQueries()) === 2 && backfillCursor('route-01') === '999', 'An empty wrap must not move the asset cursor or repeat within the invocation.');

    $clockCalls = 0;
    $service = backfillSetup(2, 3, static function () use (&$clockCalls): float {
        return ++$clockCalls === 1 ? 0.0 : 2.0;
    });
    backfillCheck($service->enqueueBackfill(10) === 0 && backfillQueries() === [] && backfillLastVisit() === null, 'Expiry before a visit must leave all progress untouched.');

    $now = 0.0;
    $clock = static function () use (&$now): float { return $now; };
    $service = backfillSetup(2, 3, $clock);
    $GLOBALS['DB']->afterTableExists = static function (string $table) use (&$now): void {
        if ($table === 'glpi_computers') {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && backfillQueries() === [], 'Expiry before the initial candidate query must stop that query.');
    backfillCheck(backfillCursor('route-01') === null && backfillCursor('route-02') === null, 'An incomplete visit must rotate without creating or advancing asset cursors.');

    $now = 0.0;
    $service = backfillSetup(1, 6, $clock);
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$now): void {
        if (($query['SELECT'] ?? []) === ['id', 'entities_id'] && isset($query['WHERE']['is_deleted'])) {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && backfillLoadedIds() === [] && backfillCursor('route-01') === '0', 'Rows fetched at expiry must not be prepared or skipped by the cursor.');
    $GLOBALS['DB']->afterRequest = null;
    backfillCheck($service->enqueueBackfill(10) === 5 && backfillLoadedIds() === range(101, 105), 'A fresh scan must revisit every row left unexamined by expiry.');

    $now = 0.0;
    $service = backfillSetup(1, 3, $clock);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => '{"route-01:Computer":"999"}']);
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$now): void {
        if (($query['SELECT'] ?? []) === ['id', 'entities_id'] && isset($query['WHERE']['is_deleted'])) {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && count(backfillQueries()) === 1 && backfillCursor('route-01') === '999', 'Expiry after an empty initial query must prevent wrapping and preserve its cursor.');

    $now = 0.0;
    $service = backfillSetup(1, 3, $clock);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => '{"route-01:Computer":"999"}']);
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$now): void {
        if (($query['SELECT'] ?? []) === ['id', 'entities_id'] && ($query['WHERE']['id'] ?? null) === ['>', 0]) {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && count(backfillQueries()) === 2 && backfillCursor('route-01') === '999', 'Expiry after fetching a wrapped page must retain the previous cursor until a row is examined.');
    $GLOBALS['DB']->afterRequest = null;
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(10) === 3 && backfillLoadedIds() === [101, 102, 103], 'An unexamined wrapped page must be revisited without skips.');

    $now = 0.0;
    $service = backfillSetup(1, 3, $clock);
    $route = EntitySyncRoute::find('route-01');
    $route['include_child_entities'] = true;
    EntitySyncRoute::save($route);
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 2, 'entities_id' => 1]);
    $GLOBALS['DB']->insert('glpi_computers', ['id' => 201, 'entities_id' => 2, 'is_deleted' => 0]);
    $GLOBALS['DB']->requests = [];
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$now): void {
        if (($query['FROM'] ?? '') === 'glpi_entities') {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && count($GLOBALS['DB']->requests) === 1 && backfillQueries() === [], 'A partial descendant list must never be used for an asset query after expiry.');

    $now = 0.0;
    $service = backfillSetup(1, 3, $clock);
    $route = EntitySyncRoute::find('route-01');
    $route['include_child_entities'] = true;
    EntitySyncRoute::save($route);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => '{"route-01:Computer":"999"}']);
    $entityQueries = 0;
    $GLOBALS['DB']->requests = [];
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$entityQueries, &$now): void {
        if (($query['FROM'] ?? '') === 'glpi_entities' && ++$entityQueries === 3) {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && count(backfillQueries()) === 1 && $entityQueries === 3, 'Expiry during wrap source verification must prevent the actual wrap candidate query.');
    backfillCheck(backfillCursor('route-01') === '999', 'A cancelled wrap query must preserve its asset cursor.');

    $now = 0.0;
    $service = backfillSetup(1, 3, $clock);
    $requestsAfterExpiry = 0;
    $GLOBALS['DB']->afterRequest = static function (array $query) use (&$now, &$requestsAfterExpiry): void {
        if ($now >= 2.0) {
            $requestsAfterExpiry++;
        }
        $column = $query['SELECT'][0] ?? null;
        if ($column instanceof \Glpi\DBAL\QueryExpression && $column->alias === 'date_mod_epoch') {
            $now = 2.0;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 0 && backfillLoadedIds() === [101] && backfillCursor('route-01') === '0', 'Expiry while loading a row must prevent preparation and leave it available for retry.');
    backfillCheck($requestsAfterExpiry === 0, 'Expiry while loading a row must prevent new route queries.');
    backfillCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === [] && $GLOBALS['DB']->tables[AssetSyncLink::TABLE] === [], 'Stopping before preparation must not write queue or link state.');
    $GLOBALS['DB']->afterRequest = null;
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(10) === 3 && backfillLoadedIds() === [101, 102, 103], 'A stopped preparation must resume at the same candidate without skips.');

    $now = 0.0;
    $service = backfillSetup(1, 6, $clock);
    $GLOBALS['DB']->afterInsert = static function (string $table) use (&$now): void {
        if ($table === AssetSyncQueue::TABLE) {
            $now = 2.5;
        }
    };
    backfillCheck($service->enqueueBackfill(10) === 1 && backfillLoadedIds() === [101] && backfillCursor('route-01') === '101', 'An in-flight enqueue must finish past the soft budget and save exactly that row.');
    $GLOBALS['DB']->afterInsert = null;
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->enqueueBackfill(10) === 5 && backfillLoadedIds() === range(102, 106), 'A partial scan must resume after the completed operation without skipping fetched rows.');

    $now = 0.0;
    $service = backfillSetup(2, 3, $clock);
    $GLOBALS['DB']->afterInsert = static function (string $table) use (&$now): void {
        if ($table === AssetSyncQueue::TABLE) {
            $now = 1.1;
        }
    };
    backfillCheck($service->enqueueBackfill(10, time() + 1) === 1 && count(backfillQueries()) === 1, 'The remaining overall deadline must shorten the two-second scan budget.');
    $service = backfillSetup(1, 3);
    backfillCheck($service->enqueueBackfill(10, time() - 1) === 0 && backfillQueries() === [] && backfillLastVisit() === null, 'An expired overall deadline must prevent all visits.');

    $service = backfillSetup(2, 5);
    backfillCheck($service->queueAssetIfNeeded('Computer', 101, 'scan'), 'Queue-first setup must enqueue existing work.');
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->run(3) === 6, 'A partial queue batch must return independently bounded enqueued plus processed volume.');
    backfillCheck(array_column(backfillQueries(), 'LIMIT') === [3, 1], 'Backfill queries must retain the full batch enqueue allowance after one existing job.');
    $jobs = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
    backfillCheck(array_column($jobs, 'items_id') === [101, 102, 103, 201] && array_column($jobs, 'status') === ['done', 'done', 'done', 'pending'], 'Queue-first partial batches must process new jobs in the same run without exceeding the processing cap.');

    $service = backfillSetup(2, 3);
    $service->queueAssetIfNeeded('Computer', 101, 'scan');
    $service->queueAssetIfNeeded('Computer', 102, 'scan');
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_last_visited' => '["scan","route-02","Computer"]']);
    $GLOBALS['DB']->requests = [];
    backfillCheck($service->run(1) === 2 && count(backfillQueries()) === 2 && backfillCursor('route-01') === '101' && backfillCursor('route-02') === '201', 'A full queue batch must still advance bounded scan cursors and enqueue allowance.');
    backfillCheck(backfillJob(102)['status'] === 'pending', 'The full queue batch must leave excess queue jobs pending.');

    $now = 0.0;
    $service = backfillSetup(1, 3, $clock);
    $GLOBALS['DB']->afterInsert = static function (string $table) use (&$now): void {
        if ($table === AssetSyncQueue::TABLE) {
            $now = 2.5;
        }
    };
    backfillCheck($service->run(3) === 2 && backfillJob(101)['status'] === 'done', 'The queue must drain same-run work after the soft scan budget expires.');
    backfillCheck(backfillCursor('route-01') === '101' && count($GLOBALS['DB']->tables[AssetSyncQueue::TABLE]) === 1, 'Same-run draining must not start further scan preparation.');

    Config::setConfigurationValues('plugin:assetsync20', ['unrelated' => 'keep']);
    AssetSyncService::uninstall();
    backfillCheck(!isset(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors']) && !isset(Config::$values['plugin:assetsync20']['asset_sync_scan_last_visited']) && !isset(Config::$values['plugin:assetsync20']['asset_sync_queue_last_visited']), 'Service uninstall must delete scan and queue rotation Config keys.');
    backfillCheck(Config::$values['plugin:assetsync20']['unrelated'] === 'keep', 'Service uninstall must preserve unrelated Config values.');
} finally {
    $GLOBALS['DB'] = $backfillMainDb;
    Config::$values = $backfillMainConfig;
}

echo 'Backfill budget and rotation tests passed (' . $backfillChecks . " checks).\n";
