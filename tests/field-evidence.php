<?php
declare(strict_types=1);

require __DIR__ . '/agent-sqlite.php';
require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../assetsync20agent/src/autoload.php';

final class CaptureDB
{
    public AgentSqlite $sql;
    public int $connection = 42;
    public int $clock = 1700000000;
    public array $epochs = [];
    public array $queries = [];
    public int $error = 0;
    public ?Closure $before = null;
    public function __construct(string $file = ':memory:') {
        $this->sql = new AgentSqlite($file);
        $this->sql->pdo->sqliteCreateFunction('CONNECTION_ID', fn () => $this->connection, 0);
        $this->sql->pdo->sqliteCreateFunction('SHA2', static fn ($value, $bits) => hash('sha256', $value), 2);
        $this->sql->pdo->sqliteCreateFunction('JSON_KEYS', static function ($json, $path) {
            $data = json_decode($json, true);
            return json_encode(array_keys($path === '$.attempts' ? $data['attempts'] : $data));
        }, 2);
    }
    public function doQuery(string $query): mixed
    {
        $this->queries[] = $query;
        if ($this->before) { ($this->before)($query); }
        $this->error = 0;
        if ($query === 'SELECT CONNECTION_ID() AS connection_id') { return $this->sql->pdo->query('SELECT ' . $this->connection . ' AS connection_id'); }
        if ($query === 'SELECT UNIX_TIMESTAMP() AS epoch') {
            return $this->sql->pdo->query('SELECT ' . ($this->epochs === [] ? $this->clock : array_shift($this->epochs)) . ' AS epoch');
        }
        if ($query === 'SELECT @@SESSION.autocommit AS enabled') { return $this->sql->pdo->query('SELECT 1 AS enabled'); }
        if (str_starts_with($query, 'SAVEPOINT ')) {
            return !$this->sql->pdo->inTransaction() || $this->sql->doQuery($query) !== false;
        }
        if (str_starts_with($query, 'RELEASE SAVEPOINT ') && !$this->sql->pdo->inTransaction()) { $this->error = 1305; return false; }
        if (preg_match('/^SHOW INDEX FROM `([^`]+)` WHERE Key_name = \'field_model\' AND Seq_in_index = 1$/', $query, $match)) {
            $rows = $this->sql->pdo->query('PRAGMA index_info(`' . $match[1] . '_field_model`)')->fetchAll(PDO::FETCH_ASSOC);
            return $this->sql->pdo->query('SELECT ' . $this->quote($rows[0]['name'] ?? '') . ' AS Column_name' . ($rows === [] ? ' WHERE 0' : ''));
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` ADD KEY `field_model` \(`model_class`\)$/', $query, $match)) {
            return $this->sql->pdo->exec('CREATE INDEX `' . $match[1] . '_field_model` ON `' . $match[1] . '`(model_class)') !== false;
        }
        return $this->sql->doQuery(str_replace(' LOCK IN SHARE MODE', '', $query));
    }
    public function errno(): int { return $this->error; }
    public function tableExists(string $table): bool { return $this->sql->tableExists($table); }
    public function quote(mixed $value): string { return $this->sql->quote($value); }
    public function fetchAssoc(PDOStatement $result): ?array { return $this->sql->fetchAssoc($result); }
    public function affectedRows(): int { return $this->sql->affectedRows(); }
}

final class Config
{
    public static ?array $staleSnapshot = null;
    public static function getConfigurationValues(string $context, array $names = []): array
    {
        global $DB;
        if (self::$staleSnapshot !== null) { return self::$staleSnapshot; }
        $values = [];
        foreach ($DB->sql->pdo->query('SELECT name,value FROM glpi_configs WHERE context=' . $DB->quote($context)) as $row) {
            if (!$names || in_array($row['name'], $names, true)) { $values[$row['name']] = $row['value']; }
        }
        return $values;
    }
    public static function setConfigurationValues(string $context, array $values): void
    {
        global $DB;
        foreach ($values as $name => $value) {
            $statement = $DB->sql->pdo->prepare('INSERT INTO glpi_configs(context,name,value) VALUES (?,?,?) ON CONFLICT(context,name) DO UPDATE SET value=excluded.value');
            $statement->execute([$context, $name, $value]);
        }
    }
}

class Computer
{
    public array $fields;
    public array|false $input = [];
    public array $updates = [];
    public array $oldvalues = [];
    public function __construct(int $id = 1) { global $DB; $this->fields = $DB->sql->pdo->query('SELECT * FROM glpi_computers WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC); }
    public function rawSearchOptions(): array { return [['id' => 1, 'table' => 'glpi_computers', 'field' => 'name'], ['id' => 16, 'table' => 'glpi_computers', 'field' => 'comment']]; }
    public static function getTable(): string { return 'glpi_computers'; }
}
final class PluginFieldsComputerExtra extends Computer
{
    public function __construct() { global $DB; $this->fields = $DB->sql->pdo->query('SELECT * FROM glpi_plugin_fields_computerextras WHERE id=1')->fetch(PDO::FETCH_ASSOC); }
    public static function getTable(): string { return 'glpi_plugin_fields_computerextras'; }
}
final class PluginFieldsContainer
{
    public static function getClassname(string $type, string $name): string { return 'PluginFieldsComputerExtra'; }
    public static function getAddSearchOptions(): array { return []; } // The CLI/profile-filtered lookup must not be needed.
}
final class PluginFieldsField { public const SEARCH_OPTION_STARTING_INDEX = 76665; }
final class CaptureContext { public static bool $api = false; public static bool $cli = false; public static bool $inventory = false; }
function isAPI(): bool { return CaptureContext::$api; }
function isCommandLine(): bool { return CaptureContext::$cli; }
final class Session
{
    public static function isCron(): bool { return CaptureContext::$cli; }
    public static function isInventory(): bool { return CaptureContext::$inventory; }
    public static function getLoginUserID(bool $human = true): int { return 7; }
}

$checks = 0;
function check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function internal(string $class, string $method, mixed ...$args): mixed { return (new ReflectionMethod($class, $method))->invoke(null, ...$args); }
function resetCapture(string $class): void
{
    foreach (['owned', 'frames', 'origins'] as $name) { (new ReflectionProperty($class, $name))->setValue(null, []); }
}
function fixture(string $class, string $file = ':memory:'): void
{
    global $DB;
    resetCapture($class);
    Config::$staleSnapshot = null;
    $DB = new CaptureDB($file);
    foreach ([
        'CREATE TABLE glpi_configs(context TEXT,name TEXT,value TEXT,PRIMARY KEY(context,name))',
        'CREATE TABLE glpi_computers(id INTEGER PRIMARY KEY,entities_id INTEGER,is_deleted INTEGER,name TEXT,comment TEXT)',
        "INSERT INTO glpi_computers VALUES(1,0,0,'Original','Original comments')",
        'CREATE TABLE glpi_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,items_id INTEGER,itemtype TEXT,id_search_option INTEGER,old_value TEXT,new_value TEXT,itemtype_link TEXT,linked_action INTEGER,user_name TEXT,date_mod TEXT)',
        'CREATE TABLE glpi_plugin_fields_containers(id INTEGER PRIMARY KEY,name TEXT,itemtypes TEXT,is_active INTEGER)',
        'CREATE TABLE glpi_plugin_fields_fields(id INTEGER PRIMARY KEY,plugin_fields_containers_id INTEGER,name TEXT,type TEXT,multiple INTEGER,is_active INTEGER,label TEXT,is_readonly INTEGER)',
        'CREATE TABLE glpi_plugin_fields_computerextras(id INTEGER PRIMARY KEY,items_id INTEGER,itemtype TEXT,plugin_fields_containers_id INTEGER,customtext TEXT,notes TEXT)',
        "INSERT INTO glpi_plugin_fields_containers VALUES(1,'extra','[\"Computer\"]',1)",
        "INSERT INTO glpi_plugin_fields_fields VALUES(1,1,'customtext','text',0,1,'Custom',0),(2,1,'notes','textarea',0,1,'Notes',0)",
        "INSERT INTO glpi_plugin_fields_computerextras VALUES(1,1,'Computer',1,'Custom old','Notes old')",
    ] as $sql) { $DB->doQuery($sql); }
    check($class::install(), 'Supported install creates private table.');
    $class::activate();
    $_SERVER['REQUEST_METHOD'] = 'POST';
}
function history(int $reference, string $old, string $new, string $actor = 'same account'): void
{
    global $DB;
    $project = static function (string $value): string {
        $decoded = json_decode($value);
        return mb_substr(is_array($decoded) || is_object($decoded) ? '' : $value, 0, 255);
    };
    $statement = $DB->sql->pdo->prepare("INSERT INTO glpi_logs(items_id,itemtype,id_search_option,old_value,new_value,itemtype_link,linked_action,user_name,date_mod) VALUES(1,'Computer',?,?,?,'',0,?,'2001-01-01 00:00:00')");
    $statement->execute([$reference, $project($old), $project($new), $actor]);
}
function modelWrite(string $class, Computer $item, string $column, string $new, bool $addHistory = true): void
{
    global $DB;
    $old = $item->fields[$column];
    $item->input[$column] = $new;
    $item->oldvalues = [$column => $old]; $item->updates = [$column];
    $DB->doQuery('UPDATE `' . $item::getTable() . '` SET `' . $column . '`=' . $DB->quote($new) . ' WHERE id=' . (int) $item->fields['id']);
    $item->fields[$column] = $new;
    $reference = ['name' => 1, 'comment' => 16, 'customtext' => 76666, 'notes' => 76667][$column];
    if ($addHistory) { history($reference, $old, $new); }
    $class::after($item);
}
function save(string $class, string $column, string $new, bool $custom = false): void
{
    $item = $custom ? new PluginFieldsComputerExtra() : new Computer();
    $item->input = [$column => $new];
    $class::before($item);
    modelWrite($class, $item, $column, $new, !$custom);
    if ($custom) { history($column === 'customtext' ? 76666 : 76667, $item->oldvalues[$column], $new); }
    $class::finishRequest();
}

if (($argv[1] ?? '') === 'fatal') {
    $class = GlpiPlugin\Assetsync20\FieldEvidence::class;
    fixture($class);
    $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Interrupted');
    register_shutdown_function(static function () use ($class): void {
        $head = internal($class, 'head', 1, 'native:name');
        echo 'Fatal pending: ', count($head['state']['attempts']), ':', $class::read(1, 'native:name') === null && !$class::settle(1, 'native:name') ? 'unknown' : 'WRONG', "\n";
    });
    trigger_error('Injected capture test interruption.', E_USER_ERROR);
    exit(1);
}

if (($argv[1] ?? '') === 'recover') {
    $DB = new CaptureDB($argv[2]); $_SERVER['REQUEST_METHOD'] = 'POST'; $class = $argv[3];
    $settled = $class::settle(1, 'native:name');
    echo json_encode(['settled' => $settled, 'origin' => $class::read(1, 'native:name'),
        'unchanged_settled' => $class::settle(1, 'native:comment')], JSON_THROW_ON_ERROR), "\n";
    exit;
}

foreach ([GlpiPlugin\Assetsync20\FieldEvidence::class, GlpiPlugin\Assetsync20agent\FieldEvidence::class] as $class) {
    fixture($class);
    check($class::read(1, 'native:name') === null, 'New heads never backfill origins.');
    $indexTable = (new ReflectionClass($class))->getConstant('TABLE');
    $index = $DB->sql->pdo->query('PRAGMA index_info(`' . $indexTable . '_field_model`)')->fetchAll(PDO::FETCH_ASSOC);
    check(($index[0]['name'] ?? '') === 'model_class'
        && (bool) array_filter($DB->queries, static fn ($sql) => str_starts_with($sql, 'CREATE TABLE') && str_contains($sql, 'KEY `field_model` (`model_class`)')),
        'Private head DDL/index covers bootstrap model-class discovery; actual MySQL EXPLAIN remains a gate.');
    save($class, 'name', 'First');
    $first = $class::read(1, 'native:name');
    check($first !== null && $first['from_epoch'] === 1700000000 && $first['to_epoch'] === 1700000000, 'Native ordinary model save gets original inclusive bounds.');
    $offset = count($DB->queries); $class::read(1, 'native:name');
    check(!array_filter(array_slice($DB->queries, $offset), static fn ($sql) => preg_match('/^(UPDATE|INSERT|DELETE|START|COMMIT|ROLLBACK) /', $sql)), 'Current evidence validation never changes stored evidence or owns transactions.');
    save($class, 'comment', 'Changed comments');
    check($class::read(1, 'native:comment') !== null && $class::read(1, 'native:name') === $first, 'Another selected field preserves unchanged Name origin.');
    $move = new Computer(); $class::before($move);
    $DB->doQuery('UPDATE glpi_computers SET entities_id=2 WHERE id=1');
    $move->updates = ['entities_id']; $move->oldvalues = ['entities_id' => 0]; $move->input = ['entities_id' => 2];
    $class::after($move); $class::finishRequest();
    check($class::read(1, 'native:name') === $first, 'Entity-only transfer retains true field origin without minting another event.');
    $noop = new Computer(); $class::before($noop); $class::finishRequest();
    check($class::read(1, 'native:name') === $first, 'No-post no-op preserves only proven uninterrupted prior evidence.');
    $DB->epochs = [1700000010,1700000011,1700000013,1700000014];
    save($class, 'name', 'Cross second');
    $cross = $class::read(1, 'native:name');
    check($cross !== null && $cross['from_epoch'] === 1700000010 && $cross['to_epoch'] === 1700000013, 'Cross-second/long interval uses original pre/post clocks, not settlement time.');
    $DB->epochs = [1700000020,1700000020,1700000019,1700000019];
    save($class, 'name', 'Clock backwards');
    check($class::read(1, 'native:name') === null, 'Backwards UTC cannot qualify.');

    fixture($class);
    $stale = new Computer();
    $DB->doQuery("UPDATE glpi_computers SET name='Already new' WHERE id=1");
    $class::before($stale); modelWrite($class, $stale, 'name', 'Already new'); $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'Stale loaded old plus plausible physical-no-op History is never origin proof.');

    fixture($class);
    $firstWriter = new Computer(); $oldSnapshotWriter = new Computer();
    $class::before($firstWriter);
    Config::$staleSnapshot = []; // Old RR Config absence must not bypass current active coverage.
    $DB->sql->pdo->beginTransaction();
    $class::before($oldSnapshotWriter);
    modelWrite($class, $oldSnapshotWriter, 'name', 'Shared new');
    $DB->sql->pdo->commit();
    modelWrite($class, $firstWriter, 'name', 'Shared new', false); // No own History: cannot borrow the other's single row.
    $class::finishWork(); Config::$staleSnapshot = null;
    check($class::read(1, 'native:name') === null, 'Stale ordinary Config absence cannot skip registration or lend one writer another writer\'s History.');
    check((bool) array_filter($DB->queries, static fn ($sql) => str_contains($sql, 'glpi_configs') && str_ends_with($sql, 'LOCK IN SHARE MODE')),
        'Absent/inactive marker decisions always use a shared CURRENT read; actual MySQL RR remains a separate gate.');

    fixture($class);
    $one = new Computer(); $two = new Computer();
    $class::before($one); $class::before($two);
    modelWrite($class, $two, 'name', 'Second'); modelWrite($class, $one, 'name', 'First');
    $owned = (new ReflectionProperty($class, 'owned'))->getValue();
    $entry = reset($owned); internal($class, 'complete', $entry);
    $head = internal($class, 'head', 1, 'native:name');
    check(count($head['state']['attempts']) === 1 && reset($head['state']['attempts'])['tainted'], 'One completion cannot clear another overlapping token.');
    $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'Same-account overlapping writes remain unknown in either completion order.');

    fixture($class);
    $outer = new Computer(); $interleaved = new Computer(); $raced = false;
    $DB->before = static function (string $sql) use ($class, $interleaved, &$raced): void {
        if (!$raced && str_starts_with($sql, 'UPDATE `glpi_plugin_assetsync20') && str_contains($sql, '`generation`=`generation`+1')) {
            $raced = true;
            $class::before($interleaved);
        }
    };
    $class::before($outer); $DB->before = null;
    $head = internal($class, 'head', 1, 'native:name');
    check($raced && count($head['state']['attempts']) === 2 && !array_filter($head['state']['attempts'], static fn ($a) => !$a['tainted']),
        'Registration CAS loses a real interleaved statement, retries, retains and taints both tokens.');
    $state = $head['state']; $state['current'] = null;
    check(internal($class, 'cas', $head, $state) && !internal($class, 'cas', $head, $state), 'A stale CAS cannot overwrite a later generation.');
    modelWrite($class, $outer, 'name', 'Outer'); modelWrite($class, $interleaved, 'name', 'Interleaved'); $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'Interleaved CAS registration never invents a no-overlap candidate.');

    fixture($class); $outer = new Computer(); $other = new Computer();
    $class::before($outer); $class::before($other);
    $entries = array_values((new ReflectionProperty($class, 'owned'))->getValue());
    check(internal($class, 'complete', $entries[0]), 'An overlapping owner can atomically retire its own tainted token.');
    $head = internal($class, 'head', 1, 'native:name');
    check(count($head['state']['attempts']) === 1 && isset($head['state']['attempts'][$entries[2]['token']])
        && $head['state']['current'] === null, 'Tainted cleanup retains the other token and cannot promote provenance.');
    check(internal($class, 'complete', $entries[2]) && internal($class, 'head', 1, 'native:name')['state']['attempts'] === [],
        'The second owner can retire across the first cleanup generation without a stale whole-head CAS.');
    fixture($class); $outer = new Computer(); $other = new Computer(); $class::before($outer);
    $entry = array_values((new ReflectionProperty($class, 'owned'))->getValue())[0]; $raced = false;
    $DB->before = static function (string $sql) use ($class, $other, &$raced): void {
        global $DB;
        if (!$raced && str_contains($sql, 'AND CAST(`evidence` AS BINARY)')) {
            $raced = true; $DB->before = null; $class::before($other);
        }
    };
    check(internal($class, 'complete', $entry) && $raced
        && count(internal($class, 'head', 1, 'native:name')['state']['attempts']) === 1,
        'A registration racing completion retires only the now-tainted own token after the CAS loses.');

    fixture($class);
    $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Candidate');
    $before = count($DB->queries); $_SERVER['REQUEST_METHOD'] = 'GET'; $class::finishRequest();
    check(count($DB->queries) === $before && $class::read(1, 'native:name') === null, 'GET cannot settle, clean up or promote.');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $DB->connection++; $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'A different DB connection cannot finish a candidate.');

    fixture($class);
    $DB->sql->pdo->beginTransaction();
    $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Uncommitted'); $class::finishRequest();
    check($DB->sql->pdo->inTransaction() && $class::read(1, 'native:name') === null, 'Callback neither commits nor publishes a caller-owned transaction.');
    $DB->sql->pdo->rollBack();
    check((new Computer())->fields['name'] === 'Original', 'Caller rollback retains original value.');
    check(!array_filter($DB->queries, static fn ($sql) => in_array($sql, ['START TRANSACTION','COMMIT','ROLLBACK'], true)), 'No hook/callback transaction ownership.');
    $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'Rollback of a newly created head is not recovered as success.');
    fixture($class);
    $DB->doQuery("INSERT INTO glpi_computers VALUES(2,0,0,'Other asset','Other comments')");
    $DB->sql->pdo->beginTransaction();
    $firstAsset = new Computer(); $secondAsset = new Computer(2);
    $class::before($firstAsset); $class::before($secondAsset);
    modelWrite($class, $firstAsset, 'name', 'Foreign TX');
    check(!array_filter($DB->queries, static fn ($sql) => str_contains($sql, 'glpi_configs') && str_contains($sql, 'FOR UPDATE')),
        'Two unrelated assets in a foreign TX take no exclusive global marker lock (actual InnoDB concurrency is an independent gate).');
    $DB->sql->pdo->commit(); $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'A foreign-transaction model write remains durably unknown after its caller commits.');

    fixture($class); save($class, 'name', 'Human original');
    $origin = $class::read(1, 'native:name');
    $DB->sql->pdo->beginTransaction();
    $class::withOrigin(1, 'native:name', internal($class, 'valueHash', 'Human original'),
        internal($class, 'valueHash', 'Inherited TX'), $origin, static function () use ($class): void {
            $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Inherited TX');
        });
    $class::finishWork();
    check($DB->sql->pdo->inTransaction() && $class::read(1, 'native:name') === null, 'Exact inherited TX remains owned by its caller until commit.');
    $DB->sql->pdo->commit(); $class::finishWork();
    check($class::read(1, 'native:name') === $origin, 'Exact inherited field origin survives an owned model transaction.');
    $DB->sql->pdo->beginTransaction();
    $class::withoutOrigin(static function () use ($class): void {
        $item = new Computer(); $class::before($item); modelWrite($class, $item, 'comment', 'Legacy TX comments');
    });
    $DB->sql->pdo->commit(); $class::finishWork();
    check($class::read(1, 'native:name') === $origin && $class::read(1, 'native:comment') === null,
        'Proven unchanged fields survive a legacy TX while its changed field stays unknown.');
    $beforeRollback = internal($class, 'head', 1, 'native:name');
    $DB->sql->pdo->beginTransaction();
    $class::withOrigin(1, 'native:name', internal($class, 'valueHash', 'Inherited TX'),
        internal($class, 'valueHash', 'Rolled back'), $origin, static function () use ($class): void {
            $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Rolled back');
        });
    $DB->sql->pdo->rollBack(); $class::finishWork();
    check(internal($class, 'head', 1, 'native:name') === $beforeRollback && $class::read(1, 'native:name') === $origin,
        'Caller rollback restores exact evidence generation and prior inherited origin.');

    fixture($class); CaptureContext::$cli = true; $_SERVER['REQUEST_METHOD'] = 'GET';
    $class::withoutOrigin(static function () use ($class): void {
        $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Existing web Cron job');
    });
    $queryCount = count($DB->queries); $class::finishRequest();
    check(!$class::settle(1, 'native:name') && count($DB->queries) === $queryCount, 'Public GET cleanup and settlement remain forbidden.');
    if ($class === GlpiPlugin\Assetsync20\FieldEvidence::class) {
        (new GlpiPlugin\Assetsync20\AssetSyncService())->processJob(['id' => 0, 'itemtype' => 'Computer', 'items_id' => 1]);
    } else { GlpiPlugin\Assetsync20agent\TaskExecutor::execute([], []); }
    check(internal($class, 'head', 1, 'native:name')['state']['attempts'] === [] && $class::read(1, 'native:name') === null,
        'Existing GET job finalizer drains its own token without minting human provenance.');
    CaptureContext::$cli = false;

    fixture($class);
    $item = new Computer(); $item->input = ['name' => 'Must not write'];
    $DB->sql->failOn = 'INSERT IGNORE'; $laterCallback = false;
    try {
        $class::before($item);
        $laterCallback = true;
        // Fields' later callback expects array input; cancellation must abort before it runs.
        array_key_exists('plugin_fields_containers_id', $item->input);
        throw new LogicException('Cancellation was not raised.');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'This save was cancelled because field change tracking is unavailable.', 'Cancellation error is clear and sanitized.');
    }
    $DB->sql->failOn = null;
    check($item->input === false && !$laterCallback && (new Computer())->fields['name'] === 'Original', 'Registration failure aborts remaining callbacks before model SQL.');

    fixture($class);
    save($class, 'name', 'Prior origin');
    $item = new Computer(); $class::before($item);
    history(1, 'Prior origin', 'Other'); history(1, 'Other', 'Prior origin');
    $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'Pre-only cleanup cannot preserve prior origin after unexplained field History.');
    fixture($class);
    $item = new Computer(); $DB->doQuery('UPDATE glpi_computers SET entities_id=2 WHERE id=1');
    $class::before($item); modelWrite($class, $item, 'name', 'Metadata changed'); $class::finishRequest();
    check($class::read(1, 'native:name') === null, 'Fresh value agreement cannot conceal stale model-loaded metadata.');

    fixture($class);
    $item = new PluginFieldsComputerExtra(); $class::before($item); modelWrite($class, $item, 'customtext', 'Custom new', false);
    $class::finishRequest();
    check($class::read(1, 'fields:1') === null && count(internal($class, 'head', 1, 'fields:1')['state']['attempts']) === 1, 'Child post without parent History stays unfinished.');
    history(76666, 'Custom old', 'Custom new'); $class::finishRequest();
    check($class::read(1, 'fields:1') !== null, 'Fields settles with official definition reference even when CLI search options are empty.');
    check(!array_filter($DB->queries, static fn ($sql) => preg_match('/FROM `glpi_plugin_fields_(fields|containers)`/', $sql) && str_ends_with($sql, 'FOR UPDATE')),
        'Shared global definitions take no exclusive metadata lock through a foreign model transaction.');
    check((bool) array_filter($DB->queries, static fn ($sql) => str_contains($sql, '`plugin_fields_containers_id`=1 LOCK IN SHARE MODE')),
        'Initial potential-field enumeration is CURRENT too, so stale field-type snapshots cannot omit an attempt.');
    $customOrigin = $class::read(1, 'fields:1');
    $DB->doQuery("UPDATE glpi_plugin_fields_fields SET label='Renamed',is_readonly=1 WHERE id=1");
    check($class::read(1, 'fields:1') === $customOrigin, 'Label/readonly UI changes do not alter the content-time identity.');
    $DB->doQuery("UPDATE glpi_plugin_fields_fields SET type='textarea' WHERE id=1");
    check($class::read(1, 'fields:1') === null, 'Changed technical field type invalidates evidence.');
    $DB->doQuery("UPDATE glpi_plugin_fields_fields SET type='text',name='renamedcolumn' WHERE id=1");
    check($class::read(1, 'fields:1') === null, 'Changed physical column identity invalidates evidence.');
    $DB->doQuery("UPDATE glpi_plugin_fields_fields SET name='customtext',plugin_fields_containers_id=99 WHERE id=1");
    check($class::read(1, 'fields:1') === null, 'Changed container identity invalidates evidence.');
    $DB->doQuery('UPDATE glpi_plugin_fields_fields SET plugin_fields_containers_id=1 WHERE id=1');
    save($class, 'notes', str_repeat('x', 400), true);
    check($class::read(1, 'fields:2') !== null, 'Textarea full hash is distinct from truncated History projection.');
    save($class, 'customtext', '{"private":"value"}', true);
    check($class::read(1, 'fields:1') !== null, 'Blank JSON History projection is corroboration, not full value proof.');
    $table = (new ReflectionClass($class))->getConstant('TABLE');
    $stored = implode('', $DB->sql->pdo->query('SELECT evidence FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN));
    check(!str_contains($stored, 'private') && !str_contains($stored, str_repeat('x', 255)), 'Evidence persists hashes, not raw field values.');
    $DB->doQuery('UPDATE glpi_plugin_fields_containers SET is_active=0 WHERE id=1');
    check(in_array('PluginFieldsComputerExtra', $class::hookTypes(), true), 'Retained child hooks survive container deactivation.');
    save($class, 'customtext', 'Retained local facts', true);
    check($class::read(1, 'fields:1') !== null, 'Technical capture remains independent of mapping/route/transport/container active admission.');

    fixture($class);
    $item = new PluginFieldsComputerExtra(); $class::before($item); modelWrite($class, $item, 'customtext', 'Duplicated', false);
    history(76666, 'Custom old', 'Duplicated'); history(76666, 'Custom old', 'Duplicated'); $class::finishRequest();
    check($class::read(1, 'fields:1') === null, 'Duplicate matching History rows do not qualify a candidate.');
    fixture($class);
    $item = new PluginFieldsComputerExtra(); $class::before($item); modelWrite($class, $item, 'customtext', 'Actual full text', false);
    history(76666, 'Custom old', 'Wrong projection'); $class::finishRequest();
    check($class::read(1, 'fields:1') === null, 'A History row with the right field but wrong projection is insufficient.');
    fixture($class);
    $DB->doQuery("INSERT INTO glpi_plugin_fields_computerextras VALUES(2,1,'Computer',1,'Duplicate','Duplicate')");
    $item = new PluginFieldsComputerExtra();
    try { $class::before($item); throw new LogicException('Duplicate child not rejected.'); } catch (RuntimeException) {}
    check($item->input === false, 'Duplicate selected child rows abort before model SQL.');

    fixture($class);
    save($class, 'name', 'Qualified'); $origin = $class::read(1, 'native:name');
    history(1, 'Qualified', 'Elsewhere', 'other actor'); history(1, 'Elsewhere', 'Qualified', 'other actor');
    check($class::read(1, 'native:name') === null, 'All-actor field watermark detects logged edit/revert during missed capture.');
    fixture($class); save($class, 'name', 'Qualified');
    $DB->doQuery("UPDATE glpi_logs SET user_name='changed anchor' WHERE id_search_option=1");
    check($class::read(1, 'native:name') === null, 'Anchor content changes invalidate evidence.');
    fixture($class); save($class, 'name', 'Qualified');
    $DB->doQuery('DELETE FROM glpi_logs WHERE id_search_option=1');
    check($class::read(1, 'native:name') === null, 'Missing History anchor cannot be replaced by a scan-now origin.');
    fixture($class); save($class, 'name', 'Qualified'); $class::deactivate(); $class::activate();
    check($class::read(1, 'native:name') === null, 'Supported lifecycle rotates epoch; no old origin survives reactivation.');
    check(in_array('PluginFieldsComputerExtra', $class::hookTypes(), true), 'Retained generated classes remain hooked independent of mapping.');
    fixture($class);
    $pending = new Computer(); $class::before($pending);
    $tokens = array_keys(internal($class, 'head', 1, 'native:name')['state']['attempts']);
    $class::deactivate(); $class::activate(); $class::before(new Computer());
    check(array_diff($tokens, array_keys(internal($class, 'head', 1, 'native:name')['state']['attempts'])) === [], 'Epoch rotation never discards foreign unfinished tokens.');

    fixture($class);
    $class::withoutOrigin(static fn () => save($class, 'name', 'Legacy sync'));
    check($class::read(1, 'native:name') === null, 'Unknown legacy/derived sync never mints human origin.');
    $beforeHash = internal($class, 'valueHash', 'Legacy sync'); $afterHash = internal($class, 'valueHash', 'Inherited');
    $class::withOrigin(1, 'native:name', $beforeHash, $afterHash, $origin, static fn () => save($class, 'name', 'Inherited'));
    check($class::read(1, 'native:name') === $origin, 'Exact trusted field/value scope inherits original event and interval.');
    $class::withOrigin(1, 'native:comment', $afterHash, $beforeHash, $origin, static fn () => save($class, 'name', 'Wrong scope'));
    check($class::read(1, 'native:name') === null, 'A trusted scope for another field cannot qualify this mutation.');
    foreach (['api', 'cli', 'inventory'] as $context) {
        fixture($class);
        CaptureContext::$$context = true;
        save($class, 'name', 'Automated write');
        CaptureContext::$$context = false;
        check($class::read(1, 'native:name') === null, 'API/CLI/inventory writes do not mint human origin.');
        save($class, 'name', 'Browser same account');
        check($class::read(1, 'native:name') !== null, 'A browser edit by the same account still qualifies independently.');
    }

    fixture($class); save($class, 'name', 'Qualified');
    $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Storage failed');
    $DB->sql->failOn = 'UPDATE `glpi_plugin_assetsync20'; $class::finishRequest();
    $DB->sql->failOn = null;
    check($class::read(1, 'native:name') === null && count(internal($class, 'head', 1, 'native:name')['state']['attempts']) === 1,
        'Settlement storage failure retains pending and never reports success.');
    $class::finishRequest();
    check($class::read(1, 'native:name') !== null, 'Same-request explicit retry only publishes after durable CAS succeeds.');

    $database = tempnam(sys_get_temp_dir(), 'as20-capture-');
    try {
        fixture($class, $database);
        $DB->epochs = [1700000100,1700000101,1700000104,1700000105];
        $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Deferred candidate');
        $waited = false;
        $DB->before = static function (string $sql) use (&$waited): void {
            if (!$waited && str_contains($sql, 'SET `evidence`=JSON_SET')) { $waited = true; usleep(120000); }
        };
        $class::finishRequest(); $DB->before = null;
        $head = internal($class, 'head', 1, 'native:name');
        check($waited && reset($head['state']['attempts'])['candidate']['recoverable'] && $class::read(1, 'native:name') === null,
            'Budget expiry leaves a durable exact post candidate with clean idle request-end proof.');
        resetCapture($class);
        $lines = []; $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' recover ' . escapeshellarg($database) . ' ' . escapeshellarg($class) . ' 2>&1', $lines, $status);
        $recovered = $status === 0 ? json_decode(implode('', $lines), true) : null;
        check($recovered && $recovered['settled'] && $recovered['origin']['from_epoch'] === 1700000100
            && $recovered['origin']['to_epoch'] === 1700000104 && $recovered['unchanged_settled'],
            'A fresh PHP process settles change/unchanged post receipts without changing original bounds.');
    } finally { unlink($database); }
    fixture($class);
    $item = new Computer(); $class::before($item);
    resetCapture($class);
    check(!$class::settle(1, 'native:name'), 'Explicit settlement cannot turn a pre-only crashed request into success.');
    fixture($class);
    $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'No callback');
    resetCapture($class);
    check(!$class::settle(1, 'native:name'), 'Post without clean request-end proof remains an orphan, not recovery success.');

    fixture($class);
    CaptureContext::$cli = true;
    $baseline = (int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn();
    $service = new GlpiPlugin\Assetsync20\AssetSyncService();
    for ($job = 0; $job < 40; $job++) {
        $class::withoutOrigin(static function () use ($class, $job): void {
            $item = new Computer(); $class::before($item); modelWrite($class, $item, 'name', 'Worker job ' . $job);
        });
        $waited = false;
        if ($job === 0) {
            $DB->before = static function (string $sql) use (&$waited): void {
                if (!$waited && str_contains($sql, 'SET `evidence`=JSON_SET')) { $waited = true; usleep(120000); }
            };
        }
        if ($class === GlpiPlugin\Assetsync20\FieldEvidence::class) {
            // Exercise the actual A job finally; the model above is a host double, not native qualification.
            $service->processJob(['id' => 0, 'itemtype' => 'Computer', 'items_id' => 1]);
        } else {
            // The actual B executor's rejection finally is also used after valid model commit/rollback.
            GlpiPlugin\Assetsync20agent\TaskExecutor::execute([], []);
        }
        $DB->before = null;
        $head = internal($class, 'head', 1, 'native:name');
        check($job === 0 ? $waited && reset($head['state']['attempts'])['candidate']['recoverable']
            : $head['state']['attempts'] === [] && internal($class, 'head', 1, 'native:comment')['state']['attempts'] === [],
            'Actual job finalizers hand off budget spill once, then drain/recover metadata before later CLI jobs.');
        check((new ReflectionProperty($class, 'owned'))->getValue() === [] && (new ReflectionProperty($class, 'frames'))->getValue() === [],
            'Repeated CLI jobs leak neither owned entries nor pre/post frames.');
    }
    CaptureContext::$cli = false;
    check((int) $DB->sql->pdo->query('SELECT COUNT(*) FROM glpi_logs')->fetchColumn() - $baseline === 40
        && $class::read(1, 'native:name') === null, 'More than 32 jobs in one process neither poison capture nor duplicate History/mint legacy human origin.');
    $class::withoutOrigin(static function () use ($class): void {
        $item = new PluginFieldsComputerExtra(); $class::before($item); modelWrite($class, $item, 'customtext', 'Legacy no parent History', false);
    });
    $class::finishWork();
    check($class::read(1, 'fields:1') === null && internal($class, 'head', 1, 'fields:1')['state']['attempts'] === [],
        'An unknown legacy post can retire unknown without inventing missing History or a human/inherited origin.');

    fixture($class);
    $context = (new ReflectionClass($class))->getConstant('CONTEXT');
    $DB->doQuery('DELETE FROM glpi_configs WHERE context=' . $DB->quote($context));
    $item = new Computer(); $class::before($item);
    check(internal($class, 'marker') === null && (new ReflectionProperty($class, 'owned'))->getValue() === [], 'Pre-upgrade missing protocol marker leaves hooks inert.');
    $class::install(); $class::activate(); save($class, 'name', 'Installed'); $class::install();
    check($class::read(1, 'native:name') === null, 'Repeated supported Update rotates coverage without backfill.');
    check(!array_filter($DB->queries, static fn ($sql) => str_contains($sql, 'UNIX_TIMESTAMP(') && $sql !== 'SELECT UNIX_TIMESTAMP() AS epoch'),
        'Capture UTC is zero-parameter DB epoch, never displayed dates or local timezone conversion.');

    fixture($class);
    for ($i = 0; $i < 32; $i++) { $class::before(new Computer()); }
    try { $class::before(new Computer()); throw new LogicException('Overflow did not cancel.'); } catch (RuntimeException) {}
    $head = internal($class, 'head', 1, 'native:name');
    check(count($head['state']['attempts']) === 32 && $head['state']['overflow'], 'Pending overflow preserves every unfinished token and fails closed.');
    resetCapture($class); // Simulated lost request: a later reader cannot reap foreign orphan tokens.
    check($class::read(1, 'native:name') === null && internal($class, 'head', 1, 'native:name') === $head, 'Crash orphans remain unknown without GET cleanup or expiry promotion.');
}
$lines = []; $status = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' fatal 2>&1', $lines, $status);
check($status !== 0 && in_array('Fatal pending: 1:unknown', $lines, true), 'Actual PHP fatal shutdown leaves candidate pending and unknown.');
$source = file_get_contents(__DIR__ . '/../src/AssetChangeHook.php');
check(strpos($source, 'FieldEvidence::after($item)') < strpos($source, 'self::queueItem($item)', strpos($source, 'function onUpdate')),
    'A facts are captured before queue suppression.');
