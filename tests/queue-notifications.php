<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetChangeHook;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;

$checks = 0;
function notificationCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

function notificationRow(): array
{
    return $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0];
}

function notificationSetup(): array
{
    $GLOBALS['DB'] = new FakeDB();
    Config::$values = Config::$reads = [];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    PluginFieldsContainer::$options = [];
    GlpiBConnection::save(['id' => 'a', 'active' => true]);
    EntitySyncRoute::save(['id' => 'route', 'glpi_b_connection_id' => 'a',
        'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '100', 'asset_types' => ['Computer'], 'active' => true]);
    \saveTestMappings('a', 'Computer', ['name' => ['glpi_b_field_key' => 'name', 'source_of_truth' => 'glpi_a']]);
    notificationCheck(FieldMapping::syncMappings('a', 'Computer') === [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']], 'Fixture must independently prove its native mapping.');
    $GLOBALS['DB']->insert('glpi_entities', ['id' => 1, 'entities_id' => 0]);
    $GLOBALS['DB']->insert('glpi_computers', ['id' => 101, 'entities_id' => 1, 'is_deleted' => 0,
        'name' => 'First edit', 'serial' => 'NOTIFY-101', 'date_mod' => '2026-01-01 00:00:00']);
    AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'a',
        'route_id' => 'route', 'remote_items_id' => 201, 'status' => AssetSyncLink::STATUS_SYNCED]);
    $remote = new FakeGlpiBClient();
    $remote->records['a:Computer'][201] = ['id' => 201, 'name' => 'Remote', 'serial' => 'NOTIFY-101', 'entities_id' => 100];
    return [new AssetSyncService($remote), $remote];
}

function notificationHook(): void
{
    $item = new Computer();
    $item->fields = $GLOBALS['DB']->tables['glpi_computers'][0];
    AssetChangeHook::onUpdate($item);
}

// Migration is additive, preserves populated rows, and prevents cron registration on failure.
$GLOBALS['DB'] = new FakeDB();
notificationCheck(AssetSyncQueue::install() && $GLOBALS['DB']->fieldExists(AssetSyncQueue::TABLE, 'needs_recheck'), 'Fresh installation must include the flag.');
notificationCheck(str_contains($GLOBALS['DB']->sqlQueries[0], '`needs_recheck` TINYINT NOT NULL DEFAULT 0'), 'Fresh schema must use one default-zero nonnullable additive flag.');
$GLOBALS['DB']->sqlQueries = [];
notificationCheck(AssetSyncQueue::install() && $GLOBALS['DB']->sqlQueries === [], 'Repeat installation must issue no DDL.');
foreach (['pending', 'running', 'retry', 'done', 'blocked'] as $index => $status) {
    $GLOBALS['DB']->insert(AssetSyncQueue::TABLE, ['itemtype' => 'Computer', 'items_id' => 101 + $index,
        'glpi_b_connection_id' => 'a', 'route_id' => 'old-route', 'payload_hash' => 'old-hash', 'remote_items_id' => 201,
        'status' => $status, 'attempts' => 4, 'payload_date' => '2026-01-01 00:00:00',
        'available_at' => '2026-01-02 00:00:00', 'started_at' => '2026-01-03 00:00:00',
        'finished_at' => '2026-01-04 00:00:00', 'date_mod' => '2026-01-05 00:00:00', 'last_error' => 'retained']);
}
$GLOBALS['DB']->columns[AssetSyncQueue::TABLE] = ['id'];
foreach ($GLOBALS['DB']->tables[AssetSyncQueue::TABLE] as &$row) { unset($row['needs_recheck']); }
unset($row);
$oldRows = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
notificationCheck(AssetSyncQueue::install(), 'Populated legacy table must migrate.');
foreach ($oldRows as $index => $row) {
    notificationCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE][$index] === $row + ['needs_recheck' => 0], 'Migration must preserve all fields and times.');
}
foreach (['false', 'throw'] as $failure) {
    $GLOBALS['DB']->columns[AssetSyncQueue::TABLE] = ['id'];
    $GLOBALS['DB']->queryResult = static function (string $sql) use ($failure) {
        if (!str_starts_with($sql, 'ALTER TABLE')) { return null; }
        if ($failure === 'throw') { throw new RuntimeException('migration unavailable'); }
        return false;
    };
    CronTask::$registered = [];
    $beforeFailure = $GLOBALS['DB']->tables[AssetSyncQueue::TABLE];
    notificationCheck(!plugin_assetsync20_install() && CronTask::$registered === [], 'Failed additive migration must return false before cron registration.');
    notificationCheck($GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === $beforeFailure, 'Failed migration must preserve populated fields and times.');
}
$GLOBALS['DB']->queryResult = null;
notificationCheck(AssetSyncQueue::install(), 'Migration must remain retryable after failure.');

