<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-scalars.php';

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20agent\Outbox;
use GlpiPlugin\Assetsync20agent\TargetScope;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;
use GlpiPlugin\Assetsync20agent\Worker;

class Printer extends Computer
{
    public static array $unreadable = [];
    public static function getTable(): string { return 'glpi_printers'; }
    public function can($id, $right): bool { return !in_array($id, self::$unreadable, true) && parent::can($id, $right); }
}
class PluginFieldsPrinterscalar extends PluginFieldsComputerscalar
{
    public static function getTable(): string { return 'glpi_plugin_fields_printerscalars'; }
}

// Cold start has no routes, mappings, pairs, subscriptions or REST credentials.
$settings = scalarSetup();
AssetSyncLink::install(); AssetSyncQueue::install(); AgentInbox::install(); AgentTask::install(); TaskJournal::install();
$DB->doQuery('CREATE TABLE glpi_entities (id INTEGER PRIMARY KEY, entities_id INT, name TEXT, completename TEXT)');
$DB->insert('glpi_entities', ['id' => 100, 'entities_id' => 0, 'name' => 'Scope', 'completename' => 'Scope']);
$DB->doQuery('CREATE TABLE glpi_profiles_users (id INTEGER PRIMARY KEY, users_id INT, profiles_id INT, entities_id INT, is_recursive INT)');
$DB->insert('glpi_profiles_users', ['id' => 1, 'users_id' => 7, 'profiles_id' => 4, 'entities_id' => 100, 'is_recursive' => 0]);
GlpiBConnection::save(['id' => 'b', 'mode' => 'agent', 'base_url' => 'https://b.invalid', 'active' => true]);
$settings = array_replace($settings, AgentTask::register('b', $settings['installation']));
Config::$values['plugin:assetsync20agent'] = $settings;
$request = array_intersect_key($settings, array_flip(['registration', 'generation', 'installation', 'connection_id']));
$response = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $settings['token']);
TargetScope::receive($response['subscriptions'], $settings);
scalarCheck($response['subscriptions']['updates'] === [] && iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === [], 'Cold-start registration has no subscriptions.');
AgentTask::discover('b', 'Computer', -1);
scalarCheck(AgentTask::discovery('b')['state'] === 'pending', 'Cold-start discovery visibly waits for B.');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $settings['token'])['task'];
$result = TaskJournal::run($task, $settings);
scalarReceipt($request, $settings['token'], $task, $result);
scalarCheck(AgentTask::discovery('b')['state'] === 'ready' && count($result['discovery']['rows']) === 2
    && iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === []
    && iterator_to_array($DB->request(['FROM' => AgentTask::PAIRS])) === [], 'Zero-subscription discovery returns permitted metadata without enrolling or pairing.');

