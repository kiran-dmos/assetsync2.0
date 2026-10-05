<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;

final class Toolbox
{
    public static array $entries = [];

    public static function logInFile(string $file, string $entry): void
    {
        self::$entries[] = $entry;
    }
}

$checks = 0;
function scopeCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scopeSetup(int $count, bool $deep = false): AssetSyncService
{
    $GLOBALS['DB'] = new FakeDB();
    Config::$values = [];
    Toolbox::$entries = [];
    for ($id = 1; $id <= $count; $id++) {
        $GLOBALS['DB']->insert('glpi_entities', ['id' => $id, 'entities_id' => $id === 1 ? 0 : ($deep ? $id - 1 : 1)]);
    }
    return new AssetSyncService(new FakeGlpiBClient(), null, static fn (): float => 0.0);
}

function scopeRead(AssetSyncService $service, array $route = [], ?int $deadline = null): array
{
    return (new ReflectionMethod(AssetSyncService::class, 'routeEntityIds'))->invoke($service, $route + [
        'id' => 'scope', 'glpi_a_source_entity_id' => '1', 'include_child_entities' => true,
    ], $deadline ?? time() + 25, 2.0);
}

foreach ([false, true] as $deep) {
    foreach ([999, 1000, 1001] as $count) {
        $service = scopeSetup($count, $deep);
        $scope = scopeRead($service);
        scopeCheck($scope['complete'] === ($count <= 1000), 'Source-inclusive wide/deep cap boundary must be explicit.');
        scopeCheck($count <= 1000 ? $scope['ids'] === array_map('strval', range(1, $count)) : $scope['ids'] === [] && $scope['reason'] === 'entity_cap', 'An overflow must never expose the first 1000 IDs.');
        $queries = $GLOBALS['DB']->requests;
        $frontiers = array_values(array_filter($queries, static fn (array $query): bool => isset($query['WHERE']['NOT'])));
        scopeCheck($frontiers[0]['LIMIT'] === 1000 && $frontiers[0]['WHERE']['NOT']['id'] === [1], 'Frontier query must exclude seen IDs and fetch remaining plus one.');
        if ($count === 1000) {
            scopeCheck(end($frontiers)['LIMIT'] === 1, 'Exact cap must still probe the last frontier to prove completeness.');
        }
        if (!$deep) {
            scopeCheck(count($queries) <= 3, 'Wide trees must use batched frontier queries, not one query per child.');
        }
    }
}

$service = scopeSetup(1);
$GLOBALS['DB']->tables['glpi_entities'] = [['id' => 0, 'entities_id' => 0], ['id' => 1, 'entities_id' => 0]];
scopeCheck(scopeRead($service, ['glpi_a_source_entity_id' => '0'])['ids'] === ['0', '1'], 'GLPI self-parented root 0 is a valid tree, not a cycle.');
foreach ([
    [['id' => 1, 'entities_id' => 1]],
    [['id' => 1, 'entities_id' => 3], ['id' => 2, 'entities_id' => 1], ['id' => 3, 'entities_id' => 2]],
    [['id' => 1, 'entities_id' => 0], ['id' => 2, 'entities_id' => 1], ['id' => 2, 'entities_id' => 1]],
    [['id' => 1, 'entities_id' => 0], ['id' => 'bad', 'entities_id' => 1]],
    [],
] as $tree) {
    $service = scopeSetup(1);
    $GLOBALS['DB']->tables['glpi_entities'] = $tree;
    $scope = scopeRead($service);
    scopeCheck(!$scope['complete'] && $scope['ids'] === [] && $scope['reason'] === 'invalid_tree', 'Missing/invalid rows and cycles must fail without partial IDs.');
}
$service = scopeSetup(1);
scopeCheck(scopeRead($service, ['glpi_a_source_entity_id' => '-1'])['reason'] === 'invalid_tree', 'Invalid source IDs must fail.');
$GLOBALS['DB']->tables['glpi_entities'] = [['id' => 0, 'entities_id' => 1], ['id' => 1, 'entities_id' => 0]];
scopeCheck(scopeRead($service, ['glpi_a_source_entity_id' => '0'])['reason'] === 'invalid_tree', 'Root 0 must not accept a non-root parent cycle.');
$GLOBALS['DB'] = new class {};
scopeCheck(scopeRead($service)['reason'] === 'unavailable', 'Missing DB API must not return source-only scope.');
$GLOBALS['DB'] = null;
scopeCheck(scopeRead($service)['reason'] === 'unavailable', 'Missing DB must fail closed.');
$GLOBALS['DB'] = new class {
    public function request(array $query) { return false; }
};
scopeCheck(scopeRead($service)['reason'] === 'query_failed', 'A false query result is not an empty tree.');
$GLOBALS['DB'] = new class {
    private int $queries = 0;
    public function request(array $query)
    {
        return match (++$this->queries) {
            1 => [['id' => 1, 'entities_id' => 0]],
            2 => [['id' => 2, 'entities_id' => 1]],
            default => false,
        };
    }
};
$scope = scopeRead($service);
scopeCheck(!$scope['complete'] && $scope['ids'] === [] && $scope['reason'] === 'query_failed', 'A failed later frontier must discard already collected descendants.');
$service = scopeSetup(2);
$GLOBALS['DB']->afterRequest = static function (array $query): void { throw new RuntimeException('private database failure'); };
scopeCheck(scopeRead($service)['reason'] === 'query_failed', 'Thrown query failures must remain explicit and sanitized.');
$service = scopeSetup(2);
scopeCheck(scopeRead($service, [], time() - 1)['reason'] === 'expired' && $GLOBALS['DB']->requests === [], 'Expired traversal must not issue a query.');
$now = 0.0;
$service = scopeSetup(1000);
$service = new AssetSyncService(new FakeGlpiBClient(), null, static function () use (&$now): float { return $now; });
$GLOBALS['DB']->afterRequest = static function (array $query) use (&$now): void {
    if (($query['LIMIT'] ?? null) === 1 && isset($query['WHERE']['NOT'])) { $now = 2.0; }
};
$scope = scopeRead($service);
scopeCheck(!$scope['complete'] && $scope['ids'] === [] && $scope['reason'] === 'expired', 'Expiry while proving the last frontier must discard the entire scope.');

