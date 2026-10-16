<?php
declare(strict_types=1);

namespace Glpi\Security {
    final class TOTPManager
    {
        public const ENFORCEMENT_OPTIONAL = 0;
        public static bool $enabled = false;
        public static int $enforcement = 0;
        public static bool $unavailable = false;
        public function is2FAEnabled(int $id): bool {
            if (self::$unavailable) { throw new \RuntimeException('Unreadable MFA policy'); }
            return self::$enabled;
        }
        public function get2FAEnforcement(int $id): int { return self::$enforcement; }
    }
}
namespace Glpi\Http {
    final class SessionManager {
        public static array $patterns = [];
        public static function registerPluginStatelessPath(string $plugin, string $pattern): void { self::$patterns[] = $pattern; }
    }
    final class Firewall {
        public const STRATEGY_NO_CHECK = 'no_check';
        public static array $patterns = [];
        public static function addPluginStrategyForLegacyScripts(string $plugin, string $pattern, string $strategy): void { self::$patterns[] = $pattern; }
    }
}
namespace GlpiPlugin\Assetsync20 {
    final class WakeWire
    {
        public static int $calls = 0;
        public static array $options = [];
        public static string $fault = '';
        public static int $wallOffset = 0;
        public static int $clockOffset = 0;
        public static ?\Closure $onSend = null;
    }
    function time(): int { return \time() + WakeWire::$wallOffset; }
    function hrtime(bool $number): int { return \hrtime(true) + WakeWire::$clockOffset; }
    function curl_init(string $url): object { WakeWire::$calls++; return (object) ['url' => $url]; }
    function curl_setopt_array(object $c, array $options): bool { WakeWire::$options = $options; return true; }
    function curl_exec(object $c): bool {
        $headers = WakeWire::$options[CURLOPT_HTTPHEADER];
        $lifecycle = in_array('X-Assetsync-Operation: initSession', $headers, true) ? 'init'
            : (in_array('X-Assetsync-Operation: killSession', $headers, true) ? 'kill' : '');
        if ($lifecycle !== '') {
            $body = $lifecycle === 'init' ? '{"session_token":"native-test-session"}' : '{}';
            return (WakeWire::$options[CURLOPT_WRITEFUNCTION])($c, $body) === strlen($body);
        }
        if (WakeWire::$onSend !== null) { (WakeWire::$onSend)(); }
        $request = json_decode(WakeWire::$options[CURLOPT_POSTFIELDS], true);
        $response = ['version' => 1, 'state' => 'completed'] + array_intersect_key($request, array_flip(['installation', 'registration', 'generation', 'connection_id']));
        if (WakeWire::$fault === 'binding') { $response['generation'] = str_repeat('0', 32); }
        $body = WakeWire::$fault === 'oversize' ? str_repeat('x', 2049) : json_encode($response);
        return (WakeWire::$options[CURLOPT_WRITEFUNCTION])($c, $body) === strlen($body);
    }
    function curl_getinfo(object $c, int $option): int { return WakeWire::$fault === 'redirect' ? 302 : 200; }
    function curl_close(object $c): void {}
}
namespace {
    ob_start();
    require __DIR__ . '/agent-both.php';
    use GlpiPlugin\Assetsync20\AgentWake;
    use GlpiPlugin\Assetsync20\AgentTask;
    use GlpiPlugin\Assetsync20\GlpiBConnection;
    use GlpiPlugin\Assetsync20\WakeWire;
    use GlpiPlugin\Assetsync20agent\Wake;
    use GlpiPlugin\Assetsync20agent\Settings;
    use GlpiPlugin\Assetsync20agent\Worker;
    use GlpiPlugin\Assetsync20agent\ServiceContext;
    use Glpi\Security\TOTPManager;

    function wakeReject(callable $operation, string $message): void
    {
        try { $operation(); }
        catch (RuntimeException | InvalidArgumentException | JsonException) { scalarCheck(true, $message); return; }
        throw new LogicException($message);
    }

