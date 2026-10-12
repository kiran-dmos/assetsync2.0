<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** A owns the pair, policy and immutable tasks. B owns execution and its durable journal. */
final class AgentTask
{
    public const PAIRS = 'glpi_plugin_assetsync20_agentpairs';
    public const TABLE = 'glpi_plugin_assetsync20_agenttasks';
    public const OUTCOMES = ['accepted', 'executing', 'waiting_agent', 'verified', 'unchanged', 'blocked', 'stale', 'partial', 'uncertain'];

    public static function install(): bool
    {
        global $DB;
        $ok = (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::PAIRS . '` (
            `connection_id` VARCHAR(255) PRIMARY KEY, `registration` CHAR(32) NOT NULL,
            `installation` CHAR(32) NOT NULL, `local_id` INT NOT NULL, `local_entity` INT NOT NULL,
            `computer_id` INT NOT NULL, `entity_id` INT NOT NULL, `serial` VARCHAR(255) NOT NULL DEFAULT \'\',
            `route_id` VARCHAR(255) NOT NULL DEFAULT \'\', `confirmed` TINYINT NOT NULL DEFAULT 0,
            `snapshot` MEDIUMTEXT NOT NULL, `observed_at` BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
            && (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` CHAR(32) PRIMARY KEY, `connection_id` VARCHAR(255) NOT NULL,
            `slot` VARCHAR(255) DEFAULT NULL, `hash` CHAR(64) NOT NULL, `body` MEDIUMTEXT NOT NULL,
            `state` VARCHAR(20) NOT NULL, `result` MEDIUMTEXT NOT NULL,
            `queue_id` INT DEFAULT NULL, `job` MEDIUMTEXT NOT NULL, `context` MEDIUMTEXT NOT NULL,
            `created_at` BIGINT NOT NULL, `expires` BIGINT NOT NULL, UNIQUE KEY `outstanding` (`slot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$ok) { return false; }
        foreach (['local_fingerprint' => "CHAR(64) NOT NULL DEFAULT ''", 'checked_at' => 'BIGINT NOT NULL DEFAULT 0'] as $column => $definition) {
            if (!$DB->fieldExists(self::PAIRS, $column)
                && !$DB->doQuery('ALTER TABLE `' . self::PAIRS . '` ADD `' . $column . '` ' . $definition)) { return false; }
        }
        return true;
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function pair(string $connection): ?array
    {
        global $DB;
        return AgentInbox::one('SELECT * FROM `' . self::PAIRS . '` WHERE `connection_id` = ' . $DB->quote($connection));
    }

    public static function outstanding(string $connection): ?array
    {
        global $DB;
        return AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote($connection));
    }

    public static function needsInspection(array $pair, bool $force): bool
    {
        if (self::outstanding($pair['connection_id'])) { return false; }
        $context = self::context($pair);
        return $force || (int) ($pair['checked_at'] ?? 0) < time() - 3600
            || ($pair['local_fingerprint'] ?? '') !== self::localFingerprint($context);
    }

    private static function localFingerprint(array $context): string
    {
        return self::hash([$context['policy'], $context['metadata'], $context['values']]);
    }

    /** Protected setup can bootstrap inspection without routes, mappings, a REST link or REST credentials. */
    public static function declare(string $connectionId, string $installation, int $localId, int $remoteId, int $entityId): array
    {
        global $DB;
        $connection = AgentInbox::connection($connectionId);
        $computer = new \Computer();
        if (!$connection || !GlpiBConnection::isAgent($connection) || !preg_match('/^[a-f0-9]{32}$/D', $installation)
            || $remoteId <= 0 || $entityId < 0 || !$computer->can($localId, READ) || !$computer->getFromDB($localId)
            || $computer->fields['is_deleted'] || $computer->fields['is_template'] || trim((string) $computer->fields['serial']) === '') {
            throw new \RuntimeException('Choose an active agent connection and an existing visible Computer with a serial.');
        }
        $credentials = ['registration' => bin2hex(random_bytes(16)), 'generation' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(32))];
        AgentInbox::sql('START TRANSACTION');
        try {
            self::write($DB->insert(AgentInbox::REGISTRATIONS, ['id' => $credentials['registration'],
                'generation' => $credentials['generation'], 'token_hash' => hash('sha256', $credentials['token']),
                'connection_id' => $connectionId, 'connection_identity' => AgentInbox::identity($connection), 'routes' => '{}', 'active' => 1]));
            // A primary key prevents a second pair or silently replacing an existing candidate.
            self::write($DB->insert(self::PAIRS, ['connection_id' => $connectionId, 'registration' => $credentials['registration'],
                'installation' => $installation, 'local_id' => $localId, 'local_entity' => (int) $computer->fields['entities_id'],
                'computer_id' => $remoteId, 'entity_id' => $entityId, 'snapshot' => '{}']));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) {
            $DB->doQuery('ROLLBACK');
            throw new \RuntimeException('Pair already exists or could not be stored. No pair was replaced.', 0, $error);
        }
        try {
            self::inspect($connectionId);
        } catch (\Throwable) {
            // The candidate is durable; never lose its once-visible credentials on publication failure.
            $credentials['inspection_error'] = 'Candidate saved, but inspection could not be queued. Use Refresh observation.';
        }
        return $credentials;
    }