function scopeScanSetup(): AssetSyncService
{
    $service = scopeSetup(1001);
    AssetSyncQueue::install();
    AssetSyncLink::install();
    GlpiBConnection::save(['id' => 'scan', 'active' => true]);
    foreach (['a-incomplete' => 1, 'b-healthy' => 2000] as $id => $source) {
        EntitySyncRoute::save([
            'id' => $id, 'glpi_b_connection_id' => 'scan', 'glpi_a_source_entity_id' => (string) $source,
            'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true,
            'include_child_entities' => true,
        ]);
    }
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 2000, 'entities_id' => 0]);
    foreach ([1, 2000] as $id) {
        $GLOBALS['DB']->insert('glpi_computers', ['id' => $id, 'entities_id' => $id, 'is_deleted' => 0, 'name' => 'Asset', 'serial' => 'S-' . $id]);
    }
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_scan_cursors' => '{"a-incomplete:Computer":"777","b-healthy:Computer":"9999"}']);
    $GLOBALS['DB']->requests = [];
    return $service;
}

$service = scopeScanSetup();
scopeCheck($service->enqueueBackfill(10) === 1, 'A healthy route must continue after an incomplete route.');
$cursors = json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'], true);
scopeCheck($cursors === ['a-incomplete:Computer' => '777', 'b-healthy:Computer' => '2000'], 'Incomplete scope must not wrap/advance; healthy empty-after-cursor scope may wrap normally.');
$assetQueries = array_values(array_filter($GLOBALS['DB']->requests, static fn (array $query): bool => isset($query['WHERE']['is_deleted'])));
scopeCheck(count($assetQueries) === 2 && array_column(array_column($assetQueries, 'WHERE'), 'entities_id') === [[2000], [2000]], 'No asset query may use an incomplete scope.');
scopeCheck(json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_last_visited'], true)[1] === 'b-healthy', 'Tuple fairness must advance beyond incomplete scopes.');
scopeCheck(count(Toolbox::$entries) === 1 && str_contains(Toolbox::$entries[0], 'incomplete scan scope') && str_contains(Toolbox::$entries[0], 'entity_cap'), 'Standalone scanning must warn clearly about incomplete scope.');

$service = scopeScanSetup();
scopeCheck($service->run(2) === 2, 'Completeness checks must preserve run enqueued-plus-processed return.');
scopeCheck(count(Toolbox::$entries) === 1, 'A run must use one existing summary, not extra incomplete-scope log entries.');
$summary = json_decode(substr(Toolbox::$entries[0], strlen('AssetSync2.0 run ')), true, 512, JSON_THROW_ON_ERROR);
scopeCheck($summary['incomplete_scope_count'] === 1 && $summary['incomplete_scopes'] === [['route_id' => 'a-incomplete', 'reason' => 'entity_cap']], 'Run summary must expose only bounded route IDs and fixed reasons.');
scopeCheck($summary['examined'] === 1 && $summary['jobs_attempted'] === 1 && $summary['jobs_succeeded'] === 1, 'Incomplete visits must not count assets or jobs.');

foreach (['failed', 'expired'] as $case) {
    $service = scopeScanSetup();
    $now = 0.0;
    $service = new AssetSyncService(new FakeGlpiBClient(), null, static function () use (&$now): float { return $now; });
    $GLOBALS['DB']->afterRequest = static function (array $query) use ($case, &$now): void {
        if (($query['FROM'] ?? '') === 'glpi_entities' && ($query['WHERE']['id'] ?? null) === 1) {
            if ($case === 'failed') { throw new RuntimeException('private database failure'); }
            $now = 2.0;
        }
    };
    $service->enqueueBackfill(10);
    $cursors = json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'], true);
    scopeCheck($cursors['a-incomplete:Computer'] === '777', 'Failure/expiry must not advance an existing cursor.');
    scopeCheck(json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_last_visited'], true)[1] === ($case === 'expired' ? 'a-incomplete' : 'b-healthy'), 'Visited incomplete tuple must be persisted even on expiry.');
    scopeCheck(!str_contains(implode('', Toolbox::$entries), 'private database failure'), 'Warnings must not include raw DB errors.');
}

$service = scopeScanSetup();
EntitySyncRoute::delete('b-healthy');
$template = EntitySyncRoute::find('a-incomplete');
for ($id = 1; $id <= 10; $id++) {
    $template['id'] = 'c-incomplete-' . $id;
    EntitySyncRoute::save($template);
}
scopeCheck($service->run(10) === 0, 'Eleven incomplete routes must not enqueue partial scope work.');
$summary = json_decode(substr(Toolbox::$entries[0], strlen('AssetSync2.0 run ')), true, 512, JSON_THROW_ON_ERROR);
scopeCheck($summary['incomplete_scope_count'] === 10 && count($summary['incomplete_scopes']) === 10 && $summary['scan_stop_reason'] === 'visit_limit', 'Incomplete-scope summary must stay bounded by the existing ten-visit cap.');

echo "Local scope completeness tests passed ($checks checks).\n";
