<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-name-both-supersession.php';
ob_end_clean();

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;

function scopeSetupRest(int $root = 1): array
{
    global $DB;
    Computer::$beforeModelUpdate = Computer::$afterModelUpdate = null;
    $pair = settingsSetup();
    $DB->insert('glpi_entities', ['id' => 12, 'entities_id' => 1]);
    $DB->insert('glpi_entities', ['id' => 13, 'entities_id' => 12]);
    $DB->update('glpi_computers', ['entities_id' => 12], ['id' => 101]);
    $snapshot = json_decode($pair['snapshot'], true);
    $snapshot['entity_id'] = 87; $snapshot['observed_at'] = time();
    $DB->update(AgentTask::PAIRS, ['confirmed' => 0, 'local_entity' => 12, 'entity_id' => 87,
        'snapshot' => json_encode($snapshot), 'observed_at' => time()], ['id' => $pair['id']]);
    EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['glpi_a_source_entity_id' => (string) $root,
        'glpi_b_target_entity_id' => '87', 'include_child_entities' => true, 'asset_types' => ['Computer', 'Printer']]));
    AgentTask::confirm('b', 'route', $pair['id']);
    GlpiBConnection::save(array_replace(GlpiBConnection::find('b'), ['agent_rest_pair' => '', 'agent_rest_pairs' => []]));
    return AgentTask::byId('b', $pair['id']);
}

function scopePolicy(string $route): array
{
    foreach (json_decode(Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2'])['rest_settings_v2'], true) as $entry) {
        $body = json_decode($entry['body'], true);
        if ($body['route_id'] === $route) { return $body; }
    }
    throw new LogicException('Expected policy missing.');
}

function scopePublish(array $reply): array
{
    RestDispatchWire::reset(); RestDispatchWire::$replies = [$reply];
    (new ReflectionMethod(RestWork::class, 'publishSettings'))->invoke(null, hrtime(true) + 20_000_000_000);
    return array_values(array_filter(RestDispatchWire::$calls, static fn ($c): bool => str_contains($c['path'], 'RestSettings')));
}

foreach ([0, 1] as $root) {
    $pair = scopeSetupRest($root);
    agentCheck(EntitySyncRoute::strictEntityPath(13) === ['0', '1', '12', '13'], 'Strict path includes validated root, child and grandchild.');
    $policy = settingsBody();
    agentCheck($policy['version'] === 3 && $policy['source_entity'] === $root && $policy['target_entity'] === 87
        && $policy['asset_types'] === ['Computer', 'Printer'] && $policy['include_child_entities'] && $policy['enabled'],
        'Canonical v3 supports Computer in a mixed route, with distinct source root and exact B target.');
    publishPolicy();
    agentCheck(RestWork::handles(GlpiBConnection::find('b'), 'Computer', 101), 'Confirmed route participates without extra REST pair opt-in.');
    AssetSyncQueue::notify('Computer', 101, 'b'); RestWork::process(AssetSyncQueue::claimDue(1)[0]);
    $task = AgentTask::outstanding('b', $pair); $body = json_decode($task['body'], true);
    agentCheck($body['version'] === 10 && $body['source_asset_entity'] === 12 && $body['entity_id'] === 87
        && $body['settings_id'] === $policy['id'], 'Work10 separately captures actual A12 and B87 without changing v8 schema.');
    $snapshot = ['itemtype' => 'Computer', 'items_id' => $body['items_id'], 'entity_id' => 87, 'pair_id' => $pair['id'],
        'serial' => $body['identity']['serial'], 'otherserial' => '', 'observed_at' => time(), 'fields' => ['name' => ['value' => $body['name']]]];
    $record = settingsWorkRecord($task, ['outcome' => 'verified', 'changed' => ['name' => 19], 'snapshot' => $snapshot,
        'timing' => ['started_at' => time(), 'finished_at' => $snapshot['observed_at'], 'within_deadline' => true]]);
    settingsDispatch([['status' => 201, 'body' => [['id' => 77]]], ['status' => 200, 'body' => [$record]]]);
    $last = RestDispatchWire::$calls[count(RestDispatchWire::$calls) - 2];
    agentCheck(str_contains($last['path'], 'compiler%5D=10&rest_entity=87'), 'Batch GET explicitly scopes the authorized target entity.');
    RestWork::process(AssetSyncQueue::claimDue(1)[0]);
    agentCheck(queue()['status'] === 'done', 'Child/root Computer request completes through fresh source/queue finalization.');
}

$pair=scopeSetupRest(0); $DB->update('glpi_entities',['entities_id'=>null],['id'=>0]);
agentCheck(EntitySyncRoute::strictEntityPath(0) === ['0'] && EntitySyncRoute::strictEntityPath(13) === ['0','1','12','13'],
    'Official GLPI root0 with NULL parent is a complete ancestry, not a missing parent.');
agentCheck(AgentTask::scope($pair)['route']['id'] === 'route','Stock NULL-root scope remains eligible through the same route winner.');

$pair = scopeSetupRest();
$link = AssetSyncLink::find('Computer', 101, 'b');
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['glpi_b_target_entity_id' => '88']));
settingsReject(static fn () => AgentTask::scope($pair), 'Changed destination must hold, never move the confirmed B asset.');
agentCheck(AssetSyncLink::find('Computer', 101, 'b') === $link && AgentTask::byId('b', $pair['id'])['computer_id'] === $pair['computer_id'], 'Destination hold preserves link and pair IDs.');

