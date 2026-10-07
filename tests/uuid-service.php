<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require_once __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\AssetUuidOperation;
use GlpiPlugin\Assetsync20\AssetUuidService;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;

// The local harness has no Composer vendor tree. Live verification must use GLPI's Ramsey validator.
if (!class_exists(\Ramsey\Uuid\Uuid::class)) {
    class UuidValidatorFixture
    {
        public static int $generated = 0;
        public static function isValid(string $value): bool { return (bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value); }
        public static function uuid4(): self { self::$generated++; return new self(); }
        public function toString(): string { return '12345678-1234-4234-8234-' . sprintf('%012d', self::$generated); }
    }
    class_alias(UuidValidatorFixture::class, \Ramsey\Uuid\Uuid::class);
}
if (!class_exists('Session')) {
    class Session
    {
        public static bool $cron = true;
        public static bool $locks = true;
        public static function isCron(): bool { return self::$cron; }
        public static function haveRight($name, $right): bool { return self::$locks; }
    }
}

$uuidChecks = 0;
function uuidCheck(bool $ok, string $message): void
{
    global $uuidChecks;
    $uuidChecks++;
    if (!$ok) { throw new RuntimeException($message); }
}

class UuidRemoteFixture
{
    public array $items = [];
    public array $calls = [];
    public array $writes = [];
    public array $denied = [];
    public array $unknown = [];
    public array $ignore = [];
    public array $logs = [];
    public ?Closure $onCall = null;
    public function uuidSnapshot(array $connection, string $type, int $id, int $entity, bool $writing, ?string $old, ?string $new): array
    {
        $key = $connection['id'];
        $this->calls[] = [$key, $writing, $new];
        if ($this->onCall !== null) { ($this->onCall)($key, $writing, $new); }
        if (isset($this->unknown[$key])) { return ['success' => false]; }
        if ($writing && isset($this->denied[$key])) { return ['success' => false, 'outcome' => $this->denied[$key]]; }
        $item = $this->items[$key];
        if ($new !== null) {
            uuidCheck((string) ($item['uuid'] ?? '') === $old, 'Dedicated remote writes compare the observed UUID.');
            $op = AssetUuidOperation::find('Computer', 1);
            $state = AssetUuidOperation::state($op);
            uuidCheck(($state['selected_uuid'] ?? null) === $new && !empty($state['sides'][AssetUuidService::remoteSideKey($key, $id, $connection['base_url'])]['pending']), 'Selected target and pending evidence are durable before remote I/O.');
            $this->writes[] = [$key, $new];
            if (!empty($this->ignore[$key])) { return ['success' => false, 'outcome' => 'unverified']; }
            $item['uuid'] = $new;
            $item['date_mod'] = '2026-10-07 12:00:00';
            $this->items[$key] = $item;
            array_unshift($this->logs[$key], ['id' => (int) ($this->logs[$key][0]['id'] ?? 0) + 1, 'id_search_option' => 400,
                'linked_action' => 0, 'itemtype_link' => '', 'old_value' => $old, 'new_value' => $new]);
        }
        return ['success' => true, 'item' => $item, 'date_mod_timezone' => 'UTC', 'history' => ['known' => true, 'rows' => $this->logs[$key], 'total' => count($this->logs[$key]), 'uuid_options' => [400]]];
    }
}

