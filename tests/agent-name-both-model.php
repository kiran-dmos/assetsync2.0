<?php
declare(strict_types=1);

ob_start(); require __DIR__ . '/agent-rest-settings-model.php'; ob_end_clean();

use GlpiPlugin\Assetsync20agent\FieldEvidence;
use GlpiPlugin\Assetsync20agent\NameBoth;
use GlpiPlugin\Assetsync20agent\RestSettings;
use GlpiPlugin\Assetsync20agent\RestWork;
use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\TaskExecutor;
use GlpiPlugin\Assetsync20agent\TaskJournal;

final class NameBrowser { public static bool $ordinary = true; }
function isAPI(): bool { return !NameBrowser::$ordinary; }
function isCommandLine(): bool { return false; }

/** Only DB dialect/current/idle probes are simulated. Models, evidence, journal and projection use actual SQL. */
final class NameModelDB
{
    public AgentSqlite $sql;
    public string $dbdefault = 'batch_test';
    public int $clock = 100;
    public int $error = 0;
    public bool $loseModelCommit = false;
    public bool $failSettlement = false;
    public int $modelWrites = 0;
    public function __construct(public object $base) {
        $this->sql = $base->sql;
        $this->sql->pdo->sqliteCreateFunction('CONNECTION_ID', static fn () => 17, 0);
        $this->sql->pdo->sqliteCreateFunction('SHA2', static fn ($s, $bits) => hash('sha256', $s), 2);
        $this->sql->pdo->sqliteCreateFunction('JSON_KEYS', static fn ($json, $path) => json_encode(array_keys(json_decode($json, true)['attempts'])), 2);
    }
    public function __call(string $name, array $args): mixed { return $this->base->$name(...$args); }
    public function errno(): int { return $this->error; }
    public function doQuery(string $sql): mixed {
        $this->error = 0;
        if (str_starts_with($sql, 'SELECT `value` FROM `glpi_configs`')) {
            $value = Config::getConfigurationValues(Settings::CONTEXT, ['field_evidence_capture'])['field_evidence_capture'] ?? null;
            return $this->sql->pdo->query('SELECT ' . ($value === null ? 'NULL' : $this->sql->quote($value)) . ' AS value');
        }
        if ($sql === 'SELECT CONNECTION_ID() AS connection_id') { return $this->sql->pdo->query('SELECT 17 AS connection_id'); }
        if ($sql === 'SELECT UNIX_TIMESTAMP() AS epoch') { return $this->sql->pdo->query('SELECT ' . $this->clock . ' AS epoch'); }
        if ($sql === 'SELECT @@SESSION.autocommit AS enabled') { return $this->sql->pdo->query('SELECT 1 AS enabled'); }
        if (str_starts_with($sql, 'SAVEPOINT as20_capture_')) { return !$this->sql->pdo->inTransaction() || $this->sql->doQuery($sql) !== false; }
        if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_capture_') && !$this->sql->pdo->inTransaction()) { $this->error = 1305; return false; }
        if ($this->failSettlement && str_starts_with($sql, 'UPDATE `glpi_plugin_assetsync20agent_fieldevidence`')) { return false; }
        if ($sql === 'COMMIT' && $this->loseModelCommit
            && $this->sql->pdo->query("SELECT COUNT(*) FROM glpi_plugin_assetsync20agent_tasks WHERE state='origin_pending'")->fetchColumn()) {
            $this->loseModelCommit = false;
            $this->base->doQuery($sql); $this->failSettlement = true;
            throw new RuntimeException('Injected lost reply after model/History/receipt commit');
        }
        if ($sql === 'ROLLBACK' && !$this->sql->pdo->inTransaction()) { return false; }
        return $this->base->doQuery(str_replace(' LOCK IN SHARE MODE', '', $sql));
    }
}

