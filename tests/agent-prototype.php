<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';
require __DIR__ . '/agent-sqlite.php';
require __DIR__ . '/../assetsync20agent/src/autoload.php';

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20agent\Outbox;
use GlpiPlugin\Assetsync20agent\Snapshot;
use GlpiPlugin\Assetsync20agent\Worker;

$checks = 0;
function agentCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

function agentSetup(): array
{
    global $DB;
    $DB = new AgentSqlite();
    Config::$values = [];
    Computer::$allowUpdate = true;
    Computer::$ignoreUpdate = false;
    PluginFieldsContainer::$options = [];
    PluginFieldsComputerdmosasset::$rows = [];
    $DB->doQuery('CREATE TABLE glpi_entities (id INTEGER PRIMARY KEY, entities_id INTEGER, name TEXT, completename TEXT)');
    $DB->doQuery('CREATE TABLE glpi_computers (id INTEGER PRIMARY KEY, entities_id INTEGER, name TEXT, serial TEXT,
        comment TEXT, otherserial TEXT, uuid TEXT, date_mod TEXT, last_boot TEXT, is_deleted INTEGER DEFAULT 0, is_template INTEGER DEFAULT 0)');
    $DB->doQuery('CREATE TABLE glpi_logs (id INTEGER PRIMARY KEY, items_id INTEGER, itemtype TEXT)');
    agentCheck(AssetSyncLink::install() && AssetSyncQueue::install() && AgentInbox::install() && Outbox::install(), 'Production schemas install through SQL dialect translation.');
    $DB->insert('glpi_entities', ['id' => 1, 'entities_id' => 0, 'name' => 'A', 'completename' => 'A']);
    $DB->insert('glpi_entities', ['id' => 0, 'entities_id' => 0, 'name' => 'Root', 'completename' => 'Root']);
    $asset = ['id' => 101, 'entities_id' => 1, 'name' => 'Original', 'serial' => 'AGENT-101',
        'date_mod' => '2026-01-01 00:00:00', 'is_deleted' => 0, 'is_template' => 0];
    $DB->insert('glpi_computers', $asset);
    GlpiBConnection::save(['id' => 'b', 'base_url' => 'https://b.invalid', 'app_token' => 'app', 'user_token' => 'user', 'active' => true]);
    EntitySyncRoute::save(['id' => 'route', 'glpi_b_connection_id' => 'b', 'glpi_a_source_entity_id' => '1',
        'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    $connection = AgentInbox::connection('b');
    AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'b', 'route_id' => 'route',
        'remote_items_id' => 201, 'confirmed_remote_id' => 201, 'confirmed_entity_id' => 100,
        'confirmed_identity' => AgentInbox::identity($connection), 'status' => 'synced']);
    $credentials = AgentInbox::register('b', ['route']);
    $event = ['registration' => $credentials['registration'], 'generation' => $credentials['generation'],
        'computer_id' => 201, 'revision' => 1, 'lifecycle' => 'present'];
    return [$event, $credentials['token'], $asset];
}

function receipt(): array { return AgentInbox::one('SELECT * FROM `' . AgentInbox::RECEIPTS . '` ORDER BY id DESC LIMIT 1') ?? []; }
function queue(): array { return AgentInbox::one('SELECT * FROM `' . AssetSyncQueue::TABLE . '` LIMIT 1') ?? []; }

[$event, $token] = agentSetup();
$health = ['kind' => 'health', 'registration' => $event['registration'], 'generation' => $event['generation'], 'reconcile_status' => 'ok'];
agentCheck(AgentInbox::decode(json_encode($health)) === $health, 'Health has a separate bounded schema.');
$ack = AgentInbox::receive($health, $token);
agentCheck($ack['status'] === 200 && $ack['recorded'] && $ack['kind'] === 'health' && !isset($ack['accepted'])
    && receipt() === [] && queue() === [], 'Health ACK never claims asset admission or synchronization.');
$registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` LIMIT 1');
agentCheck($registration['last_heartbeat'] > 0 && \GlpiPlugin\Assetsync20\AgentActivity::health($registration) === 'Worker reporting; reconciliation OK', 'Idle workers have durable health.');
$health['reconcile_status'] = 'degraded';
AgentInbox::receive($health, $token);
$registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` LIMIT 1');
agentCheck(\GlpiPlugin\Assetsync20\AgentActivity::health($registration) === 'Degraded reconciliation', 'Degraded reports are visible.');
$DB->update(AgentInbox::REGISTRATIONS, ['active' => 0], ['id' => $event['registration']]);
agentCheck(AgentInbox::receive($health, $token)['status'] === 401, 'Revoked credentials cannot report health.');

