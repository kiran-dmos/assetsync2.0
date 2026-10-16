<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-multi.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;

function autoSetup(string $authority = 'glpi_a', string $column = 'textfield', string $type = 'Computer'): array
{
    global $DB;
    $GLOBALS['autoType'] = $type;
    [$aValue, $bValue] = match ($column) { 'yesfield' => [1, 0], 'datefield' => ['2030-01-02', null], default => ['A value', 'B value'] };
    [$settings, $request, $token] = scalarControllerSetup($column, $authority, $aValue, $bValue);
    $request['task_versions'] = [1, 2, 3, 4];
    $catalog = AgentTask::catalog('b', 'Computer')['fields'];
    // Separate A/B entities in this host database model two independent inventories.
    $DB->doQuery('ALTER TABLE glpi_computers RENAME TO auto_original');
    $DB->doQuery('CREATE TABLE glpi_computers (id INTEGER PRIMARY KEY, entities_id INTEGER, name TEXT, comment TEXT,
        serial TEXT COLLATE NOCASE, states_id INTEGER DEFAULT 0, is_deleted INTEGER DEFAULT 0, is_template INTEGER DEFAULT 0, otherserial TEXT COLLATE NOCASE)');
    $DB->doQuery('INSERT INTO glpi_computers (id,entities_id,name,comment,serial,states_id,is_deleted,is_template) SELECT * FROM auto_original');
    $DB->doQuery('DROP TABLE auto_original');
    $DB->update('glpi_computers', ['serial' => null, 'otherserial' => ' 001-Inv.X '], ['id' => 101]);
    $DB->update('glpi_computers', ['serial' => '', 'otherserial' => '001-Inv.X', 'entities_id' => 200], ['id' => 201]);
    $container = $DB->firstRow('glpi_plugin_fields_containers', ['id' => 1]);
    $DB->insert('glpi_plugin_fields_containers', array_replace($container, ['id' => 2, 'entities_id' => 200]));
    foreach (iterator_to_array($DB->request(['FROM' => 'glpi_plugin_fields_fields'])) as $field) {
        $DB->insert('glpi_plugin_fields_fields', array_replace($field, ['id' => $field['id'] + 10, 'plugin_fields_containers_id' => 2]));
    }
    $DB->update(PluginFieldsComputerscalar::getTable(), ['plugin_fields_containers_id' => 2], ['items_id' => 201]);
    foreach ([AgentTask::PAIRS, AgentTask::TABLE, AssetSyncLink::TABLE, AssetSyncQueue::TABLE] as $table) { $DB->doQuery('DELETE FROM `' . $table . '`'); }
    $DB->update(AgentInbox::REGISTRATIONS, ['routes' => '{}'], ['id' => $settings['registration']]);
    EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['glpi_b_target_entity_id' => '200']));
    if ($type === 'Printer') {
        $DB->doQuery('CREATE TABLE glpi_printers AS SELECT * FROM glpi_computers');
        $DB->doQuery('CREATE TABLE glpi_plugin_fields_printerscalars AS SELECT * FROM glpi_plugin_fields_computerscalars');
        $DB->doQuery("UPDATE glpi_plugin_fields_printerscalars SET itemtype = 'Printer'");
        $DB->doQuery('UPDATE glpi_plugin_fields_containers SET itemtypes = \'["Printer"]\'');
        Search::$typedOptions['Printer'] = Search::$options;
        foreach (Search::$typedOptions['Printer'] as &$option) {
            $option['table'] = str_replace('computer', 'printer', $option['table']);
            $option['uid'] = str_replace('Computer', 'Printer', $option['uid']);
        }
        unset($option);
        foreach ($catalog as &$field) {
            foreach (['key', 'uid'] as $key) { $field[$key] = str_replace(['Computer', 'Computerscalar'], ['Printer', 'Printerscalar'], $field[$key]); }
        }
        unset($field);
        $key = 'Printer.PluginFieldsPrinterscalar.' . $column;
        \GlpiPlugin\Assetsync20\FieldMapping::save('b', 'Printer', [['glpi_a_field_key' => $key, 'glpi_b_field_key' => $key, 'source_of_truth' => $authority]], $catalog);
        EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['asset_types' => ['Printer']]));
    }
    TaskJournal::install();
    return [$settings, $request, $token];
}

