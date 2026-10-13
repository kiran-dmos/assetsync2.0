<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/agent-recovery.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20agent\TaskJournal;

[$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'First A value', 'Old B value');
TaskJournal::install();
AssetSyncQueue::notify('Computer', 101, 'b');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
$inspect = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
scalarReceipt($request, $token, $inspect, TaskJournal::run($inspect, $settings));
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
$apply = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
$result = TaskJournal::run($apply, $settings);
scalarCheck($result['outcome'] === 'verified' && $result['changed'] !== [], 'B commits a real first mapped change before A consumes the result.');
scalarReceipt($request, $token, $apply, $result);
$DB->update(PluginFieldsComputerscalar::getTable(), ['textfield' => 'Latest A value'], ['items_id' => 101]);
AssetSyncQueue::notify('Computer', 101, 'b');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
$old = AgentTask::outstanding('b');
scalarCheck($old['state'] === 'stale' && json_decode($old['result'], true) === $result && !AgentTask::canRetry($old),
    'A edit between B commit and A consumption retains actual changed evidence in a stale slot.');
$DB->sql->workerLockHeld = true;
$proof = TaskJournal::recoveryProof($apply, $settings);
scalarCheck($proof !== null && $proof['journal'] === 'verified' && $proof['result'] === $result,
    'Exact verified journal permits fresh inspection without waiting for the old expiry.');
$message = $request + ['kind' => 'recovery', 'id' => $old['id'], 'hash' => $old['hash'], 'proof' => $proof];
foreach (['finalizing', 'uncertain', 'partial'] as $state) {
    $DB->update(AgentTask::TABLE, ['state' => $state], ['id' => $old['id']]);
    scalarReject(static fn () => AgentTask::exchange($message, $token), 'Recovery retains interrupted or uncertain A writes: ' . $state);
}
$DB->update(AgentTask::TABLE, ['state' => 'stale'], ['id' => $old['id']]);
$bad = $message;
unset($bad['proof']['result']['snapshot']);
scalarReject(static fn () => AgentTask::exchange($bad, $token), 'Incomplete verified recovery evidence is rejected.');
scalarCheck(AgentTask::exchange($message, $token)['recorded'], 'Complete bound terminal evidence wakes only a fresh chain.');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
$fresh = AgentTask::outstanding('b');
scalarCheck($fresh['id'] !== $old['id'] && json_decode($fresh['body'], true)['kind'] === 'inspect', 'New operation is a fresh read-only inspection.');
for ($step = 0; $step < 2; $step++) {
    $task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
    scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'scalarApplyLocal');
}
scalarCheck(scalarSnapshot($settings)['fields'][scalarKey('textfield')]['value'] === 'Latest A value'
    && AgentTask::outstanding('b') === null, 'Latest source edit converges through a new guarded intent.');
$retained = $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]);
foreach (['body', 'hash', 'result', 'result_received_at'] as $key) {
    scalarCheck($retained[$key] === $old[$key], 'Written predecessor retains original ' . $key);
}
foreach ($result['changed'] as $historyId) {
    scalarCheck($DB->firstRow('glpi_logs', ['id' => $historyId]) !== null, 'Actual first-write History remains intact.');
}
ob_end_clean();
echo "Agent edit race tests passed ($checks checks including recovery prerequisites).\n";
