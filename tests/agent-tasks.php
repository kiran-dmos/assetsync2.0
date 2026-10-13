<?php

declare(strict_types=1);

require __DIR__ . '/agent-prototype.php';
if (!defined('READ')) { define('READ', 1); }
class Session
{
    public static function haveAccessToEntity($entity): bool { return true; }
}
class AgentSearchOptions extends Search
{
    public static function clearSearchOptionCache($type): void {}
}
class_alias(AgentSearchOptions::class, 'Glpi\\Search\\SearchOption');

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20agent\TaskJournal;
use GlpiPlugin\Assetsync20agent\TaskExecutor;

function taskSetup(): array
{
    global $DB;
    agentSetup();
    agentCheck(AgentTask::install() && TaskJournal::install(), 'Additive durable schemas install.');
    GlpiBConnection::save(['id' => 'b', 'mode' => 'agent', 'base_url' => 'https://b.invalid', 'active' => true]);
    $credentials = AgentTask::declare('b', str_repeat('a', 32), 101, 201, 100);
    $request = ['kind' => 'pull', 'registration' => $credentials['registration'], 'generation' => $credentials['generation'],
        'installation' => str_repeat('a', 32), 'connection_id' => 'b'];
    $task = AgentTask::exchange($request, $credentials['token'])['task'];
    $snapshot = ['pair_id' => $task['pair_id'], 'itemtype' => 'Computer', 'items_id' => 201, 'entity_id' => 100, 'serial' => 'AGENT-101', 'observed_at' => time(),
        'fields' => ['name' => ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'group' => 'Native', 'option' => 1,
            'metadata' => str_repeat('b', 64), 'value' => 'Original']]];
    $result = ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot];
    AgentTask::exchange(array_replace($request, ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'], 'result' => $result]), $credentials['token']);
    AgentTask::confirm('b', 'route');
    FieldMapping::save('b', 'Computer', [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a']], AgentTask::catalog('b', 'Computer')['fields']);
    return [$credentials, $request, $snapshot];
}

function queueTask(): array
{
    AssetSyncQueue::notify('Computer', 101, 'b');
    $job = AssetSyncQueue::claimDue(1)[0];
    $pair = AgentTask::pair('b');
    $task = AgentTask::publish($pair, 'inspect', [], $job, AgentTask::context($pair));
    return [$job, $task];
}

[$credentials, $request, $snapshot] = taskSetup();
$connection = GlpiBConnection::find('b');
agentCheck($connection['mode'] === 'agent' && $connection['app_token'] === '' && $connection['user_token'] === '', 'Agent mode stores no B REST tokens.');
foreach ([['getItem', [$connection, 'Computer', 201]], ['searchBySerial', [$connection, 'Computer', 'x']],
    ['createItem', [$connection, 'Computer', []]], ['updateItem', [$connection, 'Computer', 201, []]],
    ['uuidSnapshot', [$connection, 'Computer', 201, 100]], ['nativeMappingContext', [$connection, 'Computer', [], 100]],
    ['nativeStateCatalog', [$connection, 'Computer', [], 0, [], []]], ['customTextValues', [$connection, 'Computer', 201, []]],
    ['customHistoryDates', [$connection, 'Computer', 201, []]]] as [$method, $arguments]) {
    try { GlpiBConnection::$method(...$arguments); throw new LogicException('REST fallback accepted: ' . $method); }
    catch (RuntimeException $error) { agentCheck(str_contains($error->getMessage(), 'REST is disabled'), 'Public remote path rejects agent mode before REST: ' . $method); }
}
agentCheck(AgentTask::catalog('b', 'Computer')['success'], 'Pair inspection bootstraps mapping catalog.');
foreach (['uuid', 'serial', 'otherserial', 'states_id'] as $field) {
    try { AgentTask::validateMappings('Computer', [['glpi_a_field_key' => $field, 'glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a']]); throw new LogicException('Unsupported native accepted'); }
    catch (RuntimeException) { agentCheck(true, 'Unsupported native mapping blocked before UUID filtering.'); }
}
foreach (['both', 'invalid'] as $source) {
    try { AgentTask::validateMappings('Computer', [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => $source]]); throw new LogicException('Unsupported authority accepted'); }
    catch (RuntimeException) { agentCheck(true, 'Unsupported authority blocked.'); }
}
$participants = (new ReflectionMethod(\GlpiPlugin\Assetsync20\AssetUuidService::class, 'participants'))->invoke(
    new \GlpiPlugin\Assetsync20\AssetUuidService(), 'Computer', 101, ['entities_id' => 1], []);
agentCheck($participants['outcome'] === 'unsupported_agent', 'UUID participation explicitly blocked before any remote request.');

[$job, $task] = queueTask();
agentCheck(queue()['status'] === 'waiting_agent' && queue()['claim_token'] === '' && AssetSyncQueue::claimDue(1) === [], 'Offline B releases the claim and is never ordinary due work.');
$before = queue();
AssetSyncQueue::notify('Computer', 101, 'b');
$event = ['registration' => $credentials['registration'], 'generation' => $credentials['generation'], 'computer_id' => 201, 'revision' => 1, 'lifecycle' => 'present'];
AgentInbox::receive($event, $credentials['token']);
agentCheck(queue()['status'] === 'waiting_agent' && queue()['agent_completed'] === 0 && queue()['agent_requested'] === 1
    && queue()['needs_recheck'] === 1 && AssetSyncQueue::claimDue(1) === [], 'Notifications preserve waiting and outstanding revisions.');
$resultRequest = array_replace($request, ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'],
    'result' => ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot]]);
