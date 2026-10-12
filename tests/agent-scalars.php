<?php

declare(strict_types=1);

require __DIR__ . '/agent-scalar-fixtures.php';

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\FieldsText;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;

$checks = 0;
function scalarCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function scalarReject(callable $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException) { scalarCheck(true, $message); return; }
    throw new LogicException($message);
}

// Test the same raw values against independent A/B implementations, including authoritative blanks.
$valid = [
    ['text', null, ''], ['text', '', ''], ['text', '  text  ', '  text  '], ['text', 0, '0'], ['text', false, ''],
    ['yesno', 0, 0], ['yesno', 1, 1], ['yesno', '0', 0], ['yesno', '1', 1],
    ['date', null, ''], ['date', '', ''], ['date', '2000-02-29', '2000-02-29'],
    ['date', '2024-02-29', '2024-02-29'], ['date', '0001-01-01', '0001-01-01'], ['date', '9999-12-31', '9999-12-31'],
];
foreach ($valid as [$type, $value, $expected]) {
    foreach ([false, true] as $authority) {
        scalarCheck(AgentTask::normalizeValue($type, $value, $authority) === $expected
            && TaskExecutor::normalizeValue($type, $value, $authority) === $expected, 'A/B normalization parity: ' . $type . ' ' . json_encode($value));
    }
}
foreach ([null, ''] as $blank) {
    scalarCheck(AgentTask::normalizeValue('yesno', $blank) === '' && TaskExecutor::normalizeValue('yesno', $blank) === '', 'Blank read stays distinct from zero.');
    foreach ([AgentTask::class, TaskExecutor::class] as $class) {
        scalarReject(static fn () => $class::normalizeValue('yesno', $blank, true), 'Blank yesno authority blocks.');
    }
}
$invalid = [
    'yesno' => [true, false, 0.0, 1.0, 2, -1, 'yes', 'no', 'true', 'false', '00', '01', ' 0', "1\n", 'NULL', [], new stdClass()],
    'date' => ['2026-02-29', '1900-02-29', '2026-04-31', '2026-00-12', '2026-13-01', '2026-01-00', '0000-01-01',
        '0000-00-00', '2026-1-01', '26-01-01', '2026-01-1', '2026-01-01 00:00:00', ' 2026-01-01', "2026-01-01\n", 'NULL', 0, false, [], new stdClass()],
    'text' => [[], new stdClass()], 'datetime' => ['2026-01-01 00:00:00'], 'dropdown' => ['value'], 'int' => [1],
];
foreach ($invalid as $type => $values) {
    foreach ($values as $value) {
        foreach ([false, true] as $authority) {
            foreach ([AgentTask::class, TaskExecutor::class] as $class) {
                scalarReject(static fn () => $class::normalizeValue($type, $value, $authority), 'Invalid scalar rejected by ' . $class . ': ' . $type);
            }
        }
    }
}

$settings = scalarSetup();
$snapshot = scalarSnapshot($settings);
scalarCheck(array_column($snapshot['fields'], 'type') === ['text', 'text', 'text', 'yesno', 'date'], 'Typed inspection includes only the three allowed types.');
scalarCheck($snapshot['fields'][scalarKey('yesfield')]['value'] === '', 'Database NULL yesno is blank, not zero.');
$legacy = scalarSnapshot($settings, 1);
scalarCheck(array_keys($legacy['fields']) === ['name', 'comment', scalarKey('textfield')], 'V1 inspection stays text-only even beside scalar definitions.');
scalarCheck(array_keys($legacy) === ['computer_id', 'entity_id', 'serial', 'fields', 'observed_at']
    && array_keys($legacy['fields'][scalarKey('textfield')]) === ['key', 'label', 'type', 'group', 'option', 'container', 'field', 'child', 'metadata', 'value'], 'Legacy snapshot shape is unchanged.');
scalarCheck($legacy['fields'][scalarKey('textfield')] === $snapshot['fields'][scalarKey('textfield')], 'Text metadata and values retain identical wire representation.');
scalarCheck($_SESSION === ['caller' => 'preserved'] && Search::$clears >= 6, 'Actual ServiceContext restores caller and clears search options.');

