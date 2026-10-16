<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function time(): int { return $GLOBALS['recoveryNow'] ?? \time(); }
}
namespace GlpiPlugin\Assetsync20agent {
    function time(): int { return $GLOBALS['recoveryNow'] ?? \time(); }
}
namespace {
    ob_start();
    require __DIR__ . '/agent-scalars.php';

    use GlpiPlugin\Assetsync20\AgentTask;
    use GlpiPlugin\Assetsync20\AssetSyncLink;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20agent\TaskJournal;

    function recoverySetup(string $journal = 'absent'): array
    {
        global $DB;
        unset($GLOBALS['recoveryNow']);
        [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'A value', 'B value');
        TaskJournal::install();
        AssetSyncQueue::notify('Computer', 101, 'b');
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
        $inspect = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
        scalarReceipt($request, $token, $inspect, TaskJournal::run($inspect, $settings));
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
        $old = AgentTask::outstanding('b');
        $body = json_decode($old['body'], true);
        if ($journal !== 'absent') {
            $result = ['outcome' => $journal, 'changed' => []];
            $DB->insert(TaskJournal::TABLE, ['id' => $old['id'], 'hash' => $old['hash'], 'body' => $old['body'],
                'state' => $journal, 'result' => $journal === 'executing' ? '{}' : json_encode($result), 'created_at' => time(),
                'registration' => $settings['registration'], 'generation' => $settings['generation'],
                'installation' => $settings['installation'], 'connection_id' => $settings['connection_id']]);
        }
        $DB->update(AgentTask::TABLE, ['state' => 'stale', 'result' => json_encode(['outcome' => 'stale', 'changed' => []]),
            'result_received_at' => null], ['id' => $old['id']]);
        $GLOBALS['recoveryNow'] = $body['expires'] + 1;
        $DB->sql->workerLockHeld = true;
        return [$settings, $request, $token, AgentTask::outstanding('b'), $body];
    }

