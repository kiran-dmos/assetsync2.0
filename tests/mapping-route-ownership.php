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
    PluginFieldsComputerdmosasset::$rows = [];
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

function ownershipChainSetup(): array
{
    [$service, $remote] = ownershipSetup('glpi_a');
    GlpiBConnection::save(['id' => 'three', 'active' => true, 'base_url' => 'https://three.example/glpi']);
    foreach (['one' => 201, 'two' => 202, 'three' => 203] as $connection => $remoteId) {
        ownershipRoute($connection, 1);
        $remote->records[$connection . ':Computer'][$remoteId] = [
            'id' => $remoteId, 'entities_id' => 100, 'is_deleted' => 0,
            'name' => 'Shared asset', 'serial' => 'OWN-1',
            'comment' => $connection === 'two' ? 'Authoritative comment' : 'Old ' . $connection,
            'date_mod' => '2026-02-01 00:00:00',
        ];
        AssetSyncLink::save([
            'itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => $connection,
            'route_id' => 'route-' . $connection, 'remote_items_id' => $remoteId,
            'status' => AssetSyncLink::STATUS_SYNCED,
        ]);
    }

    $localDate = 'Computer.PluginFieldsComputerdmosasset.installedfield';
    $remoteDate = 'Computer.PluginFieldsComputerremote.deploymentfield';
    PluginFieldsContainer::$options = [801 => ['name' => 'Installed Date', 'table' => 'glpi_plugin_fields_computerdmosassets',
        'field' => 'installedfield', 'pfields_type' => 'date', 'pfields_fields_id' => 81]];
    PluginFieldsField::$definitions[81] = ['id' => 81, 'name' => 'installedfield', 'type' => 'date',
        'is_active' => 1, 'is_readonly' => 1, 'plugin_fields_containers_id' => 1];
    PluginFieldsComputerdmosasset::$rows[101] = ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer',
        'plugin_fields_containers_id' => 1, 'installedfield' => '2026-01-01'];
    $remote->customRecords['one:Computer'][201][$remoteDate] = '2026-03-14';
    $catalog = FieldMapping::discoverFields('Computer', Search::getOptions('Computer'), true);
    FieldMapping::save('one', 'Computer', [
        ['glpi_a_field_key' => 'Computer.comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'glpi_a'],
        ['glpi_a_field_key' => $localDate, 'glpi_b_field_key' => $remoteDate, 'source_of_truth' => 'glpi_b'],
    ], array_merge($catalog, [['key' => $remoteDate, 'label' => 'Deployment Date', 'supported' => true, 'type' => 'date']]));
    foreach (['two' => 'glpi_b', 'three' => 'glpi_a'] as $connection => $authority) {
        FieldMapping::save($connection, 'Computer', [[
            'glpi_a_field_key' => '16', 'glpi_b_field_key' => 'comment', 'source_of_truth' => $authority,
        ]], $catalog);
    }
    return [$service, $remote, $localDate, $remoteDate];
}

// One remote owns Comment, while another imports an unrelated date and forwards Comment.
foreach ([['two', 'one', 'three'], ['one', 'three', 'two']] as $order) {
    [$service, $remote, $localDate, $remoteDate] = ownershipChainSetup();
    foreach ($order as $connection) {
        ownershipCheck($service->queueAssetIfNeeded('Computer', 101, $connection), 'Every valid chain participant queues before the incoming update.');
    }
    foreach ($order as $connection) {
        $jobs = AssetSyncQueue::claimDue(1, $connection);
        ownershipCheck(count($jobs) === 1, 'The selected connection has one claimed job.');
        $service->processJob($jobs[0]);
        ownershipCheck(AssetSyncLink::find('Computer', 101, $connection)['status'] === AssetSyncLink::STATUS_SYNCED, 'Valid chain work records a full synchronized outcome.');
        if ($connection === 'one') {
            ownershipCheck(PluginFieldsComputerdmosasset::$rows[101]['installedfield'] === '2026-03-14', 'Unrelated B-owned Installed Date progresses alongside the Comment chain.');
            ownershipCheck($remote->records['one:Computer'][201]['comment'] === ($order[0] === 'two' ? 'Authoritative comment' : 'Local one'), 'Execution reads fresh A, not the payload prepared when queued.');
        }
    }
    ownershipCheck($GLOBALS['DB']->firstRow('glpi_computers', ['id' => 101])['comment'] === 'Authoritative comment', 'Only the incoming Comment owner changes A.');
    ownershipCheck($service->enqueueBackfill(10) === ($order[0] === 'two' ? 0 : 2), 'Normal backfill discovers outbound values changed by a later incoming job.');
    $service->processQueue(10);
    foreach (['one' => 201, 'two' => 202, 'three' => 203] as $connection => $remoteId) {
        ownershipCheck($remote->records[$connection . ':Computer'][$remoteId]['comment'] === 'Authoritative comment', 'Both processing orders converge on every endpoint.');
        ownershipCheck(GlpiBConnection::find($connection)['active'], 'Convergence never disables a connection.');
    }
    ownershipCheck($remote->customRecords['one:Computer'][201][$remoteDate] === '2026-03-14', 'The inbound date source is preserved.');
    $writes = array_values(array_filter($remote->requests, static fn (array $request): bool => $request['method'] === 'updateItem'));
    ownershipCheck(count($writes) === ($order[0] === 'two' ? 2 : 4)
        && !in_array(202, array_column($writes, 'items_id'), true), 'Only the two outgoing Comment endpoints receive native writes.');
    ownershipCheck(array_filter($remote->requests, static fn (array $request): bool =>
        $request['method'] === 'customTextValues' && !empty($request['changes'])) === [], 'An inbound-only date never becomes an outbound custom write.');
    $requests = $remote->requests;
    ownershipCheck($service->enqueueBackfill(10) === 0 && $service->processQueue(10) === 0
        && $remote->requests === $requests, 'Converged backfill is idempotent without new remote requests.');
}