    public static function rotate(string $connectionId): array
    {
        $pair = self::pair($connectionId);
        $computer = new \Computer();
        if (!$pair || !$computer->can((int) $pair['local_id'], READ)) {
            throw new \RuntimeException('Agent pair unavailable.');
        }
        $scope = self::scope($pair, false);
        return ['registration' => $pair['registration'], 'generation' => $scope['registration']['generation'],
            'token' => AgentInbox::rotate($pair['registration'])];
    }

    public static function recent(string $connectionId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['SELECT' => ['id', 'state', 'created_at', 'queue_id'],
            'FROM' => self::TABLE, 'WHERE' => ['connection_id' => $connectionId],
            'ORDER' => 'created_at DESC', 'LIMIT' => 5]));
    }

    public static function scope(array $pair, bool $confirmed = true): array
    {
        global $DB;
        $connection = AgentInbox::connection($pair['connection_id']);
        $registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($pair['registration']));
        $computer = new \Computer();
        if (!$connection || !GlpiBConnection::isAgent($connection) || !$registration || !$registration['active']
            || $registration['connection_identity'] !== AgentInbox::identity($connection)
            || !$computer->getFromDB((int) $pair['local_id']) || $computer->fields['is_deleted'] || $computer->fields['is_template']
            || (int) $computer->fields['entities_id'] !== (int) $pair['local_entity']) {
            throw new \RuntimeException('Agent binding or local scope is unavailable.');
        }
        if (!$confirmed) {
            return ['connection' => $connection, 'registration' => $registration, 'asset' => $computer->fields];
        }
        EntitySyncRoute::loadAll(true);
        $match = EntitySyncRoute::matchAsset('Computer', (string) $pair['local_entity'], [], $pair['connection_id']);
        $route = $match['matches'][0]['route'] ?? null;
        $link = AssetSyncLink::find('Computer', (int) $pair['local_id'], $pair['connection_id']);
        $links = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
            'itemtype' => 'Computer', 'glpi_b_connection_id' => $pair['connection_id'], 'remote_items_id' => (int) $pair['computer_id']], 'LIMIT' => 2]));
        if (!$pair['confirmed'] || !$route || count($match['matches']) !== 1 || $match['conflicts'] !== []
            || $route['id'] !== $pair['route_id'] || $route['include_child_entities'] || $route['asset_types'] !== ['Computer']
            || (int) $route['glpi_b_target_entity_id'] !== (int) $pair['entity_id'] || $route['glpi_b_target_entity_id'] === ''
            || trim((string) $computer->fields['serial']) !== $pair['serial'] || !$link || count($links) !== 1
            || (int) $link['remote_items_id'] !== (int) $pair['computer_id'] || $link['confirmed_identity'] !== AgentInbox::identity($connection)
            || (json_decode($registration['routes'], true)[$route['id']]['fingerprint'] ?? '') !== AgentInbox::routeIdentity($route)) {
            throw new \RuntimeException('Agent pair, serial or exact route changed.');
        }
        return ['connection' => $connection, 'registration' => $registration, 'asset' => $computer->fields, 'route' => $route];
    }

    public static function inspect(string $connectionId): void
    {
        $pair = self::pair($connectionId);
        if (!$pair) { throw new \RuntimeException('Declare an agent pair first.'); }
        self::publish($pair, 'inspect', [], null, []);
    }

    public static function confirm(string $connectionId, string $routeId): void
    {
        global $DB;
        $pair = self::pair($connectionId);
        if (!$pair) { throw new \RuntimeException('Declare an agent pair first.'); }
        AssetUuidOperation::withAssetLock('Computer', (int) $pair['local_id'], static function () use ($pair, $routeId, $DB): void {
            $scope = self::scope($pair, false);
            $item = new \Computer();
            if (!$item->can((int) $pair['local_id'], READ)) { throw new \RuntimeException('Computer unavailable.'); }
            $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            $route = EntitySyncRoute::find($routeId);
            if ((int) $pair['observed_at'] < time() - 300 || empty($snapshot['serial'])
                || $snapshot['serial'] !== trim((string) $scope['asset']['serial'])
                || ($snapshot['computer_id'] ?? null) !== (int) $pair['computer_id'] || ($snapshot['entity_id'] ?? null) !== (int) $pair['entity_id']
                || !$route || !$route['active'] || $route['include_child_entities'] || $route['asset_types'] !== ['Computer']
                || $route['glpi_b_connection_id'] !== $pair['connection_id'] || $route['glpi_b_target_entity_id'] === ''
                || (int) $route['glpi_a_source_entity_id'] !== (int) $pair['local_entity']
                || (int) $route['glpi_b_target_entity_id'] !== (int) $pair['entity_id']) {
                throw new \RuntimeException('Fresh independent inspection, matching serial and an exact Computer route are required.');
            }
            $link = AssetSyncLink::find('Computer', (int) $pair['local_id'], $pair['connection_id']);
            if ($link && (int) $link['remote_items_id'] !== (int) $pair['computer_id']) {
                throw new \RuntimeException('Existing link differs; automatic relinking is forbidden.');
            }
            $duplicates = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
                'itemtype' => 'Computer', 'glpi_b_connection_id' => $pair['connection_id'], 'remote_items_id' => (int) $pair['computer_id']], 'LIMIT' => 2]));
            if (count($duplicates) > ($link ? 1 : 0)) { throw new \RuntimeException('Reverse link is not unique.'); }
            AgentInbox::sql('START TRANSACTION');
            try {
                if (!$link) {
                    self::write(AssetSyncLink::saveStatus('Computer', (int) $pair['local_id'], $pair['connection_id'], $routeId,
                        AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'Agent pair independently confirmed.', (int) $pair['computer_id']));
                }
                self::write($DB->update(AssetSyncLink::TABLE, ['route_id' => $routeId, 'confirmed_remote_id' => (int) $pair['computer_id'],
                    'confirmed_entity_id' => (int) $pair['entity_id'], 'confirmed_identity' => AgentInbox::identity($scope['connection'])],
                    ['itemtype' => 'Computer', 'items_id' => (int) $pair['local_id'], 'glpi_b_connection_id' => $pair['connection_id']]));
                self::write($DB->update(AgentInbox::REGISTRATIONS, ['routes' => json_encode([$routeId => [
                    'fingerprint' => AgentInbox::routeIdentity($route), 'source_entity' => (int) $pair['local_entity']]], JSON_THROW_ON_ERROR)], ['id' => $pair['registration']]));
                self::write($DB->update(self::PAIRS, ['confirmed' => 1, 'route_id' => $routeId, 'serial' => $snapshot['serial']], ['connection_id' => $pair['connection_id']]));
                self::scope(self::pair($pair['connection_id']));
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        });
    }

    public static function catalog(string $connection, string $type): array
    {
        $pair = self::pair($connection);
        $fields = [];
        if ($pair && $type === 'Computer' && (int) $pair['observed_at'] >= time() - 3600) {
            self::scope($pair, false);
            $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            foreach ($snapshot['fields'] ?? [] as $key => $field) {
                $fields[] = ['key' => $key, 'uid' => $key, 'id' => (string) $field['option'], 'label' => $field['label'],
                    'type' => 'text', 'group' => $field['group'], 'supported' => true, 'reason' => ''];
            }
        }
        return ['success' => $fields !== [], 'fields' => $fields, 'message' => $fields === []
            ? 'No fresh scoped agent metadata. Declare the pair and request inspection on Agent Pair.' : 'Scoped B observation available.'];
    }

    public static function validateMappings(string $type, array $rows): void
    {
        if ($type !== 'Computer' || count($rows) > 32) { throw new \RuntimeException('Agent mode supports one Computer and at most 32 text mappings.'); }
        foreach ($rows as $row) {
            if (!in_array($row['source_of_truth'] ?? '', ['glpi_a', 'glpi_b'], true)) { throw new \RuntimeException('Agent mode requires one-way mapping authority.'); }
            foreach (['glpi_a_field_key', 'glpi_b_field_key'] as $side) {
                $key = $row[$side] ?? '';
                if (!in_array($key, ['name', 'comment', 'Computer.name', 'Computer.comment'], true)
                    && !preg_match('/^Computer\.PluginFields[A-Za-z0-9]+\.[a-z][a-z0-9_]*$/D', $key)) {
                    throw new \RuntimeException('Unsupported agent mapping: Name, Comments and existing custom text only; UUID is unsupported.');
                }
            }
        }
    }

    /** The caller owns no DB transaction. Task publication and releasing its queue claim commit together. */
    public static function publish(array $pair, string $kind, array $changes, ?array $job, array $context, ?string $previous = null): array
    {
        global $DB;
        $scope = self::scope($pair, $job !== null);
        $task = ['version' => 1, 'id' => bin2hex(random_bytes(16)), 'kind' => $kind,
            'installation' => $pair['installation'], 'registration' => $pair['registration'], 'generation' => $scope['registration']['generation'],
            'connection_id' => $pair['connection_id'], 'computer_id' => (int) $pair['computer_id'], 'entity_id' => (int) $pair['entity_id'],
            'serial' => $pair['confirmed'] ? $pair['serial'] : '', 'policy' => $context['policy'] ?? '',
            'expires' => time() + 300, 'changes' => $changes];
        $task['hash'] = self::hash($task);
        AgentInbox::sql('START TRANSACTION');
        try {
            if ($job !== null && !AssetSyncQueue::owns($job)) { throw new \RuntimeException('Queue claim changed.'); }
            if ($previous !== null) {
                self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $previous, 'slot' => $pair['connection_id']]));
                if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Previous task changed.'); }
            }
            self::write($DB->insert(self::TABLE, ['id' => $task['id'], 'connection_id' => $pair['connection_id'], 'slot' => $pair['connection_id'],
                'hash' => $task['hash'], 'body' => json_encode($task, JSON_THROW_ON_ERROR), 'state' => 'accepted', 'result' => '{}',
                'queue_id' => $job['id'] ?? null, 'job' => json_encode($job ?? [], JSON_THROW_ON_ERROR),
                'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => time(), 'expires' => $task['expires']]));
            if ($job !== null) { self::write(AssetSyncQueue::waitForAgent($job)); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        return $task;
    }

    /** Auth is checked for every pull/result, independently of notification ACKs. */
    public static function exchange(array $request, string $token): array
    {
        global $DB;
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !in_array($request['kind'] ?? '', ['pull', 'result'], true)) {
            return ['status' => 401];
        }
        AgentInbox::sql('START TRANSACTION');
        try {
            $pair = AgentInbox::one('SELECT * FROM `' . self::PAIRS . '` WHERE `registration` = ' . $DB->quote((string) ($request['registration'] ?? '')) . ' FOR UPDATE');
            if (!$pair) { throw new \UnexpectedValueException('Unknown pair.'); }
            try { $scope = self::scope($pair, (bool) $pair['confirmed']); }
            catch (\RuntimeException $error) { throw new \UnexpectedValueException('Current task scope is unavailable.', 0, $error); }
            if (!hash_equals($scope['registration']['token_hash'], hash('sha256', $token))
                || ($request['generation'] ?? '') !== $scope['registration']['generation']
                || ($request['installation'] ?? '') !== $pair['installation'] || ($request['connection_id'] ?? '') !== $pair['connection_id']) {
                throw new \UnexpectedValueException('Task authentication failed.');
            }
            $task = self::outstanding($pair['connection_id']);
            if ($request['kind'] === 'pull') {
                $response = ['status' => 200, 'task' => null];
                if ($task && in_array($task['state'], ['accepted', 'executing'], true)) {
                    $policyCurrent = true;
                    if ($task['queue_id'] !== null) {
                        try {
                            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
                            $policyCurrent = $expected['policy'] === self::policy($pair);
                        } catch (\Throwable) { $policyCurrent = false; }
                    }
                    if ((int) $task['expires'] < time() || !$policyCurrent) {
                        // A delivered task may have written before losing its result. Never issue another write.
                        $outcome = $task['state'] === 'executing' ? 'uncertain' : 'stale';
                        self::storeResult($task, ['outcome' => $outcome, 'changed' => []]);
                    } else {
                        self::write($DB->update(self::TABLE, ['state' => 'executing'], ['id' => $task['id']]));
                        $response['task'] = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                    }
                }
            } else {
                $id = $request['id'] ?? '';
                $saved = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote((string) $id) . ' FOR UPDATE');
                $result = $request['result'] ?? null;
                if (!$saved || $saved['connection_id'] !== $pair['connection_id'] || ($request['hash'] ?? '') !== $saved['hash']
                    || !is_array($result) || !in_array($result['outcome'] ?? '', ['verified', 'unchanged', 'blocked', 'stale', 'partial', 'uncertain'], true)
                    || !is_array($result['changed'] ?? null)) { throw new \UnexpectedValueException('Invalid task result.'); }
                $body = json_decode($saved['body'], true, 32, JSON_THROW_ON_ERROR);
                if ($body['generation'] !== $request['generation'] || $body['installation'] !== $request['installation']) { throw new \UnexpectedValueException('Stale result binding.'); }
                if ($saved['result'] === '{}') {
                    if (!$task || $task['id'] !== $saved['id']) { throw new \UnexpectedValueException('No matching outstanding task.'); }
                    try { self::validateResult($body, $result); }
                    catch (\RuntimeException $error) { throw new \UnexpectedValueException('Invalid task evidence.', 0, $error); }
                    self::storeResult($saved, $result, (int) $saved['expires'] >= time());
                } elseif (self::hash(json_decode($saved['result'], true)) !== self::hash($result)) {
                    throw new \UnexpectedValueException('Conflicting result replay.');
                }
                $response = ['status' => 200, 'recorded' => true, 'id' => $id, 'hash' => $saved['hash']];
            }
            AgentInbox::sql('COMMIT');
            return $response;
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function validateResult(array $task, array $result): void
    {
        if (!in_array($result['outcome'], ['verified', 'unchanged'], true)) { return; }
        $snapshot = $result['snapshot'] ?? [];
        if (($snapshot['computer_id'] ?? null) !== $task['computer_id'] || ($snapshot['entity_id'] ?? null) !== $task['entity_id']
            || !is_string($snapshot['serial'] ?? null) || $snapshot['serial'] === ''
            || ($task['serial'] !== '' && $snapshot['serial'] !== $task['serial'])
            || !is_int($snapshot['observed_at'] ?? null) || $snapshot['observed_at'] > $task['expires']
            || $snapshot['observed_at'] < $task['expires'] - 300
            || !is_array($snapshot['fields'] ?? null) || count($snapshot['fields']) > 32) { throw new \RuntimeException('Invalid independent snapshot.'); }
        foreach ($snapshot['fields'] as $key => $field) {
            if (!is_array($field) || ($field['key'] ?? '') !== $key || ($field['type'] ?? '') !== 'text'
                || !is_string($field['value'] ?? null) || strlen($field['value']) > 16384
                || !is_string($field['label'] ?? null) || !is_int($field['option'] ?? null) || $field['option'] <= 0
                || !preg_match('/^[a-f0-9]{64}$/D', $field['metadata'] ?? '')
                || (!in_array($key, ['name', 'comment'], true) && !preg_match('/^Computer\.PluginFields[A-Za-z0-9]+\.[a-z][a-z0-9_]*$/D', $key))) {
                throw new \RuntimeException('Invalid scoped metadata.');
            }
        }
        foreach ($task['changes'] as $key => $change) {
            if (($snapshot['fields'][$key]['value'] ?? null) !== $change['value']
                || ($snapshot['fields'][$key]['metadata'] ?? '') !== $change['metadata']
                || ($change['expected'] !== $change['value'] && !is_int($result['changed'][$key] ?? null))) {
                throw new \RuntimeException('Result lacks readback or named history evidence.');
            }
        }
    }

    private static function storeResult(array $task, array $result, bool $fresh = true): void
    {
        global $DB;
        self::write($DB->update(self::TABLE, ['result' => json_encode($result, JSON_THROW_ON_ERROR),
            'state' => !$fresh && in_array($result['outcome'], ['verified', 'unchanged'], true) ? 'stale' : $result['outcome']], ['id' => $task['id']]));
        if ($task['queue_id'] !== null) {
            self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']));
        } else {
            if ($fresh && isset($result['snapshot']) && in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                self::write($DB->update(self::PAIRS, ['snapshot' => json_encode($result['snapshot'], JSON_THROW_ON_ERROR),
                    'observed_at' => min((int) $result['snapshot']['observed_at'], time(), (int) $task['expires'])], ['connection_id' => $task['connection_id']]));
            }
            self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $task['id']]));
        }
    }

    public static function write(bool $ok): void
    {
        if (!$ok) { throw new \RuntimeException('Agent task storage write failed.'); }
    }

    public static function context(array $pair): array
    {
        global $DB;
        // Agent results may arrive in a later profile or worker pass; metadata must be fresh.
        FieldMapping::endRunCache();
        FieldsText::endRunCache();
        $scope = self::scope($pair);
        $rows = FieldMapping::load($pair['connection_id'], 'Computer');
        self::validateMappings('Computer', $rows);
        $mappings = FieldMapping::syncMappings($pair['connection_id'], 'Computer', $scope['asset']);
        if ($mappings === []) { throw new \RuntimeException('No supported agent mappings.'); }
        $values = [];
        $metadata = [];
        foreach ($mappings as $mapping) {
            $key = $mapping['glpi_a_field'];
            if (isset($values[$key])) { continue; }
            if (in_array($key, ['name', 'comment'], true)) {
                $values[$key] = (string) ($scope['asset'][$key] ?? '');
                $metadata[$key] = NativeField::local('Computer', $key);
            } else {
                $meta = FieldsText::customMetadata('Computer', [$key])[$key];
                if ($meta['type'] !== 'text') { throw new \RuntimeException('Agent custom mappings require text fields.'); }
                $container = new \PluginFieldsContainer();
                $definition = new \PluginFieldsField();
                if (!$definition->getFromDB($meta['field_id'])) { throw new \RuntimeException('Local Fields definition is unavailable.'); }
                $meta['container_id'] = (int) $definition->fields['plugin_fields_containers_id'];
                if (!$container->getFromDB($meta['container_id']) || !$definition->fields['is_active'] || $definition->fields['type'] !== 'text'
                    || !$container->fields['is_active'] || $container->fields['is_recursive']
                    || (int) $container->fields['entities_id'] !== (int) $pair['local_entity']) {
                    throw new \RuntimeException('Local Fields scope is unsupported.');
                }
                $children = iterator_to_array($DB->request(['FROM' => $meta['table'], 'WHERE' => [
                    'items_id' => (int) $pair['local_id']], 'LIMIT' => 2]));
                if (count($children) !== 1) { throw new \RuntimeException('Agent mode requires exactly one existing local custom row.'); }
                $child = array_values($children)[0];
                if (($child['itemtype'] ?? '') !== 'Computer' || (int) ($child['plugin_fields_containers_id'] ?? 0) !== $meta['container_id']) {
                    throw new \RuntimeException('Local custom row identity changed.');
                }
                $values[$key] = (string) ($child[$meta['field']] ?? '');
                $metadata[$key] = self::hash([$meta, $container->fields, $definition->fields, (int) $child['id']]);
            }
        }
        return ['policy' => self::policy($pair), 'metadata' => self::hash($metadata), 'values' => $values, 'mappings' => $mappings];
    }

    public static function policy(array $pair): string
    {
        FieldMapping::endRunCache();
        $scope = self::scope($pair);
        return self::hash([FieldMapping::load($pair['connection_id'], 'Computer'), $scope['route'],
            $scope['registration']['generation'], $pair['installation'], $scope['registration']['connection_identity'],
            \Config::getConfigurationValues('plugin:assetsync20', ['billing_fields'])]);
    }

    public static function process(array $job, callable $applyLocal): void
    {
        global $DB;
        $pair = self::pair($job['glpi_b_connection_id']);
        $task = self::outstanding($job['glpi_b_connection_id']);
        try {
            if ($task && $task['state'] === 'finalizing') {
                self::write($DB->update(self::TABLE, ['state' => 'uncertain'], ['id' => $task['id'], 'state' => 'finalizing']));
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            if (($job['itemtype'] ?? '') !== 'Computer' || !$pair || (int) $pair['local_id'] !== (int) $job['items_id']) {
                throw new \RuntimeException('Agent mode permits only the declared existing Computer.');
            }
            $context = self::context($pair);
            if (!$task) {
                self::publish($pair, 'inspect', [], $job, $context);
                return;
            }
            if ((int) $task['queue_id'] !== (int) $job['id']) { throw new \RuntimeException('Another agent operation is outstanding.'); }
            if ($task['result'] === '{}') {
                self::park($job, $task);
                return;
            }
            $captured = json_decode($task['job'], true, 32, JSON_THROW_ON_ERROR);
            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!in_array($task['state'], ['verified', 'unchanged'], true)
                || !in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            if ((int) $body['expires'] < time() || $expected['policy'] !== $context['policy'] || $expected['values'] !== $context['values']
                || $expected['metadata'] !== $context['metadata']
                || $body['serial'] !== $pair['serial'] || $body['generation'] !== self::scope($pair)['registration']['generation']) {
                self::write($DB->update(self::TABLE, ['state' => 'stale'], ['id' => $task['id']]));
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            // Only the original captured notification generation may be completed by this chain.
            $job['agent_snapshot'] = (int) ($captured['agent_snapshot'] ?? 0);
            $context['recheck'] = !empty($expected['recheck']) || !empty($job['previous_needs_recheck']);
            if ($body['kind'] === 'inspect') {
                $changes = [];
                foreach ($context['mappings'] as $mapping) {
                    $key = $mapping['glpi_b_field'];
                    $field = $result['snapshot']['fields'][$key] ?? null;
                    if (!$field || $field['type'] !== 'text') { throw new \RuntimeException('Mapped B text field or existing row is unavailable.'); }
                    $changes[$key] = ['metadata' => $field['metadata'], 'expected' => $field['value'], 'value' =>
                        $mapping['source_of_truth'] === 'glpi_a' ? $context['values'][$mapping['glpi_a_field']] : $field['value']];
                }
                self::publish($pair, 'apply', $changes, $job, $context, $task['id']);
                return;
            }
            if (!AssetSyncQueue::owns($job)) { return; }
            // This durable marker prevents replay after process death during any A-side model/billing write.
            self::write($DB->update(self::TABLE, ['state' => 'finalizing'], ['id' => $task['id'], 'state' => $task['state']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Task already finalized.'); }
            $localChanges = [];
            foreach ($context['mappings'] as $mapping) {
                if ($mapping['source_of_truth'] === 'glpi_b') {
                    $value = $result['snapshot']['fields'][$mapping['glpi_b_field']]['value'];
                    if ($context['values'][$mapping['glpi_a_field']] !== $value) { $localChanges[$mapping['glpi_a_field']] = $value; }
                }
            }
            AgentInbox::sql('START TRANSACTION');
            try {
                self::lockLocalRows($pair, array_keys($context['values']));
                $current = self::context($pair);
                if ($current['policy'] !== $context['policy'] || $current['values'] !== $context['values'] || $current['metadata'] !== $context['metadata']) {
                    throw new \RuntimeException('Local expected values changed.');
                }
                $billingChanged = $applyLocal($localChanges, $context['mappings']) === true;
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
            $outcome = $billingChanged || $localChanges !== [] || $result['changed'] !== [] ? 'verified' : 'unchanged';
            self::finish($task, $job, $outcome, $context['recheck'] || $billingChanged, self::localFingerprint(self::context($pair)));
        } catch (\Throwable) {
            if ($task) {
                $current = self::outstanding($job['glpi_b_connection_id']);
                if ($current && $current['id'] === $task['id']) {
                    self::write($DB->update(self::TABLE, ['state' => $current['state'] === 'finalizing' ? 'uncertain' : 'blocked'], ['id' => $task['id']]));
                }
            }
            // A failed task remains outstanding for inspection. Never silently republish an uncertain write.
            if (AssetSyncQueue::owns($job)) {
                if ($task) { self::write(AssetSyncQueue::waitForAgent($job)); }
                else { AssetSyncQueue::retry($job, 'Blocked before task publication: agent scope or mapping is unsupported; inspect Agent Pair configuration.'); }
            }
        }
    }

    private static function finish(array $task, array $job, string $outcome, bool $recheck, ?string $fingerprint = null): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            if (!AssetSyncQueue::owns($job)) { throw new \RuntimeException('Queue claim changed before finalization.'); }
            self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'done', 'claim_token' => '',
                'agent_completed' => (int) $job['agent_snapshot'], 'needs_recheck' => new \Glpi\DBAL\QueryExpression('GREATEST(`needs_recheck`, ' . (int) $recheck . ')'),
                'last_error' => 'Agent task ' . $outcome . '.', 'finished_at' => new \Glpi\DBAL\QueryExpression('NOW()')],
                ['id' => (int) $job['id'], 'claim_token' => $job['claim_token'], 'status' => 'running']));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Queue finalization lost ownership.'); }
            AgentInbox::sql('UPDATE `' . AgentInbox::RECEIPTS . '` SET `outcome` = ' . $DB->quote($outcome)
                . ', `completed_revision` = `admitted_revision`, `outcome_at` = ' . time()
                . ' WHERE `queue_id` = ' . (int) $job['id'] . ' AND `work_generation` <= ' . (int) $job['agent_snapshot']);
            self::write($DB->update(self::TABLE, ['state' => $outcome, 'slot' => null], ['id' => $task['id'], 'state' => 'finalizing']));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Task finalization lost ownership.'); }
            if ($fingerprint !== null) {
                self::write($DB->update(self::PAIRS, ['local_fingerprint' => $fingerprint, 'checked_at' => time()], ['connection_id' => $task['connection_id']]));
            }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function park(array $job, array $task): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            $current = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' FOR UPDATE');
            if (!$current || !AssetSyncQueue::owns($job)) { throw new \RuntimeException('Task claim changed.'); }
            self::write(AssetSyncQueue::waitForAgent($job));
            // Result receipt takes this same task lock: it either wakes us later or is already visible here.
            if ($current['result'] !== '{}') {
                self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $job['id'], 'status' => 'waiting_agent']));
            }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function lockLocalRows(array $pair, array $keys): void
    {
        global $DB;
        $tables = ['glpi_computers' => 'id'];
        $keys = array_merge($keys, HardwareBilling::localOutputKeys('Computer'), SwsdBilling::localOutputKeys('Computer'));
        foreach (FieldsText::customMetadata('Computer', $keys) as $meta) { $tables[$meta['table']] = 'items_id'; }
        foreach ($tables as $table => $column) {
            $engine = AgentInbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $DB->quote($table));
            if (strtolower((string) ($engine['ENGINE'] ?? '')) !== 'innodb') { throw new \RuntimeException('Transactional local storage is required.'); }
            AgentInbox::sql('SELECT `id` FROM `' . $table . '` WHERE `' . $column . '` = ' . (int) $pair['local_id'] . ' FOR UPDATE');
            if ($column === 'items_id') {
                $rows = iterator_to_array($DB->request(['FROM' => $table, 'WHERE' => ['items_id' => (int) $pair['local_id']], 'LIMIT' => 2]));
                if (count($rows) !== 1 || (array_values($rows)[0]['itemtype'] ?? '') !== 'Computer') {
                    throw new \RuntimeException('Existing local mapped/billing child row required.');
                }
            }
        }
    }

    /** Explicit administrator retry is restricted to tasks known not to have attempted a write. */
    public static function retryStale(string $connection): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            $task = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote($connection) . ' FOR UPDATE');
            $body = $task ? json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR) : [];
            $result = $task ? json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR) : [];
            if (!$task || !in_array($task['state'], ['stale', 'blocked'], true)
                || ($body['kind'] !== 'inspect' && !(($result['outcome'] ?? '') === 'stale' && ($result['changed'] ?? null) === []))) {
                throw new \RuntimeException('Only a confirmed no-write stale operation can be retried. Partial or uncertain evidence requires investigation.');
            }
            self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $task['id']]));
            if ($task['queue_id'] !== null) { self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent'])); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }
}
