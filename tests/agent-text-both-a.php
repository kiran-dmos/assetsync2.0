<?php
declare(strict_types=1);

// Optional read-only shared candidate for host integration before coordinator merges it.
if (($captureSource = getenv('ASSETSYNC20_CAPTURE_TEST_SOURCE')) !== false && $captureSource !== '') { require_once $captureSource; }

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\FieldEvidence;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\FieldsText;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;
use GlpiPlugin\Assetsync20\TextBoth;

$textChecks = 0;
function textCheck(bool $ok, string $why): void { global $textChecks; $textChecks++; if (!$ok) { throw new RuntimeException($why); } }
function textReject(Closure $call, string $why): void {
    try { $call(); } catch (RuntimeException) { textCheck(true, $why); return; }
    throw new LogicException($why);
}

// Separate process: selected official-wrapper doubles need different GLPI classes than the retained queue fixture.
if (($argv[1] ?? '') === '--selected') {
    require __DIR__ . '/query-expression.php'; require __DIR__ . '/agent-sqlite.php'; require __DIR__ . '/../src/autoload.php';
    define('READ', 1); define('UPDATE', 2); define('CREATE', 4);
    class Session {
        public static bool $entity = true;
        public static function isCron(): bool { return false; }
        public static function haveAccessToEntity(int $id, bool $recursive = false): bool { return self::$entity; }
        public static function isInventory(): bool { return false; }
        public static function getLoginUserID(bool $human = true): int { return 7; }
    }
    function isAPI(): bool { return false; }
    function isCommandLine(): bool { return false; }
    class Config {
        public static function getConfigurationValues(string $context, array $keys = []): array {
            global $DB; $values = [];
            foreach ($DB->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => $context]]) as $row) { $values[$row['name']] = $row['value']; }
            return $keys === [] ? $values : array_intersect_key($values, array_flip($keys));
        }
        public static function setConfigurationValues(string $context, array $values): void {
            global $DB;
            foreach ($values as $key => $value) { $DB->sql->pdo->prepare('INSERT INTO glpi_configs VALUES (?,?,?) ON CONFLICT(context,name) DO UPDATE SET value=excluded.value')->execute([$context,$key,$value]); }
        }
    }
    class Computer {
        public array $fields = [];
        public static bool $read = true, $write = true;
        public function getFromDB(int $id): bool { global $DB; $this->fields = $DB->firstRow('glpi_computers', ['id' => $id]) ?? []; return $this->fields !== []; }
        public function can(int $id, int $right): bool { return ($right === READ ? self::$read : self::$write) && $this->getFromDB($id); }
    }
    class PluginFieldsField { public const SEARCH_OPTION_STARTING_INDEX = 76600; }
    class PluginFieldsProfile { public static int $right = CREATE; public static function getRightOnContainer(int $profile, int $container): int { return self::$right; } }
    class PluginFieldsComputertexttest {
        public array $fields = [], $updates = [], $oldvalues = [];
        public array|false $input = [];
        public static function getTable(): string { return 'glpi_plugin_fields_computertexttests'; }
        public function update(array $values): void {
            global $DB; $this->input = $values;
            if (PluginFieldsContainer::$capture) { FieldEvidence::before($this); }
            foreach ($values as $column => $value) {
                if (!array_key_exists($column, $this->fields) || $this->fields[$column] === $value) { continue; }
                $this->updates[] = $column; $this->oldvalues[$column] = $this->fields[$column];
            }
            $DB->update(static::getTable(), $values, ['id' => $this->fields['id']]);
            if (PluginFieldsContainer::$capture) { FieldEvidence::after($this); }
        }
    }
    class PluginFieldsComputerothertext extends PluginFieldsComputertexttest { public static function getTable(): string { return 'glpi_plugin_fields_computerothertexts'; } }
    class PluginFieldsContainer {
        public array $fields = [];
        public static array $inputs = [];
        public static string $fault = '';
        public static bool $capture = false;
        public static function getClassname(string $type, string $name): string { return $name === 'othertexts' ? 'PluginFieldsComputerothertext' : 'PluginFieldsComputertexttest'; }
        public function getFromDB(int $id): bool { global $DB; $this->fields = $DB->firstRow('glpi_plugin_fields_containers', ['id' => $id]) ?? []; return $this->fields !== []; }
        public function updateFieldsValues(array $input, string $type, bool $massive): bool {
            global $DB;
            self::$inputs[] = $input;
            if (self::$fault === 'reject') { return false; }
            if (self::$fault === 'ignore') { return true; }
            $class = self::getClassname($type, $this->fields['name']); $model = new $class();
            $model->fields = $DB->firstRow($class::getTable(), ['items_id' => $input['items_id']]);
            $model->update(array_intersect_key($input, ['textfield' => true, 'areafield' => true, 'sibling' => true]));
            if (self::$capture) {
                foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => ['plugin_fields_containers_id' => $this->fields['id']]]) as $field) {
                    if (!in_array($field['name'], $model->updates, true)) { continue; }
                    $DB->insert('glpi_logs', ['items_id' => $input['items_id'], 'itemtype' => 'Computer', 'id_search_option' => PluginFieldsField::SEARCH_OPTION_STARTING_INDEX+(int)$field['id'],
                        'old_value' => $model->oldvalues[$field['name']], 'new_value' => $input[$field['name']], 'itemtype_link' => '', 'linked_action' => 0]);
                }
            }
            return true;
        }
    }
    final class SelectedTextDB {
        public AgentSqlite $sql;
        public int $clock = 100;
        public function __construct() {
            $this->sql = new AgentSqlite();
            $this->sql->pdo->sqliteCreateFunction('CONNECTION_ID', static fn () => 17, 0);
            $this->sql->pdo->sqliteCreateFunction('SHA2', static fn ($value, $bits) => hash('sha256', $value), 2);
            $this->sql->pdo->sqliteCreateFunction('JSON_KEYS', static fn ($json, $path) => json_encode(array_keys(json_decode($json, true)['attempts'])), 2);
        }
        public function errno(): int { return 1305; }
        public function __call(string $method, array $args): mixed { return $this->sql->$method(...$args); }
        public function doQuery(string $sql): mixed {
            if ($sql === 'SELECT UNIX_TIMESTAMP() AS epoch') { return $this->sql->pdo->query('SELECT '.$this->clock.' AS epoch'); }
            if ($sql === 'SELECT @@SESSION.autocommit AS enabled') { return $this->sql->pdo->query('SELECT 1 AS enabled'); }
            if (str_starts_with($sql, 'SAVEPOINT as20_capture_')) { return !$this->sql->pdo->inTransaction() || $this->sql->doQuery($sql) !== false; }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_capture_') && !$this->sql->pdo->inTransaction()) { return false; }
            if (str_starts_with($sql, 'SAVEPOINT as20_wake_')) { return true; }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_wake_')) { return !$this->sql->pdo->inTransaction() ? false : true; }
            if ($sql === 'SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS connection_id') { return $this->sql->pdo->query('SELECT 1 AS enabled,17 AS connection_id'); }
            if ($sql === 'SELECT CONNECTION_ID() AS connection_id') { return $this->sql->pdo->query('SELECT 17 AS connection_id'); }
            if (str_starts_with($sql, 'SELECT CHARACTER_MAXIMUM_LENGTH')) { return $this->sql->pdo->query('SELECT 255 AS characters,1020 AS bytes'); }
            return $this->sql->doQuery(str_replace(' LOCK IN SHARE MODE', '', $sql));
        }
    }
    $DB = new SelectedTextDB(); $_SESSION = ['glpiactiveprofile' => ['id' => 4]];
    $DB->doQuery('CREATE TABLE glpi_computers(id INTEGER PRIMARY KEY,entities_id INTEGER,states_id INTEGER,is_deleted INTEGER,is_template INTEGER,name TEXT,comment TEXT)');
    $DB->doQuery('CREATE TABLE glpi_configs(context TEXT,name TEXT,value TEXT,PRIMARY KEY(context,name))');
    $DB->doQuery('CREATE TABLE glpi_plugin_assetsync20_fieldevidence(computer_id INTEGER,field_key TEXT,model_class TEXT,generation INTEGER,evidence TEXT,PRIMARY KEY(computer_id,field_key))');
    $DB->doQuery('CREATE TABLE glpi_logs(id INTEGER PRIMARY KEY,items_id INTEGER,itemtype TEXT,id_search_option INTEGER,old_value TEXT,new_value TEXT,itemtype_link TEXT,linked_action INTEGER)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_fields(id INTEGER PRIMARY KEY,plugin_fields_containers_id INTEGER,name TEXT,type TEXT,is_active INTEGER,is_readonly INTEGER,mandatory INTEGER,multiple INTEGER)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_containers(id INTEGER PRIMARY KEY,name TEXT,type TEXT,is_active INTEGER,entities_id INTEGER,is_recursive INTEGER,itemtypes TEXT)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_statusoverrides(id INTEGER PRIMARY KEY,plugin_fields_fields_id INTEGER,itemtype TEXT,states TEXT,mandatory INTEGER,is_readonly INTEGER)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_computertexttests(id INTEGER PRIMARY KEY,items_id INTEGER,itemtype TEXT,plugin_fields_containers_id INTEGER,textfield TEXT,areafield TEXT,sibling TEXT)');
    $DB->doQuery('CREATE TABLE glpi_plugin_fields_computerothertexts(id INTEGER PRIMARY KEY,items_id INTEGER,itemtype TEXT,plugin_fields_containers_id INTEGER,textfield TEXT,areafield TEXT,sibling TEXT)');
    $DB->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'states_id' => 7, 'is_deleted' => 0, 'is_template' => 0]);
    $DB->insert('glpi_plugin_fields_containers', ['id' => 21, 'name' => 'texttests', 'type' => 'tab', 'is_active' => 1, 'entities_id' => 1, 'is_recursive' => 0, 'itemtypes' => '["Computer"]']);
    foreach ([31 => ['textfield', 'text', 0], 32 => ['areafield', 'textarea', 0], 33 => ['sibling', 'text', 1]] as $id => [$column, $type, $mandatory]) {
        $DB->insert('glpi_plugin_fields_fields', ['id' => $id, 'plugin_fields_containers_id' => 21, 'name' => $column, 'type' => $type, 'is_active' => 1, 'is_readonly' => 1, 'mandatory' => $mandatory, 'multiple' => 0]);
    }
    $DB->insert(PluginFieldsComputertexttest::getTable(), ['id' => 41, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 21, 'textfield' => 'Before', 'areafield' => 'Area', 'sibling' => 'Keep mandatory']);
    $pin = FieldsText::selectedTextPin('fields:31', 1, true);
    textCheck($pin['key'] === 'Computer.PluginFieldsComputertexttest.textfield' && $pin['capture_key'] === 'fields:31', 'Actual trusted custom class/key independently resolved.');
    foreach ([0, -1] as $right) { PluginFieldsProfile::$right = $right; textReject(fn () => FieldsText::selectedTextPin('fields:31', 1), 'Denied container enum is not READ.'); }
    PluginFieldsProfile::$right = READ;
    textCheck(FieldsText::selectedTextPin('fields:31', 1) === $pin, 'Official READ enum permits selected source read.');
    textReject(fn () => FieldsText::selectedTextPin('fields:31', 1, true), 'READ cannot update.');
    PluginFieldsProfile::$right = CREATE;
    textCheck(FieldsText::selectedTextPin('fields:31', 1, true) === $pin, 'Official CREATE=4 Read/Write enum is not a core READ bitmask.');
    Computer::$write = false; textReject(fn () => FieldsText::readSelectedText($pin, 101, 1, true), 'Container write does not bypass parent UPDATE.'); Computer::$write = true;
    Computer::$read = false; textReject(fn () => FieldsText::readSelectedText($pin, 101, 1), 'Container read does not bypass parent READ.'); Computer::$read = true;
    Session::$entity = false; textReject(fn () => FieldsText::readSelectedText($pin, 101, 1), 'Entity remains mandatory.'); Session::$entity = true;
    $DB->doQuery('START TRANSACTION'); FieldsText::updateSelectedText($pin, 101, 1, 'Trusted mapped readonly destination'); $DB->doQuery('COMMIT');
    textCheck(PluginFieldsContainer::$inputs[0]['sibling'] === 'Keep mandatory' && !isset(PluginFieldsContainer::$inputs[0]['areafield']), 'One official wrapper preserves required siblings, no unrelated field export.');
    $DB->insert('glpi_plugin_fields_statusoverrides', ['id' => 1, 'plugin_fields_fields_id' => 31, 'itemtype' => 'Computer', 'states' => '[7]', 'mandatory' => 1, 'is_readonly' => 1]);
    foreach (['', '0'] as $blank) {
        $DB->doQuery('START TRANSACTION'); textReject(fn () => FieldsText::updateSelectedText($pin, 101, 1, $blank), 'Actual parent status makes selected field mandatory, including official empty(0).'); $DB->doQuery('ROLLBACK');
    }
    $DB->update('glpi_plugin_fields_statusoverrides', ['plugin_fields_fields_id' => 32], ['id' => 1]);
    $DB->update(PluginFieldsComputertexttest::getTable(), ['areafield' => ''], ['id' => 41]);
    $DB->doQuery('START TRANSACTION'); textReject(fn () => FieldsText::updateSelectedText($pin, 101, 1, 'No missing required sibling'), 'Effective mandatory sibling is checked even when base mandatory=false.'); $DB->doQuery('ROLLBACK');
    $DB->update(PluginFieldsComputertexttest::getTable(), ['areafield' => 'Status-required sibling'], ['id' => 41]);
    $DB->doQuery('START TRANSACTION'); FieldsText::updateSelectedText($pin, 101, 1, 'Okay'); $DB->doQuery('COMMIT');
    textCheck(end(PluginFieldsContainer::$inputs)['areafield'] === 'Status-required sibling', 'Effective status-required sibling supplied unchanged to official wrapper.');
    $DB->update('glpi_plugin_fields_fields', ['is_readonly' => 0], ['id' => 31]);
    textReject(fn () => FieldsText::readSelectedText($pin, 101, 1), 'Policy pin requires explicit reselection after definition change.');
    $pin = FieldsText::selectedTextPin('fields:31', 1);
    $DB->doQuery('DELETE FROM glpi_plugin_fields_statusoverrides');
    $area = FieldsText::selectedTextPin('fields:32', 1);
    $DB->update('glpi_plugin_fields_fields', ['mandatory' => 1], ['id' => 32]);
    $area = FieldsText::selectedTextPin('fields:32', 1);
    $DB->update(PluginFieldsComputertexttest::getTable(), ['areafield' => ''], ['id' => 41]);
    $calls = count(PluginFieldsContainer::$inputs);
    $DB->doQuery('START TRANSACTION'); FieldsText::updateSelectedTextGroup([['pin' => $pin, 'value' => 'Together'], ['pin' => $area, 'value' => 'Proposed required sibling']], 101, 1); $DB->doQuery('COMMIT');
    textCheck(count(PluginFieldsContainer::$inputs) === $calls+1 && end(PluginFieldsContainer::$inputs)['areafield'] === 'Proposed required sibling', 'Two incoming columns validate proposed required sibling together and use ONE wrapper.');
    $before = $DB->firstRow(PluginFieldsComputertexttest::getTable(), ['id' => 41]);
    $DB->doQuery('START TRANSACTION'); textReject(fn () => FieldsText::updateSelectedTextGroup([['pin' => $pin, 'value' => 'Must roll back'], ['pin' => $area, 'value' => '0']], 101, 1), 'One invalid member blocks whole incoming container group.'); $DB->doQuery('ROLLBACK');
    textCheck($DB->firstRow(PluginFieldsComputertexttest::getTable(), ['id' => 41]) === $before, 'Failed group preserves all selected values.');
    $DB->insert('glpi_plugin_fields_containers', ['id' => 22, 'name' => 'othertexts', 'type' => 'tab', 'is_active' => 1, 'entities_id' => 1, 'is_recursive' => 0, 'itemtypes' => '["Computer"]']);
    $DB->insert('glpi_plugin_fields_fields', ['id' => 34, 'plugin_fields_containers_id' => 22, 'name' => 'textfield', 'type' => 'text', 'is_active' => 1, 'is_readonly' => 1, 'mandatory' => 0, 'multiple' => 0]);
    $DB->insert(PluginFieldsComputerothertext::getTable(), ['id' => 42, 'items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 22, 'textfield' => 'Other', 'areafield' => null, 'sibling' => null]);
    $other = FieldsText::selectedTextPin('fields:34', 1);
    $DB->doQuery('START TRANSACTION'); FieldsText::updateSelectedTextGroup([['pin' => $other, 'value' => 'Independent other container']], 101, 1); $DB->doQuery('COMMIT');
    textCheck($DB->firstRow(PluginFieldsComputerothertext::getTable(), ['id' => 42])['textfield'] === 'Independent other container', 'Independent actual container can succeed after other group fails.');
    if (method_exists(FieldEvidence::class, 'withOrigins')) {
        PluginFieldsContainer::$capture = true; FieldEvidence::activate();
        $origins = [];
        foreach ([31 => 'textfield', 32 => 'areafield'] as $fieldId => $column) {
            $DB->clock += 10; $container = new PluginFieldsContainer(); $container->getFromDB(21);
            $container->updateFieldsValues(['items_id' => 101, 'itemtype' => 'Computer', 'plugin_fields_containers_id' => 21, $column => 'Genuine browser '.$column], 'Computer', false);
            FieldEvidence::finishWork(); $origins[$column] = FieldEvidence::envelope(101, 'fields:'.$fieldId);
        }
        textCheck($origins['textfield']['origin'] !== null && $origins['areafield']['origin'] !== null && $origins['textfield']['origin'] !== $origins['areafield']['origin'], 'Separate browser-model edits have distinct original events in same container.');
        $changes = []; $receipts = [];
        foreach ([$pin, $area] as $i => $p) {
            $origin = ['identity' => md5('B').':'.md5('field-'.$i), 'revision' => $i+1, 'kind' => 'user', 'from_epoch' => 500+$i*10, 'to_epoch' => 501+$i*10];
            $changes[] = ['key' => $p['capture_key'], 'before_hash' => $origins[$p['column']]['hash'], 'after_hash' => TextBoth::valueHash('Inherited '.$p['column']), 'origin' => $origin];
        }
        $DB->doQuery('START TRANSACTION');
        FieldEvidence::withOrigins(101, $changes, static function () use ($pin, $area, $changes, &$receipts): void {
            FieldsText::updateSelectedTextGroup([['pin' => $pin, 'value' => 'Inherited textfield'], ['pin' => $area, 'value' => 'Inherited areafield']], 101, 1);
            foreach ($changes as $change) { $receipts[] = FieldEvidence::inheritedReceipt(101, $change['key'], $change['origin']); }
        });
        $DB->doQuery('COMMIT'); FieldEvidence::finishWork();
        foreach ($receipts as $i => $receipt) { textCheck(FieldEvidence::settleInherited($receipt)['origin'] === $changes[$i]['origin'], 'Single wrapper retains BOTH distinct inherited event identities, not nested singleton shadowing.'); }
    }
    $DB->doQuery('DELETE FROM glpi_plugin_fields_computertexttests WHERE id=41');
    textReject(fn () => FieldsText::readSelectedText($pin, 101, 1), 'Missing selected child never becomes blank or upsert.');
    echo "Selected Fields A: $textChecks checks PASS (official-wrapper/model doubles, native qualification separate).\n";
    exit;
}

ob_start(); require __DIR__ . '/agent-name-both-queue.php'; ob_end_clean();

/** Bounds and clock probes only; durable Config/task/queue/evidence SQL uses the existing fixture. */
final class TextQueueDB {
    public AgentSqlite $sql;
    public string $dbdefault = 'settings-test';
    public bool $falseRollback = false;
    public int $fullRollbacks = 0;
    public ?Closure $beforeFinalContext = null;
    public function __construct(public NameQueueDB $base) { $this->sql = $base->sql; }
    public function errno(): int { return $this->base->errno(); }
    public function tableExists(string $table): bool { return $this->base->tableExists($table); }
    public function fetchAssoc(PDOStatement $result): ?array { return $this->base->fetchAssoc($result); }
    public function request(array $query): ArrayIterator { return $this->base->request($query); }
    public function insert(string $table, array $fields): bool { return $this->base->insert($table, $fields); }
    public function update(string $table, array $fields, array $where): bool { return $this->base->update($table, $fields, $where); }
    public function fieldExists(string $table, string $field): bool { return $this->base->fieldExists($table, $field); }
    public function affectedRows(): int { return $this->base->affectedRows(); }
    public function quote(string $value): string { return $this->base->quote($value); }
    public function firstRow(string $table, array $where): ?array {
        if ($table === 'glpi_computers' && $this->beforeFinalContext !== null) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $context = array_filter($trace, static fn ($frame) => ($frame['class'] ?? '') === TextBoth::class && $frame['function'] === 'context');
            $finish = array_filter($trace, static fn ($frame) => ($frame['class'] ?? '') === TextBoth::class && $frame['function'] === 'finish');
            if ($context && $finish) { $callback = $this->beforeFinalContext; $this->beforeFinalContext = null; $callback(); }
        }
        return $this->base->firstRow($table, $where);
    }
    public function __call(string $method, array $args): mixed { return $this->base->$method(...$args); }
    public function doQuery(string $sql): mixed {
        if ($sql === 'ROLLBACK') { $this->fullRollbacks++; if ($this->falseRollback) { return true; } }
        if (str_starts_with($sql, 'SELECT CHARACTER_MAXIMUM_LENGTH')) {
            return $this->sql->pdo->query(str_contains($sql, "COLUMN_NAME='name'") ? 'SELECT 255 AS characters,1020 AS bytes' : 'SELECT 65535 AS characters,262140 AS bytes');
        }
        return $this->base->doQuery($sql);
    }
}

