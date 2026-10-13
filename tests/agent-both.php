<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-scalars.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AgentActivity;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\SyncActivity;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;

function bothSetup(string $a, string $b, int $aEpoch, int $bEpoch): array
{
    global $DB;
    [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'old', 'old');
    $DB->doQuery("ALTER TABLE glpi_computers ADD otherserial TEXT DEFAULT ''");
    $DB->doQuery('ALTER TABLE glpi_computers ADD date_mod TEXT');
    $DB->doQuery('ALTER TABLE glpi_logs ADD date_mod TEXT');
    TaskJournal::install();
    $pair = AgentTask::pair('b');
    $DB->update(AgentTask::PAIRS, ['checked_at' => time() - 100], ['id' => $pair['id']]);
    FieldMapping::save('b', 'Computer', [
        ['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a'],
        ['glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'both'],
    ], AgentTask::catalog('b', 'Computer')['fields']);
    foreach ([101, 201] as $id) { $DB->update('glpi_computers', ['comment' => 'Initial filled value'], ['id' => $id]); }
    bothHuman(101, $a, $aEpoch);
    bothHuman(201, $b, $bEpoch);
    $settings += ['itemtype' => 'Computer', 'items_id' => 201, 'pair_id' => $pair['id']];
    return [$settings, $request, $token];
}

function bothHuman(int $id, string $value, int $epoch): void
{
    $session = $_SESSION;
    $_SESSION['glpiID'] = 2;
    $_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', $epoch);
    (new Computer())->update(['id' => $id, 'comment' => $value]);
    $_SESSION = $session;
}

function bothLocal(array $changes): bool
{
    if ($changes !== []) { (new Computer())->update(['id' => 101] + $changes); }
    return false;
}

function bothInspect(array $settings, array $request, string $token): array
{
    global $DB;
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'bothLocal');
    $legacyPull = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [1, 2, 3, 4, 5]], $token);
    scalarCheck($legacyPull['task'] === null && str_contains($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['last_error'], 'protocol v6'), 'Older B is withheld before any Both execution.');
    $inspect = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [1, 2, 3, 4, 5, 6]], $token)['task'];
    scalarCheck($inspect['version'] === 6 && $inspect['kind'] === 'inspect', 'Both publishes only v6 inspection.');
    $result = TaskJournal::run($inspect, $settings);
    scalarCheck($result['outcome'] === 'unchanged', 'Inspection stays read-only.');
    scalarReceipt($request, $token, $inspect, $result);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'bothLocal');
    return $inspect;
}

function bothApply(array $settings, array $request, string $token, ?callable $local = null): array
{
    $apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [1, 2, 3, 4, 5, 6]], $token)['task'];
    scalarCheck(is_array($apply) && $apply['version'] === 6 && $apply['kind'] === 'apply', 'Both always asserts B under lock before local finalization.');
    $result = TaskJournal::run($apply, $settings);
    scalarCheck(in_array($result['outcome'], ['verified', 'unchanged'], true), 'Guarded B batch completes.');
    scalarReceipt($request, $token, $apply, $result);
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], $local ?? 'bothLocal');
    return [$apply, $result];
}

[$settings, $request, $token] = bothSetup('Browser A edit', 'Older B edit', time() - 10, time() - 20);
$pair = AgentTask::pair('b');
$beforeAudit = AgentTask::context($pair);
Search::$options[77] = ['table' => 'glpi_computers', 'field' => 'date_mod', 'name' => 'Last update'];
Config::$values['core']['timezone'] = 'Asia/Brunei';
$session = $_SESSION;
$_SESSION['glpiID'] = 2;
$_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', $beforeAudit['modification']['epoch']);
$displayTime = (new DateTimeImmutable('@' . $beforeAudit['modification']['epoch']))->setTimezone(new DateTimeZone('Asia/Brunei'))->format('Y-m-d H:i:s');
Log::record(101, 'date_mod', 'old timestamp', $displayTime);
$_SESSION = $session;
$afterAudit = AgentTask::context($pair);
scalarCheck($afterAudit['origin_epoch'] === $beforeAudit['origin_epoch']
    && $afterAudit['modification']['history']['field'] === 'comment'
    && $afterAudit['modification']['history']['id'] > $beforeAudit['modification']['history']['id'],
    'Browser Last update audit cannot hide its genuine current-epoch Comments edit or remove the latest History guard.');
$_SESSION['glpiID'] = 2;
$_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', time() - 20);
$bTime = (new DateTimeImmutable('@' . (time() - 20)))->setTimezone(new DateTimeZone('Asia/Brunei'))->format('Y-m-d H:i:s');
Log::record(201, 'date_mod', 'old timestamp', $bTime);
$_SESSION = $session;
$bAudit = scalarSnapshot($settings, 6);
scalarCheck($bAudit['origin_epoch'] === $bAudit['modification']['epoch'] && $bAudit['modification']['history']['field'] === 'comment',
    'B uses the same actual-author/timezone audit proof as A.');
