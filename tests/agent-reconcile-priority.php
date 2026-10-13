<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    function hrtime(bool $number): int { return \ReconcileFixture::$now; }
    function time(): int { return 1791870528; }

    class TargetScope {
        public const TABLE = 'glpi_plugin_assetsync20agent_targets';
        public static function snapshot(array $target, array $settings): array {
            $id = (int) $target['id'];
            \ReconcileFixture::$attempts[] = $id;
            \ReconcileFixture::$starts[$id][] = \ReconcileFixture::$now;
            \ReconcileFixture::$now += \ReconcileFixture::$durations[$id] ?? 0;
            if (\ReconcileFixture::$duringSnapshot !== null) { (\ReconcileFixture::$duringSnapshot)($target); }
            if (in_array($id, \ReconcileFixture::$snapshotFailures, true)) { throw new \RuntimeException('Snapshot failed.'); }
            return ['fingerprint' => 'snapshot-' . $id, 'lifecycle' => 'present'];
        }
    }
    class Outbox {
        public static function find(int $id, string $type): ?array { return null; }
        public static function observe(int $id, array $snapshot, bool $baseline, ?array $before, string $type): void {
            if (in_array($id, \ReconcileFixture::$observeFailures, true)) { throw new \RuntimeException('Observe failed.'); }
            \ReconcileFixture::$observations[$id] = ['baseline' => $baseline, 'itemtype' => $type];
        }
    }
}

namespace {
    require __DIR__ . '/agent-sqlite.php';
    require __DIR__ . '/../assetsync20agent/src/Settings.php';
    require __DIR__ . '/../assetsync20agent/src/Wake.php';
    require __DIR__ . '/../assetsync20agent/src/Worker.php';

    use GlpiPlugin\Assetsync20agent\Settings;
    use GlpiPlugin\Assetsync20agent\TargetScope;
    use GlpiPlugin\Assetsync20agent\Worker;

    class Config {
        public static array $values = [];
        public static function getConfigurationValues(string $context): array { return self::$values; }
        public static function setConfigurationValues(string $context, array $values): void {
            self::$values = array_replace(self::$values, $values);
        }
    }
    class GLPIKey {
        public function decrypt(string $value): string { return $value; }
    }
    class ReconcileFixture {
        public static int $now = 0;
        public static array $attempts = [];
        public static array $starts = [];
        public static array $durations = [];
        public static array $snapshotFailures = [];
        public static array $observeFailures = [];
        public static array $clearFailures = [];
        public static array $observations = [];
        public static ?Closure $duringSnapshot = null;
        public static int $sweepQueryDuration = 0;
    }
    class ReconcileDB {
        public AgentSqlite $sql;
        public array $queries = [];
        public function __construct() { $this->sql = new AgentSqlite(); }
        public function request(array $query): ArrayIterator {
            $this->queries[] = $query;
            if (!isset($query['WHERE']['dirty'])) { ReconcileFixture::$now += ReconcileFixture::$sweepQueryDuration; }
            return $this->sql->request($query);
        }
        public function update(string $table, array $fields, array $where): bool {
            if (in_array((int) $where['id'], ReconcileFixture::$clearFailures, true)) { return false; }
            return $this->sql->update($table, $fields, $where);
        }
    }

    function reconcileSetup(int $count, int $cursor = 0): void {
        global $DB;
        $DB = new ReconcileDB();
        $DB->sql->doQuery('CREATE TABLE ' . TargetScope::TABLE . ' (id INTEGER PRIMARY KEY, items_id INTEGER, itemtype TEXT,
            installation TEXT, registration TEXT, generation TEXT, connection_id TEXT, active INTEGER, expires INTEGER, dirty INTEGER)');
        Config::$values = ['mode' => 'agent', 'installation' => 'installation', 'registration' => 'registration',
            'generation' => 'generation', 'connection_id' => 'connection', 'target_cursor' => (string) $cursor];
        ReconcileFixture::$now = ReconcileFixture::$sweepQueryDuration = 0;
        ReconcileFixture::$attempts = ReconcileFixture::$starts = ReconcileFixture::$durations = [];
        ReconcileFixture::$snapshotFailures = ReconcileFixture::$observeFailures = ReconcileFixture::$clearFailures = [];
        ReconcileFixture::$observations = [];
        ReconcileFixture::$duringSnapshot = null;
        for ($id = 1; $id <= $count; $id++) { reconcileTarget($id); }
    }
    function reconcileTarget(int $id, array $changes = []): void {
        global $DB;
        $row = array_intersect_key(Config::$values, array_flip(['installation', 'registration', 'generation', 'connection_id']));
        $DB->sql->insert(TargetScope::TABLE, array_replace($row, ['id' => $id, 'items_id' => $id, 'itemtype' => 'Computer',
            'active' => 1, 'expires' => 1791871428, 'dirty' => 0], $changes));
    }
    function reconcileDirty(int $id): int {
        global $DB;
        return (int) $DB->sql->firstRow(TargetScope::TABLE, ['id' => $id])['dirty'];
    }
    function reconcileCheck(bool $condition, string $message): void {
        global $checks;
        $checks++;
        if (!$condition) { throw new RuntimeException($message); }
    }
    $checks = 0;
    $worker = new Worker();