check(str_contains($source, 'FieldEvidence::withoutOrigin($callback)'), 'A existing internal writes use unknown origin rather than account suppression.');
$source = file_get_contents(__DIR__ . '/../assetsync20agent/src/ChangeHook.php');
check(strpos($source, 'FieldEvidence::after($item)') < strpos($source, 'Settings::transportReady'), 'B facts precede adapter/business transport admission.');
$a = file_get_contents(__DIR__ . '/../src/FieldEvidence.php');
$b = file_get_contents(__DIR__ . '/../assetsync20agent/src/FieldEvidence.php');
check(str_replace(['Assetsync20agent', 'assetsync20agent'], ['Assetsync20', 'assetsync20'], $b) === $a, 'Independently packaged A/B helpers have exact source parity.');
require_once __DIR__ . '/../setup.php';
require_once __DIR__ . '/../assetsync20agent/setup.php';
foreach (['assetsync20', 'assetsync20agent'] as $package) {
    fixture($package === 'assetsync20' ? GlpiPlugin\Assetsync20\FieldEvidence::class : GlpiPlugin\Assetsync20agent\FieldEvidence::class);
    $register = 'plugin_' . $package . '_capture_hooks'; $register();
    check(isset($PLUGIN_HOOKS['pre_item_update'][$package]['PluginFieldsComputerExtra'], $PLUGIN_HOOKS['item_update'][$package]['PluginFieldsComputerExtra']),
        'Post-init discovery registers generated Fields pre/post hooks after its autoloader is available.');
    $setup = file_get_contents(__DIR__ . '/../' . ($package === 'assetsync20' ? '' : 'assetsync20agent/') . 'setup.php');
    check(str_contains($setup, "['post_init']") && str_contains($setup, "= '$register'"), 'Capture discovery is registered with the supported GLPI post-init lifecycle.');
}
$_SERVER['REQUEST_METHOD'] = 'GET';
echo "Field evidence: $checks checks PASS\n";