// Independent raw-history expectations: localized bool labels and official date input sentinel.
foreach ([['yesfield', null, 0, 'Non', 'Non'], ['yesfield', null, 1, 'Non', 'Oui'],
    ['yesfield', 0, 1, 'Non', 'Oui'], ['yesfield', 1, 0, 'Oui', 'Non'],
    ['datefield', '2026-10-12', '', '2026-10-12', 'NULL'], ['datefield', null, '2024-02-29', '', '2024-02-29'],
    ['datefield', '2026-10-12', '2026-10-13', '2026-10-12', '2026-10-13'],
    ['textfield', 'old', 'new', 'old', 'new']] as [$column, $old, $value, $auditOld, $auditNew]) {
    $settings = scalarSetup();
    $DB->update(PluginFieldsComputerscalar::getTable(), [$column => $old], ['items_id' => 201]);
    $task = scalarTask($settings, [$column => $value]);
    $result = TaskExecutor::execute($task, $settings);
    scalarCheck($result['outcome'] === 'verified' && count($result['changed']) === 1, 'Scalar model write verifies: ' . $column . ' ' . json_encode([$old, $value]));
    $stored = $column === 'datefield' && $value === '' ? null : $value;
    scalarCheck(scalarRow()[$column] === $stored, 'Persisted scalar readback matches, including SQL NULL clear.');
    $log = $DB->firstRow('glpi_logs', ['id' => $result['changed'][scalarKey($column)]]);
    scalarCheck($log['old_value'] === $auditOld && $log['new_value'] === $auditNew, 'Exact localized/raw official history representation.');
    if ($column === 'yesfield' && $value === 0) {
        scalarCheck(PluginFieldsContainer::$inputs[0][$column] === '0', 'Zero uses string model input to defeat GLPI loose NULL comparison.');
    }
    $validateResult = new ReflectionMethod(AgentTask::class, 'validateResult');
    $validateResult->invoke(null, $task, $result);
    $calls = PluginFieldsContainer::$calls;
    $noop = TaskExecutor::execute(scalarTask($settings, [$column => $value]), $settings);
    scalarCheck($noop['outcome'] === 'unchanged' && $noop['changed'] === [] && PluginFieldsContainer::$calls === $calls
        && count(iterator_to_array($DB->request(['FROM' => 'glpi_logs']))) === 1, 'Fresh no-op adds no model write or history.');
    scalarCheck($DB->firstRow('glpi_plugin_fields_fields', ['id' => $column === 'textfield' ? 1 : ($column === 'yesfield' ? 2 : 3)])['is_readonly'] === 1, 'Readonly definition remains intact.');
}

$settings = scalarSetup();
Search::$options[102]['name'] = 'Translated container - Translated flag';
$translated = TaskExecutor::execute(scalarTask($settings, ['yesfield' => 0]), $settings);
scalarCheck($translated['outcome'] === 'verified', 'B History uses the localized official search-option label.');

$settings = scalarSetup();
PluginFieldsComputerscalar::$dateNullSentinel = false;
$cleared = TaskExecutor::execute(scalarTask($settings, ['datefield' => '']), $settings);
scalarCheck($cleared['outcome'] === 'verified' && scalarRow()['datefield'] === '',
    'Fields generated varchar date model can retain blank and log blank, not NULL sentinel.');

$settings = scalarSetup();
$task = scalarTask($settings, ['datefield' => '']);
PluginFieldsStatusOverride::$overrides = [['plugin_fields_containers_id' => 1, 'plugin_fields_fields_id' => 3,
    'itemtype' => 'Computer', 'states' => [0], 'mandatory' => 1]];
$denied = TaskExecutor::execute($task, $settings);
scalarCheck($denied['outcome'] === 'blocked' && scalarRow()['datefield'] === '2026-10-12'
    && PluginFieldsContainer::$calls === 0, 'Fresh status override blocks B date clear before model write.');

foreach (['name', 'comment'] as $key) {
    $settings = scalarSetup();
    $result = TaskExecutor::execute(scalarTask($settings, [$key => 'Native changed']), $settings);
    scalarCheck($result['outcome'] === 'verified' && PluginFieldsContainer::$calls === 0
        && $DB->firstRow('glpi_computers', ['id' => 201])[$key] === 'Native changed', 'Native text still uses the Computer model.');
}