$pair=scopeSetupRest(); publishPolicy(); AssetSyncQueue::notify('Computer',101,'b'); RestWork::process(AssetSyncQueue::claimDue(1)[0]);
$held=AgentTask::outstanding('b',$pair);
GlpiBConnection::save(array_replace(GlpiBConnection::find('b'),['agent_transport'=>'adapter']));
agentCheck(RestWork::handles(GlpiBConnection::find('b'),'Computer',101),'Retained Work10 is never routed into legacy adapter task processing after a transport flip.');
RestDispatchWire::reset(); RestWork::sendPending(hrtime(true)+20_000_000_000);
agentCheck(RestDispatchWire::$calls === [] && currentTask($held['id']) === $held,'Transport flip holds Work10 including its unreserved context without auth, POST or false completion.');

foreach (['name','identity','route','entity'] as $change) {
    $pair=scopeSetupRest(); publishPolicy(); AssetSyncQueue::notify('Computer',101,'b'); RestWork::process(AssetSyncQueue::claimDue(1)[0]);
    $old=AgentTask::outstanding('b',$pair);
    if ($change === 'name') { $item=new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Current A after preparation']); }
    if ($change === 'identity') { $DB->update('glpi_computers',['serial'=>'Different identity'],['id'=>101]); }
    if ($change === 'route') { EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'),['glpi_b_target_entity_id'=>'88'])); publishPolicy(); }
    if ($change === 'entity') { $DB->update('glpi_computers',['entities_id'=>13],['id'=>101]); }
    RestDispatchWire::reset(); RestWork::sendPending(hrtime(true)+20_000_000_000); $held=currentTask($old['id']);
    agentCheck(RestDispatchWire::$calls === [] && $held['body'] === $old['body'] && $held['hash'] === $old['hash'] && $held['job'] === $old['job']
        && $held['result'] === '{}' && $held['slot'] === $old['slot'],'NEW unsent Work10 fresh source/scope change holds immutable work before ANY auth/RPC: '.$change);
    agentCheck(json_decode($held['context'],true)['rest_lease'] === '' && json_decode($held['context'],true)['rest_sent'] === false,'Stale Work10 is not silently reserved/replaced or marked dispatched.');
    $label=(new ReflectionMethod(\GlpiPlugin\Assetsync20\AgentActivity::class,'restLabel'))->invoke(null,$held,json_decode($held['body'],true),json_decode($held['context'],true));
    agentCheck(str_contains($label,'dispatch held for inspection'),'Work10 Activity formatter states the hold when the row is authorized for display, not false liveness/completion.');
}

$pair=scopeSetupRest(); publishPolicy(); AssetSyncQueue::notify('Computer',101,'b'); RestWork::process(AssetSyncQueue::claimDue(1)[0]);
$old=AgentTask::outstanding('b',$pair); $before=json_decode($old['body'],true); $item=new Computer(); $item->getFromDB(101);
$item->update(['id'=>101,'name'=>'Intermediate A']); $item->getFromDB(101); $item->update(['id'=>101,'name'=>$before['name']]);
agentCheck(nameQueueContext($pair)['source_hash'] === $before['source_hash'],'Work10 is explicit current-state A authority: net-equal ABA is not claimed as original-event evidence. Work11 retains its distinct full guard.');

$pair = scopeSetupRest();
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['id' => 'deeper', 'glpi_a_source_entity_id' => '12']));
settingsReject(static fn () => AgentTask::scope($pair), 'Deeper route changes winner and holds old confirmation.');
AgentTask::confirm('b', 'deeper', $pair['id']);
agentCheck(AgentTask::scope(AgentTask::byId('b', $pair['id']))['route']['id'] === 'deeper', 'Explicit confirmation uses the same unique deepest winner.');
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('deeper'), ['id' => 'tie']));
agentCheck(!scopePolicy('deeper')['enabled'] && !scopePolicy('tie')['enabled'], 'Equal-depth Computer conflict publishes disabled effective policies without asset jobs.');
settingsReject(static fn () => AgentTask::scope(AgentTask::byId('b', $pair['id'])), 'Equal-depth conflict fails runtime scope too.');
EntitySyncRoute::delete('tie');
agentCheck(scopePolicy('deeper')['enabled'] && !scopePolicy('tie')['enabled'], 'Removing conflict re-enables survivor and retains deleted-route tombstone.');