[$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'old', 'new');
$DB->doQuery('CREATE TABLE glpi_printers AS SELECT * FROM glpi_computers');
$DB->doQuery('CREATE TABLE glpi_plugin_fields_printerscalars AS SELECT * FROM glpi_plugin_fields_computerscalars');
$DB->doQuery("UPDATE glpi_plugin_fields_printerscalars SET itemtype = 'Printer', plugin_fields_containers_id = 2");
$container = $DB->firstRow('glpi_plugin_fields_containers', ['id' => 1]);
$DB->insert('glpi_plugin_fields_containers', array_replace($container, ['id' => 2, 'itemtypes' => '["Printer"]']));
foreach (iterator_to_array($DB->request(['FROM' => 'glpi_plugin_fields_fields'])) as $field) {
    $DB->insert('glpi_plugin_fields_fields', array_replace($field, ['id' => $field['id'] + 10, 'plugin_fields_containers_id' => 2]));
}
Search::$typedOptions['Printer'] = Search::$options;
foreach (Search::$typedOptions['Printer'] as &$option) {
    $option['table'] = str_replace('computer', 'printer', $option['table']);
    $option['uid'] = str_replace('Computer', 'Printer', $option['uid']);
    if (isset($option['pfields_fields_id'])) { $option['pfields_fields_id'] += 10; $option['plugin_fields_containers_id'] = 2; }
}
unset($option);
scalarCheck(TargetScope::subscription('Printer', 201, $settings) === null, 'Migration never enrolls inventory or the old configured Computer.');
$beforeComputer = $DB->firstRow('glpi_computers', ['id' => 201]);
$beforeComputerFields = scalarRow(201);
$route = EntitySyncRoute::find('route');
EntitySyncRoute::save(array_replace($route, ['id' => 'printer-route', 'asset_types' => ['Printer']]));
AgentTask::declare('b', $settings['installation'], 101, 201, 100, 'Printer');
$printerPair = AgentTask::pair('b', 'Printer', 101);
$candidatePage = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token)['subscriptions'];
scalarCheck(!in_array($printerPair['id'], array_column($candidatePage['updates'], 'pair_id'), true), 'An unconfirmed candidate never enrolls a subscription.');
$task = AgentTask::exchange($request + ['kind' => 'pull'], $token)['task'];
scalarCheck($task['version'] === 3 && $task['itemtype'] === 'Printer' && $task['items_id'] === 201 && !isset($task['computer_id']), 'V3 target is explicitly typed.');
$inspection = TaskExecutor::execute($task, $settings);
scalarCheck($inspection['outcome'] === 'unchanged' && isset($inspection['snapshot']['fields']['Printer.PluginFieldsPrinterscalar.datefield']), 'Printer independently discovers its own container and scalar metadata.');
scalarReceipt($request, $token, $task, $inspection);
AgentTask::confirm('b', 'printer-route', $printerPair['id']);
$freshPrinter = AgentTask::byId('b', $printerPair['id']);
$DB->update(AgentTask::PAIRS, ['observed_at' => time() - 301], ['id' => $printerPair['id']]);
scalarReject(static fn () => AgentTask::confirm('b', 'printer-route', $printerPair['id']), 'Stale independent observation blocks confirmation.');
$DB->update(AgentTask::PAIRS, ['observed_at' => $freshPrinter['observed_at']], ['id' => $printerPair['id']]);
Printer::$unreadable = [101];
scalarReject(static fn () => AgentTask::confirm('b', 'printer-route', $printerPair['id']), 'Pair confirmation requires fresh operator READ.');
scalarCheck(!AgentTask::catalog('b', 'Printer')['success'], 'Catalog withholds metadata when operator cannot read its A pair.');
Printer::$unreadable = [];
$subscriptions = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token);
scalarCheck($subscriptions['task'] === null && strlen(json_encode($subscriptions)) <= 65536, 'Subscription-only pull is bounded and never delivers or changes a task.');
TargetScope::receive($subscriptions['subscriptions'], $settings);
scalarCheck(TargetScope::subscription('Printer', 201, $settings) !== null && TargetScope::subscription('Computer', 201, $settings) !== null,
    'Only A confirmed typed pairs enroll notification subscriptions.');
$savedSubscriptions = iterator_to_array($DB->request(['FROM' => TargetScope::TABLE]));
TargetScope::receive($subscriptions['subscriptions'], $settings);
scalarCheck(iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === $savedSubscriptions, 'Replay never extends A-issued expiry.');
$omitted = $subscriptions['subscriptions']; $omitted['updates'] = [];
TargetScope::receive($omitted, $settings);
scalarCheck(iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === $savedSubscriptions, 'Omitted pages never revoke subscriptions.');
$forgedBinding = $subscriptions['subscriptions']; $forgedBinding['installation'] = str_repeat('0', 32);
scalarReject(static fn () => TargetScope::receive($forgedBinding, $settings), 'Subscription transport binding must match exactly.');
$approved = json_decode(AgentTask::registration(AgentTask::binding('b'))['routes'], true);
scalarCheck(isset($approved['route'], $approved['printer-route']), 'Confirming Printer appends route approval without dropping Computer approval.');
AgentTask::scope(AgentTask::pair('b', 'Computer', 101));
AgentTask::scope(AgentTask::pair('b', 'Printer', 101));
scalarCheck(AssetSyncLink::find('Printer', 101, 'b')['remote_items_id'] === 201, 'Equal numeric typed links coexist.');

