<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-tasks.php';
ob_end_clean();

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;

[$credentials, $request] = taskSetup();
[$job, $legacy] = queueTask();
for ($index = 0; $index < 51; $index++) {
    $id = md5('rest-coexist-' . $index);
    $body = ['version' => 7, 'id' => $id];
    $DB->insert(AgentTask::TABLE, ['id' => $id, 'connection_id' => 'b', 'slot' => 'rest:' . $index,
        'hash' => AgentTask::hash($body), 'body' => json_encode($body), 'state' => 'accepted', 'result' => '{}',
        'queue_id' => null, 'job' => '[]', 'context' => '{}', 'created_at' => 1, 'expires' => time() + 300]);
}
$before = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['created_at' => 1], 'ORDER' => 'id']));
$response = AgentTask::exchange($request, new \GlpiPlugin\Assetsync20\AgentApi('b'));
if (($response['task']['version'] ?? null) !== 3 || ($response['task']['kind'] ?? '') !== 'inspect') {
    throw new RuntimeException('REST records starved legacy delivery before LIMIT');
}
$after = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['created_at' => 1], 'ORDER' => 'id']));
if ($before !== $after) { throw new RuntimeException('Legacy pull modified REST evidence'); }
$DB->update(AgentTask::TABLE, ['state' => 'blocked'], ['created_at' => 1]);
$before = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['created_at' => 1], 'ORDER' => 'id']));
AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
if ($before !== iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['created_at' => 1], 'ORDER' => 'id']))) {
    throw new RuntimeException('Legacy cleanup modified REST evidence');
}
$pair = AgentTask::pair('b');
$outstanding = AgentTask::outstanding('b', $pair);
$DB->update(AgentTask::TABLE, ['slot' => null], ['id' => $outstanding['id']]);
$body = ['version' => 7, 'id' => md5('rest-selected'), 'pair_id' => $pair['id'], 'itemtype' => 'Computer', 'items_id' => 201];
$DB->insert(AgentTask::TABLE, ['id' => $body['id'], 'connection_id' => 'b', 'slot' => 'pair:' . $pair['id'],
    'hash' => AgentTask::hash($body), 'body' => json_encode($body), 'state' => 'accepted', 'result' => '{}',
    'queue_id' => $job['id'], 'job' => json_encode($job), 'context' => '{}', 'created_at' => time(), 'expires' => time() + 300]);
$connection = GlpiBConnection::find('b');
if (!RestWork::handles($connection, 'Computer', 101)
    || !RestWork::handles(array_replace($connection, ['mode' => 'rest', 'agent_rest_pair' => '']), 'Computer', 101)) {
    throw new RuntimeException('Pending REST record fell through after opt-in/mode change');
}
GlpiBConnection::save(array_replace($connection, ['agent_rest_pair' => $pair['id'], 'agent_rest_url' => 'https://b.invalid/apirest.php']));
$context = (new ReflectionMethod(RestWork::class, 'context'))->invoke(null, $pair);
RestWork::assertSource($pair, $context['source_hash'], $context['asset']);
$lockedAsset = array_replace($context['asset'], ['name' => 'Committed after the consistent snapshot']);
try {
    RestWork::assertSource($pair, $context['source_hash'], $lockedAsset);
    throw new LogicException('Finalizer used the stale consistent snapshot instead of the locked row');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'REST source changed before finalization.') { throw $error; }
}
echo "REST coexistence passed: legacy pull/cleanup exclude v7; pending transport remains fenced; finalization uses the locked source row.\n";
