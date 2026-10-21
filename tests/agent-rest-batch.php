<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-rest-dispatch.php';
ob_end_clean();

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;

function batchSetup(int $count = 2): array
{
    global $DB;
    taskSetup();
    RestWork::beginRun();
    RestDispatchWire::reset();
    $pair = AgentTask::pair('b');
    $pairs = [$pair['id']];
    $asset = $DB->firstRow('glpi_computers', ['id' => 101]);
    $link = AssetSyncLink::find('Computer', 101, 'b');
    for ($index = 1; $index < $count; $index++) {
        $id = md5('batch-pair-' . $index);
        $snapshot = json_decode($pair['snapshot'], true);
        $snapshot['items_id'] = 201 + $index;
        $DB->insert('glpi_computers', array_replace($asset, ['id' => 101 + $index]));
        $DB->insert(AgentTask::PAIRS, array_replace($pair, ['id' => $id, 'local_id' => 101 + $index,
            'computer_id' => 201 + $index, 'snapshot' => json_encode($snapshot)]));
        AssetSyncLink::save(array_replace($link, ['items_id' => 101 + $index, 'remote_items_id' => 201 + $index,
            'confirmed_remote_id' => 201 + $index]));
        $pairs[] = $id;
    }
    GlpiBConnection::save(array_replace(GlpiBConnection::find('b'), ['agent_rest_pair' => '', 'agent_rest_pairs' => $pairs,
        'agent_rest_url' => 'https://b.invalid/apirest.php', 'agent_app_token' => 'private-app', 'agent_user_token' => 'private-user']));
    foreach ($pairs as $index => $id) { AssetSyncQueue::notify('Computer', 101 + $index, 'b'); }
    foreach (AssetSyncQueue::claimDue($count) as $job) { RestWork::process($job); }
    $tasks = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => [new \Glpi\DBAL\QueryExpression("JSON_EXTRACT(body, '$.version') = 8")], 'ORDER' => 'id']));
    agentCheck(count($tasks) === $count, 'Each confirmed opt-in pair publishes immutable v8 work.');
    $connection = GlpiBConnection::find('b');
    $body = json_decode($tasks[0]['body'], true);
    $key = hash('sha256', $connection['id'] . $connection['agent_rest_url'] . $body['settings_hash']);
    Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode([$key => 9])]);
    $DB = new RestDispatchDB($DB);
    return $tasks;
}

function batchRecord(array $task, string $state = 'verified'): array
{
    $body = json_decode($task['body'], true);
    $record = restRecord($task, $state);
    $record['batch_id'] = $body['batch_id'];
    $record['expires'] = $body['expires'];
    if ($state === 'verified') {
        $snapshot = ['itemtype' => 'Computer', 'items_id' => $body['items_id'], 'entity_id' => $body['entity_id'],
            'pair_id' => $body['pair_id'], 'serial' => $body['identity']['serial'], 'otherserial' => $body['identity']['otherserial'] ?? '',
            'observed_at' => time(), 'fields' => ['name' => ['value' => $body['name']]]];
        $record['result'] = json_encode(['outcome' => 'verified', 'changed' => ['name' => 11], 'snapshot' => $snapshot,
            'timing' => ['started_at' => time(), 'finished_at' => $snapshot['observed_at'], 'within_deadline' => true]]);
    }
    return $record;
}

function batchSend(array $replies): array
{
    global $DB;
    $tasks = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => [new \Glpi\DBAL\QueryExpression("JSON_EXTRACT(body, '$.version') = 8")], 'ORDER' => 'id']));
    foreach ($tasks as $task) {
        $context = json_decode($task['context'], true); $context['rest_next'] = 0;
        $DB->update(AgentTask::TABLE, ['context' => json_encode($context)], ['id' => $task['id']]);
    }
    RestDispatchWire::$replies = $replies;
    RestWork::sendPending(hrtime(true) + 20_000_000_000);
    agentCheck(RestDispatchWire::$replies === [], 'Expected batch requests consumed.');
    return iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => [new \Glpi\DBAL\QueryExpression("JSON_EXTRACT(body, '$.version') = 8")], 'ORDER' => 'id']));
}