    final class WakeDB extends ScalarDB
    {
        public int $runOwned = 0;
        public int $transaction = 0;
        public int|string|null $autocommit = 1;
        public string $guardFault = '';
        public int $lastError = 0;
        public array $guardQueries = [];
        public int|string|null $connectionId = 17;
        public array $events = [];
        public bool $failRelease = false;
        public function errno(): int { return $this->lastError; }
        public function doQuery($sql): mixed {
            $this->events[] = $sql;
            if ($sql === 'SELECT DATABASE() AS database_name') { return $this->sql->pdo->query("SELECT 'wake_test' AS database_name"); }
            if (str_starts_with($sql, 'SELECT COALESCE(IS_USED_LOCK(')) { return $this->sql->pdo->query('SELECT ' . $this->runOwned . ' AS owned'); }
            if ($sql === 'SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS connection_id') {
                if ($this->guardFault === 'autocommit') { throw new RuntimeException('Autocommit unavailable'); }
                return $this->sql->pdo->query('SELECT ' . ($this->autocommit === null ? 'NULL' : $this->sql->pdo->quote((string) $this->autocommit))
                    . ' AS enabled, ' . ($this->connectionId === null ? 'NULL' : $this->sql->pdo->quote((string) $this->connectionId)) . ' AS connection_id');
            }
            if ($sql === 'SELECT CONNECTION_ID() AS connection_id') {
                $this->lastError = 0;
                if ($this->guardFault === 'post_loss') { throw new RuntimeException('Connection lost'); }
                if ($this->guardFault === 'post_empty') { return $this->sql->pdo->query('SELECT 1 WHERE 0'); }
                $id = $this->guardFault === 'reconnected' ? 18 : $this->connectionId;
                return $this->sql->pdo->query('SELECT ' . ($id === null ? 'NULL' : $this->sql->pdo->quote((string) $id)) . ' AS connection_id');
            }
            if (str_starts_with($sql, 'SAVEPOINT as20_wake_')) {
                $this->guardQueries[] = $sql;
                $this->lastError = 1305;
                if ($this->guardFault === 'save_false') { return false; }
                if ($this->guardFault === 'save_null') { return null; }
                if ($this->guardFault === 'save_malformed') { return $this->sql->pdo->query('SELECT 1'); }
                if ($this->guardFault === 'save_throw') { throw new RuntimeException('Savepoint failed'); }
                $this->lastError = 0;
                return true;
            }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_wake_')) {
                $this->guardQueries[] = $sql;
                $this->lastError = $this->guardFault === 'release_other' ? 2013 : 1305;
                if ($this->guardFault === 'release_null') { return null; }
                if ($this->guardFault === 'release_zero') { $this->lastError = 0; return false; }
                if ($this->guardFault === 'release_false') { return false; }
                if ($this->guardFault === 'release_other' || !$this->transaction) { throw new RuntimeException('Release failed'); }
                $this->lastError = 0;
                return true;
            }
            if (str_starts_with($sql, 'SELECT GET_LOCK(')) {
                if (str_contains($sql, 'assetsync20:run:')) { $this->runOwned++; }
                return $this->sql->pdo->query('SELECT 1 AS acquired');
            }
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK(')) {
                if ($this->failRelease && str_contains($sql, 'assetsync20:run:')) { return $this->sql->pdo->query('SELECT 0 AS released'); }
                if (str_contains($sql, 'assetsync20:run:')) { $this->runOwned = max(0, $this->runOwned - 1); }
                return $this->sql->pdo->query('SELECT 1 AS released');
            }
            return parent::doQuery($sql);
        }
    }
    [$settings, $request, $token] = scalarControllerSetup('textfield', 'glpi_a', 'same', 'same');
    $sql = $DB->sql; $DB = new WakeDB(); $DB->sql = $sql;
    $settings = Settings::load();
    $settings['mode'] = 'agent';
    $settings['endpoint'] = 'https://controller.invalid/plugins/assetsync20/front/agent-notify.php';
    Config::$values[Settings::CONTEXT] = $settings;
    User::$httpFields = ['2fa' => null, '2fa_unenforced' => 0];
    $CFG_GLPI['2fa_enforced'] = 0;
    Settings::configureWake(true);
    $wakeToken = new \GlpiPlugin\Assetsync20agent\AgentApi('b');
    $wake = ['version' => 1, 'kind' => 'wake'] + $request;
    Wake::authorize($wake, Settings::load(), $wakeToken);
    scalarCheck(Settings::load()['wake_enabled'] === '1' && !isset(Settings::load()['wake_token_hash']), 'Native wake needs no custom secret.');
    foreach (['version', 'extra', 'binding', 'token', 'disabled', 'password', 'profile'] as $case) {
        $r = $wake; $s = Settings::load(); $t = $wakeToken;
        if ($case === 'version') { $r['version'] = 2; }
        if ($case === 'extra') { $r['items_id'] = 201; }
        if ($case === 'binding') { $r['generation'] = str_repeat('0', 32); }
        if ($case === 'token') { $t = new \GlpiPlugin\Assetsync20agent\AgentApi('b', false); }
        if ($case === 'disabled') { unset($s['wake_enabled']); }
        if ($case === 'password') { $s['service_password'] = 'changed'; }
        if ($case === 'profile') { $s['service_profile'] = '999'; }
        wakeReject(static fn () => Wake::authorize($r, $s, $t), 'Wake rejects ' . $case . ' before work.');
    }
    Settings::configureWake(false);
    wakeReject(static fn () => Wake::authorize($wake, Settings::load(), $wakeToken), 'Disable revokes native wake admission.');
    Settings::configureWake(true);
    $newToken = new \GlpiPlugin\Assetsync20agent\AgentApi('b');

    User::$httpFields = ['2fa' => null, '2fa_unenforced' => 0];
    $CFG_GLPI['2fa_enforced'] = 0;
    ServiceContext::httpAccount((int) $settings['service_user']);
    foreach (['enabled', 'enforced', 'unreadable', 'persisted', 'malformed_policy'] as $case) {
        TOTPManager::$enabled = $case === 'enabled';
        TOTPManager::$enforcement = $case === 'enforced' ? 1 : 0;
        TOTPManager::$unavailable = $case === 'unreadable';
        User::$httpFields['2fa'] = $case === 'persisted' ? '{"secret":"unreadable"}' : null;
        $CFG_GLPI['2fa_enforced'] = $case === 'malformed_policy' ? 'unknown' : 0;
        wakeReject(static fn () => ServiceContext::httpAccount((int) $settings['service_user']), 'HTTP MFA fails closed: ' . $case);
        $beforeMfa = Config::$values;
        wakeReject(static fn () => Settings::configureWake(true), 'Setup refuses unsupported HTTP MFA: ' . $case);
        scalarCheck(Config::$values === $beforeMfa, 'MFA rejection does not enable wake or issue a key: ' . $case);
    }
    TOTPManager::$enabled = false; TOTPManager::$enforcement = 0; TOTPManager::$unavailable = false; $CFG_GLPI['2fa_enforced'] = 0;
    User::$httpFields['2fa'] = null;
    Config::$values[Settings::CONTEXT]['wake_last_attempt'] = 'corrupt';
    wakeReject(static fn () => Wake::receive($wake, $newToken), 'Malformed rate storage fails closed before task transport.');
    Config::$values[Settings::CONTEXT]['wake_last_attempt'] = '0'; Config::$failWakeWrites = true;
    wakeReject(static fn () => Wake::receive($wake, $newToken), 'Failed rate write/readback cannot admit work.');
    Config::$failWakeWrites = false;
    $wakeSettings = Settings::load();
    foreach (['mode', 'agent_api_url', 'service_profile', 'agent_user_token', 'wake_enabled'] as $key) {
        Config::$values[Settings::CONTEXT] = array_replace($wakeSettings, ['wake_last_attempt' => '0']);
        $readsAfterAdmission = 0;
        Config::$readHook = static function (string $context) use ($key, &$readsAfterAdmission): void {
            if ($context === Settings::CONTEXT && (Config::$values[$context]['wake_last_attempt'] ?? '0') !== '0'
                && ++$readsAfterAdmission === 3) {
                Config::$values[$context][$key] = $key === 'mode' ? 'rest' : 'changed';
            }
        };
        try {
            Wake::receive($wake, $newToken);
            throw new LogicException('Changed HTTP admission must abort: ' . $key);
        } catch (UnexpectedValueException $error) {
            scalarCheck($error->getMessage() === 'Admitted wake binding changed.', 'HTTP turn pins ' . $key . ' after admission.');
        } finally { Config::$readHook = null; }
        // The failed request must not leak its HTTP guard into a later CLI invocation.
        scalarCheck(Wake::settings() === Settings::load(), 'HTTP admission is cleared after failure: ' . $key);
    }
    Config::$values[Settings::CONTEXT] = $wakeSettings;
    $before = Config::$values;
    scalarCheck((new Worker())->run(hrtime(true) - 1) === 0 && (new Worker())->reconcile(hrtime(true) - 1) === 0
        && Config::$values === $before, 'Expired shared budgets cannot start tasks/reconciliation or renew a fresh local budget.');

    $url = 'https://b.invalid/plugins/assetsync20agent/front/agent-api.php';
    scalarCheck(\GlpiPlugin\Assetsync20\AgentSession::validUrl($url) && \GlpiPlugin\Assetsync20\AgentSession::validUrl(str_replace('/plugins/', '/marketplace/', $url)), 'Explicit plugin and marketplace wake aliases are accepted.');
    foreach (['http://b.invalid/plugins/assetsync20agent/front/agent-api.php', $url . '?token=x', $url . '/other',
        str_replace('b.invalid', 'u:p@b.invalid', $url)] as $bad) { scalarCheck(!\GlpiPlugin\Assetsync20\AgentSession::validUrl($bad), 'Unsafe wake URL rejected.'); }
    foreach (['123', 'b', 'c', 'd'] as $id) {
        GlpiBConnection::save(['id' => $id, 'mode' => 'agent', 'active' => true, 'base_url' => 'https://' . $id . '.invalid', 'wake_enabled' => true, 'agent_api_url' => $url, 'agent_auth' => 'glpi_session', 'agent_app_token' => 'app', 'agent_user_token' => 'user',
                'agent_peer_user' => '7', 'agent_peer_api_client' => '9', 'agent_peer_profile' => '4', 'agent_peer_entity' => '100']);
        if ($id !== 'b') { AgentTask::register($id, $settings['installation']); }
    }
    unset(Config::$values[GlpiBConnection::CONTEXT]['wake_phase']);
    $chosen = [];
    for ($i = 0; $i < 8; $i++) {
        scalarCheck(AgentWake::plan() === null, 'Ordinary turn retains its whole budget without reserving a wake.');
        $work = AgentWake::plan(true, hrtime(true) + 20_000_000_000); $chosen[] = $work['connection']['id'];
        WakeWire::$wallOffset += 10;
    }
    scalarCheck($chosen === ['123', 'b', 'c', 'd', '123', 'b', 'c', 'd']
        && is_string(Config::$values[GlpiBConnection::CONTEXT]['wake_cursor']), 'Wake turns rotate string IDs including numeric IDs and retain a string cursor.');
    Config::$values[GlpiBConnection::CONTEXT]['wake_phase'] = 'wake'; Config::$failWakeWrites = true;
    scalarCheck(AgentWake::plan() === ['deferred' => true], 'Failed reservation readback defers without HTTP.');
    Config::$failWakeWrites = false;
    $work = AgentWake::plan();
    $result = AgentWake::send($work, hrtime(true) + 900_000_000);
    scalarCheck($result === ['status' => 200, 'state' => 'completed'] && WakeWire::$calls === 3, 'Pinned current binding sends one wake plus two native lifecycle calls after lock release.');
    $options = WakeWire::$options;
    AgentWake::complete($work, $result, hrtime(true) + 1_000_000_000);
    $retry = json_decode(Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'], true)[$work['connection']['id']];
    scalarCheck($retry['failures'] === 0 && $retry['next_attempt'] >= \GlpiPlugin\Assetsync20\time() + 10,
        'Completed wake resets outage count and retains the B ten-second admission floor.');
    scalarCheck($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2 && !$options[CURLOPT_FOLLOWLOCATION]
        && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $options[CURLOPT_TIMEOUT_MS] <= 900, 'Wake transport verifies TLS and obeys the inherited deadline without redirects.');
    foreach (['lock', 'transaction', 'deadline'] as $case) {
        $DB->runOwned = $case === 'lock' ? 1 : 0; $DB->transaction = $case === 'transaction' ? 1 : 0;
        $calls = WakeWire::$calls;
        scalarCheck(AgentWake::send($work, $case === 'deadline' ? hrtime(true) - 1 : hrtime(true) + 1_000_000_000)['state'] === 'deferred'
            && WakeWire::$calls === $calls, 'Wake cannot dispatch under ' . $case . '.');
    }
    $DB->runOwned = 0; $DB->transaction = 0;
    foreach (['autocommit', 'save_false', 'save_null', 'save_throw', 'save_malformed', 'release_zero', 'release_null', 'release_other', 'post_loss', 'post_empty', 'reconnected'] as $fault) {
        $DB->guardFault = $fault;
        $before = Config::$values; $calls = WakeWire::$calls;
        scalarCheck(!AgentWake::transactionIdle() && AgentWake::plan() === ['deferred' => true] && Config::$values === $before,
            'Guard failure prevents planning writes: ' . $fault);
        scalarCheck(AgentWake::send($work, hrtime(true) + 1_000_000_000)['state'] === 'deferred' && WakeWire::$calls === $calls,
            'Guard failure prevents HTTP: ' . $fault);
    }
    $DB->guardFault = 'release_false';
    scalarCheck(AgentWake::transactionIdle(), 'False RELEASE result with immediate errno 1305 and successful SAVEPOINT proves idle.');
    $DB->guardFault = '';
    foreach ([null, '', 'invalid', 0] as $id) {
        $DB->connectionId = $id; $before = Config::$values;
        scalarCheck(!AgentWake::transactionIdle() && AgentWake::plan() === ['deferred' => true] && Config::$values === $before,
            'Unavailable pre-probe connection identity fails closed.');
    }
    $DB->connectionId = '00017';
    scalarCheck(AgentWake::transactionIdle(), 'Canonical decimal connection IDs are compared without integer overflow.');
    $DB->connectionId = 17;
    foreach ([0, '0', null, 'invalid'] as $autocommit) {
        $DB->autocommit = $autocommit; $before = Config::$values; $queries = $DB->guardQueries;
        scalarCheck(AgentWake::plan() === ['deferred' => true] && AgentWake::plan() === ['deferred' => true] && Config::$values === $before
            && $DB->guardQueries === $queries, 'Repeated unsupported autocommit does not change settings or issue SAVEPOINT.');
    }
    $DB->autocommit = 1;
    $DB->transaction = 1; $before = Config::$values;
    scalarCheck(AgentWake::plan() === ['deferred' => true] && Config::$values === $before, 'Active transaction defers the whole wake turn without reservation writes.');
    $calls = WakeWire::$calls;
    scalarCheck((new \GlpiPlugin\Assetsync20\AssetSyncService())->run() === 0
        && Config::$values === $before && WakeWire::$calls === $calls && $DB->transaction === 1,
        'Full service run with borrowed transaction defers without ordinary work, reservation or HTTP.');
    $failedEligibilityRead = false;
    $DB->sql->pdo->exec('CREATE TABLE caller_read_fault (id INTEGER PRIMARY KEY, value INTEGER)');
    $DB->sql->pdo->exec('INSERT INTO caller_read_fault VALUES (1,7)');
    $DB->sql->pdo->beginTransaction();
    $DB->sql->pdo->exec('UPDATE caller_read_fault SET value=19 WHERE id=1');
    Config::$readHook = static function (string $context) use (&$failedEligibilityRead): void {
        if ($context === GlpiBConnection::CONTEXT && !$failedEligibilityRead) {
            $failedEligibilityRead = true;
            throw new RuntimeException('Transient wake eligibility read failure');
        }
    };
    try {
        scalarCheck((new \GlpiPlugin\Assetsync20\AssetSyncService())->run() === 0 && $failedEligibilityRead
            && Config::$values === $before && $DB->transaction === 1 && WakeWire::$calls === $calls,
            'A failed eligibility read still fences the whole borrowed-transaction run before ordinary processing.');
        scalarCheck($DB->sql->pdo->inTransaction()
            && (int) $DB->sql->pdo->query('SELECT value FROM caller_read_fault')->fetchColumn() === 19,
            'Transient eligibility failure preserves the real foreign SQLite sentinel without commit or rollback.');
    } finally { Config::$readHook = null; $DB->sql->pdo->rollBack(); }
    $DB->transaction = 0; $bindingReads = 0; $calls = WakeWire::$calls;
    Config::$readHook = static function (string $context) use (&$bindingReads, $DB): void {
        if ($context === GlpiBConnection::CONTEXT && ++$bindingReads === 2) { $DB->transaction = 1; }
    };
    try {
        scalarCheck(AgentWake::send($work, hrtime(true) + 1_000_000_000)['state'] === 'deferred' && WakeWire::$calls === $calls,
            'Last guard detects a transaction started during binding reads.');
    } finally { Config::$readHook = null; $DB->transaction = 0; }
    foreach ($DB->guardQueries as $index => $query) {
        if (str_starts_with($query, 'RELEASE ')) {
            scalarCheck($index > 0 && $query === 'RELEASE ' . $DB->guardQueries[$index - 1],
                'Guard releases only the freshly created private marker.');
        }
    }
    foreach (['binding', 'oversize', 'redirect'] as $fault) {
        WakeWire::$fault = $fault;
        scalarCheck(AgentWake::send($work, hrtime(true) + 1_000_000_000)['state'] === 'unavailable', 'Wake failure is not sync verification: ' . $fault);
    }
    WakeWire::$fault = '';
    $numeric = GlpiBConnection::find('123');
    GlpiBConnection::save(array_replace($numeric, ['wake_enabled' => false]));
    Config::$values[GlpiBConnection::CONTEXT]['wake_phase'] = 'ordinary';
    unset(Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'], Config::$values[GlpiBConnection::CONTEXT]['wake_cursor']);
    $outageDelays = []; $healthy = ['c' => 0, 'd' => 0];
    for ($turn = 0; $turn < 30; $turn++) {
        scalarCheck(AgentWake::plan() === null, 'Every nonstarved turn admits full ordinary work first.');
        $attempt = AgentWake::plan(true, hrtime(true) + 20_000_000_000);
        scalarCheck($attempt !== null && empty($attempt['deferred']), 'A due peer receives the leftover wake reservation.');
        $id = $attempt['connection']['id'];
        $response = $id === 'b' ? ['status' => 0, 'state' => 'unavailable'] : ['status' => 200, 'state' => 'busy'];
        AgentWake::complete($attempt, $response, hrtime(true) + 1_000_000_000);
        $entry = json_decode(Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'], true)[$id];
        $delay = $entry['next_attempt'] - \GlpiPlugin\Assetsync20\time();
        if ($id === 'b') { $outageDelays[] = $delay; }
        else { $healthy[$id]++; scalarCheck($entry['failures'] === 0 && $delay >= 10, 'Busy is liveness, with the unchanged admission floor.'); }
        WakeWire::$wallOffset += 10;
    }
    scalarCheck(array_slice($outageDelays, 0, 5) === [10, 20, 40, 60, 60] && min($healthy) >= 8,
        'One outage backs off 10/20/40/60 while both other connections retain fair turns.');
    $before = Config::$values;
    scalarCheck(AgentWake::plan(true, hrtime(true) + 10_000_000_000) === null
        && Config::$values[GlpiBConnection::CONTEXT]['wake_phase'] === 'wake'
        && Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'] === $before[GlpiBConnection::CONTEXT]['wake_backoff'],
        'Insufficient leftover time schedules a wake-only fallback without claiming an attempt.');
    $fallback = AgentWake::plan(false, hrtime(true) + 25_000_000_000);
    scalarCheck($fallback !== null && empty($fallback['deferred']) && Config::$values[GlpiBConnection::CONTEXT]['wake_phase'] === 'ordinary',
        'Deferred wake-only turn returns immediately to ordinary-first scheduling.');
    $reserved = Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'];
    $stale = $fallback; $stale['attempt']['marker'] = str_repeat('0', 32);
    AgentWake::complete($stale, ['status' => 200, 'state' => 'completed'], hrtime(true) + 1_000_000_000);
    scalarCheck(Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'] === $reserved, 'A stale response cannot replace a newer attempt marker.');
    $calls = WakeWire::$calls;
    WakeWire::$onSend = static function () use ($DB): void {
        scalarCheck($DB->runOwned === 0 && $DB->transaction === 0 && !\GlpiPlugin\Assetsync20\AssetUuidOperation::hasAssetLocks(),
            'HTTP begins after all run/asset locks and transaction state are released.');
    };
    AgentWake::send($fallback, hrtime(true) + 1_000_000_000);
    WakeWire::$onSend = null;
    scalarCheck(WakeWire::$calls === $calls + 3, 'Fallback sends one bounded wake plus two native lifecycle calls.');
    $old = json_decode(Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'], true);
    $changed = GlpiBConnection::find($fallback['connection']['id']);
    GlpiBConnection::save(array_replace($changed, ['agent_user_token' => 'changed-native-token']));
    AgentWake::plan();
    $clean = json_decode(Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'], true);
    scalarCheck(!isset($clean[$changed['id']]) && !isset($clean['123']), 'Rotated keys and disabled connections lose stale retry records.');
    Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'] = '{corrupt';
    wakeReject(static fn () => AgentWake::plan(), 'Malformed retry JSON fails closed, without HTTP.');
    Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'] = json_encode((object) $old);
    if (!class_exists('Toolbox')) {
        class Toolbox {
            public static array $entries = [];
            public static function logInFile(string $file, string $entry): void { self::$entries[] = $entry; }
        }
    }
    foreach ([25, 60] as $budget) {
        $DB = new WakeDB(); Config::$values = [];
        WakeWire::$clockOffset = 0;
        \GlpiPlugin\Assetsync20\AgentInbox::install();
        AgentTask::install();
        \GlpiPlugin\Assetsync20\AssetSyncQueue::install();
        \GlpiPlugin\Assetsync20\AssetSyncLink::install();
        GlpiBConnection::save(['id' => 'cadence', 'mode' => 'agent', 'active' => true, 'base_url' => 'https://b.invalid',
            'wake_enabled' => true, 'agent_api_url' => $url, 'agent_auth' => 'glpi_session', 'agent_app_token' => 'app', 'agent_user_token' => 'user',
                'agent_peer_user' => '7', 'agent_peer_api_client' => '9', 'agent_peer_profile' => '4', 'agent_peer_entity' => '100']);
        AgentTask::register('cadence', $settings['installation']);
        $calls = WakeWire::$calls;
        WakeWire::$onSend = static function () use ($DB): void {
            scalarCheck($DB->runOwned === 0 && $DB->transaction === 0, 'Whole-run leftover HTTP starts outside the mutex and transaction.');
        };
        $service = new \GlpiPlugin\Assetsync20\AssetSyncService();
        scalarCheck($service->run(1, $budget) === 0 && WakeWire::$calls === $calls + 3,
            'An empty ordinary pass wakes in the same cycle instead of waiting for an alternating phase: ' . $budget);
        $metrics = json_decode(substr(array_pop(Toolbox::$entries), strlen('AssetSync2.0 run ')), true);
        scalarCheck($metrics['stop_reason'] === 'no_due_jobs' && $metrics['wake']['state'] === 'completed'
            && WakeWire::$options[CURLOPT_TIMEOUT_MS] <= 10000, 'Ordinary metrics and HTTP cap retain the original budget: ' . $budget);
        WakeWire::$wallOffset += 10; $reads = 0; $calls = WakeWire::$calls;
        Config::$readHook = static function (string $context) use (&$reads, $budget): void {
            if ($context === GlpiBConnection::CONTEXT && ++$reads === 4) { WakeWire::$clockOffset += $budget * 1_000_000_000; }
        };
        try { $service->run(1, $budget); }
        finally { Config::$readHook = null; }
        scalarCheck($reads >= 4 && WakeWire::$calls === $calls && Config::$values[GlpiBConnection::CONTEXT]['wake_phase'] === 'wake',
            'Full ordinary budget pressure defers wake without shortening work: ' . $budget);
        $service->run(1, $budget);
        $metrics = json_decode(substr(array_pop(Toolbox::$entries), strlen('AssetSync2.0 run ')), true);
        scalarCheck(WakeWire::$calls === $calls + 3 && $metrics['stop_reason'] === 'wake_turn'
            && Config::$values[GlpiBConnection::CONTEXT]['wake_phase'] === 'ordinary', 'Next turn is one wake-only fallback then ordinary resumes: ' . $budget);
        WakeWire::$onSend = null;
        $cadenceValues = Config::$values;
        Config::$values[GlpiBConnection::CONTEXT]['wake_backoff'] = '{corrupt';
        $calls = WakeWire::$calls;
        $service->run(1, $budget);
        $metrics = json_decode(substr(array_pop(Toolbox::$entries), strlen('AssetSync2.0 run ')), true);
        scalarCheck($metrics['stop_reason'] === 'no_due_jobs' && $metrics['wake']['state'] === 'unavailable'
            && str_contains($metrics['wake']['message'], 'ordinary synchronization continues') && WakeWire::$calls === $calls,
            'Malformed wake state reports a plain error but the whole ordinary pass proceeds: ' . $budget);
        $connection = GlpiBConnection::find('cadence');
        GlpiBConnection::save(array_replace($connection, ['wake_enabled' => false]));
        $service->run(1, $budget);
        $metrics = json_decode(substr(array_pop(Toolbox::$entries), strlen('AssetSync2.0 run ')), true);
        scalarCheck($metrics['stop_reason'] === 'no_due_jobs' && WakeWire::$calls === $calls,
            'Disabling the last wake recovers ordinary work despite malformed retained state: ' . $budget);

        Config::$values[GlpiBConnection::CONTEXT] = ['glpib_name' => 'Legacy REST',
            'glpib_base_url' => 'https://legacy.invalid', 'glpib_active' => '0'];
        $DB->transaction = 1; $DB->guardFault = 'autocommit';
        $before = Config::$values; $probes = count($DB->guardQueries);
        scalarCheck(AgentWake::plan() === null && Config::$values === $before && count($DB->guardQueries) === $probes,
            'Legacy-only wake eligibility never migrates settings or probes unknown TX state: ' . $budget);
        $DB->sql->pdo->exec('CREATE TABLE caller_wake_sentinel (id INTEGER PRIMARY KEY, value INTEGER)');
        $DB->sql->pdo->exec('INSERT INTO caller_wake_sentinel VALUES (1,7)');
        $DB->sql->pdo->beginTransaction();
        try {
            $DB->sql->pdo->exec('UPDATE caller_wake_sentinel SET value=19 WHERE id=1');
            $service->run(1, $budget);
            $metrics = json_decode(substr(array_pop(Toolbox::$entries), strlen('AssetSync2.0 run ')), true);
            scalarCheck($DB->sql->pdo->inTransaction()
                && (int) $DB->sql->pdo->query('SELECT value FROM caller_wake_sentinel')->fetchColumn() === 19
                && $metrics['stop_reason'] === 'no_due_jobs' && WakeWire::$calls === $calls,
                'Whole legacy REST run preserves the foreign sentinel and does not become a wake deferral: ' . $budget);
        } finally {
            $DB->sql->pdo->rollBack(); $DB->transaction = 0; $DB->guardFault = '';
            Config::$values = $cadenceValues;
        }
    }
    WakeWire::$clockOffset = 0;
    require __DIR__ . '/../assetsync20agent/setup.php';
    plugin_assetsync20agent_boot();
    foreach (array_merge(\Glpi\Http\SessionManager::$patterns, \Glpi\Http\Firewall::$patterns) as $pattern) {
        scalarCheck((preg_match($pattern, '/front/agent-api.php') === 1 || preg_match($pattern, '/front/agent-api.php') === 1)
            && preg_match($pattern, '/front/agent-api.php/other') === 0 && preg_match($pattern, '/front/config.php') === 0
            && preg_match($pattern, '/front/agent-api.php/other') === 0 && preg_match($pattern, '/front/agent-api.phpx') === 0,
            'Actual boot registration exempts only the exact machine routes.');
    }
    WakeWire::$wallOffset += 10;
    $attempt = AgentWake::plan(true, hrtime(true) + 20_000_000_000);
    $before = Config::$values; Config::$failWakeWrites = true; $DB->failRelease = true;
    $errorMessage = '';
    try { AgentWake::complete($attempt, ['status' => 200, 'state' => 'completed'], hrtime(true) + 1_000_000_000); }
    catch (RuntimeException $error) { $errorMessage = $error->getMessage(); }
    scalarCheck($errorMessage === 'Wake completion storage unavailable.' && Config::$values === $before,
        'Failed completion storage retains the finite reservation and preserves its error across failed cleanup.');
    scalarCheck(\GlpiPlugin\Assetsync20\AssetUuidOperation::hasAssetLocks() && AgentWake::plan() === ['deferred' => true],
        'Uncertain completion mutex release permanently fences this process.');
    ob_end_clean();
    echo "Agent wake tests passed ({$checks} checks including native Both/scalar prerequisites; HTTP qualification separate).\n";
}
