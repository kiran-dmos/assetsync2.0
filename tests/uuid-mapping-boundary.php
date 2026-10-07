<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;

$checks = 0;
function uuidMappingCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function uuidMappingError(callable $action, string $contains): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        uuidMappingCheck(str_contains($error->getMessage(), $contains), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected error: ' . $contains);
}
function uuidMappingRow(string $a, string $b, string $authority = 'glpi_a'): array
{
    return ['glpi_a_field_key' => $a, 'glpi_b_field_key' => $b, 'source_of_truth' => $authority];
}
function uuidMappingScope(string $itemtype, array $scope, string $connection = 'uuid-test'): void
{
    $all = json_decode(Config::$values['plugin:assetsync20']['field_mappings'] ?? '{}', true);
    $all[$connection][$itemtype] = $scope;
    Config::$values['plugin:assetsync20']['field_mappings'] = json_encode($all, JSON_THROW_ON_ERROR);
}
function uuidMappingOptions(string $itemtype, int $uuidId): array
{
    $table = 'glpi_' . strtolower($itemtype) . 's';
    $options = [
        701 => ['name' => 'Name', 'table' => $table, 'field' => 'name', 'uid' => $itemtype . '.name', 'datatype' => 'itemlink'],
        702 => ['name' => 'Comments', 'table' => $table, 'field' => 'comment', 'uid' => $itemtype . '.comment', 'datatype' => 'text'],
    ];
    if ($itemtype !== 'Peripheral') {
        $options[$uuidId] = ['name' => 'UUID', 'table' => $table, 'field' => 'uuid', 'uid' => $itemtype . '.uuid', 'datatype' => 'string'];
    }
    return $options;
}

Config::$values = [];
PluginFieldsContainer::$options = [];
foreach (array_keys(FieldMapping::assetTypes()) as $index => $itemtype) {
    $aId = 800 + $index;
    $bId = 900 + $index;
    Search::$nativeOptions = uuidMappingOptions($itemtype, $aId);
    $remote = FieldMapping::discoverFields($itemtype, uuidMappingOptions($itemtype, $bId), true);
    $ordinary = uuidMappingRow('name', 'comment');
    $aAliases = ['uuid', $itemtype . '.uuid'];
    $bAliases = $aAliases;
    if ($itemtype !== 'Peripheral') {
        $aAliases[] = (string) $aId;
        $bAliases[] = (string) $bId;
        $uuid = FieldMapping::selectedField($remote, (string) $bId);
        uuidMappingCheck(!$uuid['supported'] && $uuid['reason'] === FieldMapping::UUID_MAPPING_CONFLICT, 'Native UUID stays visible but cannot be selected as an ordinary field.');
    } else {
        uuidMappingCheck(!in_array('uuid', array_column($remote, 'key'), true), 'Peripheral fixture has no UUID search option.');
    }
    foreach ($aAliases as $key) {
        uuidMappingError(static fn () => FieldMapping::save('uuid-test', $itemtype, [uuidMappingRow($key, 'name')], $remote), 'New or altered native UUID');
    }
    foreach ($bAliases as $key) {
        uuidMappingError(static fn () => FieldMapping::save('uuid-test', $itemtype, [uuidMappingRow('name', $key)], $remote), 'New or altered native UUID');
    }

    $reservedA = uuidMappingRow($aAliases[count($aAliases) - 1], 'unavailable-target', 'both');
    $reservedB = uuidMappingRow('comment', (string) $bId, 'glpi_b') + [
        'glpi_b_field_uid' => $itemtype . '.uuid', 'glpi_b_field_id' => (string) $bId, 'glpi_b_field_label' => 'UUID',
    ];
    foreach ([false, true] as $v2) {
        $rows = [$ordinary, $reservedA, $reservedB];
        $scope = $v2 ? ['version' => 2, 'rows' => $rows] : [
            'name' => array_diff_key($ordinary, ['glpi_a_field_key' => true]),
            $reservedA['glpi_a_field_key'] => array_diff_key($reservedA, ['glpi_a_field_key' => true]),
            'comment' => array_diff_key($reservedB, ['glpi_a_field_key' => true]),
        ];
        uuidMappingScope($itemtype, $scope);
        $before = Config::$values['plugin:assetsync20']['field_mappings'];
        $loaded = FieldMapping::load('uuid-test', $itemtype);
        uuidMappingCheck(count($loaded) === 3, 'All existing UUID and ordinary rows remain loaded.');
        $compiled = FieldMapping::syncMappings('uuid-test', $itemtype);
        uuidMappingCheck($compiled === [['glpi_a_field' => 'name', 'glpi_b_field' => 'comment', 'source_of_truth' => 'glpi_a']], 'Only proven UUID rows are suppressed, before missing-endpoint and legacy validation.');
        uuidMappingCheck($before === Config::$values['plugin:assetsync20']['field_mappings'], 'Suppression does not rewrite storage.');
        $loaded[0]['source_of_truth'] = 'glpi_b';
        FieldMapping::save('uuid-test', $itemtype, $loaded, $remote);
        $stored = json_decode(Config::$values['plugin:assetsync20']['field_mappings'], true)['uuid-test'][$itemtype];
        uuidMappingCheck($stored['version'] === 2 && $stored['rows'][1] == $reservedA && $stored['rows'][2] == $reservedB, 'Unrelated save retains original UUID keys, search IDs, authority and display metadata without inference.');
        $saved = Config::$values['plugin:assetsync20']['field_mappings'];
        foreach (['source_of_truth' => 'glpi_a', 'glpi_a_field_key' => 'uuid', 'glpi_b_field_key' => 'name'] as $field => $value) {
            $changed = FieldMapping::load('uuid-test', $itemtype);
            $changed[1][$field] = $value;
            uuidMappingError(static fn () => FieldMapping::save('uuid-test', $itemtype, $changed, $remote), 'New or altered native UUID');
            uuidMappingCheck($saved === Config::$values['plugin:assetsync20']['field_mappings'], 'Altered reserved row is atomically rejected.');
        }
        $duplicate = FieldMapping::load('uuid-test', $itemtype);
        $duplicate[] = $duplicate[1];
        uuidMappingError(static fn () => FieldMapping::save('uuid-test', $itemtype, $duplicate, $remote), 'New or altered native UUID');
        FieldMapping::save('uuid-test', $itemtype, [$ordinary], $remote);
        uuidMappingCheck(count(FieldMapping::load('uuid-test', $itemtype)) === 1, 'Explicit removal of retained UUID rows is allowed.');
    }
}

