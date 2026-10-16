<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-auto-match.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;
use GlpiPlugin\Assetsync20agent\Creation;

function createSetup(string $authority = 'glpi_b', string $column = 'textfield', string $type = 'Computer'): array
{
    global $DB;
    [$settings, $request, $token] = autoSetup($authority, $column, $type);
    $request['task_versions'][] = 5;
    Computer::$allowCreate = true;
    Profile::$rights |= CREATE;
    $table = $type === 'Computer' ? 'glpi_computers' : 'glpi_printers';
    $child = $type === 'Computer' ? PluginFieldsComputerscalar::getTable() : PluginFieldsPrinterscalar::getTable();
    $DB->doQuery('DELETE FROM ' . $table . ' WHERE id=201');
    $DB->doQuery('DELETE FROM ' . $child . ' WHERE items_id=201');
    $DB->doQuery("ALTER TABLE glpi_plugin_fields_containers ADD COLUMN type TEXT DEFAULT 'tab'");
    return [$settings, $request, $token];
}

function createStep(array $settings, array $request, \GlpiPlugin\Assetsync20\AgentApi $token, string $kind): array
{
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarCheck(is_array($task) && $task['version'] === 5 && $task['kind'] === $kind, 'Expected creation phase ' . $kind);
    $result = TaskJournal::run($task, $settings);
    scalarCheck(in_array($result['outcome'], ['verified', 'unchanged'], true), 'Completed creation phase ' . $kind . ': ' . json_encode($result));
    scalarReceipt($request, $token, $task, $result);
    scalarReceipt($request, $token, $task, $result);
    scalarCheck(TaskJournal::run($task, $settings) === $result, 'Creation result replay is immutable.');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    return [$task, $result];
}

foreach (['unchanged', 'edited'] as $sourceState) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match');
    $options = Search::$options;
    Search::$options = array_filter($options, static fn ($option) => $option['table'] === 'glpi_computers');
    if ($sourceState === 'edited') {
        $DB->update(PluginFieldsComputerscalar::getTable(), ['textfield' => 'Fresh A edit'], ['items_id' => 101]);
    }
    $_SESSION = [];
    $response = AgentTask::exchange($request + ['kind' => 'pull'], $token);
    scalarCheck($sourceState === 'unchanged' ? ($response['task']['kind'] ?? '') === 'prepare_create' : $response['task'] === null,
        'Sessionless pull checks persisted source without an interactive Fields catalog: ' . $sourceState);
    Search::$options = $options;
}

foreach (['Computer', 'Printer'] as $type) {
    foreach (['glpi_a', 'glpi_b'] as $authority) {
        foreach (['textfield', 'yesfield', 'datefield'] as $column) {
            [$settings, $request, $token] = createSetup($authority, $column, $type);
            autoStart();
            autoStep($settings, $request, $token, 'match');
            createStep($settings, $request, $token, 'prepare_create');
            [$task, $result] = createStep($settings, $request, $token, 'create');
            $id = $result['created_id'];
            scalarCheck(AgentTask::pair('b', $type) === null && AssetSyncLink::find($type, 101, 'b') === null,
                'Creation result alone does not confirm a pair or link.');
            scalarCheck(Computer::$adds === 1, 'Exactly one normal parent add.');
            createStep($settings, $request, $token, 'confirm_create');
            $pair = AgentTask::pair('b', $type);
            scalarCheck((int) $pair['computer_id'] === $id, 'Fresh independent confirmation retains the created B ID.');
            AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
            autoStep($settings, $request, $token, 'inspect');
            scalarCheck(AgentTask::outstanding('b', $pair) === null, 'Initialized values finish with fresh inspection and no B apply.');
            scalarCheck(Computer::$adds === 1, 'Normal synchronization does not recreate.');
            $child = $type === 'Computer' ? PluginFieldsComputerscalar::getTable() : PluginFieldsPrinterscalar::getTable();
            $a = $DB->firstRow($child, ['items_id' => 101])[$column];
            $b = $DB->firstRow($child, ['items_id' => $id])[$column];
            scalarCheck($a === $b, 'One-time initialization copied A even for B authority: ' . $type . ' ' . $authority . ' ' . $column);
            if ($authority === 'glpi_b') {
                $later = match ($column) { 'yesfield' => 0, 'datefield' => '2031-03-04', default => 'Later B value' };
                $DB->update($child, [$column => $later], ['items_id' => $id]);
                AssetSyncQueue::notify($type, 101, 'b');
                AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
                autoStep($settings, $request, $token, 'inspect');
                scalarCheck(AgentTask::outstanding('b', $pair) === null, 'Later B authority requires no B mutation.');
                scalarCheck($DB->firstRow($child, ['items_id' => 101])[$column] === $later, 'B authority applies immediately after initialization.');
            }
        }
    }
}