function textSetup(string $a = 'name', string $b = 'name', bool $multi = false): array {
    global $DB;
    $pair = nameQueueSetup(); $DB = new TextQueueDB($DB);
    if ($multi) {
        $DB->base->clock = 200; $item = new Computer(); $item->getFromDB(101); $item->update(['id' => 101, 'comment' => 'Original genuine A Comments']); FieldEvidence::finishWork();
    }
    $rows = $multi ? [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'both'],
        ['glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'both']]
        : [['glpi_a_field_key' => $a, 'glpi_b_field_key' => $b, 'source_of_truth' => 'both']];
    $pins = []; foreach ($rows as $row) { $pin = FieldsText::selectedTextPin($row['glpi_b_field_key']); $pins[$pin['capture_key']] = $pin; }
    ksort($pins, SORT_STRING); $nonce = bin2hex(random_bytes(16));
    $id = RestWork::requestCatalog('b', 100, ['kind' => 'selected', 'capture_keys' => array_keys($pins)], $nonce);
    $selected = []; foreach ($pins as $key => $pin) { $selected[] = ['capture_key' => $key, 'descriptor' => $pin, 'reason' => null]; }
    $result = ['outcome' => 'observed', 'entity_id' => 100, 'fields' => $selected, 'next_after_id' => null, 'complete' => true, 'observed_at' => time()];
    $DB->update(AgentTask::TABLE, ['state' => 'observed', 'result' => json_encode($result), 'slot' => null], ['id' => $id]);
    FieldMapping::savePinnedText('b', 'Computer', $rows, 100, $id, $nonce);
    textCheck(settingsBody()['version'] === 5 && settingsBody()['enabled'], 'Explicit selected save creates enabled Settings5, not automatic old4 upgrade.');
    textCheck(json_decode($DB->firstRow(AgentTask::TABLE, ['id' => $id])['context'], true)['selection_consumed'], 'Selected receipt consumed atomically with save.');
    publishPolicy(); RestDispatchWire::reset(); RestWork::beginRun();
    return AgentTask::byId('b', $pair['id']);
}
function textTask(array $pair): array {
    AssetSyncQueue::notify('Computer', 101, 'b'); foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); }
    $task = AgentTask::outstanding('b', $pair);
    textCheck($task !== null && json_decode($task['body'], true)['version'] === 12, 'Ordinary queue publishes Work12: '.(queue()['last_error'] ?? '')); return $task;
}
function textResult(array $task, string|array $winners): array {
    $body = json_decode($task['body'], true);
    $policy = json_decode($task['context'], true)['settings'];
    $fields = []; $groups = [];
    foreach ($policy['mappings'] as $i => $mapping) {
    $source = $body['sources'][$i]['envelope']; $before = $source;
    $winner = is_string($winners) ? $winners : $winners[$mapping['a']['column']];
    $before['value'] = 'Genuine B text'; $before['hash'] = TextBoth::valueHash($before['value']);
    $epoch = $source['origin']['from_epoch'] ?? 100;
    $before['origin'] = ['identity' => md5('b-'.$i).':'.md5('event-'.$i), 'revision' => $i+3, 'kind' => 'user',
        'from_epoch' => $winner === 'a' ? $epoch-20 : $epoch+10, 'to_epoch' => $winner === 'a' ? $epoch-10 : $epoch+11];
    if ($winner === 'equal') { $before = $source; }
    if ($winner === 'conflict') { $before['origin'] = null; }
    $groupId = in_array($winner, ['a','b'], true) ? TextBoth::groupId($body['id'], $winner === 'a' ? 'b' : 'a', $mapping[$winner === 'a' ? 'b' : 'a']) : null;
    $field = ['mapping_id' => $mapping['mapping_id'], 'source_hash' => AgentTask::hash($source), 'winner' => $winner,
        'outcome' => ['a' => 'verified', 'b' => 'offered', 'equal' => 'unchanged', 'conflict' => 'conflict'][$winner],
        'mutation' => $winner === 'a' ? 'committed' : 'none', 'before' => $before, 'after' => $winner === 'a' ? $source : $before,
        'group_id' => $groupId, 'reason' => null];
    $fields[] = $field;
    if ($groupId !== null) {
        $side = $winner === 'a' ? 'b' : 'a'; $pin = $mapping[$side];
        if (!isset($groups[$groupId])) { $groups[$groupId] = ['id' => $groupId, 'side' => $side, 'kind' => $pin['kind'], 'container_id' => $pin['container_id'], 'members' => [],
            'phase' => $winner === 'a' ? 'done' : 'offered', 'mutation' => $field['mutation'], 'reason' => null,
            'started_at' => $winner === 'a' ? time() : null, 'model_ended_at' => $winner === 'a' ? time() : null]; }
        $groups[$groupId]['members'][] = $mapping['mapping_id'];
    }
    }
    ksort($groups, SORT_STRING);
    return ['revision' => 1, 'source_hash' => $body['source_hash'], 'b_complete' => true, 'fields' => $fields, 'groups' => array_values($groups)];
}
function textObserve(array $task, array $result, ?bool $matches = true): array {
    global $DB;
    $entry = ['task' => $task, 'body' => json_decode($task['body'], true), 'context' => json_decode($task['context'], true)];
    $record = settingsWorkRecord($task, $result); $observed = [];
    foreach ($result['fields'] as $f) { $observed[] = ['mapping_id' => $f['mapping_id'], 'receipt_hash' => TextBoth::stable($f) ? AgentTask::hash($f) : null, 'current_matches' => $f['after'] === null ? null : $matches]; }
    $record['read_observation'] = ['revision' => $result['revision'], 'receipt_hash' => AgentTask::hash($result), 'fields' => $observed];
    $entries = [$task['id'] => $entry]; $args = [&$entries, [$task['id'] => $record]];
    (new ReflectionMethod(RestWork::class, 'observeBatch'))->invokeArgs(null, $args);
    return $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
}
function textJobs(): void { foreach (AssetSyncQueue::claimDue(1) as $job) { RestWork::process($job); } }