$tasks = batchSetup();
$body = json_decode($tasks[0]['body'], true);
agentCheck(json_decode($tasks[1]['body'], true)['batch_id'] === $body['batch_id'], 'Equal scope shares one run-scoped batch.');
$records = array_map('batchRecord', $tasks);
$saved = batchSend([['status' => 201, 'body' => [['id' => 999], ['id' => false]]], ['status' => 200, 'body' => array_reverse($records)]]);
foreach ($saved as $index => $task) {
    restImmutable($tasks[$index], $task);
    agentCheck(json_decode($task['result'], true)['snapshot']['items_id'] === json_decode($task['body'], true)['items_id'], 'GET membership, not POST position, correlates results.');
}
$workCalls = array_values(array_filter(RestDispatchWire::$calls, static fn ($call): bool => !in_array($call['path'], ['initSession', 'killSession'], true)));
agentCheck(count($workCalls) === 2 && $workCalls[0]['method'] === 'POST' && $workCalls[1]['method'] === 'GET'
    && str_contains($workCalls[1]['path'], 'get_hateoas=0') && !str_contains($workCalls[1]['path'], 'debug'), 'Warm successful batch uses POST + one bounded GET.');
$inputs = json_decode($workCalls[0]['options'][CURLOPT_POSTFIELDS], true);
agentCheck(array_is_list($inputs['input']) && count($inputs['input']) === 2 && !$inputs['session_write'], 'Native list payload uses closed sessions.');
foreach (AssetSyncQueue::claimDue(2) as $job) { RestWork::process($job); }
foreach ($saved as $task) {
    $final = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE id = ' . $DB->quote($task['id']));
    agentCheck($final['state'] === 'verified' && $final['slot'] === null, 'Each member finalizes under a fresh queue claim and source check.');
}

$tasks = batchSetup();
$record = batchRecord($tasks[0]);
$saved = batchSend([['status' => 207, 'body' => ['ERROR_GLPI_PARTIAL_ADD', [['id' => false], ['id' => 31]]]], ['status' => 200, 'body' => [$record]]]);
agentCheck($saved[0]['result'] !== '{}' && $saved[1]['result'] === '{}', 'Partial acceptance does not complete the missing member.');
$saved = batchSend([['status' => 200, 'body' => [$record]],
    static function ($method, $path, $options) use ($tasks): array {
        $input = json_decode($options[CURLOPT_POSTFIELDS], true)['input'];
        agentCheck(count($input) === 1 && $input[0]['work_id'] === $tasks[1]['id'] && $input[0]['body'] === $tasks[1]['body'], 'Complete GET permits targeted same-ID recovery of unaccepted member.');
        return ['status' => 201, 'body' => [['id' => 32]]];
    }, ['status' => 200, 'body' => array_map('batchRecord', $tasks)]]);
agentCheck($saved[1]['result'] !== '{}', 'Targeted member result recovered.');