function nameSetup(): array {
    global $DB;
    Computer::$beforeModelUpdate = null; Computer::$afterModelUpdate = null;
    [$policy, $work] = policySetup();
    $DB = new NameModelDB($DB);
    $DB->doQuery('CREATE TABLE glpi_plugin_assetsync20agent_fieldevidence(computer_id INTEGER,field_key TEXT,model_class TEXT,generation INTEGER,evidence TEXT,PRIMARY KEY(computer_id,field_key))');
    foreach (['frames', 'owned', 'origins'] as $name) { (new ReflectionProperty(FieldEvidence::class, $name))->setValue(null, []); }
    FieldEvidence::activate();
    Computer::$beforeModelUpdate = static fn ($item) => FieldEvidence::before($item);
    Computer::$afterModelUpdate = static fn ($item) => \GlpiPlugin\Assetsync20agent\ChangeHook::updated($item);
    $_SESSION = ['glpiID' => 7]; $_SERVER['REQUEST_METHOD'] = 'POST';
    $item = new Computer(); $item->getFromDB(201); $item->update(['id' => 201, 'name' => 'Browser B']); FieldEvidence::finishWork();
    $before = FieldEvidence::envelope(201, 'native:name');
    modelCheck($before['origin'] !== null, 'Normal model fixture establishes a genuine B origin.');
    $policy['version'] = 4; $policy['id'] = md5('Name-Both-policy');
    $policy['mappings'][0]['source_of_truth'] = 'both'; $policy['include_child_entities'] = false; $policy['asset_types'] = ['Computer'];
    policyPost($policy);
    $source = $before; $source['value'] = 'Browser A';
    $source['hash'] = hash('sha256', json_encode(['type' => 'text', 'value' => $source['value']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $source['origin']['identity'] = str_repeat('f', 32) . ':' . str_repeat('e', 32); $source['origin']['from_epoch'] = 110; $source['origin']['to_epoch'] = 111;
    $work['version'] = 11; unset($work['name']);
    $work['settings_id'] = $policy['id']; $work['settings_hash'] = TaskExecutor::hash($policy);
    $work['source'] = $source; $work['source_hash'] = TaskExecutor::hash($source); $work['source_fingerprint'] = str_repeat('2', 64);
    return [$policy, $work, $before];
}

[$policy, $work, $before] = nameSetup();
$history = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
$id = modelPost($work); $row = modelRow($work); $result = json_decode($row['result'], true);
modelCheck($row['state'] === 'verified' && $result['winner'] === 'a' && $result['mutation'] === 'committed', 'A winner terminalizes only after inherited B evidence qualifies.');
NameBoth::validateResult($work, $result);
modelCheck(FieldEvidence::envelope(201, 'native:name')['origin'] === $work['source']['origin'] && $result['after']['value'] === 'Browser A', 'B value and original A interval are inherited beneath ServiceContext withoutOrigin.');
modelCheck(count(iterator_to_array($DB->request(['FROM' => 'glpi_logs']))) === count($history) + 1, 'Exactly one native parent History transition.');
$saved = modelRow($work); modelPost($work);
modelCheck(modelRow($work) === $saved, 'Lost terminal reply replays immutable work/result without another model write.');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['get_hateoas' => '0'];
$fields = $saved; RestWork::unsetUndisclosedFields($fields);
modelCheck($fields['result'] === $saved['result'] && modelRow($work) === $saved, 'Fresh GET guard reads exact stored result and does not settle.');
Computer::$allowRead = false; policyReject(static function () use ($saved): void { $fields = $saved; RestWork::unsetUndisclosedFields($fields); }, 'Terminal result still needs fresh READ.'); Computer::$allowRead = true;

foreach (['b', 'equal', 'blank', 'unknown', 'overlap'] as $case) {
    [$policy, $work, $before] = nameSetup();
    if ($case === 'b') { $work['source']['origin']['from_epoch'] = 90; $work['source']['origin']['to_epoch'] = 99; }
    if ($case === 'equal') { $work['source'] = $before; }
    if ($case === 'blank') { $work['source']['value'] = ''; $work['source']['hash'] = hash('sha256', '{"type":"text","value":""}'); }
    if ($case === 'unknown') { $work['source']['origin'] = null; }
    if ($case === 'overlap') { $work['source']['origin']['from_epoch'] = 100; $work['source']['origin']['to_epoch'] = 105; }
    $work['source_hash'] = TaskExecutor::hash($work['source']);
    $historyCount = count(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])));
    modelPost($work); $result = json_decode(modelRow($work)['result'], true);
    NameBoth::validateResult($work, $result);
    modelCheck($result['winner'] === (['b'=>'b','equal'=>'equal','blank'=>'a','unknown'=>'conflict','overlap'=>'conflict'][$case]), 'Expected field-specific Name decision: ' . $case);
    modelCheck(count(iterator_to_array($DB->request(['FROM' => 'glpi_logs']))) === $historyCount + (int) ($case === 'blank'), 'No duplicate/no-op/conflict History: ' . $case);
    if ($case === 'blank') { modelCheck($result['after']['value'] === '' && $result['after']['origin'] === $work['source']['origin'], 'Newer genuine blank clears older value with original evidence.'); }
}

[$policy, $work, $before] = nameSetup();
$DB->loseModelCommit = true;
policyReject(static fn () => modelPost($work), 'Lost postcommit reply cannot claim terminal verification.');
$pending = modelRow($work);
modelCheck($pending['state'] === 'origin_pending' && $pending['result'] === '{}' && $DB->firstRow('glpi_computers', ['id'=>201])['name'] === 'Browser A', 'Value+History+receipt commit while public success stays pending.');
$history = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['get_hateoas'=>'0'];
$read = new RestWork(); $read->getFromDB((int) $pending['id']);
modelCheck(modelRow($work) === $pending, 'GET alone never executes/reconciles a committed pending receipt.');
$DB->failSettlement = false;
foreach (['frames', 'owned', 'origins'] as $name) { (new ReflectionProperty(FieldEvidence::class, $name))->setValue(null, []); }
// Continue AFTER expiry: the committed receipt is metadata-only, not another asset execution.
BatchModelClock::$wall = $work['expires'] + 1;
modelPost($work);
modelCheck(modelRow($work)['state'] === 'verified' && iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === $history, 'Identical POST after expiry recovers original receipt without replaying model/History.');