[$event, $token] = agentSetup();
AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 102, 'glpi_b_connection_id' => 'b', 'route_id' => 'route', 'remote_items_id' => 201]);
agentCheck(AgentInbox::receive($event, $token)['status'] === 409 && queue() === [], 'An unconfirmed duplicate current reverse link blocks an otherwise confirmed pair.');

class PluginFieldsComputerboxe
{
    public array $fields = [];
    public static function getTable(): string { return 'glpi_plugin_fields_computerboxes'; }
}

[$event, $token] = agentSetup();
AssetSyncQueue::notify('Computer', 101, 'b');
$DB->update(AssetSyncQueue::TABLE, ['status' => 'retry', 'attempts' => 4, 'finished_at' => '2026-01-01 00:00:00'], ['id' => 1]);
foreach (['agent_requested', 'agent_completed', 'claim_token'] as $column) {
    $DB->doQuery('ALTER TABLE `' . AssetSyncQueue::TABLE . '` DROP COLUMN `' . $column . '`');
}
foreach (['confirmed_remote_id', 'confirmed_entity_id', 'confirmed_identity'] as $column) {
    $DB->doQuery('ALTER TABLE `' . AssetSyncLink::TABLE . '` DROP COLUMN `' . $column . '`');
}
agentCheck(AssetSyncQueue::install() && AssetSyncLink::install(), 'Additive migrations upgrade existing tables.');
agentCheck(queue()['attempts'] === 4 && queue()['finished_at'] === '2026-01-01 00:00:00' && queue()['needs_recheck'] === 1
    && queue()['agent_requested'] === 0 && queue()['claim_token'] === '', 'Migration preserves existing scheduling and initializes only new state.');
agentCheck(AssetSyncLink::find('Computer', 101, 'b')['confirmed_identity'] === '', 'Migration never treats an old positive remote ID as confirmation.');

// Real SQL failures cannot produce ACKs or a receipt without admission.
foreach (['INSERT INTO `' . AssetSyncQueue::TABLE, 'UPDATE `' . AgentInbox::RECEIPTS . '` SET `admitted_revision`', 'COMMIT'] as $failure) {
    [$event, $token] = agentSetup();
    $DB->failOn = $failure;
    try {
        AgentInbox::receive($event, $token);
        throw new LogicException('Failure incorrectly acknowledged.');
    } catch (RuntimeException $error) {
        agentCheck($error->getMessage() === 'Notification storage unavailable.', 'Storage failures are sanitized.');
    }
    $DB->failOn = null;
    agentCheck(receipt() === [] && queue() === [] && !$DB->pdo->inTransaction(), 'Failed admission/commit rolls both writes back.');
}

[$event, $token] = agentSetup();
$DB->pdo->exec('CREATE TRIGGER ignore_admission BEFORE INSERT ON ' . AssetSyncQueue::TABLE . ' BEGIN SELECT RAISE(IGNORE); END');
try { AgentInbox::receive($event, $token); throw new LogicException('No-op admission was acknowledged.'); }
catch (RuntimeException) { agentCheck(receipt() === [] && queue() === [], 'Actual SQL no-op insert cannot pass queue admission readback.'); }

[$event, $token] = agentSetup();
$DB->pdo->exec('PRAGMA foreign_keys = ON');
$DB->pdo->exec('CREATE TABLE commit_parent (id INTEGER PRIMARY KEY)');
$DB->pdo->exec('CREATE TABLE commit_guard (parent_id INTEGER REFERENCES commit_parent(id) DEFERRABLE INITIALLY DEFERRED)');
$DB->beforeQuery = static function (string $sql) use ($DB): void {
    if ($sql === 'COMMIT') { $DB->pdo->exec('INSERT INTO commit_guard VALUES (999)'); }
};
try { AgentInbox::receive($event, $token); throw new LogicException('Failed SQL commit was acknowledged.'); }
catch (RuntimeException) { agentCheck(receipt() === [] && queue() === [] && !$DB->pdo->inTransaction(), 'Database-enforced deferred-constraint COMMIT failure rolls receipt/admission back without ACK.'); }

