<?php

declare(strict_types=1);

// Both the HTTP transport and database are in memory; no GLPI instance is contacted.
$httpMetricsBootstrapOnly = true;
require __DIR__ . '/http-metrics.php';
$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\AssetUuidOperation;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;

class Toolbox
{
    public static array $entries = [];
    public static function logInFile(string $file, string $entry): void
    {
        self::$entries[] = json_decode(substr($entry, strlen('AssetSync2.0 run ')), true, 512, JSON_THROW_ON_ERROR);
    }
}

class AttemptUuidValidator
{
    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value);
    }
}
class_alias(AttemptUuidValidator::class, \Ramsey\Uuid\Uuid::class);

$attemptChecks = 0;
function attemptCheck(bool $condition, string $message): void
{
    global $attemptChecks;
    $attemptChecks++;
    if (!$condition) { throw new RuntimeException($message); }
}

function attemptSetup(int $customCount = 21, bool $uuid = true, bool $mixed = false): array
{
    $GLOBALS['DB'] = new FakeDB();
    $runLocks = 0;
    $GLOBALS['DB']->queryResult = static function (string $sql) use (&$runLocks): ?array {
        if (!str_contains($sql, "'assetsync20:run:")) { return null; }
        // MySQL advisory locks are reentrant on the same database connection.
        if (str_starts_with($sql, 'SELECT GET_LOCK')) {
            $runLocks++;
            $GLOBALS['DB']->runLockHeld = true;
            return [['acquired' => 1]];
        }
        if (str_starts_with($sql, 'SELECT RELEASE_LOCK')) {
            $GLOBALS['DB']->runLockHeld = --$runLocks > 0;
            return [['released' => 1]];
        }
        return null;
    };
    Config::$values = [];
    Config::$beforeRead = Config::$beforeWrite = Config::$beforeDelete = null;
    PluginFieldsContainer::$options = [];
    PluginFieldsField::$definitions = [];
    PluginFieldsComputerdmosasset::$rows = [];
    Toolbox::$entries = [];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    if ($uuid) { AssetUuidOperation::install(); }
    $connection = ['id' => 'a', 'base_url' => 'https://attempt.invalid', 'app_token' => 'private-app', 'user_token' => 'private-user', 'active' => true];
    GlpiBConnection::save($connection);
    EntitySyncRoute::save(['id' => 'route', 'glpi_b_connection_id' => 'a', 'glpi_a_source_entity_id' => '1',
        'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
    $asset = ['id' => 101, 'entities_id' => 1, 'name' => 'Local name', 'serial' => 'ATTEMPT-101',
        'uuid' => '12345678-1234-4234-8234-123456789012', 'date_mod' => '2026-01-01 00:00:00', 'is_deleted' => 0, 'is_template' => 0];
    $GLOBALS['DB']->insert('glpi_computers', $asset);
    $remote = array_replace($asset, ['id' => 201, 'entities_id' => 100, 'name' => 'Remote name']);
    $mappings = [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'glpi_b_field_uid' => 'Computer.name', 'source_of_truth' => 'glpi_a']];
    $fields = [['key' => 'name', 'uid' => 'Computer.name', 'id' => '1', 'label' => 'Name']];
    $options = [1 => ['field' => 'name', 'table' => 'glpi_computers', 'uid' => 'Computer.name']];
    $localRow = ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1];
    $remoteRow = array_replace($localRow, ['id' => 20, 'items_id' => 201]);
    $keys = [];
    for ($index = 1; $index <= $customCount; $index++) {
        $column = 'value' . $index . 'field';
        $key = 'Computer.PluginFieldsComputerdmosasset.' . $column;
        $type = $mixed && $index === 21 ? 'date' : 'text';
        $keys[] = $key;
        $option = ['id' => 6000 + $index, 'name' => $column, 'field' => $column,
            'table' => 'glpi_plugin_fields_computerdmosassets', 'pfields_type' => $type, 'pfields_fields_id' => 40 + $index];
        PluginFieldsContainer::$options[6000 + $index] = $options[6000 + $index] = $option;
        PluginFieldsField::$definitions[40 + $index] = ['id' => 40 + $index, 'name' => $column, 'type' => $type,
            'is_active' => 1, 'plugin_fields_containers_id' => 1, 'is_readonly' => 0];
        $mappings[] = ['glpi_a_field_key' => $key, 'glpi_b_field_key' => $key, 'glpi_b_field_uid' => $key,
            'source_of_truth' => $mixed && $index <= 20 ? 'glpi_a' : 'glpi_b'];
        $fields[] = ['key' => $key, 'uid' => $key, 'id' => (string) (6000 + $index), 'label' => $column];
        $localRow[$column] = $type === 'date' ? '2026-01-01' : 'Local ' . $index;
        $remoteRow[$column] = $type === 'date' ? '2026-02-01' : 'Remote ' . $index;
    }
    PluginFieldsComputerdmosasset::$rows[101] = $localRow;
    saveTestMappings('a', 'Computer', $mappings, $fields);
    AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'a',
        'route_id' => 'route', 'remote_items_id' => 201, 'status' => AssetSyncLink::STATUS_SYNCED]);
    $service = new AssetSyncService();
    attemptCheck($service->queueAssetIfNeeded('Computer', 101, 'a'), 'The fixture queues an ordinary asset.');
    return compact('service', 'connection', 'remote', 'remoteRow', 'options', 'keys');
}

