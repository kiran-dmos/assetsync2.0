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
function reductionCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

function reductionSetup(string $mode = 'linked', array $changed = [0], string $source = 'glpi_a'): array
{
    $GLOBALS['DB'] = new FakeDB();
    Config::$values = [];
    PluginFieldsContainer::$options = [];
    PluginFieldsComputerdmosasset::$rows = [];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    GlpiBConnection::save(['id' => 'reduction', 'active' => true]);
    EntitySyncRoute::save([
        'id' => 'route', 'glpi_b_connection_id' => 'reduction', 'glpi_a_source_entity_id' => '1',
        'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true,
    ]);
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
    $GLOBALS['DB']->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0,
        'name' => 'Local name', 'serial' => 'REDUCTION-101', 'date_mod' => '2026-01-01 00:00:00']);
    $remote = new FakeGlpiBClient();
    $mappings = [1 => ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a']];
    $remoteFields = [['key' => 'name', 'uid' => 'Computer.name', 'id' => '1', 'label' => 'Name']];
    $localRow = ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1];
    $remoteValues = [];
    $keys = [];
    foreach (['firstfield', 'secondfield', 'thirdfield', 'fourthfield'] as $index => $column) {
        $key = 'Computer.PluginFieldsComputerdmosasset.' . $column;
        $keys[] = $key;
        PluginFieldsContainer::$options[6001 + $index] = ['id' => 6001 + $index, 'name' => $column, 'field' => $column,
            'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => 'text', 'pfields_fields_id' => 41 + $index];
        PluginFieldsField::$definitions[41 + $index] = ['id' => 41 + $index, 'name' => $column, 'type' => 'text',
            'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0];
        $mappings[$key] = ['glpi_b_field_key' => $key, 'glpi_b_field_uid' => $key, 'source_of_truth' => $source];
        $remoteFields[] = ['key' => $key, 'uid' => $key, 'id' => (string) (8001 + $index), 'label' => $column];
        $localRow[$column] = 'Local ' . $index;
        $remoteValues[$key] = in_array($index, $changed, true) ? 'Remote ' . $index : $localRow[$column];
        $remote->customHistoryOptionIds[$key] = (string) (8001 + $index);
    }
    PluginFieldsComputerdmosasset::$rows[101] = $localRow;
    \saveTestMappings('reduction', 'Computer', $mappings, $remoteFields);
    reductionCheck(FieldMapping::syncMappings('reduction', 'Computer')[0] === ['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a'], 'Fixture native mapping must resolve through the real local search-option ID.');
    if ($mode !== 'create') {
        $remote->records['reduction:Computer'][201] = ['id' => 201, 'entities_id' => 100, 'is_deleted' => 0,
            'name' => 'Remote name', 'serial' => 'REDUCTION-101', 'date_mod' => '2026-01-01 00:00:00'];
        $remote->customRecords['reduction:Computer'][201] = $remoteValues;
    }
    if ($mode === 'linked') {
        AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'reduction',
            'route_id' => 'route', 'remote_items_id' => 201, 'status' => AssetSyncLink::STATUS_SYNCED]);
    }
    $service = new AssetSyncService($remote);
    reductionCheck($service->queueAssetIfNeeded('Computer', 101, 'reduction'), 'Fixture must prepare one job.');
    return [$service, $remote, $keys];
}

function reductionCustomCalls(FakeGlpiBClient $remote): array
{
    return array_values(array_filter($remote->requests, static fn (array $request): bool => $request['method'] === 'customTextValues'
        && (empty($request['dry_run']) || $request['items_id'] === 0)));
}

function reductionStatus(): string
{
    return $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'];
}