$settings = scalarSetup();
$task = scalarTask($settings, ['yesfield' => 0]);
foreach (['version', 'type', 'expected', 'value', 'hash', 'binding', 'extra', 'native'] as $failure) {
    $bad = $task;
    $key = scalarKey('yesfield');
    if ($failure === 'version') { $bad['version'] = 3; }
    if ($failure === 'type') { $bad['changes'][$key]['type'] = 'int'; }
    if ($failure === 'expected') { $bad['changes'][$key]['expected'] = null; }
    if ($failure === 'value') { $bad['changes'][$key]['value'] = ''; }
    if ($failure === 'binding') { $bad['generation'] = str_repeat('d', 32); }
    if ($failure === 'extra') { $bad['changes'][$key]['extra'] = true; }
    if ($failure === 'native') { $bad['changes']['name'] = $bad['changes'][$key]; unset($bad['changes'][$key]); }
    $bad = scalarHash($bad);
    if ($failure === 'hash') { $bad['changes'][$key]['value'] = '0'; }
    scalarReject(static fn () => TaskExecutor::validate($bad, $settings), 'Typed task rejects invalid ' . $failure);
}
$wire = $task;
$wire['changes'][scalarKey('yesfield')]['value'] = '0';
$wire = scalarHash($wire);
scalarReject(static fn () => TaskExecutor::validate($wire, $settings), 'Hashed noncanonical string zero is rejected, never silently normalized.');
scalarCheck(TaskExecutor::execute($task, $settings)['outcome'] === 'verified', 'Valid task survives invalid task probes.');

foreach (['stale_value', 'stale_metadata', 'stale_type', 'missing_column', 'missing_definition', 'missing_row', 'duplicate_row',
    'bad_identity', 'missing_option', 'bad_datatype', 'mandatory', 'deny', 'ignore', 'history_missing', 'history_wrong', 'commit',
    'revoked_user', 'revoked_profile', 'revoked_grant', 'revoked_container', 'revoked_parent', 'fresh_value'] as $failure) {
    $settings = scalarSetup();
    $task = scalarTask($settings, [$failure === 'mandatory' ? 'datefield' : 'yesfield' => $failure === 'mandatory' ? '' : 0]);
    $expected = 'blocked';
    if ($failure === 'stale_value') { $DB->update(PluginFieldsComputerscalar::getTable(), ['yesfield' => 1], ['items_id' => 201]); $expected = 'stale'; }
    if ($failure === 'stale_metadata') { $DB->update('glpi_plugin_fields_fields', ['label' => 'Renamed'], ['id' => 2]); $expected = 'stale'; }
    if ($failure === 'stale_type') { $task['changes'][scalarKey('yesfield')]['type'] = 'text'; $task['changes'][scalarKey('yesfield')]['value'] = '0'; $task = scalarHash($task); $expected = 'stale'; }
    if ($failure === 'missing_column') { $DB->doQuery('ALTER TABLE glpi_plugin_fields_computerscalars DROP COLUMN yesfield'); }
    if ($failure === 'missing_definition') { $DB->doQuery('DELETE FROM glpi_plugin_fields_fields WHERE id = 2'); $expected = 'stale'; }
    if ($failure === 'missing_row') { $DB->doQuery('DELETE FROM glpi_plugin_fields_computerscalars WHERE items_id = 201'); }
    if ($failure === 'duplicate_row') { $copy = scalarRow(); $copy['id'] = 202; $DB->insert(PluginFieldsComputerscalar::getTable(), $copy); }
    if ($failure === 'bad_identity') { $DB->update(PluginFieldsComputerscalar::getTable(), ['itemtype' => 'Monitor'], ['items_id' => 201]); }
    if ($failure === 'missing_option') { unset(Search::$options[102]); }
    if ($failure === 'bad_datatype') { Search::$options[102]['datatype'] = 'string'; }
    if ($failure === 'mandatory') { $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 3]); $task = scalarTask($settings, ['datefield' => '']); }
    if (in_array($failure, ['deny', 'ignore', 'history_missing', 'history_wrong'], true)) { PluginFieldsContainer::$fault = $failure; $expected = 'uncertain'; }
    if ($failure === 'commit') { $DB->sql->failOn = 'COMMIT'; $expected = 'uncertain'; }
    if ($failure === 'revoked_user') { User::$active = false; }
    if ($failure === 'revoked_profile') { Profile::$rights = READ; }
    if ($failure === 'revoked_grant') { Profile_User::$granted = false; }
    if ($failure === 'revoked_container') { PluginFieldsProfile::$right = READ; $expected = 'stale'; }
    if ($failure === 'revoked_parent') { Computer::$allowUpdate = false; }
    if ($failure === 'fresh_value') {
        $expected = 'stale';
        Search::$clears = 0;
        Search::$onClear = static function () use ($DB): void {
            if (Search::$clears === 3) { $DB->update(PluginFieldsComputerscalar::getTable(), ['yesfield' => 1], ['items_id' => 201]); }
        };
    }
    $before = scalarRow();
    $result = TaskExecutor::execute($task, $settings);
    scalarCheck($result['outcome'] === $expected && $result['changed'] === [], 'Failure stays explicit with no committed history: ' . $failure . ' got ' . $result['outcome']);
    scalarCheck(scalarRow() === $before && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Failure leaves asset/history unchanged: ' . $failure);
    scalarCheck(!$DB->sql->pdo->inTransaction() && $_SESSION === ['caller' => 'preserved'], 'Failure restores transaction/session: ' . $failure);
}