foreach (['pending', 'running', 'retry', 'done', 'blocked'] as $status) {
    notificationSetup();
    AssetSyncQueue::enqueue('Computer', 101, 'a', 'prepared', 'hash', '2026-01-01 00:00:00', 201);
    $GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['status' => $status, 'attempts' => 7,
        'available_at' => '2026-01-02 00:00:00', 'started_at' => '2026-01-03 00:00:00',
        'finished_at' => '2026-01-04 00:00:00', 'date_mod' => '2026-01-05 00:00:00'], ['id' => 1]);
    $before = notificationRow();
    notificationCheck(AssetSyncQueue::notify('Computer', 101, 'a'), 'Existing notification must succeed.');
    $after = notificationRow();
    $expected = $before;
    $expected['needs_recheck'] = 1;
    if (!in_array($status, ['running', 'retry'], true)) { $expected['date_mod'] = $after['date_mod']; }
    notificationCheck($after === $expected, 'Notify must only touch dirty/date_mod, preserving lease/retry/preparation/remote ID: ' . $status);
    notificationCheck(AssetSyncQueue::notify('Computer', 101, 'a') && count($GLOBALS['DB']->tables[AssetSyncQueue::TABLE]) === 1, 'A repeated/no-op notification is idempotent success.');
    $sql = array_values(array_filter($GLOBALS['DB']->sqlQueries, static fn ($sql) => str_starts_with($sql, 'INSERT INTO')))[0];
    notificationCheck(explode('ON DUPLICATE KEY UPDATE ', $sql)[1] === "`needs_recheck` = 1, `date_mod` = CASE WHEN `status` IN ('running', 'retry') THEN `date_mod` ELSE NOW() END", 'Atomic duplicate-key update must contain only the approved notification fields.');
}
notificationSetup();
notificationCheck(AssetSyncQueue::notify('Computer', 101, 'a', false) && $GLOBALS['DB']->tables[AssetSyncQueue::TABLE] === [], 'Existing-only no-op must not insert.');
notificationCheck(AssetSyncQueue::notify('Computer', 101, "quoted'id") && notificationRow()['glpi_b_connection_id'] === "quoted'id", 'Notification identity must be SQL quoted.');

foreach (['done', 'blocked'] as $terminal) {
    notificationSetup();
    AssetSyncQueue::notify('Computer', 101, 'a');
    $GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['status' => $terminal, 'attempts' => 9], ['id' => 1]);
    notificationCheck(AssetSyncQueue::dueConnectionIds() === ['a'], 'Dirty terminal jobs must appear in the fair due list.');
    $job = AssetSyncQueue::claimDue(1)[0];
    notificationCheck($job['attempts'] === 1 && $job['previous_status'] === $terminal && $job['previous_needs_recheck'] === 1 && notificationRow()['needs_recheck'] === 0, 'Terminal claim must reset attempt1 and clear dirty before fresh reads.');
    notificationCheck($job['started_epoch'] > 0 && AssetSyncQueue::owns($job), 'Claim must reread a trusted DB epoch owner fence.');
    AssetSyncQueue::notify('Computer', 101, 'a');
    notificationCheck(AssetSyncQueue::claimDue(1) === [], 'Dirty cannot bypass an active running lease.');
    notificationCheck(AssetSyncQueue::finish($job) && notificationRow()['needs_recheck'] === 1, 'Completion must not clear a notification received during processing.');
    notificationCheck(AssetSyncQueue::dueConnectionIds() === ['a'], 'Dirty completed jobs must remain eligible for the next turn/drain.');
    $next = AssetSyncQueue::claimDue(1)[0];
    notificationCheck($next['attempts'] === 1 && $next['previous_status'] === 'done', 'A new terminal turn must reset attempts.');
}