bothInspect($settings, $request, $token);
bothApply($settings, $request, $token);
scalarCheck($DB->firstRow('glpi_computers', ['id' => 201])['comment'] === 'Browser A edit', 'Trailing timestamp audits preserve the real A-to-B winner.');

foreach (['unknown_field', 'wrong_timestamp', 'different_author', 'wrong_native_value', 'older_native_epoch'] as $case) {
    [$settings, $request, $token] = bothSetup('Browser A edit', 'Older B edit', time() - 10, time() - 20);
    Search::$options[77] = ['table' => 'glpi_computers', 'field' => 'date_mod', 'name' => 'Last update'];
    Config::$values['core']['timezone'] = 'UTC';
    $epoch = AgentTask::context(AgentTask::pair('b'))['modification']['epoch'];
    $nativeLog = $DB->firstRow('glpi_logs', ['items_id' => 101]);
    if ($case === 'wrong_native_value') { $DB->update('glpi_logs', ['new_value' => 'Not current'], ['id' => $nativeLog['id']]); }
    if ($case === 'older_native_epoch') { $DB->update('glpi_logs', ['date_mod' => gmdate('Y-m-d H:i:s', $epoch - 1)], ['id' => $nativeLog['id']]); }
    $session = $_SESSION;
    $_SESSION['glpiID'] = $case === 'different_author' ? 3 : 2;
    $_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', $epoch);
    Log::record(101, 'date_mod', 'old timestamp', $case === 'wrong_timestamp' ? 'invalid' : gmdate('Y-m-d H:i:s', $epoch));
    $_SESSION = $session;
    if ($case === 'unknown_field') {
        $latest = iterator_to_array($DB->request(['FROM' => 'glpi_logs', 'WHERE' => ['items_id' => 101], 'ORDER' => 'id DESC', 'LIMIT' => 1]))[0];
        $DB->update('glpi_logs', ['id_search_option' => 987], ['id' => $latest['id']]);
    }
    scalarCheck(AgentTask::context(AgentTask::pair('b'))['origin_epoch'] === null,
        'Only a proven generated timestamp row can be skipped: ' . $case);
}

[$settings, $request, $token] = bothSetup('A differs', 'B differs', time() - 10, time() - 10);
bothInspect($settings, $request, $token);
$held = AgentTask::outstanding('b');
$context = json_decode($held['context'], true);
$reorder = static function (mixed $value) use (&$reorder): mixed {
    if (!is_array($value)) { return $value; }
    $value = array_map($reorder, $value);
    return array_is_list($value) ? $value : array_reverse($value, true);
};
$DB->update(AgentTask::TABLE, ['context' => json_encode($reorder($context))], ['id' => $held['id']]);
for ($i = 0; $i < 3; ++$i) { AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000); }
scalarCheck(AgentTask::outstanding('b')['id'] === $held['id']
    && $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'blocked',
    'MySQL JSON object key order cannot release a stable read-only conflict or publish new inspections.');
$compare = new ReflectionMethod(AgentTask::class, 'sameLocalContext');
$changed = $context;
$changed['modification']['history']['id']++;
scalarCheck(!$compare->invoke(null, $context, $changed), 'Same-second History changes remain guarded despite JSON order normalization.');
$changed = $context;
$changed['modification']['epoch'] = (string) $changed['modification']['epoch'];
scalarCheck(!$compare->invoke(null, $context, $changed), 'Evidence comparison still rejects changed scalar types.');
$guard = new ReflectionMethod(AgentTask::class, 'sameEvidence');
scalarCheck(!$guard->invoke(null, ['a', 'b'], ['b', 'a']), 'JSON list order remains significant.');

[$settings, $request, $token] = bothSetup('same', 'same', time() - 10, time() - 20);
$inspect = bothInspect($settings, $request, $token);
bothApply($settings, $request, $token);
$legacy = $DB->firstRow(AgentTask::TABLE, ['id' => $inspect['id']]);
$body = json_decode($legacy['body'], true);
$body['version'] = 4;
unset($body['guard']);
$body = scalarHash($body);
$legacy['body'] = json_encode($body);
$legacy['hash'] = $body['hash'];
$legacy['state'] = 'unchanged';
$DB->update(AgentTask::TABLE, array_intersect_key($legacy, array_flip(['body', 'hash', 'state'])), ['id' => $legacy['id']]);
$DB->doQuery('DELETE FROM `' . AgentTask::TABLE . '` WHERE id <> ' . $DB->quote($legacy['id']));
bothHuman(101, 'Human after completed legacy no-op', time());
$context = AgentTask::context(AgentTask::pair('b'));
scalarCheck($context['origin_epoch'] === $context['modification']['epoch'], 'Completed legacy no-op windows cannot label later human edits as own mutations.');
$DB->update(AgentTask::TABLE, ['state' => 'uncertain'], ['id' => $legacy['id']]);
scalarCheck(AgentTask::context(AgentTask::pair('b'))['origin_epoch'] === null, 'Ambiguous legacy mutation windows still block instead of assuming a human winner.');