foreach (['missing', 'cycle', 'root', 'root_missing', 'null_parent', 'bad_parent', 'failure'] as $fault) {
    $pair = scopeSetupRest();
    if ($fault === 'missing') { $DB->doQuery('DELETE FROM glpi_entities WHERE id=1'); }
    if ($fault === 'cycle') { $DB->update('glpi_entities', ['entities_id' => 12], ['id' => 1]); }
    if ($fault === 'root') { $DB->update('glpi_entities', ['entities_id' => 1], ['id' => 0]); }
    if ($fault === 'root_missing') { $DB->doQuery('DELETE FROM glpi_entities WHERE id=0'); }
    if ($fault === 'null_parent') { $DB->update('glpi_entities',['entities_id'=>null],['id'=>1]); }
    if ($fault === 'bad_parent') { $DB->update('glpi_entities',['entities_id'=>'invalid'],['id'=>1]); }
    if ($fault === 'failure') { $DB->sql->failOn = 'SELECT id,entities_id FROM `glpi_entities`'; }
    settingsReject(static fn () => EntitySyncRoute::strictEntityPath(12), 'Incomplete strict ancestry fails: ' . $fault);
    settingsReject(static fn () => AgentTask::scope($pair), 'New REST scope never trusts a partial path: ' . $fault);
    $DB->sql->failOn = null;
}
$pair = scopeSetupRest();
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['asset_types' => ['Printer']]));
agentCheck(!settingsBody()['enabled'], 'Disabling Computer publishes a higher disabled policy, not another supported type.');
settingsReject(static fn () => AgentTask::scope($pair), 'Computer excluded from mixed route is held.');

$pair = scopeSetupRest();
$before = settingsBody();
$calls = scopePublish(['status' => 201, 'body' => ['id' => 41]]);
agentCheck(count($calls) === 1, 'One shared policy publication is bounded.');
// A newer route save during the old request must retain its pending higher revision.
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => false]));
RestDispatchWire::reset(); RestDispatchWire::$replies = [static function () use ($DB): array {
    agentCheck(!array_filter($DB->locks) && !$DB->sql->pdo->inTransaction(), 'Network occurs after releasing config lock/TX.');
    EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['active' => true]));
    return ['status' => 201, 'body' => ['id' => 42]];
}];
(new ReflectionMethod(RestWork::class, 'publishSettings'))->invoke(null, hrtime(true) + 20_000_000_000);
agentCheck(settingsEntry()['pending'] && settingsBody()['revision'] === $before['revision'] + 2, 'Old reply cannot complete a newer saved policy.');

// Connection cursor advances even when the first peer fails, rather than draining all its routes.
GlpiBConnection::save(['id' => 'c', 'mode' => 'agent', 'agent_transport' => 'rest_records', 'active' => true,
    'agent_rest_url' => 'https://c.invalid/apirest.php', 'agent_app_token' => 'c-app', 'agent_user_token' => 'c-user']);