[$event, $token] = agentSetup();
$ack = AgentInbox::receive($event, $token);
agentCheck($ack['status'] === 200 && receipt()['admitted_revision'] === 1 && queue()['agent_requested'] === 1, 'ACK follows durable receipt and actual queue admission.');
$duplicate = AgentInbox::receive($event, $token);
agentCheck($duplicate === $ack && queue()['agent_requested'] === 1, 'Lost ACK retry is idempotent.');
// Reopening an on-disk database exercises persistence independently of PHP memory.
$file = tempnam(sys_get_temp_dir(), 'agent-sql-');
$DB->pdo->exec('VACUUM INTO ' . $DB->quote($file));
$DB = new AgentSqlite($file);
agentCheck(AgentInbox::receive($event, $token) === $ack && queue()['agent_requested'] === 1, 'Duplicate survives a database connection restart.');
register_shutdown_function(static function () use ($file): void { unlink($file); });

// Retry/in-flight notifications preserve scheduling; completion consumes exactly its snapshot.
$job = AssetSyncQueue::claimDue(1)[0];
$before = queue();
$event['revision'] = 2;
AgentInbox::receive($event, $token);
agentCheck(queue()['started_at'] === $before['started_at'] && queue()['date_mod'] === $before['date_mod']
    && queue()['claim_token'] === $job['claim_token'] && queue()['needs_recheck'] === 0, 'In-flight arrival preserves lease and local dirty bit.');
agentCheck(AgentInbox::jobPair($job) !== null, 'Receipt replacement during snapshot still validates its confirmed pair.');
agentCheck(AssetSyncQueue::finish($job) && queue()['agent_completed'] === 1 && receipt()['completed_revision'] === 0,
    'Old completion cannot complete the newer receipt.');
$job2 = AssetSyncQueue::claimDue(1)[0];
agentCheck($job2['claim_token'] !== $job['claim_token'] && !AssetSyncQueue::finish($job), 'Random fencing defeats same-second/attempt ABA.');
agentCheck(AssetSyncQueue::retry($job2, 'offline') && receipt()['outcome'] === 'retrying', 'Queue and receipt retry commit together.');
$before = queue();
$event['revision'] = 3;
AgentInbox::receive($event, $token);
agentCheck(queue()['available_at'] === $before['available_at'] && queue()['finished_at'] === $before['finished_at']
    && AssetSyncQueue::claimDue(1) === [], 'Arrivals preserve persistent retry backoff.');
$DB->update(AssetSyncQueue::TABLE, ['finished_at' => '2020-01-01 00:00:00'], ['id' => $job2['id']]);
$job3 = AssetSyncQueue::claimDue(1)[0];
$DB->failOn = 'UPDATE `' . AgentInbox::RECEIPTS;
agentCheck(!AssetSyncQueue::finish($job3) && queue()['status'] === 'running', 'Receipt failure rolls queue completion back.');
$DB->failOn = null;
agentCheck(AssetSyncQueue::finish($job3) && receipt()['outcome'] === 'unchanged' && receipt()['completed_revision'] === 3,
    'Finishing only the queue never claims synchronized field changes.');

// Scope, identity, unknown/ambiguous links and tombstones fail closed and remain visible.
foreach (['bad_token', 'generation', 'unconfirmed', 'ambiguous', 'route_edit', 'connection_edit', 'deleted', 'purged'] as $case) {
    [$event, $token] = agentSetup();
    if ($case === 'bad_token') { $token = str_repeat('0', 64); }
    if ($case === 'generation') { $event['generation'] = str_repeat('0', 32); }
    if ($case === 'unconfirmed') { $DB->update(AssetSyncLink::TABLE, ['confirmed_identity' => ''], ['id' => 1]); }
    if ($case === 'ambiguous') {
        $link = AssetSyncLink::find('Computer', 101, 'b');
        unset($link['id'], $link['last_sync_epoch']);
        $link['items_id'] = 102;
        $DB->insert(AssetSyncLink::TABLE, $link);
    }
    if ($case === 'route_edit') {
        $route = EntitySyncRoute::loadAll()[0]; $route['include_child_entities'] = true; EntitySyncRoute::save($route);
    }
    if ($case === 'connection_edit') {
        $connection = AgentInbox::connection('b'); $connection['base_url'] = 'https://replacement.invalid'; GlpiBConnection::save($connection);
    }
    if (in_array($case, ['deleted', 'purged'], true)) { $event['lifecycle'] = $case; }
    $result = AgentInbox::receive($event, $token);
    agentCheck(in_array($result['status'], [401, 409], true) && queue() === [], 'Unsafe event never queues: ' . $case);
    if ($result['status'] === 409) { agentCheck(receipt()['outcome'] === 'blocked', 'Unadmitted event stays visible: ' . $case); }
}