foreach (['a', 'b', 'equal', 'conflict'] as $winner) {
    $pair = textSetup(); $task = textTask($pair); $result = textResult($task, $winner);
    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    textObserve($task, $result); textJobs(); $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
    textCheck(($winner === 'conflict' ? $saved['state'] === 'partial' && $saved['slot'] !== null : $saved['slot'] === null && $saved['state'] === 'verified'), 'Qualified original winner/equality/no-write conflict accounts for every field: '.$winner.'; '.queue()['last_error']);
    textCheck(($DB->firstRow('glpi_computers', ['id' => 101])['name'] === 'Genuine B text') === ($winner === 'b'), 'Only B winner invokes local supported model.');
    if ($winner !== 'b') { textCheck(iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $logs, 'No local History for A/equal/conflict.'); }
    else { textCheck(FieldEvidence::envelope(101, 'native:name')['origin'] === $result['fields'][0]['after']['origin'], 'B original interval inherited, not new service origin.'); }
}

$pair = textSetup(); $context = TextBoth::context($pair); $checked = time();
$DB->update(AgentTask::PAIRS, ['local_fingerprint' => $context['fingerprint'], 'checked_at' => $checked], ['id' => $pair['id']]);
$pair = AgentTask::byId('b', $pair['id']); $item = new Computer(); $item->getFromDB(101); $item->update(['id' => 101, 'comment' => 'Unmapped']); FieldEvidence::finishWork();
textCheck(!RestWork::needsWork($pair, false), 'Unmapped Comments scanner ignores full-guard bookkeeping.'); textJobs(); RestWork::sendPending(hrtime(true)+20_000_000_000);
textCheck(RestDispatchWire::$calls === [] && AgentTask::outstanding('b', $pair) === null && queue()['status'] === 'done', 'Hook-queued unmapped edit has zero per-asset RPC.');
textCheck(AgentTask::byId('b', $pair['id'])['checked_at'] == $checked, 'Unmapped skip does not postpone separate B probe.');