Search::$nativeOptions = uuidMappingOptions('Computer', 815);
$catalog = FieldMapping::discoverFields('Computer', uuidMappingOptions('Computer', 915), true);
$raw = uuidMappingRow(' Computer.uuid ', ' name ', ' both ');
uuidMappingScope('Computer', ['version' => 2, 'rows' => [$raw]]);
FieldMapping::save('uuid-test', 'Computer', [$raw, uuidMappingRow('name', 'comment')], $catalog);
uuidMappingCheck(json_decode(Config::$values['plugin:assetsync20']['field_mappings'], true)['uuid-test']['Computer']['rows'][0] === $raw, 'Retained raw UUID identities and authority are not trimmed or canonicalized.');
uuidMappingError(static fn () => FieldMapping::save('uuid-test', 'Computer', [uuidMappingRow('Computer.uuid', ' name ', ' both ')], $catalog), 'New or altered native UUID');

uuidMappingScope('Computer', ['version' => 2, 'rows' => [uuidMappingRow('name', '815')]]);
uuidMappingCheck(!FieldMapping::isReservedUuidRow('Computer', uuidMappingRow('name', '815'), $catalog), 'A UUID search ID cannot classify B.');
uuidMappingError(static fn () => FieldMapping::syncMappings('uuid-test', 'Computer'), 'reselect the field');
uuidMappingCheck(!FieldMapping::isReservedUuidRow('Computer', uuidMappingRow('name', '915') + ['glpi_b_field_uid' => 'Computer.comment'], $catalog), 'A stable non-UUID B UID is not reinterpreted using a reused search ID.');

$custom = 'Computer.PluginFieldsComputerdmosasset.uuid';
PluginFieldsContainer::$options = [815 => ['name' => 'UUID', 'table' => 'glpi_plugin_fields_computerdmosassets',
    'field' => 'uuid', 'pfields_type' => 'text', 'pfields_fields_id' => 41]];
PluginFieldsField::$definitions[41] = ['id' => 41, 'name' => 'uuid', 'type' => 'text', 'is_active' => 1, 'plugin_fields_containers_id' => 1];
$customCatalog = FieldMapping::discoverFields('Computer', PluginFieldsContainer::$options, true);
uuidMappingCheck($customCatalog[0]['supported'] && !FieldMapping::isReservedUuidRow('Computer', uuidMappingRow($custom, $custom)), 'Custom UUID labels and columns are not reserved.');
FieldMapping::save('uuid-test', 'Computer', [uuidMappingRow('815', $custom)], $customCatalog);
uuidMappingCheck(FieldMapping::syncMappings('uuid-test', 'Computer')[0]['glpi_a_field'] === $custom, 'A custom search ID is resolved before native metadata.');
PluginFieldsContainer::$options = [];

