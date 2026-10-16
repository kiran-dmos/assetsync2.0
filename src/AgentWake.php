<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Use ordinary leftover time for wakes, with a wake-only fallback under budget pressure. */
final class AgentWake
{
    private const MINIMUM_WINDOW_NS = 11_000_000_000;
    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('#/(plugins|marketplace)/assetsync20agent/front/agent-wake\\.php$#D', $parts['path'] ?? '') === 1
            && !preg_match('/[\x00-\x20\x7f]/', $url);
    }

    /** Probe the current connection without ending any caller-owned transaction. */
    public static function transactionIdle(): bool
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'errno')) { return false; }
        try {
            $autocommit = AgentInbox::one('SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS connection_id');
            if (!in_array($autocommit['enabled'] ?? null, [1, '1'], true)) { return false; }
            $connectionId = $autocommit['connection_id'] ?? null;
            if (!is_int($connectionId) && !is_string($connectionId)) { return false; }
            $connectionId = ltrim((string) $connectionId, '0');
            if ($connectionId === '' || !ctype_digit($connectionId)) { return false; }
            $name = 'as20_wake_' . bin2hex(random_bytes(16));
            if ($DB->doQuery('SAVEPOINT ' . $name) !== true) { return false; }
            try {
                $released = $DB->doQuery('RELEASE SAVEPOINT ' . $name);
                $errorCode = $DB->errno();
            } catch (\Throwable) {
                $errorCode = $DB->errno();
                $released = false;
            }
            if ($released !== false || $errorCode !== 1305) { return false; }
            $current = AgentInbox::one('SELECT CONNECTION_ID() AS connection_id');
            $currentId = $current['connection_id'] ?? null;
            return (is_int($currentId) || is_string($currentId)) && ltrim((string) $currentId, '0') === $connectionId;
        } catch (\Throwable) { return false; }
    }

    /** Called under the existing A run lock. A single persistent cursor rotates idle connections too. */
    public static function plan(bool $afterOrdinary = false, ?int $deadline = null): ?array
    {
        // A web request cannot wait for a callback that needs the same web worker.
        if (PHP_SAPI !== 'cli') { return null; }
        $connections = [];
        // Wake eligibility is read-only; the ordinary loader may migrate legacy REST settings.
        foreach (GlpiBConnection::loadAll(false, false) as $connection) {
            if ($connection['active'] && GlpiBConnection::isAgent($connection) && !empty($connection['wake_enabled'])
                && (($connection['agent_auth'] ?? 'legacy_bearer') === 'glpi_session'
                    ? AgentSession::validUrl($connection['agent_api_url'] ?? '')
                    : (self::validUrl($connection['wake_url'] ?? '') && preg_match('/^[a-f0-9]{64}$/D', $connection['wake_token'] ?? '')))) {
                try {
                    $binding = AgentTask::binding($connection['id']);
                    if (!$binding) { continue; }
                    $registration = AgentTask::registration($binding);
                    $connections[$connection['id']] = ['connection' => $connection, 'request' => ['version' => 1, 'kind' => 'wake',
                        'installation' => $binding['installation'], 'registration' => $registration['id'],
                        'generation' => $registration['generation'], 'connection_id' => $connection['id']]];
                } catch (\RuntimeException) { continue; }
            }
        }
        $state = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['wake_phase', 'wake_cursor', 'wake_backoff']);
        if ($connections === [] && (!isset($state['wake_backoff']) || in_array($state['wake_backoff'], ['{}', '[]'], true))) { return null; }
        // An eligible wake with borrowed transaction/lock state must not fall into asset work.
        if (AssetUuidOperation::hasAssetLocks() || !self::transactionIdle()) {
            return $connections === [] ? null : ['deferred' => true];
        }
        $backoff = self::backoff($state);
        foreach ($backoff as $id => $entry) {
            if (!isset($connections[$id]) || $entry['binding'] !== self::fingerprint($connections[$id])) { unset($backoff[$id]); }
        }
        $cleaned = json_encode((object) $backoff, JSON_THROW_ON_ERROR);
        if ($cleaned !== ($state['wake_backoff'] ?? '{}')) {
            \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['wake_backoff' => $cleaned]);
        }
        $due = array_filter($connections, static fn (array $work): bool => ($backoff[$work['connection']['id']]['next_attempt'] ?? 0) <= time());
        if ($due === [] || (!$afterOrdinary && ($state['wake_phase'] ?? 'ordinary') !== 'wake')) { return null; }
        if ($deadline !== null && $deadline - hrtime(true) < self::MINIMUM_WINDOW_NS) {
            \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['wake_phase' => 'wake']);
            return null;
        }
        ksort($due, SORT_STRING);
        $chosen = (string) array_key_first($due);
        foreach (array_keys($due) as $id) {
            $id = (string) $id;
            if (strcmp($id, (string) ($state['wake_cursor'] ?? '')) > 0) { $chosen = $id; break; }
        }
        $failures = min(4, ($backoff[$chosen]['failures'] ?? 0) + 1);
        $entry = ['binding' => self::fingerprint($due[$chosen]), 'marker' => bin2hex(random_bytes(16)),
            'failures' => $failures, 'next_attempt' => time() + min(60, 10 * (2 ** ($failures - 1)))];
        $backoff[$chosen] = $entry;
        $reserved = ['wake_phase' => 'ordinary', 'wake_cursor' => $chosen,
            'wake_backoff' => json_encode((object) $backoff, JSON_THROW_ON_ERROR)];
        if (AssetUuidOperation::hasAssetLocks() || !self::transactionIdle()) { return ['deferred' => true]; }
        \Config::setConfigurationValues(GlpiBConnection::CONTEXT, $reserved);
        $saved = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, array_keys($reserved));
        foreach ($reserved as $key => $value) {
            if (($saved[$key] ?? null) !== $value) { return ['deferred' => true]; }
        }
        return $due[$chosen] + ['attempt' => $entry];
    }

    private static function fingerprint(array $work): string
    {
        return hash('sha256', json_encode([$work['request'], $work['connection']['wake_url'],
            hash('sha256', $work['connection']['wake_token'] ?? ''), AgentSession::fingerprint($work['connection'])], JSON_THROW_ON_ERROR));
    }

    private static function backoff(array $state): array
    {
        $raw = $state['wake_backoff'] ?? '{}';
        if (!is_string($raw) || strlen($raw) > 65536) { throw new \RuntimeException('Wake retry state unavailable.'); }
        $backoff = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($backoff)) { throw new \RuntimeException('Wake retry state unavailable.'); }
        foreach ($backoff as $entry) {
            if (!is_array($entry) || !preg_match('/^[a-f0-9]{64}$/D', $entry['binding'] ?? '')
                || !preg_match('/^[a-f0-9]{32}$/D', $entry['marker'] ?? '')
                || !is_int($entry['failures'] ?? null) || $entry['failures'] < 0 || $entry['failures'] > 4
                || !is_int($entry['next_attempt'] ?? null) || $entry['next_attempt'] < 0) {
                throw new \RuntimeException('Wake retry state unavailable.');
            }
        }
        return $backoff;
    }

    /** A late response can update only its own reservation, under the same nonblocking mutex. */
    public static function complete(array $work, array $response, int $deadline): void
    {
        if (PHP_SAPI !== 'cli' || !isset($work['attempt']) || hrtime(true) >= $deadline
            || AssetUuidOperation::hasAssetLocks() || !self::transactionIdle()) { return; }
        global $DB;
        $lock = $DB->quote('assetsync20:run:' . AssetSyncLockName::databaseHash($DB));
        $row = AgentInbox::one('SELECT GET_LOCK(' . $lock . ', 0) AS acquired');
        if (!in_array($row['acquired'] ?? null, [1, '1'], true)) { return; }
        $error = null;
        try {
            if (AssetUuidOperation::hasAssetLocks() || !self::transactionIdle()) { return; }
            $state = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['wake_backoff']);
            $backoff = self::backoff($state);
            $id = $work['connection']['id'];
            if (($backoff[$id] ?? null) !== $work['attempt']) { return; }
            $success = ($response['status'] ?? 0) === 200 && in_array($response['state'] ?? '', ['completed', 'busy'], true);
            $backoff[$id]['failures'] = $success ? 0 : $work['attempt']['failures'];
            $backoff[$id]['next_attempt'] = time() + ($success ? 10 : min(60, 10 * (2 ** ($backoff[$id]['failures'] - 1))));
            $values = ['wake_backoff' => json_encode((object) $backoff, JSON_THROW_ON_ERROR)];
            if (($response['state'] ?? '') === 'deferred') { $values['wake_phase'] = 'wake'; }
            if (hrtime(true) >= $deadline || AssetUuidOperation::hasAssetLocks() || !self::transactionIdle()) { return; }
            \Config::setConfigurationValues(GlpiBConnection::CONTEXT, $values);
            $saved = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, array_keys($values));
            foreach ($values as $key => $value) {
                if (($saved[$key] ?? null) !== $value) { throw new \RuntimeException('Wake completion storage unavailable.'); }
            }
        } catch (\Throwable $caught) {
            $error = $caught;
            throw $caught;
        } finally {
            try {
                $released = AgentInbox::one('SELECT RELEASE_LOCK(' . $lock . ') AS released');
                if (!in_array($released['released'] ?? null, [1, '1'], true)) { throw new \RuntimeException('Wake completion lock release unavailable.'); }
            } catch (\Throwable $cleanup) {
                AssetUuidOperation::markLockCleanupFailed();
                if ($error === null) { throw $cleanup; }
            }
        }
    }

    /** Wake responses are liveness only. Existing pull/results remain the sole write evidence. */
    public static function send(array $work, int $deadline): array
    {
        if (PHP_SAPI !== 'cli') { return ['status' => 0, 'state' => 'deferred']; }
        global $DB;
        $lock = 'assetsync20:run:' . AssetSyncLockName::databaseHash($DB);
        $owned = AgentInbox::one('SELECT COALESCE(IS_USED_LOCK(' . $DB->quote($lock) . ') = CONNECTION_ID(), 0) AS owned');
        if (!in_array($owned['owned'] ?? null, [0, '0'], true)) { return ['status' => 0, 'state' => 'deferred']; }
        if (AssetUuidOperation::hasAssetLocks()) { return ['status' => 0, 'state' => 'deferred']; }
        $connection = GlpiBConnection::find($work['connection']['id']);
        if (!$connection || !$connection['active'] || !GlpiBConnection::isAgent($connection) || empty($connection['wake_enabled'])
            || $connection['wake_url'] !== $work['connection']['wake_url'] || $connection['wake_token'] !== $work['connection']['wake_token']
            || AgentSession::fingerprint($connection) !== AgentSession::fingerprint($work['connection'])
            || (($connection['agent_auth'] ?? 'legacy_bearer') === 'glpi_session'
                ? !AgentSession::validUrl($connection['agent_api_url'] ?? '') : !self::validUrl($connection['wake_url']))
            || hrtime(true) >= $deadline) { return ['status' => 0, 'state' => 'deferred']; }
        $binding = AgentTask::binding($connection['id']);
        if (!$binding) { return ['status' => 0, 'state' => 'deferred']; }
        $registration = AgentTask::registration($binding);
        foreach (['installation' => $binding['installation'], 'registration' => $registration['id'], 'generation' => $registration['generation']] as $key => $value) {
            if ($work['request'][$key] !== $value) { return ['status' => 0, 'state' => 'deferred']; }
        }
        $retry = self::backoff(\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['wake_backoff']));
        $attempt = $retry[$connection['id']] ?? [];
        if (!isset($work['attempt']) || ($attempt['marker'] ?? null) !== $work['attempt']['marker']
            || ($attempt['binding'] ?? null) !== self::fingerprint($work)) { return ['status' => 0, 'state' => 'deferred']; }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        if (AssetUuidOperation::hasAssetLocks() || !self::transactionIdle() || hrtime(true) >= $deadline) {
            return ['status' => 0, 'state' => 'deferred'];
        }
        if (($connection['agent_auth'] ?? 'legacy_bearer') === 'glpi_session') {
            $response = AgentSession::send($connection, 'wake', $work['request'], $deadline);
            $body = $response['body'];
            if ($response['status'] !== 200 || ($body['version'] ?? null) !== 1
                || !in_array($body['state'] ?? '', ['completed', 'busy'], true)) {
                return ['status' => $response['status'], 'state' => 'unavailable'];
            }
            foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
                if (($body[$key] ?? null) !== $work['request'][$key]) { return ['status' => 0, 'state' => 'unavailable']; }
            }
            return ['status' => 200, 'state' => $body['state']];
        }
        $timeout = min(10000, max(1, (int) (($deadline - hrtime(true)) / 1_000_000)));
        $curl = curl_init($connection['wake_url']);
        $body = '';
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($work['request'], JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $connection['wake_token']],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2048) { return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            $ok = curl_exec($curl);
            $response = json_decode($body, true, 8);
            $status = $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status !== 200 || !is_array($response) || ($response['version'] ?? null) !== 1
                || !in_array($response['state'] ?? '', ['completed', 'busy'], true)) { return ['status' => $status, 'state' => 'unavailable']; }
            foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
                if (($response[$key] ?? null) !== $work['request'][$key]) { return ['status' => 0, 'state' => 'unavailable']; }
            }
            return ['status' => 200, 'state' => $response['state']];
        } finally { curl_close($curl); }
    }
}