foreach ([false, null] as $matches) {
    $pair = textSetup(); $task = textTask($pair); $result = textResult($task, 'b'); textObserve($task, $result, $matches); textJobs();
    $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
    textCheck($saved['slot'] !== null && $saved['state'] === ($matches === false ? 'partial' : 'accepted'), 'Known terminal false becomes partial; unknown remains held.');
    textCheck($saved['body'] === $task['body'] && $saved['hash'] === $task['hash'] && $saved['result'] === json_encode($result), 'Historical receipt/body/hash preserved.');
    textCheck($DB->firstRow('glpi_computers', ['id' => 101])['name'] !== 'Genuine B text', 'No stale/unknown terminal local write.');
}

$pair = textSetup(); $task = textTask($pair); $body = json_decode($task['body'], true); $body['expires'] = time()-1;
$DB->update(AgentTask::TABLE, ['body' => json_encode($body), 'hash' => AgentTask::hash($body), 'expires' => $body['expires']], ['id' => $task['id']]);
$old = AgentTask::outstanding('b', $pair); RestDispatchWire::reset(); RestWork::sendPending(hrtime(true)+20_000_000_000);
textCheck(RestDispatchWire::$calls === [] && queue()['status'] === 'pending', 'Pure expired never-reserved12 wakes ordinary queue with zero auth.'); textJobs();
$new = AgentTask::outstanding('b', $pair); $retired = $DB->firstRow(AgentTask::TABLE, ['id' => $old['id']]);
textCheck($new['id'] !== $old['id'] && $retired['state'] === 'superseded' && $retired['body'] === $old['body'] && $retired['result'] === '{}', 'Pure expiry gets fresh ID/batch without rewriting old evidence.');