function attemptPermissions(array $remote, int $right = 3): array
{
    return [httpStep('getActiveProfile', ['active_profile' => ['id' => 4, 'computer' => $right]]),
        httpStep('PluginFieldsContainer/1', ['id' => 1, 'name' => 'dmosasset', 'is_active' => 1,
            'itemtypes' => '["Computer"]', 'entities_id' => 100, 'is_recursive' => 0]),
        httpStep('PluginFieldsContainer/1/PluginFieldsProfile', [['id' => 1, 'profiles_id' => 4, 'plugin_fields_containers_id' => 1, 'right' => 4]])
            + ['headers' => ['Content-Range: 0-0/1']],
        httpStep('Computer/201', $remote)];
}

function attemptOrdinarySteps(array $fixture): array
{
    $steps = [httpStep('initSession', ['session_token' => 'ordinary-session']),
        httpStep('listSearchOptions/Computer', $fixture['options']), httpStep('Computer/201', $fixture['remote'])];
    if ($fixture['keys'] !== []) {
        foreach (PluginFieldsField::$definitions as $id => $definition) {
            $steps[] = httpStep('PluginFieldsField/' . $id, $definition);
        }
        array_push($steps, ...attemptPermissions($fixture['remote']));
        $steps[] = httpStep('Computer/201/PluginFieldsComputerdmosasset', [$fixture['remoteRow']]);
    }
    $steps[] = httpStep('Computer/201');
    $steps[] = httpStep('Computer/201', array_replace($fixture['remote'], ['name' => 'Local name']));
    $steps[] = httpStep('killSession');
    return $steps;
}

function attemptUuidSteps(array $remote): array
{
    return [httpStep('initSession', ['session_token' => 'uuid-session']), httpStep('getFullSession', ['glpitimezone' => 'UTC']),
        httpStep('Computer/201', $remote), httpStep('Computer/201/Log'), httpStep('killSession')];
}

function attemptMixedSteps(array $fixture): array
{
    $steps = attemptOrdinarySteps($fixture);
    array_splice($steps, -3); // Replace the final native write and cleanup with both outbound writes.
    array_push($steps, ...attemptPermissions($fixture['remote']));
    $steps[] = httpStep('Computer/201');
    $steps[] = httpStep('Computer/201', array_replace($fixture['remote'], ['name' => 'Local name']));
    array_push($steps, ...attemptPermissions(array_replace($fixture['remote'], ['name' => 'Local name'])));
    $steps[] = httpStep('Computer/201/PluginFieldsComputerdmosasset', [$fixture['remoteRow']]);
    $steps[] = httpStep('PluginFieldsComputerdmosasset/20');
    $verified = $fixture['remoteRow'];
    for ($index = 1; $index <= 20; $index++) { $verified['value' . $index . 'field'] = 'Local ' . $index; }
    $steps[] = httpStep('PluginFieldsComputerdmosasset/20', $verified);
    $steps[] = httpStep('killSession');
    return $steps;
}