[$settings, $request, $token] = bothSetup('A newer', 'B older', time() - 10, time() - 20);
$inspect = bothInspect($settings, $request, $token);
$apply = AgentTask::outstanding('b');
$DB->doQuery('DELETE FROM `' . AgentTask::TABLE . '` WHERE id = ' . $DB->quote($apply['id']));
$held = $DB->firstRow(AgentTask::TABLE, ['id' => $inspect['id']]);
// Represent the older reader's retained false conflict; never change the native fixture generation.
$context = json_decode($held['context'], true);
$context['origin_epoch'] = null;
$context['modification']['history']['field'] = '';
$context['field_conflict'] = 'blocked_field_conflict: old audit reader';
$DB->update(AgentTask::TABLE, ['context' => json_encode($reorder($context)), 'state' => 'blocked', 'slot' => 'pair:' . AgentTask::pair('b')['id']], ['id' => $held['id']]);
$retained = $DB->firstRow(AgentTask::TABLE, ['id' => $held['id']]);
$logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
scalarCheck(AgentTask::outstanding('b') === null && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs,
    'A newly proven old audit generation can refresh its read-only false conflict without an asset edit.');
$old = $DB->firstRow(AgentTask::TABLE, ['id' => $held['id']]);
scalarCheck($old['body'] === $retained['body'] && $old['hash'] === $retained['hash'] && $old['result'] === $retained['result']
    && json_decode($old['context'], true)['field_conflict'] === $context['field_conflict'] && $old['created_at'] === $retained['created_at'],
    'False-conflict recovery retains the original ledger body, result, time and reason.');

[$settings, $request, $token] = bothSetup('A differs', 'B older', time() - 10, time() - 20);
$DB->update('glpi_computers', ['date_mod' => gmdate('Y-m-d H:i:s', time() - 5)], ['id' => 101]);
bothInspect($settings, $request, $token);
$held = AgentTask::outstanding('b');
$context = json_decode($held['context'], true);
scalarCheck($context['modification']['history']['epoch'] < $context['modification']['epoch'], 'Reproof regression has older native History than the advanced asset epoch.');
$context['modification']['history']['field'] = '';
$DB->update(AgentTask::TABLE, ['context' => json_encode($reorder($context))], ['id' => $held['id']]);
scalarCheck(AgentTask::context(AgentTask::pair('b'))['origin_epoch'] === null,
    'False-conflict reproof cannot promote older native History to the newer asset epoch.');
$logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
// Recognition changed, so one fresh read may replace the old classification, never a write.
bothInspect($settings, $request, $token);
$blocked = AgentTask::outstanding('b');
for ($i = 0; $i < 3; ++$i) { AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000); }
scalarCheck($blocked['state'] === 'blocked' && AgentTask::outstanding('b')['id'] === $blocked['id']
    && $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'blocked',
    'An older-History false conflict remains blocked and stable after a fresh read.');
$retained = $DB->firstRow(AgentTask::TABLE, ['id' => $held['id']]);
scalarCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs && $retained['body'] === $held['body']
    && $retained['hash'] === $held['hash'] && $retained['result'] === $held['result'],
    'Older-History reproof cannot write an asset or discard original conflict evidence.');

[$settings, $request, $token] = bothSetup('same', 'same', time() - 10, time() - 20);
$inspect = bothInspect($settings, $request, $token);
$snapshot = \GlpiPlugin\Assetsync20agent\ServiceContext::run($settings, static fn () => TaskExecutor::snapshot($settings, 6));
$v4 = $inspect;
$v4['version'] = 4;
unset($v4['guard']);
$v4['identity'] = AgentTask::identity($snapshot);
$evidence = ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot];
$validator = new ReflectionMethod(AgentTask::class, 'validateResult');
$validator->invoke(null, $v4, $evidence);
$v4['identity'] = null;
scalarReject(static fn () => $validator->invoke(null, $v4, $evidence), 'V4 null identity cannot bypass the controller identity contract.');
foreach ([7, 99] as $version) {
    $unknown = $inspect;
    $unknown['version'] = $version;
    $unknown = scalarHash($unknown);
    scalarReject(static fn () => TaskExecutor::validate($unknown, $settings), 'B rejects unknown protocol versions.');
    scalarReject(static fn () => $validator->invoke(null, $unknown, $evidence), 'A rejects unknown protocol result versions.');
}