foreach ([['name' => 'a', 'comment' => 'b'], ['name' => 'b', 'comment' => 'b'], ['name' => 'conflict', 'comment' => 'b']] as $winners) {
    $pair = textSetup(multi: true); $task = textTask($pair); $body = json_decode($task['body'], true); $result = textResult($task, $winners);
    textCheck(count($body['sources']) === 2 && count(settingsBody()['mappings']) === 2, 'ONE work accounts for both simultaneous mappings, not sequential singleton work.');
    TextBoth::validateResult($body, settingsBody(), $result);
    $historyBefore = (int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn();
    $record = settingsWorkRecord($task, $result); $observed = [];
    foreach ($result['fields'] as $f) { $observed[] = ['mapping_id' => $f['mapping_id'], 'receipt_hash' => AgentTask::hash($f), 'current_matches' => true]; }
    $record['read_observation'] = ['revision' => 1, 'receipt_hash' => AgentTask::hash($result), 'fields' => $observed];
    RestDispatchWire::$replies = [['status' => 207, 'body' => [['id' => 77]]], ['status' => 200, 'body' => [$record]]];
    RestWork::sendPending(hrtime(true)+20_000_000_000);
    textCheck(RestDispatchWire::$replies === [], 'Single asset POST and GET cover ALL mapped fields.');
    $posts = array_values(array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST' && str_contains($call['path'], 'RestWork')));
    textCheck(count($posts) === 1, 'Not one mutation request per field.');
    $wire = json_decode($posts[0]['options'][CURLOPT_POSTFIELDS], true); $input = $wire['input'][0];
    textCheck(array_keys($input) === ['work_id','hash','body','allow_rows'] && $input['allow_rows'] === array_column($body['sources'], 'mapping_id'), 'Restrictive complete allow_rows sent without changing immutable body/hash.');
    textJobs(); $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
    $facts = json_decode($saved['context'], true); $asset = $DB->firstRow('glpi_computers', ['id' => 101]);
    textCheck($asset['comment'] === 'Genuine B text', 'Comments winner B applies in same sync as independent Name decision.');
    foreach ($result['fields'] as $f) {
        $mapping = array_values(array_filter(settingsBody()['mappings'], static fn ($m) => $m['mapping_id'] === $f['mapping_id']))[0];
        if ($f['winner'] === 'b') { textCheck(FieldEvidence::envelope(101, $mapping['a']['capture_key'])['origin'] === $f['after']['origin'], 'Each simultaneous copy inherits ITS distinct original event.'); }
    }
    $writes = count(array_filter($winners, static fn ($w) => $w === 'b'));
    textCheck((int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn() === $historyBefore+$writes, 'Only actual incoming fields produce History once.');
    if ($winners['name'] === 'conflict') {
        textCheck($saved['state'] === 'partial' && queue()['status'] === 'waiting_agent' && $saved['slot'] !== null, 'Conflict does not block valid incoming sibling or claim full success.');
        textCheck(AgentTask::byId('b', $pair['id'])['local_fingerprint'] === $pair['local_fingerprint'], 'Partial commit advances no whole-pair success/fingerprint.');
        $ctx = $facts; $ctx['rest_next'] = time()-1; $DB->update(AgentTask::TABLE, ['context' => json_encode($ctx)], ['id' => $task['id']]);
        RestDispatchWire::reset(); RestWork::sendPending(hrtime(true)+20_000_000_000);
        textCheck(RestDispatchWire::$calls === [] && queue()['status'] === 'pending', 'Known partial retry wakes ordinary claim BEFORE auth, without impossible NULL proof.');
        textJobs(); $next = AgentTask::outstanding('b', $pair); $old = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
        textCheck($next['id'] !== $task['id'] && $old['result'] === $saved['result'] && $old['body'] === $saved['body'] && json_decode($old['context'], true)['text_groups'] === $facts['text_groups'], 'Fresh IDs after known partial preserve actual committed group receipts and immutable prior snapshot.');
    } else { textCheck($saved['state'] === 'verified' && $saved['slot'] === null && queue()['status'] === 'done', 'Only complete multi-field reconciliation finalizes.'); }
}

$pair = textSetup(multi: true); $task = textTask($pair); $done = textResult($task, ['name' => 'a', 'comment' => 'b']);
$body = json_decode($task['body'], true); $policy = settingsBody(); $done['revision'] = 4;
TextBoth::validateProgress($body, $policy, [], $done); textCheck(true, 'First observed snapshot may skip to revision4.');
$bump = $done; $bump['revision'] = 5; textReject(fn () => TextBoth::validateProgress($body, $policy, $done, $bump), 'No revision-only bumps.');
foreach ([0, 26] as $revision) { $bad = $done; $bad['revision'] = $revision; textReject(fn () => TextBoth::validateProgress($body, $policy, [], $bad), 'Finite revision1..25.'); }
$plan = $done; $plan['revision'] = 1; $plan['b_complete'] = false;
foreach ($plan['groups'] as &$g) { if ($g['side'] === 'b') { $g['phase'] = 'pending'; $g['mutation'] = 'unstarted'; $g['started_at'] = $g['model_ended_at'] = null; } } unset($g);
foreach ($plan['fields'] as &$f) { if ($f['winner'] === 'a') { $f['outcome'] = 'pending'; $f['mutation'] = 'unstarted'; $f['after'] = null; } } unset($f);
TextBoth::validateProgress($body, $policy, $plan, $done); textCheck(true, 'Skipped pending-to-done observations retain fixed plan facts.');
$regression = $done; $regression['revision'] = 5; $regression['fields'][0]['before']['value'] = 'Forged';
textReject(fn () => TextBoth::validateProgress($body, $policy, $done, $regression), 'Completed/original plan facts cannot change.');

foreach ([false, true] as $fakeRollback) {
    $pair = textSetup(); $task = textTask($pair); $result = textResult($task, 'b'); textObserve($task, $result);
    $after = Computer::$afterModelUpdate;
    Computer::$afterModelUpdate = static function ($item) use ($after): void {
        global $DB; $after($item); $DB->doQuery('COMMIT'); throw new RuntimeException('Injected wrapper implicitly ended model TX');
    };
    $DB->falseRollback = $fakeRollback; textJobs(); Computer::$afterModelUpdate = $after;
    $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]); $c = json_decode($saved['context'], true); $local = array_values($c['text_groups'])[0];
    textCheck($local['phase'] === 'intent' && $saved['slot'] !== null && queue()['status'] !== 'done', 'Implicit commit without live savepoint NEVER proves mutation none, even if full ROLLBACK would lie.');
    textCheck($DB->fullRollbacks === 0, 'Missing ownership fence does not roll back a replacement/foreign transaction.');
    textCheck($DB->firstRow('glpi_computers', ['id' => 101])['name'] === 'Genuine B text', 'Physical committed write remains visible evidence, not mislabeled no-mutation.');
}

$pair = textSetup(multi: true); $task = textTask($pair); $result = textResult($task, 'b'); textObserve($task, $result);
Computer::$ignoreUpdate = true; textJobs(); Computer::$ignoreUpdate = false;
$saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]); $local = array_values(json_decode($saved['context'], true)['text_groups'])[0];
textCheck($saved['state'] === 'partial' && $local['phase'] === 'blocked' && $local['receipts'] === [] && $DB->fullRollbacks > 0, 'Live owned savepoint plus same connection/full rollback proves combined group none after ignored model call.');
textCheck($DB->firstRow('glpi_computers', ['id' => 101])['name'] === 'Browser A' && $DB->firstRow('glpi_computers', ['id' => 101])['comment'] === 'Original genuine A Comments', 'Failed combined native group changes neither field.');