foreach ([false, true] as $rejectIsolation) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match');
    createStep($settings, $request, $token, 'prepare_create');
    createStep($settings, $request, $token, 'create');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
    $queries = [];
    $DB->sql->beforeQuery = static function (string $sql) use (&$queries): void { $queries[] = $sql; };
    if ($rejectIsolation) { $DB->sql->failOn = 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'; }
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    $DB->sql->beforeQuery = null;
    $DB->sql->failOn = null;
    $position = array_search('SET TRANSACTION ISOLATION LEVEL READ COMMITTED', $queries, true);
    scalarCheck($position !== false, 'Creation confirmation requests transaction-local fresh reads.');
    scalarCheck($rejectIsolation ? AgentTask::pair('b') === null
        : ($queries[$position + 1] === 'START TRANSACTION' && AgentTask::pair('b') !== null),
        'Confirmation fails closed when fresh-read isolation cannot be established; no session default change.');
}

foreach (['create_right', 'container_right', 'mandatory', 'unsupported_required', 'missing_metadata', 'invalid_date', 'ambiguous_metadata'] as $failure) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    if ($failure === 'create_right') { Profile::$rights = READ | UPDATE; }
    if ($failure === 'container_right') { PluginFieldsProfile::$right = READ; }
    if ($failure === 'mandatory') { $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 13]); }
    if ($failure === 'unsupported_required') { $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1, 'type' => 'dropdown'], ['id' => 13]); }
    if ($failure === 'missing_metadata') { $DB->doQuery('ALTER TABLE glpi_plugin_fields_fields DROP COLUMN mandatory'); }
    if ($failure === 'invalid_date') {
        $DB->doQuery('ALTER TABLE glpi_plugin_fields_fields ADD COLUMN default_value TEXT');
        $DB->update('glpi_plugin_fields_fields', ['default_value' => '2030-02-30'], ['id' => 13]);
    }
    if ($failure === 'ambiguous_metadata') { Search::$options[999] = Search::$options[101]; }
    $result = TaskJournal::run($task, $settings);
    scalarCheck($result['outcome'] === 'blocked' && Computer::$adds === 0, 'Precreation fails closed on ' . $failure);
}