[$settings, $request, $token] = bothSetup('same', 'same', time() - 10, time() - 20);
$pair = AgentTask::pair('b');
$unavailable = $pair;
$unavailable['id'] = str_repeat('e', 32);
$unavailable['local_id'] = 999;
$unavailable['computer_id'] = 999;
$unavailable['observed_at'] = time();
$DB->update(AgentTask::PAIRS, ['observed_at' => time() - 1], ['id' => $pair['id']]);
$DB->insert(AgentTask::PAIRS, $unavailable);
scalarCheck(AgentTask::catalog('b', 'Computer')['success'], 'An inaccessible newest pair cannot hide fresh metadata on a readable pair.');

[$settings, $request, $token] = bothSetup('same', 'same', time() - 10, time() - 20);
$pair = AgentTask::pair('b');
foreach ([101 => 102, 201 => 202] as $oldId => $newId) {
    $row = $DB->firstRow('glpi_computers', ['id' => $oldId]);
    $row['id'] = $newId;
    $DB->insert('glpi_computers', $row);
    $child = scalarRow($oldId);
    $child['id'] = $newId;
    $child['items_id'] = $newId;
    $DB->insert(PluginFieldsComputerscalar::getTable(), $child);
}
$healthy = array_replace($pair, ['id' => str_repeat('f', 32), 'local_id' => 102, 'computer_id' => 202, 'observed_at' => time() - 3601]);
$DB->insert(AgentTask::PAIRS, $healthy);
$link = $DB->firstRow(AssetSyncLink::TABLE, ['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'b']);
unset($link['id']);
$link['items_id'] = 102;
$link['remote_items_id'] = 202;
$DB->insert(AssetSyncLink::TABLE, $link);
$old = AgentTask::publish($pair, 'inspect', [], null, []);
$DB->update(AgentTask::TABLE, ['state' => 'uncertain'], ['id' => $old['id']]);
$retained = $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]);
$DB->update(AgentTask::PAIRS, ['observed_at' => time() - 3601], ['id' => $pair['id']]);
$html = scalarMappingPage(['connection_id' => 'b', 'itemtype' => 'Computer', 'refresh_agent' => '1']);
$fresh = AgentTask::outstanding('b', $healthy);
scalarCheck($fresh !== null && json_decode($fresh['body'], true)['items_id'] === 202
    && str_contains($html, 'Inspection queued; awaiting B agent.')
    && !str_contains($html, 'GLPI B fields could not be loaded:'),
    'The real refresh form selects an idle confirmed pair and preserves its queued feedback.');
scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]) === $retained, 'Refresh cannot clear or rewrite another pair\'s uncertain task.');
$task = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [1, 2, 3, 4, 5, 6]], $token)['task'];
scalarReceipt($request, $token, $task, TaskJournal::run($task, $settings));
$html = scalarMappingPage();
scalarCheck(AgentTask::catalog('b', 'Computer')['success'] && !str_contains($html, 'name="save" value="1" class="submit" disabled'),
    'Normal B task/result delivery enables Save without a backend prime.');
$DB->update(AgentTask::TABLE, ['slot' => 'pair:' . $healthy['id'], 'state' => 'uncertain'], ['id' => $task['id']]);
$html = scalarMappingPage(['connection_id' => 'b', 'itemtype' => 'Computer', 'refresh_agent' => '1']);
scalarCheck(str_contains($html, 'No readable confirmed pair is available for inspection.')
    && $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]) === $retained,
    'An unavailable refresh reports its actual cause without replacing outstanding work.');