AgentTask::register('c', str_repeat('c', 32));
EntitySyncRoute::save(array_replace(EntitySyncRoute::find('route'), ['id' => 'c-route', 'glpi_b_connection_id' => 'c']));
FieldMapping::save('c', 'Computer', FieldMapping::load('b', 'Computer'), [['key' => 'name', 'uid' => 'name', 'id' => '1', 'label' => 'Name', 'type' => 'text', 'supported' => true]]);
Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_cursor' => 'c']);
$calls = scopePublish(['status' => 400, 'body' => ['PRIVATE_ERROR', 'PRIVATE_VALUE']]);
agentCheck(count($calls) === 1 && Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_cursor'])['rest_settings_cursor'] === 'b', 'Failed b turn advances cursor before HTTP.');
$calls = scopePublish(['status' => 201, 'body' => ['id' => 43]]);
agentCheck(count($calls) === 1 && Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_cursor'])['rest_settings_cursor'] === 'c', 'Independent c receives next due turn despite b failure.');
agentCheck(!str_contains(Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2'])['rest_settings_v2'], 'PRIVATE_'), 'Policy diagnostics do not store server text.');
GlpiBConnection::save(array_replace(GlpiBConnection::find('c'), ['active' => false]));
RestDispatchWire::reset(); (new ReflectionMethod(RestWork::class, 'publishSettings'))->invoke(null, hrtime(true) + 20_000_000_000);
agentCheck(RestDispatchWire::$calls === [], 'Inactive connection never authenticates; failed b is still in backoff.');

$pair = scopeSetupRest();
foreach ([87 => 'Entity87 Name', 88 => 'Entity88 Name'] as $entity => $label) {
    RestWork::requestRead('b', $entity);
    $task = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE JSON_EXTRACT(body, \'$.version\')=9 AND JSON_EXTRACT(body, \'$.entity_id\')=' . $entity);
    $result = ['outcome' => 'observed', 'changed' => [], 'itemtype' => 'Computer', 'entity_id' => $entity, 'observed_at' => time(),
        'fields' => [['key' => 'name', 'label' => $label, 'type' => 'text']]];
    $DB->update(AgentTask::TABLE, ['state' => 'observed', 'slot' => null, 'result' => json_encode($result)], ['id' => $task['id']]);
}
agentCheck(RestWork::catalog('b', 87)['fields'][0]['label'] === 'Entity87 Name' && RestWork::catalog('b', 88)['fields'][0]['label'] === 'Entity88 Name',
    'Catalog selects exact target entity, not latest connection observation.');

// Current Both and the verified never-reserved recovery are exercised by the required suite above.
// Recomputing scoped A policies must not rewrite an unchanged Settings4 document or its revision.
$pair=nameQueueSetup(); $oldEntry=settingsEntry(); $oldPolicy=settingsBody();
RestWork::configure(static function (): void {});
agentCheck(settingsEntry() === $oldEntry && $oldPolicy['version'] === 4,'Unchanged current Settings4 bytes/hash/revision/publication state remain exact.');
$work=nameQueueTask($pair); $immutable=$work;
GlpiBConnection::save(array_replace(GlpiBConnection::find('b'),['agent_rest_pairs'=>[],'agent_rest_pair'=>'']));
agentCheck(RestWork::handles(GlpiBConnection::find('b'),'Computer',101),'Confirmed exact Both pair also participates without an extra opt-in list.');
RestWork::configure(static function (): void {});
agentCheck(settingsEntry() === $oldEntry && currentTask($work['id']) === $immutable,'Removing obsolete opt-in does not reinterpret the Settings4/Work11 evidence.');
$descriptors=[['key'=>'name','uid'=>'name','id'=>'1','label'=>'Name','type'=>'text','supported'=>true]];
FieldMapping::save('b','Computer',[['glpi_a_field_key'=>'name','glpi_b_field_key'=>'name','source_of_truth'=>'glpi_a']],$descriptors);
$disabled=settingsBody();
agentCheck($disabled['version'] === 4 && !$disabled['enabled'] && $disabled['mappings'] === []
    && $disabled['revision'] === $oldPolicy['revision']+1,'Changing a Both mapping to A authority publishes valid higher4 disable, never schema3 or A override.');
agentCheck($disabled['include_child_entities'] === false && $disabled['asset_types'] === ['Computer'],'Disable tombstone retains the supported Settings4 schema.');
settingsReject(static fn () => nameQueueContext($pair),'Downgrade request leaves work visibly held.');
agentCheck(currentTask($work['id']) === $immutable,'Disabled policy does not rewrite, release or complete old Work11.');

foreach (['children','mixed','conflict','deleted'] as $change) {
    $pair=nameQueueSetup(); $before=settingsBody(); $work=nameQueueTask($pair);
    $route=EntitySyncRoute::find('route');
    if ($change === 'children') { EntitySyncRoute::save(array_replace($route,['include_child_entities'=>true])); }
    if ($change === 'mixed') { EntitySyncRoute::save(array_replace($route,['asset_types'=>['Computer','Printer']])); }
    if ($change === 'conflict') { EntitySyncRoute::save(array_replace($route,['id'=>'both-tie'])); }
    if ($change === 'deleted') { EntitySyncRoute::delete('route'); }
    $disabled=scopePolicy('route');
    agentCheck($disabled['version'] === 4 && !$disabled['enabled'] && $disabled['revision'] > $before['revision']
        && $disabled['include_child_entities'] === false && $disabled['asset_types'] === ['Computer'],'Unrepresentable Both scope publishes a valid higher4 disable: '.$change);
    settingsReject(static fn () => nameQueueContext($pair),'Unrepresentable Both route is visibly held: '.$change);
    agentCheck(currentTask($work['id']) === $work,'Scope change preserves retained Work11 body/hash/result/context exactly: '.$change);
}
Config::$beforeRead = Config::$beforeWrite = null;
echo "REST scope A passed: strict ancestry, root/child/distinct target, mixed Computer, no opt-in, conflicts/tombstones, cursor/backoff and scoped catalog (SQLite/mock HTTP).\n";