function autoStart(): array
{
    scalarCheck(AgentTask::queueMatch('b', $GLOBALS['autoType'], 101), 'Scanner queues an eligible unpaired asset.');
    $job = AssetSyncQueue::claimDue(1)[0];
    AgentTask::process($job, 'autoApplyLocal');
    return $job;
}

function autoApplyLocal(array $changes): bool
{
    scalarCheck(\GlpiPlugin\Assetsync20\FieldsText::updateLocal($GLOBALS['autoType'], 101, $changes, true), 'Typed A model writer completes.');
    return false;
}

function autoStep(array $settings, array $request, \GlpiPlugin\Assetsync20\AgentApi $token, string $kind): array
{
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarCheck(is_array($task) && $task['version'] === 4 && $task['kind'] === $kind, 'Expected v4 phase: ' . $kind);
    $result = TaskJournal::run($task, $settings);
    scalarCheck(in_array($result['outcome'], ['unchanged', 'verified'], true), 'B completed ' . $kind);
    scalarReceipt($request, $token, $task, $result);
    scalarReceipt($request, $token, $task, $result);
    scalarCheck(TaskJournal::run($task, $settings) === $result, 'Lost ACK replays immutable evidence: ' . $kind);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    return [$task, $result];
}

foreach (['0', '0001', ' a  b ', 'ABC-1.2/3', 'abc', '\\literal'] as $value) {
    $row = ['serial' => $value, 'otherserial' => null];
    scalarCheck(AgentTask::identity($row) === TaskExecutor::identity($row)
        && AgentTask::identity($row)['serial'] === trim($value, ' '), 'Exact v4 normalization: ' . $value);
}
foreach ([[], ['serial' => null], ['serial' => "x\t", 'otherserial' => ''], ['serial' => str_repeat('x', 256), 'otherserial' => ''],
    ['serial' => 0, 'otherserial' => '']] as $row) {
    scalarReject(static fn () => AgentTask::identity($row), 'A rejects unknown or invalid native identity.');
    scalarReject(static fn () => TaskExecutor::identity($row), 'B rejects unknown or invalid native identity.');
}

foreach (['Computer', 'Printer'] as $assetType) {
foreach (['glpi_a', 'glpi_b'] as $authority) {
    [$settings, $request, $token] = autoSetup($authority, 'textfield', $assetType);
    autoStart();
    autoStep($settings, $request, $token, 'match');
    scalarCheck(AgentTask::pair('b', $assetType) === null, 'First evidence never confirms a pair.');
    autoStep($settings, $request, $token, 'reverify');
    $pair = AgentTask::pair('b', $assetType);
    $basis = json_decode($pair['identity_basis'], true);
    scalarCheck($pair['confirmed'] === 1 && $basis['method'] === 'otherserial' && $basis['a']['serial'] === ''
        && $basis['b']['serial'] === '', 'Fresh auto inventory pair retains separate blank serial guards.');
    scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'pending', 'Matched is pending synchronization, not synchronized.');
    $matchedQueue = $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101]);
    scalarCheck($matchedQueue['route_id'] === 'route' && $matchedQueue['remote_items_id'] === 201,
        'Pair confirmation atomically retains queue route and remote ID.');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    autoStep($settings, $request, $token, 'inspect');
    if ($authority === 'glpi_a') { autoStep($settings, $request, $token, 'apply'); }
    else { scalarCheck(AgentTask::outstanding('b', $pair) === null, 'B authority finishes from a fresh inspect without a B mutation.'); }
    $expected = $authority === 'glpi_a' ? 'A value' : 'B value';
    $table = $assetType === 'Computer' ? PluginFieldsComputerscalar::getTable() : PluginFieldsPrinterscalar::getTable();
    scalarCheck($DB->firstRow($table, ['items_id' => 101])['textfield'] === $expected && $DB->firstRow($table, ['items_id' => 201])['textfield'] === $expected,
        'Blank-serial inventory pair completes ordinary mapped update ' . $assetType . ' ' . $authority);
    scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'done', 'Synchronization completes only after apply/readback.');
    $completedQueue = $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101]);
    $completedLink = AssetSyncLink::find($assetType, 101, 'b');
    scalarCheck($completedLink['status'] === AssetSyncLink::STATUS_SYNCED && $completedLink['last_sync_at'] !== null
        && $completedLink['last_payload_hash'] !== '' && $completedLink['last_payload_hash'] === $completedQueue['payload_hash'],
        'Verified typed completion stores synced link, time and matching queue hash.');
    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    AssetSyncQueue::notify($assetType, 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    autoStep($settings, $request, $token, 'inspect');
    scalarCheck(AgentTask::outstanding('b', $pair) === null, 'Equal reinspection publishes no B apply.');
    scalarCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Repeated synchronization is a real no-op.');
    $DB->update($assetType === 'Computer' ? 'glpi_computers' : 'glpi_printers', ['otherserial' => 'changed'], ['id' => 101]);
    scalarReject(static fn () => AgentTask::scope(AgentTask::pair('b', $assetType)), 'A inventory guard remains enforced after confirmation.');
}
}

