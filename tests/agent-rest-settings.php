<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-rest-dispatch.php';
ob_end_clean();

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;

final class SettingsDispatchDB extends RestDispatchDB
{
    public string $dbdefault = 'settings-test';
    public array $locks = [];
    public bool $releaseFails = false;
    public function doQuery(string $sql): mixed {
        if (preg_match('/^SELECT COALESCE\(IS_USED_LOCK\((.+)\) = CONNECTION_ID\(\), 0\) AS owned$/', $sql, $m)) {
            return $this->sql->pdo->query('SELECT ' . (int) !empty($this->locks[$m[1]]) . ' AS owned');
        }
        if (preg_match('/^SELECT (GET_LOCK|RELEASE_LOCK)\((.+?)(?:, 0)?\) AS (acquired|released)$/', $sql, $m)) {
            $result = $m[1] === 'GET_LOCK' ? (int) empty($this->locks[$m[2]]) : (int) !empty($this->locks[$m[2]]);
            if ($m[1] === 'RELEASE_LOCK' && $this->releaseFails) { $result = 0; }
            if ($result) { $this->locks[$m[2]] = $m[1] === 'GET_LOCK'; }
            return $this->sql->pdo->query('SELECT ' . $result . ' AS ' . $m[3]);
        }
        if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_wake_') && $this->sql->pdo->inTransaction()) { return true; }
        return parent::doQuery($sql);
    }
}

function settingsSetup(): array
{
    global $DB;
    Config::$beforeRead = Config::$beforeWrite = null;
    taskSetup();
    RestWork::beginRun();
    $DB = new SettingsDispatchDB($DB);
    $DB->doQuery('CREATE TABLE test_config (context TEXT, name TEXT, value TEXT, PRIMARY KEY(context,name))');
    foreach (Config::$values as $context => $values) {
        foreach ($values as $name => $value) { $DB->sql->pdo->prepare('INSERT INTO test_config VALUES (?,?,?)')->execute([$context, $name, $value]); }
    }
    // Config is backed by the same SQLite transaction, rather than an unrollbackable array double.
    Config::$beforeRead = static function ($context): void {
        global $DB;
        $query = $DB->sql->pdo->prepare('SELECT name,value FROM test_config WHERE context=?'); $query->execute([$context]);
        Config::$values[$context] = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    Config::$beforeWrite = static function ($context, $values): void {
        global $DB;
        foreach ($values as $name => $value) { $DB->sql->pdo->prepare('INSERT INTO test_config VALUES (?,?,?) ON CONFLICT(context,name) DO UPDATE SET value=excluded.value')->execute([$context, $name, $value]); }
    };
    $pair = AgentTask::pair('b');
    $connection = GlpiBConnection::find('b');
    GlpiBConnection::save(array_replace($connection, ['agent_transport' => 'rest_records', 'agent_rest_pairs' => [$pair['id']],
        'agent_rest_url' => 'https://b.invalid/apirest.php', 'agent_app_token' => 'native-b-app', 'agent_user_token' => 'native-b-user']));
    RestDispatchWire::reset();
    return $pair;
}

function settingsEntry(): array
{
    $state = json_decode(Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2'])['rest_settings_v2'], true);
    return array_values($state)[0];
}

function settingsBody(): array { return json_decode(settingsEntry()['body'], true); }
function settingsReject(Closure $operation, string $message): void {
    try { $operation(); throw new LogicException($message); } catch (RuntimeException) { agentCheck(true, $message); }
}
function publishPolicy(): void {
    RestDispatchWire::$replies = [['status' => 201, 'body' => ['id' => 41]]];
    RestWork::sendPending(hrtime(true) + 20_000_000_000);
    agentCheck(RestDispatchWire::$replies === [] && !settingsEntry()['pending'], 'Settings publication is durable and uses one shared POST.');
}

function settingsDispatch(array $replies): void
{
    global $DB;
    foreach ($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['state' => 'accepted']]) as $task) {
        $context = json_decode($task['context'], true);
        if (isset($context['rest_next'])) { $context['rest_next'] = 0; $DB->update(AgentTask::TABLE, ['context' => json_encode($context)], ['id' => $task['id']]); }
    }
    RestDispatchWire::$replies = $replies;
    RestWork::sendPending(hrtime(true) + 20_000_000_000);
    agentCheck(RestDispatchWire::$replies === [], 'Expected settings/read/work requests consumed.');
}