foreach ([['A newest', 'B old', true], ['A old', 'B newest', false], ['', 'B filled', true], ['A filled', '', false]] as [$a, $b, $aWins]) {
    [$settings, $request, $token] = bothSetup($a, $b, time() - ($aWins ? 10 : 20), time() - ($aWins ? 20 : 10));
    bothInspect($settings, $request, $token);
    [$apply, $result] = bothApply($settings, $request, $token);
    $expected = $aWins ? $a : $b;
    foreach ([101, 201] as $id) {
        $row = $DB->firstRow('glpi_computers', ['id' => $id]);
        scalarCheck($row['comment'] === $expected && $row['name'] === 'Original', 'Newest real native instant wins including blank; Name stays unchanged.');
    }
    scalarCheck(AgentTask::outstanding('b') === null && $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'done', 'Both chain converges.');
    $journal = $DB->firstRow(TaskJournal::TABLE, ['id' => $apply['id']]);
    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    scalarCheck(TaskJournal::run($apply, $settings) === $result && $DB->firstRow(TaskJournal::TABLE, ['id' => $apply['id']]) === $journal, 'V6 result/provenance replay is immutable.');
    bothInspect($settings, $request, $token);
    [, $noop] = bothApply($settings, $request, $token);
    scalarCheck($noop['outcome'] === 'unchanged' && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Equal consensus repeats without duplicate model writes or History.');
}

foreach ([true, false] as $aWins) {
    $aEpoch = time() - ($aWins ? 10 : 20);
    $bEpoch = time() - ($aWins ? 20 : 10);
    [$settings, $request, $token] = bothSetup('A comment', 'B comment', $aEpoch, $bEpoch);
    FieldMapping::save('b', 'Computer', [
        ['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'both'],
        ['glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'both'],
    ], AgentTask::catalog('b', 'Computer')['fields']);
    $session = $_SESSION;
    $_SESSION['glpiID'] = 2;
    foreach ([101 => ['A name', $aEpoch], 201 => ['B name', $bEpoch]] as $id => [$value, $epoch]) {
        $_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', $epoch);
        (new Computer())->update(['id' => $id, 'name' => $value]);
    }
    $_SESSION = $session;
    bothInspect($settings, $request, $token);
    bothApply($settings, $request, $token);
    foreach ([101, 201] as $id) {
        $row = $DB->firstRow('glpi_computers', ['id' => $id]);
        scalarCheck($row['name'] === ($aWins ? 'A name' : 'B name') && $row['comment'] === ($aWins ? 'A comment' : 'B comment'),
            'Name and Comments Both keep their captured asset decision through both model writes.');
    }
}

foreach (['equal_timestamp', 'missing', 'invalid', 'unsupported_schema', 'legacy'] as $case) {
    [$settings, $request, $token] = bothSetup('A differs', 'B differs', time() - 10, time() - ($case === 'equal_timestamp' ? 10 : 20));
    if ($case === 'missing') { $DB->update('glpi_computers', ['date_mod' => null], ['id' => 101]); }
    if ($case === 'invalid') { $DB->update('glpi_computers', ['date_mod' => 'invalid'], ['id' => 101]); }
    if ($case === 'unsupported_schema') { $DB->nativeTimestampType = 'datetime'; }
    if ($case === 'legacy') {
        $_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', time() - 10);
        (new Computer())->update(['id' => 101, 'comment' => 'Legacy own mutation']);
        unset($_SESSION['glpi_currenttime']);
    }
    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    $inspect = bothInspect($settings, $request, $token);
    $held = AgentTask::outstanding('b');
    scalarCheck($held['state'] === 'blocked' && isset(json_decode($held['context'], true)['field_conflict'])
        && str_contains($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['last_error'], 'blocked_field_conflict'), 'Ambiguous difference is a visible field conflict: ' . $case);
    scalarCheck($DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'blocked'
        && AssetSyncLink::find('Computer', 101, 'b')['status'] === AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT,
        'Completed read-only conflict is blocked rather than waiting for executable B work.');
    $queue = $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101]);
    scalarCheck(SyncActivity::currentState($queue) === 'blocked'
        && str_contains(SyncActivity::reason($queue + ['link_status' => AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT]), 'values differ'),
        'Current Work reports a plain-English blocked field conflict.');
    ob_start();
    AgentActivity::renderTasks([100], static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES), 'b');
    $html = ob_get_clean();
    scalarCheck(str_contains($html, 'Field conflict: values differ') && !str_contains($html, 'blocked_field_conflict:'),
        'Agent Tasks explains the conflict without exposing raw exceptions.');
    AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
    scalarCheck(AgentTask::outstanding('b')['id'] === $held['id'] && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Conflict does not loop or write without fresh work.');
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'bothLocal');
    scalarCheck(AgentTask::outstanding('b')['id'] === $held['id']
        && $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'blocked',
        'A repeated unchanged notification cannot relabel a known conflict as waiting for B.');
    $DB->nativeTimestampType = 'timestamp';
    bothHuman(101, 'Fresh human resolves ' . $case, time());
    AssetSyncQueue::notify('Computer', 101, 'b');
    AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
    scalarCheck(AgentTask::outstanding('b') === null && $DB->firstRow(AgentTask::TABLE, ['id' => $held['id']])['result'] === $held['result'], 'Fresh human evidence releases only read-only conflict while preserving old evidence.');
    bothInspect($settings, $request, $token);
    bothApply($settings, $request, $token);
    scalarCheck($DB->firstRow('glpi_computers', ['id' => 201])['comment'] === 'Fresh human resolves ' . $case, 'Fresh inspection resolves retained conflict.');
}

