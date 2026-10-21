<?php
declare(strict_types=1);

ob_start(); require __DIR__ . '/agent-rest-settings.php'; ob_end_clean();
if (!defined('UPDATE')) { define('UPDATE', 2); }
function isAPI(): bool { return false; }
function isCommandLine(): bool { return false; }

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\FieldEvidence;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\NameBoth;
use GlpiPlugin\Assetsync20\RestWork;

final class NameQueueDB
{
    public AgentSqlite $sql;
    public string $dbdefault = 'settings-test';
    public int $clock = 100;
    public int $error = 0;
    public bool $failReceiptCommit = false;
    public bool $failSettlement = false;
    public function __construct(public object $base) {
        $this->sql = $base->sql;
        $this->sql->pdo->sqliteCreateFunction('CONNECTION_ID', static fn () => 17, 0);
        $this->sql->pdo->sqliteCreateFunction('SHA2', static fn ($value, $bits) => hash('sha256', $value), 2);
        $this->sql->pdo->sqliteCreateFunction('JSON_KEYS', static fn ($json, $path) => json_encode(array_keys(json_decode($json, true)['attempts'])), 2);
    }
    public function __call(string $name, array $args): mixed { return $this->base->$name(...$args); }
    public function tableExists(string $table): bool { return $this->sql->tableExists($table); }
    public function fetchAssoc(PDOStatement $result): ?array { return $this->sql->fetchAssoc($result); }
    public function request(array $query): ArrayIterator { return $this->sql->request($query); }
    public function quote(string $value): string { return $this->sql->quote($value); }
    public function insert(string $table, array $fields): bool { return $this->sql->insert($table, $fields); }
    public function update(string $table, array $fields, array $where): bool { return $this->sql->update($table, $fields, $where); }
    public function affectedRows(): int { return $this->sql->affectedRows(); }
    public function fieldExists(string $table, string $field): bool { return $this->sql->fieldExists($table, $field); }
    public function errno(): int { return $this->error ?: 1305; }
    public function doQuery(string $sql): mixed {
        $this->error = 0;
        if ($sql === 'SELECT UNIX_TIMESTAMP() AS epoch') { return $this->sql->pdo->query('SELECT ' . $this->clock . ' AS epoch'); }
        if ($sql === 'SELECT @@SESSION.autocommit AS enabled') { return $this->sql->pdo->query('SELECT 1 AS enabled'); }
        if (str_starts_with($sql, 'SAVEPOINT as20_capture_')) { return !$this->sql->pdo->inTransaction() || $this->sql->doQuery($sql) !== false; }
        if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_capture_') && !$this->sql->pdo->inTransaction()) { $this->error = 1305; return false; }
        if (str_starts_with($sql, 'SELECT ENGINE FROM information_schema.TABLES')) { return $this->sql->pdo->query("SELECT 'InnoDB' AS ENGINE"); }
        if ($this->failSettlement && str_starts_with($sql, 'UPDATE `glpi_plugin_assetsync20_fieldevidence`')) { return false; }
        if ($sql === 'COMMIT' && $this->failReceiptCommit
            && $this->sql->pdo->query("SELECT COUNT(*) FROM glpi_plugin_assetsync20_agenttasks WHERE state='origin_pending'")->fetchColumn()) {
            $this->failReceiptCommit = false; $this->base->doQuery($sql); $this->failSettlement = true;
            throw new RuntimeException('Injected A lost committed receipt');
        }
        if ($sql === 'ROLLBACK' && !$this->sql->pdo->inTransaction()) { return false; }
        return $this->base->doQuery(str_replace(['`glpi_configs`', ' LOCK IN SHARE MODE'], ['test_config', ''], $sql));
    }
}

