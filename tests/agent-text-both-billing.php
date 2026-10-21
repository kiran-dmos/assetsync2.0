<?php
declare(strict_types=1);

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\FieldEvidence;
use GlpiPlugin\Assetsync20\RestWork;

ob_start(); require __DIR__ . '/agent-text-both-a.php'; ob_end_clean();
$textChecks = 0;
$pair = textSetup(multi: true); $task = textTask($pair);
$result = textResult($task, ['name' => 'b', 'comment' => 'conflict']); textObserve($task, $result);
$calls = 0;
$billing = static function (array $mappings) use (&$calls): bool {
    global $DB; $calls++;
    textCheck($mappings === [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'both'],
        ['glpi_a_field' => 'comment', 'glpi_b_field' => 'comment', 'source_of_truth' => 'both']], 'Callback receives existing billing mapping shape from validated selected policy, not legacy Both validation.');
    textCheck($DB->firstRow('glpi_computers', ['id' => 101])['name'] === 'Genuine B text', 'Billing sees the fresh incoming value, not the publication snapshot.');
    textCheck(FieldEvidence::envelope(101, 'native:name')['origin'] !== null, 'Billing runs only after inherited receipt qualification.');
    return true;
};
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job, $billing); }
textCheck($calls === 1, 'Known partial Work12 invokes billing-only callback before parking.');
$saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
textCheck($saved['state'] === 'partial' && json_decode($saved['context'], true)['text_billing'] === ['phase' => 'done', 'changed' => true], 'Known partial retains durable billing completion, never full success.');

foreach ([false, true] as $partial) {
    foreach ([false, true] as $writeBeforeFailure) {
        $pair = textSetup(multi: true); $task = textTask($pair);
        $result = textResult($task, ['name' => 'b', 'comment' => $partial ? 'conflict' : 'equal']); textObserve($task, $result);
        $DB->sql->pdo->exec('CREATE TABLE test_billing (value INTEGER, writes INTEGER)');
        $DB->sql->pdo->exec('INSERT INTO test_billing VALUES (0,0)');
        $calls = 0; $fail = true;
        $billing = static function () use (&$calls, &$fail, $writeBeforeFailure, $task): bool {
            global $DB; $calls++;
            $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]); $c = json_decode($saved['context'], true);
            textCheck($c['text_billing'] === ['phase' => 'pending'], 'Pending marker is durable BEFORE callback execution.');
            textCheck(array_unique(array_column($c['text_groups'], 'phase')) === ['done'], 'Every incoming local receipt is settled before billing.');
            textCheck((new ReflectionProperty(FieldEvidence::class, 'origins'))->getValue() === [[]], 'Billing has an explicit no-human/no-inherited-origin scope, not an inbound map.');
            if ($fail && !$writeBeforeFailure) { throw new RuntimeException('Injected billing failure before derived output'); }
            $changed = $DB->sql->pdo->exec('UPDATE test_billing SET value=1,writes=writes+1 WHERE value<>1') === 1;
            if ($fail) { throw new RuntimeException('Injected interruption after derived output, before completion receipt'); }
            return $changed;
        };
        foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job, $billing); }
        $held = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]); $heldContext = json_decode($held['context'], true);
        $history = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
        textCheck($calls === 1 && $heldContext['text_billing']['phase'] === 'pending', 'Callback failure retains unfinished durable billing state.');
        textCheck($held['state'] !== 'partial' && $held['state'] !== 'verified' && $held['slot'] !== null && queue()['status'] !== 'done', 'Failed billing cannot complete or park as replaceable partial.');
        textCheck(!\GlpiPlugin\Assetsync20\TextBoth::knownPartial($held), 'Pending billing is never supersession authority.');
        textCheck(AgentTask::byId('b', $pair['id'])['local_fingerprint'] === $pair['local_fingerprint'], 'Failed billing does not advance the pair fingerprint.');
        $fail = false; textObserve($held, $result); AssetSyncQueue::notify('Computer', 101, 'b');
        foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job, $billing); }
        $after = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]); $c = json_decode($after['context'], true);
        textCheck($calls === 2 && $c['text_billing'] === ['phase' => 'done', 'changed' => true], 'Retry accounts for billing, conservatively retaining recheck after lost callback response.');
        textCheck($after['state'] === ($partial ? 'partial' : 'verified'), 'Settled billing allows exactly the original full/partial outcome.');
        textCheck($DB->sql->pdo->query('SELECT writes FROM test_billing')->fetchColumn() === 1, 'Fresh idempotent billing writes derived output only once.');
        textCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $history, 'Billing retry never repeats inbound model History.');
        textCheck($after['body'] === $held['body'] && $after['hash'] === $held['hash'] && $after['result'] === $held['result']
            && $c['text_groups'] === $heldContext['text_groups'], 'Retry preserves exact original work, remote snapshot and settled local receipt facts.');
    }
}

$pair = textSetup(); $task = textTask($pair); $result = textResult($task, 'b'); textObserve($task, $result);
$DB->base->failReceiptCommit = true; $calls = 0;
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job, static function () use (&$calls): bool { $calls++; return false; }); }
textCheck($calls === 0, 'Unsettled/uncertain inbound receipts never invoke billing.');
echo "Text Both billing: $textChecks checks PASS (host doubles; native SWSD proof separate)\n";