// Pure decision rules plus actual SQL execution cover exactness independently of discovery pages.
$identity = ['serial' => '0', 'otherserial' => '0007'];
$candidate = ['items_id' => 201, 'itemtype' => 'Computer', 'entity_id' => 200, 'serial' => '0', 'otherserial' => '0007'];
$evidence = ['complete' => true, 'observed_at' => time(), 'lookup_complete' => ['serial' => true, 'otherserial' => true], 'serial' => [$candidate], 'otherserial' => []];
scalarCheck(AgentTask::chooseMatch($identity, $evidence)['method'] === 'serial', 'Zero is a unique serial, not blank.');
$duplicate = array_replace($candidate, ['items_id' => 999]);
scalarCheck(!isset(AgentTask::chooseMatch($identity, array_replace($evidence, ['serial' => [$candidate, $duplicate], 'otherserial' => [$candidate]]))['candidate']), 'Duplicate serial cannot fall back to unique inventory.');
scalarCheck(AgentTask::chooseMatch($identity, array_replace($evidence, ['otherserial' => [$candidate, $duplicate]]))['method'] === 'serial', 'Unique serial has precedence over inventory duplicates.');
scalarCheck(!isset(AgentTask::chooseMatch($identity, array_replace($evidence, ['serial' => [], 'otherserial' => [$candidate, $duplicate]]))['candidate']), 'Duplicate fallback inventory blocks.');
scalarCheck(!isset(AgentTask::chooseMatch($identity, array_replace($evidence, ['serial' => [], 'otherserial' => [array_replace($candidate, ['serial' => 'other'])]]))['candidate']), 'Both nonblank contradictory serials block inventory fallback.');
scalarReject(static fn () => AgentTask::chooseMatch($identity, array_replace($evidence, ['complete' => false])), 'Incomplete evidence cannot mean zero matches.');

foreach (['0', '0007', 'ABC-1.2/3', 'Case', 'a  b'] as $serial) {
    [$settings, $request, $token] = autoSetup();
    $DB->update('glpi_computers', ['serial' => $serial], ['id' => 101]);
    $DB->update('glpi_computers', ['serial' => ' ' . $serial . ' '], ['id' => 201]);
    foreach ([strtolower($serial), strtoupper($serial), $serial . '.'] as $index => $near) {
        if ($near === $serial) { $near .= 'x'; }
        $DB->insert('glpi_computers', ['id' => 10 + $index, 'entities_id' => 200, 'serial' => $near, 'otherserial' => 'unrelated']);
    }
    autoStart();
    [$task, $result] = autoStep($settings, $request, $token, 'match');
    scalarCheck(count($result['evidence']['serial']) === 1 && $result['evidence']['serial'][0]['items_id'] === 201,
        'Binary SQL predicate excludes earlier collation-only rows before LIMIT: ' . $serial);
    autoStep($settings, $request, $token, 'reverify');
    scalarCheck(json_decode(AgentTask::pair('b')['identity_basis'], true)['method'] === 'serial', 'Exact serial confirmed.');
}