$tasks = batchSetup();
batchSend([['status' => 0, 'body' => []], ['status' => 503, 'body' => []]]);
$saved = batchSend([['status' => 503, 'body' => []]]);
agentCheck(count(array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST')) === 1
    && $saved[0]['result'] === '{}' && $saved[1]['result'] === '{}', 'Ambiguous batch POST plus failed reads never causes a resend.');
$saved = batchSend([['status' => 200, 'body' => array_map('batchRecord', $tasks)]]);
agentCheck($saved[0]['result'] !== '{}' && $saved[1]['result'] !== '{}', 'One successful collection read recovers ambiguous terminal results.');

$tasks = batchSetup();
$bad = batchRecord($tasks[0]); $bad['body'] .= ' ';
$saved = batchSend([['status' => 400, 'body' => ['ERROR_GLPI_ADD', []]], ['status' => 200, 'body' => [$bad]]]);
agentCheck($saved[0]['result'] === '{}' && $saved[1]['result'] === '{}', 'All-failed status or altered immutable body is not proof of absence or completion.');
batchSend([['status' => 206, 'body' => []]]);

$tasks = batchSetup();
foreach ($tasks as &$task) {
    $body = json_decode($task['body'], true); $body['expires'] = time() - 1;
    $task['body'] = json_encode($body); $task['hash'] = AgentTask::hash($body);
    $DB->update(AgentTask::TABLE, ['body' => $task['body'], 'hash' => $task['hash'], 'expires' => $body['expires']], ['id' => $task['id']]);
} unset($task);
$saved = batchSend([['status' => 200, 'body' => array_map(static fn ($task) => batchRecord($task, 'accepted'), $tasks)]]);
agentCheck(json_decode($saved[0]['result'], true)['outcome'] === 'stale'
    && !array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST'), 'Expired accepted SQL projection becomes stale on A without GET mutation or POST.');

$tasks = batchSetup(1);
$saved = batchSend([['status' => 201, 'body' => [['id' => 31]]], ['status' => 200, 'body' => [batchRecord($tasks[0], 'executing')]]]);
agentCheck(str_contains(restLabel($saved[0]), 'REST B intent executing; completion unverified'), 'Persisted executing intent is not proof of worker liveness.');

$tasks = batchSetup(1);
$body = json_decode($tasks[0]['body'], true); $body['expires'] = time() - 1;
$tasks[0]['body'] = json_encode($body); $tasks[0]['hash'] = AgentTask::hash($body);
$DB->update(AgentTask::TABLE, ['body' => $tasks[0]['body'], 'hash' => $tasks[0]['hash'], 'expires' => $body['expires']], ['id' => $tasks[0]['id']]);
$executing = batchRecord($tasks[0], 'executing');
$uncertain = array_replace($executing, ['state' => 'uncertain', 'result' => '{"outcome":"uncertain","changed":[]}']);
$saved = batchSend([['status' => 200, 'body' => [$executing]], ['status' => 201, 'body' => [['id' => 31]]], ['status' => 200, 'body' => [$uncertain]]]);
agentCheck(json_decode($saved[0]['result'], true)['outcome'] === 'uncertain'
    && count(array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST')) === 1,
    'Expired executing intent gets identical POST reconciliation and retained uncertainty, never endless false processing.');
agentCheck(str_contains(restLabel($saved[0]), 'REST uncertain; retained for inspection'), 'Retained uncertainty is visible without claiming A completion.');

$tasks = batchSetup(1);
$blocked = array_replace(batchRecord($tasks[0], 'blocked'), ['result' => '{"outcome":"blocked","changed":[]}']);
$saved = batchSend([['status' => 201, 'body' => [['id' => 31]]], ['status' => 200, 'body' => [$blocked]]]);
agentCheck(str_contains(restLabel($saved[0]), 'REST blocked; retained for inspection'), 'Retained B denial is visible before A finalization.');

$tasks = batchSetup(1);
try {
    batchSend([['status' => 201, 'body' => [['id' => 31]]], static function () use ($tasks): array {
        $GLOBALS['DB']->sql->failOn = '`result`=';
        return ['status' => 200, 'body' => [batchRecord($tasks[0])]];
    }]);
    throw new LogicException('Result storage failure swallowed');
} catch (RuntimeException $error) {
    agentCheck(!str_contains($error->getMessage(), 'Invalid REST'), 'Storage exception propagates outside result validation.');
} finally { $DB->sql->failOn = null; }
$stored = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE id = ' . $DB->quote($tasks[0]['id']));
agentCheck($stored['result'] === '{}' && (json_decode($stored['context'], true)['rest_error']['error'] ?? '') !== 'invalid_result', 'Storage failure does not fabricate invalid_result evidence.');

$tasks = batchSetup(5);
$ids = array_column(array_map(static fn ($task) => json_decode($task['body'], true), $tasks), 'batch_id');
agentCheck(count(array_unique($ids)) === 2 && max(array_count_values($ids)) === 4, 'Response budget splits five assets into batches of at most four.');
RestWork::beginRun();
$state = (new ReflectionProperty(RestWork::class, 'batches'))->getValue();
agentCheck($state === [], 'New run cannot append work to an already dispatched batch.');
echo "REST v8 A batching passed: native lists, exact correlation, partial/lost response recovery, expiry, finalization and run bounds (mock HTTP).\n";