function nameQueueSetup(): array {
    global $DB;
    Session::$entityAccess = true;
    Computer::$beforeModelUpdate = Computer::$afterModelUpdate = null;
    $pair = settingsSetup();
    $DB = new NameQueueDB($DB);
    foreach (['id_search_option INTEGER', 'old_value TEXT', 'new_value TEXT', 'itemtype_link TEXT', 'linked_action INTEGER', 'user_name TEXT', 'date_mod TEXT'] as $column) { $DB->doQuery('ALTER TABLE glpi_logs ADD ' . $column); }
    $DB->doQuery('CREATE TABLE glpi_plugin_assetsync20_fieldevidence(computer_id INTEGER,field_key TEXT,model_class TEXT,generation INTEGER,evidence TEXT,PRIMARY KEY(computer_id,field_key))');
    foreach (['frames', 'owned', 'origins'] as $name) { (new ReflectionProperty(FieldEvidence::class, $name))->setValue(null, []); }
    FieldEvidence::activate();
    Computer::$beforeModelUpdate = static fn ($item) => FieldEvidence::before($item);
    Computer::$afterModelUpdate = static function ($item): void {
        global $DB;
        foreach ($item->updates as $column) {
            if (!in_array($column, ['name','comment'], true)) { continue; }
            $DB->insert('glpi_logs', ['items_id'=>101, 'itemtype'=>'Computer', 'id_search_option'=>$column === 'name' ? 1 : 16,
                'old_value'=>$item->oldvalues[$column], 'new_value'=>$item->input[$column], 'itemtype_link'=>'', 'linked_action'=>0, 'user_name'=>'Service (7)', 'date_mod'=>'2001-01-01']);
        }
        \GlpiPlugin\Assetsync20\AssetChangeHook::onUpdate($item);
    };
    $item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Browser A']); FieldEvidence::finishWork();
    $definitions = [['key'=>'name','uid'=>'name','id'=>'1','label'=>'Independent B Name','type'=>'text','group'=>'Native','supported'=>true]];
    FieldMapping::save('b', 'Computer', [['glpi_a_field_key'=>'name','glpi_b_field_key'=>'name','source_of_truth'=>'both']], $definitions);
    agentCheck(settingsBody()['version'] === 4 && settingsBody()['enabled'], 'Effective Both mapping allocates explicit settings4 on the current route revision.');
    publishPolicy(); RestDispatchWire::reset();
    return AgentTask::byId('b', $pair['id']);
}