function uuidFixture(string $a = '', array $bs = ['one' => '']): UuidRemoteFixture
{
    global $DB;
    $DB = new FakeDB();
    $DB->timezone = 'UTC';
    $DB->tables['glpi_lockedfields'] = [];
    Config::$values = [];
    GlpiBConnection::endRunCache();
    EntitySyncRoute::endRunCache();
    AssetUuidOperation::install();
    AssetSyncLink::install();
    Computer::$allowUpdate = true;
    Computer::$ignoreUpdate = false;
    Session::$cron = true;
    Search::$nativeOptions = [400 => ['table' => 'glpi_computers', 'field' => 'uuid', 'datatype' => 'string', 'uid' => 'Computer.uuid']];
    $DB->insert('glpi_computers', ['id' => 1, 'entities_id' => 0, 'uuid' => $a, 'name' => 'Asset', 'serial' => 'Serial',
        'comment' => 'PRIVATE COMMENT MUST NOT PERSIST', 'contact' => 'PRIVATE CONTACT MUST NOT PERSIST', 'date_mod' => '2026-10-01 12:00:00', 'is_deleted' => 0, 'is_template' => 0]);
    $DB->insert('glpi_logs', ['id' => 10, 'itemtype' => 'Computer', 'items_id' => 1, 'id_search_option' => 1, 'linked_action' => 0,
        'itemtype_link' => '', 'old_value' => '', 'new_value' => 'Asset', 'date_mod' => '2026-10-01 12:00:00']);
    $client = new UuidRemoteFixture();
    foreach ($bs as $key => $uuid) {
        GlpiBConnection::save(['id' => $key, 'name' => $key, 'base_url' => 'https://' . $key . '.invalid', 'app_token' => 'PRIVATE APP', 'user_token' => 'PRIVATE TOKEN', 'active' => true]);
        EntitySyncRoute::save(['id' => 'route-' . $key, 'glpi_b_connection_id' => $key, 'glpi_a_source_entity_id' => '0', 'glpi_b_target_entity_id' => '5', 'asset_types' => ['Computer'], 'active' => true]);
        AssetSyncLink::save(['itemtype' => 'Computer', 'items_id' => 1, 'glpi_b_connection_id' => $key, 'route_id' => 'route-' . $key, 'remote_items_id' => 10]);
        $client->items[$key] = ['id' => 10, 'entities_id' => 5, 'uuid' => $uuid, 'name' => 'Remote', 'date_mod' => '2026-10-02 12:00:00', 'is_deleted' => 0, 'is_template' => 0];
        $client->logs[$key] = [['id' => 10, 'id_search_option' => 1, 'linked_action' => 0, 'itemtype_link' => '', 'old_value' => '', 'new_value' => 'Remote']];
    }
    $DB->beforeUpdate = static function (string $table, array $fields) use ($DB): void {
        if ($table !== 'glpi_computers' || !array_key_exists('uuid', $fields)) { return; }
        $op = AssetUuidOperation::find('Computer', 1);
        uuidCheck(!empty(AssetUuidOperation::state($op)['sides']['a']['pending']), 'Local UUID pending evidence is durable before model update.');
        $old = $DB->tables[$table][0]['uuid'];
        $DB->tables[$table][0]['date_mod'] = '2026-10-07 12:00:00';
        $DB->insert('glpi_logs', ['itemtype' => 'Computer', 'items_id' => 1, 'id_search_option' => 400, 'linked_action' => 0, 'itemtype_link' => '', 'old_value' => $old, 'new_value' => $fields['uuid']]);
    };
    return $client;
}

function uuidRun(UuidRemoteFixture $client, array $paused = []): array
{
    return (new AssetUuidService($client))->run(hrtime(true) + 20_000_000_000, $paused);
}
function uuidRetry(): void
{
    global $DB;
    foreach ($DB->tables[AssetUuidOperation::TABLE] as &$row) { $row['next_attempt'] = null; $row['lease_until'] = null; }
    unset($row);
}

$u1 = '12345678-1234-4234-8234-123456789012';
$u2 = 'abcdefab-abcd-4abc-8abc-abcdefabcdef';
foreach (['', '  ', null] as $value) { uuidCheck(AssetUuidService::normalize($value) === '', 'Only null/empty/whitespace is blank.'); }
foreach ([$u1, $u2, '00000000-0000-0000-0000-000000000000', 'ffffffff-ffff-ffff-ffff-ffffffffffff'] as $value) {
    uuidCheck(AssetUuidService::normalize(' ' . strtoupper($value) . ' ') === $value, 'Canonical dashed UUID, nil and max follow Ramsey syntax without identity policy.');
}
foreach (['{' . $u1 . '}', 'urn:uuid:' . $u1, str_replace('-', '', $u1), 'invalid', 0, false] as $value) {
    uuidCheck(AssetUuidService::normalize($value) === null, 'Wrapped/noncanonical/nonstring UUID rejected.');
}
uuidCheck(AssetUuidService::select('invalid', [''], $u1, $u2)['outcome'] === 'conflict', 'Malformed A cannot fall through to retained intent or generation.');
uuidCheck(AssetUuidService::select('invalid', [$u2], $u1, null)['uuid'] === $u2, 'Fresh B consensus overrides malformed A and old intent.');
uuidCheck(AssetUuidService::select($u1, [$u1, $u2], null, null)['outcome'] === 'conflict', 'Distinct valid B values conflict.');
uuidCheck(AssetUuidService::select($u1, ['invalid'], null, null)['outcome'] === 'conflict', 'Malformed B is a conflict, not blank.');