[$settings, $request, $token] = autoSetup();
$DB->update('glpi_computers', ['serial' => 'A serial'], ['id' => 101]);
autoStart();
autoStep($settings, $request, $token, 'match');
autoStep($settings, $request, $token, 'reverify');
scalarCheck(json_decode(AgentTask::pair('b')['identity_basis'], true)['a']['serial'] === 'A serial', 'Proven-zero serial lookup permits blank B serial inventory fallback.');

foreach (['local_duplicate', 'remote_duplicate', 'no_match', 'permission', 'grant', 'failed_read', 'changed_route', 'changed_a', 'changed_b',
    'cross_entity', 'deleted', 'existing_link', 'reverse_link', 'retained_approval', 'missing_column'] as $scenario) {
    [$settings, $request, $token] = autoSetup();
    autoStart();
    if ($scenario === 'local_duplicate') {
        $row = $DB->firstRow('glpi_computers', ['id' => 101]);
        $DB->insert('glpi_computers', array_replace($row, ['id' => 9999]));
    } elseif ($scenario === 'remote_duplicate') {
        for ($n = 1; $n <= 60; $n++) {
            $DB->insert('glpi_computers', ['id' => 300 + $n, 'entities_id' => 200, 'serial' => '', 'otherserial' => 'other-' . $n]);
        }
        $row = $DB->firstRow('glpi_computers', ['id' => 201]);
        $DB->insert('glpi_computers', array_replace($row, ['id' => 9999]));
    } elseif ($scenario === 'no_match') { $DB->update('glpi_computers', ['otherserial' => 'other'], ['id' => 201]);
    } elseif ($scenario === 'permission') { Profile::$rights = READ;
    } elseif ($scenario === 'grant') { Profile_User::$granted = false;
    } elseif ($scenario === 'failed_read') { $DB->sql->failOn = 'CAST(TRIM(';
    } elseif ($scenario === 'changed_route') { EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['glpi_b_target_entity_id' => '201']));
    } elseif ($scenario === 'changed_a') { $DB->update('glpi_computers', ['otherserial' => 'other'], ['id' => 101]);
    } elseif ($scenario === 'cross_entity') { $DB->update('glpi_computers', ['entities_id' => 201], ['id' => 201]);
    } elseif ($scenario === 'deleted') { $DB->update('glpi_computers', ['is_deleted' => 1], ['id' => 201]);
    } elseif ($scenario === 'existing_link' || $scenario === 'reverse_link') {
        AssetSyncLink::saveStatus('Computer', $scenario === 'existing_link' ? 101 : 999, 'b', 'route', AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'retained', 201);
    } elseif ($scenario === 'retained_approval') {
        $DB->update(AgentInbox::REGISTRATIONS, ['routes' => json_encode(['route' => ['fingerprint' => 'old', 'source_entity' => 100]])], ['id' => $settings['registration']]);
    } elseif ($scenario === 'missing_column') { $DB->doQuery('ALTER TABLE glpi_computers DROP COLUMN otherserial'); }
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    if ($task) {
        $result = TaskExecutor::execute($task, $settings);
        scalarReceipt($request, $token, $task, $result);
    }
    $DB->sql->failOn = null;
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    $next = AgentTask::matchTask('b', 'Computer', 101);
    if ($next) {
        if (json_decode($next['body'], true)['version'] === 5) {
            scalarCheck(in_array($scenario, ['no_match', 'cross_entity', 'deleted'], true), 'Only complete scoped no-match starts creation prerequisites.');
            scalarCheck(AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'] === null, 'V4 agent waits for upgrade before creation.');
            scalarCheck(AgentTask::pair('b') === null && Computer::$adds === 0, 'No pair or add during upgrade wait.');
            continue;
        }
        if ($scenario === 'changed_b') { $DB->update('glpi_computers', ['serial' => 'new serial'], ['id' => 201]); }
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarReceipt($request, $token, $task, TaskExecutor::execute($task, $settings));
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    }
    scalarCheck(AgentTask::pair('b') === null, 'No pair on ' . $scenario);
    scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'retry', 'Existing backoff on ' . $scenario);
    scalarCheck(AssetSyncQueue::claimDue(1) === [], 'No immediate retry loop on ' . $scenario);
}