foreach ([false, true] as $laterEdit) {
    $pair = textSetup(); $task = textTask($pair); $result = textResult($task, 'b'); textObserve($task, $result);
    $DB->base->failReceiptCommit = true; textJobs();
    $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]); $c = json_decode($saved['context'], true);
    textCheck(array_values($c['text_groups'])[0]['phase'] === 'origin_pending' && $saved['slot'] !== null && queue()['status'] !== 'done', 'Lost COMMIT reply retains durable ALL-member receipts, not false success.');
    $DB->base->failSettlement = false; $logs = (int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn();
    if ($laterEdit) {
        $DB->base->clock = 500; $item = new Computer(); $item->getFromDB(101); $item->update(['id' => 101, 'name' => 'New genuine local edit']); FieldEvidence::finishWork();
        $logs++;
    }
    $saved = textObserve($saved, $result); AssetSyncQueue::notify('Computer', 101, 'b'); textJobs();
    $after = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
    textCheck((int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn() === $logs, 'Receipt recovery never repeats a local model/History write.');
    textCheck($laterEdit ? $after['slot'] !== null && queue()['status'] !== 'done' : $after['state'] === 'verified' && $after['slot'] === null, 'Later edit blocks unresolved receipt, unchanged committed receipt can settle/finalize.');
}

$pair = textSetup(multi: true); $task = textTask($pair); $result = textResult($task, ['name' => 'conflict', 'comment' => 'b']);
textObserve($task, $result); textJobs(); $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
textCheck(!TextBoth::partialRetryDue($saved, AgentTask::byId('b', $pair['id'])), 'Our own inherited sibling is not a fresh human change that bypasses conflict retry delay.');
$DB->base->clock = 700; $item = new Computer(); $item->getFromDB(101); $item->update(['id' => 101, 'name' => 'New genuine conflict resolution']); FieldEvidence::finishWork();
RestDispatchWire::reset(); RestWork::sendPending(hrtime(true)+20_000_000_000);
textCheck(RestDispatchWire::$calls === [] && queue()['status'] === 'pending', 'Genuine mapped edit bypasses known-partial60s delay with zero pre-wake auth.');

$pair = textSetup(); $task = textTask($pair); $body = json_decode($task['body'], true); $result = textResult($task, 'a');
$ctx = json_decode($task['context'], true); $ctx['rest_lease'] = str_repeat('1',32); $ctx['rest_sent'] = true;
$DB->update(AgentTask::TABLE, ['context' => json_encode($ctx)], ['id' => $task['id']]); $task = AgentTask::outstanding('b', $pair);
$plan = $result; $plan['b_complete'] = false; $plan['fields'][0]['outcome'] = 'pending'; $plan['fields'][0]['mutation'] = 'unstarted'; $plan['fields'][0]['after'] = null;
$plan['groups'][0]['phase'] = 'pending'; $plan['groups'][0]['mutation'] = 'unstarted'; $plan['groups'][0]['started_at'] = $plan['groups'][0]['model_ended_at'] = null;
$item = new Computer(); $item->getFromDB(101); $item->update(['id' => 101, 'name' => 'Source superseded after reservation']); FieldEvidence::finishWork();
$record = settingsWorkRecord($task, $plan); $f = $plan['fields'][0];
$record['read_observation'] = ['revision' => 1, 'receipt_hash' => AgentTask::hash($plan), 'fields' => [['mapping_id' => $f['mapping_id'], 'receipt_hash' => null, 'current_matches' => null]]];
$closed = $plan; $closed['revision'] = 2; $closed['b_complete'] = true; $closed['fields'][0]['outcome'] = 'blocked'; $closed['fields'][0]['mutation'] = 'none'; $closed['fields'][0]['reason'] = 'source_superseded';
$closed['fields'][0]['after'] = $closed['fields'][0]['before'];
$closed['groups'][0]['phase'] = 'blocked'; $closed['groups'][0]['mutation'] = 'none'; $closed['groups'][0]['reason'] = 'source_superseded';
$closedRecord = settingsWorkRecord($task, $closed); $closedRecord['read_observation'] = ['revision' => 2, 'receipt_hash' => AgentTask::hash($closed),
    'fields' => [['mapping_id' => $f['mapping_id'], 'receipt_hash' => AgentTask::hash($closed['fields'][0]), 'current_matches' => null]]];
RestDispatchWire::reset(); RestDispatchWire::$replies = [['status' => 200, 'body' => [$record]], ['status' => 201, 'body' => ['id' => 77]], ['status' => 200, 'body' => [$closedRecord]]];
RestWork::sendPending(hrtime(true)+20_000_000_000);
$calls = array_values(array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST'));
$posted = json_decode($calls[0]['options'][CURLOPT_POSTFIELDS], true)['input'][0];
textCheck($posted['allow_rows'] === [] && $posted['body'] === $task['body'] && $posted['hash'] === $task['hash'], 'Changed source continuation restricts rows to empty without changing body/hash or inferring expired lease absence.');
textJobs(); $saved = $DB->firstRow(AgentTask::TABLE, ['id' => $task['id']]);
textCheck($saved['state'] === 'partial' && TextBoth::partialRetryDue($saved, AgentTask::byId('b', $pair['id'])), 'Known-none source-superseded block needs no impossible boolean observation and changed source bypasses delay.');
textCheck(count(json_decode($saved['context'], true)['text_snapshots']) === 2, 'Both actually received distinct revision snapshots retained.');

// New pure entry points keep strict raw identity, bounds and original intervals independent of DB.
$pin = FieldsText::selectedTextPin('name');
foreach ([false, 0, [], new stdClass()] as $raw) { textReject(fn () => TextBoth::normalize($raw, $pin), 'No scalar coercion.'); }
textCheck(TextBoth::normalize(null, $pin) === '' && TextBoth::valueHash(null) !== TextBoth::valueHash(''), 'Null equals blank only for decision, not raw guard.');
textReject(fn () => TextBoth::normalize(str_repeat('x', 256), $pin), 'Actual destination character bound.');
textReject(fn () => TextBoth::normalize("\xff", $pin), 'Invalid UTF8 held.');

$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --selected';
passthru($command, $exit); textCheck($exit === 0, 'Selected custom source/wrapper tests passed independently.');
echo "Text Both A: $textChecks checks PASS (host SQL/model/HTTP doubles; full native matrix separate).\n";
