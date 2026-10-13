<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function time(): int { return $GLOBALS['deadlineNow'] ?? \time(); }
}

namespace GlpiPlugin\Assetsync20agent {
    function time(): int { return $GLOBALS['deadlineNow'] ?? \time(); }
}

namespace {
    ob_start();
    require __DIR__ . '/agent-auto-create.php';

    use GlpiPlugin\Assetsync20\AgentTask;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20agent\TaskJournal;

    // All setup and existing regressions use the real clock. Only these scenarios advance time.
    [$settings, $request, $token] = createSetup('glpi_a');
    $GLOBALS['deadlineNow'] = time();
    $initial = $GLOBALS['deadlineNow'];
    $parent = $DB->firstRow('glpi_computers', ['id' => 101]);
    $child = $DB->firstRow(PluginFieldsComputerscalar::getTable(), ['items_id' => 101]);
    for ($i = 0; $i < 30; $i++) {
        $id = 101 + $i;
        if ($i > 0) {
            $DB->insert('glpi_computers', array_replace($parent, ['id' => $id]));
            $copy = array_replace($child, ['items_id' => $id]);
            unset($copy['id']);
            $DB->insert(PluginFieldsComputerscalar::getTable(), $copy);
        }
        $DB->update('glpi_computers', ['serial' => 'deadline-' . $id, 'otherserial' => 'inventory-' . $id], ['id' => $id]);
        scalarCheck(AgentTask::queueMatch('b', 'Computer', $id), 'Normal scanner admits asset ' . $id);
    }
    $apply = static function (array $changes): bool {
        scalarCheck($changes === [], 'A-authority run needs no local model writes.');
        return false;
    };
    foreach (AssetSyncQueue::claimDue(100) as $job) { AgentTask::process($job, $apply); }
    $original = AgentTask::matchTask('b', 'Computer', 101);
    $GLOBALS['deadlineNow'] += 360;
    AgentTask::queueMatch('b', 'Computer', 101);
    scalarCheck(AssetSyncQueue::claimDue(100) === [], 'Over-five-minute undelivered backlog remains waiting.');
    for ($tick = 0; $tick < 400; $tick++) {
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        if ($task) {
            scalarCheck($task['expires'] === $GLOBALS['deadlineNow'] + 300, 'First pull has full execution window.');
            $result = TaskJournal::run($task, $settings);
            scalarCheck(TaskJournal::run($task, $settings) === $result, 'Executing journal replay is immutable.');
            scalarReceipt($request, $token, $task, $result);
            $received = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']])['result_received_at'];
            scalarReceipt($request, $token, $task, $result);
            scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $task['id']])['result_received_at'] === $received,
                'Duplicate delivery preserves first receipt.');
        }
        if ($tick % 4 === 0) {
            foreach (AssetSyncQueue::claimDue(100) as $job) { AgentTask::process($job, $apply); }
        }
        $GLOBALS['deadlineNow'] += 15;
    }
    $done = iterator_to_array($DB->request(['FROM' => AssetSyncQueue::TABLE, 'WHERE' => ['status' => 'done']]));
    scalarCheck(count($done) === 30 && Computer::$adds === 30, 'Thirty assets finish normal B15s/A60s cadence with exactly one creation each.');
    $retained = $DB->firstRow(AgentTask::TABLE, ['id' => $original['id']]);
    scalarCheck($retained['body'] === $original['body'] && $retained['hash'] === $original['hash']
        && $retained['result'] === '{}' && $retained['slot'] === null && $retained['result_received_at'] === null,
        'First-pull replacement preserves original evidence and releases its slot.');
    unset($GLOBALS['deadlineNow']);

    foreach (['timely', 'late', 'old', 'future', 'future_receipt', 'legacy'] as $case) {
        [$settings, $request, $token] = autoSetup();
        $GLOBALS['deadlineNow'] = time();
        autoStart();
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        $GLOBALS['deadlineNow'] = $task['expires'] - 10;
        $result = TaskJournal::run($task, $settings);
        if ($case === 'late') { $GLOBALS['deadlineNow'] += 11; }
        if ($case === 'future') {
            $result['evidence']['observed_at'] = $GLOBALS['deadlineNow'] + 1;
            try {
                scalarReceipt($request, $token, $task, $result);
                throw new LogicException('Future observation accepted.');
            } catch (UnexpectedValueException) {
                scalarCheck(AgentTask::matchTask('b', 'Computer', 101)['result_received_at'] === null, 'Rejected evidence has no receipt.');
            }
            unset($GLOBALS['deadlineNow']);
            continue;
        }
        scalarReceipt($request, $token, $task, $result);
        if ($case === 'legacy') { $DB->update(AgentTask::TABLE, ['result_received_at' => null], ['id' => $task['id']]); }
        $GLOBALS['deadlineNow'] = $task['expires'] + ($case === 'old' ? 301 : 20);
        if ($case === 'future_receipt') {
            $DB->update(AgentTask::TABLE, ['result_received_at' => $GLOBALS['deadlineNow'] + 1], ['id' => $task['id']]);
        }
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
        $next = AgentTask::matchTask('b', 'Computer', 101);
        scalarCheck($case === 'timely' ? ($next && json_decode($next['body'], true)['kind'] === 'reverify') : $next === null,
            'Processing after execution deadline requires timely receipt and fresh evidence: ' . $case);
        unset($GLOBALS['deadlineNow']);
    }

    // Every phase can be processed after expiry when receipt was timely and observation still fresh.
    [$settings, $request, $token] = createSetup();
    $GLOBALS['deadlineNow'] = time();
    autoStart();
    foreach (['match', 'prepare_create', 'create', 'confirm_create', 'inspect', 'apply'] as $kind) {
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarCheck($task['kind'] === $kind, 'Delayed-processing phase ' . $kind);
        $GLOBALS['deadlineNow'] = $task['expires'] - 10;
        scalarCheck(AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'] === $task,
            'Delivered task keeps ID/hash/expiry on replay: ' . $kind);
        $result = TaskJournal::run($task, $settings);
        scalarReceipt($request, $token, $task, $result);
        $received = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']])['result_received_at'];
        $GLOBALS['deadlineNow'] = $task['expires'] + 20;
        scalarReceipt($request, $token, $task, $result);
        scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $task['id']])['result_received_at'] === $received,
            'Late duplicate cannot rewrite first receipt: ' . $kind);
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
        if ($kind === 'confirm_create') { AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal'); }
    }
    scalarCheck(Computer::$adds === 1 && AgentTask::outstanding('b') === null,
        'Delayed worker finishes creation and scalar synchronization with one add.');
    unset($GLOBALS['deadlineNow']);

    [$settings, $request, $token] = autoSetup();
    $GLOBALS['deadlineNow'] = time();
    $job = autoStart();
    $original = AgentTask::matchTask('b', 'Computer', 101);
    $GLOBALS['deadlineNow'] += 360;
    $DB->sql->failOn = 'INSERT INTO `' . AgentTask::TABLE;
    try {
        AgentTask::exchange($request + ['kind' => 'pull'], $token);
        throw new LogicException('Failed replacement was delivered.');
    } catch (RuntimeException) {
        scalarCheck(AgentTask::matchTask('b', 'Computer', 101) === $original, 'Failed first-pull insert rolls back slot transfer.');
    }
    $DB->sql->failOn = null;
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
    $job = AssetSyncQueue::claimDue(1)[0];
    $park = new ReflectionMethod(AgentTask::class, 'park');
    $park->invoke(null, $job, $original);
    scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['id' => $job['id']])['status'] === 'pending',
        'Stale worker parks against current slot owner and preserves its result wakeup.');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'autoApplyLocal');
    scalarCheck(json_decode(AgentTask::matchTask('b', 'Computer', 101)['body'], true)['kind'] === 'reverify',
        'Transferred job/context pointers remain usable after stale-owner parking.');
    unset($GLOBALS['deadlineNow']);

    foreach (['unchanged', 'edited'] as $source) {
        [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'A value', 'B value');
        $GLOBALS['deadlineNow'] = time();
        AssetSyncQueue::notify('Computer', 101, 'b');
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
        $original = AgentTask::outstanding('b');
        $GLOBALS['deadlineNow'] += 360;
        $options = Search::$options;
        Search::$options = array_filter($options, static fn ($option) => $option['table'] === 'glpi_computers');
        $_SESSION = [];
        if ($source === 'edited') { $DB->update(PluginFieldsComputerscalar::getTable(), ['textfield' => 'Changed'], ['items_id' => 101]); }
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarCheck($source === 'unchanged' ? ($task !== null && $task['id'] !== $original['id']) : $task === null,
            'Sessionless first-pull reissue validates persisted paired source: ' . $source);
        Search::$options = $options;
        unset($GLOBALS['deadlineNow']);
    }

    foreach ([1, 2, 3] as $version) {
        [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'A value', 'B value');
        TaskJournal::install();
        $GLOBALS['deadlineNow'] = time();
        AssetSyncQueue::notify('Computer', 101, 'b');
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
        $original = AgentTask::outstanding('b');
        $body = json_decode($original['body'], true);
        $body['version'] = $version;
        if ($version < 3) {
            $body['computer_id'] = $body['items_id'];
            unset($body['items_id'], $body['itemtype'], $body['pair_id']);
        }
        $body = scalarHash($body);
        $original['body'] = json_encode($body);
        $original['hash'] = $body['hash'];
        $DB->update(AgentTask::TABLE, ['body' => $original['body'], 'hash' => $body['hash']], ['id' => $body['id']]);
        $GLOBALS['deadlineNow'] += 360;
        $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarCheck($task['version'] === $version && $task['id'] !== $body['id'] && $task['expires'] === $GLOBALS['deadlineNow'] + 300,
            'Legacy first-pull replacement retains wire version ' . $version);
        scalarCheck(AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'] === $task, 'Legacy executing replay stays immutable.');
        scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
        $retained = $DB->firstRow(AgentTask::TABLE, ['id' => $body['id']]);
        scalarCheck($retained['body'] === $original['body'] && $retained['hash'] === $original['hash']
            && $retained['result'] === '{}' && $retained['result_received_at'] === null && $retained['slot'] === null,
            'Legacy predecessor body/hash/result survive first delivery unchanged.');
        unset($GLOBALS['deadlineNow']);
    }

    [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'A value', 'B value');
    TaskJournal::install();
    $DB->doQuery('CREATE TABLE glpi_profiles_users (id INTEGER PRIMARY KEY, users_id INT, profiles_id INT, entities_id INT, is_recursive INT)');
    $DB->insert('glpi_profiles_users', ['id' => 1, 'users_id' => 7, 'profiles_id' => 4, 'entities_id' => 100, 'is_recursive' => 0]);
    $GLOBALS['deadlineNow'] = time();
    AgentTask::discover('b', 'Computer', -1);
    $original = AgentTask::discoveryTask('b');
    $GLOBALS['deadlineNow'] += 360;
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarCheck($task['kind'] === 'discover' && AgentTask::binding('b')['discovery_id'] === $task['id']
        && $task['id'] !== $original['id'], 'Discovery pointer transfers with the replacement slot.');
    scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
    scalarCheck(AgentTask::binding('b')['discovery_observed_id'] === $task['id'], 'Discovery result points at the delivered replacement.');
    unset($GLOBALS['deadlineNow']);

    // Migration never invents receipt times for retained historical results.
    $DB->doQuery('ALTER TABLE `' . AgentTask::TABLE . '` DROP COLUMN result_received_at');
    scalarCheck(AgentTask::install() && AgentTask::install(), 'Existing task table upgrades additively and repeatably.');
    foreach ($DB->request(['FROM' => AgentTask::TABLE]) as $row) {
        scalarCheck($row['result_received_at'] === null, 'Migration leaves historical arrival unknown.');
    }
    ob_end_clean();
    echo "Agent deadline tests passed ({$checks} checks including existing regressions; simulated clock and SQLite).\n";
}