// B baseline/hook races, no-op scans, old ACKs, old claims and generation reset.
[$event, $token] = agentSetup();
$settings = ['registration' => $event['registration'], 'generation' => $event['generation']];
$snapshot = Snapshot::read(101);
Outbox::observe(101, $snapshot, false);
Outbox::observe(101, ['fingerprint' => str_repeat('0', 64), 'lifecycle' => 'present'], true);
agentCheck(Outbox::find(101)['revision'] === 1 && Outbox::find(101)['fingerprint'] === $snapshot['fingerprint'], 'Silent baseline cannot erase a concurrent hook.');
Outbox::observe(101, $snapshot, false);
agentCheck(Outbox::find(101)['revision'] === 1, 'No-op hooks do not create echo revisions.');
$sent = Outbox::claim($settings);
Outbox::observe(101, ['fingerprint' => str_repeat('1', 64), 'lifecycle' => 'present'], false);
$ack = ['accepted' => true] + array_intersect_key($sent, array_flip(['registration', 'generation', 'computer_id', 'revision']));
agentCheck(Outbox::acknowledge($sent, $ack, $settings) && Outbox::find(101)['revision'] === 2 && Outbox::find(101)['acked_revision'] === 1,
    'Old ACK acknowledges only the sent revision.');
$sent2 = Outbox::claim($settings);
agentCheck(!Outbox::acknowledge($sent, $ack, $settings) && !Outbox::retry($sent, 'retrying'), 'A replaced claim cannot acknowledge or retry.');
$settings['generation'] = str_repeat('b', 32);
agentCheck(!Outbox::acknowledge($sent2, ['accepted' => true] + array_intersect_key($sent2, array_flip(['registration','generation','computer_id','revision'])), $settings),
    'Old registration generation cannot clear current work.');
$before = Outbox::find(101);
Outbox::observe(101, ['fingerprint' => str_repeat('2', 64), 'lifecycle' => 'present'], false);
Outbox::observe(101, $snapshot, true, $before);
agentCheck(Outbox::find(101)['fingerprint'] === str_repeat('2', 64), 'Stale reconcile snapshot cannot overwrite a newer hook.');

[$event, $token] = agentSetup();
$worker = new Worker();
agentCheck($worker->reconcile() <= 50 && Outbox::find(101)['revision'] === 0, 'Initial scan is bounded and silent.');
$DB->update('glpi_computers', ['name' => 'Missed hook'], ['id' => 101]);
$worker->reconcile();
agentCheck(Outbox::find(101)['revision'] === 1, 'Reconciliation detects missed native hooks.');
$DB->update('glpi_computers', ['date_mod' => '2026-10-01 00:00:00'], ['id' => 101]);
$worker->reconcile();
agentCheck(Outbox::find(101)['revision'] === 1, 'Volatile timestamp-only changes do not enqueue.');
$DB->doQuery('DELETE FROM glpi_computers WHERE id=101');
$worker->reconcile();
agentCheck(Outbox::find(101)['lifecycle'] === 'purged' && Outbox::find(101)['revision'] === 2, 'Tracked missing Computers produce retained tombstones.');

[$event, $token] = agentSetup();
(new Worker())->reconcile();
$DB->update('glpi_computers', ['last_boot' => '2026-10-01 01:02:03'], ['id' => 101]);
$computer = new Computer();
$computer->getFromDB(101);
\GlpiPlugin\Assetsync20agent\ChangeHook::changed($computer);
agentCheck(Outbox::find(101)['revision'] === 1, 'Supported last_boot datetime-only hook advances revision.');
$DB->update('glpi_computers', ['last_boot' => '2026-10-02 01:02:03'], ['id' => 101]);
(new Worker())->reconcile();
agentCheck(Outbox::find(101)['revision'] === 2, 'Supported last_boot datetime-only missed hook is reconciled.');