[$policy, $work] = nameSetup();
$DB->base->base->crashAfterIntent = true;
policyReject(static fn () => modelPost($work), 'Crash after intent is held uncertain, not a receipt.');
$history = iterator_to_array($DB->request(['FROM'=>'glpi_logs'])); modelPost($work);
modelCheck(modelRow($work)['state'] === 'uncertain' && iterator_to_array($DB->request(['FROM'=>'glpi_logs'])) === $history, 'Executing orphan never replays asset mutation.');

[$policy, $work] = nameSetup();
Search::$options[1]['readonly'] = true;
policyReject(static fn () => modelPost($work), 'Readonly metadata blocks destination write.');
modelCheck($DB->firstRow('glpi_computers', ['id'=>201])['name'] === 'Browser B' && modelRow($work)['state'] === 'executing', 'Readonly failure has no mutation/no false terminal verification.');

[$policy, $work] = nameSetup();
PluginFieldsContainer::$fault = 'history_wrong';
policyReject(static fn () => modelPost($work), 'Wrong History projection rolls back model and inherited receipt.');
modelCheck($DB->firstRow('glpi_computers', ['id'=>201])['name'] === 'Browser B' && modelRow($work)['state'] === 'executing', 'History failure rollback preserves original B value and does not publish success.');

[$policy, $work] = nameSetup();
$after = Computer::$afterModelUpdate;
Computer::$afterModelUpdate = static function ($item) use ($after): void { $after($item); BatchModelClock::$now = BatchModelClock::$deadline + 1; };
policyReject(static fn () => modelPost($work), 'Owned model overruns shared monotonic request deadline.');
modelCheck($DB->firstRow('glpi_computers',['id'=>201])['name'] === 'Browser B' && modelRow($work)['state'] === 'executing', 'Deadline overrun rolls back model/History/receipt, never false verification.');

[$policy, $work] = nameSetup();
$DB->sql->beforeQuery = static function (string $sql) use ($DB): void {
    if ($sql !== 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') { return; }
    $DB->sql->beforeQuery = null;
    $item = new Computer(); $item->getFromDB(201); $item->update(['id'=>201,'name'=>'Concurrent B']); FieldEvidence::finishWork();
};
policyReject(static fn () => modelPost($work), 'Exact B guard changes after preparation, before owned model write.');
modelCheck($DB->firstRow('glpi_computers',['id'=>201])['name'] === 'Concurrent B' && modelRow($work)['state'] === 'executing', 'Concurrent B edit is not overwritten or relabeled as completion.');

[$policy, $work] = policySetup(); policyPost($policy);
$newPolicy = $policy; $newPolicy['version']=4; $newPolicy['revision']=2; $newPolicy['id']=md5('new-version-head');
$newPolicy['mappings'][0]['source_of_truth']='both'; $newPolicy['include_child_entities']=false; $newPolicy['asset_types']=['Computer'];
policyPost($newPolicy);
modelCheck(!RestSettings::active($policy) && RestSettings::active($newPolicy), 'Settings4 advances the SAME existing v2 route head, not a competing version-specific scope.');
policyReject(static fn () => policyPost($policy), 'Old lower v2 cannot reactivate after settings4.');
$disabled = array_replace($newPolicy,['id'=>md5('new-version-tombstone'),'revision'=>3,'enabled'=>false]); policyPost($disabled);
policyReject(static fn () => policyPost($newPolicy), 'Retained settings4 cannot reactivate after its higher disable tombstone.');
modelCheck(!RestSettings::active($newPolicy) && !RestSettings::active($disabled), 'Versioned tombstone fences new mutation, retained immutable policies remain.');

[$policy, $work] = nameSetup();
Config::setConfigurationValues(Settings::CONTEXT,['agent_peer_user'=>'8','agent_peer_profile'=>'9']);
$DB->doQuery('CREATE TABLE glpi_profiles_users(users_id INTEGER,profiles_id INTEGER,entities_id INTEGER,is_recursive INTEGER)');
$DB->doQuery('INSERT INTO glpi_profiles_users VALUES(8,9,100,0)');
$_GET=['range'=>'0-1','get_hateoas'=>'0','searchText'=>['batch_id'=>$work['batch_id'],'compiler'=>'11','binding'=>'spoof','entities_id'=>'999']];
$criteria = RestWork::getSystemSQLCriteria();
modelCheck($criteria['AND']['rest_work.batch_id'] === $work['batch_id'] && $criteria['AND']['rest_work.compiler'] === 11
    && $criteria['AND']['rest_work.binding'] !== 'spoof', 'Mandatory nested exact batch/compiler/binding survives caller LIKE/spoof filters.');
$_GET['range']='0-3'; policyReject(static fn () => RestWork::getSystemSQLCriteria(), 'Work11 collection cannot expose four large evidence rows.');
$_GET['range']='0-1'; $_GET['searchText']['batch_id']='%'; policyReject(static fn () => RestWork::getSystemSQLCriteria(), 'Wildcard batch cannot replace exact indexed lookup.');
echo "Name Both B model PASS: guarded decisions, inheritance, no-op/blank/conflicts, readonly/History rollback, intent and expired receipt recovery (SQLite/native doubles).\n";
