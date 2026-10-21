<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    function curl_init(string $url): never { throw new \LogicException('Adapter attempted network in REST records mode.'); }
}
namespace {
    ob_start();
    require __DIR__ . '/agent-native-settings.php';
    ob_end_clean();
    require_once __DIR__ . '/agent-sqlite.php';

    use GlpiPlugin\Assetsync20agent\Settings;
    use GlpiPlugin\Assetsync20agent\Outbox;
    use GlpiPlugin\Assetsync20agent\Worker;
    use GlpiPlugin\Assetsync20agent\TaskJournal;
    use GlpiPlugin\Assetsync20agent\Wake;

    final class BindingSettingsDB
    {
        public AgentSqlite $sql;
        public string $dbdefault = 'binding-test';
        public bool $owned = false;
        public bool $releaseFails = false;
        public ?Closure $beforeQuery = null;
        public array $queries = [];
        public function __construct() { $this->sql = new AgentSqlite(); }
        public function __call(string $name, array $args): mixed { return $this->sql->$name(...$args); }
        public function quote(string $value): string { return $this->sql->quote($value); }
        public function fetchAssoc(PDOStatement $result): ?array { return $this->sql->fetchAssoc($result); }
        public function errno(): int { return 1305; }
        public function doQuery(string $sql): mixed {
            if ($this->beforeQuery !== null) { ($this->beforeQuery)($sql); }
            $this->queries[] = $sql;
            if ($sql === 'SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS id') { return $this->sql->pdo->query('SELECT 1 AS enabled, 17 AS id'); }
            if ($sql === 'SELECT CONNECTION_ID() AS id') { return $this->sql->pdo->query('SELECT 17 AS id'); }
            if (str_starts_with($sql, 'SAVEPOINT as20_rest_')) { return true; }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_rest_')) { return $this->sql->pdo->inTransaction(); }
            if (str_starts_with($sql, 'SELECT COALESCE(IS_USED_LOCK(')) { return $this->sql->pdo->query('SELECT ' . (int) $this->owned . ' AS owned'); }
            if (str_starts_with($sql, 'SELECT GET_LOCK(')) { $this->owned = true; return $this->sql->pdo->query('SELECT 1 AS acquired'); }
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK(')) {
                if (!$this->releaseFails) { $this->owned = false; }
                return $this->sql->pdo->query('SELECT ' . (int) !$this->releaseFails . ' AS released');
            }
            return $this->sql->doQuery($sql);
        }
    }