foreach (['a_without_hook', 'b_notification'] as $case) {
    [$settings, $request, $token] = bothSetup('A differs', 'B differs', time() - 10, time() - 10);
    bothInspect($settings, $request, $token);
    $held = AgentTask::outstanding('b');
    bothHuman($case === 'a_without_hook' ? 101 : 201, 'Fresh edit resolves conflict', time());
    if ($case === 'b_notification') {
        $ack = AgentInbox::receive($request + ['computer_id' => 201, 'itemtype' => 'Computer', 'revision' => 1, 'lifecycle' => 'present'], $token);
        scalarCheck($ack['status'] === 200, 'A genuine B revision is admitted without completing it early.');
    }
    AgentTask::recoverStaleInspections(hrtime(true) + 1_000_000_000);
    scalarCheck(AgentTask::outstanding('b') === null
        && $DB->firstRow(AssetSyncQueue::TABLE, ['items_id' => 101])['status'] === 'pending',
        'Fresh evidence wakes a blocked queue even when the A hook was missed: ' . $case);
    bothInspect($settings, $request, $token);
    bothApply($settings, $request, $token);
    scalarCheck($DB->firstRow('glpi_computers', ['id' => 101])['comment'] === 'Fresh edit resolves conflict'
        && $DB->firstRow('glpi_computers', ['id' => 201])['comment'] === 'Fresh edit resolves conflict'
        && $DB->firstRow(AgentTask::TABLE, ['id' => $held['id']])['result'] === $held['result'],
        'Conflict refresh converges while retaining original evidence: ' . $case);
}

[$settings, $request, $token] = bothSetup('same', 'same', time() - 10, time() - 20);
$DB->update('glpi_computers', ['date_mod' => null], ['id' => 101]);
$logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
bothInspect($settings, $request, $token);
bothApply($settings, $request, $token);
scalarCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Equal normalized values need no timestamp.');

[$settings, $request, $token] = bothSetup('same', 'same', time() - 10, time() - 20);
$DB->update('glpi_computers', ['date_mod' => 'invalid-before'], ['id' => 201]);
bothInspect($settings, $request, $token);
$apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token)['task'];
$DB->update('glpi_computers', ['date_mod' => 'invalid-after'], ['id' => 201]);
scalarCheck(TaskJournal::run($apply, $settings) === ['outcome' => 'stale', 'changed' => []], 'Unknown timestamp literals are fenced, never parsed or ignored when they change.');

[$settings, $request, $token] = bothSetup('A newest', 'B old', time() - 10, time() - 20);
bothInspect($settings, $request, $token);
$apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token)['task'];
$commits = 0;
$DB->sql->beforeQuery = static function (string $sql) use ($DB, &$commits): void {
    if ($sql !== 'COMMIT' || ++$commits !== 2) { return; }
    $DB->sql->beforeQuery = null;
    $DB->sql->pdo->commit();
    throw new RuntimeException('Lost successful commit reply');
};
$committed = TaskJournal::run($apply, $settings);
scalarCheck($committed['outcome'] === 'verified' && $DB->firstRow('glpi_computers', ['id' => 201])['comment'] === 'A newest'
    && json_decode($DB->firstRow(TaskJournal::TABLE, ['id' => $apply['id']])['result'], true) === $committed,
    'V6 reports actual atomically committed provenance after a lost commit reply.');
$logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
scalarCheck(TaskJournal::run($apply, $settings) === $committed && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs,
    'Lost commit reply cannot cause duplicate model writes or conflicting reports.');

[$settings, $request, $token] = bothSetup('A newest', 'B old', time() - 10, time() - 20);
bothInspect($settings, $request, $token);
$apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token)['task'];
$DB->update('glpi_computers', ['date_mod' => gmdate('Y-m-d H:i:s')], ['id' => 201]);
$logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
$stale = TaskJournal::run($apply, $settings);
scalarCheck($stale === ['outcome' => 'stale', 'changed' => []] && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Timestamp-only B edit invalidates v6 even without a value change.');

[$settings, $request, $token] = bothSetup('A old', 'B newest', time() - 20, time() - 10);
bothInspect($settings, $request, $token);
$apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token)['task'];
$DB->update('glpi_computers', ['date_mod' => gmdate('Y-m-d H:i:s')], ['id' => 201]);
$stale = TaskJournal::run($apply, $settings);
scalarReceipt($request, $token, $apply, $stale);
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'bothLocal');
scalarCheck($stale['outcome'] === 'stale' && $DB->firstRow('glpi_computers', ['id' => 101])['comment'] === 'A old', 'B-winning no-op assertion also rejects timestamp-only changes before A writes.');

