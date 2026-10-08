<?php

declare(strict_types=1);

$attemptBootstrapOnly = true;
require __DIR__ . '/sync-attempts.php';

use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\GlpiBConnection;

function attemptRemote(array $connection, string $method, array $arguments): array
{
    return (new ReflectionMethod(AssetSyncService::class, 'callRemote'))->invoke(new AssetSyncService(), $method, [$connection, ...$arguments]);
}

$fixture = attemptSetup(1, false);
$connection = $fixture['connection'];
$key = $fixture['keys'][0];
$customArguments = ['Computer', 201, [$key]];
$changedRow = array_replace($fixture['remoteRow'], ['value1field' => 'Fresh value']);
httpStart([httpStep('initSession', ['session_token' => 's']), httpStep('listSearchOptions/Computer', $fixture['options']),
    httpStep('PluginFieldsField/41', PluginFieldsField::$definitions[41]), ...attemptPermissions($fixture['remote']),
    httpStep('Computer/201/PluginFieldsComputerdmosasset', [$fixture['remoteRow']]), ...attemptPermissions($fixture['remote']),
    httpStep('Computer/201/PluginFieldsComputerdmosasset', [$changedRow]),
    httpStep('getActiveProfile', ['active_profile' => ['id' => 4, 'computer' => 0]]), httpStep('killSession')]);
GlpiBConnection::beginOrdinaryAttempt(25_000_000_000);
attemptCheck(attemptRemote($connection, 'nativeMappingContext', ['Computer', ['name'], 100])['success'], 'Native metadata starts the single lazy session.');
$first = attemptRemote($connection, 'customTextValues', $customArguments);
$second = attemptRemote($connection, 'customTextValues', $customArguments);
attemptCheck($first['item'][$key] === 'Remote 1' && $second['item'][$key] === 'Fresh value', 'Reused descriptors never cache current custom values.');
$denied = attemptRemote($connection, 'customTextValues', $customArguments);
attemptCheck(!$denied['success'] && str_contains($denied['message'], 'permission'), 'A fresh revoked profile blocks access despite reusable metadata.');
attemptCheck(GlpiBConnection::endOrdinaryAttempt() === [], 'One successful cleanup ends all calls in the attempt.');
attemptEnded();
attemptCheck(HttpMetricsCurl::$executions === 15, 'Only search options and validated field definitions are reused; permission checks and values stay fresh.');

// An actual queue retry starts with new metadata, even when the same service object is reused.
$fixture = attemptSetup(1, false);
httpStart([httpStep('initSession', ['session_token' => 'first']), httpStep('listSearchOptions/Computer', $fixture['options']),
    httpStep('Computer/201', $fixture['remote']), httpStep('PluginFieldsField/41', PluginFieldsField::$definitions[41]),
    httpStep('getActiveProfile', [], 503), httpStep('killSession')]);
$fixture['service']->run(1, 25);
attemptCheck(Toolbox::$entries[0]['jobs_retried'] === 1, 'A transient failure after metadata reads retains ordinary retry behavior.');
attemptEnded();
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['available_at'] = null;
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['finished_at'] = gmdate('Y-m-d H:i:s', time() - 120);
$inactive = array_replace(PluginFieldsField::$definitions[41], ['is_active' => 0]);
httpStart([httpStep('initSession', ['session_token' => 'retry']), httpStep('listSearchOptions/Computer', $fixture['options']),
    httpStep('Computer/201', $fixture['remote']), httpStep('PluginFieldsField/41', $inactive), httpStep('killSession')]);
$fixture['service']->run(1, 25);
attemptCheck(Toolbox::$entries[1]['jobs_blocked'] === 1 && Toolbox::$entries[1]['jobs_succeeded'] === 0, 'Retry rereads a now-inactive remote definition and blocks before any write.');
attemptEnded();

// Endpoint and credential changes must not reuse either the session or metadata.
foreach (['id', 'base_url', 'app_token', 'user_token'] as $field) {
    httpStart([httpStep('initSession', ['session_token' => 's']), httpStep('Computer/201', $fixture['remote']), httpStep('killSession')]);
    GlpiBConnection::beginOrdinaryAttempt(25_000_000_000);
    attemptCheck(attemptRemote($connection, 'getItem', ['Computer', 201, false])['success'], 'The original identity can read within its session.');
    $changed = array_replace($connection, [$field => $connection[$field] . '-changed']);
    $result = attemptRemote($changed, 'getItem', ['Computer', 201, false]);
    attemptCheck(!$result['success'] && str_contains($result['message'], 'connection changed'), 'Changed identity is rejected without HTTP: ' . $field);
    GlpiBConnection::endOrdinaryAttempt();
    attemptEnded();
}
httpStart([httpStep('initSession', ['session_token' => 's']), httpStep('Computer/201', $fixture['remote']),
    httpStep('Computer/201', $fixture['remote']), httpStep('killSession')]);
