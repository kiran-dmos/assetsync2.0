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
function rowCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function rowError(callable $action, string $contains): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        rowCheck(str_contains($error->getMessage(), $contains), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected mapping failure: ' . $contains);
}
function mappingRow(string $a, string $b, string $source = 'glpi_a'): array
{
    return ['glpi_a_field_key' => $a, 'glpi_b_field_key' => $b, 'source_of_truth' => $source];
}
function storedScope(array $scope, string $connection = 'one'): void
{
    $all = json_decode(Config::$values['plugin:assetsync20']['field_mappings'] ?? '{}', true);
    $all[$connection]['Computer'] = $scope;
    Config::$values['plugin:assetsync20']['field_mappings'] = json_encode($all, JSON_THROW_ON_ERROR);
}

Config::$values = [];
$GLOBALS['DB'] = new FakeDB();
PluginFieldsContainer::$options = [];
Search::$nativeOptions = [
    40 => ['name' => 'Type', 'table' => 'glpi_computertypes', 'field' => 'name',
        'linkfield' => 'computertypes_id', 'uid' => 'Computer.ComputerType.name', 'datatype' => 'dropdown'],
    50 => ['name' => 'Location', 'table' => 'glpi_locations', 'field' => 'name', 'linkfield' => 'locations_id'],
];
GlpiBConnection::save(['id' => 'one', 'base_url' => 'https://one.example/glpi', 'active' => true]);
GlpiBConnection::save(['id' => 'two', 'base_url' => 'https://two.example/glpi', 'active' => true]);
$catalog = FieldMapping::discoverFields('Computer', Search::getOptions('Computer'), true);
$fanout = [mappingRow('1', 'name'), mappingRow('Computer.name', 'comment')];
FieldMapping::save('one', 'Computer', $fanout, $catalog);
$loaded = FieldMapping::load('one', 'Computer');
rowCheck(array_is_list($loaded) && count($loaded) === 2, 'Repeated A survives row storage.');
rowCheck(array_column($loaded, 'glpi_a_field_key') === ['name', 'name'], 'A aliases become canonical identities.');
$json = Config::$values['plugin:assetsync20']['field_mappings'];
$scope = json_decode($json, true)['one']['Computer'];
rowCheck($scope['version'] === 2 && !isset($scope['rows'][0]['glpi_b_field_id']), 'V2 saves no search IDs.');
rowCheck(count(FieldMapping::syncMappings('one', 'Computer')) === 2, 'A fanout compiles.');
FieldMapping::load('one', 'Computer');
rowCheck($json === Config::$values['plugin:assetsync20']['field_mappings'], 'Loading does not migrate.');
rowError(static fn () => FieldMapping::save('one', 'Computer', $fanout), 'Reload current field metadata');
foreach ([
    [mappingRow('name', 'name'), mappingRow('serial', 'name')],
    [mappingRow('name', 'name'), mappingRow('1', 'name')],
    [mappingRow('name', 'name', 'both'), mappingRow('name', 'comment')],
    [mappingRow('name', 'name', 'glpi_b'), mappingRow('name', 'comment', 'both')],
] as $invalid) {
    foreach ([$invalid, array_reverse($invalid)] as $ordered) {
        rowError(static fn () => FieldMapping::save('one', 'Computer', $ordered, $catalog),
            $ordered[0]['glpi_b_field_key'] === $ordered[1]['glpi_b_field_key'] ? 'destination' : 'exclusive');
        rowCheck($json === Config::$values['plugin:assetsync20']['field_mappings'], 'Rejected save is atomic.');
    }
}
foreach ([mappingRow('', 'name'), mappingRow('name', '', 'glpi_a'), mappingRow('name', 'comment', 'invalid'),
    mappingRow('locations_id', 'comment'), mappingRow('name', 'computertypes_id')] as $row) {
    rowError(static fn () => FieldMapping::save('one', 'Computer', [$row], $catalog), 'Mapping row 1');
}

