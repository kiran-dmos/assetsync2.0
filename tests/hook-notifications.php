<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetChangeHook;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;

$checks = 0;
function hookCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

function hookSetup(): Computer
{
    $GLOBALS['DB'] = new FakeDB();
    Config::$values = Config::$reads = [];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    foreach (['a', 'b', 'disabled', 'conflicted'] as $id) {
        GlpiBConnection::save(['id' => $id, 'active' => $id !== 'disabled']);
        EntitySyncRoute::save(['id' => $id, 'glpi_b_connection_id' => $id,
            'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    }
    EntitySyncRoute::save(['id' => 'duplicate', 'glpi_b_connection_id' => 'conflicted',
        'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '200', 'asset_types' => ['Computer'], 'active' => true]);
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
    $GLOBALS['DB']->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0,
        'name' => 'Not read by hook', 'serial' => 'Not read by hook', 'date_mod' => '2026-01-01 00:00:00']);
    $item = new Computer();
    $item->fields = ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0];
    Config::$reads = [];
    $GLOBALS['DB']->requests = $GLOBALS['DB']->sqlQueries = [];
    return $item;
}

function hookRows(): array
{
    return $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
}

function hookAssertCheap(bool $native): void
{
    hookCheck(Config::$reads === ['glpib_connections' => 1, 'entity_sync_routes' => 1], 'All connections must share one config read and one scope match, without mapping/billing reads.');
    $parents = array_values(array_filter($GLOBALS['DB']->requests, static fn ($query) => $query['FROM'] === 'glpi_computers'));
    hookCheck(count($parents) === ($native ? 0 : 1), 'Known native fields must be reused; custom/incomplete hooks need exactly one parent query.');
    if (!$native) {
        hookCheck($parents[0]['SELECT'] === ['id', 'entities_id', 'is_deleted'] && $parents[0]['LIMIT'] === 1, 'Parent query must be narrow and readonly.');
    }
    hookCheck(count(array_filter($GLOBALS['DB']->requests, static fn ($query) => $query['FROM'] === 'glpi_entities')) === 1, 'Entity traversal must happen once, not once per connection.');
    hookCheck($GLOBALS['DB']->tables[AssetSyncLink::TABLE] === [], 'Hooks must never write links or calculate payloads.');
    foreach (hookRows() as $row) {
        hookCheck($row['status'] === 'pending' && $row['needs_recheck'] === 1 && $row['route_id'] === '' && $row['payload_hash'] === '' && $row['payload_date'] === null, 'New notifications must be unprepared dirty identities.');
    }
}

$item = hookSetup();
GlpiBConnection::beginHttpMetrics();
AssetChangeHook::onAdd($item);
hookCheck(array_column(hookRows(), 'glpi_b_connection_id') === ['a', 'b', 'conflicted'], 'Eligible and conflicted active connections must insert; disabled must not.');
hookAssertCheap(true);
hookCheck(GlpiBConnection::finishHttpMetrics() === [], 'Save notifications must execute zero HTTP requests.');
hookCheck($GLOBALS['DB']->tables['glpi_computers'][0]['date_mod'] === '2026-01-01 00:00:00', 'Hook must not write the source or billing outputs.');
AssetChangeHook::onUpdate($item);
hookCheck(count(hookRows()) === 3, 'Repeated notifications must preserve identity uniqueness.');

foreach (['custom', 'partial-native'] as $kind) {
    $native = hookSetup();
    if ($kind === 'custom') {
        $item = new PluginFieldsComputerdmosasset();
        $item->fields = ['id' => 99, 'items_id' => 101, 'itemtype' => 'Computer', 'namefield' => 'Never prepare here'];
    } else {
        $item = $native;
        $item->fields = ['id' => 101];
    }
    AssetChangeHook::onUpdate($item);
    hookCheck(array_column(hookRows(), 'items_id') === [101, 101, 101], 'Custom hooks must use parent identity, never child ID.');
    hookAssertCheap(false);
}

foreach (['outscope', 'deleted', 'missing', 'disabled'] as $kind) {
    $item = hookSetup();
    AssetSyncQueue::notify('Computer', 101, 'removed');
    AssetSyncQueue::notify('Computer', 101, 'disabled');
    if ($kind === 'outscope') { $item->fields['entities_id'] = 9; }
    if ($kind === 'deleted') { $item->fields['is_deleted'] = 1; }
    if ($kind === 'missing') { $item->fields = ['id' => 101]; $GLOBALS['DB']->tables['glpi_computers'] = []; }
    if ($kind === 'disabled') {
        foreach (['a', 'b', 'conflicted'] as $id) { GlpiBConnection::save(['id' => $id, 'active' => false]); }
    }
    $GLOBALS['DB']->sqlQueries = [];
    AssetChangeHook::onUpdate($item);
    hookCheck(array_column(hookRows(), 'glpi_b_connection_id') === ['removed', 'disabled'], 'Unscoped/deleted/missing/disabled assets must only notify existing queue identities.');
    $writes = array_values(array_filter($GLOBALS['DB']->sqlQueries, static fn ($sql) => str_starts_with($sql, 'UPDATE `')));
    hookCheck(count($writes) === 2, 'Existing removed/disabled identities must be notified with UPDATE only.');
}

$item = hookSetup();
$GLOBALS['DB']->queryResult = static function (string $sql) {
    if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, "'a'")) { throw new RuntimeException('one connection failed'); }
    return null;
};
AssetChangeHook::onUpdate($item);
hookCheck(array_column(hookRows(), 'glpi_b_connection_id') === ['b', 'conflicted'], 'One failed connection must not prevent later notifications.');
$GLOBALS['DB']->queryResult = null;

$item = hookSetup();
try {
    AssetChangeHook::withoutQueue(static function () use ($item): bool {
        AssetChangeHook::withoutQueue(static function () use ($item): bool { AssetChangeHook::onUpdate($item); return true; });
        throw new RuntimeException('suppression test');
    });
} catch (RuntimeException $error) {
    hookCheck($error->getMessage() === 'suppression test', 'Test must propagate the original exception.');
}
hookCheck(hookRows() === [] && Config::$reads === [], 'Suppressed hooks must do no work.');
AssetChangeHook::onUpdate($item);
hookCheck(count(hookRows()) === 3, 'Nested suppression must restore in finally after an exception.');

$item = hookSetup();
AssetChangeHook::onUpdate(new stdClass());
$custom = new PluginFieldsComputerdmosasset();
$custom->fields = ['id' => 99, 'itemtype' => 'Unsupported', 'items_id' => 101];
AssetChangeHook::onUpdate($custom);
hookCheck(hookRows() === [] && Config::$reads === [], 'Unknown items and invalid custom parents must be ignored cheaply.');

echo "Hook notification tests passed ($checks checks).\n";