[$event, $token] = agentSetup();
$DB->doQuery('CREATE TABLE glpi_plugin_fields_containers (id INTEGER PRIMARY KEY, name TEXT, itemtypes TEXT, is_active INTEGER)');
$DB->doQuery('CREATE TABLE glpi_plugin_fields_fields (id INTEGER PRIMARY KEY, name TEXT, type TEXT, plugin_fields_containers_id INTEGER, is_active INTEGER)');
$DB->doQuery('CREATE TABLE glpi_plugin_fields_computerboxes (id INTEGER PRIMARY KEY, items_id INTEGER, itemtype TEXT, plugin_fields_containers_id INTEGER, notesfield TEXT, plugin_fields_colorfielddropdowns_id INTEGER)');
$DB->insert('glpi_plugin_fields_containers', ['id' => 9, 'name' => 'boxes', 'itemtypes' => '["Computer"]', 'is_active' => 1]);
foreach (['notesfield' => 'textarea', 'colorfield' => 'dropdown'] as $name => $type) {
    $DB->insert('glpi_plugin_fields_fields', ['name' => $name, 'type' => $type, 'is_active' => 1, 'plugin_fields_containers_id' => 9]);
}
$custom = new PluginFieldsComputerboxe();
$custom->fields = ['id' => 90, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 9, 'notesfield' => 'before', 'plugin_fields_colorfielddropdowns_id' => 3];
$DB->insert(PluginFieldsComputerboxe::getTable(), $custom->fields);
agentCheck(Snapshot::hookTypes() === [PluginFieldsComputerboxe::class], 'Fields-generated class/table resolves irregular container inflection.');
(new Worker())->reconcile();
$DB->update(PluginFieldsComputerboxe::getTable(), ['notesfield' => 'changed'], ['id' => 90]);
\GlpiPlugin\Assetsync20agent\ChangeHook::changed($custom);
agentCheck(Outbox::find(101)['revision'] === 1, 'Generated custom-row hook detects text changes independently of controller metadata.');
\GlpiPlugin\Assetsync20agent\ChangeHook::changed($custom);
agentCheck(Outbox::find(101)['revision'] === 1, 'Unchanged custom-row hook does not produce echo work.');
$DB->update(PluginFieldsComputerboxe::getTable(), ['plugin_fields_colorfielddropdowns_id' => 7], ['id' => 90]);
(new Worker())->reconcile();
agentCheck(Outbox::find(101)['revision'] === 2, 'Missed custom dropdown hook is repaired by reconciliation.');
$custom->fields['itemtype'] = 'Monitor';
\GlpiPlugin\Assetsync20agent\ChangeHook::changed($custom);
agentCheck(Outbox::find(101)['revision'] === 2, 'Wrong custom parent type is ignored.');

// End-to-end controller processing with real SQL and a strictly offline remote transport.
[$event, $token, $asset] = agentSetup();
saveTestMappings('b', 'Computer', [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'glpi_b_field_uid' => 'Computer.name', 'source_of_truth' => 'glpi_b']],
    [['key' => 'name', 'uid' => 'Computer.name', 'id' => '1', 'label' => 'Name']]);
$remote = new FakeGlpiBClient();
$remote->records['b:Computer'][201] = array_replace($asset, ['id' => 201, 'entities_id' => 100]);
$service = new AssetSyncService($remote);
$service->queueAssetIfNeeded('Computer', 101, 'b');
$service->processQueue(1);
agentCheck(queue()['status'] === 'done', 'Ordinary setup synchronizes successfully.');
$remote->requests = [];
AssetSyncQueue::notify('Computer', 101, 'b');
$service->processQueue(1);
agentCheck($remote->requests === [], 'Unchanged local notification retains zero-HTTP skip.');
$remote->records['b:Computer'][201]['name'] = 'B changed';
AgentInbox::receive($event, $token);
$service->processQueue(1);
agentCheck($DB->firstRow('glpi_computers', ['id' => 101])['name'] === 'B changed', 'Accepted B event bypasses unchanged A hash and applies inbound native text.');
agentCheck(receipt()['outcome'] === 'synchronized', 'Successful verified field update has synchronized outcome.');
agentCheck(!in_array('searchBySerial', array_column($remote->requests, 'method'), true)
    && !in_array('createItem', array_column($remote->requests, 'method'), true), 'Agent path never searches or creates.');
