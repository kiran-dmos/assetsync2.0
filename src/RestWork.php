<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Confirmed routes use durable stock REST records through the ordinary A queue. */
final class RestWork
{
    private const RESOURCE = 'GlpiPlugin%5CAssetsync20agent%5CRestWork';
    private const SETTINGS = 'GlpiPlugin%5CAssetsync20agent%5CRestSettings';
    private const DISPATCH_CURSOR = 'rest_work_dispatch_cursor_v1';
    private static array $batches = [];
    private static bool $configuring = false;

    public static function beginRun(): void { self::$batches = []; }

    /** Serialize saved rules and their revision together; never do network I/O under this lock. */
    public static function configure(callable $save, ?bool $required = null): void
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'doQuery')) { $save(); return; }
        if (!$required && !array_filter(GlpiBConnection::loadAll(false, false), static fn (array $c): bool => ($c['agent_transport'] ?? 'adapter') === 'rest_records')) { $save(); return; }
        if (!AgentWake::transactionIdle()) { throw new \RuntimeException('REST settings require an idle database connection.'); }
        $lock = $DB->quote('assetsync20:settings:' . substr(hash('sha256', (string) ($DB->dbdefault ?? 'glpi')), 0, 32));
        if ((int) (AgentInbox::one('SELECT COALESCE(IS_USED_LOCK(' . $lock . ') = CONNECTION_ID(), 0) AS owned')['owned'] ?? 1) !== 0) { throw new \RuntimeException('REST settings lock already owned.'); }
        if ((int) (AgentInbox::one('SELECT GET_LOCK(' . $lock . ', 0) AS acquired')['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Settings are busy; retry the save.');
        }
        try {
            self::$configuring = true;
            AgentInbox::sql('START TRANSACTION');
            try { $save(); self::refreshSettings(); AgentInbox::sql('COMMIT'); }
            catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        } finally {
            self::$configuring = false;
            if ((int) (AgentInbox::one('SELECT RELEASE_LOCK(' . $lock . ') AS released')['released'] ?? 0) !== 1) { throw new \RuntimeException('REST settings lock release unavailable.'); }
        }
    }

    /** The three shared JSON documents participate in the same owned Config transaction. */
    public static function saveConfiguration(array $values): void
    {
        \Config::setConfigurationValues(GlpiBConnection::CONTEXT, $values);
        if (!self::$configuring) { return; }
        $saved = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, array_keys($values));
        foreach ($values as $key => $value) {
            if (($saved[$key] ?? null) !== $value) { throw new \RuntimeException('REST configuration persistence failed.'); }
        }
    }

    private static function settingsState(): array
    {
        $raw = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2'])['rest_settings_v2'] ?? '{}';
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state)) { throw new \RuntimeException('REST settings state unavailable.'); }
        return $state;
    }

    private static function storeSettings(array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2' => $json]);
        if ((\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_v2'])['rest_settings_v2'] ?? null) !== $json) { throw new \RuntimeException('REST settings persistence failed.'); }
    }

    private static function settingsKey(array $body): string
    {
        $scope = [];
        foreach (['installation', 'registration', 'generation', 'connection_id', 'route_id'] as $key) { $scope[$key] = $body[$key]; }
        return AgentTask::hash($scope);
    }

    /** The caller owns the short configuration lock. Deleted routes retain their last scope. */
    private static function refreshSettings(): void
    {
        global $DB;
        $connections = array_filter(GlpiBConnection::loadAll(false, false), static fn (array $c): bool => GlpiBConnection::isAgent($c) && ($c['agent_transport'] ?? 'adapter') === 'rest_records');
        if ($connections === [] || !$DB->tableExists(AgentTask::BINDINGS)) { return; }
        $state = self::settingsState(); $before = $state;
        $conflicted = [];
        foreach (EntitySyncRoute::findConfigConflicts() as $conflict) {
            if ($conflict['itemtype'] === 'Computer') { foreach ($conflict['route_ids'] as $id) { $conflicted[$id] = true; } }
        }
        foreach ($connections as $connection) {
            if (!$connection['active']) { continue; }
            $binding = AgentTask::binding($connection['id']);
            if (!$binding) { continue; }
            $registration = AgentTask::registration($binding);
            $routes = [];
            foreach (EntitySyncRoute::loadAll(true) as $route) {
                if ($route['glpi_b_connection_id'] === $connection['id']) { $routes[$route['id']] = $route; }
            }
            // Include removed routes, so deletion produces a durable disable tombstone.
            foreach ($state as $entry) {
                $old = json_decode($entry['body'], true, 32, JSON_THROW_ON_ERROR);
                if ($old['connection_id'] === $connection['id'] && $old['registration'] === $registration['id']
                    && $old['generation'] === $registration['generation'] && $old['installation'] === $binding['installation']
                    && !isset($routes[$old['route_id']])) { $routes[$old['route_id']] = null; }
            }
            foreach ($routes as $routeId => $route) {
                $base = ['version' => 3, 'installation' => $binding['installation'], 'registration' => $registration['id'],
                    'generation' => $registration['generation'], 'connection_id' => $connection['id'], 'route_id' => (string) $routeId];
                $key = self::settingsKey($base);
                $old = isset($state[$key]) ? json_decode($state[$key]['body'], true, 32, JSON_THROW_ON_ERROR) : null;
                if ($route === null && $old === null) { continue; }
                $mappings = [];
                FieldMapping::endRunCache();
                $rows = FieldMapping::load($connection['id'], 'Computer');
                if (count($rows) === 1) {
                    $row = array_values($rows)[0];
                    if (in_array($row['glpi_a_field_key'] ?? '', ['name', 'Computer.name'], true)
                        && in_array($row['glpi_b_field_key'] ?? '', ['name', 'Computer.name'], true) && in_array($row['source_of_truth'] ?? '', ['glpi_a', 'both'], true)) {
                        $mappings = [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => $row['source_of_truth']]];
                    }
                }
                // A route that reached Both cannot silently fall back to A authority, even at a higher revision.
                $both = ($mappings[0]['source_of_truth'] ?? '') === 'both' || ($old['version'] ?? 0) === 4;
                if ($both) {
                    $base['version'] = 4;
                    $base += ['include_child_entities' => false, 'asset_types' => ['Computer']];
                    if (($mappings[0]['source_of_truth'] ?? '') !== 'both') { $mappings = []; }
                } else {
                    $types = $route ? $route['asset_types'] : ($old['asset_types'] ?? ['Computer']);
                    sort($types, SORT_STRING);
                    $base += ['include_child_entities' => $route ? $route['include_child_entities'] : ($old['include_child_entities'] ?? false), 'asset_types' => $types];
                }
                $base += ['source_entity' => $route ? (int) $route['glpi_a_source_entity_id'] : $old['source_entity'],
                    'target_entity' => $route ? (int) $route['glpi_b_target_entity_id'] : $old['target_entity'],
                    'itemtype' => 'Computer', 'mappings' => $mappings,
                    'enabled' => $route !== null && $route['active'] && in_array('Computer', $route['asset_types'], true) && !isset($conflicted[$routeId])
                        && (!$both || (!$route['include_child_entities'] && $route['asset_types'] === ['Computer']))
                        && $route['glpi_b_target_entity_id'] !== '' && $route['glpi_a_source_entity_id'] !== '' && $mappings !== []];
                $effective = AgentTask::hash($base);
                if (($state[$key]['effective'] ?? '') === $effective) { continue; }
                $document = $base + ['revision' => ($old['revision'] ?? 0) + 1];
                $document = ['version' => $base['version'], 'id' => substr(AgentTask::hash($document), 0, 32)] + $document;
                $state[$key] = ['effective' => $effective, 'body' => json_encode($document, JSON_THROW_ON_ERROR),
                    'hash' => AgentTask::hash($document), 'pending' => true, 'next' => 0];
            }
        }
        if ($state !== $before) { self::storeSettings($state); }
    }

    /** Configuration publication is independent of eligible assets and excludes disabled connections. */
    private static function publishSettings(int $deadline): void
    {
        self::configure(static function (): void {});
        $chosen = null;
        self::configure(static function () use (&$chosen): void {
            $state = self::settingsState();
            $due = [];
            foreach ($state as $key => $entry) {
                if (!$entry['pending'] || $entry['next'] > time()) { continue; }
                $body = json_decode($entry['body'], true, 32, JSON_THROW_ON_ERROR);
                $connection = AgentInbox::connection($body['connection_id']);
                if (!$connection || ($connection['agent_transport'] ?? 'adapter') !== 'rest_records') { continue; }
                $binding = AgentTask::binding($connection['id']);
                if (!$binding || $binding['registration'] !== $body['registration'] || $binding['installation'] !== $body['installation']
                    || AgentTask::registration($binding)['generation'] !== $body['generation']) { continue; }
                $due[$connection['id']][] = ['key' => $key, 'entry' => $entry, 'body' => $body, 'connection' => $connection];
            }
            if ($due === []) { return; }
            ksort($due, SORT_STRING);
            $cursor = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_cursor'])['rest_settings_cursor'] ?? '';
            $id = (string) array_key_first($due);
            foreach (array_keys($due) as $candidate) { if (strcmp((string) $candidate, $cursor) > 0) { $id = (string) $candidate; break; } }
            usort($due[$id], static fn (array $a, array $b): int => [$a['entry']['next'], $a['key']] <=> [$b['entry']['next'], $b['key']]);
            $chosen = $due[$id][0]; $key = $chosen['key'];
            $state[$key]['failures'] = min(4, ($state[$key]['failures'] ?? 0) + 1);
            $state[$key]['next'] = time() + min(60, 10 * (2 ** ($state[$key]['failures'] - 1)));
            self::storeSettings($state);
            self::saveConfiguration(['rest_settings_cursor' => $id]);
        });
        if ($chosen === null || hrtime(true) + 1_000_000_000 >= $deadline) { return; }
        $connection = AgentInbox::connection($chosen['connection']['id']);
        if (!$connection || $connection !== $chosen['connection']) { return; }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $client = new RestSession($connection, $deadline);
        try {
            $response = $client->request('POST', self::SETTINGS, ['settings_id' => $chosen['body']['id'], 'hash' => $chosen['entry']['hash'], 'body' => $chosen['entry']['body']]);
            $valid = $response['status'] === 201 && is_int($response['body']['id'] ?? null) && $response['body']['id'] > 0;
            $diagnostic = $client->diagnostic();
            $diagnostic['phase'] = $diagnostic['phase'] === 'init_session' ? 'init_session' : 'settings_post';
            if (!$valid && $diagnostic['error'] === '') { $diagnostic['error'] = 'invalid_response'; }
            $diagnostic['at'] = time();
            self::configure(static function () use ($chosen, $response, $valid, $diagnostic): void {
                $state = self::settingsState();
                if (($state[$chosen['key']]['hash'] ?? '') !== $chosen['entry']['hash']) { return; }
                $state[$chosen['key']]['pending'] = !$valid;
                if ($valid) { $state[$chosen['key']]['failures'] = 0; }
                $state[$chosen['key']]['dispatch'] = $diagnostic;
                self::storeSettings($state);
                if (!$valid) { return; }
                $receipts = json_decode(\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts'])['rest_settings_receipts'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $key = hash('sha256', $chosen['connection']['id'] . $chosen['connection']['agent_rest_url'] . $chosen['entry']['hash']);
                $receipts[$key] = $response['body']['id'];
                \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode($receipts, JSON_THROW_ON_ERROR)]);
            });
        } finally { $client->close(); }
    }

    public static function settingsStatus(string $connectionId): array
    {
        $rows = [];
        $binding = AgentTask::binding($connectionId);
        if (!$binding || !AgentInbox::connection($connectionId)) { return []; }
        $registration = AgentTask::registration($binding);
        foreach (self::settingsState() as $entry) {
            $body = json_decode($entry['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($body['connection_id'] !== $connectionId || $body['registration'] !== $registration['id']
                || $body['generation'] !== $registration['generation'] || $body['installation'] !== $binding['installation']) { continue; }
            $rows[] = ['route' => $body['route_id'], 'revision' => $body['revision'], 'enabled' => $body['enabled'],
                'pending' => $entry['pending'], 'status' => (int) ($entry['dispatch']['status'] ?? 0), 'error' => (string) ($entry['dispatch']['error'] ?? '')];
        }
        return $rows;
    }

    public static function requestRead(string $connectionId, int $entity, ?array $pair = null): void
    {
        if ($entity < 0 || ($pair && $pair['itemtype'] !== 'Computer')) { throw new \RuntimeException('REST inspection supports Computer only.'); }
        self::configure(static function () use ($connectionId, $entity, $pair): void {
            global $DB;
            $connection = AgentInbox::connection($connectionId);
            if (!$connection || ($connection['agent_transport'] ?? '') !== 'rest_records' || !RestSession::validUrl($connection['agent_rest_url'])) { throw new \RuntimeException('REST records connection unavailable.'); }
            $binding = AgentTask::binding($connectionId);
            if (!$binding) { throw new \RuntimeException('Register the B installation first.'); }
            $registration = AgentTask::registration($binding);
            if ($pair) {
                AgentTask::selectedAsset('Computer', (int) $pair['local_id'], (int) $pair['local_entity']);
                if ($pair['connection_id'] !== $connectionId || $pair['registration'] !== $registration['id'] || $pair['installation'] !== $binding['installation'] || (int) $pair['entity_id'] !== $entity) { throw new \RuntimeException('REST candidate binding changed.'); }
            }
            $body = ['version' => 9, 'id' => bin2hex(random_bytes(16)), 'kind' => $pair ? 'inspect' : 'metadata',
                'installation' => $binding['installation'], 'registration' => $registration['id'], 'generation' => $registration['generation'],
                'connection_id' => $connectionId, 'itemtype' => 'Computer', 'entity_id' => $entity, 'items_id' => $pair ? (int) $pair['computer_id'] : 0,
                'pair_id' => $pair['id'] ?? '', 'expires' => time() + 300];
            $context = ['rest_next' => 0, 'rest_until' => 0, 'rest_lease' => '', 'rest_sent' => false,
                'rest_url_hash' => hash('sha256', $connection['agent_rest_url']), 'local_id' => $pair ? (int) $pair['local_id'] : 0];
            AgentTask::write($DB->insert(AgentTask::TABLE, ['id' => $body['id'], 'connection_id' => $connectionId,
                'slot' => 'rest-read:' . hash('sha256', $connectionId . ':' . $entity . ':' . $body['pair_id']), 'hash' => AgentTask::hash($body),
                'body' => json_encode($body, JSON_THROW_ON_ERROR), 'state' => 'accepted', 'result' => '{}', 'job' => '[]',
                'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => time(), 'expires' => $body['expires']]));
        }, true);
    }

    private static function readConnection(array $body, array $context): ?array
    {
        $connection = AgentInbox::connection($body['connection_id']);
        if (!$connection || ($connection['agent_transport'] ?? '') !== 'rest_records'
            || hash('sha256', $connection['agent_rest_url']) !== $context['rest_url_hash']) { return null; }
        $binding = AgentTask::binding($connection['id']);
        if (!$binding || $binding['registration'] !== $body['registration'] || $binding['installation'] !== $body['installation']
            || AgentTask::registration($binding)['generation'] !== $body['generation']) { return null; }
        return $connection;
    }

    private static function sendRead(int $deadline): void
    {
        global $DB;
        $task = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `slot` IS NOT NULL AND `state` = \'accepted\'
            AND JSON_EXTRACT(`body`, \'$.version\') = 9 AND COALESCE(JSON_EXTRACT(`context`, \'$.rest_next\'), 0) <= ' . time()
            . ' AND COALESCE(JSON_EXTRACT(`context`, \'$.rest_until\'), 0) <= ' . time() . ' ORDER BY `created_at`, `id` LIMIT 1');
        if (!$task) { return; }
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!hash_equals($task['hash'], AgentTask::hash($body))) { throw new \RuntimeException('REST read intent changed.'); }
        $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
        $connection = self::readConnection($body, $context);
        if (!$connection) { return; }
        $context['rest_until'] = time() + 30; $context['rest_lease'] = bin2hex(random_bytes(16));
        self::saveReservation($task, $context);
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $client = new RestSession($connection, $deadline);
        $lookup = self::RESOURCE . '?range=0-0&get_hateoas=0&searchText%5Bcompiler%5D=9&searchText%5Bwork_id%5D=' . $body['id'] . '&rest_entity=' . $body['entity_id'];
        try {
            $records = null;
            if ($context['rest_sent'] || $body['expires'] < time()) {
                $response = $client->request('GET', $lookup);
                self::diagnose($context, $client, 'work_lookup', $response['status'] === 200);
                if ($response['status'] !== 200 || !array_is_list($response['body']) || count($response['body']) > 1) { return; }
                $records = $response['body'];
            }
            $record = $records[0] ?? null;
            if ($record !== null && (($record['work_id'] ?? '') !== $body['id'] || ($record['hash'] ?? '') !== $task['hash'] || ($record['body'] ?? '') !== $task['body'])) {
                $context['rest_error'] = ['phase' => 'result', 'status' => 200, 'error' => 'invalid_result', 'at' => time()]; return;
            }
            if (($records === null || $records === [] || (($record['state'] ?? '') === 'accepted' && ($record['result'] ?? '{}') === '{}')) && $body['expires'] >= time()) {
                if (self::readConnection($body, $context) !== $connection) { return; }
                $context['rest_sent'] = true;
                self::saveReservation($task, $context);
                $response = $client->request('POST', self::RESOURCE, ['work_id' => $body['id'], 'hash' => $task['hash'], 'body' => $task['body']]);
                self::diagnose($context, $client, 'work_post', $response['status'] === 201);
                $response = $client->request('GET', $lookup);
                self::diagnose($context, $client, 'work_lookup', $response['status'] === 200);
                if ($response['status'] !== 200 || !array_is_list($response['body']) || count($response['body']) > 1) { return; }
                $records = $response['body']; $record = $records[0] ?? null;
            }
            if ($record !== null && (($record['work_id'] ?? '') !== $body['id'] || ($record['hash'] ?? '') !== $task['hash'] || ($record['body'] ?? '') !== $task['body'])) {
                $context['rest_error'] = ['phase' => 'result', 'status' => 200, 'error' => 'invalid_result', 'at' => time()]; return;
            }
            try {
                $result = $record ? json_decode($record['result'], true, 32, JSON_THROW_ON_ERROR) : [];
                if ($result === []) {
                    if ($body['expires'] >= time()) { return; }
                    $result = ['outcome' => 'stale', 'changed' => []];
                }
                self::validateObservation($body, $result);
            } catch (\JsonException | \TypeError | \RuntimeException) {
                $context['rest_error'] = ['phase' => 'result', 'status' => 200, 'error' => 'invalid_result', 'at' => time()]; return;
            }
            self::configure(static function () use (&$task, $body, &$context, $result): void {
                global $DB;
                if (self::readConnection($body, $context) === null) { return; }
                if ($result['outcome'] === 'observed' && $body['kind'] === 'inspect') {
                    $pair = AgentTask::byId($body['connection_id'], $body['pair_id']);
                    if (!$pair || (int) $pair['computer_id'] !== $body['items_id'] || (int) $pair['entity_id'] !== $body['entity_id'] || (int) $pair['local_id'] !== $context['local_id']) { throw new \RuntimeException('Inspection candidate changed.'); }
                    AgentTask::selectedAsset('Computer', (int) $pair['local_id'], (int) $pair['local_entity']);
                    $snapshot = $result['snapshot'] + ['observed_at' => $result['observed_at'], 'fields' => ['name' => $result['fields'][0]]];
                    AgentTask::write($DB->update(AgentTask::PAIRS, ['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'observed_at' => $result['observed_at']], ['id' => $pair['id'], 'registration' => $body['registration'], 'installation' => $body['installation']]));
                }
                self::saveReservation($task, $context, $result);
                AgentTask::write($DB->update(AgentTask::TABLE, ['state' => $result['outcome'], 'slot' => null], ['id' => $task['id'], 'hash' => $task['hash']]));
            });
        } finally {
            try { $client->close(); }
            finally { $context['rest_until'] = 0; $context['rest_next'] = time() + 10; self::saveReservation($task, $context); }
        }
    }

    private static function validateObservation(array $body, array $result): void
    {
        if (($result['outcome'] ?? '') === 'stale' && ($result['changed'] ?? null) === []) { return; }
        $field = $result['fields'][0] ?? [];
        if (array_diff(array_keys($result), ['outcome', 'changed', 'itemtype', 'entity_id', 'observed_at', 'fields', 'snapshot'])
            || array_diff(array_keys($field), ['key', 'uid', 'id', 'label', 'type', 'group', 'supported', 'reason'])
            || ($result['outcome'] ?? '') !== 'observed' || ($result['changed'] ?? null) !== [] || ($result['itemtype'] ?? '') !== 'Computer'
            || ($result['entity_id'] ?? null) !== $body['entity_id'] || !is_int($result['observed_at'] ?? null)
            || $result['observed_at'] < $body['expires'] - 300 || $result['observed_at'] > $body['expires']
            || count($result['fields'] ?? []) !== 1 || ($field['key'] ?? '') !== 'name' || ($field['type'] ?? '') !== 'text'
            || !is_string($field['label'] ?? null) || strlen($field['label']) > 255 || ($field['supported'] ?? null) !== true
            || ($field['uid'] ?? '') !== 'name' || !is_string($field['id'] ?? null) || !ctype_digit($field['id'])
            || ($field['group'] ?? '') !== 'Native' || ($field['reason'] ?? '') !== '') { throw new \RuntimeException('Invalid REST observation.'); }
        if ($body['kind'] === 'inspect') {
            $snapshot = $result['snapshot'] ?? [];
            foreach (['itemtype', 'items_id', 'entity_id', 'pair_id'] as $key) { if (($snapshot[$key] ?? null) !== $body[$key]) { throw new \RuntimeException('REST observation target changed.'); } }
            if (!is_string($snapshot['name'] ?? null) || strlen($snapshot['name']) > 255 || AgentTask::identity($snapshot)['serial'] === '') { throw new \RuntimeException('REST observation identity unavailable.'); }
        }
    }

    public static function catalog(string $connectionId, ?int $entity = null): array
    {
        global $DB;
        if ($entity === null) {
            $entities = [];
            foreach (EntitySyncRoute::loadAll(true) as $route) {
                if ($route['active'] && $route['glpi_b_connection_id'] === $connectionId && $route['glpi_b_target_entity_id'] !== ''
                    && in_array('Computer', $route['asset_types'], true)) { $entities[(int) $route['glpi_b_target_entity_id']] = true; }
            }
            if (count($entities) !== 1) { return ['success' => false, 'fields' => [], 'message' => 'Select one target entity for scoped Computer metadata.']; }
            $entity = (int) array_key_first($entities);
        }
        if ($entity < 0) { throw new \RuntimeException('Invalid metadata entity.'); }
        foreach ($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['connection_id' => $connectionId, 'state' => 'observed',
            new \Glpi\DBAL\QueryExpression("JSON_EXTRACT(`body`, '$.version') = 9 AND JSON_EXTRACT(`body`, '$.entity_id') = " . $entity
                . " AND JSON_UNQUOTE(JSON_EXTRACT(`body`, '$.itemtype')) = 'Computer'")], 'ORDER' => 'created_at DESC, id DESC', 'LIMIT' => 10]) as $task) {
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            if (self::readConnection($body, $context) && ($result['observed_at'] ?? 0) >= time() - 300) {
                return ['success' => true, 'fields' => $result['fields'], 'message' => 'Scoped B definitions observed.'];
            }
        }
        return ['success' => false, 'fields' => [], 'message' => 'Request scoped REST metadata on Agent Pair.'];
    }

    public static function handles(array $connection, string $type, int $id): bool
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists(AgentTask::PAIRS) || !$DB->tableExists(AgentTask::TABLE)) { return false; }
        $pair = AgentTask::pair($connection['id'], $type, $id);
        if (!$pair) { return false; }
        if (($connection['agent_transport'] ?? 'adapter') === 'rest_records') { return true; }
        if (($connection['agent_rest_pair'] ?? '') === $pair['id'] || in_array($pair['id'], $connection['agent_rest_pairs'] ?? [], true)) { return true; }
        $task = AgentTask::outstanding($connection['id'], $pair);
        return $task && in_array(json_decode($task['body'], true)['version'] ?? 0, [7, 8, 10, 11], true);
    }

    private static function context(array $pair, ?array $guarded = null): array
    {
        $scope = AgentTask::scope($pair);
        $connection = $scope['connection'];
        $records = ($connection['agent_transport'] ?? 'adapter') === 'rest_records';
        if ($pair['itemtype'] !== 'Computer' || (!$records && ($connection['agent_rest_pair'] ?? '') !== $pair['id']
                && !in_array($pair['id'], $connection['agent_rest_pairs'] ?? [], true))
            || !RestSession::validUrl($connection['agent_rest_url'] ?? '')) { throw new \RuntimeException('Selected REST pair is unavailable.'); }
        FieldMapping::endRunCache();
        $rows = FieldMapping::load($pair['connection_id'], 'Computer');
        if (count($rows) !== 1) { throw new \RuntimeException('Name REST requires exactly one mapping; additional mappings are unsupported.'); }
        $row = array_values($rows)[0];
        if (!in_array($row['glpi_a_field_key'] ?? '', ['name', 'Computer.name'], true)
            || !in_array($row['glpi_b_field_key'] ?? '', ['name', 'Computer.name'], true)
            || !in_array($row['source_of_truth'] ?? '', ['glpi_a', 'both'], true)) { throw new \RuntimeException('Name REST supports native Name to Name only.'); }
        $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
        $identity = !empty($pair['identity_basis']) ? json_decode($pair['identity_basis'], true, 32, JSON_THROW_ON_ERROR)['b']
            : (array_key_exists('otherserial', $snapshot) ? AgentTask::identity($snapshot) : ['serial' => $pair['serial']]);
        $settings = ['version' => 1];
        foreach (['installation', 'registration', 'connection_id'] as $key) { $settings[$key] = $pair[$key]; }
        $settings += ['generation' => $scope['registration']['generation'], 'route_id' => $pair['route_id'],
            'source_entity' => (int) $pair['local_entity'], 'target_entity' => (int) $pair['entity_id'], 'itemtype' => 'Computer',
            'mappings' => [['glpi_a_field' => 'name', 'glpi_b_field' => 'name', 'source_of_truth' => 'glpi_a']]];
        $settings = ['version' => 1, 'id' => substr(AgentTask::hash($settings), 0, 32)] + $settings;
        $entry = self::settingsState()[self::settingsKey($settings)] ?? null;
        if ($entry && !$records) {
            throw new \RuntimeException('Retained versioned route requires REST records transport. No protocol downgrade.');
        }
        if ($records) {
            if (!$entry) { throw new \RuntimeException('REST settings publication pending.'); }
            $settings = json_decode($entry['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!$settings['enabled']) { throw new \RuntimeException('REST route is disabled or unsupported.'); }
            if (!in_array($settings['version'], [3, 4], true)) { throw new \RuntimeException('Scoped REST settings publication pending.'); }
            if ($settings['version'] === 3) {
                if ($entry['pending']) { throw new \RuntimeException('REST settings publication pending.'); }
                $route = $scope['route']; $types = $route['asset_types']; sort($types, SORT_STRING);
                if ($settings['source_entity'] !== (int) $route['glpi_a_source_entity_id'] || $settings['target_entity'] !== (int) $pair['entity_id']
                    || $settings['include_child_entities'] !== $route['include_child_entities'] || $settings['asset_types'] !== $types) { throw new \RuntimeException('REST source/target policy changed.'); }
            } elseif ($scope['route']['include_child_entities'] || $scope['route']['asset_types'] !== ['Computer']) { throw new \RuntimeException('Name Both requires its exact single-type route.'); }
        }
        if ($row['source_of_truth'] === 'both') {
            if (($settings['version'] ?? 0) !== 4 || $settings['mappings'][0]['source_of_truth'] !== 'both') { throw new \RuntimeException('Name Both settings4 publication pending.'); }
            NameBoth::localItem($pair, false);
            $evidence = $guarded ?? FieldEvidence::envelope((int) $pair['local_id'], 'native:name');
            NameBoth::validateEnvelope($evidence);
            if (NameBoth::normalize($scope['asset']['name']) !== NameBoth::normalize($evidence['value'])) { throw new \RuntimeException('Name source changed during preparation.'); }
            return $scope + ['settings' => $settings, 'source_hash' => AgentTask::hash($evidence), 'identity' => $identity,
                'evidence' => $evidence, 'fingerprint' => NameBoth::fingerprint(AgentTask::hash($settings), AgentTask::identity($scope['asset']), $evidence)];
        }
        $source = AgentTask::hash([AgentTask::hash($settings), AgentTask::identity($scope['asset']), (string) $scope['asset']['name']]);
        return $scope + ['settings' => $settings, 'source_hash' => $source, 'identity' => $identity];
    }

    public static function needsWork(array $pair, bool $force): bool
    {
        $task = AgentTask::outstanding($pair['connection_id'], $pair);
        if ($task) {
            $version = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR)['version'] ?? 0;
            if (self::neverReserved($task)) {
                try { $fresh = self::context($pair); }
                catch (\Throwable) { return true; }
                $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                return $body['source_hash'] !== $fresh['source_hash'] || $body['settings_hash'] !== AgentTask::hash($fresh['settings']);
            }
            return self::superseded($task) || ($version === 11 && $task['result'] !== '{}' && in_array($task['state'], ['accepted', 'origin_pending', 'finalizing'], true));
        }
        try { $context = self::context($pair); }
        catch (\Throwable) { return true; }
        return $force || ($pair['local_fingerprint'] ?? '') !== ($context['fingerprint'] ?? $context['source_hash']) || self::probeDue($pair, $context);
    }

    /** Interim current-state reconciliation, not the future immutable change/ACK delivery protocol. */
    public static function probeDue(array $pair, array $context): bool
    {
        return ($context['settings']['version'] ?? 0) === 4 && (int) ($pair['checked_at'] ?? 0) <= time() - 60;
    }

    public static function assertNameSource(array $pair, array $body, array $evidence): array
    {
        FieldEvidence::assertGuard((int) $pair['local_id'], 'native:name', $evidence);
        $context = self::context($pair, $evidence);
        foreach (['installation', 'registration', 'connection_id'] as $key) {
            if ($body[$key] !== $pair[$key]) { throw new \RuntimeException('Name Both pair binding changed.'); }
        }
        if ($body['settings_hash'] !== AgentTask::hash($context['settings']) || $body['settings_id'] !== $context['settings']['id']
            || $body['generation'] !== $context['registration']['generation'] || $body['items_id'] !== (int) $pair['computer_id']
            || $body['entity_id'] !== (int) $pair['entity_id'] || $body['pair_id'] !== $pair['id']) {
            throw new \RuntimeException('Name Both policy or confirmed target changed.');
        }
        return $context;
    }

    public static function assertNamePolicy(array $pair, array $body): void
    {
        $scope = AgentTask::scope($pair);
        $entry = self::settingsState()[self::settingsKey($body + ['route_id' => $pair['route_id']])] ?? null;
        FieldMapping::endRunCache();
        $rows = array_values(FieldMapping::load($pair['connection_id'], 'Computer'));
        if (($scope['connection']['agent_transport'] ?? '') !== 'rest_records' || !$entry || $entry['hash'] !== $body['settings_hash']
            || count($rows) !== 1 || ($rows[0]['source_of_truth'] ?? '') !== 'both'
            || !in_array($rows[0]['glpi_a_field_key'] ?? '', ['name', 'Computer.name'], true)
            || !in_array($rows[0]['glpi_b_field_key'] ?? '', ['name', 'Computer.name'], true)) { throw new \RuntimeException('Name Both policy changed.'); }
    }

    private static function superseded(array $task): bool
    {
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
        return in_array($body['version'] ?? 0, [8, 10], true) && ($result['outcome'] ?? '') === 'stale'
            && ($result['reason'] ?? '') === 'settings_superseded' && ($result['changed'] ?? null) === [];
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
                if (!in_array($body['version'], [7, 8, 10, 11], true)) { throw new \RuntimeException('Resolve the retained agent operation before switching this pair to REST records.'); }
                if ($task['result'] === '{}') {
                    if ($body['version'] === 11) {
                        if (self::neverReserved($task) && ($body['source_hash'] !== $context['source_hash']
                            || $body['settings_hash'] !== AgentTask::hash($context['settings']))) {
                            self::replaceUnsent($task, $job, $pair, $context);
                        } else { self::parkName($task, $job); }
                    } else { AgentTask::write(AssetSyncQueue::waitForAgent($job)); }
                    return;
                }
                $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
                if ($body['version'] === 11) { NameBoth::finish($task, $job, $pair, $context, $body, $result); return; }
                if (self::superseded($task) && in_array($context['settings']['version'], [2, 3], true) && $body['settings_id'] !== $context['settings']['id']) {
                    AgentInbox::sql('START TRANSACTION');
                    try {
                        if (!AssetSyncQueue::owns($job)) { throw new \RuntimeException('REST superseded queue claim changed.'); }
                        AgentTask::write($DB->update(AgentTask::TABLE, ['slot' => null, 'state' => 'stale'],
                            ['id' => $task['id'], 'hash' => $task['hash'], 'slot' => $task['slot'], 'state' => 'accepted', 'result' => $task['result']]));
                        if ($DB->affectedRows() !== 1) { throw new \RuntimeException('REST superseded receipt changed.'); }
                        AgentTask::write(AssetSyncQueue::retry($job, 'Settings superseded before B mutation; queue fresh current-policy work.'));
                        AgentInbox::sql('COMMIT');
                    } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
                    return;
                }
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
            if (isset($context['fingerprint']) && ($pair['local_fingerprint'] ?? '') === $context['fingerprint']
                && !self::probeDue($pair, $context) && (int) ($job['agent_snapshot'] ?? 0) <= (int) ($job['agent_completed'] ?? 0)) {
                // Hooks remain light. A queued unmapped edit is consumed locally without claiming a verified sync.
                AgentTask::write(AssetSyncQueue::finish($job, 'Mapped Name unchanged; no REST work published.'));
                return;
            }
            $body = ['version' => 7, 'id' => bin2hex(random_bytes(16))];
            foreach (['installation', 'registration', 'connection_id'] as $key) { $body[$key] = $pair[$key]; }
            $body += ['generation' => $context['registration']['generation'], 'settings_id' => $context['settings']['id'],
                'settings_hash' => AgentTask::hash($context['settings']), 'pair_id' => $pair['id'], 'itemtype' => 'Computer',
                'items_id' => (int) $pair['computer_id'], 'entity_id' => (int) $pair['entity_id'], 'identity' => $context['identity'],
                'name' => (string) $context['asset']['name'], 'source_hash' => $context['source_hash'], 'expires' => time() + 300];
            if (($context['connection']['agent_transport'] ?? 'adapter') === 'rest_records' || in_array($pair['id'], $context['connection']['agent_rest_pairs'] ?? [], true)) {
                $body['version'] = $context['settings']['version'] === 3 ? 10 : 8;
                if ($body['version'] === 10) { $body['source_asset_entity'] = (int) $context['asset']['entities_id']; }
                if (isset($context['evidence'])) {
                    $body['version'] = 11;
                    unset($body['name']);
                    $body['source'] = $context['evidence'];
                    $body['source_fingerprint'] = $context['fingerprint'];
                }
                $key = AgentTask::hash([$context['settings'], $context['connection']['agent_rest_url'], AgentSession::fingerprint($context['connection'])]);
                $batch = self::$batches[$key] ?? ['id' => bin2hex(random_bytes(16)), 'inputs' => []];
                $body['batch_id'] = $batch['id'];
                $input = ['work_id' => $body['id'], 'hash' => AgentTask::hash($body), 'body' => self::workJson($body)];
                if (count($batch['inputs']) >= ($body['version'] === 11 ? 2 : 4) || strlen(json_encode(['session_write' => false, 'input' => [...$batch['inputs'], $input]], JSON_THROW_ON_ERROR)) > 49152) {
                    $batch = ['id' => bin2hex(random_bytes(16)), 'inputs' => []];
                    $body['batch_id'] = $batch['id'];
                    $input = ['work_id' => $body['id'], 'hash' => AgentTask::hash($body), 'body' => self::workJson($body)];
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
                    'slot' => 'pair:' . $pair['id'], 'hash' => $hash, 'body' => self::workJson($body),
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
                } else {
                    $message = ($body['version'] ?? 0) === 11
                        ? 'Name Both validation or local evidence recovery is unavailable; immutable work retained, completion unverified.'
                        : $error->getMessage();
                    AgentTask::write(AssetSyncQueue::block($job, $message));
                }
            }
        }
    }

    private static function workJson(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR | ($body['version'] === 11 ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0));
    }

    /** Empty sticky lease is evidence of NEVER reserved; its expiry or sent=false alone is not. */
    private static function neverReserved(array $task): bool
    {
        try {
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $keys = ['version', 'id', 'installation', 'registration', 'generation', 'connection_id', 'settings_id', 'settings_hash',
                'pair_id', 'itemtype', 'items_id', 'entity_id', 'identity', 'source', 'source_hash', 'source_fingerprint', 'expires', 'batch_id'];
            if (!is_array($body) || array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))) { return false; }
            if (($body['version'] ?? null) !== 11 || $body['id'] !== $task['id'] || AgentTask::hash($body) !== $task['hash']
                || ($task['slot'] ?? null) !== 'pair:' . $body['pair_id'] || $task['connection_id'] !== $body['connection_id']
                || $task['state'] !== 'accepted' || $task['result'] !== '{}' || !is_array($context)
                || ($context['rest_sent'] ?? null) !== false || ($context['rest_lease'] ?? null) !== ''
                || ($context['rest_until'] ?? null) !== 0 || !is_int($context['rest_next'] ?? null)
                || !is_array($context['settings'] ?? null) || AgentTask::hash($context['settings']) !== $body['settings_hash']
                || ($context['settings']['id'] ?? null) !== $body['settings_id'] || ($context['settings']['version'] ?? null) !== 4
                || array_diff(array_keys($context), ['settings', 'rest_next', 'rest_sent', 'rest_lease', 'rest_until', 'rest_observe_only'])
                || (array_key_exists('rest_observe_only', $context) && !is_bool($context['rest_observe_only']))
                || $body['itemtype'] !== 'Computer' || !is_int($body['items_id']) || $body['items_id'] < 1
                || !is_int($body['entity_id']) || $body['entity_id'] < 0 || !is_int($body['expires'])
                || !is_array($body['identity']) || !in_array(array_keys($body['identity']), [['serial'], ['serial', 'otherserial']], true)) { return false; }
            foreach (['id', 'installation', 'registration', 'generation', 'settings_id', 'pair_id', 'batch_id'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $body[$key])) { return false; }
            }
            foreach (['settings_hash', 'source_hash', 'source_fingerprint'] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $body[$key])) { return false; }
            }
            $identity = AgentTask::identity($body['identity'] + ['otherserial' => '']);
            if (array_intersect_key($identity, $body['identity']) !== $body['identity']) { return false; }
            foreach (['mutation', 'receipt', 'local_mutation', 'origin_pending', 'name_origin_pending', 'superseded_by'] as $key) {
                if (array_key_exists($key, $context)) { return false; }
            }
            NameBoth::validateEnvelope($body['source']);
            return $body['source_hash'] === AgentTask::hash($body['source']);
        } catch (\Throwable) { return false; }
    }

    /** Binary equality matters for JSON/text columns; context also pins the exact sticky dispatch lease. */
    private static function nameFence(array $task): string
    {
        global $DB;
        $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_string($context['rest_lease'] ?? null)) { throw new \RuntimeException('REST dispatch lease unavailable.'); }
        $where = [];
        foreach (['id', 'body', 'hash', 'slot', 'state', 'result', 'context', 'queue_id', 'job'] as $key) {
            if (!array_key_exists($key, $task)) { throw new \RuntimeException('REST dispatch fence unavailable.'); }
            $where[] = $task[$key] === null ? '`' . $key . '` IS NULL'
                : 'CAST(`' . $key . '` AS BINARY)=CAST(' . $DB->quote((string) $task[$key]) . ' AS BINARY)';
        }
        $where[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(`context`, \'$.rest_lease\')) AS BINARY)=CAST(' . $DB->quote($context['rest_lease']) . ' AS BINARY)';
        return implode(' AND ', $where);
    }

    private static function lockedName(array $task): ?array
    {
        return AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE ' . self::nameFence($task) . ' FOR UPDATE');
    }

    /** Wake only this eligible parked task. Never replace a running queue claim or notification counters. */
    private static function deferUnsent(array $task, array $context): void
    {
        global $DB;
        if (!AgentWake::transactionIdle()) { throw new \RuntimeException('REST deferred wake requires an idle connection.'); }
        AgentInbox::sql('START TRANSACTION');
        try {
            if (!self::lockedName($task) || !self::saveReservation($task, $context)) { AgentInbox::sql('ROLLBACK'); return; }
            if (self::neverReserved($task)) { self::wakeNameQueue($task); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    /** Task lock precedes queue lock at both result receipt and empty-result parking. */
    private static function wakeNameQueue(array $task): void
    {
        global $DB;
        $queue = AgentInbox::one('SELECT * FROM `' . AssetSyncQueue::TABLE . '` WHERE `id`=' . (int) $task['queue_id'] . ' FOR UPDATE');
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        $pair = AgentTask::byId($task['connection_id'], $body['pair_id']);
        if (!$pair || !$queue || $queue['status'] !== 'waiting_agent' || ($queue['claim_token'] ?? '') !== ''
            || $queue['glpi_b_connection_id'] !== $task['connection_id'] || $queue['itemtype'] !== $pair['itemtype']
            || (int) $queue['items_id'] !== (int) $pair['local_id']) { return; }
        AgentTask::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'],
            ['id' => $queue['id'], 'status' => 'waiting_agent', 'claim_token' => '']));
        if ($DB->affectedRows() !== 1) { throw new \RuntimeException('REST deferred queue changed.'); }
    }

    private static function parkName(array $task, array $job): void
    {
        global $DB;
        if (!AgentWake::transactionIdle()) { throw new \RuntimeException('REST queue parking requires an idle connection.'); }
        AgentInbox::sql('START TRANSACTION');
        try {
            // A result may have arrived after process() read it. Serialize parking with its receipt.
            $current = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id`=' . $DB->quote($task['id']) . ' FOR UPDATE');
            if (!$current || $current['body'] !== $task['body'] || $current['hash'] !== $task['hash'] || $current['slot'] !== $task['slot']
                || !AssetSyncQueue::owns($job)) { throw new \RuntimeException('REST queue ownership changed.'); }
            AgentTask::write(AssetSyncQueue::waitForAgent($job));
            if ($current['result'] !== '{}') { self::wakeNameQueue($current); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    /** Replace only proven unstarted work, under the existing short current-policy lock/owned transaction. */
    private static function replaceUnsent(array $task, array $job, array $pair, array $context): void
    {
        global $DB;
        if (!self::neverReserved($task) || !isset($context['evidence']) || $context['settings']['version'] !== 4) { self::parkName($task, $job); return; }
        $oldBody = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        $body = array_replace($oldBody, ['id' => bin2hex(random_bytes(16)), 'batch_id' => bin2hex(random_bytes(16)),
            'settings_id' => $context['settings']['id'], 'settings_hash' => AgentTask::hash($context['settings']),
            'source' => $context['evidence'], 'source_hash' => $context['source_hash'], 'source_fingerprint' => $context['fingerprint'], 'expires' => time() + 300]);
        $json = self::workJson($body); $hash = AgentTask::hash($body);
        if (strlen(json_encode(['session_write' => false, 'input' => [['work_id' => $body['id'], 'hash' => $hash, 'body' => $json]]], JSON_THROW_ON_ERROR)) > 49152) {
            throw new \RuntimeException('REST replacement exceeds request budget.');
        }
        try { self::configure(static function () use ($task, $job, $pair, $context, $body, $json, $hash, $DB): void {
            $binding = AgentInbox::one('SELECT * FROM `' . AgentTask::BINDINGS . '` WHERE `connection_id`=' . $DB->quote($pair['connection_id']) . ' FOR UPDATE');
            if (!$binding || $binding['installation'] !== $body['installation'] || $binding['registration'] !== $body['registration']
                || AgentTask::registration($binding)['generation'] !== $body['generation']) { throw new \RuntimeException('REST replacement binding changed.'); }
            if (!self::lockedName($task) || !self::neverReserved($task)) { throw new \RuntimeException('REST unstarted work changed.'); }
            $lockedPair = AgentInbox::one('SELECT * FROM `' . AgentTask::PAIRS . '` WHERE `id`=' . $DB->quote($pair['id']) . ' FOR UPDATE');
            if (!$lockedPair || $lockedPair !== $pair) { throw new \RuntimeException('REST confirmed pair changed.'); }
            AgentInbox::sql('SELECT `id` FROM `' . AssetSyncQueue::TABLE . '` WHERE `id`=' . (int) $job['id'] . ' FOR UPDATE');
            AgentInbox::sql('SELECT `id` FROM `' . AssetSyncLink::TABLE . '` WHERE `itemtype`=\'Computer\' AND `items_id`=' . (int) $pair['local_id']
                . ' AND `glpi_b_connection_id`=' . $DB->quote($pair['connection_id']) . ' FOR UPDATE');
            if ((int) $task['queue_id'] !== (int) $job['id'] || !AssetSyncQueue::owns($job)) { throw new \RuntimeException('REST replacement queue claim changed.'); }
            $fresh = self::assertNameSource($lockedPair, $body, $context['evidence']);
            if ($fresh['source_hash'] !== $body['source_hash'] || $fresh['identity'] !== $body['identity']) { throw new \RuntimeException('REST replacement source changed.'); }
            $oldContext = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $oldContext['superseded_by'] = $body['id'];
            AgentTask::write($DB->update(AgentTask::TABLE, ['slot' => null, 'state' => 'superseded', 'context' => json_encode($oldContext, JSON_THROW_ON_ERROR)],
                [new \Glpi\DBAL\QueryExpression(self::nameFence($task))]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('REST supersession fence changed.'); }
            AgentTask::write($DB->insert(AgentTask::TABLE, ['id' => $body['id'], 'connection_id' => $pair['connection_id'], 'slot' => $task['slot'],
                'body' => $json, 'hash' => $hash, 'state' => 'accepted', 'result' => '{}', 'queue_id' => $job['id'], 'job' => json_encode($job, JSON_THROW_ON_ERROR),
                'context' => json_encode(['settings' => $fresh['settings'], 'rest_next' => 0, 'rest_sent' => false, 'rest_lease' => '', 'rest_until' => 0], JSON_THROW_ON_ERROR),
                'created_at' => time(), 'expires' => $body['expires']]));
            AgentTask::write(AssetSyncQueue::waitForAgent($job));
        }, true); }
        catch (\Throwable $error) {
            // A COMMIT reply can be lost after the atomic slot transfer. Read durable identities, never retry from memory.
            if (AgentWake::transactionIdle()) {
                $old = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id`=' . $DB->quote($task['id']));
                $new = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id`=' . $DB->quote($body['id']));
                $oldContext = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR); $oldContext['superseded_by'] = $body['id'];
                if ($old && $new && $old['state'] === 'superseded' && $old['slot'] === null
                    && $old['body'] === $task['body'] && $old['hash'] === $task['hash'] && $old['result'] === $task['result'] && $old['job'] === $task['job']
                    && $old['context'] === json_encode($oldContext, JSON_THROW_ON_ERROR) && $new['body'] === $json && $new['hash'] === $hash
                    && (int) $new['queue_id'] === (int) $job['id'] && $new['job'] === json_encode($job, JSON_THROW_ON_ERROR)) { return; }
            }
            throw $error;
        }
    }

    /** Advance durable selection only. A cursor is never permission to reserve or send its task. */
    private static function dispatchCandidate(int $deadline, bool $first): ?array
    {
        global $DB;
        if (hrtime(true) + 2_000_000_000 >= $deadline || !AgentWake::transactionIdle() || AssetUuidOperation::hasAssetLocks()) { return null; }
        $lock = $DB->quote('assetsync20:dispatch:' . AssetSyncLockName::databaseHash($DB));
        if ((int) (AgentInbox::one('SELECT COALESCE(IS_USED_LOCK(' . $lock . ') = CONNECTION_ID(), 0) AS owned')['owned'] ?? 1) !== 0) { return null; }
        if ((int) (AgentInbox::one('SELECT GET_LOCK(' . $lock . ', 0) AS acquired')['acquired'] ?? 0) !== 1) { return null; }
        try {
            if (hrtime(true) + 2_000_000_000 >= $deadline) { return null; }
            // Idle autocommit plus the selection lock gives each selector a fresh Config read.
            $raw = \Config::getConfigurationValues(GlpiBConnection::CONTEXT, [self::DISPATCH_CURSOR])[self::DISPATCH_CURSOR] ?? null;
            $cursor = $raw === null ? ['after' => null, 'ceiling' => null] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($cursor) || array_keys($cursor) !== ['after', 'ceiling']) { throw new \RuntimeException('REST dispatch cursor unavailable.'); }
            foreach ($cursor as $point) {
                if ($point !== null && (!is_array($point) || array_keys($point) !== ['created_at', 'id']
                    || !is_int($point['created_at']) || !is_string($point['id']) || strlen($point['id']) > 32)) {
                    throw new \RuntimeException('REST dispatch cursor unavailable.');
                }
            }
            if (($cursor['ceiling'] === null && $cursor['after'] !== null)
                || ($cursor['after'] !== null && [$cursor['after']['created_at'], $cursor['after']['id']] > [$cursor['ceiling']['created_at'], $cursor['ceiling']['id']])) {
                throw new \RuntimeException('REST dispatch cursor order unavailable.');
            }
            // Only the first selection may wrap a sweep completed in an earlier pass.
            for ($wrap = 0; $wrap < 2; $wrap++) {
                $where = '`slot` IS NOT NULL AND `state` = \'accepted\' AND `result` = \'{}\'';
                $bodyJson = "(CASE WHEN JSON_VALID(`body`) THEN `body` ELSE '{}' END)";
                $contextJson = "(CASE WHEN JSON_VALID(`context`) THEN `context` ELSE '{}' END)";
                $where .= ' AND JSON_EXTRACT(' . $bodyJson . ', \'$.version\') IN (7, 8, 10, 11)'
                    . ' AND COALESCE(JSON_EXTRACT(' . $contextJson . ', \'$.rest_until\'), 0) <= ' . time()
                    . ' AND COALESCE(JSON_EXTRACT(' . $contextJson . ', \'$.rest_next\'), 0) <= ' . time();
                if ($cursor['ceiling'] === null) {
                    $last = AgentInbox::one('SELECT `created_at`, `id` FROM `' . AgentTask::TABLE . '` WHERE ' . $where . ' ORDER BY `created_at` DESC, `id` DESC LIMIT 1');
                    if (!$last) { return null; }
                    $cursor['ceiling'] = ['created_at' => (int) $last['created_at'], 'id' => $last['id']];
                }
                $ceiling = $cursor['ceiling'];
                $where .= ' AND (`created_at` < ' . $ceiling['created_at'] . ' OR (`created_at` = ' . $ceiling['created_at'] . ' AND `id` <= ' . $DB->quote($ceiling['id']) . '))';
                if ($cursor['after'] !== null) {
                    $after = $cursor['after'];
                    $where .= ' AND (`created_at` > ' . $after['created_at'] . ' OR (`created_at` = ' . $after['created_at'] . ' AND `id` > ' . $DB->quote($after['id']) . '))';
                }
                $task = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE ' . $where . ' ORDER BY `created_at`, `id` LIMIT 1');
                $cursor = $task ? ['after' => ['created_at' => (int) $task['created_at'], 'id' => $task['id']], 'ceiling' => $ceiling]
                    : ['after' => null, 'ceiling' => null];
                if ($task || !$first) { break; }
            }
            $json = json_encode($cursor, JSON_THROW_ON_ERROR);
            \Config::setConfigurationValues(GlpiBConnection::CONTEXT, [self::DISPATCH_CURSOR => $json]);
            if ((\Config::getConfigurationValues(GlpiBConnection::CONTEXT, [self::DISPATCH_CURSOR])[self::DISPATCH_CURSOR] ?? null) !== $json) {
                throw new \RuntimeException('REST dispatch cursor persistence failed.');
            }
            return $task;
        } finally {
            if ((int) (AgentInbox::one('SELECT RELEASE_LOCK(' . $lock . ') AS released')['released'] ?? 0) !== 1) {
                throw new \RuntimeException('REST dispatch selection lock release unavailable.');
            }
        }
    }

    /** Malformed retained dispatch evidence is held byte-for-byte, not repaired or coerced. */
    private static function dispatchReady(array $task, array $body): bool
    {
        try {
            $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            return in_array($body['version'] ?? null, [7, 8, 10, 11], true) && ($body['id'] ?? null) === $task['id']
                && ($body['connection_id'] ?? null) === $task['connection_id'] && AgentTask::hash($body) === $task['hash']
                && is_string($body['pair_id'] ?? null) && ($task['slot'] ?? null) === 'pair:' . $body['pair_id']
                && ($body['version'] === 7 || (is_string($body['batch_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $body['batch_id'])))
                && is_array($context) && is_bool($context['rest_sent'] ?? null) && is_int($context['rest_until'] ?? null)
                && is_int($context['rest_next'] ?? null) && $context['rest_until'] <= time() && $context['rest_next'] <= time()
                && is_string($context['rest_lease'] ?? null) && ($context['rest_lease'] === '' || preg_match('/^[a-f0-9]{32}$/D', $context['rest_lease']))
                && is_array($context['settings'] ?? null);
        } catch (\JsonException | \TypeError) { return false; }
    }

    /** Only invoked after the outer run and asset locks are released. */
    public static function sendPending(int $deadline): void
    {
        global $DB;
        if (!is_object($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists(AgentTask::TABLE)
            || PHP_SAPI !== 'cli' || hrtime(true) + 2_000_000_000 >= $deadline || AssetUuidOperation::hasAssetLocks() || !AgentWake::transactionIdle()) { return; }
        $lock = $DB->quote('assetsync20:run:' . AssetSyncLockName::databaseHash($DB));
        if ((int) (AgentInbox::one('SELECT COALESCE(IS_USED_LOCK(' . $lock . ') = CONNECTION_ID(), 0) AS owned')['owned'] ?? 1) !== 0) { return; }
        self::publishSettings($deadline);
        if (hrtime(true) + 2_000_000_000 >= $deadline) { return; }
        self::sendRead($deadline);
        if (hrtime(true) + 2_000_000_000 >= $deadline) { return; }
        for ($examined = 0; $examined < 32 && hrtime(true) + 2_000_000_000 < $deadline; $examined++) {
            $task = self::dispatchCandidate($deadline, $examined === 0);
            if (!$task) { return; }
            if (hrtime(true) + 2_000_000_000 >= $deadline) { return; }
            try { $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { continue; }
            if (!is_array($body) || !self::dispatchReady($task, $body)) { continue; }
            $connection = AgentInbox::connection($task['connection_id']);
            if (!$connection || ($body['version'] === 10 && ($connection['agent_transport'] ?? 'adapter') !== 'rest_records')) { continue; }
            if (in_array($body['version'], [8, 10, 11], true)) {
                if (self::sendBatch($task, $body, $deadline)) { return; }
            } elseif (self::sendSingle($task, $body, $deadline)) { return; }
        }
    }

    private static function sendSingle(array $task, array $body, int $deadline): bool
    {
        global $DB;
        $pair = AgentTask::byId($task['connection_id'], $body['pair_id']);
        if (!$pair) { return false; }
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
        if ($reserved === null) { return false; }
        if (AssetUuidOperation::hasAssetLocks() || !AgentWake::transactionIdle()) { return true; }
        $task['context'] = json_encode($reserved, JSON_THROW_ON_ERROR);
        $connection = GlpiBConnection::find($task['connection_id']);
        if (!$connection || !$connection['active']) { return true; }
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
                if (!$valid) { return true; }
                $receipts[$receiptKey] = $response['body']['id'];
                \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode($receipts, JSON_THROW_ON_ERROR)]);
            }
            $record = null;
            if ($reserved['rest_sent'] || $body['expires'] < time()) {
                // A failed GET is unknown, never evidence that a previous POST was absent.
                $record = self::lookup($client, $body['id'], $reserved);
                if ($record === null) { return true; }
            }
            if ($record === [] && $body['expires'] < time()) {
                $reserved['rest_state'] = 'expired';
                self::saveReservation($task, $reserved, ['outcome' => 'stale', 'changed' => []]);
                $DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']);
                return true;
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
                return true;
            }
            $reserved['rest_state'] = in_array($record['state'] ?? '', ['accepted', 'executing', 'expired'], true)
                ? $record['state'] : 'unknown';
            $reserved['rest_observed_at'] = time();
            try {
                $result = json_decode($record['result'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if ($result === []) {
                    if (($record['state'] ?? '') === 'expired') { $result = ['outcome' => 'stale', 'changed' => []]; }
                    else { return true; }
                }
                self::validateResult($body, $result);
            } catch (\Throwable) {
                $reserved['rest_state'] = 'unknown';
                $reserved['rest_error'] = ['phase' => 'result', 'status' => 200, 'error' => 'invalid_result', 'at' => time()];
                return true;
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
        return true;
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

    private static function saveReservation(array &$task, array $context, ?array $result = null): bool
    {
        global $DB;
        $fields = ['context' => json_encode($context, JSON_THROW_ON_ERROR)];
        if ($result !== null) { $fields += ['result' => json_encode($result, JSON_THROW_ON_ERROR), 'result_received_at' => time()]; }
        if ((json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR)['version'] ?? 0) === 11) {
            $before = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            if ($task['state'] !== 'accepted' || $task['slot'] === null || !is_string($context['rest_lease'] ?? null)
                || !is_bool($before['rest_sent'] ?? null) || !is_bool($context['rest_sent'] ?? null)
                || !is_int($before['rest_until'] ?? null) || !is_int($context['rest_until'] ?? null)
                || !is_int($before['rest_next'] ?? null) || !is_int($context['rest_next'] ?? null)
                || ($context['rest_lease'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $context['rest_lease']))
                || (($before['rest_lease'] ?? null) !== '' && $context['rest_lease'] === '')
                || (($before['rest_sent'] ?? null) === true && ($context['rest_sent'] ?? null) !== true)
                || (($context['rest_sent'] ?? null) === true && $context['rest_lease'] === '')) { return false; }
            AgentTask::write($DB->update(AgentTask::TABLE, $fields, [new \Glpi\DBAL\QueryExpression(self::nameFence($task))]));
            $next = array_replace($task, $fields);
            if ($DB->affectedRows() !== 1 && !AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE ' . self::nameFence($next))) {
                return false;
            }
            $task = $next; // Includes our result: later cleanup must fence the newly persisted receipt, not {}.
            return true;
        }
        AgentTask::write($DB->update(AgentTask::TABLE, $fields, ['id' => $task['id'], 'hash' => $task['hash'], 'context' => $task['context']]));
        if ($DB->affectedRows() !== 1) {
            $saved = AgentInbox::one('SELECT `context` FROM `' . AgentTask::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' AND `hash` = ' . $DB->quote($task['hash']));
            if (($saved['context'] ?? null) !== $fields['context']) { throw new \RuntimeException('REST dispatch reservation changed.'); }
        }
        $task['context'] = $fields['context'];
        return true;
    }

    private static function validateResult(array $body, array $result): void
    {
        if ($body['version'] === 11) { NameBoth::validateResult($body, $result); return; }
        if (!in_array($result['outcome'] ?? '', ['verified', 'unchanged', 'stale', 'blocked', 'uncertain'], true)
            || !is_array($result['changed'] ?? null) || array_diff(array_keys($result['changed']), ['name'])) { throw new \RuntimeException('Invalid REST result.'); }
        if (!in_array($result['outcome'], ['verified', 'unchanged'], true)) { return; }
        if (in_array($body['version'], [8, 10], true)) {
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
    private static function sendBatch(array $first, array $body, int $deadline): bool
    {
        global $DB;
        $members = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => ['connection_id' => $first['connection_id'],
            new \Glpi\DBAL\QueryExpression('JSON_UNQUOTE(JSON_EXTRACT(`body`, \'$.batch_id\')) = ' . $DB->quote($body['batch_id']))],
            'ORDER' => 'id ASC', 'LIMIT' => 5]));
        if (count($members) > 4 || $members === []) { return false; }
        $expected = []; $entries = []; $client = null; $reservedAny = false;
        try {
            foreach ($members as $task) {
                try { $member = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { return false; }
                if (!is_array($member)) { return false; }
                foreach (['version', 'batch_id', 'installation', 'registration', 'generation', 'connection_id', 'settings_id', 'settings_hash', 'entity_id'] as $key) {
                    if (($member[$key] ?? null) !== ($body[$key] ?? null)) { return false; }
                }
                if (AgentTask::hash($member) !== $task['hash']) { return false; }
                $expected[$task['id']] = $task;
            }
            foreach ($expected as $task) {
                if ($task['state'] !== 'accepted' || !$task['slot'] || $task['result'] !== '{}') { continue; }
                $member = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                if (!self::dispatchReady($task, $member) || hrtime(true) + 2_000_000_000 >= $deadline) { continue; }
                $pair = AgentTask::byId($task['connection_id'], $member['pair_id']);
                if (!$pair) { continue; }
                $reserved = null;
                AssetUuidOperation::withAssetLock('Computer', (int) $pair['local_id'], static function () use ($pair, $task, $member, &$reserved, &$reservedAny): void {
                    global $DB;
                    $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
                    $connection = AgentInbox::connection($pair['connection_id']);
                    if ($member['version'] === 10 && (!$connection || ($connection['agent_transport'] ?? 'adapter') !== 'rest_records')) { return; }
                    $historical = in_array($context['settings']['version'] ?? 1, [2, 3, 4], true) || ($connection['agent_transport'] ?? 'adapter') === 'rest_records';
                    try {
                        $scope = self::context($pair);
                        if (AgentTask::hash($scope['settings']) !== $member['settings_hash']) { throw new \RuntimeException('REST settings changed before dispatch.'); }
                        $sourceChanged = in_array($member['version'], [10, 11], true) && $scope['source_hash'] !== $member['source_hash'];
                        // Eligibility ignores bookkeeping; an immutable execution must still match its exact source.
                        $context['rest_observe_only'] = $sourceChanged;
                    } catch (\RuntimeException $error) {
                        if (!$historical) { throw $error; }
                        // Changed rules can still collect original evidence, but never resend old commands.
                        $context['rest_observe_only'] = true;
                    }
                    if (in_array($member['version'], [10, 11], true) && empty($context['rest_sent']) && $context['rest_observe_only']) {
                        // Keep the stale slot, but let later batches advance without HTTP for this preparation.
                        $context['rest_next'] = time() + 10;
                        if ($member['version'] === 11) { self::deferUnsent($task, $context); }
                        else { self::saveReservation($task, $context); }
                        return;
                    }
                    $context['rest_reconcile_only'] = false;
                    if ($context['rest_observe_only'] && ($connection['agent_transport'] ?? 'adapter') === 'rest_records') {
                        $current = self::settingsState()[self::settingsKey($context['settings'])] ?? null;
                        $context['rest_reconcile_only'] = $current !== null && !$current['pending'] && $current['hash'] !== $member['settings_hash'];
                    }
                    $binding = AgentTask::binding($pair['connection_id']);
                    if (!$connection || !$binding || $binding['registration'] !== $member['registration'] || $binding['installation'] !== $member['installation']
                        || AgentTask::registration($binding)['generation'] !== $member['generation']) { return; }
                    if ($context['rest_until'] > time() || $context['rest_next'] > time()) { return; }
                    $context['rest_lease'] = bin2hex(random_bytes(16)); $context['rest_until'] = time() + 30;
                    if ($member['version'] === 11) {
                        if (self::saveReservation($task, $context)) { $reserved = $context; $reservedAny = true; }
                        return;
                    }
                    if ($DB->update(AgentTask::TABLE, ['context' => json_encode($context, JSON_THROW_ON_ERROR)],
                        ['id' => $task['id'], 'context' => $task['context'], 'state' => 'accepted', 'result' => '{}']) && $DB->affectedRows() === 1) { $reserved = $context; $reservedAny = true; }
                });
                if ($reserved === null) {
                    if (in_array($member['version'], [10, 11], true)) { continue; }
                    return $reservedAny;
                }
                $task['context'] = json_encode($reserved, JSON_THROW_ON_ERROR);
                $reserved['rest_state'] = 'unknown'; $reserved['rest_members'] = array_keys($expected);
                $entries[$task['id']] = ['task' => $task, 'body' => $member, 'context' => $reserved];
            }
            if ($entries === [] || AssetUuidOperation::hasAssetLocks() || !AgentWake::transactionIdle()) { return $reservedAny; }
            $connection = GlpiBConnection::find($first['connection_id']);
            if (!$connection || !$connection['active'] || ($body['version'] === 10 && ($connection['agent_transport'] ?? 'adapter') !== 'rest_records')) { return $reservedAny; }
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $client = new RestSession($connection, $deadline);
            $settings = reset($entries)['context']['settings'];
            $receiptKey = hash('sha256', $connection['id'] . $connection['agent_rest_url'] . $body['settings_hash']);
            $receipts = json_decode(\Config::getConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts'])['rest_settings_receipts'] ?? '{}', true);
            if (empty($receipts[$receiptKey]) && array_filter($entries, static fn (array $entry): bool => empty($entry['context']['rest_observe_only']))) {
                $response = $client->request('POST', self::SETTINGS, ['settings_id' => $settings['id'], 'hash' => $body['settings_hash'], 'body' => json_encode($settings, JSON_THROW_ON_ERROR)]);
                $valid = $response['status'] === 201 && is_int($response['body']['id'] ?? null) && $response['body']['id'] > 0;
                foreach ($entries as &$entry) { self::diagnose($entry['context'], $client, 'settings_post', $valid); } unset($entry);
                if (!$valid) { return $reservedAny; }
                $receipts[$receiptKey] = $response['body']['id'];
                \Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode($receipts, JSON_THROW_ON_ERROR)]);
            }
            $records = null;
            foreach ($entries as $entry) {
                if ($entry['context']['rest_sent'] || $entry['body']['expires'] < time() || !empty($entry['context']['rest_observe_only'])) {
                    $records = self::lookupBatch($client, $body['batch_id'], $expected, $entries);
                    if ($records === null) { return $reservedAny; }
                    self::observeBatch($entries, $records);
                    break;
                }
            }
            $inputs = [];
            foreach ($entries as &$entry) {
                if (!empty($entry['fenced_out'])) { continue; }
                $record = $records[$entry['task']['id']] ?? null;
                if (!empty($entry['context']['rest_observe_only']) && (empty($entry['context']['rest_reconcile_only']) || $record === null)) { continue; }
                $pending = $entry['body']['version'] === 11 && ($record['state'] ?? '') === 'origin_pending';
                if ($entry['task']['result'] !== '{}' || ($entry['body']['expires'] < time() && ($record['state'] ?? '') !== 'executing' && !$pending)) { continue; }
                if ($record !== null && (!in_array($record['state'] ?? '', ['accepted', 'executing', 'origin_pending'], true) || ($record['result'] ?? '{}') !== '{}')) { continue; }
                $entry['context']['rest_sent'] = true;
                if (!self::saveReservation($entry['task'], $entry['context'])) { $entry['fenced_out'] = true; continue; }
                $inputs[] = ['work_id' => $entry['task']['id'], 'hash' => $entry['task']['hash'], 'body' => $entry['task']['body']];
            } unset($entry);
            if ($inputs === []) { return $reservedAny; }
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
                    if (!empty($entry['fenced_out'])) { continue; }
                    $entry['context']['rest_until'] = 0; $entry['context']['rest_next'] = time() + 10;
                    $entry['context']['rest_expires'] = $entry['body']['expires'];
                    self::saveReservation($entry['task'], $entry['context']);
                } unset($entry);
            }
        }
        return $reservedAny;
    }

    private static function lookupBatch(RestSession $client, string $batch, array $expected, array &$entries): ?array
    {
        $body = json_decode(reset($expected)['body'], true, 32, JSON_THROW_ON_ERROR);
        $version = $body['version'];
        $scope = $version === 10 ? '&searchText%5Bcompiler%5D=10&rest_entity=' . $body['entity_id'] : ($version === 11 ? '&searchText%5Bcompiler%5D=11' : '');
        $response = $client->request('GET', self::RESOURCE . '?range=0-' . ($version === 11 ? '1' : '3') . '&get_hateoas=0&searchText%5Bbatch_id%5D=' . $batch . $scope);
        $valid = $response['status'] === 200 && array_is_list($response['body']) && count($response['body']) <= ($version === 11 ? 2 : 4);
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
                : (in_array($record['state'] ?? '', ['accepted', 'executing', 'origin_pending'], true) ? $record['state'] : 'unknown');
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
            if ($entry['body']['version'] === 11) {
                if (!AgentWake::transactionIdle()) { throw new \RuntimeException('REST observation requires an idle connection.'); }
                AgentInbox::sql('START TRANSACTION');
                try {
                    if (!self::lockedName($entry['task']) || !self::saveReservation($entry['task'], $entry['context'], $result)) {
                        AgentInbox::sql('ROLLBACK'); $entry['fenced_out'] = true; continue;
                    }
                    self::wakeNameQueue($entry['task']);
                    AgentInbox::sql('COMMIT');
                } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
            } else { self::saveReservation($entry['task'], $entry['context'], $result); }
            $entry['task']['result'] = json_encode($result, JSON_THROW_ON_ERROR);
            $entry['context']['rest_state'] = $result['outcome'] === 'stale' ? 'expired' : $result['outcome'];
            if ($entry['body']['version'] !== 11) { AgentTask::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $entry['task']['queue_id'], 'status' => 'waiting_agent'])); }
        } unset($entry);
    }
}