    function bindingReject(Closure $operation, string $message): void {
        try { $operation(); throw new LogicException($message); } catch (RuntimeException) { setupCheck(true, $message); }
    }
    $DB = new BindingSettingsDB();
    $DB->doQuery('CREATE TABLE test_config(context TEXT,name TEXT,value TEXT,PRIMARY KEY(context,name))');
    $DB->doQuery('CREATE TABLE glpi_profiles_users(users_id INTEGER,profiles_id INTEGER,entities_id INTEGER,is_recursive INTEGER)');
    $DB->sql->insert('glpi_profiles_users', ['users_id' => 7, 'profiles_id' => 4, 'entities_id' => 2, 'is_recursive' => 0]);
    foreach (Config::$values as $context => $values) {
        foreach ($values as $name => $value) { $DB->sql->pdo->prepare('INSERT INTO test_config VALUES(?,?,?)')->execute([$context, $name, $value]); }
    }
    Config::$beforeRead = static function ($context): void {
        global $DB;
        $query = $DB->sql->pdo->prepare('SELECT name,value FROM test_config WHERE context=?'); $query->execute([$context]);
        Config::$values[$context] = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $failKey = '';
    Config::$beforeWrite = static function ($context, $values) use (&$failKey): void {
        global $DB;
        foreach ($values as $name => $value) {
            $DB->sql->pdo->prepare('INSERT INTO test_config VALUES(?,?,?) ON CONFLICT(context,name) DO UPDATE SET value=excluded.value')->execute([$context, $name, $value]);
            if ($name === $failKey) { throw new RuntimeException('Injected partial binding write failure'); }
        }
    };
    $before = Settings::load();
    $rest = array_replace($b, ['transport' => 'rest_records', 'agent_api_url' => 'not-an-adapter-url', 'agent_app_token' => '', 'agent_user_token' => '']);
    Settings::save($rest);
    $saved = Settings::load();
    setupCheck($saved['transport'] === 'rest_records' && !Settings::transportReady($saved), 'REST records needs no outgoing transport readiness.');
    foreach (['installation', 'registration', 'generation', 'connection_id', 'agent_api_url', 'agent_app_token', 'agent_user_token'] as $key) {
        setupCheck($saved[$key] === $before[$key], 'Preserve retained adapter value and binding: ' . $key);
    }
    $DB->doQuery('CREATE TABLE `' . Outbox::TABLE . '` (id INTEGER PRIMARY KEY,evidence TEXT,last_outcome TEXT)');
    $DB->sql->insert(Outbox::TABLE, ['id' => 42, 'evidence' => 'retained']);
    $queued = $DB->sql->firstRow(Outbox::TABLE, ['id' => 42]);
    setupCheck(Outbox::claim($saved) === null && (new Worker())->run() === 0, 'Direct claim and worker cannot drain adapter rows.');
    TaskJournal::cycle($saved, hrtime(true) + 2_000_000_000);
    setupCheck(Worker::send($saved, ['kind' => 'health'], hrtime(true) + 2_000_000_000)['status'] === 0
        && TaskJournal::send($saved, ['kind' => 'pull'], hrtime(true) + 2_000_000_000)['status'] === 0, 'Direct notify and pull entrypoints cannot send.');
    setupCheck($DB->sql->firstRow(Outbox::TABLE, ['id' => 42]) === $queued, 'Pending adapter evidence is exact.');
    setupCheck(Settings::transportReady($before) && Outbox::claim($before) === null, 'Pure readiness still validates captured adapter input, but stale direct claim is fenced.');
    setupCheck(\GlpiPlugin\Assetsync20agent\AgentSession::send($before, 'task', ['kind' => 'pull'], hrtime(true) + 2_000_000_000)['status'] === 0,
        'Stale captured adapter settings cannot reach HTTP send.');
    setupCheck(Worker::send($before, ['kind' => 'health'], hrtime(true) + 2_000_000_000)['status'] === 0
        && TaskJournal::send($before, ['kind' => 'pull'], hrtime(true) + 2_000_000_000)['status'] === 0, 'Stale notify/pull entrypoints reach the fresh fence before HTTP.');
    setupCheck($DB->sql->firstRow(Outbox::TABLE, ['id' => 42]) === $queued, 'Stale probes change no retained row or lease.');
    bindingReject(static fn () => Settings::configureWake(true), 'REST records cannot enable adapter wake.');
    $html = setupRender('b');
    setupCheck(!str_contains($html, 'Optional HTTPS wake') && !str_contains($html, 'A background worker is required')
        && str_contains($html, 'Retained adapter evidence'), 'REST setup hides adapter controls but retains inspectable legacy evidence.');

    $DB->doQuery('START TRANSACTION');
    bindingReject(static fn () => Settings::save($rest), 'Binding save refuses caller-owned transaction.');
    setupCheck($DB->sql->pdo->inTransaction() && !$DB->owned, 'Caller transaction and lock ownership remain untouched.');
    $DB->doQuery('ROLLBACK');
    $DB->owned = true;
    bindingReject(static fn () => Settings::save($rest), 'Binding save refuses reentrant worker lock.');
    setupCheck($DB->owned, 'Refusal never releases the outer lock.');
    $DB->owned = false;
    $before = Settings::load(); $failKey = 'generation';
    bindingReject(static fn () => Settings::save(array_replace($rest, ['registration' => str_repeat('d', 32), 'generation' => str_repeat('e', 32)])), 'Partial multi-key binding failure propagates.');
    $failKey = '';
    setupCheck(Settings::load() === $before && !$DB->sql->pdo->inTransaction() && !$DB->owned, 'Failed binding is rolled back exactly and lock released.');
    Settings::save(array_replace($rest, ['transport' => 'adapter', 'agent_api_url' => $before['agent_api_url']]));
    setupCheck(Settings::transportReady(Settings::load()), 'Explicit adapter restoration retains strong original credential checks.');
    $workerBefore = Config::getConfigurationValues(Settings::CONTEXT);
    $DB->beforeQuery = static function (string $sql): void {
        global $DB;
        if (str_starts_with($sql, 'SELECT GET_LOCK(')) {
            $DB->beforeQuery = null;
            Config::setConfigurationValues(Settings::CONTEXT, ['transport' => 'rest_records']);
        }
    };
    setupCheck((new Worker())->run() === 0, 'Worker rechecks mode after acquiring its lock.');
    setupCheck(Config::getConfigurationValues(Settings::CONTEXT) === array_replace($workerBefore, ['transport' => 'rest_records'])
        && $DB->sql->firstRow(Outbox::TABLE, ['id' => 42]) === $queued && !$DB->owned, 'Mode-save winner prevents cycle metadata, notifications and lease changes.');
    $DB->releaseFails = true;
    bindingReject(static fn () => Settings::save($rest), 'A successful SELECT is not proof of lock release.');
    setupCheck($DB->owned, 'Failed cleanup ownership is not concealed.');
    $DB->releaseFails = false; $DB->owned = false;
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Config::getConfigurationValues(Settings::CONTEXT)));
    // Start with only the installation identity, with no outgoing URL/tokens ever configured.
    $DB->sql->pdo->exec("DELETE FROM test_config WHERE context='plugin:assetsync20agent'");
    Config::setConfigurationValues(Settings::CONTEXT, ['installation' => str_repeat('a', 32)]);
    $fresh = array_diff_key($rest, array_flip(['agent_api_url', 'agent_app_token', 'agent_user_token']));
    Settings::save($fresh);
    setupCheck(Settings::load()['transport'] === 'rest_records' && empty(Settings::load()['agent_api_url']), 'Fresh B REST setup has no outgoing-A credential prerequisite.');
    Config::$beforeRead = Config::$beforeWrite = null;
    echo "REST transport passed: credential-independent setup, adapter fences, owned transaction/lock rejection, multi-key rollback and restoration (SQL-backed Config/native doubles).\n";
}
