<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Worker
{
    public const WAKE_VERSION = 1;
    public function run(?int $deadline = null, ?\Closure $admit = null): int
    {
        global $DB;
        // HTTP wakes and CLI workers share one database-scoped lock.
        $lock = 'assetsync20agent:' . substr(hash('sha256', (string) ($DB->dbdefault ?? 'glpi')), 0, 32);
        $quotedLock = $DB->quote($lock);
        if ((int) (Outbox::one("SELECT GET_LOCK({$quotedLock}, 0) AS acquired")['acquired'] ?? 0) !== 1) {
            return 0;
        }
        try {
            $deadline = min($deadline ?? PHP_INT_MAX, hrtime(true) + 20_000_000_000);
            if (hrtime(true) >= $deadline) { return 0; }
            if ($admit !== null) { $admit(); }
            $settings = Wake::settings();
            if (($settings['mode'] ?? 'rest') === 'agent') {
                TaskJournal::cycle($settings, $deadline);
            }
            try {
                $this->reconcile($deadline);
            } catch (\UnexpectedValueException $error) {
                throw $error;
            } catch (\Throwable) {
                \Config::setConfigurationValues(Settings::CONTEXT, ['reconcile_status' => 'degraded']);
            }
            $settings = Wake::settings();
            if (hrtime(true) < $deadline && Settings::validEndpoint($settings['endpoint'] ?? '') && !empty($settings['token'])
                && (time() - (int) ($settings['health_attempt'] ?? 0) >= 60
                    || ($settings['health_reported_status'] ?? '') !== ($settings['reconcile_status'] ?? 'degraded'))) {
                $health = ['kind' => 'health', 'registration' => $settings['registration'], 'generation' => $settings['generation'],
                    'reconcile_status' => $settings['reconcile_status'] ?? 'degraded'];
                $response = self::send($settings, $health, $deadline);
                $state = ['health_attempt' => (string) time()];
                if ($response['status'] === 200 && ($response['body']['kind'] ?? '') === 'health'
                    && ($response['body']['recorded'] ?? false) === true
                    && ($response['body']['registration'] ?? '') === $health['registration']
                    && ($response['body']['generation'] ?? '') === $health['generation']) {
                    $state['health_ack'] = (string) time();
                    $state['health_reported_status'] = $health['reconcile_status'];
                }
                \Config::setConfigurationValues(Settings::CONTEXT, $state);
            }
            $count = 0;
            while ($count < 20 && hrtime(true) + 500_000_000 < $deadline) {
                $settings = Wake::settings();
                if (!Settings::validEndpoint($settings['endpoint'] ?? '') || empty($settings['generation']) || empty($settings['token'])) {
                    break;
                }
                Wake::assertCurrent();
                $sent = Outbox::claim($settings);
                if ($sent === null) {
                    break;
                }
                if (($settings['mode'] ?? 'rest') === 'agent') {
                    try {
                        $subscription = TargetScope::subscription($sent['itemtype'], (int) $sent['computer_id'], $settings);
                        if (!$subscription) { throw new \RuntimeException('Subscription unavailable.'); }
                        TargetScope::snapshot($subscription, $settings);
                    } catch (\Throwable) {
                        Outbox::retry($sent, 'blocked');
                        $count++;
                        continue;
                    }
                }
                $sent['computer_id'] = (int) $sent['computer_id'];
                $sent['revision'] = (int) $sent['revision'];
                $response = self::send($settings, $sent, $deadline);
                if ($response['status'] !== 200 || !Outbox::acknowledge($sent, $response['body'], Settings::load())) {
                    Outbox::retry($sent, match ($response['status']) {401, 403 => 'unauthorized', 409 => 'blocked', default => 'retrying'});
                }
                $count++;
            }
            \Config::setConfigurationValues(Settings::CONTEXT, ['worker_seen' => (string) time()]);
            return $count;
        } finally {
            Outbox::sql("SELECT RELEASE_LOCK({$quotedLock})");
        }
    }

    /** Persistent separate cursors discover changes and tracked purges, at most 50 rows/2 seconds. */
    public function reconcile(?int $deadline = null): int
    {
        global $DB;
        $deadline = min($deadline ?? PHP_INT_MAX, hrtime(true) + 2_000_000_000);
        if (hrtime(true) >= $deadline) { return 0; }
        $state = Wake::settings();
        if (($state['mode'] ?? 'rest') === 'agent') {
            $priorityDeadline = min($deadline, hrtime(true) + max(0, (int) (($deadline - hrtime(true)) / 2)));
            $cursor = max(0, (int) ($state['target_cursor'] ?? 0));
            $seen = 0;
            $degraded = false;
            $where = array_intersect_key($state, array_flip(['installation', 'registration', 'generation', 'connection_id']));
            $where += ['active' => 1, 'expires' => ['>', time()]];
            $process = static function (array $target) use ($DB, $state, &$degraded): void {
                Wake::assertCurrent();
                $id = (int) $target['items_id'];
                $type = $target['itemtype'];
                try {
                    $before = Outbox::find($id, $type);
                    $snapshot = TargetScope::snapshot($target, $state);
                    Outbox::observe($id, $snapshot, (int) $target['dirty'] === 0, $before, $type);
                    if (!$DB->update(TargetScope::TABLE, ['dirty' => 0], ['id' => (int) $target['id'], 'dirty' => (int) $target['dirty']])) {
                        throw new \RuntimeException('Subscription dirty clear failed.');
                    }
                } catch (\Throwable) { $degraded = true; }
            };
            $priorityIds = [];
            foreach ($DB->request(['FROM' => TargetScope::TABLE, 'WHERE' => $where + ['dirty' => ['>', 0]],
                'ORDER' => 'id ASC', 'LIMIT' => 5]) as $target) {
                if (hrtime(true) >= $priorityDeadline) { break; }
                $priorityIds[(int) $target['id']] = true;
                $seen++;
                $process($target);
            }
            if ($seen < 50 && hrtime(true) < $deadline) {
                $limit = 50 - $seen;
                $sweepSeen = 0;
                $interrupted = false;
                foreach ($DB->request(['FROM' => TargetScope::TABLE, 'WHERE' => $where + ['id' => ['>', $cursor]],
                    'ORDER' => 'id ASC', 'LIMIT' => $limit]) as $target) {
                    if ($seen >= 50 || hrtime(true) >= $deadline) { $interrupted = true; break; }
                    $seen++;
                    $sweepSeen++;
                    if (!isset($priorityIds[(int) $target['id']])) { $process($target); }
                    $cursor = (int) $target['id'];
                    \Config::setConfigurationValues(Settings::CONTEXT, ['target_cursor' => (string) $cursor]);
                }
                // Priority rows consume the shared budget but never move the ordinary sweep cursor.
                if (!$interrupted && $sweepSeen < $limit && hrtime(true) < $deadline) { $cursor = 0; }
            }
            \Config::setConfigurationValues(Settings::CONTEXT, ['target_cursor' => (string) $cursor, 'reconcile_status' => $degraded ? 'degraded' : 'ok']);
            return $seen;
        }
        $count = 0;
        foreach (['scan_cursor' => 'glpi_computers', 'purge_cursor' => Outbox::TABLE] as $key => $table) {
            $column = $key === 'scan_cursor' ? 'id' : 'computer_id';
            $cursor = max(0, (int) ($state[$key] ?? 0));
            $rows = $DB->request(['SELECT' => [$column], 'FROM' => $table, 'WHERE' => [$column => ['>', $cursor]], 'ORDER' => $column . ' ASC', 'LIMIT' => 25]);
            $seen = 0;
            foreach ($rows as $row) {
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $id = (int) $row[$column];
                $before = Outbox::find($id);
                try {
                    $snapshot = Snapshot::read($id, $deadline);
                } catch (\Throwable) {
                    \Config::setConfigurationValues(Settings::CONTEXT, [$key => (string) $cursor, 'reconcile_status' => 'degraded']);
                    return $count;
                }
                Outbox::observe($id, $snapshot, true, $before);
                $cursor = $id;
                $seen++;
                $count++;
            }
            // Reset only on a fully exhausted page, never on a deadline interruption.
            if ($seen < 25 && hrtime(true) < $deadline) {
                $cursor = 0;
            }
            \Config::setConfigurationValues(Settings::CONTEXT, [$key => (string) $cursor]);
        }
        \Config::setConfigurationValues(Settings::CONTEXT, ['reconcile_status' => 'ok']);
        return $count;
    }

    public static function send(array $settings, array $sent, int $deadline): array
    {
        Wake::assertCurrent();
        if (!Settings::validEndpoint($settings['endpoint'])) {
            return ['status' => 0, 'body' => []];
        }
        $timeout = min(5000, max(1, (int) (($deadline - hrtime(true)) / 1_000_000)));
        $keys = ($sent['kind'] ?? '') === 'health'
            ? ['kind', 'registration', 'generation', 'reconcile_status']
            : ['registration', 'generation', 'computer_id', 'revision', 'lifecycle'];
        if (($settings['mode'] ?? 'rest') === 'agent' && ($sent['kind'] ?? '') !== 'health') { $keys[] = 'itemtype'; }
        $payload = array_intersect_key($sent, array_flip($keys));
        $curl = curl_init($settings['endpoint']);
        $body = '';
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $settings['token']],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2048) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            $ok = curl_exec($curl);
            $decoded = json_decode($body, true, 8);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'body' => is_array($decoded) ? $decoded : []];
        } finally {
            curl_close($curl);
        }
    }
}