$event['revision'] = 2;
AgentInbox::receive($event, $token);
$service->processQueue(1);
agentCheck(receipt()['outcome'] === 'unchanged', 'Echo notification finishes unchanged and cannot loop writes.');

foreach (['entity_exit', 'missing', 'permission', 'both'] as $case) {
    [$event, $token, $asset] = agentSetup();
    saveTestMappings('b', 'Computer', ['name' => ['glpi_b_field_key' => 'name', 'source_of_truth' => $case === 'both' ? 'both' : 'glpi_b']]);
    $remote = new FakeGlpiBClient();
    if ($case !== 'missing') {
        $remote->records['b:Computer'][201] = array_replace($asset, ['id' => 201, 'entities_id' => $case === 'entity_exit' ? 200 : 100,
            'name' => 'Newer B', 'date_mod' => '2026-02-01 00:00:00']);
    }
    Computer::$allowUpdate = $case !== 'permission';
    AgentInbox::receive($event, $token);
    (new AssetSyncService($remote))->processQueue(1);
    agentCheck(receipt()['outcome'] === ($case === 'both' ? 'synchronized' : 'blocked'), 'Agent path preserves entity, missing-link, local permission and Both policies: ' . $case);
    agentCheck(!in_array('searchBySerial', array_column($remote->requests, 'method'), true)
        && !in_array('createItem', array_column($remote->requests, 'method'), true), 'No fallback create/search after blocked agent reads: ' . $case);
}

// Agent scope must be verified before preparation can persist derived billing outputs.
[$event, $token, $asset] = agentSetup();
$billingKeys = [];
foreach (['redeployedfield' => 'date', 'billablefield' => 'yesno', 'startfield' => 'date'] as $column => $type) {
    $id = count($billingKeys) + 901;
    $billingKeys[$column] = 'Computer.PluginFieldsComputerdmosasset.' . $column;
    PluginFieldsContainer::$options[$id] = ['name' => $column, 'table' => 'glpi_plugin_fields_computerdmosassets',
        'field' => $column, 'pfields_type' => $type, 'pfields_fields_id' => $id];
    PluginFieldsField::$definitions[$id] = ['id' => $id, 'name' => $column, 'type' => $type,
        'is_active' => 1, 'is_readonly' => 0, 'plugin_fields_containers_id' => 1];
}
\GlpiPlugin\Assetsync20\BillingFieldConfig::save(['hardware' => ['enabled' => false], 'swsd' => ['enabled' => true,
    'status' => 'name', 'redeployed_date' => $billingKeys['redeployedfield'],
    'swsd_billable' => $billingKeys['billablefield'], 'swsd_billing_start_date' => $billingKeys['startfield']]]);
$DB->update('glpi_computers', ['name' => 'In Use'], ['id' => 101]);
PluginFieldsComputerdmosasset::$rows[101] = ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer',
    'plugin_fields_containers_id' => 1, 'redeployedfield' => '2026-01-01', 'billablefield' => 0, 'startfield' => ''];
$beforeBilling = PluginFieldsComputerdmosasset::$rows[101];
saveTestMappings('b', 'Computer', [
    $billingKeys['billablefield'] => ['glpi_b_field_key' => $billingKeys['billablefield'], 'source_of_truth' => 'glpi_a'],
    $billingKeys['startfield'] => ['glpi_b_field_key' => $billingKeys['startfield'], 'source_of_truth' => 'glpi_a'],
]);
$remote = new FakeGlpiBClient();
$remote->records['b:Computer'][201] = array_replace($asset, ['id' => 201, 'entities_id' => 200]);
AgentInbox::receive($event, $token);
(new AssetSyncService($remote))->processQueue(1);
agentCheck(receipt()['outcome'] === 'blocked' && PluginFieldsComputerdmosasset::$rows[101] === $beforeBilling,
    'Agent entity exit must block before derived billing preparation writes A.');