// A third outbound connection must see both incoming writers, not approve each pair separately.
[$service, $remote] = ownershipChainSetup();
ownershipCheck($service->queueAssetIfNeeded('Computer', 101, 'one'), 'The valid mixed job queues before a second Comment writer is added.');
$catalog = FieldMapping::discoverFields('Computer', Search::getOptions('Computer'), true);
FieldMapping::save('three', 'Computer', [[
    'glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'glpi_b',
]], $catalog);
$before = $GLOBALS['DB']->tables['glpi_computers'];
$beforeCustom = PluginFieldsComputerdmosasset::$rows;
$service->processQueue(10);
ownershipCheck($remote->requests === [] && $before === $GLOBALS['DB']->tables['glpi_computers']
    && $beforeCustom === PluginFieldsComputerdmosasset::$rows, 'Competing writers block the third outbound job before local preparation or remote operations.');
ownershipCheck(AssetSyncLink::find('Computer', 101, 'one')['status'] === AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'Competing writers retain the explicit blocked configuration outcome.');
foreach (['one', 'two', 'three'] as $connection) {
    ownershipBlocked(static fn () => FieldMapping::syncMappings($connection, 'Computer', ['entities_id' => 1]), 'across active');
}
GlpiBConnection::save(['id' => 'three', 'active' => false]);
ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 2, 'A disabled second writer does not compete with the single active owner.');
GlpiBConnection::save(['id' => 'three', 'active' => true]);
ownershipRoute('three', 1, false);
ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 2, 'A disabled second-writer route does not compete.');
ownershipRoute('three', 2);
ownershipCheck(count(FieldMapping::syncMappings('one', 'Computer', ['entities_id' => 1])) === 2, 'A nonmatching second-writer route does not compete.');

// A previously blocked worker retained this unchanged payload hash. Ownership fixes do not reset queue policy.
ownershipRoute('three', 1);
FieldMapping::save('three', 'Computer', [[
    'glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'glpi_a',
]], $catalog);
ownershipCheck(!$service->queueAssetIfNeeded('Computer', 101, 'one'), 'A matching blocked hash stays suppressed even after its ownership becomes valid.');
ownershipCheck(AssetSyncQueue::notify('Computer', 101, 'one'), 'A normal unchanged asset-save notification requests a recheck.');
$service->processQueue(1);
ownershipCheck($remote->requests === []
    && AssetSyncLink::find('Computer', 101, 'one')['status'] === AssetSyncLink::STATUS_BLOCKED_CONFIGURATION,
    'An unchanged local notification alone does not clear a retained blocked configuration hash.');
ownershipCheck($service->queueAssetIfNeeded('Computer', 101, 'two'), 'The valid incoming Comment owner can still queue.');
$service->processQueue(1);
ownershipCheck($service->enqueueBackfill(10) === 2, 'After the owner changes mapped A data, normal backfill recovers the blocked outbound connection and the other subscriber.');
$service->processQueue(10);
ownershipCheck(AssetSyncLink::find('Computer', 101, 'one')['status'] === AssetSyncLink::STATUS_SYNCED
    && $remote->records['one:Computer'][201]['comment'] === 'Authoritative comment'
    && PluginFieldsComputerdmosasset::$rows[101]['installedfield'] === '2026-03-14',
    'Recovery uses normal queue/backfill operations and imports the independent date without manually resetting the blocked row.');
ownershipCheck($service->enqueueBackfill(10) === 0, 'Recovered work returns to normal unchanged-payload suppression.');

// Preparation-time rejection can leave no payload hash, so the first corrected backfill is eligible.
[$service, $remote] = ownershipChainSetup();
AssetSyncLink::saveStatus('Computer', 101, 'one', 'route-one', AssetSyncLink::STATUS_BLOCKED_CONFIGURATION,
    'Previously rejected cross-connection ownership.');
ownershipCheck(AssetSyncLink::find('Computer', 101, 'one')['last_payload_hash'] === '', 'The preparation-rejection fixture has no completed payload hash.');
ownershipCheck($service->enqueueBackfill(10) === 3, 'Normal backfill admits the corrected blank-hash blocked link alongside both other connections.');
$service->processQueue(10);
$service->enqueueBackfill(10);
$service->processQueue(10);
ownershipCheck(AssetSyncLink::find('Computer', 101, 'one')['status'] === AssetSyncLink::STATUS_SYNCED
    && $remote->records['one:Computer'][201]['comment'] === 'Authoritative comment'
    && PluginFieldsComputerdmosasset::$rows[101]['installedfield'] === '2026-03-14',
    'A blank-hash legacy ownership block recovers through backfill alone.');

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