// A delivered create is never replaced on expiry, but its late durable result is accepted.
[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$result = TaskJournal::run($task, $settings);
$DB->update(AgentTask::TABLE, ['expires' => time() - 1], ['id' => $task['id']]);
scalarCheck(AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'] === null, 'Expired delivered create is not sent again.');
$retained = AgentTask::matchTask('b', 'Computer', 101);
scalarCheck($retained['state'] === 'uncertain' && $retained['result'] === '{}' && $retained['body'] === json_encode($task),
    'Expiry retains immutable creation intent without synthesizing conflicting result.');
scalarReceipt($request, $token, $task, $result);
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
createStep($settings, $request, $token, 'confirm_create');
scalarCheck(Computer::$adds === 1 && (int) AgentTask::pair('b')['computer_id'] === $result['created_id'], 'Late result recovers ID without duplicate add.');

foreach (['after_create', 'before_delivery', 'before_confirmation'] as $when) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
    if ($when === 'before_delivery') {
        $DB->update(PluginFieldsComputerscalar::getTable(), ['textfield' => 'changed A'], ['items_id' => 101]);
        scalarCheck(AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'] === null && Computer::$adds === 0, 'Changed source stops create delivery.');
        continue;
    }
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    $result = TaskJournal::run($task, $settings);
    scalarReceipt($request, $token, $task, $result);
    if ($when === 'before_confirmation') {
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
        $confirm = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarReceipt($request, $token, $confirm, TaskJournal::run($confirm, $settings));
    }
    $DB->update(PluginFieldsComputerscalar::getTable(), ['textfield' => 'changed A'], ['items_id' => 101]);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    scalarCheck(AgentTask::pair('b') === null && Computer::$adds === 1 && AgentTask::matchTask('b', 'Computer', 101) !== null,
        'Changed source retains created identity without confirming, reseeding or recreating: ' . $when);
    scalarCheck($DB->firstRow(TaskJournal::TABLE, ['id' => $task['id']])['result'] === json_encode($result), 'Durable created ID survives source change.');
}

foreach (['child_failure', 'history_missing', 'identity_rule', 'result_failure', 'commit_failure'] as $failure) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    if ($failure === 'child_failure') { PluginFieldsContainer::$fault = 'ignore'; }
    if ($failure === 'history_missing') { PluginFieldsContainer::$fault = 'history_missing'; }
    if ($failure === 'identity_rule') {
        Computer::$afterAdd = static function (int $id) use ($DB): void { $DB->update('glpi_computers', ['otherserial' => 'changed by rule'], ['id' => $id]); };
    }
    if ($failure === 'result_failure' || $failure === 'commit_failure') {
        Computer::$afterAdd = static function () use ($DB, $failure): void {
            $DB->sql->failOn = $failure === 'commit_failure' ? 'COMMIT' : 'UPDATE `' . TaskJournal::TABLE . '`';
        };
    }
    try { $result = TaskJournal::run($task, $settings); } catch (Throwable) { $result = null; }
    $DB->sql->failOn = null;
    if ($DB->sql->pdo->inTransaction()) { $DB->doQuery('ROLLBACK'); }
    $result = TaskJournal::run($task, $settings);
    scalarCheck($result['outcome'] === 'uncertain' && Computer::$adds === 1, 'Interrupted creation is uncertain and never replayed: ' . $failure);
    scalarCheck(count(iterator_to_array($DB->request(['FROM' => 'glpi_computers']))) === 1, 'Parent rolls back with children and result: ' . $failure);
    scalarCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Creation History rolls back: ' . $failure);
}

// An official extension committing unexpectedly cannot be undone by losing the savepoint.
[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
Computer::$afterAdd = static function () use ($DB): void { $DB->sql->pdo->commit(); };
$result = TaskJournal::run($task, $settings);
scalarCheck($result['outcome'] === 'uncertain' && ($result['attempted_id'] ?? 0) > 0
    && $DB->firstRow('glpi_computers', ['id' => $result['attempted_id']]) !== null,
    'Implicit commit retains returned model ID as uncertain evidence, never verified creation.');
scalarCheck(TaskJournal::run($task, $settings) === $result && Computer::$adds === 1, 'Implicit commit cannot replay creation.');

// A newly appearing existing target must never receive the seed exception.
[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$DB->insert('glpi_computers', ['id' => 201, 'entities_id' => 200, 'name' => 'Existing B', 'comment' => '', 'serial' => '', 'otherserial' => '001-Inv.X']);
$DB->insert(PluginFieldsComputerscalar::getTable(), ['id' => 201, 'items_id' => 201, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 2,
    'textfield' => 'Keep existing B', 'yesfield' => 0, 'datefield' => null]);
$result = TaskJournal::run($task, $settings);
scalarCheck(($result['existing'] ?? false) && Computer::$adds === 0 && scalarRow(201)['textfield'] === 'Keep existing B', 'Precreate match never seeds an existing target.');
scalarReceipt($request, $token, $task, $result);
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
autoStep($settings, $request, $token, 'reverify');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
autoStep($settings, $request, $token, 'inspect');
scalarCheck(AgentTask::outstanding('b') === null, 'Fresh existing values complete without a B mutation.');
scalarCheck(scalarRow(101)['textfield'] === 'Keep existing B', 'Existing B keeps ordinary B authority.');

foreach (['prepare_create', 'confirm_create', 'create'] as $phase) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match');
    if ($phase !== 'prepare_create') { createStep($settings, $request, $token, 'prepare_create'); }
    if ($phase === 'confirm_create') { createStep($settings, $request, $token, 'create'); }
    $saved = AgentTask::matchTask('b', 'Computer', 101);
    // Read-only phases may have been delivered. Create may renew only while never delivered.
    if ($phase !== 'create') { AgentTask::exchange($request + ['kind' => 'pull'], $token); }
    $DB->update(AgentTask::TABLE, ['expires' => time() - 1], ['id' => $saved['id']]);
    AgentTask::queueMatch('b', 'Computer', 101);
    if ($phase === 'create') {
        scalarCheck(AssetSyncQueue::claimDue(1) === [], 'Undelivered create waits for first pull without scheduler retries.');
        AgentTask::exchange($request + ['kind' => 'pull'], $token);
    } else {
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    }
    $renewed = AgentTask::matchTask('b', 'Computer', 101);
    scalarCheck($renewed['id'] !== $saved['id'] && json_decode($renewed['body'], true)['kind'] === $phase,
        'Expired safe phase renewed: ' . $phase);
    scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $saved['id']])['body'] === $saved['body'], 'Renewal preserves old immutable body.');
    scalarCheck(Computer::$adds === ($phase === 'confirm_create' ? 1 : 0), 'Renewal itself never adds.');
}