foreach (['UTC', '+08:00', 'America/New_York'] as $timezone) {
    notificationSetup();
    $GLOBALS['DB']->timezone = $timezone;
    AssetSyncQueue::notify('Computer', 101, 'a');
    $job = AssetSyncQueue::claimDue(1)[0];
    $epoch = strtotime('2026-11-01 06:00:30 UTC');
    notificationCheck(abs($job['started_epoch'] - time()) <= 2, 'Claim start epoch must not shift with DB display timezone.');
    notificationCheck(AssetSyncQueue::savePrepared($job, 'route', 'fresh-hash', $epoch), 'Preparation must accept the current UTC owner fence.');
    $prepared = notificationRow();
    notificationCheck(AssetSyncQueue::savePrepared($job, 'route', 'fresh-hash', $epoch) && notificationRow() === $prepared, 'Matching no-op preparation must succeed after owner/value reread.');
    notificationCheck(AssetSyncQueue::savePrepared($job, 'route', 'fresh-hash', null) && notificationRow()['payload_date'] === null, 'Missing payload epoch must remain NULL.');
    $stale = $job;
    $stale['attempts']++;
    notificationCheck(!AssetSyncQueue::savePrepared($stale, 'bad', 'bad', $epoch) && !AssetSyncQueue::finish($stale)
        && !AssetSyncQueue::retry($stale, 'stale') && !AssetSyncQueue::block($stale, 'stale'), 'Wrong attempt must reject preparation and every completion.');
    $stale = $job;
    $stale['started_epoch']++;
    notificationCheck(!AssetSyncQueue::owns($stale) && !AssetSyncQueue::savePrepared($stale, 'bad', 'bad', $epoch)
        && !AssetSyncQueue::finish($stale) && !AssetSyncQueue::retry($stale, 'stale') && !AssetSyncQueue::block($stale, 'stale'), 'Wrong epoch must reject all owner operations.');
    notificationCheck($GLOBALS['DB']->timezone === $timezone, 'Owner/preparation operations must restore DB timezone.');
    $GLOBALS['DB']->sqlQueries = [];
    AssetSyncQueue::notify('Computer', 101, 'a');
    notificationCheck(AssetSyncQueue::retry($job, 'first503') && notificationRow()['needs_recheck'] === 1, 'Retry must preserve new dirty work.');
    $retry = notificationRow();
    AssetSyncQueue::notify('Computer', 101, 'a');
    notificationCheck(notificationRow() === $retry && AssetSyncQueue::claimDue(1) === [], 'Dirty retry must keep its timestamps and backoff.');
    $GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['finished_at' => (new DateTimeImmutable('-61 seconds', new DateTimeZone($timezone)))->format('Y-m-d H:i:s')], ['id' => 1]);
    $retried = AssetSyncQueue::claimDue(1)[0];
    notificationCheck($retried['attempts'] === 2 && $retried['previous_status'] === 'retry', 'Due retry must increment normally despite dirty bit.');
    AssetSyncQueue::notify('Computer', 101, 'a');
    notificationCheck(AssetSyncQueue::block($retried, 'blocked') && notificationRow()['needs_recheck'] === 1, 'Block must preserve dirty events.');
    notificationCheck(AssetSyncQueue::claimDue(1)[0]['attempts'] === 1, 'A newly dirty block must return to attempt1.');
    notificationCheck(count(array_filter($GLOBALS['DB']->sqlQueries, static fn ($sql) => str_contains($sql, 'CREATE TABLE') || str_contains($sql, 'ALTER TABLE'))) === 0, 'Runtime queue operations must never issue DDL.');
}

// Independently execute the actual fence expression across the repeated DST hour.
if (extension_loaded('pdo_sqlite')) {
    $sql = new PDO('sqlite::memory:');
    $sql->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($value) => $value, 1);
    $sql->exec('CREATE TABLE owners (id INTEGER, status TEXT, attempts INTEGER, started_at INTEGER)');
    $earlier = strtotime('2026-11-01 01:25:00 -04:00');
    $later = strtotime('2026-11-01 01:25:00 -05:00');
    $insert = $sql->prepare('INSERT INTO owners VALUES (1, ?, ?, ?)');
    $insert->execute(['running', 1, $earlier]);
    $insert->execute(['running', 1, $later]);
    $insert->execute(['running', 2, $later]);
    $insert->execute(['done', 1, $later]);
    $fence = (new ReflectionMethod(AssetSyncQueue::class, 'ownerWhere'))->invoke(null,
        ['id' => 1, 'status' => 'running', 'attempts' => 1, 'started_epoch' => $later]);
    $selected = $sql->query("SELECT started_at FROM owners WHERE id = 1 AND status = 'running' AND attempts = 1 AND " . $fence[0]->expression)->fetchAll(PDO::FETCH_COLUMN);
    notificationCheck(array_map('intval', $selected) === [$later] && $later - $earlier === 3600, 'Epoch fence SQL must distinguish repeated local times and reject wrong status/attempt.');
}