$pair = AgentTask::pair('b', 'Printer', 101);
$changes = [];
foreach (['name' => 'New printer', 'comment' => 'Printer comments', 'Printer.PluginFieldsPrinterscalar.textfield' => 'Printer text',
    'Printer.PluginFieldsPrinterscalar.yesfield' => 1, 'Printer.PluginFieldsPrinterscalar.datefield' => '2028-02-29'] as $key => $value) {
    $field = $inspection['snapshot']['fields'][$key];
    $changes[$key] = ['type' => $field['type'], 'metadata' => $field['metadata'], 'expected' => $field['value'], 'value' => $value];
}
$task = AgentTask::publish($pair, 'apply', $changes, null, []);
TaskJournal::install();
$result = TaskJournal::run($task, $settings);
scalarCheck($result['outcome'] === 'verified' && count($result['changed']) === 5, 'One typed writer verifies Name, Comments, text, yesno and date with named History.');
scalarCheck($DB->firstRow('glpi_computers', ['id' => 201]) === $beforeComputer && scalarRow(201) === $beforeComputerFields, 'Printer writes cannot touch equal numeric Computer rows.');
foreach ($DB->request(['FROM' => 'glpi_logs']) as $log) { scalarCheck($log['itemtype'] === 'Printer', 'Printer History uses the actual type.'); }
AgentTask::unconfirm('b', $printerPair['id']);
$revocations = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token);
TargetScope::receive($revocations['subscriptions'], $settings);
TargetScope::receive($subscriptions['subscriptions'], $settings);
scalarCheck(TargetScope::subscription('Printer', 201, $settings) === null, 'Explicit revocation rejects older active revision replay.');
$journal = $DB->firstRow(TaskJournal::TABLE, ['id' => $task['id']]);
scalarCheck(TaskJournal::run($task, $settings) === $result && $DB->firstRow(TaskJournal::TABLE, ['id' => $task['id']]) === $journal, 'Revoked subscription does not prevent immutable durable result replay.');
$newTask = $task; $newTask['id'] = bin2hex(random_bytes(16)); $newTask = scalarHash($newTask);
TaskExecutor::validate($newTask, $settings);
scalarReject(static fn () => AgentTask::publish(AgentTask::byId('b', $printerPair['id']), 'apply', $changes, null, []), 'Only A confirmed pair may publish an apply task; subscription is not write authority.');
scalarReceipt($request, $token, $task, $result);
AgentTask::confirm('b', 'printer-route', $printerPair['id']);

// An uncertain pair keeps its exact slot while another Computer and Printer remain independent.
$computerPair = AgentTask::pair('b', 'Computer', 101);
$held = AgentTask::publish($computerPair, 'inspect', [], null, []);
$DB->update(AgentTask::TABLE, ['state' => 'uncertain'], ['id' => $held['id']]);
$newComputer = $DB->firstRow('glpi_computers', ['id' => 101]);
$DB->insert('glpi_computers', array_replace($newComputer, ['id' => 102]));
$DB->insert('glpi_computers', array_replace($newComputer, ['id' => 202]));
$DB->insert(PluginFieldsComputerscalar::getTable(), array_replace(scalarRow(201), ['id' => 202, 'items_id' => 202]));
AgentTask::declare('b', $settings['installation'], 102, 202, 100);
AgentTask::inspect('b', $printerPair['id']);
scalarCheck(AgentTask::outstanding('b', $computerPair)['id'] === $held['id']
    && AgentTask::outstanding('b', AgentTask::pair('b', 'Computer', 102)) !== null
    && AgentTask::outstanding('b', $printerPair) !== null && AgentTask::pair('b') === null,
    'Multiple Computers and Printer have independent slots; ambiguous connection-only lookup fails closed.');