foreach (['prepare_create', 'confirm_create'] as $phase) {
    foreach (['successful_result', 'expired_pull'] as $expiry) {
        [$settings, $request, $token] = createSetup();
        autoStart(); autoStep($settings, $request, $token, 'match');
        if ($phase === 'confirm_create') {
            createStep($settings, $request, $token, 'prepare_create');
            createStep($settings, $request, $token, 'create');
        }
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        if ($expiry === 'successful_result') {
            scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
            // Historical receipts have unknown arrival time and cannot authorize new work.
            $DB->update(AgentTask::TABLE, ['result_received_at' => null], ['id' => $task['id']]);
        }
        $DB->update(AgentTask::TABLE, ['expires' => time() - 1], ['id' => $task['id']]);
        if ($expiry === 'expired_pull') { AgentTask::exchange($request + ['kind' => 'pull'], $token); }
        AgentTask::queueMatch('b', 'Computer', 101);
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
        $renewed = AgentTask::matchTask('b', 'Computer', 101);
        scalarCheck($renewed['id'] !== $task['id'] && json_decode($renewed['body'], true)['kind'] === $phase,
            'Expired read-only evidence requests fresh work: ' . $phase . ' ' . $expiry);
        scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $task['id']])['body'] === json_encode($task),
            'Expired read-only evidence keeps immutable task body.');
        scalarCheck(Computer::$adds === ($phase === 'confirm_create' ? 1 : 0), 'Expired evidence never repeats creation.');
    }
}

foreach (['before_park', 'after_park'] as $arrival) {
    [$settings, $request, $token] = createSetup();
    autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    $result = TaskJournal::run($task, $settings);
    $DB->update(AgentTask::TABLE, ['expires' => time() - 1], ['id' => $task['id']]);
    AgentTask::queueMatch('b', 'Computer', 101);
    $job = AssetSyncQueue::claimDue(1)[0];
    if ($arrival === 'before_park') {
        $DB->sql->beforeQuery = static function (string $sql) use ($DB, $task, $result): void {
            if (!str_contains($sql, 'FROM `' . AgentTask::TABLE . '` WHERE `id` = ') || !str_ends_with($sql, 'FOR UPDATE')) { return; }
            $DB->sql->beforeQuery = null;
            // Simulates receipt winning the task lock, without pretending SQLite models concurrent InnoDB locks.
            $DB->update(AgentTask::TABLE, ['result' => json_encode($result), 'state' => 'verified'], ['id' => $task['id']]);
        };
    }
    AgentTask::process($job, 'autoApplyLocal');
    $DB->sql->beforeQuery = null;
    if ($arrival === 'after_park') { scalarReceipt($request, $token, $task, $result); }
    scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['id' => $job['id']])['status'] === 'pending', 'Result wakes queue ' . $arrival);
    scalarCheck(AgentTask::matchTask('b', 'Computer', 101)['state'] === 'verified', 'Parking does not overwrite received result state ' . $arrival);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    scalarCheck(json_decode(AgentTask::matchTask('b', 'Computer', 101)['body'], true)['kind'] === 'confirm_create', 'Park race retains created ID.');
}

// Detect delivery racing an attempt to replace a supposedly undelivered create.
[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$saved = AgentTask::matchTask('b', 'Computer', 101);
$DB->update(AgentTask::TABLE, ['expires' => time() - 1], ['id' => $saved['id']]);
AgentTask::queueMatch('b', 'Computer', 101);
$DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['items_id' => 101]);
$DB->sql->beforeQuery = static function (string $sql) use ($DB, $saved): void {
    if (!str_starts_with($sql, 'SELECT `connection_id` FROM `' . AgentTask::BINDINGS)) { return; }
    $DB->sql->beforeQuery = null;
    $DB->update(AgentTask::TABLE, ['state' => 'executing'], ['id' => $saved['id']]);
};
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
$DB->sql->beforeQuery = null;
scalarCheck(AgentTask::matchTask('b', 'Computer', 101)['id'] === $saved['id'], 'Fresh locked delivery state prevents replacing a delivered create.');