GlpiBConnection::beginOrdinaryAttempt(25_000_000_000);
attemptRemote($connection, 'getItem', ['Computer', 201, false]);
attemptCheck(attemptRemote(array_reverse($connection, true), 'getItem', ['Computer', 201, false])['success'], 'Array key order does not change a connection identity.');
GlpiBConnection::endOrdinaryAttempt();
attemptEnded();

// Only a complete catalog can be reused after a failed page; raw catalogs retain all rows.
$typeOptions = [40 => ['table' => 'glpi_computertypes', 'field' => 'name', 'datatype' => 'dropdown', 'uid' => 'Computer.ComputerType.name']];
$firstPage = httpStep('ComputerType', [['id' => 1, 'name' => 'First']], 206) + ['headers' => ['Content-Range: 0-0/2']];
$complete = httpStep('ComputerType', [['id' => 1, 'name' => 'First'], ['id' => 2, 'name' => 'Second']]) + ['headers' => ['Content-Range: 0-1/2']];
httpStart([httpStep('initSession', ['session_token' => 's']), httpStep('listSearchOptions/Computer', $typeOptions),
    $firstPage, httpStep('ComputerType', [], 503), $complete, httpStep('killSession')]);
GlpiBConnection::beginOrdinaryAttempt(25_000_000_000);
$args = ['Computer', ['computertypes_id'], 100];
attemptCheck(!attemptRemote($connection, 'nativeMappingContext', $args)['success'], 'A failed later page never returns a partial catalog.');
$catalog = attemptRemote($connection, 'nativeMappingContext', $args);
attemptCheck(array_column($catalog['catalogs']['computertypes_id'], 'id') === [1, 2], 'The next call starts catalog pagination again and sees every row.');
attemptCheck(attemptRemote($connection, 'nativeMappingContext', $args) === $catalog, 'A proven complete raw catalog can be reused within the attempt.');
GlpiBConnection::endOrdinaryAttempt();
attemptEnded();

// Reusing a raw State catalog still rereads ancestry and candidate visibility.
$stateOptions = [31 => ['table' => 'glpi_states', 'field' => 'completename', 'datatype' => 'dropdown', 'linkfield' => 'states_id', 'uid' => 'Computer.State.completename']];
$state = ['id' => 7, 'name' => 'Ready', 'entities_id' => 0, 'is_recursive' => 1];
httpStart([httpStep('initSession', ['session_token' => 's']), httpStep('listSearchOptions/Computer', $stateOptions),
    httpStep('State', [$state]) + ['headers' => ['Content-Range: 0-0/1']], httpStep('Entity/100', ['id' => 100, 'entities_id' => 0]),
    httpStep('Entity/100', ['id' => 100, 'entities_id' => 200]), httpStep('Entity/200', ['id' => 200, 'entities_id' => 0]),
    httpStep('State/7', $state + ['is_visible_computer' => 1]), httpStep('State/7', $state + ['is_visible_computer' => 0]), httpStep('killSession')]);
GlpiBConnection::beginOrdinaryAttempt(25_000_000_000);
$first = attemptRemote($connection, 'nativeMappingContext', ['Computer', ['states_id'], 100]);
$second = attemptRemote($connection, 'nativeMappingContext', ['Computer', ['states_id'], 100]);
attemptCheck($first['entity_path'] === [100, 0] && $second['entity_path'] === [100, 200, 0], 'Entity ancestry stays fresh while the catalog is reused.');
$args = ['Computer', [$state], 7, ['Ready'], [100, 0]];
attemptCheck(count(attemptRemote($connection, 'nativeStateCatalog', $args)['rows']) === 1, 'A currently visible State remains eligible.');
attemptCheck(attemptRemote($connection, 'nativeStateCatalog', $args)['rows'] === [], 'Newly hidden State details cannot be cached.');
GlpiBConnection::endOrdinaryAttempt();
attemptEnded();

httpStart([]);
GlpiBConnection::beginOrdinaryAttempt(25_000_000_000);
attemptCheck(GlpiBConnection::endOrdinaryAttempt() === [] && HttpMetricsCurl::$executions === 0, 'An attempt without remote work neither opens nor kills a session.');
attemptEnded();
httpStart([httpStep('initSession', ['session_token' => 'one']), httpStep('Computer/201', $fixture['remote']), httpStep('killSession'),
    httpStep('initSession', ['session_token' => 'two']), httpStep('Computer/201', $fixture['remote']), httpStep('killSession')]);
attemptCheck(GlpiBConnection::getItem($connection, 'Computer', 201, false)['success'] && GlpiBConnection::getItem($connection, 'Computer', 201, false)['success'], 'Standalone API helpers keep independent sessions after scoped work.');
attemptEnded();

echo "Attempt cache and retry tests passed ($attemptChecks checks).\n";