scalarReject(static fn () => AgentTask::retryStale('b', $computerPair['id']), 'Uncertain retry stays forbidden.');
scalarReject(static fn () => AgentTask::declare('b', $settings['installation'], 102, 201, 100, 'Printer'), 'Reverse typed uniqueness rejects reuse.');
$secondPair = AgentTask::pair('b', 'Computer', 102);
$secondTask = json_decode(AgentTask::outstanding('b', $secondPair)['body'], true);
$secondResult = TaskExecutor::execute($secondTask, $settings);
scalarReceipt($request, $token, $secondTask, $secondResult);
AgentTask::confirm('b', 'route', $secondPair['id']);
scalarCheck((bool) AgentTask::byId('b', $secondPair['id'])['confirmed'] && AgentTask::outstanding('b', $computerPair)['id'] === $held['id'],
    'A second Computer completes inspection/confirmation while the first remains uncertain.');
$forgedTask = $secondTask; $forgedTask['pair_id'] = $computerPair['id'];
scalarCheck(AgentTask::taskPair($forgedTask) === null, 'Pair ID cannot authenticate a different typed task target.');

// An almost-full immutable task leaves no room for updates; separate pull still progresses.
$largeChanges = ['name' => ['type' => 'text', 'metadata' => str_repeat('a', 64), 'expected' => str_repeat('x', 16384), 'value' => str_repeat('y', 16384)],
    'comment' => ['type' => 'text', 'metadata' => str_repeat('b', 64), 'expected' => str_repeat('x', 16000), 'value' => '']];
$template = array_replace($secondTask, ['kind' => 'apply', 'changes' => $largeChanges]);
$largeChanges['comment']['value'] = str_repeat('y', 65480 - strlen(json_encode(['status' => 200, 'task' => $template])));
$large = AgentTask::publish(AgentTask::byId('b', $secondPair['id']), 'apply', $largeChanges, null, []);
$DB->update(AgentTask::TABLE, ['created_at' => 0], ['id' => $large['id']]);
$largeResponse = AgentTask::exchange($request + ['kind' => 'pull'], $token);
scalarCheck($largeResponse['task']['id'] === $large['id'] && strlen(json_encode($largeResponse)) <= 65536
    && !isset($largeResponse['subscriptions']), 'Near-limit task cannot inflate the 64 KiB response with subscription updates.');
$separateResponse = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token);
scalarCheck($separateResponse['task'] === null && count($separateResponse['subscriptions']['updates']) === 3
    && strlen(json_encode($separateResponse)) <= 65536, 'Subscription-only pull progresses independently of the large outstanding task.');
$largeChanges['comment']['value'] = str_repeat('z', 16384);
scalarReject(static fn () => AgentTask::publish(AgentTask::byId('b', $secondPair['id']), 'apply', $largeChanges, null, [], $large['id']), 'Oversized task is rejected before replacing its predecessor.');
scalarCheck(AgentTask::outstanding('b', $secondPair)['id'] === $large['id'], 'Oversized publication preserves predecessor slot.');

