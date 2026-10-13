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
            $readOnlyMatch = ($task['version'] === 4 && in_array($task['kind'], ['match', 'reverify'], true))
                || ($task['version'] === 6 && $task['kind'] === 'inspect');
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
        if (($task['version'] === 5 && $task['kind'] === 'create') || ($task['version'] === 6 && $task['kind'] === 'apply')) {
            // Creation and v6 apply write their result in the same transaction as their models.
            // A failed/ambiguous COMMIT must not report inferred success from an in-memory result.
            $saved = Outbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($id));
            if (!$saved) { throw new \RuntimeException($task['version'] === 5 ? 'Creation journal disappeared.' : 'V6 journal disappeared.'); }
            if ($saved['result'] !== '{}') { return json_decode($saved['result'], true, 32, JSON_THROW_ON_ERROR); }
            if ($result['outcome'] === 'verified' || ($task['version'] === 6 && $result['outcome'] === 'unchanged')) { $result = ['outcome' => 'uncertain', 'changed' => []]; }
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
        $deadline = min($deadline, hrtime(true) + 5_000_000_000);
        // A separate bounded pull keeps leases/revocations moving even behind a large task or lost result ACK.
        $response = self::send($settings, ['kind' => 'pull', 'subscriptions_only' => true], $deadline);
        if ($response['status'] !== 200) { return; }
        if ($response['status'] === 200 && isset($response['body']['subscriptions'])) {
            TargetScope::receive($response['body']['subscriptions'], $settings);
        }
        $count = 0;
        // One reserved probe prevents ordinary traffic from starving retained legacy operations.
        if (is_array($response['body']['recovery'] ?? null) && hrtime(true) < $deadline) {
            if (!self::reportRecovery($settings, $response['body']['recovery'], $deadline)) { return; }
            $count++;
        }
        // Report durable results before pulling new work. Lost ACKs only replay the same result.
        $where = ['acknowledged' => 0, 'report_status' => 'pending'];
        foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) { $where[$key] = $settings[$key]; }
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDER' => 'created_at ASC', 'LIMIT' => 10]) as $row) {
            if ($count >= 10 || hrtime(true) >= $deadline) { return; }
            $task = json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($task['generation'] !== $settings['generation'] || $task['registration'] !== $settings['registration']
                || $task['installation'] !== $settings['installation'] || $task['connection_id'] !== $settings['connection_id']) { continue; }
            $result = self::run($task, $settings);
            if (!self::report($settings, $task, $result, $deadline)) { return; }
            $count++;
        }
        while ($count < 10 && hrtime(true) < $deadline) {
            $response = self::send($settings, ['kind' => 'pull'], $deadline);
            if ($response['status'] !== 200) { return; }
            if (isset($response['body']['subscriptions'])) {
                TargetScope::receive($response['body']['subscriptions'], $settings);
            }
            $task = $response['body']['task'] ?? null;
            if (hrtime(true) >= $deadline) { return; }
            if (!is_array($task)) {
                $recovery = $response['body']['recovery'] ?? null;
                if (!is_array($recovery)) { return; }
                if (!self::reportRecovery($settings, $recovery, $deadline)) { return; }
                $count++;
                continue;
            }
            $result = self::run($task, $settings);
            if (!self::report($settings, $task, $result, $deadline)) { return; }
            $count++;
        }
    }

    private static function reportRecovery(array $settings, array $task, int $deadline): bool
    {
        $proof = self::recoveryProof($task, $settings);
        if ($proof === null) { return true; }
        if (hrtime(true) >= $deadline) { return false; }
        $response = self::send($settings, ['kind' => 'recovery', 'id' => $task['id'], 'hash' => $task['hash'], 'proof' => $proof], $deadline);
        return $response['status'] === 200 && ($response['body']['recorded'] ?? false) === true
            && ($response['body']['id'] ?? '') === $task['id'] && ($response['body']['hash'] ?? '') === $task['hash'];
    }

    /** This is a journal read, never an execution or a replay of the old apply. */
    public static function recoveryProof(array $task, array $settings): ?array
    {
        global $DB;
        TaskExecutor::validate($task, $settings, false);
        if ($task['kind'] !== 'apply' || !in_array($task['version'], [1, 2, 3, 4, 6], true)) { return null; }
        $lock = 'assetsync20agent:' . substr(hash('sha256', (string) ($DB->dbdefault ?? 'glpi')), 0, 32);
        if ((int) (Outbox::one('SELECT IS_USED_LOCK(' . $DB->quote($lock) . ') = CONNECTION_ID() AS owned')['owned'] ?? 0) !== 1) {
            return null;
        }
        $target = $settings;
        if ($task['version'] >= 3) {
            $target = array_replace($settings, array_intersect_key($task, array_flip(['itemtype', 'items_id', 'pair_id', 'entity_id'])));
        } elseif (($task['computer_id'] ?? 0) !== (int) ($settings['computer_id'] ?? 0)
            || $task['entity_id'] !== (int) ($settings['entity_id'] ?? -1)) { return null; }
        TaskExecutor::configuration($target, $task['version'] < 3);
        ServiceContext::run($target, static fn () => ServiceContext::computer($target));
        // Storage errors throw; only a successful empty SELECT proves absence. The journal is never pruned.
        $row = Outbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']));
        $journal = 'absent';
        $resultHash = '';
        if ($row !== null) {
            if ($row['hash'] !== $task['hash'] || TaskExecutor::hash(json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR)) !== TaskExecutor::hash($task)
                || !in_array($row['state'], ['unchanged', 'stale', 'verified'], true) || $row['result'] === '{}') { return null; }
            foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) {
                if ($row[$key] !== $settings[$key]) { return null; }
            }
            $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
            if (($result['outcome'] ?? '') !== $row['state'] || !is_array($result['changed'] ?? null)
                || ($row['state'] === 'verified' ? ($result['changed'] === [] || !is_array($result['snapshot'] ?? null)) : $result['changed'] !== [])) { return null; }
            $journal = $row['state'];
            $resultHash = TaskExecutor::hash($result);
        }
        if ($journal === 'absent' && time() <= $task['expires']) { return null; }
        $proof = ['version' => 1, 'observed_at' => time(), 'journal' => $journal, 'result_hash' => $resultHash];
        if ($journal === 'verified') { $proof['result'] = $result; }
        return $proof;
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

    public static function send(array $settings, array $payload, int $deadline, bool $legacyPull = false): array
    {
        if (!Settings::validEndpoint($settings['endpoint'] ?? '') || hrtime(true) >= $deadline) { return ['status' => 0, 'body' => []]; }
        if (($payload['kind'] ?? '') === 'pull') {
            $payload['task_versions'] = $legacyPull ? [1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6];
            $payload['recovery_version'] = 1;
        }
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
            $response = ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => is_array($decoded) ? $decoded : []];
        } finally { curl_close($curl); }
        // Older A rejects the additive capability list before handling a pull. Retry only that read.
        if (!$legacyPull && ($payload['kind'] ?? '') === 'pull' && $response['status'] === 400) {
            return self::send($settings, $payload, $deadline, true);
        }
        return $response;
    }
}