foreach (['linked', 'matched'] as $mode) {
    [$service, $remote, $keys] = reductionSetup($mode);
    $remote->readonlyCustomKeys[$keys[0]] = true;
    reductionCheck($service->processQueue(1) === 1 && reductionStatus() === 'done', 'Existing assets must complete without zero-item validation.');
    $methods = array_column($remote->requests, 'method');
    $expected = ['getItem', 'customTextValues', 'customTextValues', 'updateItem', 'customTextValues'];
    reductionCheck($methods === ($mode === 'matched' ? ['searchBySerial', ...$expected] : $expected), 'Linked/matched ordering must fully read custom maps before native/custom updates.');
    $itemRead = array_values(array_filter($remote->requests, static fn ($request) => $request['method'] === 'getItem'))[0];
    reductionCheck(!$itemRead['needs_date_mod_timezone'], 'Linked/matched A-owned mappings must not require timezone permission.');
    [$read, $write] = reductionCustomCalls($remote);
    reductionCheck($read['items_id'] === 201 && $read['keys'] === $keys && $read['changes'] === [] && $read['expected_types'] === array_fill_keys($keys, 'text'), 'Initial read must still validate all four custom mappings and types.');
    reductionCheck($write['items_id'] === 201 && $write['keys'] === [$keys[0]] && $write['changes'] === [$keys[0] => 'Local 0'] && $write['expected_types'] === $read['expected_types'] && $write['allow_readonly'], 'One changed field among four must write only that key with unchanged expected types and allowReadonly true.');
    reductionCheck($remote->customRecords['reduction:Computer'][201] === array_combine($keys, ['Local 0', 'Local 1', 'Local 2', 'Local 3']), 'Narrow writes must persist the changed field and preserve all unchanged values.');
}

[$service, $remote, $keys] = reductionSetup('linked', [0, 2]);
$service->processQueue(1);
[$read, $write] = reductionCustomCalls($remote);
reductionCheck($write['keys'] === [$keys[0], $keys[2]] && $write['changes'] === [$keys[0] => 'Local 0', $keys[2] => 'Local 2'], 'Multiple changed keys must remain grouped in one helper call.');
reductionCheck($read['keys'] === $keys && reductionStatus() === 'done', 'Grouped changes must not narrow the initial full read.');

[$service, $remote, $keys] = reductionSetup('linked', []);
$service->processQueue(1);
reductionCheck(count(reductionCustomCalls($remote)) === 1 && reductionCustomCalls($remote)[0]['keys'] === $keys, 'Unchanged custom values must have one full read and no custom write/zero-item call.');

[$service, $remote, $keys] = reductionSetup('create');
$service->processQueue(1);
reductionCheck(array_column($remote->requests, 'method') === ['searchBySerial', 'customTextValues', 'createItem', 'customTextValues', 'customTextValues', 'customTextValues'], 'Creation must validate only after a zero-match search and immediately before native create.');
$custom = reductionCustomCalls($remote);
reductionCheck($custom[0]['items_id'] === 0 && $custom[0]['keys'] === $keys && $custom[0]['changes'] === array_combine($keys, ['Local 0', 'Local 1', 'Local 2', 'Local 3'])
    && $custom[0]['dry_run'] && $custom[0]['expected_types'] === array_fill_keys($keys, 'text'), 'Creation validation must resolve all configured custom destination values before POST.');
reductionCheck($custom[1]['items_id'] === 1000 && $custom[1]['keys'] === $keys && $custom[2]['keys'] === $keys && $custom[2]['allow_readonly'] && reductionStatus() === 'done', 'Missing custom rows after native creation must retain full initial read and all changed writes.');
reductionCheck($remote->customRecords['reduction:Computer'][1000] === array_combine($keys, ['Local 0', 'Local 1', 'Local 2', 'Local 3']), 'All new custom values must persist after creation.');

foreach ([false, true] as $transient) {
    [$service, $remote, $keys] = reductionSetup('create');
    $remote->customRequestResult = static fn (int $id): ?array => $id === 0 ? ['success' => false, 'message' => 'Invalid creation configuration', 'transient' => $transient] : null;
    $service->processQueue(1);
    reductionCheck(array_column($remote->requests, 'method') === ['searchBySerial', 'customTextValues'] && $remote->records === [], 'Failed creation validation must prevent every native POST/create call.');
    reductionCheck(reductionStatus() === ($transient ? 'retry' : 'blocked'), 'Creation validation failure must keep its retry/permanent classification.');
}