// A notification before claim selection is consumed only before a fresh source read.
[$service, $remote] = notificationSetup();
notificationHook();
$GLOBALS['DB']->update('glpi_computers', ['name' => 'Before claim edit'], ['id' => 101]);
notificationHook();
notificationCheck($service->processQueue(1) === 1 && $remote->records['a:Computer'][201]['name'] === 'Before claim edit', 'Worker must prepare the latest edit, never the notification payload.');
notificationCheck(notificationRow()['status'] === 'done' && notificationRow()['needs_recheck'] === 0, 'Preclaim events must finish cleanly after a fresh read.');
$requests = count($remote->requests);
notificationHook();
notificationCheck($service->processQueue(1) === 1 && count($remote->requests) === $requests, 'Unchanged terminal notification must use existing hash gates and zero HTTP.');

// Capture a source edit after the worker read but before HTTP completion.
[$unused, $remote] = notificationSetup();
$client = new class($remote) {
    public ?Closure $duringRead = null;
    public function __construct(public FakeGlpiBClient $delegate) {}
    public function nativeMappingContext(...$arguments): array { return $this->delegate->nativeMappingContext(...$arguments); }
    public function getItem(array $connection, string $type, int $id, bool $timezone = true): array
    {
        if ($this->duringRead !== null) { ($this->duringRead)(); }
        return $this->delegate->getItem($connection, $type, $id, $timezone);
    }
    public function updateItem(array $connection, string $type, int $id, array $input): array
    {
        return $this->delegate->updateItem($connection, $type, $id, $input);
    }
};
$service = new AssetSyncService($client);
notificationHook();
$client->duringRead = static function () use ($client): void {
    $client->duringRead = null;
    $GLOBALS['DB']->update('glpi_computers', ['name' => 'After fresh read', 'date_mod' => '2026-01-02 00:00:00'], ['id' => 101]);
    notificationHook();
};
$service->processQueue(1);
notificationCheck($remote->records['a:Computer'][201]['name'] === 'First edit' && notificationRow()['needs_recheck'] === 1 && notificationRow()['status'] === 'done', 'A later source edit must not be lost when the older snapshot completes.');
notificationCheck($service->processQueue(1) === 1 && $remote->records['a:Computer'][201]['name'] === 'After fresh read' && notificationRow()['needs_recheck'] === 0, 'The next fair turn must deliver the deferred edit.');
$GLOBALS['DB']->update('glpi_computers', ['name' => 'After finish'], ['id' => 101]);
notificationHook();
notificationCheck($service->processQueue(1) === 1 && $remote->records['a:Computer'][201]['name'] === 'After finish', 'A postcompletion edit must make done eligible again.');

// Persist fresh preparation even on the first transport failure; unchanged backfill must not reset it.
foreach ([503, 403] as $status) {
    notificationSetup();
    $remote = new class($status) {
        public int $calls = 0;
        public function __construct(private int $status) {}
        public function nativeMappingContext(...$arguments): array { return (new FakeGlpiBClient())->nativeMappingContext(...$arguments); }
        public function getItem(array $connection, string $type, int $id, bool $timezone): array
        {
            $this->calls++;
            notificationCheck(notificationRow()['route_id'] === 'route' && strlen(notificationRow()['payload_hash']) === 64, 'Fresh preparation must be durable before the very first HTTP attempt.');
            notificationHook();
            return ['success' => false, 'transient' => $this->status === 503, 'status_code' => $this->status, 'message' => 'request failed'];
        }
    };
    $service = new AssetSyncService($remote);
    notificationHook();
    $service->processQueue(1);
    $failed = notificationRow();
    notificationCheck($failed['status'] === ($status === 503 ? 'retry' : 'blocked') && $failed['needs_recheck'] === 1, 'Failure completion must preserve notifications and classification.');
    notificationCheck(!$service->queueAssetIfNeeded('Computer', 101, 'a') && notificationRow() === $failed, 'Unchanged backfill must leave retry/block preparation, dirty bit and timestamps intact.');
    if ($status === 503) {
        notificationCheck($service->processQueue(1) === 0 && $remote->calls === 1, 'Dirty cannot bypass transport retry backoff.');
    } else {
        notificationCheck(AssetSyncLink::find('Computer', 101, 'a')['last_payload_hash'] === $failed['payload_hash'], 'Blocked links must use the fresh in-memory preparation, not the blank notification hash.');
        notificationCheck($service->processQueue(1) === 1 && $remote->calls === 1, 'An unchanged dirty block must follow existing block hash gate, not replay HTTP.');
    }
}

