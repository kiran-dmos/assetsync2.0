<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class TaskJournal
{
    public const TABLE = 'glpi_plugin_assetsync20agent_tasks';

    public static function install(): bool
    {
        global $DB;
        $ok = (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` CHAR(32) PRIMARY KEY, `hash` CHAR(64) NOT NULL, `body` MEDIUMTEXT NOT NULL,
            `state` VARCHAR(20) NOT NULL, `result` MEDIUMTEXT NOT NULL, `acknowledged` TINYINT NOT NULL DEFAULT 0,
            `created_at` BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$ok) { return false; }
        foreach (['registration' => "CHAR(32) NOT NULL DEFAULT ''", 'generation' => "CHAR(32) NOT NULL DEFAULT ''",
            'installation' => "CHAR(32) NOT NULL DEFAULT ''", 'connection_id' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'report_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'"] as $name => $definition) {
            if (!$DB->fieldExists(self::TABLE, $name) && !$DB->doQuery('ALTER TABLE `' . self::TABLE . '` ADD `' . $name . '` ' . $definition)) { return false; }
        }
        return true;
    }

    /** The worker lock serializes execution. A persisted executing row is never executed again. */
    public static function run(array $task, array $settings): array
    {
        global $DB;
        $id = $task['id'] ?? '';
        TaskExecutor::validate($task, $settings, false);
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \RuntimeException('Invalid task ID.'); }
        $existing = Outbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($id));
        if ($existing) {
            if ($existing['hash'] !== ($task['hash'] ?? '')
                || TaskExecutor::hash(json_decode($existing['body'], true, 32, JSON_THROW_ON_ERROR)) !== TaskExecutor::hash($task)) {
                throw new \RuntimeException('Task ID/hash collision.');
            }
            if ($existing['result'] !== '{}') { return json_decode($existing['result'], true, 32, JSON_THROW_ON_ERROR); }
            $readOnlyMatch = $task['version'] === 4 && in_array($task['kind'], ['match', 'reverify'], true);
            $result = ['outcome' => $readOnlyMatch ? 'stale' : 'uncertain', 'changed' => []];
        } else {
            TaskExecutor::validate($task, $settings);
            Outbox::sql('START TRANSACTION');
            try {
                if (!$DB->insert(self::TABLE, ['id' => $id, 'hash' => $task['hash'], 'body' => json_encode($task, JSON_THROW_ON_ERROR),
                    'state' => 'executing', 'result' => '{}', 'created_at' => time(), 'registration' => $task['registration'],
                    'generation' => $task['generation'], 'installation' => $task['installation'], 'connection_id' => $task['connection_id']])) { throw new \RuntimeException('Journal unavailable.'); }
                Outbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
            $result = TaskExecutor::execute($task, $settings);
        }
        if ($task['version'] === 5 && $task['kind'] === 'create') {
            // Creation writes its successful result in the same transaction as the model add.
            // A failed/ambiguous COMMIT must not report inferred success from an in-memory result.
            $saved = Outbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($id));
            if (!$saved) { throw new \RuntimeException('Creation journal disappeared.'); }
            if ($saved['result'] !== '{}') { return json_decode($saved['result'], true, 32, JSON_THROW_ON_ERROR); }
            if ($result['outcome'] === 'verified') { $result = ['outcome' => 'uncertain', 'changed' => []]; }
        }
        Outbox::sql('START TRANSACTION');
        try {
            if (!$DB->update(self::TABLE, ['state' => $result['outcome'], 'result' => json_encode($result, JSON_THROW_ON_ERROR)], ['id' => $id, 'result' => '{}'])) {
                throw new \RuntimeException('Journal result unavailable.');
            }
            Outbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        return $result;
    }

    public static function cycle(array $settings, int $deadline): void
    {
        global $DB;
        // A separate bounded pull keeps leases/revocations moving even behind a large task or lost result ACK.
        $response = self::send($settings, ['kind' => 'pull', 'subscriptions_only' => true], $deadline);
        if ($response['status'] === 200 && isset($response['body']['subscriptions'])) {
            TargetScope::receive($response['body']['subscriptions'], $settings);
        }
        // Report durable results before pulling new work. Lost ACKs only replay the same result.
        $where = ['acknowledged' => 0, 'report_status' => 'pending'];
        foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) { $where[$key] = $settings[$key]; }
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDER' => 'created_at ASC', 'LIMIT' => 20]) as $row) {
            $task = json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($task['generation'] !== $settings['generation'] || $task['registration'] !== $settings['registration']
                || $task['installation'] !== $settings['installation'] || $task['connection_id'] !== $settings['connection_id']) { continue; }
            $result = self::run($task, $settings);
            if (!self::report($settings, $task, $result, $deadline)) { return; }
        }
        $response = self::send($settings, ['kind' => 'pull'], $deadline);
        if ($response['status'] === 200 && isset($response['body']['subscriptions'])) {
            TargetScope::receive($response['body']['subscriptions'], $settings);
        }
        $task = $response['body']['task'] ?? null;
        if ($response['status'] !== 200 || !is_array($task)) { return; }
        $result = self::run($task, $settings);
        self::report($settings, $task, $result, $deadline);
    }

    private static function report(array $settings, array $task, array $result, int $deadline): bool
    {
        global $DB;
        $response = self::send($settings, ['kind' => 'result', 'id' => $task['id'], 'hash' => $task['hash'], 'result' => $result], $deadline);
        if (in_array($response['status'], [401, 403, 409], true)) {
            // Retain the result and rejection separately. Rejected report is not an ACK or write authorization.
            return $DB->update(self::TABLE, ['report_status' => 'rejected'], ['id' => $task['id'], 'hash' => $task['hash']]);
        }
        if ($response['status'] !== 200 || ($response['body']['recorded'] ?? false) !== true
            || ($response['body']['id'] ?? '') !== $task['id'] || ($response['body']['hash'] ?? '') !== $task['hash']) { return false; }
        return $DB->update(self::TABLE, ['acknowledged' => 1], ['id' => $task['id'], 'hash' => $task['hash']]);
    }

    public static function send(array $settings, array $payload, int $deadline): array
    {
        if (!Settings::validEndpoint($settings['endpoint'] ?? '') || hrtime(true) >= $deadline) { return ['status' => 0, 'body' => []]; }
        if (($payload['kind'] ?? '') === 'pull') { $payload['task_versions'] = [1, 2, 3, 4, 5]; }
        foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) { $payload[$key] = $settings[$key]; }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) { throw new \RuntimeException('Task body exceeds limit.'); }
        $url = substr($settings['endpoint'], 0, -strlen('agent-notify.php')) . 'agent-task.php';
        $curl = curl_init($url);
        $body = '';
        $timeout = min(5000, max(1, (int) (($deadline - hrtime(true)) / 1_000_000)));
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $settings['token']],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            $ok = curl_exec($curl);
            $decoded = json_decode($body, true, 32);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => is_array($decoded) ? $decoded : []];
        } finally { curl_close($curl); }
    }
}