function settingsWorkRecord(array $task, array $result = []): array
{
    $body = json_decode($task['body'], true);
    return ['id' => 77, 'work_id' => $task['id'], 'hash' => $task['hash'], 'body' => $task['body'], 'batch_id' => $body['batch_id'],
        'expires' => $body['expires'], 'state' => $result['outcome'] ?? 'accepted', 'result' => $result === [] ? '{}' : json_encode($result)];
}

$pair = settingsSetup();
$connection = GlpiBConnection::find('b');
agentCheck(empty($connection['agent_api_url']) && empty($connection['agent_peer_user']) && $connection['agent_user_token'] === 'native-b-user',
    'A REST setup needs no adapter URL or incoming A peer configuration.');
$first = settingsEntry();
agentCheck(settingsBody()['revision'] === 1 && settingsBody()['enabled'], 'Saved effective Name rules allocate initial durable v2.');
GlpiBConnection::save(array_replace($connection, ['name' => 'Renamed display only']));
$route = EntitySyncRoute::find('route');
EntitySyncRoute::save(array_replace($route, ['name' => 'Renamed route only']));
agentCheck(settingsEntry() === $first, 'Display-only saves retain identical bytes/hash/revision.');
$DB->doQuery('START TRANSACTION');
settingsReject(static fn () => GlpiBConnection::save(['id' => 'ordinary', 'mode' => 'rest', 'active' => true]), 'Global JSON writers cannot join caller transactions when Records exists.');
agentCheck($DB->sql->pdo->inTransaction(), 'Rejected save does not commit or roll back caller transaction.');
$DB->doQuery('ROLLBACK');

$persist = Config::$beforeWrite; $interleaved = false;
Config::$beforeWrite = static function ($context, $values) use ($persist, &$interleaved): void {
    if (!$interleaved && isset($values['glpib_connections'])) {
        $interleaved = true;
        settingsReject(static fn () => GlpiBConnection::save(['id' => 'ordinary', 'mode' => 'rest', 'active' => true]), 'Interleaved ordinary save shares the Records configuration lock.');
    }
    $persist($context, $values);
};
GlpiBConnection::save(array_replace($connection, ['name' => 'Serialized Records name']));
Config::$beforeWrite = $persist;
GlpiBConnection::save(['id' => 'ordinary', 'mode' => 'rest', 'active' => true]);
agentCheck($interleaved && GlpiBConnection::find('ordinary') !== null && GlpiBConnection::find('b')['name'] === 'Serialized Records name'
    && settingsEntry() === $first, 'Retry preserves both connection writes and exact effective policy.');

$beforeRoute = EntitySyncRoute::find('route');
Config::$beforeWrite = static function ($context, $values) use ($persist): void {
    $persist($context, $values);
    if (isset($values['rest_settings_v2'])) { throw new RuntimeException('Injected settings persistence failure'); }
};
settingsReject(static fn () => EntitySyncRoute::save(array_replace($beforeRoute, ['active' => false])), 'Failed revision persistence rolls back the route save too.');
Config::$beforeWrite = $persist;
agentCheck(EntitySyncRoute::find('route')['active'] && settingsEntry() === $first && !array_filter($DB->locks), 'Rules/revision rollback together and cleanup releases the lock.');
publishPolicy();
RestDispatchWire::reset(); RestWork::sendPending(hrtime(true) + 20_000_000_000);
agentCheck(RestDispatchWire::$calls === [], 'Unchanged idle settings need no authentication or sync calls.');

EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => false]));
agentCheck(settingsBody()['revision'] === 2 && !settingsBody()['enabled'], 'Disable persists a higher revision without asset jobs.');
publishPolicy();
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => true]));
agentCheck(settingsBody()['revision'] === 3, 'Re-enable advances the same route revision.');
EntitySyncRoute::delete('route');
agentCheck(settingsBody()['revision'] === 4 && !settingsBody()['enabled'] && settingsBody()['route_id'] === 'route', 'Deleted route retains identity in its tombstone.');
GlpiBConnection::save(array_replace(GlpiBConnection::find('b'), ['active' => false]));
RestDispatchWire::reset(); RestWork::sendPending(hrtime(true) + 20_000_000_000);
agentCheck(RestDispatchWire::$calls === [] && settingsEntry()['pending'], 'Disabled connection sends zero auth/settings requests and retains pending tombstone.');

$pair = settingsSetup(); publishPolicy(); RestDispatchWire::reset();
$DB->doQuery('DELETE FROM `' . AgentTask::PAIRS . '`');
$DB->doQuery('DELETE FROM glpi_computers');
$policy = settingsEntry();
RestWork::requestRead('b', 100);
agentCheck(settingsEntry() === $policy, 'Pair-free metadata publication does not change business settings.');
$task = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE JSON_EXTRACT(body, \'$.version\')=9');
$body = json_decode($task['body'], true);
$observation = ['outcome' => 'observed', 'changed' => [], 'itemtype' => 'Computer', 'entity_id' => 100, 'observed_at' => time(),
    'fields' => [['key' => 'name', 'uid' => 'name', 'id' => '1', 'label' => 'Independent B Name', 'type' => 'text', 'group' => 'Native', 'supported' => true, 'reason' => '']]];
$record = ['id' => 55, 'work_id' => $body['id'], 'hash' => $task['hash'], 'body' => $task['body'], 'state' => 'observed', 'result' => json_encode($observation)];
RestDispatchWire::$replies = [['status' => 201, 'body' => ['id' => 55]], ['status' => 200, 'body' => [$record]]];
RestWork::sendPending(hrtime(true) + 20_000_000_000);
$saved = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE id=' . $DB->quote($body['id']));
agentCheck($saved['state'] === 'observed' && $saved['slot'] === null && $saved['body'] === $task['body'] && $saved['hash'] === $task['hash'], 'Observation finalizes without inventing asset mutation completion.');
agentCheck(AgentTask::catalog('b', 'Computer')['fields'][0]['label'] === 'Independent B Name' && settingsEntry() === $policy, 'Catalog uses B definition labels without assets or head changes.');
$calls = array_values(array_filter(RestDispatchWire::$calls, static fn ($call): bool => !in_array($call['path'], ['initSession', 'killSession'], true)));
agentCheck(count($calls) === 2 && $calls[0]['method'] === 'POST' && $calls[1]['method'] === 'GET' && str_contains($calls[1]['path'], 'compiler%5D=9'), 'Metadata uses one POST and one exact bounded stored-result GET.');

$pair = settingsSetup(); publishPolicy(); RestDispatchWire::reset();
$DB->update(AgentTask::PAIRS, ['confirmed' => 0, 'snapshot' => '{}', 'observed_at' => 0], ['id' => $pair['id']]);
AgentTask::inspect('b', $pair['id']);
$task = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE JSON_EXTRACT(body, \'$.version\')=9');
$body = json_decode($task['body'], true);
$observation['observed_at'] = time();
$observation['snapshot'] = ['itemtype' => 'Computer', 'items_id' => 201, 'entity_id' => 100, 'pair_id' => $pair['id'], 'name' => 'Original', 'serial' => $pair['serial'], 'otherserial' => ''];
$record = ['id' => 56, 'work_id' => $body['id'], 'hash' => $task['hash'], 'body' => $task['body'], 'state' => 'observed', 'result' => json_encode($observation)];
RestDispatchWire::$replies = [['status' => 201, 'body' => ['id' => 56]], ['status' => 200, 'body' => [$record]]];
RestWork::sendPending(hrtime(true) + 20_000_000_000);
agentCheck(!AgentTask::byId('b', $pair['id'])['confirmed'], 'Read-only inspection never confirms a pair.');
AgentTask::confirm('b', 'route', $pair['id']);
agentCheck((bool) AgentTask::byId('b', $pair['id'])['confirmed'], 'Fresh independently observed typed IDs support explicit confirmation without adapter pulls.');