[$settings, $request, $token] = autoSetup();
autoStart();
$task = AgentTask::matchTask('b', 'Computer', 101);
$DB->update(AgentTask::TABLE, ['expires' => time() - 1, 'state' => 'executing'], ['id' => $task['id']]);
AgentTask::queueMatch('b', 'Computer', 101);
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
scalarCheck(AgentTask::matchTask('b', 'Computer', 101) === null && AgentTask::pair('b') === null, 'Expired read-only match releases only its own slot.');
scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $task['id']])['body'] === $task['body'], 'Expiry preserves immutable task bytes.');

// Freshness is checked again after B reverify, immediately before atomic confirmation.
foreach (['identity', 'route', 'entity', 'duplicate', 'binding', 'claim', 'storage'] as $change) {
    [$settings, $request, $token] = autoSetup();
    autoStart();
    autoStep($settings, $request, $token, 'match');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    $result = TaskJournal::run($task, $settings);
    scalarReceipt($request, $token, $task, $result);
    $job = AssetSyncQueue::claimDue(1)[0];
    if ($change === 'identity') { $DB->update('glpi_computers', ['serial' => 'changed'], ['id' => 101]); }
    if ($change === 'route') { EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => false])); }
    if ($change === 'entity') { $DB->update('glpi_computers', ['entities_id' => 200], ['id' => 101]); }
    if ($change === 'duplicate') { $DB->insert('glpi_computers', array_replace($DB->firstRow('glpi_computers', ['id' => 101]), ['id' => 555])); }
    if ($change === 'binding') { $DB->update(AgentTask::BINDINGS, ['installation' => str_repeat('e', 32)], ['connection_id' => 'b']); }
    if ($change === 'claim') { $DB->update(AssetSyncQueue::TABLE, ['claim_token' => 'other'], ['id' => $job['id']]); }
    if ($change === 'storage') { $DB->sql->failOn = 'INSERT INTO `' . AssetSyncLink::TABLE . '`'; }
    AgentTask::process($job, 'autoApplyLocal');
    $DB->sql->failOn = null;
    scalarCheck(AgentTask::pair('b') === null && AssetSyncLink::find('Computer', 101, 'b') === null, 'No partial confirmation after fresh ' . $change . ' change.');
    if ($change === 'claim') {
        scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['id' => $job['id']])['claim_token'] === 'other'
            && AgentTask::matchTask('b', 'Computer', 101)['id'] === $task['id'], 'Lost queue claim leaves current owner and match slot intact.');
    }
}

[$settings, $request, $token] = autoSetup('glpi_a', 'textfield', 'Printer');
$DB->insert('glpi_printers', array_replace($DB->firstRow('glpi_printers', ['id' => 201]), ['id' => 9999]));
Printer::$unreadable = [9999];
autoStart();
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$result = TaskExecutor::execute($task, $settings);
scalarCheck($result['outcome'] === 'unchanged' && !$result['evidence']['lookup_complete']['otherserial'], 'Denied exact duplicate invalidates evidence rather than disappearing.');
scalarReceipt($request, $token, $task, $result);
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
scalarCheck(AgentTask::pair('b', 'Printer') === null, 'Permission filtering cannot manufacture a unique pair.');
Printer::$unreadable = [];

// A's bearer endpoint has no interactive A login. Authority is the binding and exact route.
[$settings, $request, $token] = autoSetup();
autoStart();
$_SESSION = [];
Computer::$allowRead = false;
Session::$entityAccess = false;
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
scalarCheck(is_array($task) && $task['kind'] === 'match' && $_SESSION === [], 'Sessionless authenticated pull does not fabricate or require an A session.');
Computer::$allowRead = true;
Session::$entityAccess = true;

