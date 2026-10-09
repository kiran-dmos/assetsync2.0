<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Worker
{
    public function run(): int
    {
        global $DB;
        // A database-scoped lock excludes overlapping CLI workers, with automatic process-death release.
        $lock = 'assetsync20agent:' . substr(hash('sha256', (string) ($DB->dbdefault ?? 'glpi')), 0, 32);
        $quotedLock = $DB->quote($lock);
        if ((int) (Outbox::one("SELECT GET_LOCK({$quotedLock}, 0) AS acquired")['acquired'] ?? 0) !== 1) {
            return 0;
        }
        try {
            $deadline = hrtime(true) + 20_000_000_000;
            try {
                $this->reconcile();
            } catch (\Throwable) {
                \Config::setConfigurationValues(Settings::CONTEXT, ['reconcile_status' => 'degraded']);
            }
            $settings = Settings::load();
            if (Settings::validEndpoint($settings['endpoint'] ?? '') && !empty($settings['token'])
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
                $settings = Settings::load();
                if (!Settings::validEndpoint($settings['endpoint'] ?? '') || empty($settings['generation']) || empty($settings['token'])) {
                    break;
                }
                $sent = Outbox::claim($settings);
                if ($sent === null) {
                    break;
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
    public function reconcile(): int
    {
        global $DB;
        $state = \Config::getConfigurationValues(Settings::CONTEXT);
        $deadline = hrtime(true) + 2_000_000_000;
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
        if (!Settings::validEndpoint($settings['endpoint'])) {
            return ['status' => 0, 'body' => []];
        }
        $timeout = min(5000, max(1, (int) (($deadline - hrtime(true)) / 1_000_000)));
        $keys = ($sent['kind'] ?? '') === 'health'
            ? ['kind', 'registration', 'generation', 'reconcile_status']
            : ['registration', 'generation', 'computer_id', 'revision', 'lifecycle'];
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
