<?php
declare(strict_types=1);

ob_start(); require __DIR__ . '/agent-rest-scope.php'; ob_end_clean();

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetUuidOperation;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\RestWork;

const SELECTION_CURSOR = 'rest_work_dispatch_cursor_v1';

/** Only named-lock ownership is doubled. Candidate/cursor/queue SQL uses the existing SQLite fixture. */
final class DispatchSelectionDB
{
    public AgentSqlite $sql;
    public int $actor = 17;
    public ?int $owner = null;
    public int $acquisitions = 0;
    public int $advances = 0;
    public int $candidateReads = 0;
    public bool $releaseFails = false;
    public bool $assetUnderSelection = false;
    public ?Closure $onAcquire = null;
    public ?Closure $onCandidate = null;
    public function __construct(public object $base) { $this->sql = $base->sql; }
    public function __call(string $name, array $args): mixed { return $this->base->$name(...$args); }
    public function tableExists(string $table): bool { return $this->base->tableExists($table); }
    public function fetchAssoc(PDOStatement $result): ?array { return $this->base->fetchAssoc($result); }
    public function quote(string $value): string { return $this->base->quote($value); }
    public function errno(): int { return $this->base->errno(); }
    public function request(array $query): ArrayIterator { return $this->base->request($query); }
    public function update(string $table, array $fields, array $where): bool { return $this->base->update($table, $fields, $where); }
    public function insert(string $table, array $fields): bool { return $this->base->insert($table, $fields); }
    public function fieldExists(string $table, string $field): bool { return $this->base->fieldExists($table, $field); }
    public function affectedRows(): int { return $this->base->affectedRows(); }
    public function doQuery(string $sql): mixed {
        if (str_contains($sql, 'assetsync20:dispatch:')) {
            if (str_starts_with($sql, 'SELECT COALESCE(IS_USED_LOCK(')) {
                return $this->sql->pdo->query('SELECT ' . (int) ($this->owner === $this->actor) . ' AS owned');
            }
            if (str_starts_with($sql, 'SELECT GET_LOCK(')) {
                $acquired = $this->owner === null;
                if ($acquired) { $this->owner = $this->actor; $this->acquisitions++; if ($this->onAcquire) { ($this->onAcquire)(); } }
                return $this->sql->pdo->query('SELECT ' . (int) $acquired . ' AS acquired');
            }
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK(')) {
                $released = !$this->releaseFails && $this->owner === $this->actor;
                if ($released) { $this->owner = null; }
                return $this->sql->pdo->query('SELECT ' . (int) $released . ' AS released');
            }
        }
        if (str_starts_with($sql, 'SELECT GET_LOCK(') && $this->owner !== null) { $this->assetUnderSelection = true; }
        if (str_starts_with($sql, 'SELECT * FROM `' . AgentTask::TABLE . '` WHERE `slot` IS NOT NULL') && str_contains($sql, 'JSON_VALID(`body`)')) {
            $this->candidateReads++;
            if ($this->onCandidate) { ($this->onCandidate)(); }
        }
        return $this->base->doQuery($sql);
    }
}

$selectionChecks = 0;
function selectionCheck(bool $ok, string $message): void {
    global $selectionChecks; $selectionChecks++;
    if (!$ok) { throw new RuntimeException($message); }
}
function selectionCursor(): array {
    return json_decode(Config::getConfigurationValues(GlpiBConnection::CONTEXT, [SELECTION_CURSOR])[SELECTION_CURSOR] ?? 'null', true);
}
function selectionNext(bool $first = true, ?int $deadline = null): ?array {
    return (new ReflectionMethod(RestWork::class, 'dispatchCandidate'))->invoke(null, $deadline ?? hrtime(true) + 20_000_000_000, $first);
}
function selectionRows(): array { global $DB; return iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'ORDER' => 'id ASC'])); }
function selectionCatch(Closure $call): bool { try { $call(); return false; } catch (RuntimeException) { return true; } }

