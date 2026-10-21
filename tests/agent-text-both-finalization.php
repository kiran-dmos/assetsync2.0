<?php
declare(strict_types=1);

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\FieldEvidence;
use GlpiPlugin\Assetsync20\RestWork;

ob_start(); require __DIR__ . '/agent-text-both-a.php'; ob_end_clean();
$textChecks = 0;
$selected = $argv[1] ?? '';
foreach (['equal', 'conflict', 'a', 'b'] as $winner) {
    $pair = textSetup(); $task = textTask($pair); $result = textResult($task, $winner);
    textObserve($task, $result);
    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    $pin = \GlpiPlugin\Assetsync20\FieldsText::selectedTextPin('name'); $nonce = bin2hex(random_bytes(16));
    $selection = RestWork::requestCatalog('b', 100, ['kind' => 'selected', 'capture_keys' => ['native:name']], $nonce);
    $selectedResult = ['outcome' => 'observed', 'entity_id' => 100, 'fields' => [['capture_key' => 'native:name', 'descriptor' => $pin, 'reason' => null]],
        'next_after_id' => null, 'complete' => true, 'observed_at' => time()];
    $DB->update(AgentTask::TABLE, ['state' => 'observed', 'result' => json_encode($selectedResult), 'slot' => null], ['id' => $selection]);
    \GlpiPlugin\Assetsync20\FieldMapping::savePinnedText('b', 'Computer', [['glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'both']], 100, $selection, $nonce);
    RestDispatchWire::reset(); RestWork::beginRun(); publishPolicy();
    textJobs(); $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
    $noGroups = in_array($winner, ['equal', 'conflict'], true);
    textCheck($noGroups ? $saved['state'] === 'partial' : $saved['state'] !== 'partial', 'Policy change retires only an accounted receipt with NO write groups: ' . $winner . '; ' . queue()['last_error']);
    textCheck($saved['slot'] !== null && queue()['status'] !== 'done', 'Policy change never claims completion or erases the retained slot.');
    textCheck($saved['body'] === $task['body'] && $saved['hash'] === $task['hash'] && $saved['result'] === json_encode($result), 'Policy change retains exact original work and received B facts.');
    textCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs && AgentTask::byId('b', $pair['id'])['local_fingerprint'] === $pair['local_fingerprint'], 'Policy retirement makes no model/History write or verified fingerprint.');
    if ($noGroups) {
        $context = json_decode($saved['context'], true);
        textCheck($context['rest_next'] === 0, 'Accounted no-write policy replacement is immediately due.');
        RestDispatchWire::reset(); RestWork::sendPending(hrtime(true) + 20_000_000_000); textJobs();
        $next = AgentTask::outstanding('b', $pair); $old = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
        textCheck($next['id'] !== $task['id'] && $old['state'] === 'superseded' && $old['result'] === $saved['result'], 'Ordinary claim replaces known no-write policy work while preserving the old receipt.');
    }
}
if (!in_array($selected, ['--settlement','--settings'], true)) {
    foreach (['a', 'b'] as $winner) {
        $pair = textSetup(); $task = textTask($pair); $result = textResult($task, $winner);
        textObserve($task, $result);
        $fired = false;
        $DB->beforeFinalContext = static function () use (&$fired): void {
            global $DB;
            $fired = true; $DB->base->clock = 800;
            $item = new Computer(); $item->getFromDB(101);
            $item->update(['id' => 101, 'name' => 'Human edit between completion reads']); FieldEvidence::finishWork();
        };
        textJobs();
        $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
        textCheck($fired, 'Final recapture race was actually injected after the earlier semantic checks.');
        textCheck($saved['state'] !== 'verified' && $saved['slot'] !== null && queue()['status'] !== 'done', 'Final locked evidence must still match expected original/committed facts: ' . $winner);
        textCheck(AgentTask::byId('b', $pair['id'])['local_fingerprint'] === $pair['local_fingerprint'], 'A racing human edit cannot become the verified pair fingerprint.');
        textCheck($DB->firstRow('glpi_computers', ['id' => 101])['name'] === 'Human edit between completion reads', 'Finalization never overwrites the racing human edit.');
        textCheck($saved['body'] === $task['body'] && $saved['hash'] === $task['hash'] && $saved['result'] === json_encode($result), 'Race preserves original body/hash and B receipt.');
    }
}
if (!in_array($selected, ['--race','--settings'], true)) {
    foreach ([false, true] as $laterLocalEdit) {
        $pair = textSetup(); $task = textTask($pair); $result = textResult($task, 'b'); textObserve($task, $result);
        $DB->base->failReceiptCommit = true; textJobs();
        $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
        $c = json_decode($saved['context'], true); $local = array_values($c['text_groups'])[0];
        textCheck($local['phase'] === 'origin_pending', 'Lost COMMIT response leaves the exact durable local receipt.');
        $receipts = $local['receipts']; $DB->base->failSettlement = false;
        if ($laterLocalEdit) {
            $DB->base->clock = 900; $item = new Computer(); $item->getFromDB(101);
            $item->update(['id' => 101, 'name' => 'Later local edit still held']); FieldEvidence::finishWork();
        }
        $logs = (int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn();
        textObserve($saved, $result, false); AssetSyncQueue::notify('Computer', 101, 'b'); textJobs();
        $after = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
        $context = json_decode($after['context'], true); $local = array_values($context['text_groups'])[0];
        textCheck($local['phase'] === ($laterLocalEdit ? 'origin_pending' : 'done'), 'Local settlement is independent of later B current mismatch, but a local mismatch stays held.');
        textCheck($laterLocalEdit ? $after['state'] !== 'partial' : $after['state'] === 'partial', 'A settled local receipt plus changed B becomes known partial, not false verified.');
        textCheck($after['slot'] !== null && queue()['status'] !== 'done', 'Remote mismatch never claims full reconciliation.');
        textCheck($local['receipts'] === $receipts && $after['body'] === $task['body'] && $after['result'] === $saved['result'], 'Recovery preserves original durable receipts, work and B snapshot.');
        textCheck((int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn() === $logs, 'Metadata-only recovery produces no duplicate model write or History.');
    }
}
// Cross the real A serializer/B decoder boundary instead of hand-authoring B's preferred key order.
require_once __DIR__ . '/../assetsync20agent/src/autoload.php';
if (!class_exists('CommonDBTM', false)) { class CommonDBTM {} }
$pair = textSetup(multi: true); $policy = settingsBody();
$decode = static fn (array $value): array => \GlpiPlugin\Assetsync20agent\RestSettings::decode(json_encode($value, JSON_THROW_ON_ERROR), AgentTask::hash($value));
textCheck($decode($policy) === $policy, 'B decodes the actual A-produced Settings5 header without changing its body or hash.');
textReject(static fn () => $decode($policy + ['unknown' => true]), 'Settings5 still rejects unknown header members.');
$missing = $policy; unset($missing['enabled']);
textReject(static fn () => $decode($missing), 'Settings5 still rejects missing header members.');
textReject(static fn () => \GlpiPlugin\Assetsync20agent\RestSettings::decode(json_encode($policy), str_repeat('0',64)), 'Settings5 exact input hash remains mandatory.');
$duplicate = substr(json_encode($policy),0,-1) . ',"enabled":true}';
textReject(static fn () => \GlpiPlugin\Assetsync20agent\RestSettings::decode($duplicate,AgentTask::hash($policy)), 'Settings5 duplicate JSON members remain forbidden.');
$route = \GlpiPlugin\Assetsync20\EntitySyncRoute::find($pair['route_id']);
\GlpiPlugin\Assetsync20\EntitySyncRoute::save(array_replace($route,['active'=>false]));
$disabled = settingsBody();
textCheck($disabled['version'] === 5 && !$disabled['enabled'] && $disabled['mappings'] === [], 'A emits an empty Settings5 disable tombstone without downgrading its version.');
textCheck($decode($disabled) === $disabled, 'B accepts the actual A-generated disabled Settings5 tombstone.');
echo "Text Both finalization: $textChecks checks PASS (host model/SQL doubles; native race proof separate)\n";