// A unique serial does not need inventory evidence. Errors stay separate, never become zero.
foreach (['sql', 'denied_duplicate'] as $failure) {
    [$settings, $request, $token] = autoSetup('glpi_a', 'textfield', 'Printer');
    $DB->update('glpi_printers', ['serial' => 'unique-serial'], ['id' => 101]);
    $DB->update('glpi_printers', ['serial' => 'unique-serial'], ['id' => 201]);
    if ($failure === 'denied_duplicate') {
        $DB->insert('glpi_printers', array_replace($DB->firstRow('glpi_printers', ['id' => 201]), ['id' => 9999, 'serial' => 'different-serial']));
        Printer::$unreadable = [9999];
    }
    autoStart();
    foreach (['match', 'reverify'] as $phase) {
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarCheck($task['kind'] === $phase, 'Unique serial phase proceeds despite irrelevant inventory failure.');
        if ($failure === 'sql') { $DB->sql->failOn = 'TRIM(`otherserial`)'; }
        $result = TaskExecutor::execute($task, $settings);
        $DB->sql->failOn = null;
        scalarCheck($result['evidence']['lookup_complete'] === ['serial' => true, 'otherserial' => false]
            && count($result['evidence']['serial']) === 1, 'Separate completeness preserves unique serial and inventory error.');
        scalarReceipt($request, $token, $task, $result);
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    }
    scalarCheck(json_decode(AgentTask::pair('b', 'Printer')['identity_basis'], true)['method'] === 'serial', 'A serial priority survives ' . $failure);
    Printer::$unreadable = [];
}

[$settings, $request, $token] = autoSetup();
autoStart();
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$DB->update('glpi_computers', ['otherserial' => 'not-a-match'], ['id' => 201]);
$DB->doQuery("WITH RECURSIVE numbers(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM numbers WHERE n<10001)
    INSERT INTO glpi_computers (id,entities_id,serial,otherserial) SELECT n+1000,200,'','unrelated' FROM numbers");
$queries = [];
$DB->sql->beforeQuery = static function (string $sql) use (&$queries): void { if (str_contains($sql, 'MAX_EXECUTION_TIME')) { $queries[] = $sql; } };
$result = TaskExecutor::execute($task, $settings);
$DB->sql->beforeQuery = null;
scalarCheck($result['evidence']['lookup_complete']['otherserial'] === true && $result['evidence']['otherserial'] === [], 'Large inventory no-match can be complete; no global row cap.');
scalarCheck(count($queries) === 1 && str_contains($queries[0], 'MAX_EXECUTION_TIME(1000)')
    && str_contains($queries[0], '`entities_id` = 200') && str_ends_with($queries[0], 'LIMIT 2')
    && !str_contains($queries[0], 'FOR UPDATE'), 'Actual scoped exact lookup has database deadline and no inventory lock.');
scalarCheck(AgentTask::matchContext('b', 'Computer', 101)['local_counts']['otherserial'] === 1, 'Unrelated entities above 10000 rows do not block A uniqueness.');
$DB->sql->failOn = 'MAX_EXECUTION_TIME';
$timedOut = TaskExecutor::execute($task, $settings);
scalarCheck(!$timedOut['evidence']['lookup_complete']['otherserial'] && $timedOut['evidence']['otherserial'] === [], 'Simulated DB deadline error remains incomplete, never a zero match.');
scalarReject(static fn () => AgentTask::matchContext('b', 'Computer', 101), 'A database timeout fails closed.');
$DB->sql->failOn = null;
foreach (['10.11.6-MariaDB', '5.5.5-10.6.23-MariaDB'] as $version) {
    $DB->sql->databaseVersion = $version;
    $queries = [];
    $DB->sql->beforeQuery = static function (string $sql) use (&$queries): void { if (str_starts_with($sql, 'SET STATEMENT')) { $queries[] = $sql; } };
    $result = TaskExecutor::execute($task, $settings);
    scalarCheck($result['evidence']['lookup_complete']['otherserial'] && count($queries) === 1
        && str_starts_with($queries[0], 'SET STATEMENT max_statement_time=1.000 FOR SELECT'), 'MariaDB uses enforced per-statement timeout: ' . $version);
    $DB->sql->beforeQuery = null;
}
$DB->sql->databaseVersion = '5.7.44';
$result = TaskExecutor::execute($task, $settings);
scalarCheck(!$result['evidence']['lookup_complete']['otherserial'] && $result['evidence']['lookup_errors']['otherserial'] === 'Unsupported database statement timeout.', 'Unsupported B database fails explicitly rather than ignoring a timeout hint.');
scalarReject(static fn () => AgentTask::matchContext('b', 'Computer', 101), 'Unsupported A database fails explicitly.');

foreach (['blank', 'control', 'missing'] as $invalid) {
    [$settings, $request, $token] = autoSetup();
    if ($invalid === 'blank') { $DB->update('glpi_computers', ['serial' => null, 'otherserial' => ' '], ['id' => 101]); }
    if ($invalid === 'control') { $DB->update('glpi_computers', ['serial' => "bad\t"], ['id' => 101]); }
    if ($invalid === 'missing') { $DB->doQuery('ALTER TABLE glpi_computers DROP COLUMN otherserial'); }
    $service = new \GlpiPlugin\Assetsync20\AssetSyncService();
    scalarCheck($service->enqueueBackfill(10) === 1, 'Actual scanner enrolls in-scope invalid identity: ' . $invalid);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    $queue = $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101]);
    scalarCheck($queue['status'] === 'retry' && str_starts_with($queue['last_error'], 'Verification failed:')
        && !str_contains($queue['last_error'], 'No exact') && AgentTask::matchTask('b', 'Computer', 101) === null,
        'Invalid identity is a visible verification failure with backoff, never no match: ' . $invalid);
}