    foreach (['absent', 'unchanged', 'stale'] as $journal) {
        [$settings, $request, $token, $old, $body] = recoverySetup($journal);
        $writes = PluginFieldsContainer::$calls;
        $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
        scalarCheck(!AgentTask::canRetry($old), 'Null legacy receipt does not authorize apply retry.');
        $offered = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true, 'recovery_version' => 1], $token);
        scalarCheck($offered['task'] === null && $offered['recovery'] === $body, 'Recovery is separate from executable work.');
        $proof = TaskJournal::recoveryProof($body, $settings);
        scalarCheck($proof['journal'] === $journal && PluginFieldsContainer::$calls === $writes
            && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Proof is a scoped journal read without old execution.');
        $message = $request + ['kind' => 'recovery', 'id' => $old['id'], 'hash' => $old['hash'], 'proof' => $proof];
        $DB->sql->failOn = 'COMMIT';
        scalarReject(static fn () => AgentTask::exchange($message, $token), 'Failed proof COMMIT cannot acknowledge or wake work.');
        $DB->sql->failOn = null;
        scalarCheck(!isset(json_decode(AgentTask::outstanding('b')['context'], true)['recovery_proof'])
            && $DB->firstRow(AssetSyncQueue::TABLE, ['id' => $old['queue_id']])['status'] === 'waiting_agent', 'Proof failure rolls back context and queue together.');
        scalarCheck(AgentTask::exchange($message, $token)['recorded'], 'Fresh bound proof is acknowledged.');
        $saved = AgentTask::outstanding('b');
        foreach (['body', 'hash', 'result', 'result_received_at'] as $key) {
            scalarCheck($saved[$key] === $old[$key], 'Recovery preserves historical ' . $key);
        }
        $claim = AssetSyncQueue::claimDue(1)[0];
        $DB->sql->failOn = 'INSERT INTO `' . AgentTask::TABLE;
        AgentTask::process($claim, 'scalarApplyLocal');
        $DB->sql->failOn = null;
        scalarCheck(AgentTask::outstanding('b')['id'] === $old['id'], 'Failed replacement keeps the original slot.');
        AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
        $fresh = AgentTask::outstanding('b');
        scalarCheck($fresh['id'] !== $old['id'] && json_decode($fresh['body'], true)['kind'] === 'inspect', 'Normal claim transfers the slot to one fresh inspection.');
        $message['proof']['observed_at']++;
        scalarCheck(AgentTask::exchange($message, $token)['recorded'] && AgentTask::outstanding('b')['id'] === $fresh['id'], 'Lost proof ACK replay creates no second replacement.');
        scalarCheck(count(iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['slot' => $fresh['slot']]]))) === 1,
            'Exactly one outstanding owner remains.');
        $historical = $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]);
        scalarCheck($historical['slot'] === null && $historical['body'] === $old['body'] && $historical['result'] === $old['result'], 'Slot transfer retains the original evidence.');
    }

    foreach (['executing', 'partial', 'uncertain', 'verified'] as $journal) {
        [$settings, $request, $token, $old, $body] = recoverySetup($journal);
        scalarCheck(TaskJournal::recoveryProof($body, $settings) === null && AgentTask::outstanding('b')['id'] === $old['id'],
            'Ambiguous or written journal is retained: ' . $journal);
    }
    [$settings, $request, $token, $old, $body] = recoverySetup();
    $GLOBALS['recoveryNow'] = $body['expires'];
    scalarCheck(TaskJournal::recoveryProof($body, $settings) === null, 'Absence requires B clock strictly past expiry.');
    $GLOBALS['recoveryNow']++;
    $DB->sql->workerLockHeld = false;
    scalarCheck(TaskJournal::recoveryProof($body, $settings) === null, 'Absence requires the existing exclusive worker lock.');
    $DB->sql->workerLockHeld = true;
    $DB->sql->failOn = 'SELECT * FROM `' . TaskJournal::TABLE;
    scalarReject(static fn () => TaskJournal::recoveryProof($body, $settings), 'A failed journal read is never absence.');
    $DB->sql->failOn = null;
    $other = $settings; $other['generation'] = str_repeat('f', 32);
    scalarReject(static fn () => TaskJournal::recoveryProof($body, $other), 'Rebinding cannot authorize a proof.');
    $DB->update('glpi_computers', ['entities_id' => 999], ['id' => 201]);
    scalarReject(static fn () => TaskJournal::recoveryProof($body, $settings), 'Out-of-scope target cannot authorize a proof.');

    foreach (['hash', 'body', 'changed'] as $conflict) {
        [$settings, $request, $token, $old, $body] = recoverySetup('unchanged');
        $changes = match ($conflict) {
            'hash' => ['hash' => str_repeat('f', 64)],
            'body' => ['body' => json_encode(array_replace($body, ['serial' => 'other']))],
            'changed' => ['result' => json_encode(['outcome' => 'unchanged', 'changed' => ['name']])],
        };
        $DB->update(TaskJournal::TABLE, $changes, ['id' => $old['id']]);
        scalarCheck(TaskJournal::recoveryProof($body, $settings) === null, 'Conflicting journal cannot authorize recovery: ' . $conflict);
    }

    [$settings, $request, $token, $old, $body] = recoverySetup();
    $proof = TaskJournal::recoveryProof($body, $settings);
    $message = $request + ['kind' => 'recovery', 'id' => $old['id'], 'hash' => $old['hash'], 'proof' => $proof];
    $DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => $old['queue_id']]);
    $claim = AssetSyncQueue::claimDue(1)[0];
    scalarReject(static fn () => AgentTask::exchange($message, $token), 'Proof cannot overwrite a concurrently running queue claim.');
    scalarCheck(AssetSyncQueue::owns($claim), 'Conflicting proof preserves the current claim.');
    $DB->update(AssetSyncQueue::TABLE, ['status' => 'waiting_agent', 'claim_token' => ''], ['id' => $old['queue_id']]);
    $DB->update(AgentTask::TABLE, ['slot' => null], ['id' => $old['id']]);
    scalarReject(static fn () => AgentTask::exchange($message, $token), 'Proof cannot release a changed slot owner.');

    [$settings, $request, $token, $old, $body] = recoverySetup();
    $second = $old;
    $secondBody = $body;
    $secondBody['id'] = bin2hex(random_bytes(16));
    $secondBody = scalarHash($secondBody);
    $second['id'] = $secondBody['id']; $second['hash'] = $secondBody['hash']; $second['body'] = json_encode($secondBody);
    $second['slot'] = 'pair:' . str_repeat('e', 32);
    $DB->insert(AgentTask::TABLE, $second);
    $ordinary = $second;
    $ordinaryBody = $secondBody;
    $ordinaryBody['id'] = bin2hex(random_bytes(16)); $ordinaryBody['expires'] = $GLOBALS['recoveryNow'] + 300;
    $ordinaryBody['kind'] = 'inspect'; $ordinaryBody['changes'] = []; $ordinaryBody = scalarHash($ordinaryBody);
    $ordinary['id'] = $ordinaryBody['id']; $ordinary['hash'] = $ordinaryBody['hash']; $ordinary['body'] = json_encode($ordinaryBody);
    $ordinary['state'] = 'accepted'; $ordinary['result'] = '{}'; $ordinary['expires'] = $ordinaryBody['expires'];
    $ordinary['slot'] = 'pair:' . str_repeat('d', 32);
    $DB->insert(AgentTask::TABLE, $ordinary);
    $probeRequest = $request + ['kind' => 'pull', 'subscriptions_only' => true, 'recovery_version' => 1];
    $firstOffer = AgentTask::exchange($probeRequest, $token);
    $nextOffer = AgentTask::exchange($probeRequest, $token);
    scalarCheck($firstOffer['task'] === null && $firstOffer['recovery']['id'] !== $nextOffer['recovery']['id'],
        'Reserved recovery offers rotate despite ordinary accepted work and a refused previous probe.');

    foreach (['apply', 'inspect'] as $kind) {
        [$settings, $request, $token, $old, $body] = recoverySetup();
        if ($kind === 'inspect') {
            $body['kind'] = 'inspect'; $body['changes'] = []; $body = scalarHash($body);
            $DB->update(AgentTask::TABLE, ['body' => json_encode($body), 'hash' => $body['hash']], ['id' => $old['id']]);
        }
        for ($i = 0; $i < 10; $i++) {
            $skipped = $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]);
            $skippedBody = $body; $skippedBody['id'] = str_pad(dechex($i + 1), 32, '0', STR_PAD_LEFT);
            if ($kind === 'inspect' && $i < 5) { $skippedBody['kind'] = 'apply'; }
            $skippedBody['generation'] = str_repeat('f', 32); $skippedBody = scalarHash($skippedBody);
            $skipped['id'] = $skippedBody['id']; $skipped['hash'] = $skippedBody['hash'];
            $skipped['body'] = json_encode($skippedBody); $skipped['created_at'] = 0;
            $skippedContext = json_decode($skipped['context'], true);
            $skippedContext['retained_test_evidence'] = ['value' => 'unchanged'];
            $skipped['context'] = json_encode($skippedContext);
            $skipped['slot'] = 'pair:' . $skippedBody['id'];
            $DB->insert(AgentTask::TABLE, $skipped);
        }
        $reached = false;
        for ($pass = 0; $pass < 3; $pass++) {
            if ($kind === 'apply') {
                $offer = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true, 'recovery_version' => 1], $token);
                $reached = ($offer['recovery']['id'] ?? null) === $old['id'];
            } else {
                AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
                $reached = AgentTask::outstanding('b') === null;
            }
            if ($reached) { break; }
        }
        scalarCheck($reached, 'Bounded recovery reaches valid ' . $kind . ' behind ten older ineligible rows.');
        for ($i = 0; $i < 10; $i++) {
            $id = str_pad(dechex($i + 1), 32, '0', STR_PAD_LEFT);
            $skipped = $DB->firstRow(AgentTask::TABLE, ['id' => $id]);
            scalarCheck($skipped['slot'] === 'pair:' . $id && $skipped['state'] === 'stale'
                && json_decode($skipped['body'], true)['generation'] === str_repeat('f', 32),
                'Ineligible retained operation is not released or offered: ' . $kind);
            scalarCheck(json_decode($skipped['context'], true)['retained_test_evidence'] === ['value' => 'unchanged'],
                'Rotation preserves separate context evidence: ' . $kind);
        }
    }

    \GlpiPlugin\Assetsync20\Config\WorkerSettings::saveInterval(2);
    scalarCheck(\GlpiPlugin\Assetsync20\Config\WorkerSettings::getInterval() === 2, 'Existing A worker accepts a two-second interval.');
    try { \GlpiPlugin\Assetsync20\Config\WorkerSettings::saveInterval(1); throw new LogicException('One-second A interval accepted'); }
    catch (InvalidArgumentException) { scalarCheck(true, 'A interval still enforces the two-second minimum.'); }

    [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'A value', 'B value');
    scalarControllerRun($settings, $request, $token);
    $pair = AgentTask::pair('b');
    $queue = $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101]);
    $link = AssetSyncLink::find('Computer', 101, 'b');
    scalarCheck($queue['remote_items_id'] === 201 && $queue['route_id'] === 'route' && $queue['payload_hash'] !== ''
        && $link['status'] === 'synced' && $link['last_payload_hash'] === $queue['payload_hash'] && $link['last_sync_at'] !== null,
        'Verified apply completes remote ID, route, payload hash and synced link time together.');
    $DB->update(AssetSyncQueue::TABLE, ['remote_items_id' => null, 'route_id' => ''], ['id' => $queue['id']]);
    $DB->update(AssetSyncLink::TABLE, ['status' => AssetSyncLink::STATUS_BLOCKED_CONFIGURATION], ['id' => $link['id']]);
    scalarCheck(AgentTask::needsInspection($pair, false), 'Already-done legacy rows require fresh inspection instead of claiming old success.');
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
    $old = AgentTask::outstanding('b');
    $DB->update(AgentTask::TABLE, ['state' => 'stale'], ['id' => $old['id']]);
    $retained = $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]);
    $savedConnections = Config::$values['plugin:assetsync20']['glpib_connections'];
    $disabledConnections = json_decode($savedConnections, true, 32, JSON_THROW_ON_ERROR);
    foreach ($disabledConnections as &$connection) { if ($connection['id'] === 'b') { $connection['active'] = false; } }
    unset($connection);
    Config::$values['plugin:assetsync20']['glpib_connections'] = json_encode($disabledConnections, JSON_THROW_ON_ERROR);
    \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll(true, false);
    AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
    scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]) === $retained,
        'Disabled connection recovery leaves all retained context and task state identical.');
    Config::$values['plugin:assetsync20']['glpib_connections'] = $savedConnections;
    \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll(true, false);
    AgentTask::recoverStaleInspections(hrtime(true) - 1);
    scalarCheck(AgentTask::outstanding('b')['id'] === $old['id'], 'Expired recovery budget does no work.');
    AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
    scalarCheck(AgentTask::outstanding('b') === null && $DB->firstRow(AssetSyncQueue::TABLE, ['id' => $old['queue_id']])['status'] === 'pending',
        'Normal bounded recovery wakes a stale read-only inspection.');
    scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $old['id']])['body'] === $old['body'], 'Read-only recovery retains the immutable body.');

    unset($GLOBALS['recoveryNow']);
    ob_end_clean();
    echo "Agent recovery tests passed ($checks checks including scalar prerequisites; simulated clocks and SQLite).\n";
}