// Receipt and outbox identities include type, including claim and ACK fencing.
Outbox::install();
TargetScope::receive(AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token)['subscriptions'], $settings);
foreach (['Computer', 'Printer'] as $type) {
    Outbox::observe(201, ['fingerprint' => hash('sha256', $type), 'lifecycle' => 'present'], false, null, $type);
    $event = ['registration' => $settings['registration'], 'generation' => $settings['generation'], 'computer_id' => 201,
        'itemtype' => $type, 'revision' => 1, 'lifecycle' => 'present'];
    $ack = AgentInbox::receive(AgentInbox::decode(json_encode($event)), $token);
    scalarCheck($ack['accepted'] && $ack['itemtype'] === $type, 'Typed receipt ACK: ' . $type);
}
scalarCheck(count(iterator_to_array($DB->request(['FROM' => AgentInbox::RECEIPTS]))) === 2, 'Equal numeric receipts remain distinct.');
$sent = Outbox::claim($settings);
$sent['computer_id'] = (int) $sent['computer_id']; $sent['revision'] = (int) $sent['revision'];
$ack = ['accepted' => true] + array_intersect_key($sent, array_flip(['registration', 'generation', 'computer_id', 'itemtype', 'revision']));
$wrong = $ack; $wrong['itemtype'] = $sent['itemtype'] === 'Computer' ? 'Printer' : 'Computer';
scalarCheck(!Outbox::acknowledge($sent, $wrong, $settings) && Outbox::acknowledge($sent, $ack, $settings), 'ACK cannot cross a typed claim.');
$remainingType = $sent['itemtype'] === 'Computer' ? 'Printer' : 'Computer';
$pending = Outbox::find(201, $remainingType);
$originalSession = $_SESSION;
User::$active = false;
scalarCheck(Outbox::claim($settings) === null && $_SESSION === $originalSession, 'Inactive service account cannot claim; caller context is restored.');
$protected = array_flip(['revision', 'acked_revision', 'fingerprint', 'generation']);
scalarCheck(array_intersect_key(Outbox::find(201, $remainingType), $protected) === array_intersect_key($pending, $protected), 'Failed fresh account authorization preserves old outbox watermarks.');
User::$active = true;
$DB->update(Outbox::TABLE, ['next_attempt' => 0], ['itemtype' => $remainingType]);
PluginFieldsProfile::$right = READ;
scalarCheck(Outbox::claim($settings) === null, 'Readonly container permission cannot authorize a notification claim.');
PluginFieldsProfile::$right = UPDATE;
$DB->update(Outbox::TABLE, ['next_attempt' => 0], ['itemtype' => $remainingType]);
$beforeExpiry = Outbox::find(201, $remainingType);
$DB->update(TargetScope::TABLE, ['expires' => time() - 1], ['itemtype' => $remainingType]);
scalarCheck(Outbox::claim($settings) === null && Outbox::find(201, $remainingType) === $beforeExpiry, 'Expired subscriptions cannot claim or reset outbox revisions.');

// The database page never scans beyond 51 candidates even when none are readable.
for ($id = 301; $id <= 355; $id++) { $DB->insert('glpi_printers', array_replace($newComputer, ['id' => $id, 'name' => 'Page ' . $id])); }
Printer::$unreadable = range(301, 350);
$page = TargetScope::page('Printer', 100, 'Page', 0);
scalarCheck($page['rows'] === [] && $page['next_cursor'] === 350, 'Unreadable names/serials are withheld with bounded cursor progress.');
Printer::$unreadable = [];
$page = TargetScope::page('Printer', 100, 'Page', 0);
scalarCheck(count($page['rows']) === 50 && $page['next_cursor'] === 350, 'Selectable page is capped at 50.');
scalarReject(static fn () => AgentTask::selectPage([], 'Printer', 100, 'Page', 0, [355]), 'A cart rejects forged off-page selection.');
$cart = AgentTask::selectPage([], 'Printer', 100, 'Page', 0, array_column($page['rows'], 'items_id'));
$cart = AgentTask::selectPage($cart, 'Printer', 100, 'Page', 350, [355]);
scalarCheck(count($cart) === 51 && isset($cart[301], $cart[355]) && !isset($cart[354]), 'A page selection retains only explicit previous choices.');
scalarReject(static fn () => AgentTask::selectPage(array_fill_keys(range(1000, 1199), 100), 'Printer', 100, 'Page', 350, [355]), 'A selection cart cannot grow past 200.');
Printer::$unreadable = [355];
scalarReject(static fn () => AgentTask::selectedAsset('Printer', 355, 100), 'A cart revalidates operator READ before pairing.');
Printer::$unreadable = [];
scalarReject(static fn () => TargetScope::model('User'), 'Arbitrary classes cannot enter selectors or tasks.');