$service = new AssetSyncService(new FakeGlpiBClient());
$ordinary = ['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a'];
$compiled = [$ordinary,
    ['glpi_a_field' => 'uuid', 'glpi_b_field' => 'comment', 'source_of_truth' => 'both'],
    ['glpi_a_field' => 'comment', 'glpi_b_field' => 'uuid', 'source_of_truth' => 'glpi_a'],
];
$asset = ['name' => 'Mapped name', 'comment' => 'Ordinary comments', 'serial' => 'BOUNDARY', 'uuid' => 'must-not-copy'];
$route = ['id' => 'uuid-route', 'glpi_b_target_entity_id' => '100'];
uuidMappingCheck($service->payloadHash($asset, $route, $compiled) === $service->payloadHash($asset, $route, [$ordinary]), 'UUID rows are excluded defensively from ordinary payload hash.');
$create = (new ReflectionMethod(AssetSyncService::class, 'createInput'))->invoke($service, $asset, $route, $compiled);
uuidMappingCheck($create === ['entities_id' => 100, 'serial' => 'BOUNDARY', 'name' => 'Mapped name'], 'Native create payload neither writes UUID nor copies UUID into other fields.');
$changes = (new ReflectionMethod(AssetSyncService::class, 'existingChanges'))->invoke($service, $asset,
    ['name' => 'Old name', 'uuid' => 'remote-uuid', 'comment' => 'Old', 'entities_id' => 100], $route, $compiled);
uuidMappingCheck($changes['remote'] === ['name' => 'Mapped name'] && $changes['local'] === [] && $changes['conflicts'] === [], 'Ordinary update comparison suppresses only UUID rows.');

$customCompiled = [['glpi_a_field' => $custom, 'glpi_b_field' => 'comment', 'source_of_truth' => 'glpi_a']];
uuidMappingCheck($service->payloadHash([$custom => 'one'], $route, $customCompiled) !== $service->payloadHash([$custom => 'two'], $route, $customCompiled), 'Custom UUID values still affect ordinary hashes.');
uuidMappingCheck((new ReflectionMethod(AssetSyncService::class, 'createInput'))->invoke($service, [$custom => 'custom value'], $route, $customCompiled)['comment'] === 'custom value', 'Custom UUID source still participates in ordinary create.');

// Suppressed cross-connection inbound rows cannot acquire ownership of another ordinary endpoint.
$GLOBALS['DB'] = new FakeDB();
Config::$values = [];
AssetSyncQueue::install();
AssetSyncLink::install();
foreach (['first', 'second'] as $connection) {
    \GlpiPlugin\Assetsync20\GlpiBConnection::save(['id' => $connection, 'active' => true, 'base_url' => 'https://' . $connection . '.example']);
    EntitySyncRoute::save(['id' => 'route-' . $connection, 'glpi_b_connection_id' => $connection,
        'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
}
$GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
$GLOBALS['DB']->insert('glpi_computers', $asset + ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0, 'date_mod' => '2026-01-01 00:00:00']);
uuidMappingScope('Computer', ['version' => 2, 'rows' => [uuidMappingRow('name', 'name'),
    uuidMappingRow('uuid', 'name', 'both'), uuidMappingRow('name', 'uuid', 'both')]], 'first');
uuidMappingScope('Computer', ['version' => 2, 'rows' => [uuidMappingRow('name', 'uuid', 'both')]], 'second');
uuidMappingCheck(count(FieldMapping::syncMappings('first', 'Computer', ['entities_id' => 1])) === 1, 'Reserved rows are suppressed from local and cross-connection ownership.');
$remote = new FakeGlpiBClient();
$service = new AssetSyncService($remote);
uuidMappingCheck($service->queueAssetIfNeeded('Computer', 101, 'first'), 'Ordinary rows still queue alongside UUID conflicts.');
$service->processQueue(10);
$creates = array_values(array_filter($remote->requests, static fn (array $request): bool => $request['method'] === 'createItem'));
uuidMappingCheck(count($creates) === 1 && !isset($creates[0]['input']['uuid']) && $creates[0]['input']['name'] === 'Mapped name', 'Actual ordinary create flow excludes native UUID and preserves Name.');
uuidMappingCheck(AssetSyncLink::find('Computer', 101, 'first')['status'] === AssetSyncLink::STATUS_SYNCED, 'UUID conflicts do not block valid ordinary synchronization.');
echo 'UUID mapping boundary tests passed (' . $checks . " checks).\n";