$pair = settingsSetup(); publishPolicy();
AssetSyncQueue::notify('Computer', 101, 'b');
RestWork::process(AssetSyncQueue::claimDue(1)[0]);
$old = AgentTask::outstanding('b', $pair);
$oldBody = json_decode($old['body'], true);
agentCheck(json_decode($old['context'], true)['settings']['version'] === 2, 'Name work pins immutable v2 without changing v8 schema.');
$accepted = settingsWorkRecord($old);
settingsDispatch([['status' => 201, 'body' => [['id' => 77]]], ['status' => 200, 'body' => [$accepted]]]);
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => false]));
$stale = settingsWorkRecord($old, ['outcome' => 'stale', 'changed' => [], 'reason' => 'settings_superseded']);
settingsDispatch([['status' => 201, 'body' => ['id' => 42]], ['status' => 200, 'body' => [$accepted]],
    ['status' => 201, 'body' => [['id' => 77]]], ['status' => 200, 'body' => [$stale]]]);
$receipt = AgentTask::outstanding('b', $pair);
agentCheck($receipt['body'] === $old['body'] && $receipt['hash'] === $old['hash'] && RestWork::needsWork($pair, false), 'Explicit superseded receipt can progress through backfill, with original request exact.');
RestWork::process(AssetSyncQueue::claimDue(1)[0]);
agentCheck(AgentTask::outstanding('b', $pair) !== null, 'Disabled scope does not retire receipt or publish replacement prematurely.');
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => true]));
publishPolicy();
AssetSyncQueue::notify('Computer', 101, 'b');
RestWork::process(AssetSyncQueue::claimDue(1)[0]);
$retired = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE id=' . $DB->quote($old['id']));
agentCheck($retired['slot'] === null && $retired['state'] === 'stale' && $retired['body'] === $old['body'] && $retired['hash'] === $old['hash']
    && $retired['result'] === $receipt['result'], 'Only known unstarted superseded receipt retires; immutable evidence remains exact.');
$DB->update(AssetSyncQueue::TABLE, ['status' => 'pending', 'available_at' => null, 'finished_at' => null], ['id' => (int) $old['queue_id']]);
RestWork::process(AssetSyncQueue::claimDue(1)[0]);
$fresh = AgentTask::outstanding('b', $pair);
$freshBody = json_decode($fresh['body'], true);
agentCheck($fresh['id'] !== $old['id'] && $freshBody['settings_id'] === settingsBody()['id'] && $freshBody['settings_id'] !== $oldBody['settings_id'], 'Replacement is a fresh immutable ID pinned to the new enabled revision.');
$snapshot = ['itemtype' => 'Computer', 'items_id' => $freshBody['items_id'], 'entity_id' => $freshBody['entity_id'], 'pair_id' => $freshBody['pair_id'],
    'serial' => $freshBody['identity']['serial'], 'otherserial' => $freshBody['identity']['otherserial'] ?? '', 'observed_at' => time(), 'fields' => ['name' => ['value' => $freshBody['name']]]];
$verified = settingsWorkRecord($fresh, ['outcome' => 'verified', 'changed' => ['name' => 11], 'snapshot' => $snapshot,
    'timing' => ['started_at' => time(), 'finished_at' => $snapshot['observed_at'], 'within_deadline' => true]]);
settingsDispatch([['status' => 201, 'body' => [['id' => 78]]], ['status' => 200, 'body' => [$verified]]]);
RestWork::process(AssetSyncQueue::claimDue(1)[0]);
agentCheck(AgentTask::outstanding('b', $pair) === null && queue()['status'] === 'done', 'Replacement actually finalizes synchronization after disable/re-enable.');
GlpiBConnection::save(array_replace(GlpiBConnection::find('b'), ['agent_transport' => 'adapter']));
AssetSyncQueue::notify('Computer', 101, 'b');
RestWork::process(AssetSyncQueue::claimDue(1)[0]);
agentCheck(AgentTask::outstanding('b', $pair) === null && queue()['status'] === 'blocked', 'Transport rollback cannot silently publish v1-policy REST work for a retained v2 route.');