$DB->doQuery('CREATE TABLE glpi_profiles_users (id INTEGER PRIMARY KEY, users_id INT, profiles_id INT, entities_id INT, is_recursive INT)');
$DB->insert('glpi_profiles_users', ['id' => 1, 'users_id' => 7, 'profiles_id' => 4, 'entities_id' => 100, 'is_recursive' => 0]);
$tasksBefore = iterator_to_array($DB->request(['FROM' => AgentTask::PAIRS]));
$targetsBefore = iterator_to_array($DB->request(['FROM' => TargetScope::TABLE]));
AgentTask::discover('b', 'Printer', -1, 'Page');
$discoveryTask = json_decode(AgentTask::discoveryTask('b')['body'], true);
$pageResult = TaskExecutor::execute($discoveryTask, $settings);
scalarCheck($pageResult['outcome'] === 'unchanged' && count($pageResult['discovery']['rows']) === 50
    && $pageResult['discovery']['next_cursor'] === 350, 'Readonly discovery does not filter by subscription membership.');
scalarReceipt($request, $token, $discoveryTask, $pageResult);
scalarCheck(AgentTask::discovery('b')['state'] === 'ready' && iterator_to_array($DB->request(['FROM' => AgentTask::PAIRS])) === $tasksBefore
    && iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === $targetsBefore, 'Discovery results never create, confirm or authorize pairs.');
scalarReject(static fn () => AgentTask::exchange(array_replace($request, ['kind' => 'pull', 'connection_id' => 'forged']), $token), 'Correct token cannot forge a different connection.');
$forged = $inspection; $forged['snapshot']['pair_id'] = $computerPair['id'];
scalarReject(static fn () => (new ReflectionMethod(AgentTask::class, 'validateResult'))->invoke(null,
    json_decode(AgentTask::outstanding('b', $printerPair)['body'], true), $forged), 'Typed snapshot cannot forge another pair ownership.');
AgentTask::discover('b', 'Printer', 100, 'No matching name');
$emptyTask = json_decode(AgentTask::discoveryTask('b')['body'], true);
$emptyResult = TaskExecutor::execute($emptyTask, $settings);
scalarReceipt($request, $token, $emptyTask, $emptyResult);
scalarCheck($emptyResult['discovery']['rows'] === [], 'Empty discovery is a successful readonly result.');
$revokedTask = $emptyTask; $revokedTask['id'] = bin2hex(random_bytes(16)); $revokedTask = scalarHash($revokedTask);
Profile_User::$granted = false;
scalarCheck(TaskExecutor::execute($revokedTask, $settings)['outcome'] === 'blocked', 'Discovery cannot bypass a revoked service grant.');
Profile_User::$granted = true;

$retained = Outbox::find(201, 'Printer');
Profile_User::$granted = false;
(new Worker())->reconcile();
scalarCheck(Outbox::find(201, 'Printer') === $retained && Outbox::find(355, 'Printer') === null
    && Outbox::find(301, 'Printer') === null, 'Worker fresh authorization failure preserves outbox and never enrolls discovered inventory.');
Profile_User::$granted = true;
$hookType = $sent['itemtype'];
$hookItem = TargetScope::model($hookType);
$hookItem->getFromDB(201);
$beforeHook = Outbox::find(201, $hookType);
User::$active = false;
\GlpiPlugin\Assetsync20agent\ChangeHook::changed($hookItem);
scalarCheck(Outbox::find(201, $hookType) === $beforeHook && (int) TargetScope::subscription($hookType, 201, $settings)['dirty'] === 1,
    'Hook records protocol dirtiness without privileged fingerprints or service execution.');
