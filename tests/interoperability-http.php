<?php

declare(strict_types=1);

// Uses the strict in-memory HTTP transport; this test never opens a network connection.
require __DIR__ . '/remote-catalog-pagination.php';

use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\FieldsText;
use GlpiPlugin\Assetsync20\NativeField;

$startChecks = $checks;
$call = new ReflectionMethod(AssetSyncService::class, 'callRemote');
$typeOption = ['table' => 'glpi_computertypes', 'field' => 'name', 'datatype' => 'dropdown',
    'uid' => 'Computer.ComputerType.name'];
$options = catalogStep('listSearchOptions/Computer', [40 => $typeOption]);
catalogStart([$init, $options, catalogPage(0, 1000, 1001, 'ComputerType'), catalogPage(1000, 1, 1001, 'ComputerType'), $kill]);
$result = $call->invoke(new AssetSyncService(), 'nativeMappingContext', [$connection, 'Computer', ['computertypes_id'], 100]);
catalogCheck($result['success'] && count($result['catalogs']['computertypes_id']) === 1001, 'Global Type catalogs need complete pagination but no entity or per-option detail requests.');
catalogCheck(NativeField::idForLabel($result['catalogs']['computertypes_id'], 'Label 1001') === 1001, 'Native reference resolution includes later pages.');
catalogFinish(5);

$stateOption = ['table' => 'glpi_states', 'field' => 'completename', 'datatype' => 'dropdown',
    'linkfield' => 'states_id', 'uid' => 'Computer.State.completename'];
$stateRows = [];
for ($id = 1; $id <= 1000; $id++) {
    $stateRows[] = ['id' => $id, 'name' => 'State ' . $id, 'completename' => 'States > ' . $id, 'entities_id' => 0, 'is_recursive' => 1];
}
catalogStart([$init, catalogStep('listSearchOptions/Computer', [31 => $stateOption]),
    catalogStep('State', $stateRows, 206, ['Content-Range: 0-999/1000']),
    catalogStep('Entity/100', ['id' => 100, 'entities_id' => 0]), $kill]);
$result = $call->invoke(new AssetSyncService(), 'nativeMappingContext', [$connection, 'Computer', ['states_id'], 100]);
catalogCheck($result['success'] && count($result['catalogs']['states_id']) === 1000, 'State discovery does not hydrate every catalog row.');
catalogFinish(5);
catalogStart([$init,
    catalogStep('State/3', $stateRows[2] + ['is_visible_computer' => 0]),
    catalogStep('State/750', $stateRows[749] + ['is_visible_computer' => 1]), $kill]);
$states = $call->invoke(new AssetSyncService(), 'nativeStateCatalog', [$connection, 'Computer', $result['catalogs']['states_id'], 3, ['States > 750'], [100, 0]]);
catalogCheck($states['success'] && array_column($states['rows'], 'id') === [750], 'Only source and exact label candidates get detail reads; hidden State is excluded.');
catalogFinish(4);
catalogStart([$init, catalogStep('State/750', $stateRows[749]), $kill]);
$states = $call->invoke(new AssetSyncService(), 'nativeStateCatalog', [$connection, 'Computer', $stateRows, 0, ['States > 750'], [100, 0]]);
catalogCheck($states['success'] && $states['rows'] === [], 'Missing State visibility remains blocked.');
catalogFinish(3);

foreach ([['id' => 100, 'entities_id' => 'invalid'], ['id' => '100invalid', 'entities_id' => 0]] as $invalidEntity) {
    catalogStart([$init, catalogStep('listSearchOptions/Computer', [31 => $stateOption]),
        catalogStep('State', [$stateRows[0]], 200, ['Content-Range: 0-0/1']),
        catalogStep('Entity/100', $invalidEntity), $kill]);
    $result = $call->invoke(new AssetSyncService(), 'nativeMappingContext', [$connection, 'Computer', ['states_id'], 100]);
    catalogCheck(!$result['success'] && !$result['transient'] && str_contains($result['message'], 'Invalid reference ID'), 'Malformed remote ancestry must not become root or a matching entity ID.');
    catalogFinish(5);
}