foreach (['linked', 'matched', 'create'] as $mode) {
    [$service, $remote, $keys] = reductionSetup($mode);
    $remote->unavailableCustomKeys = [$keys[3]]; // This field is unchanged on existing assets.
    $service->processQueue(1);
    reductionCheck(reductionStatus() === 'blocked' && !array_intersect(['createItem', 'updateItem'], array_column($remote->requests, 'method')), 'Invalid unchanged mappings must still block before any native create/update.');
    $custom = reductionCustomCalls($remote);
    reductionCheck(count($custom) === 1 && $custom[0]['keys'] === $keys
        && ($mode === 'create' ? $custom[0]['dry_run'] : $custom[0]['changes'] === [])
        && $custom[0]['items_id'] === ($mode === 'create' ? 0 : 201), 'All-map validation must remain on the correct creation/existing read path.');
}

[$service, $remote, $keys] = reductionSetup('linked', [0], 'both');
$GLOBALS['DB']->insert('glpi_logs', ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 101, 'itemtype_link' => '',
    'id_search_option' => 6001, 'date_mod' => '2026-01-03 00:00:00', 'old_value' => 'Old', 'new_value' => 'Local 0']);
$remote->customHistoryDates['reduction:Computer'][201]['8001'] = '2026-01-02 00:00:00';
$service->processQueue(1);
$history = array_values(array_filter($remote->requests, static fn (array $request): bool => $request['method'] === 'customHistoryDates'));
reductionCheck(count($history) === 1 && $history[0]['refs'] === [$keys[0] => ['option_id' => '8001', 'itemtype_link' => 'PluginFieldsComputerdmosasset']], 'Both history must use refs from the full initial read.');
reductionCheck(reductionStatus() === 'done' && reductionCustomCalls($remote)[1]['keys'] === [$keys[0]] && $remote->customRecords['reduction:Computer'][201][$keys[0]] === 'Local 0', 'Narrow writes must not change custom-history source selection.');
reductionCheck($remote->requests[0]['needs_date_mod_timezone'], 'Custom Both mappings must retain named timezone lookup.');

[$service, $remote] = reductionSetup();
$nativeBoth = FieldMapping::load('reduction', 'Computer');
$nativeBoth[0]['source_of_truth'] = 'both';
\saveTestMappings('reduction', 'Computer', $nativeBoth);
$service->processQueue(1);
reductionCheck($remote->requests[0]['needs_date_mod_timezone'], 'Native Both mappings must retain named timezone lookup.');

foreach (['linked', 'matched'] as $mode) {
    [$service, $remote, $keys] = reductionSetup($mode, [0], 'glpi_b');
    $defaultRead = $remote->getItem(['id' => 'reduction'], 'Computer', 201);
    reductionCheck($defaultRead['date_mod_timezone'] === $remote->dateModTimezone, 'Three-argument callers must retain timezone data by default.');
    $remote->requests = [];
    $service->processQueue(1);
    reductionCheck(reductionStatus() === 'done' && PluginFieldsComputerdmosasset::$rows[101]['firstfield'] === 'Remote 0', 'B-owned inbound values must still update A.');
    reductionCheck(count(reductionCustomCalls($remote)) === 1 && reductionCustomCalls($remote)[0]['keys'] === $keys, 'Inbound-only custom work must retain the full read and no outbound custom write.');
    $itemRead = array_values(array_filter($remote->requests, static fn ($request) => $request['method'] === 'getItem'))[0];
    reductionCheck(!$itemRead['needs_date_mod_timezone'], 'Mixed native A/custom B one-way mappings must not require timezone permission on linked or matched assets.');
}

[$service, $remote, $keys] = reductionSetup('matched');
$remote->records['reduction:Computer'][202] = array_replace($remote->records['reduction:Computer'][201], ['id' => 202]);
$service->processQueue(1);
reductionCheck(array_column($remote->requests, 'method') === ['searchBySerial'] && reductionStatus() === 'blocked', 'Duplicate serial matches must block without redundant custom validation.');
[$service, $remote] = reductionSetup('linked');
$remote->records = [];
$service->processQueue(1);
reductionCheck(array_column($remote->requests, 'method') === ['getItem'] && reductionStatus() === 'blocked', 'Missing linked assets must remain blocked without a pointless zero-item validation.');

echo "Request reduction tests passed ($checks checks).\n";