function attemptEnded(): void
{
    attemptCheck(HttpMetricsCurl::$steps === [], 'Only the expected HTTP requests execute.');
    attemptCheck(HttpMetricsCurl::$closed === HttpMetricsCurl::$executions, 'Every initialized HTTP handle closes.');
    attemptCheck((new ReflectionProperty(GlpiBConnection::class, 'ordinaryAttempt'))->getValue() === null, 'Attempt session and metadata must clear.');
    attemptCheck((new ReflectionProperty(GlpiBConnection::class, 'httpDeadlineNs'))->getValue() === null, 'The HTTP deadline must restore.');
}

if ($attemptBootstrapOnly ?? false) { return; }

$fixture = attemptSetup();
$steps = [...attemptOrdinarySteps($fixture), ...attemptUuidSteps(array_replace($fixture['remote'], ['name' => 'Local name']))];
foreach ($steps as &$step) { $step['ms'] = 500; }
unset($step);
httpStart($steps);
$volume = $fixture['service']->run(1, 25);
$summary = Toolbox::$entries[0];
attemptCheck($summary['jobs_succeeded'] === 1 && $summary['jobs_retried'] === 0, 'All 22 mappings must complete at 500ms/request within the actual 25-second run.');
attemptCheck($summary['uuid']['attempted'] === 1 && $summary['uuid']['outcome'] === 'equal' && $summary['uuid']['seeded'] === 1, 'Bounded discovery seeds and runs one UUID operation without double bootstrap.');
attemptCheck($volume === $summary['enqueued'] + $summary['jobs_attempted'] && (float) $summary['total_ms'] === 18500.0, 'Run volume and real HTTP latency accounting stay exact.');
attemptCheck(count(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['path'] === 'initSession')) === 2, 'The ordinary attempt uses one session, separate from UUID.');
attemptCheck(count(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['path'] === 'Computer')) === 1, 'Native and custom mappings share one raw search-option read.');
attemptCheck(json_decode(Config::$values['plugin:assetsync20']['asset_sync_scan_cursors'], true)['route:Computer'] === '101', 'Scans still advance during slow ordinary/UUID work.');
for ($index = 1; $index <= 21; $index++) {
    attemptCheck(PluginFieldsComputerdmosasset::$rows[101]['value' . $index . 'field'] === 'Remote ' . $index, 'Every inbound field persists after the native outbound write.');
}
attemptEnded();
echo '22-mapping actual run: 37 requests, 18500ms, ordinary + UUID + scans complete.' . "\n";

$fixture = attemptSetup(21, true, true);
AssetUuidOperation::bootstrap(PHP_INT_MAX);
$GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = gmdate('Y-m-d H:i:s', time() + 3600);
$steps = attemptMixedSteps($fixture);
foreach ($steps as &$step) { $step['ms'] = 350; }
unset($step);
httpStart($steps);
$fixture['service']->run(1, 25);
$summary = Toolbox::$entries[0];
attemptCheck($summary['jobs_succeeded'] === 1 && $summary['jobs_retried'] === 0 && $summary['uuid']['attempted'] === 0, 'Future UUID work cannot take time from the original slow 22-mapping mixed-direction attempt.');
attemptCheck($summary['http']['a']['requests'] === 43 && (float) $summary['total_ms'] === 15050.0, 'The original mixed fixture uses 43 requests and one session at 350ms/request.');
attemptCheck(PluginFieldsComputerdmosasset::$rows[101]['value21field'] === '2026-02-01', 'The inbound date must persist after all native/custom outbound writes.');
$writes = array_values(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['options'][CURLOPT_CUSTOMREQUEST] === 'PUT'));
attemptCheck(count($writes) === 2, 'Both native and custom writes complete without changing direction order.');
attemptCheck($writes[0]['path'] === '201' && json_decode($writes[0]['options'][CURLOPT_POSTFIELDS], true) === ['input' => ['name' => 'Local name']], 'The native PUT sends the exact A-owned Name value first.');
$customInput = json_decode($writes[1]['options'][CURLOPT_POSTFIELDS], true)['input'];
$expectedInput = [];
for ($index = 1; $index <= 20; $index++) { $expectedInput['value' . $index . 'field'] = 'Local ' . $index; }
attemptCheck($writes[1]['path'] === '20' && $customInput === $expectedInput, 'The custom PUT sends all and only the 20 exact A-owned values to the generated row.');
attemptCheck(count(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['options'][CURLOPT_CUSTOMREQUEST] !== 'GET')) === 2, 'There are exactly two PUTs and every other HTTP request is a GET.');
attemptEnded();
echo 'Original mixed 22-mapping run: 43 requests, 1 session, 15050ms, both directions complete.' . "\n";