User::$active = true;
(new Worker())->reconcile();
scalarCheck((int) TargetScope::subscription($hookType, 201, $settings)['dirty'] === 0
    && (int) Outbox::find(201, $hookType)['revision'] > (int) $beforeHook['revision'] && $_SESSION === $originalSession,
    'Worker fingerprints dirty subscribed assets under fresh service context and restores caller state.');

// Ordered subscription pages advance without touching any outstanding task slot.
$heldBefore = $DB->firstRow(AgentTask::TABLE, ['id' => $held['id']]);
for ($n = 0; $n < 61; $n++) {
    $DB->insert(AgentTask::PAIRS, array_replace($computerPair, ['id' => sprintf('%032x', 10000 + $n),
        'local_id' => 1000 + $n, 'computer_id' => 2000 + $n, 'confirmed' => 0, 'subscription_revision' => 1,
        'subscription_issued' => time(), 'subscription_active' => 1, 'subscription_generation' => $settings['generation']]));
}
$DB->update(AgentTask::BINDINGS, ['subscription_cursor' => '', 'subscription_revision' => 100], ['connection_id' => 'b']);
$firstPage = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token)['subscriptions'];
$secondPage = AgentTask::exchange($request + ['kind' => 'pull', 'subscriptions_only' => true], $token)['subscriptions'];
scalarCheck(count($firstPage['updates']) === 50 && count($secondPage['updates']) === 14
    && count(array_unique(array_column(array_merge($firstPage['updates'], $secondPage['updates']), 'pair_id'))) === 64,
    'Bounded subscription cursor fairly visits later pairs without relying on task completion.');
scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $held['id']]) === $heldBefore, 'Lease pages never reset uncertain task bytes or slots.');

// Migration starts from genuine old keys and preserves every old row value and task byte.
$DB = new ScalarDB();
Config::$values['plugin:assetsync20agent'] = $settings;
$DB->doQuery('CREATE TABLE ' . Outbox::TABLE . ' (computer_id INTEGER PRIMARY KEY, revision BIGINT DEFAULT 0, acked_revision BIGINT DEFAULT 0,
    fingerprint CHAR(64), lifecycle VARCHAR(12), generation CHAR(32), claim_token CHAR(32), lease_until BIGINT, next_attempt BIGINT,
    attempts INT, last_outcome VARCHAR(20), last_ack BIGINT, changed_at BIGINT)');
$DB->insert(Outbox::TABLE, ['computer_id' => 201, 'revision' => 17, 'acked_revision' => 4, 'fingerprint' => str_repeat('f', 64), 'last_outcome' => 'blocked']);
$oldOutbox = $DB->firstRow(Outbox::TABLE, ['computer_id' => 201]);
$DB->doQuery('CREATE TABLE ' . AgentTask::PAIRS . ' (connection_id VARCHAR(255) PRIMARY KEY, registration CHAR(32), installation CHAR(32),
    local_id INT, local_entity INT, computer_id INT, entity_id INT, serial VARCHAR(255), route_id VARCHAR(255), confirmed INT, snapshot TEXT,
    observed_at BIGINT, local_fingerprint CHAR(64), checked_at BIGINT)');
$DB->insert(AgentTask::PAIRS, array_diff_key($computerPair, array_flip(['id', 'itemtype', 'subscription_revision', 'subscription_issued', 'subscription_active', 'subscription_generation'])));
$oldPair = $DB->firstRow(AgentTask::PAIRS, ['connection_id' => 'b']);
$DB->doQuery('CREATE TABLE `' . AgentInbox::RECEIPTS . '` (`id` INTEGER PRIMARY KEY, `registration_id` CHAR(32), `computer_id` INT,
    `received_revision` BIGINT, `admitted_revision` BIGINT, `completed_revision` BIGINT, `work_generation` BIGINT, `queue_id` INT,
    `lifecycle` VARCHAR(12), `outcome` VARCHAR(20), `received_at` BIGINT, `outcome_at` BIGINT, UNIQUE (`registration_id`, `computer_id`))');