foreach (['dispatch', 'finalize'] as $case) {
    [$settings, $request, $token] = bothSetup('A old', 'B newest', time() - 20, time() - 10);
    bothInspect($settings, $request, $token);
    if ($case === 'dispatch') {
        $DB->update('glpi_computers', ['date_mod' => gmdate('Y-m-d H:i:s')], ['id' => 101]);
        $pull = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token);
        scalarCheck($pull['task'] === null && AgentTask::outstanding('b')['state'] === 'stale', 'Timestamp-only A edit invalidates v6 before B dispatch.');
    } else {
        $DB->sql->beforeQuery = static function (string $sql) use ($DB): void {
            if (!str_starts_with($sql, 'SELECT `id` FROM `glpi_computers` WHERE `id` = 101') || !str_ends_with($sql, 'FOR UPDATE')) { return; }
            $DB->sql->beforeQuery = null;
            $DB->update('glpi_computers', ['date_mod' => gmdate('Y-m-d H:i:s')], ['id' => 101]);
        };
        bothApply($settings, $request, $token);
        scalarCheck(AgentTask::outstanding('b')['state'] === 'uncertain' && $DB->firstRow('glpi_computers', ['id' => 101])['comment'] === 'A old', 'A timestamp guard is rechecked under finalization lock.');
    }
}

[$settings, $request, $token] = bothSetup('A newest', 'B old', time() - 10, time() - 20);
$pair = AgentTask::pair('b');
FieldMapping::save('b', 'Computer', [
    ['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a'],
    ['glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'both'],
    ['glpi_a_field_key' => scalarKey('datefield'), 'glpi_b_field_key' => scalarKey('datefield'), 'source_of_truth' => 'glpi_a'],
], AgentTask::catalog('b', 'Computer')['fields']);
$DB->update(PluginFieldsComputerscalar::getTable(), ['datefield' => ''], ['items_id' => 101]);
$DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 3]);
$DB->update('glpi_computers', ['name' => 'A name must not be partially written'], ['id' => 101]);
bothInspect($settings, $request, $token);
$apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token)['task'];
$logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
$blocked = TaskJournal::run($apply, $settings);
scalarCheck($blocked === ['outcome' => 'blocked', 'changed' => []] && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original'
    && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'Entire batch is prevalidated before first Name or Both write.');

[$settings, $request, $token] = bothSetup('A newest', 'B old', time() - 10, time() - 20);
bothInspect($settings, $request, $token);
$apply = AgentTask::exchange($request + ['kind' => 'pull', 'task_versions' => [6]], $token)['task'];
$result = TaskJournal::run($apply, $settings);
$DB->sql->workerLockHeld = true;
$proof = TaskJournal::recoveryProof($apply, $settings);
scalarCheck($proof['journal'] === 'verified' && $proof['result'] === $result, 'V6 apply produces independently retained recovery proof.');
$saved = $DB->firstRow(AgentTask::TABLE, ['id' => $apply['id']]);
$DB->update(AgentTask::TABLE, ['state' => 'stale'], ['id' => $apply['id']]);
$ack = AgentTask::exchange($request + ['kind' => 'recovery', 'id' => $apply['id'], 'hash' => $apply['hash'], 'proof' => $proof], $token);
scalarCheck($ack['recorded'], 'A accepts complete v6 apply recovery evidence.');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], 'bothLocal');
$replacement = AgentTask::outstanding('b');
scalarCheck(json_decode($replacement['body'], true)['version'] === 6 && json_decode($replacement['body'], true)['kind'] === 'inspect'
    && $DB->firstRow(AgentTask::TABLE, ['id' => $apply['id']])['body'] === $saved['body'], 'Recovery replaces only through fresh v6 inspection and preserves old task body/hash.');

[$settings, $request, $token] = bothSetup('A newest', 'B old', time() - 10, time() - 20);
bothInspect($settings, $request, $token);
[, $written] = bothApply($settings, $request, $token);
$target = $settings;
$before = \GlpiPlugin\Assetsync20agent\ServiceContext::run($target, static fn () => TaskExecutor::snapshot($target, 6));
scalarCheck($before['origin_epoch'] === $written['mutation']['origin_epoch'] && $before['origin_epoch'] < $before['modification']['epoch'], 'B sync echo retains the real comparison origin.');
bothHuman(201, 'Fresh same-second B edit', $before['modification']['epoch']);
$after = \GlpiPlugin\Assetsync20agent\ServiceContext::run($target, static fn () => TaskExecutor::snapshot($target, 6));
scalarCheck($after['origin_epoch'] === $before['modification']['epoch'] && $after['modification']['history']['id'] > $before['modification']['history']['id'], 'B same-second human edit is established by real native History.');
$session = $_SESSION;
$_SESSION['glpiID'] = 7;
$_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', $before['modification']['epoch']);
(new Computer())->update(['id' => 201, 'comment' => 'Same configured user, fresh actual History']);
$_SESSION = $session;
$sameAuthor = \GlpiPlugin\Assetsync20agent\ServiceContext::run($target, static fn () => TaskExecutor::snapshot($target, 6));
scalarCheck($sameAuthor['origin_epoch'] === $before['modification']['epoch'], 'Actual newer native History, rather than an invented author distinction, qualifies same-second edits under the configured B user.');