$remote->records['b:Computer'][201]['entities_id'] = 100;
$remote->records['b:Computer'][201]['name'] = 'In Use';
saveTestMappings('b', 'Computer', ['name' => ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_b']]);
$event['revision'] = 2;
AgentInbox::receive($event, $token);
(new AssetSyncService($remote))->processQueue(1);
agentCheck(receipt()['outcome'] === 'synchronized' && (int) PluginFieldsComputerdmosasset::$rows[101]['billablefield'] === 1,
    'Billing-only successful local writes must count as synchronized, not unchanged.');

// Custom names/IDs are different on each side; the agent uses the same mapping engine.
foreach (['text', 'dropdown'] as $type) {
    [$event, $token, $asset] = agentSetup();
    $column = $type === 'text' ? 'notesfield' : 'plugin_fields_departmentfielddropdowns_id';
    $a = 'Computer.PluginFieldsComputerdmosasset.' . $column;
    $b = 'Computer.PluginFieldsComputerremoteonly.' . ($type === 'text' ? 'differentfield' : 'plugin_fields_categoryfielddropdowns_id');
    PluginFieldsContainer::$options = [801 => ['name' => 'Local custom',
        'table' => $type === 'text' ? 'glpi_plugin_fields_computerdmosassets' : 'glpi_plugin_fields_departmentfielddropdowns',
        'field' => $type === 'text' ? $column : 'completename', 'linkfield' => $column, 'pfields_type' => $type, 'pfields_fields_id' => 81,
        'joinparams' => ['beforejoin' => ['table' => 'glpi_plugin_fields_computerdmosassets']]]];
    PluginFieldsField::$definitions[81] = ['id' => 81, 'name' => $type === 'text' ? $column : 'departmentfield',
        'type' => $type, 'is_active' => 1, 'is_readonly' => 1, 'plugin_fields_containers_id' => 1];
    PluginFieldsComputerdmosasset::$rows[101] = ['id' => 10, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 1,
        $column => $type === 'text' ? 'before' : 4];
    PluginFieldsDepartmentfieldDropdown::$rows = [4 => ['id' => 4, 'name' => 'Before'], 5 => ['id' => 5, 'name' => 'After']];
    saveTestMappings('b', 'Computer', [$a => ['glpi_b_field_key' => $b, 'source_of_truth' => 'glpi_b']]);
    $remote = new FakeGlpiBClient();
    $remote->records['b:Computer'][201] = array_replace($asset, ['id' => 201, 'entities_id' => 100]);
    $remote->customRecords['b:Computer'][201][$b] = $type === 'text' ? 'after' : 89;
    $remote->customDropdownOptions[$b] = [['id' => 88, 'name' => 'Before'], ['id' => 89, 'name' => 'After']];
    AgentInbox::receive($event, $token);
    (new AssetSyncService($remote))->processQueue(1);
    agentCheck(receipt()['outcome'] === 'synchronized' && PluginFieldsComputerdmosasset::$rows[101][$column] === ($type === 'text' ? 'after' : 5),
        'Agent respects existing read-only service policy and maps independently named custom ' . $type . ' metadata/IDs.');
}

$states = ['queue_status' => 'done', 'agent_completed' => 2, 'work_generation' => 2, 'received_revision' => 2, 'admitted_revision' => 2, 'outcome' => 'unchanged'];
agentCheck(\GlpiPlugin\Assetsync20\AgentActivity::outcome($states) === 'unchanged', 'Activity does not equate queue completion with field synchronization.');
foreach (['running' => 'processing', 'retry' => 'retrying', 'done' => 'queued'] as $queueStatus => $outcome) {
    agentCheck(\GlpiPlugin\Assetsync20\AgentActivity::outcome(array_replace($states, ['queue_status' => $queueStatus, 'work_generation' => 3])) === $outcome,
        'Activity exposes outstanding notification outcome: ' . $outcome);
}

foreach ([['requested_a_id' => 101], ['revision' => '1'], ['lifecycle' => 'move'], ['computer_id' => -1]] as $invalid) {
    try { AgentInbox::decode(json_encode(array_replace($event, $invalid))); throw new LogicException('Accepted invalid event.'); }
    catch (InvalidArgumentException) { agentCheck(true, 'Identity-only payload validation rejects unsupported input.'); }
}
agentCheck(AgentInbox::decode(json_encode($event)) === $event, 'Exact bounded event schema roundtrips.');
echo "Agent prototype tests passed ($checks checks).\n";