    reconcileSetup(100);
    reconcileTarget(1000, ['dirty' => 1]);
    reconcileCheck($worker->reconcile() === 50 && ReconcileFixture::$attempts === array_merge([1000], range(1, 49)),
        'A high dirty ID precedes the clean sweep under the shared 50-candidate budget.');
    reconcileCheck(Config::$values['target_cursor'] === '49' && $DB->queries[0]['LIMIT'] === 5 && $DB->queries[1]['LIMIT'] === 49,
        'Priority alone cannot advance the sweep cursor; sweep limit uses the remaining budget.');
    reconcileCheck(reconcileDirty(1000) === 0 && !ReconcileFixture::$observations[1000]['baseline']
        && ReconcileFixture::$observations[1]['baseline'], 'Dirty notifications publish changes; clean rows retain baseline semantics.');

    reconcileSetup(120, 60);
    $DB->sql->update(TargetScope::TABLE, ['dirty' => 3], ['id' => 2]);
    reconcileCheck($worker->reconcile() === 50 && ReconcileFixture::$attempts === array_merge([2], range(61, 109))
        && Config::$values['target_cursor'] === '109', 'A dirty ID behind the cursor is attempted before ordinary forward progress.');

    foreach (['snapshotFailures', 'observeFailures', 'clearFailures'] as $failure) {
        reconcileSetup(100);
        for ($id = 1; $id <= 5; $id++) { $DB->sql->update(TargetScope::TABLE, ['dirty' => 1], ['id' => $id]); }
        ReconcileFixture::${$failure} = range(1, 5);
        reconcileCheck($worker->reconcile() === 50 && ReconcileFixture::$attempts === range(1, 45)
            && Config::$values['target_cursor'] === '45' && Config::$values['reconcile_status'] === 'degraded',
            'Failed priority work is not repeated in the sweep and clean rows still progress: ' . $failure);
        for ($id = 1; $id <= 5; $id++) {
            reconcileCheck(reconcileDirty($id) === 1, 'Failed work retains dirtiness: ' . $failure);
        }
    }

    reconcileSetup(100);
    for ($id = 1; $id <= 5; $id++) { $DB->sql->update(TargetScope::TABLE, ['dirty' => 1], ['id' => $id]); }
    ReconcileFixture::$durations = [1 => 600_000_000, 2 => 600_000_000];
    reconcileCheck($worker->reconcile() === 50 && ReconcileFixture::$attempts === range(1, 48)
        && Config::$values['target_cursor'] === '48', 'Priority stops starting work at one second and dirty fallback remains in the sweep.');
    reconcileCheck(ReconcileFixture::$starts[1] === [0] && ReconcileFixture::$starts[2] === [600_000_000]
        && ReconcileFixture::$starts[3] === [1_200_000_000], 'Overlapping rows have one snapshot attempt; later dirty work uses the sweep budget.');

    reconcileSetup(100, 25);
    $DB->sql->update(TargetScope::TABLE, ['dirty' => 1], ['id' => 2]);
    ReconcileFixture::$durations = [2 => 2_100_000_000];
    reconcileCheck($worker->reconcile() === 1 && Config::$values['target_cursor'] === '25' && count($DB->queries) === 1,
        'A priority snapshot exhausting the deadline leaves the saved sweep cursor untouched.');