// COMMIT can succeed while its acknowledgement is lost. Only reread durable evidence is success.
[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
Computer::$afterAdd = static function () use ($DB): void {
    $DB->sql->beforeQuery = static function (string $sql) use ($DB): void {
        if ($sql !== 'COMMIT') { return; }
        $DB->sql->beforeQuery = null;
        $DB->sql->pdo->commit();
        throw new RuntimeException('Lost commit acknowledgement');
    };
};
$result = TaskJournal::run($task, $settings);
scalarCheck($result['outcome'] === 'verified' && Computer::$adds === 1
    && TaskJournal::run($task, $settings) === $result, 'Ambiguous commit recovers only persisted creation evidence, without replay.');

foreach ([['name', 'comment'], ['comment', 'name']] as $order) {
    [$settings, $request, $token] = createSetup();
    $rows = [];
    foreach ($order as $key) { $rows[$key] = ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_b']; }
    Config::$values['plugin:assetsync20']['field_mappings'] = json_encode(['b' => ['Computer' => $rows]]);
    \GlpiPlugin\Assetsync20\FieldMapping::endRunCache();
    autoStart();
    // Invalid ownership or conflicting seed must fail before publication, in either row order.
    $pending = AgentTask::matchTask('b', 'Computer', 101);
    if ($pending) {
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        if ($task) { scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings)); }
        $jobs = AssetSyncQueue::claimDue(1);
        if ($jobs) { AgentTask::process($jobs[0], 'autoApplyLocal'); }
    }
    scalarCheck(AgentTask::matchTask('b', 'Computer', 101) === null && Computer::$adds === 0, 'Conflicting B seed rejected for order ' . implode(',', $order));
}

// Missing native A values are not blank initialization values.
[$settings, $request, $token] = createSetup();
Config::$values['plugin:assetsync20']['field_mappings'] = json_encode(['b' => ['Computer' => [
    'name' => ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a']]]]);
\GlpiPlugin\Assetsync20\FieldMapping::endRunCache();
$readContext = new ReflectionMethod(AgentTask::class, 'mappingContext');
scalarReject(static fn () => $readContext->invoke(null, ['itemtype' => 'Computer', 'connection_id' => 'b', 'local_id' => 101, 'local_entity' => 100],
    ['entities_id' => 100], 'test'), 'Missing native name cannot become a blank seed.');

foreach (['Computer', 'Printer'] as $type) {
    [$settings, $request, $token] = createSetup('glpi_b', 'textfield', $type);
    Config::$values['plugin:assetsync20']['field_mappings'] = json_encode(['b' => [$type => [
        'name' => ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_b'],
        'comment' => ['glpi_b_field_key' => 'comment', 'source_of_truth' => 'glpi_a']]]]);
    \GlpiPlugin\Assetsync20\FieldMapping::endRunCache();
    $table = $type === 'Computer' ? 'glpi_computers' : 'glpi_printers';
    $DB->update($table, ['name' => 'Mapped A name', 'comment' => 'Mapped A comments'], ['id' => 101]);
    autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
    [, $result] = createStep($settings, $request, $token, 'create');
    $created = $DB->firstRow($table, ['id' => $result['created_id']]);
    scalarCheck($created['name'] === 'Mapped A name' && $created['comment'] === 'Mapped A comments', 'Native mapped name/comments initialize through normal add: ' . $type);
    createStep($settings, $request, $token, 'confirm_create');
}

[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$DB->update('glpi_plugin_fields_fields', ['label' => 'Changed metadata'], ['id' => 11]);
$result = TaskJournal::run($task, $settings);
scalarCheck($result['outcome'] === 'stale' && Computer::$adds === 0, 'Fresh prerequisite fingerprint prevents a write after metadata changes.');

[$settings, $request, $token] = createSetup();
autoStart(); autoStep($settings, $request, $token, 'match'); createStep($settings, $request, $token, 'prepare_create');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$DB->insert(TaskJournal::TABLE, ['id' => $task['id'], 'hash' => $task['hash'], 'body' => json_encode($task), 'state' => 'executing',
    'result' => '{}', 'created_at' => time(), 'registration' => $task['registration'], 'generation' => $task['generation'],
    'installation' => $task['installation'], 'connection_id' => $task['connection_id']]);
scalarCheck(TaskJournal::run($task, $settings)['outcome'] === 'uncertain' && Computer::$adds === 0, 'Crash after durable executing marker never retries add.');

ob_end_clean();
echo "Agent automatic creation tests passed ($checks checks including prerequisites; host doubles/SQLite, runtime verification still required).\n";