function selectionAttach(object $base): void {
    global $DB; $DB = new DispatchSelectionDB($base);
    Config::$beforeRead = static function ($context): void {
        global $DB;
        $query = $DB->sql->pdo->prepare('SELECT name,value FROM test_config WHERE context=?'); $query->execute([$context]);
        Config::$values[$context] = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    Config::$beforeWrite = static function ($context, $values): void {
        global $DB;
        if (isset($values[SELECTION_CURSOR])) { $DB->advances++; }
        foreach ($values as $name => $value) {
            $DB->sql->pdo->prepare('INSERT INTO test_config VALUES(?,?,?) ON CONFLICT(context,name) DO UPDATE SET value=excluded.value')->execute([$context, $name, $value]);
        }
    };
}

// A fresh PHP process reopens the durable cursor; no static sweep position is restored.
if (($argv[1] ?? '') === '--resume') {
    selectionAttach(new SettingsDispatchDB(new AgentSqlite($argv[2])));
    $next = selectionNext();
    echo json_encode(['id' => $next['id'] ?? null, 'cursor' => selectionCursor()], JSON_THROW_ON_ERROR);
    exit;
}

function selectionSetup(int $prefix = 0): array {
    global $DB;
    static $oldName;
    if ($oldName === null) { $p = nameQueueSetup(); $oldName = nameQueueTask($p); }
    $pair = scopeSetupRest(); publishPolicy();
    AssetSyncQueue::notify('Computer', 101, 'b'); RestWork::process(AssetSyncQueue::claimDue(1)[0]);
    $healthy = AgentTask::outstanding('b', $pair);
    $DB->update(AgentTask::TABLE, ['created_at' => 1000], ['id' => $healthy['id']]);
    $healthy = currentTask($healthy['id']);
    for ($i = 0; $i < $prefix; $i++) {
        $body = json_decode($oldName['body'], true);
        $body['id'] = md5('selection-unsafe-' . $i); $body['pair_id'] = md5('selection-pair-' . $i); $body['batch_id'] = md5('selection-batch-' . $i);
        $context = json_decode($oldName['context'], true); $context['rest_sent'] = 'false';
        $DB->insert(AgentTask::TABLE, array_replace($oldName, ['id' => $body['id'], 'slot' => 'pair:' . $body['pair_id'],
            'body' => json_encode($body), 'hash' => AgentTask::hash($body), 'context' => json_encode($context), 'created_at' => 100 + $i]));
    }
    selectionAttach($DB); RestDispatchWire::reset();
    return [$pair, $healthy];
}

function selectionReply(array $task): void {
    RestDispatchWire::$replies = [static function ($method, $path, $options) use ($task): array {
        global $DB;
        selectionCheck($DB->owner === null && !AssetUuidOperation::hasAssetLocks(), 'Selection/asset locks released before Work HTTP.');
        $input = json_decode($options[CURLOPT_POSTFIELDS], true)['input'];
        selectionCheck($method === 'POST' && count($input) === 1 && $input[0]['work_id'] === $task['id'], 'Only the healthy immutable Work10 is dispatched.');
        return ['status' => 201, 'body' => [['id' => 77]]];
    }, ['status' => 200, 'body' => [settingsWorkRecord($task)]]];
}

[$pair, $healthy] = selectionSetup(1); $before = selectionRows(); $queueBefore = queue();
selectionReply($healthy); RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck(RestDispatchWire::$replies === [] && json_decode(currentTask($healthy['id'])['context'], true)['rest_sent'] === true,
    'Exact malformed-string Work11 prefix no longer blocks ordinary-queue-prepared Work10.');
foreach ($before as $row) { if ($row['id'] !== $healthy['id']) { selectionCheck(currentTask($row['id']) === $row, 'Unsafe/old task remains byte-exact, including result/job/context.'); } }
selectionCheck(queue() === $queueBefore && !$DB->assetUnderSelection && $DB->owner === null, 'Dispatch advances no queue completion and holds no selection lock over asset work.');

[$pair, $healthy] = selectionSetup(70); $before = selectionRows();
RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck($DB->advances === 32 && $DB->candidateReads === 32 && RestDispatchWire::$calls === [] && selectionRows() === $before,
    'First pass examines exactly32 unsafe candidates, zero HTTP/task writes; prefix exceeds50.');
$ceiling = selectionCursor()['ceiling'];
// Simulate continuous arrivals beyond the fixed sweep ceiling.
for ($i = 0; $i < 8; $i++) {
    // Random healthy IDs may sort first; arrivals must retain the unsafe fixture's batch/context.
    $row = currentTask(md5('selection-unsafe-0')); $body = json_decode($row['body'], true); $body['id'] = md5('arrival-' . $i); $body['pair_id'] = md5('arrival-pair-' . $i);
    $row = array_replace($row, ['id' => $body['id'], 'slot' => 'pair:' . $body['pair_id'], 'created_at' => 2000 + $i,
        'body' => json_encode($body), 'hash' => AgentTask::hash($body)]);
    $DB->insert(AgentTask::TABLE, $row);
}
$file = tempnam(sys_get_temp_dir(), 'assetsync-dispatch-'); unlink($file);
try {
    $DB->sql->pdo->exec('VACUUM INTO ' . $DB->quote($file));
    $lines = []; $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --resume ' . escapeshellarg($file) . ' 2>&1', $lines, $exit);
    $resumed = $exit === 0 ? json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR) : null;
    selectionCheck($exit === 0 && $resumed['id'] === md5('selection-unsafe-32') && $resumed['cursor']['ceiling'] === $ceiling,
        'Fresh PHP process resumes after32, retaining the old fixed ceiling despite new arrivals.');
} finally { if (is_file($file)) { unlink($file); } }
$reads = $DB->candidateReads; RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck($DB->candidateReads - $reads === 32 && selectionCursor()['ceiling'] === $ceiling && RestDispatchWire::$calls === [], 'Second pass retains ceiling and bounded next32.');
selectionReply($healthy); RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck(RestDispatchWire::$replies === [] && selectionCursor()['after']['id'] === $healthy['id'], 'Healthy work beyond70 unsafe rows progresses without rewriting the prefix.');
$next = selectionNext();
selectionCheck($next['id'] === md5('selection-unsafe-0') && selectionCursor()['ceiling']['created_at'] === 2007,
    'Next ordinary pass wraps and captures a new ceiling; arrivals cannot extend the prior sweep.');