foreach (['unknown', 'permission', 'locked'] as $failure) {
    $client = uuidFixture('', ['one' => '', 'two' => '']);
    if ($failure === 'unknown') { $client->unknown['two'] = true; } else { $client->denied['two'] = $failure; }
    $result = uuidRun($client);
    uuidCheck($result['outcome'] === $failure && $client->writes === [] && $DB->tables['glpi_computers'][0]['uuid'] === '', 'All participant/permission/lock checks precede the first mutation: ' . $failure);
}
$client = uuidFixture('', ['one' => $u1, 'two' => $u2]);
uuidCheck(uuidRun($client)['outcome'] === 'conflict' && $client->writes === [] && $DB->tables['glpi_computers'][0]['uuid'] === '', 'Conflicting Bs preserve every side.');
$client = uuidFixture('malformed', ['one' => '']);
uuidCheck(uuidRun($client)['outcome'] === 'conflict' && AssetUuidOperation::find('Computer', 1)['generated_value'] === null, 'Malformed A neither generates nor writes.');

$client = uuidFixture('', ['one' => '', 'two' => '']);
$result = uuidRun($client);
$operation = AssetUuidOperation::find('Computer', 1);
$state = AssetUuidOperation::state($operation);
$generated = $operation['generated_value'];
uuidCheck($result['outcome'] === 'verified' && AssetUuidService::normalize($generated) !== '', 'Blank consensus generates and verifies one durable UUID.');
uuidCheck($DB->tables['glpi_computers'][0]['uuid'] === $generated && $client->items['one']['uuid'] === $generated && $client->items['two']['uuid'] === $generated, 'Generated UUID reaches A and all linked B participants.');
uuidCheck(!$state['sides']['a']['time_uncertain'] && $state['sides']['a']['effective_date'] === '2026-10-01 12:00:00', 'Verified UUID-only local history retains the prior effective timestamp.');
foreach (['PRIVATE COMMENT', 'PRIVATE CONTACT', 'PRIVATE APP', 'PRIVATE TOKEN', 'base_url', 'serial'] as $private) {
    uuidCheck(!str_contains($operation['state_json'], $private), 'Durable evidence excludes private payloads: ' . $private);
}
$writes = count($client->writes);
uuidRetry();
uuidCheck(uuidRun($client)['outcome'] === 'equal' && count($client->writes) === $writes && AssetUuidOperation::find('Computer', 1)['generated_value'] === $generated, 'Retry/equal recheck neither regenerates nor rewrites.');

$client = uuidFixture('', ['one' => '']);
$client->ignore['one'] = true;
uuidCheck(uuidRun($client)['outcome'] === 'unverified', 'Ignored remote write never reports success.');
$operation = AssetUuidOperation::find('Computer', 1);
$generated = $operation['generated_value'];
uuidCheck($DB->tables['glpi_computers'][0]['uuid'] === $generated, 'Partial local success retains generated value.');
unset($client->ignore['one']);
$client->items['one']['uuid'] = $u2;
uuidRetry();
uuidCheck(uuidRun($client)['outcome'] === 'verified' && $DB->tables['glpi_computers'][0]['uuid'] === $u2, 'Fresh valid B overrides a prior partially written generated intent.');
uuidCheck(AssetUuidOperation::find('Computer', 1)['generated_value'] === $generated, 'Original generated value remains immutable after B override.');

$client = uuidFixture('', ['one' => $u1]);
uuidCheck(uuidRun($client, ['one' => true])['outcome'] === 'unknown' && $client->calls === [], 'Paused active participant is unknown with zero HTTP.');
$connection = GlpiBConnection::find('one');
$connection['active'] = false;
GlpiBConnection::save($connection);
uuidRetry();
uuidCheck(uuidRun($client)['outcome'] === 'no_participants' && $client->calls === [], 'Disabled participant makes no HTTP and cannot generate A UUID.');