// Existing accepted preparations and retries must not be canceled by an unchanged link hash.
foreach (['pending', 'retry', 'legacy-retry'] as $status) {
    [$service, $remote] = notificationSetup();
    $service->queueAssetIfNeeded('Computer', 101, 'a');
    $prepared = notificationRow();
    AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 101, 'glpi_b_connection_id' => 'a',
        'route_id' => 'route', 'remote_items_id' => 201, 'status' => 'synced', 'last_payload_hash' => $prepared['payload_hash']]);
    if ($status !== 'pending') {
        $job = AssetSyncQueue::claimDue(1)[0];
        AssetSyncQueue::retry($job, 'retry');
        $GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['finished_at' => gmdate('Y-m-d H:i:s', time() - 61)], ['id' => 1]);
        if ($status === 'legacy-retry') { $GLOBALS['DB']->update(AssetSyncQueue::TABLE, ['payload_hash' => ''], ['id' => 1]); }
    }
    notificationCheck($service->processQueue(1) === 1 && count($remote->requests) > 0 && $remote->records['a:Computer'][201]['name'] === 'First edit', 'Accepted prepared/retry work must still execute even when link hash matches.');
}

// Backfill may replace preparation, but it must never clear a later hook notification.
[$service, $remote] = notificationSetup();
notificationHook();
notificationCheck($service->queueAssetIfNeeded('Computer', 101, 'a') && notificationRow()['needs_recheck'] === 1, 'Backfill must preserve dirty while preparing an unprepared notification.');

// A stale worker must not update preparation, completion, links or HTTP.
foreach (['entry', 'missing', 'outscope', 'conflict', 'preparation', 'failed-preparation'] as $stage) {
    [$service, $remote] = notificationSetup();
    notificationHook();
    $job = AssetSyncQueue::claimDue(1)[0];
    $links = $GLOBALS['DB']->tables[AssetSyncLink::TABLE];
    $lose = static function (): void { $GLOBALS['DB']->tables[AssetSyncQueue::TABLE][0]['attempts']++; };
    if ($stage === 'entry') { $lose(); }
    if ($stage === 'missing') {
        $GLOBALS['DB']->tables['glpi_computers'][0]['is_deleted'] = 1;
        $GLOBALS['DB']->afterFirstRow = static function (string $table) use ($lose): void {
            if ($table === 'glpi_computers') { $lose(); }
        };
    }
    if ($stage === 'outscope') { $GLOBALS['DB']->tables['glpi_computers'][0]['entities_id'] = 9; }
    if ($stage === 'conflict') {
        EntitySyncRoute::save(['id' => 'other', 'glpi_b_connection_id' => 'a',
            'glpi_a_source_entity_id' => '1', 'glpi_b_target_entity_id' => '200', 'asset_types' => ['Computer'], 'active' => true]);
    }
    if (in_array($stage, ['outscope', 'conflict'], true)) {
        $GLOBALS['DB']->afterRequest = static function (array $query) use ($lose): void {
            if ($query['FROM'] === 'glpi_computers') { $lose(); }
        };
    }
    if ($stage === 'preparation') {
        $GLOBALS['DB']->beforeUpdate = static function (string $table, array $fields) use ($lose): bool {
            if ($table === AssetSyncQueue::TABLE && isset($fields['payload_hash'])) { $lose(); }
            return true;
        };
    }
    if ($stage === 'failed-preparation') {
        $GLOBALS['DB']->beforeUpdate = static fn (string $table, array $fields): bool => $table !== AssetSyncQueue::TABLE || !isset($fields['payload_hash']);
    }
    $service->processJob($job);
    notificationCheck($GLOBALS['DB']->tables[AssetSyncLink::TABLE] === $links && $remote->requests === [], 'Lost owner must not write links or call HTTP at ' . $stage);
    notificationCheck(notificationRow()['status'] === 'running' && notificationRow()['payload_hash'] === '', 'Lost owner must not complete or prepare the winning claim.');
}

echo "Queue notification tests passed ($checks checks).\n";
