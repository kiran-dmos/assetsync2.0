<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** One selected pair uses durable stock REST records through the ordinary A queue. */
final class RestWork
{
    private const RESOURCE = 'GlpiPlugin%5CAssetsync20agent%5CRestWork';
    private const SETTINGS = 'GlpiPlugin%5CAssetsync20agent%5CRestSettings';
    private static array $batches = [];

    public static function beginRun(): void { self::$batches = []; }

    public static function handles(array $connection, string $type, int $id): bool
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists(AgentTask::PAIRS) || !$DB->tableExists(AgentTask::TABLE)) { return false; }
        $pair = AgentTask::pair($connection['id'], $type, $id);
        if (!$pair) { return false; }
        if (($connection['agent_rest_pair'] ?? '') === $pair['id'] || in_array($pair['id'], $connection['agent_rest_pairs'] ?? [], true)) { return true; }
        $task = AgentTask::outstanding($connection['id'], $pair);
        return $task && in_array(json_decode($task['body'], true)['version'] ?? 0, [7, 8], true);
    }

    private static function context(array $pair): array
    {
        $scope = AgentTask::scope($pair);
        $connection = $scope['connection'];
        if ($pair['itemtype'] !== 'Computer' || (($connection['agent_rest_pair'] ?? '') !== $pair['id']
                && !in_array($pair['id'], $connection['agent_rest_pairs'] ?? [], true))
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
                if (!in_array($body['version'], [7, 8], true)) { throw new \RuntimeException('Resolve the retained agent operation before switching this pair to REST records.'); }
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
            if (in_array($pair['id'], $context['connection']['agent_rest_pairs'] ?? [], true)) {
                $body['version'] = 8;
                $key = AgentTask::hash([$context['settings'], $context['connection']['agent_rest_url'], AgentSession::fingerprint($context['connection'])]);
                $batch = self::$batches[$key] ?? ['id' => bin2hex(random_bytes(16)), 'inputs' => []];
                $body['batch_id'] = $batch['id'];
                $input = ['work_id' => $body['id'], 'hash' => AgentTask::hash($body), 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
                if (count($batch['inputs']) >= 4 || strlen(json_encode(['session_write' => false, 'input' => [...$batch['inputs'], $input]], JSON_THROW_ON_ERROR)) > 49152) {
                    $batch = ['id' => bin2hex(random_bytes(16)), 'inputs' => []];
                    $body['batch_id'] = $batch['id'];
                    $input = ['work_id' => $body['id'], 'hash' => AgentTask::hash($body), 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
                }
                if (strlen(json_encode(['session_write' => false, 'input' => [$input]], JSON_THROW_ON_ERROR)) > 49152) {
                    throw new \RuntimeException('REST batch work exceeds request budget.');
                }
                $batch['inputs'][] = $input; self::$batches[$key] = $batch;
            }
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
            AND JSON_EXTRACT(`body`, \'$.version\') IN (7, 8) AND COALESCE(JSON_EXTRACT(`context`, \'$.rest_until\'), 0) <= ' . time()
            . ' AND COALESCE(JSON_EXTRACT(`context`, \'$.rest_next\'), 0) <= ' . time() . ' ORDER BY `created_at`, `id` LIMIT 1');
        if (!$task) { return; }
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        if ($body['version'] === 8) { self::sendBatch($task, $body, $deadline); return; }
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
        if ($body['version'] === 8) {
            $timing = $result['timing'] ?? [];
            if (!is_int($timing['started_at'] ?? null) || !is_int($timing['finished_at'] ?? null)
                || ($timing['within_deadline'] ?? null) !== true || $timing['started_at'] < $body['expires'] - 300
                || $timing['started_at'] > $timing['finished_at'] || $timing['finished_at'] > $body['expires']
                || $timing['finished_at'] !== ($result['snapshot']['observed_at'] ?? null)) { throw new \RuntimeException('Invalid REST execution timing.'); }
        }
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

    /** The existing task rows are the durable batch membership; all network work follows reservation. */
    private static function sendBatch(array $first, array $body, int $deadline): void
    {
        global $DB;
        $members = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['connection_id' => $first['connection_id'],
            new \Glpi\DBAL\QueryExpression('JSON_UNQUOTE(JSON_EXTRACT(`body`, \'$.batch_id\')) = ' . $DB->quote($body['batch_id']))],
            'ORDER' => 'id ASC', 'LIMIT' => 5]));
        if (count($members) > 4 || $members === []) { return; }
        $expected = []; $entries = []; $client = null;
        try {
            foreach ($members as $task) {
                $member = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                foreach (['version', 'batch_id', 'installation', 'registration', 'generation', 'connection_id', 'settings_id', 'settings_hash', 'entity_id'] as $key) {
                    if (($member[$key] ?? null) !== $body[$key]) { throw new \RuntimeException('REST batch membership changed.'); }
                }
                if (AgentTask::hash($member) !== $task['hash']) { throw new \RuntimeException('REST batch hash changed.'); }
                $expected[$task['id']] = $task;
            }
            foreach ($expected as $task) {
                if ($task['state'] !== 'accepted' || !$task['slot'] || $task['result'] !== '{}') { continue; }
                $member = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                $pair = AgentTask::byId($task['connection_id'], $member['pair_id']);
                if (!$pair) { return; }
                $reserved = null;
                AssetUuidOperation::withAssetLock('Computer', (int) $pair['local_id'], static function () use ($pair, $task, $member, &$reserved): void {
                    global $DB;
                    $scope = self::context($pair);
                    if (AgentTask::hash($scope['settings']) !== $member['settings_hash']) { throw new \RuntimeException('REST settings changed before dispatch.'); }
                    $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
                    if ($context['rest_until'] > time() || $context['rest_next'] > time()) { return; }
                    $context['rest_lease'] = bin2hex(random_bytes(16)); $context['rest_until'] = time() + 30;
                    if ($DB->update(AgentTask::TABLE, ['context' => json_encode($context, JSON_THROW_ON_ERROR)],
                        ['id' => $task['id'], 'context' => $task['context'], 'state' => 'accepted', 'result' => '{}']) && $DB->affectedRows() === 1) { $reserved = $context; }
                });
                if ($reserved === null) { return; }
                $task['context'] = json_encode($reserved, JSON_THROW_ON_ERROR);
                $reserved['rest_state'] = 'unknown'; $reserved['rest_members'] = array_keys($expected);
                $entries[$task['id']] = ['task' => $task, 'body' => $member, 'context' => $reserved];
            }
            if ($entries === [] || AssetUuidOperation::hasAssetLocks() || !AgentWake::transactionIdle()) { return; }
            $connection = GlpiBConnection::find($first['connection_id']);
            if (!$connection || !$connection['active']) { return; }
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $client = new RestSession($connection, $deadline);
            $settings = reset($entries)['context']['settings'];
            $receiptKey = hash('sha256', $connection['id'] . $connection['agent_rest_url'] . $body['settings_hash']);
            $receipts = json_decode(\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts'])['rest_settings_receipts'] ?? '{}', true);
            if (empty($receipts[$receiptKey])) {
                $response = $client->request('POST', self::SETTINGS, ['settings_id' => $settings['id'], 'hash' => $body['settings_hash'], 'body' => json_encode($settings, JSON_THROW_ON_ERROR)]);
                $valid = $response['status'] === 201 && is_int($response['body']['id'] ?? null) && $response['body']['id'] > 0;
                foreach ($entries as &$entry) { self::diagnose($entry['context'], $client, 'settings_post', $valid); } unset($entry);
                if (!$valid) { return; }
                $receipts[$receiptKey] = $response['body']['id'];
                \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode($receipts, JSON_THROW_ON_ERROR)]);
            }
            $records = null;
            foreach ($entries as $entry) {
                if ($entry['context']['rest_sent'] || $entry['body']['expires'] < time()) {
                    $records = self::lookupBatch($client, $body['batch_id'], $expected, $entries);
                    if ($records === null) { return; }
                    self::observeBatch($entries, $records);
                    break;
                }
            }
            $inputs = [];
            foreach ($entries as &$entry) {
                $record = $records[$entry['task']['id']] ?? null;
                if ($entry['task']['result'] !== '{}' || ($entry['body']['expires'] < time() && ($record['state'] ?? '') !== 'executing')) { continue; }
                if ($record !== null && (!in_array($record['state'] ?? '', ['accepted', 'executing'], true) || ($record['result'] ?? '{}') !== '{}')) { continue; }
                $entry['context']['rest_sent'] = true;
                self::saveReservation($entry['task'], $entry['context']);
                $inputs[] = ['work_id' => $entry['task']['id'], 'hash' => $entry['task']['hash'], 'body' => $entry['task']['body']];
            } unset($entry);
            if ($inputs === []) { return; }
            if (strlen(json_encode(['session_write' => false, 'input' => $inputs], JSON_THROW_ON_ERROR)) > 49152) { throw new \RuntimeException('REST batch exceeds request budget.'); }
            $response = $client->request('POST', self::RESOURCE, $inputs);
            // Native per-row IDs, including 207/400 details, never establish mutation or absence.
            $valid = in_array($response['status'], [201, 207], true);
            foreach ($entries as &$entry) { self::diagnose($entry['context'], $client, 'work_post', $valid); } unset($entry);
            $records = self::lookupBatch($client, $body['batch_id'], $expected, $entries);
            if ($records !== null) { self::observeBatch($entries, $records); }
        } finally {
            try { if ($client !== null) { $client->close(); } }
            finally {
                foreach ($entries as &$entry) {
                    $entry['context']['rest_until'] = 0; $entry['context']['rest_next'] = time() + 10;
                    $entry['context']['rest_expires'] = $entry['body']['expires'];
                    self::saveReservation($entry['task'], $entry['context']);
                } unset($entry);
            }
        }
    }

    private static function lookupBatch(RestSession $client, string $batch, array $expected, array &$entries): ?array
    {
        $response = $client->request('GET', self::RESOURCE . '?range=0-3&get_hateoas=0&searchText%5Bbatch_id%5D=' . $batch);
        $valid = $response['status'] === 200 && array_is_list($response['body']) && count($response['body']) <= 4;
        $records = [];
        if ($valid) {
            foreach ($response['body'] as $record) {
                $id = is_array($record) ? ($record['work_id'] ?? null) : null;
                if (!is_string($id) || !isset($expected[$id]) || isset($records[$id]) || ($record['batch_id'] ?? '') !== $batch
                    || ($record['hash'] ?? '') !== $expected[$id]['hash'] || ($record['body'] ?? '') !== $expected[$id]['body']) { $valid = false; break; }
                $records[$id] = $record;
            }
        }
        foreach ($entries as &$entry) { self::diagnose($entry['context'], $client, 'work_lookup', $valid); } unset($entry);
        return $valid ? $records : null;
    }

    private static function observeBatch(array &$entries, array $records): void
    {
        global $DB;
        foreach ($entries as $id => &$entry) {
            if ($entry['task']['result'] !== '{}') { continue; }
            $record = $records[$id] ?? null;
            $entry['context']['rest_observed_at'] = time();
            $entry['context']['rest_state'] = $record === null ? 'not_found'
                : (in_array($record['state'] ?? '', ['accepted', 'executing'], true) ? $record['state'] : 'unknown');
            try {
                $result = $record === null ? [] : json_decode($record['result'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if ($result === []) {
                    if ($entry['body']['expires'] >= time() || ($record !== null && ($record['state'] ?? '') !== 'accepted')) { continue; }
                    $result = ['outcome' => 'stale', 'changed' => []];
                }
                self::validateResult($entry['body'], $result);
            } catch (\JsonException | \TypeError | \RuntimeException) {
                $entry['context']['rest_state'] = 'unknown';
                $entry['context']['rest_error'] = ['phase' => 'result', 'status' => 200, 'error' => 'invalid_result', 'at' => time()];
                continue;
            }
            self::saveReservation($entry['task'], $entry['context'], $result);
            $entry['task']['result'] = json_encode($result, JSON_THROW_ON_ERROR);
            $entry['context']['rest_state'] = $result['outcome'] === 'stale' ? 'expired' : $result['outcome'];
            AgentTask::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $entry['task']['queue_id'], 'status' => 'waiting_agent']));
        } unset($entry);
    }
}