foreach ([true, false] as $persist) {
    catalogStart([$init,
        catalogStep('Computer/2') + ['method' => 'PUT', 'input' => ['name' => 'Changed']],
        catalogStep('Computer/2', ['id' => 2, 'name' => $persist ? 'Changed' : 'Ignored']),
        $kill]);
    $result = $call->invoke(new AssetSyncService(), 'updateItem', [$connection, 'Computer', 2, ['name' => 'Changed']]);
    catalogCheck($result['success'] === $persist, 'Native PUT requires persisted value verification.');
    if (!$persist) {
        catalogCheck(!$result['transient'] && str_contains($result['message'], 'did not persist'), 'Ignored native PUT is explicit and never successful.');
    }
    catalogFinish(4);
}
catalogStart([$init,
    catalogStep('Computer', ['id' => 52], 201) + ['method' => 'POST', 'input' => ['name' => 'Created']],
    catalogStep('Computer/52', [], 503), $kill]);
$result = $call->invoke(new AssetSyncService(), 'createItem', [$connection, 'Computer', ['name' => 'Created']]);
catalogCheck(!$result['success'] && $result['transient'] && $result['id'] === 52, 'Native create retains its ID after transient verification failure.');
catalogFinish(4, 1);

$remoteKey = 'Computer.PluginFieldsComputerremoteonly.notesfield';
catalogCheck(!class_exists('PluginFieldsComputerremoteonly'), 'Remote generated class intentionally absent on A.');
FakeItemTypes::$classResult = static fn () => throw new LogicException('Local generated class resolver used for remote metadata');
FakeItemTypes::$tableResult = static fn () => throw new LogicException('Local generated table resolver used for remote metadata');
$remoteOption = ['table' => 'glpi_plugin_fields_computerremoteonlys', 'field' => 'notesfield', 'pfields_type' => 'textarea', 'pfields_fields_id' => 81];
$remoteDefinition = ['id' => 81, 'name' => 'notesfield', 'type' => 'textarea', 'is_active' => 1, 'plugin_fields_containers_id' => 91, 'is_readonly' => 1];
$remoteContainer = ['id' => 91, 'name' => 'remoteonly', 'is_active' => 1, 'itemtypes' => '["Computer"]', 'entities_id' => 0, 'is_recursive' => 0];
$remoteGrant = catalogStep('PluginFieldsContainer/91/PluginFieldsProfile',
    [['id' => 1, 'profiles_id' => 4, 'plugin_fields_containers_id' => 91, 'right' => 4]], 200, ['Content-Range: 0-0/1']);
$remoteRow = ['id' => 67, 'items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 91, 'notesfield' => 'Written on B'];
catalogStart([$init, catalogStep('listSearchOptions/Computer', [181 => $remoteOption]), catalogStep('PluginFieldsField/81', $remoteDefinition),
    $active, catalogStep('PluginFieldsContainer/91', $remoteContainer), $remoteGrant, $asset,
    catalogStep('Computer/2/PluginFieldsComputerremoteonly', []),
    catalogStep('PluginFieldsComputerremoteonly', ['id' => 67], 201) + ['method' => 'POST', 'input' => ['notesfield' => 'Written on B', 'items_id' => 2, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 91]],
    catalogStep('PluginFieldsComputerremoteonly/67', $remoteRow), $kill]);
$result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$remoteKey], [$remoteKey => 'Written on B'], [$remoteKey => 'text'], true]);
catalogCheck($result['success'] && $result['item'][$remoteKey] === 'Written on B', 'Text/textarea writes to B-only readonly generated class use B definitions, permissions, endpoint, and readback.');
catalogFinish(11);
$preparedMetadata = $result['metadata'];
catalogStart([$init, $active, catalogStep('PluginFieldsContainer/91', $remoteContainer), $remoteGrant, $asset, $kill]);
$result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$remoteKey],
    [$remoteKey => 'Prepared again'], [$remoteKey => 'text'], true, true, null, $preparedMetadata]);
catalogCheck($result['success'], 'Within one asset operation, preflight reuses descriptors but reads profile/container/asset permissions again.');
catalogFinish(6);
catalogStart([$init, catalogStep('getActiveProfile', ['active_profile' => ['id' => 4, 'computer' => 1]]), $kill]);
$result = $call->invoke(new AssetSyncService(), 'customTextValues', [$connection, 'Computer', 2, [$remoteKey],
    [$remoteKey => 'Must not write'], [$remoteKey => 'text'], true, true, null, $preparedMetadata]);
catalogCheck(!$result['success'] && str_contains($result['message'], 'update'), 'Prepared descriptors never cache or bypass changed asset permissions.');
catalogFinish(3);
FakeItemTypes::$classResult = FakeItemTypes::$tableResult = null;

echo 'Interoperability HTTP tests passed (' . ($checks - $startChecks) . " checks).\n";