$DB->insert(AgentInbox::RECEIPTS, ['id' => 1, 'registration_id' => $settings['registration'], 'computer_id' => 201,
    'received_revision' => 19, 'admitted_revision' => 16, 'completed_revision' => 4, 'outcome' => 'uncertain']);
$oldReceipt = $DB->firstRow(AgentInbox::RECEIPTS, ['id' => 1]);
AgentTask::install(); TaskJournal::install(); AgentInbox::install();
$legacyBody = ['version' => 2, 'id' => str_repeat('f', 32), 'kind' => 'apply', 'installation' => $settings['installation'],
    'registration' => $settings['registration'], 'generation' => $settings['generation'], 'connection_id' => 'b',
    'computer_id' => 201, 'entity_id' => 100, 'serial' => 'SCALAR-1', 'policy' => '', 'expires' => time() - 1, 'changes' => []];
$legacyBody = scalarHash($legacyBody);
$legacyRow = ['id' => $legacyBody['id'], 'connection_id' => 'b', 'slot' => 'b', 'hash' => $legacyBody['hash'], 'body' => json_encode($legacyBody),
    'state' => 'uncertain', 'result' => '{"outcome":"uncertain","changed":[]}', 'job' => '{}', 'context' => '{}', 'created_at' => time() - 500, 'expires' => time() - 1];
$DB->insert(AgentTask::TABLE, $legacyRow);
$oldTask = $DB->firstRow(AgentTask::TABLE, ['id' => $legacyBody['id']]);
foreach ([1, 2] as $pass) {
    scalarCheck(AgentTask::install() && AgentInbox::install() && Outbox::install() && TargetScope::install() && TaskJournal::install(), 'Restartable migrations pass ' . $pass);
    scalarCheck(array_intersect_key($DB->firstRow(Outbox::TABLE, ['computer_id' => 201]), $oldOutbox) === $oldOutbox, 'All old outbox values retained.');
    scalarCheck(array_intersect_key(AgentTask::pair('b'), $oldPair) === $oldPair, 'All old pair values retained.');
    scalarCheck(array_intersect_key($DB->firstRow(AgentInbox::RECEIPTS, ['id' => 1]), $oldReceipt) === $oldReceipt, 'All old receipt revisions and outcomes retained.');
    scalarCheck($DB->firstRow(AgentTask::TABLE, ['id' => $legacyBody['id']]) === $oldTask && AgentTask::outstanding('b')['slot'] === 'b', 'Legacy task bytes, results and unresolved slot retained.');
    scalarCheck(iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === [], 'Migration never seeds legacy Computer, old outbox or inventory subscriptions.');
}
Outbox::sql('DELETE FROM `' . TargetScope::TABLE . '`');
TargetScope::install();
scalarCheck(iterator_to_array($DB->request(['FROM' => TargetScope::TABLE])) === [], 'Repeated migration cannot enroll anything.');
$bindingBeforeConflict = AgentTask::binding('b');
$conflictingPair = array_replace(AgentTask::pair('b'), ['id' => str_repeat('e', 32), 'itemtype' => 'Printer', 'registration' => str_repeat('d', 32)]);
$DB->insert(AgentTask::PAIRS, $conflictingPair);
scalarCheck(!AgentTask::install(), 'Migration rejects inconsistent bindings across legacy pairs instead of accepting the first.');
scalarCheck(AgentTask::binding('b') === $bindingBeforeConflict, 'Conflicting migration leaves the existing binding unchanged.');
$DB->doQuery('DELETE FROM `' . AgentTask::PAIRS . '` WHERE `id` = ' . $DB->quote($conflictingPair['id']));
scalarCheck(AgentTask::install(), 'Consistent migration remains restartable after conflict diagnosis.');
echo "Multi-asset tests passed ($checks checks including scalar prerequisites; host doubles/SQLite only).\n";
ob_end_flush();