$pair = settingsSetup(); publishPolicy();
RestWork::requestRead('b', 100);
$readTask = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE JSON_EXTRACT(body, \'$.version\')=9');
settingsDispatch([['status' => 0, 'body' => []], ['status' => 0, 'body' => []]]);
$unknown = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE id=' . $DB->quote($readTask['id']));
agentCheck($unknown['result'] === '{}' && $unknown['slot'] === $readTask['slot'] && $unknown['body'] === $readTask['body'], 'Lost metadata POST and failed GET retain exact unknown intent.');
RestDispatchWire::reset(); settingsDispatch([['status' => 0, 'body' => []]]);
$calls = array_values(array_filter(RestDispatchWire::$calls, static fn ($call): bool => !in_array($call['path'], ['initSession', 'killSession'], true)));
agentCheck(count($calls) === 1 && $calls[0]['method'] === 'GET', 'Failed metadata recovery read cannot authorize another POST.');
$DB->releaseFails = true;
settingsReject(static fn () => RestWork::configure(static function (): void {}), 'Release result is verified.');
$DB->releaseFails = false; $DB->locks = [];

$pair = settingsSetup();
GlpiBConnection::save(array_replace(GlpiBConnection::find('b'), ['agent_rest_pairs' => []]));
$DB->doQuery('DELETE FROM `' . AgentTask::PAIRS . '`');
$DB->doQuery('DELETE FROM `' . \GlpiPlugin\Assetsync20\AssetSyncLink::TABLE . '`');
$before = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE]));
$service = new \GlpiPlugin\Assetsync20\AssetSyncService();
$prepare = new ReflectionMethod($service, 'prepareAndQueueAsset');
$prepare->invoke($service, 'Computer', 101, 'b', false, time() + 20, microtime(true) + 20);
$service->processQueue(1);
agentCheck(iterator_to_array($DB->request(['FROM' => AgentTask::TABLE])) === $before,
    'Records backfill plus queue processing publishes no legacy match task for an unpaired asset.');
agentCheck(queue()['status'] === 'blocked' && str_contains(queue()['last_error'], 'explicitly opted-in confirmed'),
    'Unpaired Records backfill is visibly unsupported, not an adapter matching fallback.');

$connection = GlpiBConnection::find('b');
GlpiBConnection::save(array_replace($connection, ['agent_transport' => 'adapter']));
AssetSyncQueue::notify('Computer', 101, 'b');
AgentTask::process(AssetSyncQueue::claimDue(1)[0], static fn (): bool => false);
$legacy = AgentTask::matchTask('b', 'Computer', 101);
agentCheck($legacy !== null && json_decode($legacy['body'], true)['version'] === 4, 'Real legacy match fixture exists before transport switch.');
GlpiBConnection::save($connection);
$prepare->invoke(new \GlpiPlugin\Assetsync20\AssetSyncService(), 'Computer', 101, 'b', false, time() + 20, microtime(true) + 20);
agentCheck(AgentTask::matchTask('b', 'Computer', 101) === $legacy, 'Records scanner preserves retained legacy match bytes, state, result and slot.');
$body = json_decode($legacy['body'], true);
$body['expires'] = time() - 1;
$DB->update(AgentTask::TABLE, ['expires' => $body['expires'], 'body' => json_encode($body),
    'hash' => AgentTask::hash($body), 'state' => 'executing'], ['id' => $legacy['id']]);
$expired = AgentTask::matchTask('b', 'Computer', 101);
$prepare->invoke(new \GlpiPlugin\Assetsync20\AssetSyncService(), 'Computer', 101, 'b', false, time() + 20, microtime(true) + 20);
agentCheck(AgentTask::matchTask('b', 'Computer', 101) === $expired,
    'Expired legacy match remains exact even when the Records scanner makes its queue pending.');

Config::$beforeRead = Config::$beforeWrite = null;
echo "REST settings A passed: durable revisions/tombstones, shared JSON lock, rollback, disabled zero-HTTP and pair-free POST/GET inspection (SQL-backed Config/mock HTTP).\n";
