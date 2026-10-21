<?php
declare(strict_types=1);

namespace Glpi\Security {
    final class TOTPManager {
        public const ENFORCEMENT_OPTIONAL = 0;
        public function is2FAEnabled(int $id): bool { return false; }
        public function get2FAEnforcement(int $id): int { return 0; }
    }
}
namespace GlpiPlugin\Assetsync20agent {
    function hrtime(bool $asNumber = false): int { return \BatchModelClock::$now; }
    function time(): int { return \BatchModelClock::$wall ?: \time(); }
    final class RestPeer {
        public static bool $idle = true;
        public static function deadline(): int { return \BatchModelClock::$deadline; }
        public static function work(array $body): array { return Settings::load(); }
        public static function settings(): array { return Settings::load(); }
        public static function binding(array $settings): string { return str_repeat('b', 64); }
        public static function policy(array $settings): void {}
        public static function grant(array $settings, int $entity): void {}
        public static function entity(array $settings, int $entity): void {}
        public static function idle(): bool { return self::$idle; }
        public static function read(array $body): array {
            $settings = Settings::load();
            if (!\Profile_User::$granted) { throw new \RuntimeException('Fixture peer grant denied.'); }
            if ($body['items_id'] > 0 && !(new \Computer())->can($body['items_id'], READ)) { throw new \RuntimeException('Fixture peer READ denied.'); }
            return $settings;
        }
        public static function canView(): bool { return true; }
    }
}
namespace {
    final class BatchModelClock { public static int $now = 1_000_000_000; public static int $deadline = 6_000_000_000; public static int $wall = 0; }
    class CommonDBTM {
        public array $fields = [];
        public static function getTable(): string { return str_ends_with(static::class, 'RestWork') ? 'rest_work' : 'rest_settings'; }
        public static function unsetUndisclosedFields(&$fields) {}
        public function getID(): int { return (int) $this->fields['id']; }
        public function getFromDB($id) { global $DB; $this->fields = $DB->firstRow(static::getTable(), ['id' => $id]) ?? []; return $this->fields !== []; }
        public function add(array $input, $options = [], $history = true) {
            global $DB;
            $DB->insert(static::getTable(), $input);
            $id = (int) $DB->sql->pdo->lastInsertId();
            $this->getFromDB($id);
            if (method_exists($this, 'post_addItem')) { $this->post_addItem(); }
            return $id;
        }
    }
    require __DIR__ . '/agent-scalar-fixtures.php';
    use GlpiPlugin\Assetsync20agent\RestSettings;
    use GlpiPlugin\Assetsync20agent\RestWork;
    use GlpiPlugin\Assetsync20agent\TaskExecutor;
    use GlpiPlugin\Assetsync20agent\TaskJournal;