$settings = scalarSetup();
$task = scalarTask($settings, ['yesfield' => 0, 'datefield' => '2026-10-13']);
$DB->sql->beforeQuery = static function (string $sql) use ($DB): void {
    if ($sql === 'COMMIT' && PluginFieldsContainer::$calls === 1) { PluginFieldsProfile::$right = READ; }
};
$result = TaskExecutor::execute($task, $settings);
scalarCheck($result['outcome'] === 'partial' && count($result['changed']) === 1 && scalarRow()['yesfield'] === 0
    && scalarRow()['datefield'] === '2026-10-12', 'Permission revoked after first commit retains exactly the committed scalar and history.');

// Both old and new journals retain exact bodies, hashes and results, including expired replay.
foreach ([1, 2] as $version) {
    $settings = scalarSetup();
    scalarCheck(TaskJournal::install(), 'Journal installs.');
    $task = scalarTask($settings, $version === 1 ? ['textfield' => 'legacy'] : ['yesfield' => 0], $version);
    $result = TaskJournal::run($task, $settings);
    $saved = $DB->firstRow(TaskJournal::TABLE, ['id' => $task['id']]);
    scalarCheck($result['outcome'] === 'verified' && $saved['body'] === json_encode($task, JSON_THROW_ON_ERROR)
        && $saved['hash'] === $task['hash'], 'Journal keeps immutable original wire and hash v' . $version);
    $count = PluginFieldsContainer::$calls;
    User::$active = false;
    scalarCheck(TaskJournal::run($task, $settings) === $result && PluginFieldsContainer::$calls === $count
        && $DB->firstRow(TaskJournal::TABLE, ['id' => $task['id']]) === $saved, 'Replay uses exact durable result without model/auth execution v' . $version);
    $expired = $task; $expired['id'] = bin2hex(random_bytes(16)); $expired['expires'] = time() - 1; $expired = scalarHash($expired);
    $copy = $saved; $copy['id'] = $expired['id']; $copy['hash'] = $expired['hash']; $copy['body'] = json_encode($expired);
    $DB->insert(TaskJournal::TABLE, $copy);
    scalarCheck(TaskJournal::run($expired, $settings) === $result, 'Expired durable result still replays v' . $version);
    $interrupted = $task; $interrupted['id'] = bin2hex(random_bytes(16)); $interrupted = scalarHash($interrupted);
    $copy['id'] = $interrupted['id']; $copy['hash'] = $interrupted['hash']; $copy['body'] = json_encode($interrupted); $copy['result'] = '{}'; $copy['state'] = 'executing';
    $DB->insert(TaskJournal::TABLE, $copy);
    scalarCheck(TaskJournal::run($interrupted, $settings) === ['outcome' => 'uncertain', 'changed' => []]
        && PluginFieldsContainer::$calls === $count, 'Interrupted journal never reruns a possible write v' . $version);
}