// The original mixed job must also complete with due UUID work in either priority order.
foreach (['ordinary', 'uuid'] as $priority) {
    $fixture = attemptSetup(21, true, true);
    Config::setConfigurationValues('plugin:assetsync20', ['asset_sync_next_priority' => $priority]);
    $ordinary = attemptMixedSteps($fixture);
    $uuid = attemptUuidSteps($priority === 'ordinary'
        ? array_replace($fixture['remote'], ['name' => 'Local name']) : $fixture['remote']);
    $steps = $priority === 'ordinary' ? [...$ordinary, ...$uuid] : [...$uuid, ...$ordinary];
    foreach ($steps as &$step) { $step['ms'] = 350; }
    unset($step);
    httpStart($steps);
    $fixture['service']->run(1, 25);
    $summary = Toolbox::$entries[0];
    attemptCheck($summary['jobs_succeeded'] === 1 && $summary['jobs_retried'] === 0
        && $summary['uuid']['attempted'] === 1 && $summary['uuid']['outcome'] === 'equal', 'Original mixed 22 mappings and due UUID work both complete with first priority: ' . $priority);
    attemptCheck($summary['http']['a']['requests'] === 48 && (float) $summary['total_ms'] === 16800.0, 'Both due classes share the fixed 25 seconds with exact simulated request cost.');
    attemptCheck(count(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['path'] === 'initSession')) === 2, 'One ordinary session and one separate UUID session are used.');
    attemptCheck(PluginFieldsComputerdmosasset::$rows[101]['value21field'] === '2026-02-01', 'The authoritative inbound date persists with either due-work priority.');
    $writes = array_values(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['options'][CURLOPT_CUSTOMREQUEST] !== 'GET'));
    attemptCheck(count($writes) === 2 && $writes[0]['options'][CURLOPT_CUSTOMREQUEST] === 'PUT'
        && json_decode($writes[0]['options'][CURLOPT_POSTFIELDS], true) === ['input' => ['name' => 'Local name']], 'The native outbound payload and write order remain exact.');
    attemptCheck($writes[1]['options'][CURLOPT_CUSTOMREQUEST] === 'PUT'
        && json_decode($writes[1]['options'][CURLOPT_POSTFIELDS], true) === ['input' => $expectedInput], 'All and only the 20 outbound custom values are sent; the inbound date is not sent to B.');
    attemptEnded();
}
echo 'Original mixed 22-mapping due UUID runs: 48 requests, 2 sessions, 16800ms in either priority order.' . "\n";