[$settings, $request, $token] = autoSetup();
autoStart();
$oldRequest = $request; unset($oldRequest['task_versions']);
$before = AgentTask::matchTask('b', 'Computer', 101);
scalarCheck(AgentTask::exchange($oldRequest + ['kind' => 'pull'], $token)['task'] === null, 'Old B cannot receive a v4 task without advertised capability.');
scalarCheck(AgentTask::matchTask('b', 'Computer', 101) === $before
    && str_contains($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['last_error'], 'upgrade B'), 'Upgrade requirement is visible and does not rewrite task bytes/state.');
scalarCheck(AgentTask::exchange($request + ['kind' => 'pull'], $token)['task']['id'] === $before['id'], 'Upgraded B receives the same immutable task.');

foreach (['yesfield', 'datefield'] as $column) {
    foreach (['glpi_a', 'glpi_b'] as $authority) {
        [$settings, $request, $token] = autoSetup($authority, $column);
        autoStart();
        autoStep($settings, $request, $token, 'match');
        autoStep($settings, $request, $token, 'reverify');
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
        autoStep($settings, $request, $token, 'inspect');
        if ($authority === 'glpi_a') { autoStep($settings, $request, $token, 'apply'); }
        else { scalarCheck(AgentTask::outstanding('b') === null, 'Typed B-authority readback needs no B apply.'); }
        $expected = $column === 'yesfield' ? ($authority === 'glpi_a' ? 1 : 0) : ($authority === 'glpi_a' ? '2030-01-02' : '');
        $fieldType = $column === 'yesfield' ? 'yesno' : 'date';
        scalarCheck(AgentTask::normalizeValue($fieldType, scalarRow(101)[$column]) === $expected
            && AgentTask::normalizeValue($fieldType, scalarRow(201)[$column]) === $expected, 'Automatic fallback pair verifies typed ' . $column . ' ' . $authority);
    }
}

foreach (['serial', 'otherserial'] as $changedColumn) {
    [$settings, $request, $token] = autoSetup();
    autoStart();
    autoStep($settings, $request, $token, 'match');
    autoStep($settings, $request, $token, 'reverify');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    autoStep($settings, $request, $token, 'inspect');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    $DB->update('glpi_computers', [$changedColumn => 'changed-after-inspect'], ['id' => 201]);
    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    $result = TaskExecutor::execute($task, $settings);
    scalarCheck($result['outcome'] === 'stale' && $result['changed'] === [] && scalarRow(201)['textfield'] === 'B value'
        && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Normal v4 apply blocks changed B ' . $changedColumn . ' without writes.');
}

ob_end_clean();
echo "Agent automatic matching tests passed ($checks checks including prerequisites; host doubles/SQLite, not live MySQL).\n";