function scalarControllerSetup(string $column, string $authority, mixed $a, mixed $b): array
{
    global $DB;
    $settings = scalarSetup();
    $DB->update(PluginFieldsComputerscalar::getTable(), [$column => $a], ['items_id' => 101]);
    $DB->update(PluginFieldsComputerscalar::getTable(), [$column => $b], ['items_id' => 201]);
    scalarCheck(AssetSyncLink::install() && AssetSyncQueue::install() && AgentInbox::install() && AgentTask::install(), 'Controller schemas install.');
    $DB->doQuery('CREATE TABLE glpi_entities (id INTEGER PRIMARY KEY, entities_id INTEGER, name TEXT, completename TEXT)');
    $DB->insert('glpi_entities', ['id' => 100, 'entities_id' => 0, 'name' => 'Scope', 'completename' => 'Scope']);
    GlpiBConnection::save(['id' => 'b', 'mode' => 'agent', 'base_url' => 'https://b.invalid', 'active' => true]);
    EntitySyncRoute::save(['id' => 'route', 'glpi_b_connection_id' => 'b', 'glpi_a_source_entity_id' => '100',
        'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    $credentials = AgentTask::declare('b', $settings['installation'], 101, 201, 100);
    $settings = array_replace($settings, $credentials);
    Config::$values['plugin:assetsync20agent'] = $settings;
    $request = array_intersect_key($settings, array_flip(['registration', 'generation', 'installation', 'connection_id']));
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $credentials['token'])['task'];
    scalarReceipt($request, $credentials['token'], $task, TaskExecutor::execute($task, $settings));
    AgentTask::confirm('b', 'route');
    FieldMapping::save('b', 'Computer', [['glpi_a_field_key' => scalarKey($column), 'glpi_b_field_key' => scalarKey($column),
        'source_of_truth' => $authority]], AgentTask::catalog('b', 'Computer')['fields']);
    return [$settings, $request, $credentials['token']];
}
function scalarReceipt(array $request, string $token, array $task, array $result): void
{
    $ack = AgentTask::exchange($request + ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'], 'result' => $result], $token);
    scalarCheck($ack['recorded'] === true, 'Controller accepts independently validated evidence.');
}
function scalarApplyLocal(array $changes): bool
{
    scalarCheck(FieldsText::updateLocal('Computer', 101, $changes, true), 'A reuses its existing FieldsText model writer.');
    return false;
}
function scalarControllerRun(array $settings, array $request, string $token): void
{
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    for ($step = 0; $step < 2; $step++) {
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarCheck($task['version'] === 3, 'New controller publishes typed version 3.');
        scalarReceipt($request, $token, $task, TaskExecutor::execute($task, $settings));
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    }
}
foreach (['glpi_a', 'glpi_b'] as $authority) {
    foreach ([['yesfield', null, 0], ['yesfield', 1, 0], ['yesfield', 0, 1],
        ['datefield', '2026-10-12', null], ['datefield', null, '2024-02-29'], ['datefield', '2026-10-12', '2026-10-13'],
        ['textfield', 'old', 'new']] as [$column, $destination, $source]) {
        [$settings, $request, $token] = scalarControllerSetup($column, $authority,
            $authority === 'glpi_a' ? $source : $destination, $authority === 'glpi_b' ? $source : $destination);
        scalarControllerRun($settings, $request, $token);
        $expected = AgentTask::normalizeValue($column === 'yesfield' ? 'yesno' : ($column === 'datefield' ? 'date' : 'text'), $source);
        $pair = AgentTask::pair('b');
        scalarCheck(AgentTask::context($pair)['values'][scalarKey($column)] === $expected
            && scalarSnapshot($settings)['fields'][scalarKey($column)]['value'] === $expected
            && AgentTask::outstanding('b') === null, 'Both sides converge: ' . $authority . ' ' . $column);
        scalarCheck(!AgentTask::needsInspection($pair, false), 'Completed fingerprint converges without republishing.');
        $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
        scalarControllerRun($settings, $request, $token);
        scalarCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Explicit reinspection and no-op add no history.');
    }
}

scalarCheck(!method_exists(PluginFieldsContainer::class, 'canUpdateTargetItem'), 'Tests run without the Fields 1.24.5-only permission helper.');

// Controller rejects malformed or incomplete typed readback before storing it as successful evidence.
$settings = scalarSetup();
$task = scalarTask($settings, ['yesfield' => 0]);
$result = TaskExecutor::execute($task, $settings);
$validateResult = new ReflectionMethod(AgentTask::class, 'validateResult');
foreach (['blank', 'null', 'string_zero', 'wrong_type', 'missing_value', 'missing_field', 'missing_history', 'metadata', 'native_type'] as $failure) {
    $bad = $result;
    $key = scalarKey('yesfield');
    if ($failure === 'blank') { $bad['snapshot']['fields'][$key]['value'] = ''; }
    if ($failure === 'null') { $bad['snapshot']['fields'][$key]['value'] = null; }
    if ($failure === 'string_zero') { $bad['snapshot']['fields'][$key]['value'] = '0'; }
    if ($failure === 'wrong_type') { $bad['snapshot']['fields'][$key]['type'] = 'text'; $bad['snapshot']['fields'][$key]['value'] = '0'; }
    if ($failure === 'missing_value') { unset($bad['snapshot']['fields'][$key]['value']); }
    if ($failure === 'missing_field') { unset($bad['snapshot']['fields'][$key]); }
    if ($failure === 'missing_history') { $bad['changed'] = []; }
    if ($failure === 'metadata') { $bad['snapshot']['fields'][$key]['metadata'] = str_repeat('f', 64); }
    if ($failure === 'native_type') { $bad['snapshot']['fields']['name']['type'] = 'yesno'; $bad['snapshot']['fields']['name']['value'] = 0; }
    scalarReject(static fn () => $validateResult->invoke(null, $task, $bad), 'Controller rejects result ' . $failure);
}
$legacyTask = scalarTask($settings, [], 1);
scalarReject(static fn () => $validateResult->invoke(null, $legacyTask, $result), 'V1 results cannot acquire scalar fields.');
$legacyResult = TaskExecutor::execute($legacyTask, $settings);
$validateResult->invoke(null, $legacyTask, $legacyResult);
scalarCheck($legacyResult['outcome'] === 'unchanged', 'V1 text-only inspection is still accepted on A.');

// Existing limits remain in force for both task versions and all eligible scalar fields.
foreach ([1, 2] as $version) {
    $task = scalarTask($settings, ['textfield' => str_repeat('a', 16384)], $version);
    TaskExecutor::validate($task, $settings);
    $task['changes'][scalarKey('textfield')]['value'] .= 'a'; $task = scalarHash($task);
    scalarReject(static fn () => TaskExecutor::validate($task, $settings), 'Text cap remains 16 KiB v' . $version);
    $task = scalarTask($settings, [], $version);
    $task['kind'] = 'apply';
    for ($i = 0; $i < 33; $i++) {
        $task['changes'][scalarKey('extra' . $i)] = ($version === 2 ? ['type' => 'text'] : [])
            + ['metadata' => str_repeat('a', 64), 'expected' => '', 'value' => 'x'];
    }
    $task = scalarHash($task);
    scalarReject(static fn () => TaskExecutor::validate($task, $settings), 'Task mapping cap remains 32 v' . $version);
}
$DB->update('glpi_computers', ['name' => str_repeat('a', 49152)], ['id' => 201]);
scalarReject(static fn () => scalarSnapshot($settings), 'Snapshot cap remains 48 KiB.');
$settings = scalarSetup();
for ($i = 0; $i < 28; $i++) {
    $column = 'extra' . $i;
    $DB->doQuery('ALTER TABLE glpi_plugin_fields_computerscalars ADD COLUMN ' . $column . ' INTEGER');
    $DB->insert('glpi_plugin_fields_fields', ['id' => 10 + $i, 'name' => $column, 'label' => $column, 'type' => 'yesno',
        'plugin_fields_containers_id' => 1, 'is_active' => 1, 'is_readonly' => 0, 'mandatory' => 0]);
    Search::$options[110 + $i] = array_replace(Search::$options[102], ['field' => $column]);
}
scalarReject(static fn () => scalarSnapshot($settings), 'Total eligible field cap remains 32 including unmapped scalars and native fields.');
scalarCheck(count(scalarSnapshot($settings, 1)['fields']) === 3, 'V1 budget still counts only its original eligible text fields.');

foreach (['glpi_a', 'glpi_b'] as $authority) {
    [$settings, $request, $token] = scalarControllerSetup('yesfield', $authority,
        $authority === 'glpi_a' ? null : 0, $authority === 'glpi_b' ? null : 0);
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarReceipt($request, $token, $task, TaskExecutor::execute($task, $settings));
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    scalarCheck(AgentTask::outstanding('b')['state'] === 'blocked' && PluginFieldsContainer::$calls === 0
        && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Blank authority blocks before any apply task: ' . $authority);
}

foreach (['missing_row', 'duplicate_row', 'missing_column', 'missing_option', 'missing_definition', 'wrong_definition', 'unsupported_type'] as $failure) {
    scalarControllerSetup('yesfield', 'glpi_b', 0, 1);
    if ($failure === 'missing_row') { $DB->doQuery('DELETE FROM glpi_plugin_fields_computerscalars WHERE items_id = 101'); }
    if ($failure === 'duplicate_row') { $row = scalarRow(101); $row['id'] = 102; $DB->insert(PluginFieldsComputerscalar::getTable(), $row); }
    if ($failure === 'missing_column') { $DB->doQuery('ALTER TABLE glpi_plugin_fields_computerscalars DROP COLUMN yesfield'); }
    if ($failure === 'missing_option') { unset(Search::$options[102]); }
    if ($failure === 'missing_definition') { $DB->doQuery('DELETE FROM glpi_plugin_fields_fields WHERE id = 2'); }
    if ($failure === 'wrong_definition') { $DB->update('glpi_plugin_fields_fields', ['name' => 'wrong'], ['id' => 2]); }
    if ($failure === 'unsupported_type') { Search::$options[102]['pfields_type'] = 'number'; $DB->update('glpi_plugin_fields_fields', ['type' => 'number'], ['id' => 2]); }
    scalarReject(static fn () => AgentTask::context(AgentTask::pair('b')), 'Local missing/unsupported metadata is an error, not blank: ' . $failure);
    scalarCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Local context failure cannot create or write data.');
}

foreach (['mandatory', 'status_mandatory', 'readback', 'fresh_local', 'type_mismatch'] as $failure) {
    [$settings, $request, $token] = scalarControllerSetup('datefield', 'glpi_b', '2026-10-12', null);
    if ($failure === 'mandatory') { $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 3]); }
    if ($failure === 'status_mandatory') {
        PluginFieldsStatusOverride::$overrides = [['plugin_fields_containers_id' => 1, 'plugin_fields_fields_id' => 3,
            'itemtype' => 'Computer', 'states' => [0], 'mandatory' => 1]];
    }
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    $result = TaskExecutor::execute($task, $settings);
    if ($failure === 'type_mismatch') { $result['snapshot']['fields'][scalarKey('datefield')]['type'] = 'text'; }
    scalarReceipt($request, $token, $task, $result);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    if ($failure === 'type_mismatch') {
        scalarCheck(AgentTask::outstanding('b')['state'] === 'blocked', 'Fresh endpoint type mismatch blocks before apply publication.');
        continue;
    }
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarReceipt($request, $token, $task, TaskExecutor::execute($task, $settings));
    if ($failure === 'readback') { PluginFieldsComputerscalar::$ignoreUpdate = true; }
    if ($failure === 'fresh_local') { $DB->update(PluginFieldsComputerscalar::getTable(), ['datefield' => '2026-10-14'], ['items_id' => 101]); }
    $before = scalarRow(101);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    scalarCheck(AgentTask::outstanding('b')['state'] === ($failure === 'fresh_local' ? 'stale' : 'uncertain')
        && scalarRow(101) === $before && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'A denial/stale/readback failure retains explicit evidence without changing data: ' . $failure);
}

[$settings, $request, $token] = scalarControllerSetup('datefield', 'glpi_b', '2026-10-12', null);
$DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 3]);
PluginFieldsStatusOverride::$overrides = [['plugin_fields_containers_id' => 1, 'plugin_fields_fields_id' => 3,
    'itemtype' => 'Computer', 'states' => [0], 'mandatory' => 0]];