// A previously retried mixed job can recover without retaining sessions or failed responses.
$fixture = attemptSetup(21, true, true);
AssetUuidOperation::bootstrap(PHP_INT_MAX);
$GLOBALS['DB']->tables[AssetUuidOperation::TABLE][0]['next_attempt'] = gmdate('Y-m-d H:i:s', time() + 3600);
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['attempts'] = 10;
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['status'] = 'retry';
for ($attempt = 11; $attempt <= 13; $attempt++) {
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['available_at'] = null;
    $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['finished_at'] = gmdate('Y-m-d H:i:s', time() - 10000);
    httpStart([httpStep('initSession', [], 503)]);
    $fixture['service']->run(1, 25);
    $row = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
    attemptCheck($row['status'] === 'retry' && $row['attempts'] === $attempt, 'Repeated transient failures keep the existing retry count and resolve each claim.');
    attemptEnded();
}
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['available_at'] = null;
$GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['finished_at'] = gmdate('Y-m-d H:i:s', time() - 10000);
$steps = attemptMixedSteps($fixture);
foreach ($steps as &$step) { $step['ms'] = 350; }
unset($step);
httpStart($steps);
$fixture['service']->run(1, 25);
$row = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
attemptCheck($row['status'] === 'done' && $row['attempts'] === 14 && $row['last_error'] === 'Asset synchronized.', 'Recovered attempt replaces the prior failure with success instead of remaining in a deterministic retry loop.');
attemptCheck(HttpMetricsCurl::$executions === 43 && PluginFieldsComputerdmosasset::$rows[101]['value21field'] === '2026-02-01', 'Recovery starts a fresh session and completes the entire 22-field job.');
attemptEnded();

// Failed cleanup cannot replay a verified mutation; the outage pauses only its connection.
foreach ([httpStep('killSession', [], 503), httpStep('killSession', false, 0) + ['errno' => 7]] as $kill) {
    $fixture = attemptSetup(0, false);
    $steps = attemptOrdinarySteps($fixture);
    $steps[count($steps) - 1] = $kill;
    httpStart($steps);
    $fixture['service']->run(2);
    $summary = Toolbox::$entries[0];
    attemptCheck($summary['jobs_succeeded'] === 1 && $summary['jobs_retried'] === 0 && $summary['stop_reason'] === 'connections_paused', 'Cleanup outage preserves success and pauses that connection.');
    attemptCheck(count($summary['session_cleanup_failures']) === 1 && $summary['session_cleanup_failures'][0]['executed'], 'Cleanup failure remains explicit in actual run reporting.');
    attemptCheck(count(array_filter(HttpMetricsCurl::$trace, static fn ($entry) => $entry['options'][CURLOPT_CUSTOMREQUEST] === 'PUT')) === 1, 'A successful mutation is not repeated for cleanup.');
    attemptEnded();
}

$fixture = attemptSetup(0, false);
$steps = attemptOrdinarySteps($fixture);
array_pop($steps);
$steps[count($steps) - 1]['ms'] = 26000; // A stalled external operation can return after its timeout.
httpStart($steps);
$fixture['service']->run(1);
$summary = Toolbox::$entries[0];
attemptCheck($summary['jobs_succeeded'] === 1 && $summary['session_cleanup_failures'] === [
    ['status_code' => 0, 'cause' => 'budget_deadline', 'executed' => false],
], 'Expired total time skips remote cleanup explicitly and preserves verified success.');
attemptEnded();

$fixture = attemptSetup(0, false);
httpStart([httpStep('initSession', ['session_token' => 'ordinary-session']), httpStep('listSearchOptions/Computer', [], 503), httpStep('killSession')]);
$fixture['service']->run(1);
attemptCheck(Toolbox::$entries[0]['jobs_retried'] === 1, 'Primary remote failure still retries and cleans up once.');
attemptEnded();

$fixture = attemptSetup(0, false);
httpStart(attemptOrdinarySteps($fixture));
$GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields): void {
    if ($table === AssetSyncQueue::TABLE && array_key_exists('remote_items_id', $fields)) { throw new RuntimeException('Local failure after remote reads'); }
};
try {
    $fixture['service']->run(1);
    throw new LogicException('Expected the local persistence failure.');
} catch (RuntimeException $error) {
    attemptCheck($error->getMessage() === 'Local failure after remote reads', 'Attempt cleanup preserves the original processing exception.');
}
attemptEnded();

echo "Ordinary attempt tests passed ($attemptChecks checks).\n";