[$pair, $healthy] = selectionSetup(2); $first = selectionNext(); $point = selectionCursor()['after'];
$DB->doQuery('DELETE FROM `' . AgentTask::TABLE . '` WHERE id=' . $DB->quote($first['id']));
$next = selectionNext();
selectionCheck($next['id'] === md5('selection-unsafe-1') && selectionCursor()['after'] !== $point, 'Deleted cursor row does not require OFFSET/existence or restart the sweep.');

[$pair, $healthy] = selectionSetup(2); $during = null;
$DB->onAcquire = static function () use (&$during): void {
    global $DB; $DB->onAcquire = null; $DB->actor = 18;
    $during = selectionNext(); $DB->actor = 17;
};
$first = selectionNext(); $DB->actor = 18; $second = selectionNext();
selectionCheck($during === null && $first['id'] !== $second['id'] && $DB->acquisitions === 2 && $DB->owner === null,
    'Concurrent selector cannot enter held nonblocking lock, then rereads durable cursor for a distinct candidate.');
$DB->owner = 18; $DB->actor = 18; $before = selectionCursor();
selectionCheck(selectionNext() === null && selectionCursor() === $before && $DB->owner === 18, 'Nested same-owner selection never releases its outer lock.');
$DB->owner = null;

[$pair, $healthy] = selectionSetup(1); $before = selectionRows(); $persist = Config::$beforeWrite;
Config::$beforeWrite = static function ($context, $values) use ($persist): void { if (!isset($values[SELECTION_CURSOR])) { $persist($context, $values); } };
selectionCheck(selectionCatch(static fn () => RestWork::sendPending(hrtime(true) + 20_000_000_000)) && selectionRows() === $before
    && RestDispatchWire::$calls === [] && $DB->owner === null, 'Silent cursor-write failure fails fresh readback before any candidate authorization.');
Config::$beforeWrite = $persist;