$client = uuidFixture('', ['one' => '']);
$client->onCall = static function () use ($client): void {
    $connection = GlpiBConnection::find('one');
    $connection['active'] = false;
    GlpiBConnection::save($connection);
    $client->onCall = null;
};
uuidCheck(uuidRun($client)['outcome'] === 'changed' && count($client->calls) === 1 && $client->writes === [], 'A mid-operation disable is freshly detected before any further HTTP or mutation.');

$client = uuidFixture('', ['one' => '']);
AssetUuidOperation::bootstrap(hrtime(true) + 1_000_000_000);
$claim = AssetUuidOperation::claim();
uuidCheck($claim !== null && AssetUuidOperation::owns($claim), 'Claim establishes SQL-fenced ownership.');
$stale = $claim;
$stale['claim_token'] = str_repeat('f', 32);
uuidCheck(!AssetUuidOperation::persist($stale, ['selected_uuid' => $u1], $u1), 'Stale claimant cannot persist generation.');
$DB->tables[AssetUuidOperation::TABLE][0]['generated_value'] = $u2;
uuidCheck(!AssetUuidOperation::persist($claim, ['selected_uuid' => $u1], $u1), 'Generation CAS must fail when guard affects zero rows even while lease is owned.');
$DB->tables[AssetUuidOperation::TABLE][0]['lease_until'] = '2000-01-01 00:00:00';
uuidCheck(!AssetUuidOperation::owns($claim) && !AssetUuidOperation::finish($claim, [], 'verified'), 'Expired lease fences persistence and finish.');
foreach ($DB->requests as $query) {
    foreach ($query['WHERE'] ?? [] as $condition) {
        if ($condition instanceof \Glpi\DBAL\QueryExpression && str_contains($condition->expression, 'lease_until')) {
            uuidCheck(str_contains($condition->expression, 'UNIX_TIMESTAMP(`lease_until`)'), 'Lease selection and fences use epoch comparisons.');
        }
    }
}

$before = ['item' => ['id' => 1, 'uuid' => '', 'name' => 'A', 'date_mod' => '2026-10-01 00:00:00'], 'history' => ['known' => true, 'rows' => [['id' => 1]]]];
$pending = AssetUuidService::pendingEvidence($before, [], $u1);
$after = ['item' => array_replace($before['item'], ['uuid' => $u1, 'date_mod' => '2026-10-07 00:00:00']),
    'history' => ['known' => true, 'total' => 2, 'uuid_options' => [400], 'rows' => [['id' => 2, 'id_search_option' => 400, 'linked_action' => 0, 'itemtype_link' => '', 'old_value' => '', 'new_value' => $u1], ['id' => 1]]]];
