<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;

$checks = 0;
function ownershipCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function ownershipRoute(string $connection, int $entity, bool $active = true, bool $children = false, string $itemtype = 'Computer'): void
{
    EntitySyncRoute::save([
        'id' => 'route-' . $connection, 'glpi_b_connection_id' => $connection,
        'glpi_a_source_entity_id' => (string) $entity, 'glpi_b_target_entity_id' => '100',
        'asset_types' => [$itemtype], 'include_child_entities' => $children, 'active' => $active,
    ]);
}
function ownershipSetup(string $authority, bool $alias = false): array
{
    Config::$values = [];
    $GLOBALS['DB'] = new FakeDB();
    Search::$nativeOptions = [];
    PluginFieldsContainer::$options = [];
    Computer::$allowUpdate = true;
    Computer::$ignoreUpdate = false;
    AssetSyncQueue::install();
    AssetSyncLink::install();
    $remote = new FakeGlpiBClient();
    foreach (['one' => 1, 'two' => 2] as $connection => $entity) {
        GlpiBConnection::save([
            'id' => $connection, 'active' => true,
            'base_url' => $alias && $connection === 'two' ? 'https://ONE.example:443/glpi/apirest.php/' : 'https://' . $connection . '.example/glpi',
        ]);
        ownershipRoute($connection, $entity);
        $GLOBALS['DB']->insert('glpi_entities', ['id' => $entity, 'entities_id' => 0]);
        $assetId = 100 + $entity;
        $remoteId = 200 + $entity;
        $GLOBALS['DB']->insert('glpi_computers', [
            'id' => $assetId, 'entities_id' => $entity, 'is_deleted' => 0,
            'name' => 'Client ' . $connection, 'comment' => 'Local ' . $connection,
            'serial' => 'OWN-' . $entity, 'date_mod' => '2026-01-01 00:00:00',
        ]);
        $remote->records[$connection . ':Computer'][$remoteId] = [
            'id' => $remoteId, 'entities_id' => 100, 'is_deleted' => 0,
            'name' => 'Client ' . $connection, 'comment' => 'Remote ' . $connection,
            'serial' => 'OWN-' . $entity, 'date_mod' => '2026-02-01 00:00:00',
        ];
        AssetSyncLink::save([
            'itemtype' => 'Computer', 'items_id' => $assetId, 'glpi_b_connection_id' => $connection,
            'route_id' => 'route-' . $connection, 'remote_items_id' => $remoteId, 'status' => AssetSyncLink::STATUS_SYNCED,
        ]);
        FieldMapping::save($connection, 'Computer', [[
            'glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => $authority,
        ]], FieldMapping::discoverFields('Computer', Search::getOptions('Computer'), true));
    }
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 3, 'entities_id' => 1]);
    return [new AssetSyncService($remote), $remote];
}
function ownershipBlocked(callable $action, string $expected): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        ownershipCheck(str_contains($error->getMessage(), $expected), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected ownership rejection: ' . $expected);
}

foreach (['glpi_b', 'both'] as $authority) {
    [$service, $remote] = ownershipSetup($authority);
    foreach (['one' => 101, 'two' => 102] as $connection => $assetId) {
        ownershipCheck($service->queueAssetIfNeeded('Computer', $assetId, $connection), 'Disjoint clients can each queue their one-to-one ' . $authority . ' mapping.');
    }
    $service->processQueue(10);
    foreach (['one' => 101, 'two' => 102] as $connection => $assetId) {
        $asset = new Computer();
        $asset->getFromDB($assetId);
        ownershipCheck($asset->fields['comment'] === 'Remote ' . $connection, 'Each client receives Comments from its own B.');
        ownershipCheck(AssetSyncLink::find('Computer', $assetId, $connection)['status'] === AssetSyncLink::STATUS_SYNCED, 'Disjoint inbound sync is verified.');
    }

    // A route edit after enqueue must be revalidated before preparation or any client operation.
    [$service, $remote] = ownershipSetup($authority);
    ownershipCheck($service->queueAssetIfNeeded('Computer', 101, 'one'), 'Initially disjoint job queues.');
    ownershipRoute('two', 1);
    $before = $GLOBALS['DB']->tables['glpi_computers'];
    $service->processQueue(10);
    ownershipCheck($remote->requests === [] && $before === $GLOBALS['DB']->tables['glpi_computers'], 'Worker blocks competing asset-matching writers without preparing or writing the asset.');
    ownershipCheck(AssetSyncLink::find('Computer', 101, 'one')['status'] === AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'Worker exposes the ownership conflict.');
    ownershipCheck(!$service->queueAssetIfNeeded('Computer', 101, 'two'), 'The competing connection is equally blocked, with no order winner.');

    ownershipRoute('two', 1, false);
    ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 1, 'Disabled routes do not compete.');
    ownershipRoute('two', 1, true, false, 'Monitor');
    ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 1, 'Other itemtypes do not compete.');
    ownershipRoute('one', 1, true, true);
    ownershipRoute('two', 3);
    ownershipBlocked(static fn () => FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 3]), 'matching this asset');
    ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 1, 'A child-only competitor does not own the parent asset.');
}

// Same API aliases are harmless for different assets, but overlapping creation/writes are unsafe.
[$service, $remote] = ownershipSetup('glpi_a', true);
ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 1
    && count(FieldMapping::syncMappings('two', 'Computer', ['entities_id' => 2])) === 1, 'Known API aliases with disjoint routes remain usable.');
ownershipCheck($service->queueAssetIfNeeded('Computer', 101, 'one'), 'Disjoint alias queues.');
ownershipRoute('two', 1);
$before = $GLOBALS['DB']->tables['glpi_computers'];
$service->processQueue(10);
ownershipCheck($remote->requests === [] && $before === $GLOBALS['DB']->tables['glpi_computers'], 'Overlapping aliases block before any client operation.');
ownershipBlocked(static fn () => FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1]), 'same GLPI B API');
ownershipBlocked(static fn () => FieldMapping::syncMappings('two', 'Computer', ['entities_id' => 1]), 'same GLPI B API');

// Save validates only one scope, even while matching routes overlap; runtime enforces asset ownership.
$catalog = FieldMapping::discoverFields('Computer', Search::getOptions('Computer'), true);
FieldMapping::save('two', 'Computer', [[
    'glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'both',
]], $catalog);
ownershipCheck(FieldMapping::load('two', 'Computer')[0]['source_of_truth'] === 'both', 'Save does not attempt static route intersection.');
ownershipBlocked(static fn () => FieldMapping::syncMappings('two', 'Computer', ['entities_id' => 1]), 'same GLPI B API');
FieldMapping::save('two', 'Computer', [], $catalog);
ownershipBlocked(static fn () => FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1]), 'same GLPI B API');
ownershipBlocked(static fn () => FieldMapping::syncMappings('two', 'Computer', ['entities_id' => 1]), 'same GLPI B API');
echo 'Mapping route ownership tests passed (' . $checks . " checks).\n";