[$pair, $healthy] = selectionSetup(1); $before = selectionRows(); $persist = Config::$beforeWrite;
Config::$beforeWrite = static function ($context, $values) use ($persist): void {
    $persist($context, $values); if (isset($values[SELECTION_CURSOR])) { throw new RuntimeException('Injected crash after advance'); }
};
selectionCheck(selectionCatch(static fn () => RestWork::sendPending(hrtime(true) + 20_000_000_000)) && selectionRows() === $before
    && RestDispatchWire::$calls === [] && $DB->owner === null, 'Crash after durable cursor advance changes no task and releases selection lock.');
Config::$beforeWrite = $persist; selectionReply($healthy); RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck(RestDispatchWire::$replies === [], 'Next pass progresses after crash without granting execution to the skipped task.');

[$pair, $healthy] = selectionSetup(); $before = selectionRows();
RestWork::sendPending(hrtime(true) + 1_000_000);
selectionCheck($DB->acquisitions === 0 && RestDispatchWire::$calls === [] && selectionRows() === $before, 'Exhausted deadline performs no selection or HTTP.');
$DB->onCandidate = static function (): void { usleep(15000); };
RestWork::sendPending(hrtime(true) + 2_010_000_000);
selectionCheck(RestDispatchWire::$calls === [] && selectionRows() === $before && $DB->owner === null, 'Deadline exhausted during selection cannot start reservation/auth afterward.');

[$pair, $healthy] = selectionSetup(); $DB->releaseFails = true; $before = selectionRows();
selectionCheck(selectionCatch(static fn () => RestWork::sendPending(hrtime(true) + 20_000_000_000)) && RestDispatchWire::$calls === []
    && selectionRows() === $before && $DB->owner === 17, 'Unverified RELEASE_LOCK is an error, never authorization to continue.');

// An inactive row and a valid-looking row without a pair are zero-reservation candidates, not global blockers.
[$pair, $healthy] = selectionSetup();
GlpiBConnection::save(array_replace(GlpiBConnection::find('b'), ['id' => 'inactive-selection', 'active' => false]));
$retained = [];
foreach (['inactive-selection', 'b'] as $i => $connection) {
    $body = json_decode($healthy['body'], true); $body['id'] = md5('zero-reserve-' . $i); $body['pair_id'] = md5('missing-pair-' . $i);
    $body['batch_id'] = md5('zero-batch-' . $i); $body['connection_id'] = $connection;
    $row = array_replace($healthy, ['id' => $body['id'], 'slot' => 'pair:' . $body['pair_id'], 'connection_id' => $connection,
        'created_at' => 200 + $i, 'body' => json_encode($body), 'hash' => AgentTask::hash($body)]);
    $DB->insert(AgentTask::TABLE, $row); $retained[] = currentTask($row['id']);
}
selectionReply($healthy); RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck(RestDispatchWire::$replies === [], 'Sweep continues past inactive and zero-reservation missing-pair batches.');
foreach ($retained as $row) { selectionCheck(currentTask($row['id']) === $row, 'Skipped inactive/missing-pair context and evidence stay exact.'); }

// SQL due/version filters keep unrelated reads and delayed leases out of the candidate budget/ceiling.
[$pair, $healthy] = selectionSetup();
for ($i = 0; $i < 90; $i++) {
    $body = json_decode($healthy['body'], true); $body['id'] = md5('not-due-' . $i); $body['pair_id'] = md5('not-due-pair-' . $i);
    $context = json_decode($healthy['context'], true);
    if ($i % 3 === 0) { $context['rest_until'] = time() + 3600; }
    if ($i % 3 === 1) { $context['rest_next'] = time() + 3600; }
    if ($i % 3 === 2) { $body['version'] = 9; }
    $DB->insert(AgentTask::TABLE, array_replace($healthy, ['id' => $body['id'], 'slot' => 'pair:' . $body['pair_id'],
        'created_at' => $i === 89 ? 9000 : 50 + $i, 'body' => json_encode($body), 'hash' => AgentTask::hash($body), 'context' => json_encode($context)]));
}
$before = selectionRows(); $next = selectionNext();
selectionCheck($next['id'] === $healthy['id'] && $DB->candidateReads === 1 && selectionCursor()['ceiling']['id'] === $healthy['id']
    && selectionRows() === $before, 'Ninety delayed/read9 rows consume no candidate budget and cannot become the sweep ceiling.');