FieldMapping::save('two', 'Computer', [mappingRow('name', 'serial')], $catalog);
foreach (['one', 'two'] as $connectionId) {
    EntitySyncRoute::save(['id' => 'ownership-' . $connectionId, 'glpi_b_connection_id' => $connectionId,
        'glpi_a_source_entity_id' => '7', 'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
}
$ownershipAsset = ['entities_id' => 7];
rowCheck(count(FieldMapping::syncMappings('one', 'Computer', $ownershipAsset)) === 2, 'Cross-connection A fanout is allowed.');
FieldMapping::save('two', 'Computer', [mappingRow('name', 'serial', 'glpi_b')], $catalog);
rowError(static fn () => FieldMapping::syncMappings('one', 'Computer', $ownershipAsset), 'across active');
storedScope(['version' => 2, 'rows' => [mappingRow('1', 'serial', 'both')]], 'two');
rowError(static fn () => FieldMapping::syncMappings('one', 'Computer', $ownershipAsset), 'across active');
GlpiBConnection::save(['id' => 'two', 'base_url' => 'https://two.example/glpi', 'active' => false]);
rowCheck(count(FieldMapping::syncMappings('one', 'Computer', $ownershipAsset)) === 2, 'Inactive connection does not own A.');
GlpiBConnection::save(['id' => 'two', 'base_url' => 'https://ONE.example:443/glpi/apirest.php/', 'active' => true]);
rowError(static fn () => FieldMapping::syncMappings('one', 'Computer', $ownershipAsset), 'same GLPI B API');
rowCheck(FieldMapping::canonicalApiEndpoint('https://ONE.example:443/glpi/apirest.php/') === FieldMapping::canonicalApiEndpoint('https://one.example/glpi'), 'Known API URL aliases canonicalize.');
rowCheck(FieldMapping::canonicalApiEndpoint('https://one.example/other') !== FieldMapping::canonicalApiEndpoint('https://one.example/glpi'), 'Distinct installation paths stay independent.');
GlpiBConnection::save(['id' => 'two', 'active' => false]);
EntitySyncRoute::delete('ownership-one');
EntitySyncRoute::delete('ownership-two');

$legacy = [
    '1' => ['glpi_b_field_key' => '901', 'glpi_b_field_uid' => 'Computer.name', 'source_of_truth' => 'glpi_a'],
    'serial' => ['glpi_b_field_key' => 'serial', 'source_of_truth' => 'both'],
    'missing' => ['glpi_b_field_key' => 'missing B', 'source_of_truth' => 'broken'],
    'comment' => 'glpi_b',
];
storedScope($legacy);
$before = Config::$values['plugin:assetsync20']['field_mappings'];
$rows = FieldMapping::load('one', 'Computer');
rowCheck(count($rows) === 4 && $rows[2]['source_of_truth'] === 'broken' && $rows[3]['glpi_b_field_key'] === '', 'Legacy invalid and scalar records survive.');
rowCheck($before === Config::$values['plugin:assetsync20']['field_mappings'], 'Legacy reads never migrate.');
rowError(static fn () => FieldMapping::syncMappings('one', 'Computer'), 'authority');
storedScope(array_slice($legacy, 0, 2, true));
rowCheck(count(FieldMapping::syncMappings('one', 'Computer')) === 2, 'Old supported native mappings keep executing from stable independent aliases.');
storedScope(['computertypes_id' => ['glpi_b_field_key' => 'computertypes_id', 'source_of_truth' => 'glpi_a']]);
rowError(static fn () => FieldMapping::syncMappings('one', 'Computer'), 'Legacy mapping requires');
FieldMapping::save('one', 'Computer', FieldMapping::load('one', 'Computer'), $catalog);
rowCheck(FieldMapping::syncMappings('one', 'Computer')[0]['glpi_a_field'] === 'computertypes_id', 'Explicit current-metadata save acknowledges expanded native support.');
storedScope(['name' => ['glpi_b_field_key' => 'name', 'glpi_b_field_uid' => 'Computer.comment', 'source_of_truth' => 'glpi_a']]);
rowError(static fn () => FieldMapping::syncMappings('one', 'Computer'), 'ambiguous');
storedScope(['version' => 3, 'rows' => []]);
rowError(static fn () => FieldMapping::load('one', 'Computer'), 'version');

// A current invalid configuration blocks before billing preparation or client calls.
AssetSyncQueue::install();
AssetSyncLink::install();
EntitySyncRoute::save(['id' => 'r', 'glpi_b_connection_id' => 'one', 'glpi_a_source_entity_id' => '1',
    'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
$GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
$GLOBALS['DB']->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0,
    'name' => 'Local name', 'comment' => 'Local comment', 'serial' => 'ROW-101', 'date_mod' => '2026-01-01 00:00:00']);
$remote = new FakeGlpiBClient();
$service = new AssetSyncService($remote);
storedScope(['version' => 2, 'rows' => [mappingRow('name', 'name'), mappingRow('serial', 'name')]]);
$assetBefore = $GLOBALS['DB']->tables['glpi_computers'];
rowCheck(!$service->queueAssetIfNeeded('Computer', 101, 'one'), 'Invalid ownership cannot queue preparation.');
rowCheck($remote->requests === [] && $assetBefore === $GLOBALS['DB']->tables['glpi_computers'], 'Invalid ownership performs no client or asset mutations.');
FieldMapping::save('one', 'Computer', $fanout, $catalog);
rowCheck($service->queueAssetIfNeeded('Computer', 101, 'one'), 'Valid configuration can queue before a later conflicting edit.');
storedScope(['version' => 2, 'rows' => [mappingRow('name', 'name'), mappingRow('serial', 'name')]]);
$service->processQueue(10);
rowCheck($remote->requests === [] && $assetBefore === $GLOBALS['DB']->tables['glpi_computers'], 'Worker revalidates ownership before preparation or client writes.');
rowCheck(AssetSyncLink::find('Computer', 101, 'one')['status'] === AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'Changed invalid configuration is visibly blocked.');
FieldMapping::save('one', 'Computer', $fanout, $catalog);
$compiled = FieldMapping::syncMappings('one', 'Computer');
$route = ['id' => 'r', 'glpi_b_target_entity_id' => 100];
rowCheck($service->payloadHash(['name' => 'x'], $route, $compiled) === $service->payloadHash(['name' => 'x'], $route, array_reverse($compiled)), 'Payload hashes ignore row order.');
$remote->records['one:Computer'][201] = ['id' => 201, 'entities_id' => 100, 'name' => 'Remote',
    'comment' => 'Old', 'serial' => 'ROW-101', 'date_mod' => '2026-01-01 00:00:00'];
AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'one',
    'route_id' => 'r', 'remote_items_id' => 201, 'status' => AssetSyncLink::STATUS_SYNCED]);