$DB->failOn = 'COMMIT';
try { AgentTask::exchange($resultRequest, $credentials['token']); throw new LogicException('Commit failure ACK'); } catch (RuntimeException) {}
$DB->failOn = null;
agentCheck(AgentTask::outstanding('b')['result'] === '{}' && queue()['status'] === 'waiting_agent', 'Failed result COMMIT persists neither result nor wake.');
AgentTask::exchange($resultRequest, $credentials['token']);
$newClaim = AssetSyncQueue::claimDue(1)[0];
agentCheck($newClaim['claim_token'] !== $job['claim_token'] && queue()['agent_completed'] === 0, 'Result wakes a fresh claim without consuming captured revisions.');
AgentTask::exchange($resultRequest, $credentials['token']);
agentCheck(queue()['claim_token'] === $newClaim['claim_token'] && queue()['status'] === 'running', 'Duplicate old result cannot wake or complete a newer claim.');
$DB->update(AgentTask::TABLE, ['state' => 'finalizing'], ['id' => $task['id']]);
$called = false;
AgentTask::process($newClaim, static function () use (&$called): void { $called = true; });
agentCheck(!$called && AgentTask::outstanding('b')['state'] === 'uncertain' && queue()['status'] === 'waiting_agent', 'Crash after durable finalizing becomes uncertain even with a saved result.');

[$credentials, $request, $snapshot] = taskSetup();
[$job, $task] = queueTask();
// Reproduce a result racing after the worker saw an empty result but before it parked.
$DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => $job['id']]);
$claim = AssetSyncQueue::claimDue(1)[0];
$staleView = AgentTask::outstanding('b');
AgentTask::exchange(array_replace($request, ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'],
    'result' => ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot]]), $credentials['token']);
(new ReflectionMethod(AgentTask::class, 'park'))->invoke(null, $claim, $staleView);
agentCheck(queue()['status'] === 'pending' && queue()['claim_token'] === '', 'Atomic park observes a racing committed result and preserves wakeup.');

[$credentials, $request, $snapshot] = taskSetup();
[$job, $task] = queueTask();
$DB->update(AgentTask::TABLE, ['expires' => time() - 1], ['id' => $task['id']]);
AgentTask::exchange(array_replace($request, ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'],
    'result' => ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot]]), $credentials['token']);
agentCheck(AgentTask::outstanding('b')['state'] === 'stale' && AgentTask::outstanding('b')['result'] !== '{}', 'Late evidence is durable but cannot become fresh input.');
$route = EntitySyncRoute::find('route'); $route['active'] = false; EntitySyncRoute::save($route);
agentCheck(AgentTask::exchange($request, $credentials['token'])['task'] === null,
    'A revoked pair cannot deliver work; the authenticated installation remains available for other pairs.');

[$credentials, $request, $snapshot] = taskSetup();
AssetSyncQueue::notify('Computer', 101, 'b');
$job = AssetSyncQueue::claimDue(1)[0];
$DB->failOn = 'COMMIT';
try { AgentTask::publish(AgentTask::pair('b'), 'inspect', [], $job, []); throw new LogicException('Failed publication accepted'); } catch (RuntimeException) {}
$DB->failOn = null;
agentCheck(AgentTask::outstanding('b') === null && AssetSyncQueue::owns($job), 'Publication failure rolls back task and queue release together.');

[$credentials, $request, $snapshot] = taskSetup();
[$job, $task] = queueTask();
$settings = $request + ['computer_id' => 201, 'entity_id' => 100];
$terminal = ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot];
$DB->insert(TaskJournal::TABLE, ['id' => $task['id'], 'hash' => $task['hash'], 'body' => json_encode($task),
    'state' => 'unchanged', 'result' => json_encode($terminal), 'created_at' => time()]);
agentCheck(TaskJournal::run($task, $settings) === $terminal, 'Durable journal replays result without any GLPI model execution.');
$tampered = $task; $tampered['computer_id'] = 999;
try { TaskJournal::run($tampered, $settings); throw new LogicException('Tampered replay accepted'); } catch (RuntimeException) { agentCheck(true, 'Replay validates immutable task body/hash and local binding.'); }
$settings['generation'] = str_repeat('f', 32);
try { TaskJournal::run($task, $settings); throw new LogicException('Rebound replay accepted'); } catch (RuntimeException) { agentCheck(true, 'Rebinding does not authorize replay.'); }