[$settings, $request, $token] = bothSetup('A before own copy', 'B genuine newer real edit', time() - 20, time() - 5);
$pair = AgentTask::pair('b');
$session = $_SESSION;
$_SESSION['glpiID'] = 2;
$_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', time() - 1);
(new Computer())->update(['id' => 101, 'comment' => 'Legacy copied value']);
$_SESSION = $session;
$legacy = ['version' => 3, 'id' => bin2hex(random_bytes(16)), 'kind' => 'inspect', 'connection_id' => 'b', 'pair_id' => $pair['id'],
    'itemtype' => 'Computer', 'items_id' => 201, 'entity_id' => 100, 'registration' => $settings['registration'], 'installation' => $settings['installation']];
$legacyContext = ['values' => ['comment' => 'A before own copy'], 'mappings' => [
    ['glpi_a_field' => 'comment', 'glpi_b_field' => 'comment', 'source_of_truth' => 'glpi_b']]];
$legacyResult = ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => ['fields' => ['comment' => ['value' => 'Legacy copied value']]]];
$DB->insert(AgentTask::TABLE, ['id' => $legacy['id'], 'connection_id' => 'b', 'slot' => null, 'hash' => AgentTask::hash($legacy), 'body' => json_encode($legacy),
    'state' => 'verified', 'result' => json_encode($legacyResult), 'queue_id' => 1, 'job' => '[]', 'context' => json_encode($legacyContext), 'created_at' => time() - 30, 'expires' => time() - 1]);
$context = AgentTask::context($pair);
scalarCheck($context['origin_epoch'] === null && $context['modification']['epoch'] > time() - 5, 'Legacy own mutation after pair check cannot become a real winner even under a real administrator author.');
bothInspect($settings, $request, $token);
scalarCheck(AgentTask::outstanding('b')['state'] === 'blocked' && $DB->firstRow('glpi_computers', ['id' => 201])['comment'] === 'B genuine newer real edit', 'Legacy own timestamp newer than B human edit blocks instead of overwriting B.');

[$settings, $request, $token] = bothSetup('Before legacy cron', 'B actual human', time() - 20, time() - 10);
$session = $_SESSION;
$_SESSION['glpicronuserrunning'] = 'cron_' . \GlpiPlugin\Assetsync20\AssetSyncCron::TASK_NAME;
$_SESSION['glpi_currenttime'] = gmdate('Y-m-d H:i:s', time() - 1);
(new Computer())->update(['id' => 101, 'comment' => 'Legacy cron copy']);
$_SESSION = $session;
$context = AgentTask::context(AgentTask::pair('b'));
scalarCheck(!Session::isCron() && $context['origin_epoch'] === null && $context['modification']['epoch'] > time() - 10,
    'Actual GLPI sync cron author is recognized even from a later non-cron caller and without a complete old task anchor.');
bothInspect($settings, $request, $token);
scalarCheck(AgentTask::outstanding('b')['state'] === 'blocked', 'Newer unanchored sync cron timestamp never wins a native Both difference.');

foreach (['{"shape":[1]}', '["shape",2]', str_repeat('x', 300)] as $value) {
    [$settings, $request, $token] = bothSetup($value, 'older', time() - 10, time() - 20);
    $context = AgentTask::context(AgentTask::pair('b'));
    scalarCheck($context['origin_epoch'] === time() - 10 && $context['modification']['history']['field'] === 'comment', 'A uses official JSON-removal/truncation History semantics.');
    bothInspect($settings, $request, $token);
    [, $result] = bothApply($settings, $request, $token);
    scalarCheck($result['snapshot']['fields']['comment']['value'] === $value && $result['snapshot']['origin_epoch'] === $context['origin_epoch'], 'Native Comments raw History normalization agrees independently on A and B.');
}

[$settings, $request, $token] = bothSetup('A old', 'B newest', time() - 20, time() - 10);
bothInspect($settings, $request, $token);
bothApply($settings, $request, $token, static function (array $changes): bool {
    bothLocal($changes);
    (new Computer())->update(['id' => 101, 'name' => 'Own billing-shaped native write']);
    return true;
});
$context = AgentTask::context(AgentTask::pair('b'));
scalarCheck($context['origin_epoch'] < $context['modification']['epoch'], 'A own inbound/billing mutation inherits the pinned real origin.');
$sameSecond = $context['modification']['epoch'];
bothHuman(101, 'Fresh same-second A edit', $sameSecond);
$fresh = AgentTask::context(AgentTask::pair('b'));
scalarCheck($fresh['origin_epoch'] === $sameSecond && $fresh['modification']['history']['id'] > $context['modification']['history']['id'], 'Actual later native History distinguishes same-second human edit from own mutation.');

ob_end_clean();
echo "Agent Both tests passed ($checks checks including scalar prerequisites; host doubles/SQLite, live qualification separate).\n";