rowCheck($service->queueAssetIfNeeded('Computer', 101, 'one'), 'Fanout queues.');
$service->processQueue(10);
rowCheck($remote->records['one:Computer'][201]['name'] === 'Local name'
    && $remote->records['one:Computer'][201]['comment'] === 'Local name', 'Fanout writes both distinct native targets.');
rowCheck(count(array_filter($remote->requests, static fn (array $request): bool => $request['method'] === 'updateItem')) === 1, 'Fanout batches native writes.');

// Repeated native and custom A sources can each feed native and independent B-only custom targets.
$aCustom = 'Computer.PluginFieldsComputerdmosasset.namefield';
$bCustom = 'Computer.PluginFieldsComputerremote.titlefield';
PluginFieldsContainer::$options = [601 => ['name' => 'Local custom', 'table' => 'glpi_plugin_fields_computerdmosassets',
    'field' => 'namefield', 'pfields_type' => 'text', 'pfields_fields_id' => 41]];
PluginFieldsField::$definitions[41] = ['id' => 41, 'name' => 'namefield', 'type' => 'text',
    'is_active' => 1, 'is_readonly' => 1, 'plugin_fields_containers_id' => 1];
PluginFieldsComputerdmosasset::$rows[101] = ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer',
    'plugin_fields_containers_id' => 1, 'namefield' => 'Custom fanout'];
$bOnly = ['name' => 'B-only title', 'table' => 'glpi_plugin_fields_computerremotes', 'field' => 'titlefield',
    'uid' => $bCustom, 'pfields_type' => 'text', 'pfields_fields_id' => 141];
FakeItemTypes::$classResult = static function (): never { throw new RuntimeException('Remote discovery used an A class resolver.'); };
FakeItemTypes::$tableResult = static function (): never { throw new RuntimeException('Remote discovery used an A table resolver.'); };
$bCatalog = FieldMapping::discoverFields('Computer', [901 => $bOnly], true);
FakeItemTypes::$classResult = FakeItemTypes::$tableResult = null;
rowCheck($bCatalog[0]['supported'] && $bCatalog[0]['key'] === $bCustom && !class_exists('PluginFieldsComputerremote'), 'B-only discovery is independent of local generated classes.');
foreach (['name' => 'Local name', $aCustom => 'Custom fanout'] as $source => $value) {
    FieldMapping::save('one', 'Computer', [mappingRow($source, 'name'), mappingRow($source, 'comment'), mappingRow($source, $bCustom)], array_merge($catalog, $bCatalog));
    $remote->records['one:Computer'][201]['name'] = 'Old';
    $remote->records['one:Computer'][201]['comment'] = 'Old';
    $remote->customRecords['one:Computer'][201][$bCustom] = 'Old';
    $remote->requests = [];
    rowCheck($service->queueAssetIfNeeded('Computer', 101, 'one', true), 'Native/custom fanout queues.');
    $service->processQueue(10);
    rowCheck($remote->records['one:Computer'][201]['name'] === $value
        && $remote->records['one:Computer'][201]['comment'] === $value
        && $remote->customRecords['one:Computer'][201][$bCustom] === $value, 'Every fanout target receives its independent converted value.');
    rowCheck(count(array_filter($remote->requests, static fn (array $request): bool =>
        $request['method'] === 'customTextValues' && !empty($request['changes']) && empty($request['dry_run'])
    )) === 1, 'Custom fanout keeps one batched write operation.');
}

$unsupported = array_values(array_filter($catalog, static fn (array $field): bool => !$field['supported']));
rowCheck($unsupported !== [] && $unsupported[0]['reason'] !== '', 'Unsupported discovery retains reasons.');
echo 'Mapping row tests passed (' . $checks . " checks).\n";