function nameQueueContext(array $pair): array { return (new ReflectionMethod(RestWork::class,'context'))->invoke(null,$pair); }
function nameQueueTask(array $pair): array {
    global $DB;
    AssetSyncQueue::notify('Computer',101,'b');
    foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
    $task = AgentTask::outstanding('b',$pair);
    agentCheck($task !== null && json_decode($task['body'],true)['version'] === 11, 'Ordinary queue publishes Work11 with an immutable full guard.');
    return $task;
}
function nameQueueResult(array $task, string $winner = 'b'): array {
    $body = json_decode($task['body'],true);
    $b = $body['source']; $b['value'] = 'Newer browser B';
    $b['hash'] = hash('sha256',json_encode(['type'=>'text','value'=>$b['value']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $b['origin']['identity'] = str_repeat('b',32).':'.str_repeat('c',32); $b['origin']['from_epoch']=110; $b['origin']['to_epoch']=111;
    if ($winner === 'equal') { $b = $body['source']; }
    if ($winner === 'conflict') { $b['origin']=null; }
    if ($winner === 'a') { $b['origin']['from_epoch']=90; $b['origin']['to_epoch']=99; }
    return ['outcome'=>$winner === 'equal' ? 'unchanged' : ($winner === 'conflict' ? 'conflict' : 'verified'), 'changed'=>$winner === 'a' ? ['name'] : [], 'winner'=>$winner,
        'mutation'=>$winner === 'a' ? 'committed' : 'none', 'source_hash'=>$body['source_hash'], 'before'=>$b, 'after'=>$winner === 'a' ? $body['source'] : $b,
        'started_at'=>min(time(), $body['expires']-2), 'model_ended_at'=>min(time(), $body['expires']-1), 'within_deadline'=>true];
}
function receiveNameResult(array $task, array $result): void {
    global $DB;
    $DB->update(AgentTask::TABLE,['result'=>json_encode($result),'result_received_at'=>time()],['id'=>$task['id']]);
    $DB->update(AssetSyncQueue::TABLE,['status'=>'pending'],['id'=>(int)$task['queue_id']]);
}

$pair = nameQueueSetup(); $context = nameQueueContext($pair);
$checked = time(); $DB->update(AgentTask::PAIRS,['local_fingerprint'=>$context['fingerprint'],'checked_at'=>$checked],['id'=>$pair['id']]);
$pair = AgentTask::byId('b',$pair['id']);
agentCheck(!RestWork::needsWork($pair,false) && !(new AssetSyncService())->queueAssetIfNeeded('Computer',101,'b'), 'Ordinary scanner ignores eligible-pair unmapped-only state.');
$item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'comment'=>'Unmapped comments']); FieldEvidence::finishWork();
$newContext = nameQueueContext($pair);
agentCheck($context['source_hash'] !== $newContext['source_hash'] && $context['fingerprint'] === $newContext['fingerprint'], 'Comments advances exact guard but not Name original-event eligibility.');
agentCheck(queue()['status'] === 'pending', 'Actual AssetChangeHook onUpdate notifies the eligible route even for Comments-only save.');
$link = AssetSyncLink::find('Computer',101,'b');
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
RestWork::sendPending(hrtime(true)+20_000_000_000);
agentCheck(RestDispatchWire::$calls === [] && AgentTask::outstanding('b',$pair) === null && queue()['status'] === 'done', 'Queued unmapped save produces zero auth/per-asset HTTP and no new work.');
agentCheck(AgentTask::byId('b',$pair['id'])['checked_at'] == $checked && AssetSyncLink::find('Computer',101,'b') === $link, 'Local skip does not postpone probe or fabricate last-verified/link success.');
$DB->update(AgentTask::PAIRS,['checked_at'=>time()-61],['id'=>$pair['id']]);
agentCheck((new AssetSyncService())->queueAssetIfNeeded('Computer',101,'b'), 'Ordinary bounded scanner schedules due B-only current-state reconciliation, without forced POST.');
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
$task = AgentTask::outstanding('b',$pair); $body = json_decode($task['body'],true);
agentCheck($body['version'] === 11, 'Due unchanged A still publishes the separately counted interim probe.');
$result = nameQueueResult($task); $record = settingsWorkRecord($task,$result);
settingsDispatch([['status'=>207,'body'=>[['id'=>77]]],['status'=>200,'body'=>[$record]]]);
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
$done = $DB->firstRow(AgentTask::TABLE,['id'=>$task['id']]);
agentCheck($done['state'] === 'verified' && $done['slot'] === null && queue()['status'] === 'done', 'B-only ordinary probe finalizes only after A inherited value/History/evidence.');
agentCheck(FieldEvidence::envelope(101,'native:name')['origin'] === $result['after']['origin'] && $DB->firstRow('glpi_computers',['id'=>101])['name'] === 'Newer browser B', 'Actual A value and inherited original B interval agree.');
$pair = AgentTask::byId('b',$pair['id']);
agentCheck(!RestWork::needsWork($pair,false), 'Post-inheritance logical fingerprint prevents echo.');

foreach (['equal','conflict','uncertain'] as $case) {
    $pair = nameQueueSetup(); $task = nameQueueTask($pair); $before = iterator_to_array($DB->request(['FROM'=>'glpi_logs']));
    $result = $case === 'uncertain' ? ['outcome'=>'uncertain','changed'=>[]] : nameQueueResult($task,$case);
    receiveNameResult($task,$result);
    foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
    $saved = $DB->firstRow(AgentTask::TABLE,['id'=>$task['id']]);
    agentCheck(($saved['slot'] === null) === ($case !== 'uncertain'), 'Only qualified equality/proven no-write conflict may finish/release; uncertain slot remains.');
    agentCheck(iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === $before, 'Equality/conflict/uncertainty never fabricate History.');
}

$pair = nameQueueSetup(); $task = nameQueueTask($pair); $result = nameQueueResult($task); receiveNameResult($task,$result);
$DB->failReceiptCommit = true;
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
$pending = $DB->firstRow(AgentTask::TABLE,['id'=>$task['id']]);
agentCheck($pending['state'] === 'origin_pending' && $pending['slot'] !== null && queue()['status'] !== 'done', 'A lost postcommit reply retains receipt/slot and cannot claim completion.');
$history = iterator_to_array($DB->request(['FROM'=>'glpi_logs'])); $DB->failSettlement=false;
foreach (['frames','owned','origins'] as $name) { (new ReflectionProperty(FieldEvidence::class,$name))->setValue(null,[]); }
agentCheck((new AssetSyncService())->queueAssetIfNeeded('Computer',101,'b'), 'Ordinary job scanning resumes local committed metadata-only receipt recovery.');
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
agentCheck($DB->firstRow(AgentTask::TABLE,['id'=>$task['id']])['state'] === 'verified' && iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === $history, 'A receipt recovery verifies original evidence without a second local model write.');
echo "Name Both A queue PASS: scanner/queued unmapped zero RPC, separate due B-only probe, local inheritance/finalization, conflicts/uncertainty and committed recovery (mock HTTP/SQLite).\n";

$pair = nameQueueSetup(); $task = nameQueueTask($pair); $result = nameQueueResult($task); receiveNameResult($task,$result);
$_SESSION = ['glpicronuserrunning'=>'cron_assetsync20_sync', 'glpi_currenttime'=>gmdate('Y-m-d H:i:s')];
Session::$entityAccess = false;
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
agentCheck($DB->firstRow(AgentTask::TABLE,['id'=>$task['id']])['state'] === 'verified'
    && !isset($_SESSION['glpiID'],$_SESSION['glpiactiveentities']) && FieldEvidence::envelope(101,'native:name')['origin'] === $result['after']['origin'],
    'A cron-marker-only context uses existing fresh confirmed-route authorization, no synthetic login/entities, and inherits B origin.');
unset($_SESSION['glpicronuserrunning']);
try { NameBoth::localItem($pair,true); throw new LogicException('Interactive missing entity access accepted'); } catch (RuntimeException) {}
Session::$entityAccess = true;
echo "Name Both A cron-context component PASS; actual unmodified CLI WorkerCommand remains an independent native gate.\n";

$pair = nameQueueSetup(); $task = nameQueueTask($pair);
$body = json_decode($task['body'],true); $body['expires'] = time()-1;
$context = json_decode($task['context'],true); $context['rest_sent']=true; $context['rest_next']=0;
$DB->update(AgentTask::TABLE,['body'=>json_encode($body),'hash'=>AgentTask::hash($body),'expires'=>$body['expires'],'context'=>json_encode($context)],['id'=>$task['id']]);
$task = AgentTask::outstanding('b',$pair); $pending = settingsWorkRecord($task); $pending['state']='origin_pending';
$result = nameQueueResult($task,'a'); $terminal = settingsWorkRecord($task,$result);
RestDispatchWire::reset();
settingsDispatch([['status'=>200,'body'=>[$pending]], ['status'=>201,'body'=>[['id'=>77]]], ['status'=>200,'body'=>[$terminal]]]);
$calls = array_values(array_filter(RestDispatchWire::$calls,static fn ($c)=>!in_array($c['path'],['initSession','killSession'],true)));
agentCheck(array_column($calls,'method') === ['GET','POST','GET'] && AgentTask::outstanding('b',$pair)['result'] !== '{}',
    'Expired persisted origin_pending after complete GET drives identical POST metadata continuation, not GET-only stranding.');
$sent = json_decode($calls[1]['options'][CURLOPT_POSTFIELDS],true)['input'][0];
agentCheck($sent['work_id'] === $task['id'] && $sent['hash'] === $task['hash'] && $sent['body'] === $task['body'], 'Pending continuation never replaces immutable identity/body/hash.');

$pair = nameQueueSetup(); $task = nameQueueTask($pair); $result = nameQueueResult($task); receiveNameResult($task,$result);
$item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Latest A']); FieldEvidence::finishWork();
foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
agentCheck($DB->firstRow('glpi_computers',['id'=>101])['name'] === 'Latest A' && AgentTask::outstanding('b',$pair)['slot'] !== null,
    'A source guard race preserves latest browser edit and retains the old result, without a stale local write.');
echo "Name Both continuation/race component PASS.\n";

foreach (['stale', 'aba', 'comments'] as $change) {
    $pair = nameQueueSetup(); $task = nameQueueTask($pair);
    $before = nameQueueContext($pair);
    $item = new Computer(); $item->getFromDB(101);
    $item->update(['id'=>101, $change === 'comments' ? 'comment' : 'name'=>'Changed after preparation']);
    FieldEvidence::finishWork();
    if ($change === 'aba') {
        $item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>$before['evidence']['value']]);
        FieldEvidence::finishWork();
    }
    $fresh = nameQueueContext($pair);
    agentCheck($fresh['source_hash'] !== json_decode($task['body'],true)['source_hash'], 'Prepared exact guard detects ' . $change . '.');
    if ($change === 'comments') { agentCheck($fresh['fingerprint'] === $before['fingerprint'], 'Unmapped change keeps logical eligibility distinct from execution guard.'); }
    RestDispatchWire::reset();
    try { RestWork::sendPending(hrtime(true)+20_000_000_000); } catch (LogicException) {}
    agentCheck(RestDispatchWire::$calls === [] && AgentTask::outstanding('b',$pair)['result'] === '{}',
        'Unsent Work11 with a changed ' . $change . ' guard makes zero HTTP/auth and retains its immutable slot.');
    agentCheck(json_decode(AgentTask::outstanding('b',$pair)['context'],true)['rest_next'] > time(),
        'Held source does not monopolize the next dispatch turn.');
}
echo "Name Both unsent exact-source preflight PASS (mock HTTP/SQLite).\n";

$pair = nameQueueSetup(); $task = nameQueueTask($pair);
$context = json_decode($task['context'],true); $context['rest_sent']=true;
$DB->update(AgentTask::TABLE,['context'=>json_encode($context)],['id'=>$task['id']]);
$task = AgentTask::outstanding('b',$pair);
$item = new Computer(); $item->getFromDB(101); $item->update(['id'=>101,'name'=>'Changed after ambiguous POST']); FieldEvidence::finishWork();
RestDispatchWire::reset();
settingsDispatch([['status'=>200,'body'=>[settingsWorkRecord($task)]]]);
$calls = array_values(array_filter(RestDispatchWire::$calls,static fn ($c)=>!in_array($c['path'],['initSession','killSession'],true)));
agentCheck(array_column($calls,'method') === ['GET'] && AgentTask::outstanding('b',$pair)['result'] === '{}',
    'A changed source after an ambiguous POST may observe retained acceptance but never resend the mutation.');
echo "Name Both changed sent-source observation-only PASS (mock HTTP/SQLite).\n";