foreach ([0, 1] as $newNotification) {
    [$credentials, $request, $snapshot] = taskSetup();
    [$job, $task] = queueTask();
    $DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => $job['id']]);
    $claim = AssetSyncQueue::claimDue(1)[0];
    $DB->update(AgentTask::TABLE, ['state' => 'finalizing'], ['id' => $task['id']]);
    if ($newNotification) { AssetSyncQueue::notify('Computer', 101, 'b'); }
    $savedTask = AgentTask::outstanding('b');
    (new ReflectionMethod(AgentTask::class, 'finish'))->invoke(null, $savedTask, $claim, 'unchanged', false);
    agentCheck(queue()['status'] === 'done' && (int) queue()['needs_recheck'] === $newNotification,
        'Finalization consumes captured dirty work but preserves a newly arrived local notification.');
}

[$credentials, $request, $snapshot] = taskSetup();
$pair = AgentTask::pair('b');
$context = AgentTask::context($pair);
$fingerprint = AgentTask::hash([$context['policy'], $context['metadata'], $context['values']]);
$DB->update(AgentTask::PAIRS, ['checked_at' => time(), 'local_fingerprint' => $fingerprint], ['connection_id' => 'b']);
agentCheck(!AgentTask::needsInspection(AgentTask::pair('b'), false), 'Unchanged A scan converges without treating cached B values as fresh.');
agentCheck(AgentTask::needsInspection(AgentTask::pair('b'), true), 'Explicit refresh still requests fresh B inspection.');
$DB->update(AgentTask::PAIRS, ['checked_at' => time() - 3601], ['connection_id' => 'b']);
agentCheck(AgentTask::needsInspection(AgentTask::pair('b'), false), 'Hourly safety net requests fresh B observation.');
[$job, $task] = queueTask();
agentCheck(!AgentTask::needsInspection(AgentTask::pair('b'), true) && queue()['needs_recheck'] === 0,
    'Backfill cannot dirty an outstanding operation, even with force enabled.');

[$credentials, $request, $snapshot] = taskSetup();
$event = ['registration' => $credentials['registration'], 'generation' => $credentials['generation'],
    'computer_id' => 201, 'revision' => 1, 'lifecycle' => 'present'];
AgentInbox::receive($event, $credentials['token']);
$job = AssetSyncQueue::claimDue(1)[0];
$route = EntitySyncRoute::find('route'); $route['active'] = false; EntitySyncRoute::save($route);
$called = false;
AgentTask::process($job, static function () use (&$called): void { $called = true; });
agentCheck(!$called && queue()['agent_completed'] === 0 && queue()['status'] === 'retry' && AssetSyncQueue::claimDue(1) === [],
    'Rejected pre-publication scope performs no billing/model writes, consumes no revision and preserves backoff.');

taskSetup();
GlpiBConnection::save(['id' => 'recovery', 'mode' => 'agent', 'base_url' => 'https://recovery.invalid', 'active' => true]);
$DB->failOn = 'INSERT INTO `' . AgentTask::TABLE;
$recovery = AgentTask::declare('recovery', str_repeat('c', 32), 101, 201, 100);
$DB->failOn = null;
agentCheck(AgentTask::pair('recovery') !== null && AgentTask::outstanding('recovery') === null
    && isset($recovery['token'], $recovery['inspection_error']), 'Failed initial publication returns durable candidate credentials and recovery message.');
$rotated = AgentTask::rotate('recovery');
agentCheck($rotated['registration'] === $recovery['registration'] && $rotated['generation'] === $recovery['generation']
    && $rotated['token'] !== $recovery['token'], 'Unconfirmed candidate token rotation preserves binding.');
$request = ['kind' => 'pull', 'registration' => $rotated['registration'], 'generation' => $rotated['generation'],
    'installation' => str_repeat('c', 32), 'connection_id' => 'recovery'];
try { AgentTask::exchange($request, $recovery['token']); throw new LogicException('Old token survived rotation'); }
catch (UnexpectedValueException) { agentCheck(true, 'Rotated token invalidates the previous token.'); }
AgentTask::inspect('recovery');
$task = AgentTask::exchange($request, $rotated['token'])['task'];
AgentTask::exchange(array_replace($request, ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'],
    'result' => ['outcome' => 'blocked', 'changed' => []]]), $rotated['token']);
agentCheck(AgentTask::outstanding('recovery') === null && AgentTask::recent('recovery')[0]['state'] === 'blocked',
    'Standalone failure remains visible after its outstanding slot is released.');

echo "Bounded agent task tests passed ({$checks} total checks including existing prototype coverage).\n";