    final class BatchModelDB extends ScalarDB {
        public string $dbdefault = 'batch_test';
        public bool $crashAfterIntent = false;
        public function doQuery(string $sql): mixed {
            $sql = str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            if (str_starts_with($sql, 'SHOW INDEX FROM `rest_work`')) {
                return $this->sql->pdo->query("SELECT 'binding' AS Column_name FROM sqlite_master WHERE name = 'rest_batch'");
            }
            if (str_starts_with($sql, 'ALTER TABLE `rest_work` ADD KEY')) { return $this->sql->pdo->exec('CREATE INDEX rest_batch ON rest_work(binding,batch_id,entities_id)') !== false; }
            if ($sql === 'SELECT @@SESSION.innodb_lock_wait_timeout AS seconds') { return $this->sql->pdo->query('SELECT 50 AS seconds'); }
            if (str_starts_with($sql, 'SET SESSION innodb_lock_wait_timeout')) { return true; }
            if ($sql === 'COMMIT' && $this->crashAfterIntent) {
                $result = parent::doQuery($sql); $this->crashAfterIntent = false;
                throw new RuntimeException('Injected process loss after durable executing intent');
            }
            return parent::doQuery($sql);
        }
    }
    function modelCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
    function modelSetup(): array {
        global $DB;
        $settings = scalarSetup(); $old = $DB; $DB = new BatchModelDB(); $DB->sql = $old->sql;
        User::$httpFields = ['2fa' => null, '2fa_unenforced' => 0]; $GLOBALS['CFG_GLPI']['2fa_enforced'] = 0;
        BatchModelClock::$now = 1_000_000_000; BatchModelClock::$deadline = 6_000_000_000; BatchModelClock::$wall = 0;
        $DB->doQuery("ALTER TABLE glpi_computers ADD otherserial TEXT DEFAULT ''");
        $DB->doQuery('ALTER TABLE glpi_computers ADD date_mod TEXT');
        $DB->doQuery('ALTER TABLE glpi_logs ADD date_mod TEXT');
        modelCheck(TaskJournal::install() && RestSettings::install() && RestWork::install() && RestWork::install(), 'Additive migration repeats safely.');
        $document = ['version' => 1, 'id' => str_repeat('d', 32), 'installation' => $settings['installation'],
            'registration' => $settings['registration'], 'generation' => $settings['generation'], 'connection_id' => 'b',
            'route_id' => 'route', 'source_entity' => 1, 'target_entity' => 100, 'itemtype' => 'Computer',
            'mappings' => [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']]];
        $DB->insert(RestSettings::getTable(), ['settings_id' => $document['id'], 'hash' => TaskExecutor::hash($document),
            'body' => json_encode($document), 'binding' => str_repeat('b', 64), 'connection_id' => 'b',
            'registration' => $settings['registration'], 'generation' => $settings['generation'], 'installation' => $settings['installation'], 'entities_id' => 100]);
        $body = ['version' => 8, 'id' => str_repeat('e', 32), 'batch_id' => str_repeat('f', 32),
            'installation' => $settings['installation'], 'registration' => $settings['registration'], 'generation' => $settings['generation'],
            'connection_id' => 'b', 'settings_id' => $document['id'], 'settings_hash' => TaskExecutor::hash($document),
            'pair_id' => str_repeat('1', 32), 'itemtype' => 'Computer', 'items_id' => 201, 'entity_id' => 100,
            'identity' => ['serial' => 'SCALAR-1'], 'name' => 'Batched Name', 'source_hash' => str_repeat('c', 64), 'expires' => time() + 300];
        $_GET = ['get_hateoas' => '0'];
        return [$settings, $body];
    }
    function modelPost(array $body): int {
        $method = $_SERVER['REQUEST_METHOD'] ?? null; $query = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = [];
        try { return (new RestWork())->add(['work_id' => $body['id'], 'hash' => TaskExecutor::hash($body), 'body' => json_encode($body)]); }
        finally { $_GET = $query; if ($method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $method; } }
    }
    function modelRow(array $body): array { global $DB; return $DB->firstRow(RestWork::getTable(), ['work_id' => $body['id']]) ?? []; }

    [$settings, $body] = modelSetup();
    $id = modelPost($body);
    $row = modelRow($body); $result = json_decode($row['result'], true);
    modelCheck($row['state'] === 'verified' && $result['timing']['within_deadline'] && $result['timing']['started_at'] <= $result['timing']['finished_at'], 'Projection stores verified result and timing evidence.');
    modelCheck($DB->firstRow('glpi_computers', ['id' => 201])['name'] === $body['name'], 'Actual model fixture saved native Name.');
    $journal = $DB->firstRow(TaskJournal::TABLE, ['id' => $body['id']]);
    modelCheck($journal['report_status'] === 'rest' && $journal['result'] === $row['result'], 'Journal and projection expose identical durable results, outside legacy reporting.');
    $history = iterator_to_array($DB->request(['FROM' => 'glpi_logs']));
    $before = $row;
    modelCheck(modelPost($body) === $id && modelRow($body) === $before && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === $history, 'Terminal identical POST does not mutate projection, journal, model or History.');
    $work = new RestWork(); $work->getFromDB($id);
    modelCheck($work->fields === $before && modelRow($body) === $before, 'v8 GET returns SQL-visible fields without writes.');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];
    try { $work->getFromDB($id); throw new LogicException('Unbounded v8 GET options allowed'); } catch (RuntimeException) {}
    $_GET = ['get_hateoas' => '0']; $work->getFromDB($id); unset($_SERVER['REQUEST_METHOD']);
    $collision = $body; $collision['name'] = 'Collision';
    try { modelPost($collision); throw new LogicException('Collision accepted'); } catch (RuntimeException) {}
    modelCheck(modelRow($body) === $before, 'Same-ID changed body cannot replace retained work.');

    [$settings, $body] = modelSetup();
    Computer::$allowUpdate = false;
    try { modelPost($body); throw new LogicException('Denied writer succeeded'); } catch (RuntimeException) {}
    $row = modelRow($body);
    modelCheck($row['state'] === 'accepted' && $row['result'] === '{}' && $row['task_body'] === '{}', 'Service denial retains unstarted durable acceptance.');
    $work = new RestWork(); $work->getFromDB($row['id']);
    modelCheck(modelRow($body) === $row, 'Accepted GET never compiles or executes even when read repeatedly.');
    Computer::$allowUpdate = true;
    modelPost($body);
    modelCheck(modelRow($body)['state'] === 'verified', 'Restored writer continues original ID and immutable work.');

    [$settings, $body] = modelSetup();
    $DB->sql->beforeQuery = static function ($sql): void {
        if (str_starts_with($sql, 'INSERT INTO `glpi_logs`')) { BatchModelClock::$now += 6_000_000_000; }
    };
    modelPost($body);
    modelCheck(modelRow($body)['state'] === 'uncertain' && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original'
        && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Model overrun rolls back model/History and publishes uncertainty, never false verified.');
    $DB->sql->beforeQuery = null;
    BatchModelClock::$now = 8_000_000_000; BatchModelClock::$deadline = 13_000_000_000;
    $frozen = modelRow($body); modelPost($body);
    modelCheck(modelRow($body) === $frozen, 'Uncertain terminal replay never reruns mutation.');
    $later = $body; $later['id'] = str_repeat('2', 32); BatchModelClock::$now = BatchModelClock::$deadline;
    try { modelPost($later); throw new LogicException('Late acceptance succeeded'); } catch (RuntimeException) {}
    modelCheck(modelRow($later) === [], 'Shared request deadline may reject later rows without inventing acceptance.');

    [$settings, $body] = modelSetup();
    $DB->crashAfterIntent = true;
    try { modelPost($body); throw new LogicException('Crash injection did not fire'); } catch (RuntimeException | PDOException) {}
    modelCheck(modelRow($body)['state'] === 'executing' && modelRow($body)['result'] === '{}', 'Crash retains executing projection with empty durable result.');
    BatchModelClock::$wall = $body['expires'] + 1;
    $work = new RestWork(); $before = modelRow($body); $work->getFromDB($before['id']);
    modelCheck(modelRow($body) === $before, 'Expired orphan GET remains read-only.');
    modelPost($body);
    modelCheck(modelRow($body)['state'] === 'uncertain' && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original'
        && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Identical POST reconciles expired executing intent without model replay.');

    [$settings, $body] = modelSetup();
    $DB->sql->failOn = "`state`='verified',`result`=";
    modelPost($body);
    $DB->sql->failOn = null;
    modelCheck(modelRow($body)['state'] === 'uncertain' && $DB->firstRow('glpi_computers', ['id' => 201])['name'] === 'Original'
        && iterator_to_array($DB->request(['FROM' => 'glpi_logs'])) === [], 'Projection/journal failure cannot commit model/History or fabricate success.');

    [$settings, $body] = modelSetup();
    $legacy = ['work_id' => str_repeat('3', 32), 'hash' => str_repeat('a', 64), 'body' => '{"version":7}', 'binding' => str_repeat('b', 64),
        'connection_id' => 'b', 'registration' => $settings['registration'], 'generation' => $settings['generation'], 'installation' => $settings['installation'],
        'entities_id' => 100, 'accepted_at' => time(), 'task_body' => '{}', 'execution_settings' => '{}'];
    $DB->doQuery('DROP INDEX rest_batch');
    foreach (['batch_id', 'state', 'result', 'expires'] as $column) { $DB->doQuery('ALTER TABLE rest_work DROP COLUMN ' . $column); }
    $DB->insert(RestWork::getTable(), $legacy);
    $original = $DB->firstRow(RestWork::getTable(), ['work_id' => $legacy['work_id']]);
    RestWork::install();
    $oldRow = $DB->firstRow(RestWork::getTable(), ['work_id' => $legacy['work_id']]);
    modelCheck(array_intersect_key($oldRow, $original) === $original, 'Migration from actual old column layout preserves every original value.');
    modelCheck($oldRow['batch_id'] === null && $oldRow['state'] === null && $oldRow['result'] === null && $oldRow['expires'] === null, 'Existing-version inserts keep nullable v8 columns.');
    RestWork::install();
    modelCheck($DB->firstRow(RestWork::getTable(), ['work_id' => $legacy['work_id']]) === $oldRow, 'Repeated migration preserves original legacy columns byte-for-byte.');
    $public = $oldRow; RestWork::unsetUndisclosedFields($public);
    modelCheck(!isset($public['batch_id']) && !array_key_exists('state', $public) && isset($public['task_body']), 'Legacy collection shape retains compiler fields and omits added NULL projection fields.');
    $public = array_replace($oldRow, ['state' => 'verified', 'result' => '{"outcome":"verified"}']); RestWork::unsetUndisclosedFields($public);
    modelCheck($public['state'] === 'verified' && $public['result'] !== '{}', 'Legacy virtual state/result remain visible.');
    echo "REST v8 B model passed: projection/journal/model atomicity, deadline overrun, continuation/replay, read-only GET and additive migration (SQLite/native model doubles).\n";
}
