<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** One selected pair uses durable stock REST records through the ordinary A queue. */
final class RestWork
{
    private const RESOURCE = 'GlpiPlugin%5CAssetsync20agent%5CRestWork';
    private const SETTINGS = 'GlpiPlugin%5CAssetsync20agent%5CRestSettings';

    public static function handles(array $connection, string $type, int $id): bool
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists(AgentTask::PAIRS) || !$DB->tableExists(AgentTask::TABLE)) { return false; }
        $pair = AgentTask::pair($connection['id'], $type, $id);
        if (!$pair) { return false; }
        if (($connection['agent_rest_pair'] ?? '') === $pair['id']) { return true; }
        $task = AgentTask::outstanding($connection['id'], $pair);
        return $task && (json_decode($task['body'], true)['version'] ?? 0) === 7;
    }

    private static function context(array $pair): array
    {
        $scope = AgentTask::scope($pair);
        $connection = $scope['connection'];
        if ($pair['itemtype'] !== 'Computer' || ($connection['agent_rest_pair'] ?? '') !== $pair['id']
            || !RestSession::validUrl($connection['agent_rest_url'] ?? '')) { throw new \RuntimeException('Selected REST pair is unavailable.'); }
        FieldMapping::endRunCache();
        $rows = FieldMapping::load($pair['connection_id'], 'Computer');
        if (count($rows) !== 1) { throw new \RuntimeException('Name REST requires exactly one mapping; additional mappings are unsupported.'); }
        $row = array_values($rows)[0];
        if (!in_array($row['glpi_a_field_key'] ?? '', ['name', 'Computer.name'], true)
            || !in_array($row['glpi_b_field_key'] ?? '', ['name', 'Computer.name'], true)
            || ($row['source_of_truth'] ?? '') !== 'glpi_a') { throw new \RuntimeException('Name REST supports native Name to Name with glpi_a authority only.'); }
        $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
        $identity = !empty($pair['identity_basis']) ? json_decode($pair['identity_basis'], true, 32, JSON_THROW_ON_ERROR)['b']
            : (array_key_exists('otherserial', $snapshot) ? AgentTask::identity($snapshot) : ['serial' => $pair['serial']]);
        $settings = ['version' => 1];
        foreach (['installation', 'registration', 'connection_id'] as $key) { $settings[$key] = $pair[$key]; }
        $settings += ['generation' => $scope['registration']['generation'], 'route_id' => $pair['route_id'],
            'source_entity' => (int) $pair['local_entity'], 'target_entity' => (int) $pair['entity_id'], 'itemtype' => 'Computer',
            'mappings' => [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']]];
        $settings = ['version' => 1, 'id' => substr(AgentTask::hash($settings), 0, 32)] + $settings;
        $source = AgentTask::hash([AgentTask::hash($settings), AgentTask::identity($scope['asset']), (string) $scope['asset']['name']]);
        return $scope + ['settings' => $settings, 'source_hash' => $source, 'identity' => $identity];
    }

    public static function needsWork(array $pair, bool $force): bool
    {
        if (AgentTask::outstanding($pair['connection_id'], $pair)) { return false; }
        try { $context = self::context($pair); }
        catch (\Throwable) { return true; }
        return $force || ($pair['local_fingerprint'] ?? '') !== $context['source_hash'];
    }

    public static function assertSource(array $pair, string $hash, array $lockedAsset): void
    {
        $context = self::context($pair);
        if ((int) ($lockedAsset['id'] ?? 0) !== (int) $pair['local_id']
            || (int) ($lockedAsset['entities_id'] ?? -1) !== (int) $pair['local_entity']
            || !empty($lockedAsset['is_deleted']) || !empty($lockedAsset['is_template'])
            || AgentTask::hash([AgentTask::hash($context['settings']), AgentTask::identity($lockedAsset), (string) $lockedAsset['name']]) !== $hash) {
            throw new \RuntimeException('REST source changed before finalization.');
        }
    }

    public static function process(array $job): void
    {
        global $DB;
        $pair = AgentTask::pair($job['glpi_b_connection_id'], $job['itemtype'], (int) $job['items_id']);
        try {
            if (!$pair || !AssetSyncQueue::owns($job)) { return; }
            $context = self::context($pair);
            $task = AgentTask::outstanding($pair['connection_id'], $pair);
            if ($task) {
                $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                if ($body['version'] !== 7) { throw new \RuntimeException('Resolve the retained agent operation before switching this pair to REST records.'); }
                if ($task['result'] === '{}') { AgentTask::write(AssetSyncQueue::waitForAgent($job)); return; }
                $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
                if (!in_array($result['outcome'] ?? '', ['verified', 'unchanged'], true)) {
                    AgentTask::write(AssetSyncQueue::block($job, 'REST work ' . ($result['outcome'] ?? 'uncertain') . '; retained evidence requires inspection.'));
                    return;
                }
                if ($body['source_hash'] !== $context['source_hash']) {
                    AgentInbox::sql('START TRANSACTION');
                    try {
                        AgentTask::write($DB->update(AgentTask::TABLE, ['slot' => null, 'state' => 'stale'], ['id' => $task['id'], 'slot' => $task['slot']]));
                        AgentTask::write(AssetSyncQueue::retry($job, 'Source changed during REST work; queue a fresh immutable request.'));
                        AgentInbox::sql('COMMIT');
                    } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
                    return;
                }
                self::validateResult($body, $result);
                $captured = json_decode($task['job'], true, 32, JSON_THROW_ON_ERROR);
                $job['agent_snapshot'] = (int) ($captured['agent_snapshot'] ?? 0);
                AgentTask::write($DB->update(AgentTask::TABLE, ['state' => 'finalizing'], ['id' => $task['id'], 'state' => $task['state']]));
                if ($DB->affectedRows() !== 1) { throw new \RuntimeException('REST result ownership changed.'); }
                AgentTask::finish($task, $job, $result['outcome'], !empty($job['previous_needs_recheck']), $context['source_hash']);
                return;
            }
            $body = ['version' => 7, 'id' => bin2hex(random_bytes(16))];
            foreach (['installation', 'registration', 'connection_id'] as $key) { $body[$key] = $pair[$key]; }
            $body += ['generation' => $context['registration']['generation'], 'settings_id' => $context['settings']['id'],
                'settings_hash' => AgentTask::hash($context['settings']), 'pair_id' => $pair['id'], 'itemtype' => 'Computer',
                'items_id' => (int) $pair['computer_id'], 'entity_id' => (int) $pair['entity_id'], 'identity' => $context['identity'],
                'name' => (string) $context['asset']['name'], 'source_hash' => $context['source_hash'], 'expires' => time() + 300];
            $hash = AgentTask::hash($body);
            $stored = ['settings' => $context['settings'], 'rest_next' => 0, 'rest_sent' => false, 'rest_lease' => '', 'rest_until' => 0];
            AgentInbox::sql('START TRANSACTION');
            try {
                if (!AssetSyncQueue::owns($job)) { throw new \RuntimeException('REST queue claim changed.'); }
                AgentTask::write($DB->insert(AgentTask::TABLE, ['id' => $body['id'], 'connection_id' => $pair['connection_id'],
                    'slot' => 'pair:' . $pair['id'], 'hash' => $hash, 'body' => json_encode($body, JSON_THROW_ON_ERROR),
                    'state' => 'accepted', 'result' => '{}', 'queue_id' => (int) $job['id'], 'job' => json_encode($job, JSON_THROW_ON_ERROR),
                    'context' => json_encode($stored, JSON_THROW_ON_ERROR), 'created_at' => time(), 'expires' => $body['expires']]));
                AgentTask::write(AssetSyncQueue::waitForAgent($job));
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        } catch (\Throwable $error) {
            if (AssetSyncQueue::owns($job)) {
                if ($error->getMessage() === 'REST source changed before finalization.' && isset($task, $body)) {
                    AgentInbox::sql('START TRANSACTION');
                    try {
                        AgentTask::write($DB->update(AgentTask::TABLE, ['slot' => null, 'state' => 'stale'], ['id' => $task['id'], 'state' => 'finalizing']));
                        AgentTask::write(AssetSyncQueue::retry($job, $error->getMessage()));
                        AgentInbox::sql('COMMIT');
                    } catch (\Throwable $storage) { $DB->doQuery('ROLLBACK'); throw $storage; }
                } else { AgentTask::write(AssetSyncQueue::block($job, $error->getMessage())); }
            }
        }
    }

    /** Only invoked after the outer run and asset locks are released. */
    public static function sendPending(int $deadline): void
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists(AgentTask::TABLE)
            || PHP_SAPI !== 'cli' || hrtime(true) + 2_000_000_000 >= $deadline || AssetUuidOperation::hasAssetLocks() || !AgentWake::transactionIdle()) { return; }
        $lock = $DB->quote('assetsync20:run:' . AssetSyncLockName::databaseHash($DB));
        if ((int) (AgentInbox::one('SELECT COALESCE(IS_USED_LOCK(' . $lock . ') = CONNECTION_ID(), 0) AS owned')['owned'] ?? 1) !== 0) { return; }
        $task = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `slot` IS NOT NULL AND `state` = \'accepted\' AND `result` = \'{}\'
            AND JSON_EXTRACT(`body`, \'$.version\') = 7 AND COALESCE(JSON_EXTRACT(`context`, \'$.rest_until\'), 0) <= ' . time()
            . ' AND COALESCE(JSON_EXTRACT(`context`, \'$.rest_next\'), 0) <= ' . time() . ' ORDER BY `created_at`, `id` LIMIT 1');
        if (!$task) { return; }
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        $pair = AgentTask::byId($task['connection_id'], $body['pair_id']);
        if (!$pair) { return; }
        $reserved = null;
        AssetUuidOperation::withAssetLock('Computer', (int) $pair['local_id'], static function () use ($pair, $task, &$reserved): void {
            self::context($pair);
            $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $context['rest_lease'] = bin2hex(random_bytes(16)); $context['rest_until'] = time() + 30;
            global $DB;
            if (!$DB->update(AgentTask::TABLE, ['context' => json_encode($context, JSON_THROW_ON_ERROR)],
                ['id' => $task['id'], 'context' => $task['context'], 'state' => 'accepted']) || $DB->affectedRows() !== 1) { return; }
            $reserved = $context;
        });
        if ($reserved === null || AssetUuidOperation::hasAssetLocks() || !AgentWake::transactionIdle()) { return; }
        $task['context'] = json_encode($reserved, JSON_THROW_ON_ERROR);
        $connection = GlpiBConnection::find($task['connection_id']);
        if (!$connection || !$connection['active']) { return; }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $client = new RestSession($connection, $deadline);
        $reserved['rest_state'] = 'unknown';
        try {
            $settings = $reserved['settings'];
            $receiptKey = hash('sha256', $connection['id'] . $connection['agent_rest_url'] . $body['settings_hash']);
            $receipts = json_decode(\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts'])['rest_settings_receipts'] ?? '{}', true);
            if (empty($receipts[$receiptKey])) {
                $response = $client->request('POST', self::SETTINGS, ['settings_id' => $settings['id'], 'hash' => $body['settings_hash'],
                    'body' => json_encode($settings, JSON_THROW_ON_ERROR)]);
                $valid = $response['status'] === 201 && is_int($response['body']['id'] ?? null) && $response['body']['id'] > 0;
                self::diagnose($reserved, $client, 'settings_post', $valid);
                if (!$valid) { return; }
                $receipts[$receiptKey] = $response['body']['id'];
                \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode($receipts, JSON_THROW_ON_ERROR)]);
            }
            $record = null;
            if ($reserved['rest_sent'] || $body['expires'] < time()) {
                // A failed GET is unknown, never evidence that a previous POST was absent.
                $record = self::lookup($client, $body['id'], $reserved);
                if ($record === null) { return; }
            }
            if ($record === [] && $body['expires'] < time()) {
                $reserved['rest_state'] = 'expired';
                self::saveReservation($task, $reserved, ['outcome' => 'stale', 'changed' => []]);
                $DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']);
                return;
            }
            if ($record === null || $record === [] || ($record['state'] ?? '') === 'accepted') {
                $reserved['rest_sent'] = true;
                self::saveReservation($task, $reserved);
                $response = $client->request('POST', self::RESOURCE, ['work_id' => $body['id'], 'hash' => $task['hash'], 'body' => $task['body']]);
                $id = $response['body']['id'] ?? null;
                $valid = $response['status'] === 201 && is_int($id) && $id > 0;
                self::diagnose($reserved, $client, 'work_post', $valid);
                if ($valid) {
                    $response = $client->request('GET', self::RESOURCE . '/' . $id);
                    self::diagnose($reserved, $client, 'work_get', $response['status'] === 200);
                    $record = $response['status'] === 200 ? $response['body'] : null;
                } else { $record = self::lookup($client, $body['id'], $reserved); }
            }
            if (!$record || ($record['work_id'] ?? '') !== $body['id'] || ($record['hash'] ?? '') !== $task['hash']
                || ($record['body'] ?? '') !== $task['body']) {
                if ($record === []) { $reserved['rest_state'] = 'not_found'; }
                elseif ($record !== null) {
                    $reserved['rest_error'] = ['phase' => 'work_get', 'status' => 200, 'error' => 'invalid_response', 'at' => time()];
                }
                return;
            }
            $reserved['rest_state'] = in_array($record['state'] ?? '', ['accepted', 'executing', 'expired'], true)
                ? $record['state'] : 'unknown';
            $reserved['rest_observed_at'] = time();
            try {
                $result = json_decode($record['result'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if ($result === []) {
                    if (($record['state'] ?? '') === 'expired') { $result = ['outcome' => 'stale', 'changed' => []]; }
                    else { return; }
                }
                self::validateResult($body, $result);
            } catch (\Throwable) {
                $reserved['rest_state'] = 'unknown';
                $reserved['rest_error'] = ['phase' => 'result', 'status' => 200, 'error' => 'invalid_result', 'at' => time()];
                return;
            }
            $reserved['rest_state'] = $result['outcome'];
            self::saveReservation($task, $reserved, $result);
            $DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']);
        } finally {
            try { $client->close(); }
            finally {
                $reserved['rest_until'] = 0; $reserved['rest_next'] = time() + 10;
                $reserved['rest_expires'] = $body['expires'];
                self::saveReservation($task, $reserved);
            }
        }
    }

    /** Keep the last failure even when a later GET successfully observes acceptance. */
    private static function diagnose(array &$context, RestSession $client, string $phase, bool $valid): void
    {
        $diagnostic = $client->diagnostic();
        $diagnostic['phase'] = $diagnostic['phase'] === 'init_session' ? 'init_session' : $phase;
        if (!$valid && $diagnostic['error'] === '') { $diagnostic['error'] = 'invalid_response'; }
        $diagnostic['at'] = time();
        $context['rest_dispatch'] = $diagnostic;
        if ($diagnostic['phase'] === 'work_post') { $context['rest_post'] = $diagnostic; }
        if ($diagnostic['error'] !== '') { $context['rest_error'] = $diagnostic; }
    }

    private static function lookup(RestSession $client, string $id, array &$context): ?array
    {
        $response = $client->request('GET', self::RESOURCE . '?range=0-1&searchText%5Bwork_id%5D=' . $id);
        $valid = $response['status'] === 200 && array_is_list($response['body']) && count($response['body']) <= 1;
        self::diagnose($context, $client, 'work_lookup', $valid);
        if (!$valid) { return null; }
        if ($response['body'] === []) { return []; }
        $record = $response['body'][0];
        if (!is_array($record) || ($record['work_id'] ?? '') !== $id || !is_numeric($record['id'] ?? null)) {
            self::diagnose($context, $client, 'work_lookup', false);
            return null;
        }
        $response = $client->request('GET', self::RESOURCE . '/' . (int) $record['id']);
        self::diagnose($context, $client, 'work_get', $response['status'] === 200);
        return $response['status'] === 200 ? $response['body'] : null;
    }

    private static function saveReservation(array &$task, array $context, ?array $result = null): void
    {
        global $DB;
        $fields = ['context' => json_encode($context, JSON_THROW_ON_ERROR)];
        if ($result !== null) { $fields += ['result' => json_encode($result, JSON_THROW_ON_ERROR), 'result_received_at' => time()]; }
        AgentTask::write($DB->update(AgentTask::TABLE, $fields, ['id' => $task['id'], 'hash' => $task['hash'], 'context' => $task['context']]));
        if ($DB->affectedRows() !== 1) {
            $saved = AgentInbox::one('SELECT `context` FROM `' . AgentTask::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' AND `hash` = ' . $DB->quote($task['hash']));
            if (($saved['context'] ?? null) !== $fields['context']) { throw new \RuntimeException('REST dispatch reservation changed.'); }
        }
        $task['context'] = $fields['context'];
    }

    private static function validateResult(array $body, array $result): void
    {
        if (!in_array($result['outcome'] ?? '', ['verified', 'unchanged', 'stale', 'blocked', 'uncertain'], true)
            || !is_array($result['changed'] ?? null) || array_diff(array_keys($result['changed']), ['name'])) { throw new \RuntimeException('Invalid REST result.'); }
        if (!in_array($result['outcome'], ['verified', 'unchanged'], true)) { return; }
        $snapshot = $result['snapshot'] ?? [];
        if (($snapshot['itemtype'] ?? '') !== 'Computer' || ($snapshot['items_id'] ?? null) !== $body['items_id']
            || ($snapshot['entity_id'] ?? null) !== $body['entity_id'] || ($snapshot['pair_id'] ?? '') !== $body['pair_id']
            || array_intersect_key(AgentTask::identity($snapshot), $body['identity']) !== $body['identity'] || array_keys($snapshot['fields'] ?? []) !== ['name']
            || ($snapshot['fields']['name']['value'] ?? null) !== $body['name']
            || !is_int($snapshot['observed_at'] ?? null) || $snapshot['observed_at'] > time()
            || $snapshot['observed_at'] < $body['expires'] - 300 || $snapshot['observed_at'] > $body['expires']) {
            throw new \RuntimeException('REST Name readback does not match the immutable request.');
        }
    }
}