    reconcileSetup(20);
    ReconcileFixture::$durations = array_fill_keys(range(1, 20), 600_000_000);
    reconcileCheck($worker->reconcile() === 4 && ReconcileFixture::$attempts === range(1, 4)
        && Config::$values['target_cursor'] === '4', 'A deadline-interrupted short page keeps its last consumed cursor.');
    ReconcileFixture::$durations = [];
    ReconcileFixture::$attempts = [];
    reconcileCheck($worker->reconcile() === 16 && ReconcileFixture::$attempts === range(5, 20)
        && Config::$values['target_cursor'] === '0', 'The next cycle resumes the partial page and resets only after actual exhaustion.');

    reconcileSetup(20, 8);
    ReconcileFixture::$sweepQueryDuration = 2_000_000_000;
    reconcileCheck($worker->reconcile() === 0 && ReconcileFixture::$attempts === [] && Config::$values['target_cursor'] === '8',
        'A query consuming the clock budget cannot reset an unprocessed short page.');

    reconcileSetup(55, 10);
    for ($id = 1; $id <= 5; $id++) { $DB->sql->update(TargetScope::TABLE, ['dirty' => 1], ['id' => $id]); }
    reconcileCheck($worker->reconcile() === 50 && Config::$values['target_cursor'] === '55',
        'A full remaining-budget page preserves the cursor even when it reaches the current final ID.');
    reconcileCheck($worker->reconcile() === 0 && Config::$values['target_cursor'] === '0',
        'An empty exhausted sweep resets the cursor on the next cycle.');

    reconcileSetup(15, 10);
    $DB->sql->update(TargetScope::TABLE, ['dirty' => 1], ['id' => 2]);
    reconcileCheck($worker->reconcile() === 6 && Config::$values['target_cursor'] === '0',
        'A fully consumed result below the query limit resets the sweep cursor.');

    reconcileSetup(2);
    $DB->sql->update(TargetScope::TABLE, ['dirty' => 1], ['id' => 1]);
    ReconcileFixture::$duringSnapshot = static function (array $target) use ($DB): void {
        $id = (int) $target['id'];
        $DB->sql->update(TargetScope::TABLE, ['dirty' => (int) $target['dirty'] + 1], ['id' => $id]);
    };
    reconcileCheck($worker->reconcile() === 3 && ReconcileFixture::$attempts === [1, 2]
        && reconcileDirty(1) === 2 && reconcileDirty(2) === 1, 'Concurrent increments survive both passes, including clean-to-dirty changes.');
    reconcileCheck(Config::$values['reconcile_status'] === 'ok', 'A successful conditional clear with a newer increment is not an error.');
    ReconcileFixture::$duringSnapshot = null;
    ReconcileFixture::$attempts = [];
    reconcileCheck($worker->reconcile() === 4 && ReconcileFixture::$attempts === [1, 2]
        && reconcileDirty(1) === 0 && reconcileDirty(2) === 0, 'Retained increments receive one fresh priority attempt next cycle.');

    reconcileSetup(0);
    $excluded = ['installation' => 'other', 'registration' => 'other', 'generation' => 'other', 'connection_id' => 'other',
        'active' => 0, 'expires' => 1791870528];
    $id = 1;
    foreach ($excluded as $key => $value) { reconcileTarget($id++, [$key => $value, 'dirty' => 1]); }
    reconcileTarget(7, ['itemtype' => 'Printer', 'dirty' => 1]);
    reconcileTarget(8, ['expires' => 1791870527]);
    reconcileTarget(9);
    reconcileCheck($worker->reconcile() === 3 && ReconcileFixture::$attempts === [7, 9],
        'Both passes exclude other bindings, inactive subscriptions, and expired or exactly-expiring subscriptions.');
    reconcileCheck(ReconcileFixture::$observations[7] === ['baseline' => false, 'itemtype' => 'Printer']
        && ReconcileFixture::$observations[9]['baseline'], 'Typed notifications and clean baseline behavior are preserved.');
    for ($id = 1; $id <= 6; $id++) { reconcileCheck(reconcileDirty($id) === 1, 'Excluded dirtiness stays untouched.'); }
    reconcileCheck(Config::$values['reconcile_status'] === 'ok' && Config::$values['target_cursor'] === '0',
        'A healthy exhausted scoped sweep reports ok and resets its cursor.');

    echo "Agent reconciliation priority tests passed ($checks checks; simulated clocks, SQLite selection, no transport).\n";
}