[$pair, $healthy] = selectionSetup(6);
foreach (['{', '[]', '{"rest_sent":0}', '{"rest_sent":null}'] as $i => $context) {
    $DB->update(AgentTask::TABLE, ['context' => $context], ['id' => md5('selection-unsafe-' . $i)]);
}
$before = selectionRows(); selectionReply($healthy); RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck(RestDispatchWire::$replies === [], 'Safe JSON predicates and strict PHP checks pass malformed JSON/typed contexts without casting or SQL parser failure.');
foreach ($before as $row) { if ($row['id'] !== $healthy['id']) { selectionCheck(currentTask($row['id']) === $row, 'Malformed retained evidence is never rewritten.'); } }

[$pair, $healthy] = selectionSetup(); $before = selectionRows(); $DB->sql->failOn = 'JSON_VALID(`body`)';
selectionCheck(selectionCatch(static fn () => RestWork::sendPending(hrtime(true) + 20_000_000_000)) && RestDispatchWire::$calls === []
    && selectionRows() === $before && $DB->owner === null, 'SQL selection failure propagates and releases lock; never classified as a validation skip.');
$DB->sql->failOn = null;
$DB->doQuery('START TRANSACTION');
selectionCheck(selectionNext() === null && $DB->sql->pdo->inTransaction(), 'Selection neither joins nor commits a caller-owned transaction.');
$DB->doQuery('ROLLBACK');

[$pair, $healthy] = selectionSetup(2);
$DB->update(AgentTask::TABLE, ['created_at' => 100], ['id' => md5('selection-unsafe-1')]);
$tieIds = [md5('selection-unsafe-0'), md5('selection-unsafe-1')]; sort($tieIds, SORT_STRING);
selectionCheck(selectionNext()['id'] === $tieIds[0] && selectionNext(false)['id'] === $tieIds[1], 'Equal creation seconds use exact id keyset tie-break, without duplicate or lost candidates.');

// The first successful reservation ends this pass even if its GET fails. A later batch is untouched.
[$pair, $first] = selectionSetup();
$asset = $DB->firstRow('glpi_computers', ['id' => 101]); $DB->insert('glpi_computers', array_replace($asset, ['id' => 102]));
$sibling = array_replace($pair, ['id' => md5('selection-sibling'), 'local_id' => 102, 'computer_id' => 202]);
$snapshot = json_decode($sibling['snapshot'], true); $snapshot['items_id'] = 202; $sibling['snapshot'] = json_encode($snapshot);
$DB->insert(AgentTask::PAIRS, $sibling);
AssetSyncLink::save(array_replace(AssetSyncLink::find('Computer', 101, 'b'), ['items_id' => 102, 'remote_items_id' => 202, 'confirmed_remote_id' => 202]));
RestWork::beginRun(); AssetSyncQueue::notify('Computer', 102, 'b'); RestWork::process(AssetSyncQueue::claimDue(1)[0]);
$second = AgentTask::outstanding('b', $sibling); $DB->update(AgentTask::TABLE, ['created_at' => 1001], ['id' => $second['id']]); $second = currentTask($second['id']);
$context = json_decode($first['context'], true); $context['rest_sent'] = true; $context['rest_lease'] = str_repeat('a', 32);
$DB->update(AgentTask::TABLE, ['context' => json_encode($context)], ['id' => $first['id']]);
RestDispatchWire::reset(); RestDispatchWire::$replies = [['status' => 503, 'body' => []]];
RestWork::sendPending(hrtime(true) + 20_000_000_000);
selectionCheck(RestDispatchWire::$replies === [] && currentTask($second['id']) === $second && selectionCursor()['after']['id'] === $first['id'],
    'Reserved-any stops selection after first batch, including failed observation; no second reservation/POST.');
selectionCheck(count(array_filter(RestDispatchWire::$calls, static fn ($c) => $c['method'] === 'POST')) === 0, 'Failed GET never establishes absence or authorizes another POST.');

echo "REST dispatch selection: $selectionChecks checks PASS (host SQL/lock/HTTP doubles; native worker/races remain independent).\n";