uuidCheck(AssetUuidService::uuidOnlyProof($pending, $after), 'Complete expected UUID history plus unchanged native scalar hash proves UUID-only effect.');
foreach (['missing_log', 'ordinary_change', 'unknown_history', 'missing_anchor', 'wrong_option', 'wrong_value'] as $case) {
    $bad = $after;
    $pre = $pending;
    match ($case) {
        'missing_log' => $bad['history']['rows'] = [],
        'ordinary_change' => $bad['item']['name'] = 'Changed',
        'unknown_history' => $bad['history']['known'] = false,
        'missing_anchor' => $pre['anchor']['id'] = 0,
        'wrong_option' => $bad['history']['rows'][0]['id_search_option'] = 401,
        'wrong_value' => $bad['history']['rows'][0]['new_value'] = $u2,
    };
    uuidCheck(!AssetUuidService::uuidOnlyProof($pre, $bad), 'Ambiguous history remains uncertain: ' . $case);
}
$guard = ['pending' => $pending, 'time_uncertain' => true];
uuidCheck(!empty(AssetUuidService::guardItem($after['item'], $guard)['_uuid_time_uncertain']), 'Crash pending cannot clear because UUID happens to match.');
$changes = (new ReflectionMethod(AssetSyncService::class, 'existingChanges'))->invoke(new AssetSyncService(),
    ['name' => 'A', 'comment' => 'A text', '_uuid_time_uncertain' => true], ['name' => 'B', 'comment' => 'B text'],
    ['glpi_b_target_entity_id' => ''], [
        ['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'both'],
        ['glpi_a_field' => 'comment', 'glpi_b_field' => 'comment', 'source_of_truth' => 'glpi_a'],
    ]);
uuidCheck($changes['conflicts'] === [] && $changes['uuid_conflicts'] === ['name'] && $changes['remote'] === ['comment' => 'A text'], 'UUID uncertainty defers only differing Both while one-way changes remain available.');
$customHistory = (new ReflectionMethod(AssetSyncService::class, 'customHistoryNeeds'))->invoke(new AssetSyncService(),
    ['_uuid_time_uncertain' => true], [], [], []);
uuidCheck($customHistory === ['local' => [], 'remote' => []], 'Deferred Both rows do not request unrelated custom history or hold up one-way writes.');
$work = (new ReflectionMethod(AssetSyncService::class, 'payloadNeedsWork'))->invoke(new AssetSyncService(),
    ['status' => AssetSyncLink::STATUS_BLOCKED_UUID_TIME, 'route_id' => 'r', 'last_payload_hash' => 'same', 'remote_items_id' => 10],
    ['id' => 'r'], 'same', [['source_of_truth' => 'both']]);
uuidCheck($work, 'UUID-derived conflict remains independently retryable without changing ordinary conflict policy.');

// Fresh A must participate in every last-minute consensus check, even when only B needs writing.
$client = uuidFixture($u1, ['one' => '']);
$client->onCall = static function () use ($client, $u2): void {
    $GLOBALS['DB']->tables['glpi_computers'][0]['uuid'] = $u2;
    $client->onCall = null;
};
uuidCheck(uuidRun($client)['outcome'] === 'changed' && $client->writes === [], 'An externally changed A UUID cannot send the stale observed value to B.');

$gap = $after;
$gap['history']['rows'][0]['id'] = 4;
uuidCheck(!AssetUuidService::uuidOnlyProof($pending, $gap), 'A global ID gap could hide purged ordinary edit/revert; it cannot prove UUID-only time.');
$guard = ['raw_date' => $after['item']['date_mod'], 'effective_date' => $before['item']['date_mod'],
    'effective_epoch' => 1790812800, 'snapshot_hash' => AssetUuidService::snapshotHash($after['item']), 'time_uncertain' => false,
    'anchor' => ['known' => true, 'id' => 2], 'timezone' => 'UTC'];
$history = ['known' => true, 'total' => 4, 'uuid_options' => [400], 'native_options' => [1 => 'name'], 'rows' => [
    ['id' => 4, 'id_search_option' => 1, 'linked_action' => 0, 'itemtype_link' => '', 'old_value' => 'Changed', 'new_value' => 'A', 'date_mod' => $after['item']['date_mod']],
    ['id' => 3, 'id_search_option' => 1, 'linked_action' => 0, 'itemtype_link' => '', 'old_value' => 'A', 'new_value' => 'Changed', 'date_mod' => $after['item']['date_mod']],
    ['id' => 2],
]];
$resolved = AssetUuidService::resolveGuard($after['item'], $guard, $history);
uuidCheck(empty($resolved['_uuid_time_uncertain']) && $resolved['date_mod'] === $after['item']['date_mod'], 'Logged same-second ordinary edit/revert retains attributable current native time, not pre-UUID time.');
$later = $after['item'];
$later['name'] = 'Changed';
$later['date_mod'] = '2026-10-08 00:00:00';
$laterHistory = $history;
$laterHistory['rows'] = [array_replace($history['rows'][1], ['date_mod' => $later['date_mod']]), ['id' => 2]];
$resolved = AssetUuidService::resolveGuard($later, $guard + ['pending' => $pending], $laterHistory);
// The pending anchor differs from the completed guard, so use its actual complete interval separately.
$resolved = AssetUuidService::resolveGuard($later, array_replace($guard, ['time_uncertain' => true]), $laterHistory);
uuidCheck(empty($resolved['_uuid_time_uncertain']) && $resolved['date_mod'] === $later['date_mod'], 'Later provable human edit clears a timestamp guard even with only Both mappings.');
$unlogged = $later;
$unlogged['comment'] = 'Unlogged external change';
uuidCheck(!empty(AssetUuidService::resolveGuard($unlogged, $guard, $laterHistory)['_uuid_time_uncertain']), 'Reverse-applied history must recover the entire prior scalar hash; unlogged fields cannot silently clear uncertainty.');
$unchangedHistory = ['known' => true, 'rows' => [['id' => 2]], 'total' => 1];
$resolved = AssetUuidService::resolveGuard($after['item'], $guard, $unchangedHistory);
uuidCheck($resolved['_date_mod_epoch'] === $guard['effective_epoch'], 'Verified local prior time retains the trusted UNIX epoch.');
uuidCheck(!empty(AssetUuidService::resolveGuard($after['item'], $guard, $unchangedHistory, false)['_uuid_time_uncertain']), 'A changed API timezone cannot reinterpret old effective date strings.');
uuidCheck(empty(AssetUuidService::resolveGuard($later, $guard, $laterHistory, false)['_uuid_time_uncertain']), 'Fresh later ordinary history can establish current time without interpreting an old timezone string.');

$client = uuidFixture($u1, ['one' => '', 'two' => $u1]);
$client->ignore['one'] = true;
uuidRun($client);
$connection = GlpiBConnection::find('one');
$side = AssetUuidService::remoteSideKey('one', 10, $connection['base_url']);
$pendingGuard = AssetUuidOperation::state(AssetUuidOperation::find('Computer', 1))['sides'][$side];
$connection['active'] = false;
GlpiBConnection::save($connection);
uuidRetry();
uuidCheck(uuidRun($client)['outcome'] === 'equal', 'Other eligible participants still receive an independent equal check.');
uuidCheck(AssetUuidOperation::state(AssetUuidOperation::find('Computer', 1))['sides'][$side] === $pendingGuard, 'Disabling a participant does not delete its pending timestamp guard.');
$connection['active'] = true;
GlpiBConnection::save($connection);
$client->items['one']['uuid'] = $u1;
unset($client->ignore['one']);
uuidRetry();
uuidRun($client);
uuidCheck(!empty(AssetUuidOperation::state(AssetUuidOperation::find('Computer', 1))['sides'][$side]['pending']), 'Re-enabled equal UUID retains crash-pending uncertainty.');
uuidCheck($side !== AssetUuidService::remoteSideKey('one', 10, 'https://different.invalid'), 'Endpoint replacement cannot reuse another asset history guard.');

$client = uuidFixture($u1, ['one' => '', 'two' => '']);
$connection = GlpiBConnection::find('two');
$connection['base_url'] = GlpiBConnection::find('one')['base_url'] . '/apirest.php/';
GlpiBConnection::save($connection);
uuidCheck(uuidRun($client)['outcome'] === 'conflict' && $client->calls === [], 'Known overlapping API aliases are rejected before remote reads.');

// Peripheral has no UUID search option. Audit transport failure cannot undo verified native success.
class Peripheral extends Computer
{
    public static function getTable(): string { return 'glpi_peripherals'; }
    public function getFromDB($id): bool { $row = $GLOBALS['DB']->firstRow(self::getTable(), ['id' => (int) $id]); $this->fields = $row ?? []; return $row !== null; }
    public function update(array $input): bool { $id = $input['id']; unset($input['id']); return $GLOBALS['DB']->update(self::getTable(), $input, ['id' => $id]); }
}
class Log
{
    public const HISTORY_LOG_SIMPLE_MESSAGE = 99;
    public static bool $throws = true;
    public static function history(...$args): bool { if (self::$throws) { throw new RuntimeException('Audit unavailable'); } return false; }
}
$client = uuidFixture('');
$DB->beforeUpdate = null;
$DB->tables['glpi_peripherals'] = $DB->tables['glpi_computers'];
Search::$nativeOptions = [];
$nativeRead = new ReflectionMethod(AssetUuidService::class, 'localSnapshot');
$nativeWrite = new ReflectionMethod(AssetUuidService::class, 'writeLocal');
foreach ([true, false] as $throws) {
    Log::$throws = $throws;
    $DB->tables['glpi_peripherals'][0]['uuid'] = '';
    $pre = $nativeRead->invoke(new AssetUuidService(), 'Peripheral', 1);
    $written = $nativeWrite->invoke(new AssetUuidService(), 'Peripheral', 1, $pre['item'], $u1);
    uuidCheck($written['success'] && $written['item']['uuid'] === $u1 && empty($written['audit_fallback']), 'Peripheral audit throw/false is separate from verified native success.');
}

foreach (['corrupt_state', 'uuid_query_failure', 'postwrite_query_failure', 'custom_corrupt_state'] as $failure) {
    uuidFixture($u1, ['one' => $u1]);
    \GlpiPlugin\Assetsync20\AssetSyncQueue::install();
    $DB->beforeUpdate = null;
    $DB->tables['glpi_computers'][0]['otherserial'] = '';
    AssetUuidOperation::bootstrap(hrtime(true) + 1_000_000_000);
    $DB->tables[AssetUuidOperation::TABLE][0]['state_json'] = in_array($failure, ['corrupt_state', 'custom_corrupt_state'], true) ? '{broken' : '{}';
    $remote = new FakeGlpiBClient();
    $remote->records['one:Computer'][10] = ['id' => 10, 'entities_id' => 5, 'uuid' => $u1, 'name' => $failure === 'postwrite_query_failure' ? 'Asset' : 'Remote',
        'serial' => 'Serial', 'otherserial' => 'Inbound inventory', 'comment' => 'Remote comment', 'date_mod' => '2026-10-02 12:00:00', 'is_deleted' => 0];
    $both = 'name';
    if ($failure === 'custom_corrupt_state') {
        $both = 'Computer.PluginFieldsComputerdmosasset.namefield';
        PluginFieldsContainer::$options = [1810 => ['name' => 'Custom name', 'table' => 'glpi_plugin_fields_computerdmosassets',
            'field' => 'namefield', 'pfields_type' => 'text', 'pfields_fields_id' => 1]];
        PluginFieldsComputerdmosasset::$rows = [['id' => 1, 'items_id' => 1, 'itemtype' => 'Computer', 'namefield' => 'Local custom']];
        $remote->customRecords['one:Computer'][10][$both] = 'Remote custom';
    }
    $rows = [];
    foreach ([[$both, 'both'], ['comment', 'glpi_a'], ['otherserial', 'glpi_b']] as [$field, $authority]) {
        $rows[] = ['glpi_a_field_key' => $field, 'glpi_b_field_key' => $field, 'source_of_truth' => $authority];
    }
    Config::$values['plugin:assetsync20']['field_mappings'] = json_encode(['one' => ['Computer' => ['version' => 2, 'rows' => $rows]]]);
    $service = new AssetSyncService($remote);
    uuidCheck($service->queueAssetIfNeeded('Computer', 1, 'one'), 'Ordinary fixture queues: ' . $failure);
    $DB->afterRequest = static function (array $query) use ($failure, $remote): void {
        $afterWrite = in_array('updateItem', array_column($remote->requests, 'method'), true);
        if (($query['FROM'] ?? '') === AssetUuidOperation::TABLE
            && ($failure === 'uuid_query_failure' || ($failure === 'postwrite_query_failure' && $afterWrite))) {
            throw new RuntimeException('UUID evidence query unavailable');
        }
    };
    $service->processQueue(1);
    $DB->afterRequest = null;
    uuidCheck($remote->records['one:Computer'][10]['comment'] === 'PRIVATE COMMENT MUST NOT PERSIST'
        && $DB->tables['glpi_computers'][0]['otherserial'] === 'Inbound inventory', 'UUID evidence failure preserves remote and local one-way writes: ' . $failure);
    $link = AssetSyncLink::find('Computer', 1, 'one');
    uuidCheck($link['status'] === ($failure === 'postwrite_query_failure' ? AssetSyncLink::STATUS_SYNCED : AssetSyncLink::STATUS_BLOCKED_UUID_TIME), 'Only differing Both is deferred; verified ordinary writes stay successful: ' . $failure);
    uuidCheck($remote->customHistoryRequests === [], 'UUID-deferred custom Both cannot demand history before one-way writes.');
    PluginFieldsContainer::$options = [];
}
echo 'UUID service tests passed (' . $uuidChecks . " checks).\n";