AssetSyncQueue::notify('Computer', 101, 'b');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
foreach (['inspect', 'apply'] as $kind) {
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarReceipt($request, $token, $task, TaskExecutor::execute($task, $settings));
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
}
scalarCheck(AgentTask::outstanding('b') === null && scalarRow(101)['datefield'] === null,
    'A honors a fresh status override relaxing the base mandatory flag.');

// Simulate an outstanding pre-upgrade task and context exactly as v1 stored them.
[$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_b', 'old', 'legacy inbound');
AssetSyncQueue::notify('Computer', 101, 'b');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
foreach (['inspect', 'apply'] as $kind) {
    $saved = AgentTask::outstanding('b');
    $task = json_decode($saved['body'], true);
    $task['version'] = 1;
    $task['computer_id'] = $task['items_id'];
    unset($task['items_id'], $task['itemtype'], $task['pair_id']);
    foreach ($task['changes'] as &$change) { unset($change['type']); }
    unset($change);
    $task = scalarHash($task);
    $context = json_decode($saved['context'], true); unset($context['types']);
    $body = json_encode($task, JSON_THROW_ON_ERROR);
    $DB->update(AgentTask::TABLE, ['body' => $body, 'hash' => $task['hash'], 'context' => json_encode($context)], ['id' => $task['id']]);
    $pulled = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarCheck($pulled === $task, 'A serves immutable pre-upgrade v1 ' . $kind);
    $result = TaskExecutor::execute($pulled, $settings);
    scalarReceipt($request, $token, $task, $result);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    scalarReceipt($request, $token, $task, $result);
    $after = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
    scalarCheck($after['body'] === $body && $after['hash'] === $task['hash'] && json_decode($after['result'], true) === $result,
        'V1 completion and duplicate receipt preserve original body/hash/result: ' . $kind);
}
scalarCheck(AgentTask::outstanding('b') === null && scalarRow(101)['textfield'] === 'legacy inbound', 'Old v1 context finishes inbound text after upgrade.');

// Render the real mapping page with the same bootstrap substitution as fieldmapping-ui.php.
scalarControllerSetup('textfield', 'glpi_a', 'old', 'new');
Search::$options[104] = array_replace(Search::$options[102], ['field' => 'numberfield', 'name' => 'Number', 'pfields_type' => 'number']);
function scalarMappingPage(): string
{
    $_GET = ['connection_id' => 'b', 'itemtype' => 'Computer'];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/plugins/assetsync20/front/fieldmapping.php';
    $source = file_get_contents(__DIR__ . '/../front/fieldmapping.php');
    $source = str_replace("include GLPI_ROOT . '/inc/includes.php';", '', $source);
    $source = str_replace('__DIR__', var_export(dirname(__DIR__) . '/front', true), $source);
    ob_start();
    try { eval(substr($source, strlen('<?php'))); return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
$configBefore = Config::$values;
$html = scalarMappingPage();
$document = new DOMDocument();
$previousErrors = libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
libxml_use_internal_errors($previousErrors);
$xpath = new DOMXPath($document);
foreach (['glpi_a_field_key', 'glpi_b_field_key'] as $side) {
    $options = $xpath->query('//select[@name="field_mappings[0][' . $side . ']"]/optgroup/option');
    $keys = [];
    foreach ($options as $option) { $keys[] = $option->getAttribute('value'); }
    scalarCheck(in_array(scalarKey('yesfield'), $keys, true) && in_array(scalarKey('datefield'), $keys, true)
        && !in_array(scalarKey('numberfield'), $keys, true), 'Mapping selector offers Yes/No and Date but not Number: ' . $side);
}
scalarCheck(!str_contains($html, '<option value="both"') && Config::$values === $configBefore, 'Agent page keeps one-way authorities and read-only rendering.');

echo "Agent scalar tests passed ({$checks} checks; host model doubles and SQLite, not live GLPI/InnoDB).\n";
