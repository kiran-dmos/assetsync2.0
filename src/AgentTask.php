<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** A owns the pair, policy and immutable tasks. B owns execution and its durable journal. */
final class AgentTask
{
    public const PAIRS = 'glpi_plugin_assetsync20_agentpairs';
    public const TABLE = 'glpi_plugin_assetsync20_agenttasks';
    public const BINDINGS = 'glpi_plugin_assetsync20_agentbindings';
    public const OUTCOMES = ['accepted', 'executing', 'waiting_agent', 'matched', 'verified', 'unchanged', 'blocked', 'stale', 'partial', 'uncertain'];

    public static function install(): bool
    {
        global $DB;
        $ok = (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::PAIRS . '` (
            `id` CHAR(32) NOT NULL PRIMARY KEY, `itemtype` VARCHAR(100) NOT NULL DEFAULT \'Computer\',
            `connection_id` VARCHAR(255) NOT NULL, `registration` CHAR(32) NOT NULL,
            `installation` CHAR(32) NOT NULL, `local_id` INT NOT NULL, `local_entity` INT NOT NULL,
            `computer_id` INT NOT NULL, `entity_id` INT NOT NULL, `serial` VARCHAR(255) NOT NULL DEFAULT \'\',
            `route_id` VARCHAR(255) NOT NULL DEFAULT \'\', `confirmed` TINYINT NOT NULL DEFAULT 0,
            `snapshot` MEDIUMTEXT NOT NULL, `observed_at` BIGINT NOT NULL DEFAULT 0,
            UNIQUE KEY `local_pair` (`connection_id`, `itemtype`, `local_id`),
            UNIQUE KEY `remote_pair` (`connection_id`, `itemtype`, `computer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
            && (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` CHAR(32) PRIMARY KEY, `connection_id` VARCHAR(255) NOT NULL,
            `slot` VARCHAR(255) DEFAULT NULL, `hash` CHAR(64) NOT NULL, `body` MEDIUMTEXT NOT NULL,
            `state` VARCHAR(20) NOT NULL, `result` MEDIUMTEXT NOT NULL,
            `queue_id` INT DEFAULT NULL, `job` MEDIUMTEXT NOT NULL, `context` MEDIUMTEXT NOT NULL,
            `created_at` BIGINT NOT NULL, `expires` BIGINT NOT NULL, UNIQUE KEY `outstanding` (`slot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$ok) { return false; }
        if (!$DB->fieldExists(self::TABLE, 'result_received_at', false)
            && !$DB->doQuery('ALTER TABLE `' . self::TABLE . '` ADD `result_received_at` BIGINT DEFAULT NULL')) { return false; }
        foreach (['identity_basis' => 'TEXT DEFAULT NULL', 'local_fingerprint' => "CHAR(64) NOT NULL DEFAULT ''", 'checked_at' => 'BIGINT NOT NULL DEFAULT 0'] as $column => $definition) {
            if (!$DB->fieldExists(self::PAIRS, $column)
                && !$DB->doQuery('ALTER TABLE `' . self::PAIRS . '` ADD `' . $column . '` ' . $definition)) { return false; }
        }
        foreach (['id' => "CHAR(32) NOT NULL DEFAULT ''", 'itemtype' => "VARCHAR(100) NOT NULL DEFAULT 'Computer'"] as $column => $definition) {
            if (!$DB->fieldExists(self::PAIRS, $column) && !$DB->doQuery('ALTER TABLE `' . self::PAIRS . '` ADD `' . $column . '` ' . $definition)) { return false; }
        }
        // Deterministic IDs make an interrupted migration restartable without rewriting evidence.
        foreach ($DB->request(['SELECT' => ['connection_id'], 'FROM' => self::PAIRS, 'WHERE' => ['id' => '']]) as $legacy) {
            if (!$DB->update(self::PAIRS, ['id' => md5('legacy:' . $legacy['connection_id'])], ['connection_id' => $legacy['connection_id'], 'id' => ''])) { return false; }
        }
        $index = AgentInbox::one('SHOW INDEX FROM `' . self::PAIRS . '` WHERE Key_name = \'PRIMARY\' AND Seq_in_index = 1');
        if (($index['Column_name'] ?? '') !== 'id' && !$DB->doQuery('ALTER TABLE `' . self::PAIRS . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`),
            ADD UNIQUE KEY `local_pair` (`connection_id`, `itemtype`, `local_id`), ADD UNIQUE KEY `remote_pair` (`connection_id`, `itemtype`, `computer_id`)')) { return false; }
        if (!$DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::BINDINGS . '` (
            `connection_id` VARCHAR(255) PRIMARY KEY, `registration` CHAR(32) NOT NULL,
            `installation` CHAR(32) NOT NULL, UNIQUE KEY `registration` (`registration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')) { return false; }
        // Validate all retained identities before inserting any missing binding.
        $bindings = [];
        $registrations = [];
        foreach ($DB->request(['FROM' => self::BINDINGS]) as $binding) {
            $bindings[$binding['connection_id']] = $binding;
            $registrations[$binding['registration']] = $binding['connection_id'];
        }
        $missing = [];
        foreach ($DB->request(['SELECT' => ['connection_id', 'registration', 'installation'], 'FROM' => self::PAIRS]) as $pair) {
            $binding = $bindings[$pair['connection_id']] ?? null;
            if (($binding && ($binding['registration'] !== $pair['registration'] || $binding['installation'] !== $pair['installation']))
                || (isset($registrations[$pair['registration']]) && $registrations[$pair['registration']] !== $pair['connection_id'])) {
                return false;
            }
            if (!$binding) { $missing[$pair['connection_id']] = $pair; }
            $bindings[$pair['connection_id']] = $pair;
            $registrations[$pair['registration']] = $pair['connection_id'];
        }
        foreach ($missing as $binding) {
            if (!$DB->insert(self::BINDINGS, $binding)) { return false; }
        }
        foreach (['subscription_revision' => 'BIGINT NOT NULL DEFAULT 0', 'subscription_issued' => 'BIGINT NOT NULL DEFAULT 0',
            'subscription_active' => 'TINYINT NOT NULL DEFAULT 0', 'subscription_generation' => "CHAR(32) NOT NULL DEFAULT ''"] as $column => $definition) {
            if (!$DB->fieldExists(self::PAIRS, $column) && !$DB->doQuery('ALTER TABLE `' . self::PAIRS . '` ADD `' . $column . '` ' . $definition)) { return false; }
        }
        foreach (['subscription_revision' => 'BIGINT NOT NULL DEFAULT 0', 'subscription_cursor' => "CHAR(32) NOT NULL DEFAULT ''",
            'discovery_id' => "CHAR(32) NOT NULL DEFAULT ''", 'discovery_observed_id' => "CHAR(32) NOT NULL DEFAULT ''"] as $column => $definition) {
            if (!$DB->fieldExists(self::BINDINGS, $column) && !$DB->doQuery('ALTER TABLE `' . self::BINDINGS . '` ADD `' . $column . '` ' . $definition)) { return false; }
        }
        return true;
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function pair(string $connection, string $type = 'Computer', ?int $localId = null): ?array
    {
        global $DB;
        $rows = iterator_to_array($DB->request(['FROM' => self::PAIRS, 'WHERE' => array_merge([
            'connection_id' => $connection, 'itemtype' => $type], $localId === null ? [] : ['local_id' => $localId]), 'LIMIT' => 2]));
        return count($rows) === 1 ? array_values($rows)[0] : null;
    }

    public static function outstanding(string $connection, ?array $pair = null): ?array
    {
        global $DB;
        $pair ??= self::pair($connection);
        if (!$pair) { return null; }
        $task = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote('pair:' . $pair['id']));
        if ($task) { return $task; }
        $legacy = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote($connection));
        if (!$legacy) { return null; }
        $body = json_decode($legacy['body'], true, 32, JSON_THROW_ON_ERROR);
        return ($body['version'] ?? 0) < 3 && $pair['itemtype'] === 'Computer'
            && ($body['computer_id'] ?? 0) === (int) $pair['computer_id'] && ($body['registration'] ?? '') === $pair['registration'] ? $legacy : null;
    }

    public static function needsInspection(array $pair, bool $force): bool
    {
        global $DB;
        if (self::outstanding($pair['connection_id'], $pair)) { return false; }
        $context = self::context($pair);
        $queue = AgentInbox::one('SELECT * FROM `' . AssetSyncQueue::TABLE . '` WHERE `itemtype` = ' . $DB->quote($pair['itemtype'])
            . ' AND `items_id` = ' . (int) $pair['local_id'] . ' AND `glpi_b_connection_id` = ' . $DB->quote($pair['connection_id']));
        $link = AssetSyncLink::find($pair['itemtype'], (int) $pair['local_id'], $pair['connection_id']);
        $unfinishedLink = $queue && $queue['status'] === 'done'
            && ((int) ($queue['remote_items_id'] ?? 0) !== (int) $pair['computer_id']
                || $queue['route_id'] !== $pair['route_id'] || ($link['status'] ?? '') !== AssetSyncLink::STATUS_SYNCED);
        return $force || (int) ($pair['checked_at'] ?? 0) < time() - 3600
            || ($pair['local_fingerprint'] ?? '') !== self::localFingerprint($context) || $unfinishedLink;
    }

    private static function localFingerprint(array $context): string
    {
        $parts = [$context['policy'], $context['metadata'], $context['values']];
        if (isset($context['identity'])) { $parts[] = $context['identity']; }
        return self::hash($parts);
    }

    /** V4 only: absent is unknown, explicit NULL is blank, and "0" is an identifier. */
    public static function identity(array $row): array
    {
        $identity = [];
        foreach (['serial', 'otherserial'] as $key) {
            if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))
                || strlen((string) $row[$key]) > 255 || preg_match('/[\x00-\x1f\x7f]/', (string) $row[$key])) {
                throw new \RuntimeException('Native identity is missing or invalid.');
            }
            $identity[$key] = trim((string) $row[$key], ' ');
        }
        return $identity;
    }

    public static function isMatch(array $body): bool
    {
        return (($body['version'] ?? 0) === 4 && in_array($body['kind'] ?? '', ['match', 'reverify'], true))
            || (($body['version'] ?? 0) === 5 && in_array($body['kind'] ?? '', ['prepare_create', 'create', 'confirm_create'], true));
    }

    public static function model(string $type): object
    {
        global $CFG_GLPI;
        if (!in_array($type, ['Computer', 'Printer'], true) || !in_array($type, $CFG_GLPI['inventory_types'] ?? [], true)) {
            throw new \RuntimeException('This installed inventory type is not supported by the agent.');
        }
        return new $type();
    }

    public static function binding(string $connection): ?array
    {
        global $DB;
        return AgentInbox::one('SELECT * FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($connection));
    }

    public static function register(string $connectionId, string $installation): array
    {
        global $DB;
        $connection = AgentInbox::connection($connectionId);
        if (!$connection || !GlpiBConnection::isAgent($connection) || !preg_match('/^[a-f0-9]{32}$/D', $installation)) {
            throw new \RuntimeException('Choose an active agent connection and B installation identity.');
        }
        $credentials = ['registration' => bin2hex(random_bytes(16)), 'generation' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(32))];
        AgentInbox::sql('START TRANSACTION');
        try {
            self::write($DB->insert(AgentInbox::REGISTRATIONS, ['id' => $credentials['registration'], 'generation' => $credentials['generation'],
                'token_hash' => hash('sha256', $credentials['token']), 'connection_id' => $connectionId,
                'connection_identity' => AgentInbox::identity($connection), 'routes' => '{}', 'active' => 1]));
            self::write($DB->insert(self::BINDINGS, ['connection_id' => $connectionId, 'registration' => $credentials['registration'], 'installation' => $installation]));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        return $credentials;
    }

    public static function registration(array $binding): array
    {
        global $DB;
        $connection = AgentInbox::connection($binding['connection_id']);
        $registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($binding['registration']));
        if (!$connection || !GlpiBConnection::isAgent($connection) || !$registration || !$registration['active']
            || $registration['connection_id'] !== $binding['connection_id'] || $registration['connection_identity'] !== AgentInbox::identity($connection)) {
            throw new \RuntimeException('Installation registration is unavailable.');
        }
        return $registration;
    }

    public static function byId(string $connection, string $id): ?array
    {
        global $DB;
        return AgentInbox::one('SELECT * FROM `' . self::PAIRS . '` WHERE `connection_id` = ' . $DB->quote($connection) . ' AND `id` = ' . $DB->quote($id));
    }

    public static function taskPair(array $task): ?array
    {
        global $DB;
        $body = isset($task['body']) ? json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR) : $task;
        if (self::isMatch($body)) { return null; }
        if (($body['version'] ?? 0) >= 3) {
            $pair = self::byId($body['connection_id'], $body['pair_id']);
            return $pair && $pair['itemtype'] === $body['itemtype'] && (int) $pair['computer_id'] === $body['items_id']
                && (int) $pair['entity_id'] === $body['entity_id'] && $pair['registration'] === $body['registration']
                && $pair['installation'] === $body['installation'] ? $pair : null;
        }
        return AgentInbox::one('SELECT * FROM `' . self::PAIRS . '` WHERE `connection_id` = ' . $DB->quote($body['connection_id'])
            . ' AND `itemtype` = \'Computer\' AND `computer_id` = ' . (int) $body['computer_id'] . ' AND `registration` = ' . $DB->quote($body['registration']));
    }

    /** Protected setup can bootstrap inspection without routes, mappings, a REST link or REST credentials. */
    public static function declare(string $connectionId, string $installation, int $localId, int $remoteId, int $entityId, string $type = 'Computer'): array
    {
        global $DB;
        $connection = AgentInbox::connection($connectionId);
        $computer = self::model($type);
        if (!$connection || !GlpiBConnection::isAgent($connection) || !preg_match('/^[a-f0-9]{32}$/D', $installation)
            || $remoteId <= 0 || $entityId < 0 || !$computer->can($localId, READ) || !$computer->getFromDB($localId)
            || $computer->fields['is_deleted'] || $computer->fields['is_template'] || trim((string) $computer->fields['serial']) === '') {
            throw new \RuntimeException('Choose an active agent connection and an existing visible asset with a serial.');
        }
        self::selectedAsset($type, $localId, (int) $computer->fields['entities_id']);
        $binding = self::binding($connectionId);
        $credentials = $binding ? [] : self::register($connectionId, $installation);
        $binding = self::binding($connectionId);
        self::registration($binding);
        if ($binding['installation'] !== $installation) { throw new \RuntimeException('Installation identity differs from the registration.'); }
        $pairId = bin2hex(random_bytes(16));
        AgentInbox::sql('START TRANSACTION');
        try {
            self::write($DB->insert(self::PAIRS, ['id' => $pairId, 'itemtype' => $type, 'connection_id' => $connectionId, 'registration' => $binding['registration'],
                'installation' => $installation, 'local_id' => $localId, 'local_entity' => (int) $computer->fields['entities_id'],
                'computer_id' => $remoteId, 'entity_id' => $entityId, 'snapshot' => '{}']));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) {
            $DB->doQuery('ROLLBACK');
            throw new \RuntimeException('Pair already exists or could not be stored. No pair was replaced.', 0, $error);
        }
        try {
            self::inspect($connectionId, $pairId);
        } catch (\Throwable) {
            // The candidate is durable; never lose its once-visible credentials on publication failure.
            $credentials['inspection_error'] = 'Candidate saved, but inspection could not be queued. Use Refresh observation.';
        }
        return $credentials;
    }

    public static function rotate(string $connectionId): array
    {
        $binding = self::binding($connectionId);
        if (!$binding) { throw new \RuntimeException('Agent registration unavailable.'); }
        $registration = self::registration($binding);
        return ['registration' => $registration['id'], 'generation' => $registration['generation'], 'token' => AgentInbox::rotate($registration['id'])];
    }

    public static function recent(string $connectionId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['SELECT' => ['id', 'state', 'created_at', 'queue_id'],
            'FROM' => self::TABLE, 'WHERE' => ['connection_id' => $connectionId],
            'ORDER' => 'created_at DESC', 'LIMIT' => 5]));
    }

    public static function discoveryTask(string $connection): ?array
    {
        global $DB;
        return AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote('discovery:' . hash('sha256', $connection)));
    }

    public static function discover(string $connection, string $type, int $entity, string $search = '', int $cursor = 0): void
    {
        global $DB;
        self::model($type);
        if ($entity < -1 || $cursor < 0 || strlen($search) > 100) { throw new \RuntimeException('Invalid discovery filter.'); }
        $binding = self::binding($connection);
        if (!$binding) { throw new \RuntimeException('Register the installation first.'); }
        $registration = self::registration($binding);
        $task = ['version' => 3, 'id' => bin2hex(random_bytes(16)), 'kind' => 'discover',
            'installation' => $binding['installation'], 'registration' => $registration['id'], 'generation' => $registration['generation'],
            'connection_id' => $connection, 'pair_id' => '', 'itemtype' => $type, 'items_id' => 0, 'entity_id' => $entity,
            'serial' => '', 'policy' => '', 'expires' => time() + 300, 'changes' => [], 'filter' => ['search' => $search, 'cursor' => $cursor]];
        $task['hash'] = self::hash($task);
        AgentInbox::sql('START TRANSACTION');
        try {
            self::write($DB->insert(self::TABLE, ['id' => $task['id'], 'connection_id' => $connection, 'slot' => 'discovery:' . hash('sha256', $connection),
                'hash' => $task['hash'], 'body' => json_encode($task, JSON_THROW_ON_ERROR), 'state' => 'accepted', 'result' => '{}',
                'job' => '[]', 'context' => '{}', 'created_at' => time(), 'expires' => $task['expires']]));
            self::write($DB->update(self::BINDINGS, ['discovery_id' => $task['id']], ['connection_id' => $connection]));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    public static function discovery(string $connection): array
    {
        global $DB;
        $binding = self::binding($connection);
        $capabilities = null;
        if (!empty($binding['discovery_observed_id'])) {
            $observed = AgentInbox::one('SELECT `result` FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($binding['discovery_observed_id']));
            $capabilities = json_decode($observed['result'] ?? '{}', true)['discovery']['types'] ?? null;
        }
        $where = ['connection_id' => $connection];
        if (!empty($binding['discovery_id'])) { $where['id'] = $binding['discovery_id']; }
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDER' => 'created_at DESC, id DESC', 'LIMIT' => 100]);
        foreach ($rows as $row) {
            $body = json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($body['kind'] !== 'discover') { continue; }
            $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
            $state = $row['result'] === '{}' ? 'pending' : (!isset($result['discovery']) ? 'error'
                : ((int) $result['discovery']['observed_at'] < time() - 300 ? 'stale' : ($result['discovery']['rows'] === [] ? 'empty' : 'ready')));
            return ['state' => $state, 'task' => $body, 'page' => $result['discovery'] ?? [], 'capabilities' => $capabilities];
        }
        return ['state' => 'not_requested', 'page' => [], 'capabilities' => $capabilities];
    }

    private static function validateDiscovery(array $task, array $result): void
    {
        $page = $result['discovery'] ?? [];
        if ($result['changed'] !== [] || !is_array($page['rows'] ?? null) || count($page['rows']) > 50
            || !is_int($page['observed_at'] ?? null) || $page['observed_at'] < $task['expires'] - 300 || $page['observed_at'] > $task['expires']
            || !is_array($page['entities'] ?? null) || count($page['entities']) > 50 || !is_array($page['types'] ?? null)
            || strlen(json_encode($page, JSON_THROW_ON_ERROR)) > 49152
            || (!is_null($page['next_cursor'] ?? null) && (!is_int($page['next_cursor']) || $page['next_cursor'] <= $task['filter']['cursor']))) {
            throw new \RuntimeException('Invalid discovery page.');
        }
        $last = $task['filter']['cursor'];
        foreach ($page['rows'] as $row) {
            if (!is_array($row) || ($row['itemtype'] ?? '') !== $task['itemtype'] || !is_int($row['items_id'] ?? null)
                || $row['items_id'] <= $last || !is_int($row['entity_id'] ?? null) || $row['entity_id'] < 0
                || ($task['entity_id'] >= 0 && $row['entity_id'] !== $task['entity_id'])
                || !is_string($row['name'] ?? null) || !is_string($row['serial'] ?? null) || !is_string($row['reason'] ?? null)) {
                throw new \RuntimeException('Invalid discovery summary.');
            }
            $last = $row['items_id'];
        }
    }

    public static function localPage(string $type, int $entity, string $search, int $cursor): array
    {
        global $DB;
        $item = self::model($type);
        if ($entity < 0 || $cursor < 0 || strlen($search) > 100 || !\Session::haveAccessToEntity($entity)) { throw new \RuntimeException('Invalid local filter.'); }
        $sql = 'SELECT `id`,`name`,`serial`,`entities_id` FROM `' . $item::getTable() . '` WHERE `entities_id` = ' . $entity
            . ' AND `is_deleted` = 0 AND `is_template` = 0 AND `id` > ' . $cursor;
        if ($search !== '') { $sql .= ' AND (`name` LIKE ' . $DB->quote('%' . addcslashes($search, '%_') . '%')
            . ' OR `serial` LIKE ' . $DB->quote('%' . addcslashes($search, '%_') . '%') . ')'; }
        $result = AgentInbox::sql($sql . ' ORDER BY `id` LIMIT 51');
        $rows = []; $scanned = 0; $last = $cursor; $next = null;
        while ($row = $DB->fetchAssoc($result)) {
            if (++$scanned > 50) { $next = $last; break; }
            $last = (int) $row['id'];
            if (!$item->can($last, READ)) { continue; }
            $rows[] = ['items_id' => $last, 'itemtype' => $type, 'entity_id' => (int) $row['entities_id'],
                'name' => (string) $row['name'], 'serial' => trim((string) $row['serial'])];
        }
        return ['rows' => $rows, 'next_cursor' => $next];
    }

    /** Only the displayed page can change the cart; other pages remain explicitly selected. */
    public static function selectPage(array $cart, string $type, int $entity, string $search, int $cursor, array $selected): array
    {
        if (count($selected) > 50 || count($cart) > 200) { throw new \RuntimeException('Selection limit exceeded (50 per page, 200 total).'); }
        $page = array_column(self::localPage($type, $entity, $search, $cursor)['rows'], null, 'items_id');
        foreach ($selected as $id) {
            if (!is_scalar($id) || !ctype_digit((string) $id) || !isset($page[(int) $id]) || $page[(int) $id]['serial'] === '') {
                throw new \RuntimeException('Selection changed or is outside the displayed readable page.');
            }
        }
        foreach ($page as $id => $row) { unset($cart[$id]); }
        foreach ($selected as $id) { $cart[(int) $id] = $entity; }
        if (count($cart) > 200) { throw new \RuntimeException('Selection limit is 200 assets. Clear selections before adding more.'); }
        return $cart;
    }

    public static function selectedAsset(string $type, int $id, int $entity): array
    {
        $item = self::model($type);
        if (!$item->getFromDB($id) || !$item->can($id, READ) || !\Session::haveAccessToEntity($entity)
            || (int) $item->fields['entities_id'] !== $entity || $item->fields['is_deleted'] || $item->fields['is_template']) {
            throw new \RuntimeException('Selected A asset is no longer readable in its original entity.');
        }
        return ['items_id' => $id, 'itemtype' => $type, 'entity_id' => $entity, 'name' => (string) $item->fields['name'],
            'serial' => trim((string) $item->fields['serial'])];
    }

    public static function scope(array $pair, bool $confirmed = true): array
    {
        global $DB;
        $connection = AgentInbox::connection($pair['connection_id']);
        $registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($pair['registration']));
        $type = $pair['itemtype'];
        $computer = self::model($type);
        if (!$connection || !GlpiBConnection::isAgent($connection) || !$registration || !$registration['active']
            || $registration['connection_identity'] !== AgentInbox::identity($connection)
            || !$computer->getFromDB((int) $pair['local_id']) || $computer->fields['is_deleted'] || $computer->fields['is_template']
            || (int) $computer->fields['entities_id'] !== (int) $pair['local_entity']) {
            throw new \RuntimeException('Agent binding or local scope is unavailable.');
        }
        if (!$confirmed) {
            return ['connection' => $connection, 'registration' => $registration, 'asset' => $computer->fields];
        }
        if (!empty($pair['identity_basis'])) {
            $binding = self::binding($pair['connection_id']);
            if (!$binding || $binding['registration'] !== $pair['registration'] || $binding['installation'] !== $pair['installation']) {
                throw new \RuntimeException('Automatic pair binding changed.');
            }
        }
        EntitySyncRoute::loadAll(true);
        $match = EntitySyncRoute::matchAsset($type, (string) $pair['local_entity'], [], $pair['connection_id']);
        $route = $match['matches'][0]['route'] ?? null;
        $link = AssetSyncLink::find($type, (int) $pair['local_id'], $pair['connection_id']);
        $links = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
            'itemtype' => $type, 'glpi_b_connection_id' => $pair['connection_id'], 'remote_items_id' => (int) $pair['computer_id']], 'LIMIT' => 2]));
        if (!$pair['confirmed'] || !$route || count($match['matches']) !== 1 || $match['conflicts'] !== []
            || $route['id'] !== $pair['route_id'] || $route['include_child_entities'] || $route['asset_types'] !== [$type]
            || (int) $route['glpi_b_target_entity_id'] !== (int) $pair['entity_id'] || $route['glpi_b_target_entity_id'] === ''
            || (empty($pair['identity_basis']) ? trim((string) $computer->fields['serial']) !== $pair['serial']
                : self::identity($computer->fields) !== json_decode($pair['identity_basis'], true, 32, JSON_THROW_ON_ERROR)['a']) || !$link || count($links) !== 1
            || (int) $link['remote_items_id'] !== (int) $pair['computer_id'] || $link['confirmed_identity'] !== AgentInbox::identity($connection)
            || (json_decode($registration['routes'], true)[$route['id']]['fingerprint'] ?? '') !== AgentInbox::routeIdentity($route)) {
            throw new \RuntimeException('Agent pair, serial or exact route changed.');
        }
        return ['connection' => $connection, 'registration' => $registration, 'asset' => $computer->fields, 'route' => $route];
    }

    public static function inspect(string $connectionId, ?string $pairId = null): void
    {
        $pair = $pairId === null ? self::pair($connectionId) : self::byId($connectionId, $pairId);
        if (!$pair) { throw new \RuntimeException('Declare an agent pair first.'); }
        self::publish($pair, 'inspect', [], null, []);
    }

    public static function confirm(string $connectionId, string $routeId, ?string $pairId = null): void
    {
        global $DB;
        $pair = $pairId === null ? self::pair($connectionId) : self::byId($connectionId, $pairId);
        if (!$pair) { throw new \RuntimeException('Declare an agent pair first.'); }
        AssetUuidOperation::withAssetLock($pair['itemtype'], (int) $pair['local_id'], static function () use ($pair, $routeId, $DB): void {
            AgentInbox::sql('START TRANSACTION');
            try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($pair['connection_id']) . ' FOR UPDATE');
            $pair = self::byId($pair['connection_id'], $pair['id']);
            $type = $pair['itemtype'];
            $scope = self::scope($pair, false);
            $item = self::model($type);
            if (!$item->can((int) $pair['local_id'], READ) || !\Session::haveAccessToEntity((int) $pair['local_entity'])) { throw new \RuntimeException('Asset unavailable.'); }
            $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            $route = EntitySyncRoute::find($routeId);
            if ((int) $pair['observed_at'] < time() - 300 || empty($snapshot['serial'])
                || $snapshot['serial'] !== trim((string) $scope['asset']['serial'])
                || ($snapshot['items_id'] ?? $snapshot['computer_id'] ?? null) !== (int) $pair['computer_id'] || ($snapshot['entity_id'] ?? null) !== (int) $pair['entity_id']
                || ($snapshot['itemtype'] ?? 'Computer') !== $type
                || !$route || !$route['active'] || $route['include_child_entities'] || $route['asset_types'] !== [$type]
                || $route['glpi_b_connection_id'] !== $pair['connection_id'] || $route['glpi_b_target_entity_id'] === ''
                || (int) $route['glpi_a_source_entity_id'] !== (int) $pair['local_entity']
                || (int) $route['glpi_b_target_entity_id'] !== (int) $pair['entity_id']) {
                throw new \RuntimeException('Fresh independent inspection, matching serial and an exact single-type route are required.');
            }
            $link = AssetSyncLink::find($type, (int) $pair['local_id'], $pair['connection_id']);
            if ($link && (int) $link['remote_items_id'] !== (int) $pair['computer_id']) {
                throw new \RuntimeException('Existing link differs; automatic relinking is forbidden.');
            }
            $duplicates = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
                'itemtype' => $type, 'glpi_b_connection_id' => $pair['connection_id'], 'remote_items_id' => (int) $pair['computer_id']], 'LIMIT' => 2]));
            if (count($duplicates) > ($link ? 1 : 0)) { throw new \RuntimeException('Reverse link is not unique.'); }
                if (!$link) {
                    self::write(AssetSyncLink::saveStatus($type, (int) $pair['local_id'], $pair['connection_id'], $routeId,
                        AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'Agent pair independently confirmed.', (int) $pair['computer_id']));
                }
                self::write($DB->update(AssetSyncLink::TABLE, ['route_id' => $routeId, 'confirmed_remote_id' => (int) $pair['computer_id'],
                    'confirmed_entity_id' => (int) $pair['entity_id'], 'confirmed_identity' => AgentInbox::identity($scope['connection'])],
                    ['itemtype' => $type, 'items_id' => (int) $pair['local_id'], 'glpi_b_connection_id' => $pair['connection_id']]));
                $registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($pair['registration']) . ' FOR UPDATE');
                $approved = json_decode($registration['routes'], true, 32, JSON_THROW_ON_ERROR);
                $approved[$routeId] = ['fingerprint' => AgentInbox::routeIdentity($route), 'source_entity' => (int) $pair['local_entity']];
                self::write($DB->update(AgentInbox::REGISTRATIONS, ['routes' => json_encode($approved, JSON_THROW_ON_ERROR)], ['id' => $pair['registration']]));
                self::write($DB->update(self::PAIRS, ['confirmed' => 1, 'route_id' => $routeId, 'serial' => $snapshot['serial']], ['id' => $pair['id']]));
                self::scope(self::byId($pair['connection_id'], $pair['id']));
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        });
    }

    public static function catalog(string $connection, string $type): array
    {
        global $DB;
        self::model($type);
        $fields = [];
        foreach ($DB->request(['FROM' => self::PAIRS, 'WHERE' => ['connection_id' => $connection, 'itemtype' => $type,
            'observed_at' => ['>=', time() - 3600]], 'ORDER' => 'observed_at DESC,local_id DESC', 'LIMIT' => 32]) as $pair) {
            if (!self::model($type)->can((int) $pair['local_id'], READ)
                || !\Session::haveAccessToEntity((int) $pair['local_entity'])) { continue; }
            try {
                self::scope($pair, false);
                $snapshot = json_decode($pair['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\RuntimeException | \JsonException) { continue; }
            foreach ($snapshot['fields'] ?? [] as $key => $field) {
                $fields[] = ['key' => $key, 'uid' => $key, 'id' => (string) $field['option'], 'label' => $field['label'],
                    'type' => $field['type'], 'group' => $field['group'], 'supported' => true, 'reason' => ''];
            }
            if ($fields !== []) { break; }
        }
        return ['success' => $fields !== [], 'fields' => $fields, 'message' => $fields === []
            ? 'No fresh scoped agent metadata. Declare the pair and request inspection on Agent Pair.' : 'Scoped B observation available.'];
    }

    public static function validateMappings(string $type, array $rows, bool $creation = false): void
    {
        self::model($type);
        if (count($rows) > 32) { throw new \RuntimeException('Agent mode supports at most 32 mappings per type.'); }
        foreach ($rows as $row) {
            if (!in_array($row['source_of_truth'] ?? '', ['glpi_a', 'glpi_b', 'both'], true)) { throw new \RuntimeException('Unsupported agent mapping authority.'); }
            if (($row['source_of_truth'] ?? '') === 'both') {
                $a = str_starts_with($row['glpi_a_field_key'] ?? '', $type . '.') ? substr($row['glpi_a_field_key'], strlen($type) + 1) : ($row['glpi_a_field_key'] ?? '');
                $b = str_starts_with($row['glpi_b_field_key'] ?? '', $type . '.') ? substr($row['glpi_b_field_key'], strlen($type) + 1) : ($row['glpi_b_field_key'] ?? '');
                if ($creation || !in_array($a, ['name', 'comment'], true) || $a !== $b) {
                    throw new \RuntimeException('Agent Both requires the same native Name or Comments field on an existing confirmed pair; custom fields and creation are unsupported.');
                }
            }
            foreach (['glpi_a_field_key', 'glpi_b_field_key'] as $side) {
                $key = $row[$side] ?? '';
                if (!in_array($key, ['name', 'comment', $type . '.name', $type . '.comment'], true)
                    && !preg_match('/^' . preg_quote($type, '/') . '\.PluginFields[A-Za-z0-9]+\.[a-z][a-z0-9_]*$/D', $key)) {
                    throw new \RuntimeException('Unsupported agent mapping: Name, Comments and existing custom text, yesno or date only; UUID is unsupported.');
                }
            }
        }
    }

    /** The caller owns no DB transaction. Task publication and releasing its queue claim commit together. */
    public static function publish(array $pair, string $kind, array $changes, ?array $job, array $context, ?string $previous = null): array
    {
        global $DB;
        $scope = self::scope($pair, $job !== null || $kind === 'apply');
        if (self::outstanding($pair['connection_id'], $pair) && $previous === null) { throw new \RuntimeException('This pair has an outstanding task.'); }
        if ($job !== null && (!isset($context['modification']) || !isset($context['source_guard']))) { $context['source_guard'] = self::creationSourceGuard($job); }
        $task = ['version' => 3, 'id' => bin2hex(random_bytes(16)), 'kind' => $kind,
            'installation' => $pair['installation'], 'registration' => $pair['registration'], 'generation' => $scope['registration']['generation'],
            'connection_id' => $pair['connection_id'], 'pair_id' => $pair['id'], 'itemtype' => $pair['itemtype'],
            'items_id' => (int) $pair['computer_id'], 'entity_id' => (int) $pair['entity_id'],
            'serial' => $pair['confirmed'] ? $pair['serial'] : '', 'policy' => $context['policy'] ?? '',
            'expires' => time() + 300, 'changes' => $changes];
        if (!empty($pair['identity_basis'])) {
            $task['version'] = 4;
            $task['identity'] = json_decode($pair['identity_basis'], true, 32, JSON_THROW_ON_ERROR)['b'];
        }
        if (isset($context['modification'])) {
            $task['version'] = 6;
            $task['identity'] = $kind === 'apply' ? self::identity($context['b_snapshot'])
                : (!empty($pair['identity_basis']) ? json_decode($pair['identity_basis'], true, 32, JSON_THROW_ON_ERROR)['b'] : null);
            $task['guard'] = $kind === 'apply' ? ['modification' => $context['b_snapshot']['modification'],
                'native_values' => $context['b_snapshot']['native_values'], 'origin_epoch' => $context['b_origin']] : null;
        }
        $task['hash'] = self::hash($task);
        if (strlen(json_encode(['status' => 200, 'task' => $task], JSON_THROW_ON_ERROR)) > 65536) {
            throw new \RuntimeException('Task exceeds the 64 KiB transport envelope.');
        }
        AgentInbox::sql('START TRANSACTION');
        try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($pair['connection_id']) . ' FOR UPDATE');
            if ($job !== null && !AssetSyncQueue::owns($job)) { throw new \RuntimeException('Queue claim changed.'); }
            if ($previous !== null) {
                $old = self::outstanding($pair['connection_id'], $pair);
                if ($old) { $old = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($old['id']) . ' FOR UPDATE'); }
                if (!$old || $old['id'] !== $previous) { throw new \RuntimeException('Previous task ownership changed.'); }
                self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $previous, 'slot' => $old['slot']]));
                if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Previous task changed.'); }
            }
            self::write($DB->insert(self::TABLE, ['id' => $task['id'], 'connection_id' => $pair['connection_id'], 'slot' => 'pair:' . $pair['id'],
                'hash' => $task['hash'], 'body' => json_encode($task, JSON_THROW_ON_ERROR), 'state' => 'accepted', 'result' => '{}',
                'queue_id' => $job['id'] ?? null, 'job' => json_encode($job ?? [], JSON_THROW_ON_ERROR),
                'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => time(), 'expires' => $task['expires']]));
            if ($job !== null) { self::write(AssetSyncQueue::waitForAgent($job)); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        return $task;
    }

    public static function decode(string $body): array
    {
        if (strlen($body) > 65536) { throw new \InvalidArgumentException(); }
        $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request)) { throw new \InvalidArgumentException(); }
        $keys = ($request['kind'] ?? '') === 'pull' ? ['kind', 'registration', 'generation', 'installation', 'connection_id']
            : ['kind', 'registration', 'generation', 'installation', 'connection_id', 'id', 'hash', ($request['kind'] ?? '') === 'recovery' ? 'proof' : 'result'];
        if (($request['kind'] ?? '') === 'pull' && array_key_exists('recovery_version', $request)) {
            if ($request['recovery_version'] !== 1) { throw new \InvalidArgumentException(); }
            $keys[] = 'recovery_version';
        }
        if (($request['kind'] ?? '') === 'recovery') {
            if (!is_string($request['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $request['id'])
                || !is_string($request['hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['hash'])
                || !is_array($request['proof'] ?? null)) { throw new \InvalidArgumentException(); }
        }
        if (($request['kind'] ?? '') === 'pull' && array_key_exists('subscriptions_only', $request)) {
            if (!is_bool($request['subscriptions_only'])) { throw new \InvalidArgumentException(); }
            $keys[] = 'subscriptions_only';
        }
        if (($request['kind'] ?? '') === 'pull' && array_key_exists('task_versions', $request)) {
            if (!is_array($request['task_versions']) || !array_is_list($request['task_versions'])
                || count($request['task_versions']) > 6 || array_diff($request['task_versions'], [1, 2, 3, 4, 5, 6]) !== []
                || count(array_filter($request['task_versions'], 'is_int')) !== count($request['task_versions'])) { throw new \InvalidArgumentException(); }
            $keys[] = 'task_versions';
        }
        if (array_diff(array_keys($request), $keys) !== [] || array_diff($keys, array_keys($request)) !== []) { throw new \InvalidArgumentException(); }
        foreach (['registration', 'generation', 'installation'] as $key) {
            if (!is_string($request[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $request[$key])) { throw new \InvalidArgumentException(); }
        }
        if (!is_string($request['connection_id']) || strlen($request['connection_id']) > 255) { throw new \InvalidArgumentException(); }
        return $request;
    }

    /** Auth is checked for every pull/result, independently of notification ACKs. */
    public static function exchange(array $request, string $token, ?AgentApi $api = null): array
    {
        global $DB;
        if (($api === null && !preg_match('/^[a-f0-9]{64}$/D', $token)) || !in_array($request['kind'] ?? '', ['pull', 'result', 'recovery'], true)) {
            return ['status' => 401];
        }
        AgentInbox::sql('START TRANSACTION');
        try {
            $binding = AgentInbox::one('SELECT * FROM `' . self::BINDINGS . '` WHERE `registration` = ' . $DB->quote((string) ($request['registration'] ?? '')) . ' FOR UPDATE');
            if (!$binding) { throw new \UnexpectedValueException('Unknown installation.'); }
            try { $registration = self::registration($binding); }
            catch (\RuntimeException $error) { throw new \UnexpectedValueException('Current registration unavailable.', 0, $error); }
            $connection = AgentInbox::connection($binding['connection_id']);
            if (!$connection) { throw new \UnexpectedValueException('Agent connection unavailable.'); }
            if ($api !== null) { $api->assertPeer($connection); }
            if (($api === null && (($connection['agent_auth'] ?? 'legacy_bearer') !== 'legacy_bearer'
                    || !hash_equals($registration['token_hash'], hash('sha256', $token))))
                || ($request['generation'] ?? '') !== $registration['generation']
                || ($request['installation'] ?? '') !== $binding['installation'] || ($request['connection_id'] ?? '') !== $binding['connection_id']) {
                throw new \UnexpectedValueException('Task authentication failed.');
            }
            $task = null;
            if ($request['kind'] === 'pull') {
                $response = ['status' => 200, 'task' => null];
                if (($request['subscriptions_only'] ?? false) !== true) {
                    $candidates = AgentInbox::sql('SELECT * FROM `' . self::TABLE . '` WHERE `connection_id` = ' . $DB->quote($binding['connection_id'])
                        . ' AND `slot` IS NOT NULL AND `state` IN (\'accepted\',\'executing\') ORDER BY `created_at`, `id` LIMIT '
                        . ($api === null ? 1 : 50) . ' FOR UPDATE');
                    while ($candidate = $DB->fetchAssoc($candidates)) {
                        if (self::readableApiTask($api, $candidate)) { $task = $candidate; break; }
                    }
                }
                if ($task && in_array($task['state'], ['accepted', 'executing'], true)) {
                    $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                    if ($body['registration'] !== $registration['id'] || $body['generation'] !== $registration['generation']
                        || $body['installation'] !== $binding['installation']) { throw new \UnexpectedValueException('Task ownership changed.'); }
                    $matching = self::isMatch($body);
                    $pair = $body['kind'] === 'discover' || $matching ? null : self::taskPair($task);
                    $policyCurrent = true;
                    try {
                        if ($matching) {
                            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
                            $currentScope = self::matchContext($binding['connection_id'], $body['itemtype'], $expected['local_id']);
                            $policyCurrent = self::sameMatchScope($currentScope, $expected['scope']);
                            if ($body['version'] === 5) {
                                $sourceJob = ['glpi_b_connection_id' => $binding['connection_id'], 'itemtype' => $body['itemtype'], 'items_id' => $expected['local_id']];
                                $policyCurrent = $policyCurrent && self::creationSourceGuard($sourceJob) === $expected['source']['guard'];
                            }
                        } elseif ($body['kind'] !== 'discover') {
                            if (!$pair) { throw new \RuntimeException('Pair unavailable.'); }
                            self::scope($pair, $body['kind'] === 'apply' || (bool) $pair['confirmed']);
                        }
                        if ($task['queue_id'] !== null && !$matching) {
                            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
                            $policyCurrent = $expected['policy'] === self::policy($pair);
                            if ($body['version'] === 6) {
                                // Bearer pulls have no interactive Fields profile. Check the captured raw guard,
                                // then native values/instant/identity; worker metadata and rights are rechecked at finalize.
                                $native = self::nativeEvidence($pair);
                                $policyCurrent = $policyCurrent && self::sameEvidence($expected['modification'], $native['modification'])
                                    && self::sameEvidence($expected['native_values'], $native['native_values']) && self::sameEvidence($expected['identity'], self::identity($native['asset']))
                                    && self::sameEvidence($expected['source_guard'], self::creationSourceGuard([
                                        'glpi_b_connection_id' => $pair['connection_id'], 'itemtype' => $pair['itemtype'], 'items_id' => (int) $pair['local_id']]));
                            }
                            if ($task['state'] === 'accepted' && (int) $task['expires'] < time() + 300) {
                                if (isset($expected['source_guard'])) {
                                    $freshGuard = self::creationSourceGuard([
                                        'glpi_b_connection_id' => $pair['connection_id'], 'itemtype' => $pair['itemtype'],
                                        'items_id' => (int) $pair['local_id']]);
                                    $policyCurrent = $policyCurrent && ($body['version'] === 6
                                        ? self::sameEvidence($freshGuard, $expected['source_guard']) : $freshGuard === $expected['source_guard']);
                                } else {
                                    $current = self::context($pair);
                                    $policyCurrent = $policyCurrent && $expected['values'] === $current['values']
                                        && $expected['metadata'] === $current['metadata'];
                                }
                            }
                        }
                    } catch (\Throwable) { $policyCurrent = false; }
                    if (($body['version'] ?? 0) >= 4 && !in_array($body['version'], $request['task_versions'] ?? [], true)) {
                        if ($task['queue_id'] !== null) {
                            self::write($DB->update(AssetSyncQueue::TABLE, ['last_error' => $body['version'] === 6
                                ? 'Both waiting: upgrade B to an agent supporting protocol v6 before enabling Both on A.' : ($body['version'] === 5
                                ? 'Creation waiting: upgrade B to agent 0.5.0 (protocol v5), then upgrade A.'
                                : 'Verification waiting: upgrade B to agent 0.4.0 (protocol v4), then upgrade A.')],
                                ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']));
                        }
                    } elseif (!$policyCurrent || ($task['state'] === 'executing' && (int) $task['expires'] < time())) {
                        // Keep the result empty so a late actual delivery can still be recorded.
                        $state = $task['state'] === 'executing' && in_array($body['kind'], ['create', 'apply'], true)
                            ? 'uncertain' : 'stale';
                        self::write($DB->update(self::TABLE, ['state' => $state], ['id' => $task['id']]));
                        self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']));
                    } else {
                        if ($task['state'] === 'accepted' && $task['result'] === '{}' && (int) $task['expires'] < time() + 300) {
                            // First delivery gets a full window; the published record stays immutable.
                            unset($body['hash']);
                            $body['id'] = bin2hex(random_bytes(16));
                            $body['expires'] = time() + 300;
                            $body['hash'] = self::hash($body);
                            self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $task['id'], 'slot' => $task['slot']]));
                            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('First delivery ownership changed.'); }
                            $replacement = array_replace($task, ['id' => $body['id'], 'hash' => $body['hash'],
                                'body' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => time(),
                                'expires' => $body['expires'], 'result_received_at' => null]);
                            self::write($DB->insert(self::TABLE, $replacement));
                            if ($body['kind'] === 'discover') {
                                self::write($DB->update(self::BINDINGS, ['discovery_id' => $body['id']],
                                    ['connection_id' => $binding['connection_id'], 'discovery_id' => $task['id']]));
                            }
                            $task = $replacement;
                        }
                        self::write($DB->update(self::TABLE, ['state' => 'executing'], ['id' => $task['id']]));
                        $response['task'] = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                    }
                }
                if (!$task && ($request['recovery_version'] ?? 0) === 1) {
                    foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['connection_id' => $binding['connection_id'],
                        'state' => 'stale', new \Glpi\DBAL\QueryExpression('`slot` IS NOT NULL')],
                        'ORDER' => new \Glpi\DBAL\QueryExpression("COALESCE(JSON_EXTRACT(`context`, '$.recovery_checked_at'), 0) ASC, created_at ASC, id ASC"), 'LIMIT' => 10]) as $candidate) {
                        $body = json_decode($candidate['body'], true, 32, JSON_THROW_ON_ERROR);
                        if (!self::readableApiTask($api, $candidate)) { continue; }
                        // Rotate every examined row, including ineligible older bindings and kinds.
                        $candidateContext = json_decode($candidate['context'], true, 32, JSON_THROW_ON_ERROR);
                        $candidateContext['recovery_checked_at'] = (int) (microtime(true) * 1_000_000);
                        self::write($DB->update(self::TABLE, ['context' => json_encode($candidateContext, JSON_THROW_ON_ERROR)],
                            ['id' => $candidate['id'], 'slot' => $candidate['slot'], 'state' => 'stale']));
                        if (($body['kind'] ?? '') !== 'apply' || !in_array($body['version'] ?? null, [1, 2, 3, 4, 6], true) || $candidate['queue_id'] === null
                            || isset($candidateContext['recovery_proof'])
                            || $body['registration'] !== $registration['id'] || $body['generation'] !== $registration['generation']
                            || $body['installation'] !== $binding['installation']) { continue; }
                        $response['recovery'] = $body;
                        break;
                    }
                }
            } elseif ($request['kind'] === 'recovery') {
                $saved = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote((string) ($request['id'] ?? '')) . ' FOR UPDATE');
                $proof = $request['proof'] ?? null;
                $proofKeys = ['version', 'observed_at', 'journal', 'result_hash'];
                if (is_array($proof) && ($proof['journal'] ?? '') === 'verified') { $proofKeys[] = 'result'; }
                if (!$saved || $saved['connection_id'] !== $binding['connection_id'] || ($request['hash'] ?? '') !== $saved['hash']
                    || !is_array($proof) || array_keys($proof) !== $proofKeys
                    || $proof['version'] !== 1 || !is_int($proof['observed_at'])
                    || $proof['observed_at'] < time() - 300 || $proof['observed_at'] > time() + 30
                    || !in_array($proof['journal'], ['absent', 'unchanged', 'stale', 'verified'], true)
                    || !is_string($proof['result_hash'])
                    || ($proof['journal'] === 'absent' ? $proof['result_hash'] !== '' : !preg_match('/^[a-f0-9]{64}$/D', $proof['result_hash']))) {
                    throw new \UnexpectedValueException('Invalid recovery proof.');
                }
                $body = json_decode($saved['body'], true, 32, JSON_THROW_ON_ERROR);
                if ($body['kind'] !== 'apply' || !in_array($body['version'], [1, 2, 3, 4, 6], true)
                    || ($proof['journal'] === 'absent' && ((int) $saved['expires'] >= time() || $proof['observed_at'] <= $body['expires']))
                    || $body['registration'] !== $registration['id'] || $body['generation'] !== $registration['generation']
                    || $body['installation'] !== $binding['installation']) { throw new \UnexpectedValueException('Recovery binding changed.'); }
                if ($proof['journal'] === 'verified') {
                    $writtenResult = $proof['result'];
                    if (!is_array($writtenResult) || ($writtenResult['outcome'] ?? '') !== 'verified'
                        || !is_array($writtenResult['changed'] ?? null) || $writtenResult['changed'] === []
                        || self::hash($writtenResult) !== $proof['result_hash']) { throw new \UnexpectedValueException('Invalid verified recovery result.'); }
                    foreach ($writtenResult['changed'] as $key => $historyId) {
                        if (!isset($body['changes'][$key]) || !is_int($historyId) || $historyId <= 0) { throw new \UnexpectedValueException('Invalid verified recovery History.'); }
                    }
                    try { self::validateResult($body, $writtenResult); }
                    catch (\RuntimeException $error) { throw new \UnexpectedValueException('Incomplete verified recovery evidence.', 0, $error); }
                }
                $context = json_decode($saved['context'], true, 32, JSON_THROW_ON_ERROR);
                if (isset($context['recovery_proof'])) {
                    $previousProof = $context['recovery_proof'];
                    unset($previousProof['observed_at']);
                    $replayedProof = $proof;
                    unset($replayedProof['observed_at']);
                    if ($previousProof !== $replayedProof) { throw new \UnexpectedValueException('Conflicting recovery replay.'); }
                } else {
                    $oldResult = json_decode($saved['result'], true, 32, JSON_THROW_ON_ERROR);
                    $sameVerifiedResult = $proof['journal'] === 'verified' && ($oldResult['outcome'] ?? '') === 'verified'
                        && self::hash($oldResult) === $proof['result_hash'];
                    if ($saved['result'] !== '{}'
                        && !$sameVerifiedResult
                        && (!in_array($oldResult['outcome'] ?? '', ['stale', 'unchanged'], true) || ($oldResult['changed'] ?? null) !== [])) {
                        throw new \UnexpectedValueException('Retained write evidence forbids recovery.');
                    }
                    $pair = self::taskPair($saved);
                    $current = $pair ? self::outstanding($binding['connection_id'], $pair) : null;
                    $job = AgentInbox::one('SELECT * FROM `' . AssetSyncQueue::TABLE . '` WHERE `id` = ' . (int) $saved['queue_id'] . ' FOR UPDATE');
                    if (!$pair || !$current || $current['id'] !== $saved['id'] || $saved['state'] !== 'stale' || !$job
                        || $job['itemtype'] !== $pair['itemtype'] || (int) $job['items_id'] !== (int) $pair['local_id']
                        || $job['glpi_b_connection_id'] !== $binding['connection_id'] || $job['status'] === 'running') {
                        throw new \UnexpectedValueException('Recovery task or queue ownership changed.');
                    }
                    $context['recovery_proof'] = $proof;
                    self::write($DB->update(self::TABLE, ['context' => json_encode($context, JSON_THROW_ON_ERROR)], ['id' => $saved['id'], 'slot' => $saved['slot'], 'state' => 'stale']));
                    self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $job['id'], 'status' => $job['status']]));
                }
                $response = ['status' => 200, 'recorded' => true, 'id' => $saved['id'], 'hash' => $saved['hash']];
            } else {
                $id = $request['id'] ?? '';
                $saved = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote((string) $id) . ' FOR UPDATE');
                $result = $request['result'] ?? null;
                if (!$saved || $saved['connection_id'] !== $binding['connection_id'] || ($request['hash'] ?? '') !== $saved['hash']
                    || !is_array($result) || !in_array($result['outcome'] ?? '', ['verified', 'unchanged', 'blocked', 'stale', 'partial', 'uncertain'], true)
                    || !is_array($result['changed'] ?? null)) { throw new \UnexpectedValueException('Invalid task result.'); }
                $body = json_decode($saved['body'], true, 32, JSON_THROW_ON_ERROR);
                if ($body['registration'] !== $registration['id'] || $body['generation'] !== $request['generation'] || $body['installation'] !== $request['installation']) { throw new \UnexpectedValueException('Stale result binding.'); }
                if ($saved['result'] === '{}') {
                    $pair = $body['kind'] === 'discover' || self::isMatch($body) ? null : self::taskPair($saved);
                    $task = self::isMatch($body) ? AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote((string) $saved['slot']))
                        : ($pair ? self::outstanding($binding['connection_id'], $pair) : self::discoveryTask($binding['connection_id']));
                    if (!$task || $task['id'] !== $saved['id']) { throw new \UnexpectedValueException('No matching outstanding task.'); }
                    try { self::validateResult($body, $result); }
                    catch (\RuntimeException $error) { throw new \UnexpectedValueException('Invalid task evidence.', 0, $error); }
                    self::write($DB->update(self::TABLE, ['result_received_at' => time()], ['id' => $saved['id']]));
                    self::storeResult($saved, $result, $body['version'] === 5 || (int) $saved['expires'] >= time());
                } elseif (self::hash(json_decode($saved['result'], true)) !== self::hash($result)) {
                    throw new \UnexpectedValueException('Conflicting result replay.');
                }
                $response = ['status' => 200, 'recorded' => true, 'id' => $id, 'hash' => $saved['hash']];
            }
            if ($request['kind'] === 'pull') {
                $budget = 65536 - strlen(json_encode($response, JSON_THROW_ON_ERROR)) - 32;
                if ($budget >= 512) { $response['subscriptions'] = self::subscriptions($binding, $registration, $budget, $api); }
            }
            if (strlen(json_encode($response, JSON_THROW_ON_ERROR)) > 65536) { throw new \RuntimeException('Task response exceeds transport budget.'); }
            AgentInbox::sql('COMMIT');
            return $response;
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    /** The caller holds the binding lock. Lost pages replay fixed A-issued expiries on a later pass. */
    private static function readableApiTask(?AgentApi $api, array $task): bool
    {
        if ($api === null) { return true; }
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        if ($body['kind'] === 'discover') { return true; }
        $pair = self::isMatch($body) ? null : self::taskPair($task);
        $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
        $localId = (int) ($pair['local_id'] ?? $context['local_id'] ?? 0);
        return $api->activateReadAsset($body['itemtype'] ?? 'Computer', $localId);
    }

    private static function subscriptions(array $binding, array $registration, int $budget, ?AgentApi $api = null): array
    {
        global $DB;
        $page = ['installation' => $binding['installation'], 'registration' => $registration['id'],
            'generation' => $registration['generation'], 'connection_id' => $binding['connection_id'], 'updates' => []];
        $cursor = $binding['subscription_cursor'];
        $revision = (int) $binding['subscription_revision'];
        $count = 0;
        $full = false;
        foreach ($DB->request(['FROM' => self::PAIRS, 'WHERE' => ['connection_id' => $binding['connection_id'],
            'id' => ['>', $cursor]], 'ORDER' => 'id ASC', 'LIMIT' => 50]) as $pair) {
            try { self::scope($pair); $active = $api === null || $api->activateReadAsset($pair['itemtype'], (int) $pair['local_id']); }
            catch (\Throwable) { $active = false; }
            if (!$active && (int) $pair['subscription_revision'] === 0) {
                $cursor = $pair['id'];
                $count++;
                continue;
            }
            $issue = (int) $pair['subscription_revision'] === 0 || (bool) $pair['subscription_active'] !== $active
                || $pair['subscription_generation'] !== $registration['generation']
                || ($active && (int) $pair['subscription_issued'] <= time() - 600);
            $issued = $issue ? time() : (int) $pair['subscription_issued'];
            $update = ['pair_id' => $pair['id'], 'itemtype' => $pair['itemtype'], 'items_id' => (int) $pair['computer_id'],
                'entity_id' => (int) $pair['entity_id'], 'revision' => $issue ? $revision + 1 : (int) $pair['subscription_revision'],
                'issued_at' => $issued, 'expires' => $issued + 900, 'active' => $active];
            $page['updates'][] = $update;
            if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > $budget) { array_pop($page['updates']); $full = true; break; }
            if ($issue) {
                $revision++;
                self::write($DB->update(self::PAIRS, ['subscription_revision' => $revision, 'subscription_issued' => $issued,
                    'subscription_active' => (int) $active, 'subscription_generation' => $registration['generation']], ['id' => $pair['id']]));
            }
            $cursor = $pair['id'];
            $count++;
        }
        if (!$full && $count < 50) { $cursor = ''; }
        self::write($DB->update(self::BINDINGS, ['subscription_cursor' => $cursor, 'subscription_revision' => $revision], ['connection_id' => $binding['connection_id']]));
        $api?->restoreReadScope();
        return $page;
    }

    public static function unconfirm(string $connection, string $pairId): void
    {
        global $DB;
        $pair = self::byId($connection, $pairId);
        if (!$pair || !self::model($pair['itemtype'])->can((int) $pair['local_id'], READ)
            || !\Session::haveAccessToEntity((int) $pair['local_entity'])) { throw new \RuntimeException('Pair unavailable.'); }
        self::write($DB->update(self::PAIRS, ['confirmed' => 0], ['id' => $pair['id']]));
    }

    private static function validateResult(array $task, array $result): void
    {
        if (!in_array($task['version'] ?? null, [1, 2, 3, 4, 5, 6], true)) {
            throw new \RuntimeException('Unsupported task result version.');
        }
        foreach (['snapshot', 'evidence', 'discovery'] as $key) {
            if (isset($result[$key]['observed_at']) && $result[$key]['observed_at'] > time()) {
                throw new \RuntimeException('Observation is in the future.');
            }
        }
        if ($task['version'] === 5) {
            self::validateCreationResult($task, $result);
            return;
        }
        if (self::isMatch($task)) {
            if ($result['changed'] !== []) { throw new \RuntimeException('Matching cannot write assets.'); }
            if (in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                self::chooseMatch($task['identity'], $result['evidence'] ?? []);
                if ($result['evidence']['observed_at'] < $task['expires'] - 300 || $result['evidence']['observed_at'] > $task['expires']) {
                    throw new \RuntimeException('Matching evidence is outside the task window.');
                }
                if ($task['kind'] === 'reverify') {
                    $snapshotTask = $task;
                    $snapshotTask['kind'] = 'inspect';
                    $snapshotTask['identity'] = self::identity($result['snapshot'] ?? []);
                    $snapshotTask['serial'] = $snapshotTask['identity']['serial'];
                    self::validateResult($snapshotTask, $result);
                }
            }
            return;
        }
        if (!in_array($result['outcome'], ['verified', 'unchanged'], true)) { return; }
        if ($task['kind'] === 'discover') { self::validateDiscovery($task, $result); return; }
        $snapshot = $result['snapshot'] ?? [];
        $type = $task['itemtype'] ?? 'Computer';
        if (($snapshot['items_id'] ?? $snapshot['computer_id'] ?? null) !== ($task['items_id'] ?? $task['computer_id']) || ($snapshot['entity_id'] ?? null) !== $task['entity_id']
            || ($snapshot['itemtype'] ?? 'Computer') !== $type
            || ($task['version'] >= 3 && ($snapshot['pair_id'] ?? '') !== $task['pair_id'])
            || !is_string($snapshot['serial'] ?? null) || ($task['version'] < 4 && $snapshot['serial'] === '')
            || ($task['version'] === 4 && self::identity($snapshot) !== $task['identity'])
            || ($task['version'] === 6 && ($task['identity'] !== null || $task['kind'] !== 'inspect') && self::identity($snapshot) !== $task['identity'])
            || ($task['serial'] !== '' && $snapshot['serial'] !== $task['serial'])
            || !is_int($snapshot['observed_at'] ?? null) || $snapshot['observed_at'] > $task['expires']
            || $snapshot['observed_at'] < $task['expires'] - 300
            || !is_array($snapshot['fields'] ?? null) || count($snapshot['fields']) > 32) { throw new \RuntimeException('Invalid independent snapshot.'); }
        foreach ($snapshot['fields'] as $key => $field) {
            $types = $task['version'] === 1 ? ['text'] : ['text', 'yesno', 'date'];
            if (!is_array($field) || ($field['key'] ?? '') !== $key || !in_array($field['type'] ?? '', $types, true)
                || !array_key_exists('value', $field) || self::normalizeValue($field['type'], $field['value']) !== $field['value']
                || (is_string($field['value']) && strlen($field['value']) > 16384)
                || (in_array($key, ['name', 'comment'], true) && $field['type'] !== 'text')
                || !is_string($field['label'] ?? null) || !is_int($field['option'] ?? null) || $field['option'] <= 0
                || !preg_match('/^[a-f0-9]{64}$/D', $field['metadata'] ?? '')
                || (!in_array($key, ['name', 'comment'], true) && !preg_match('/^' . preg_quote($type, '/') . '\.PluginFields[A-Za-z0-9]+\.[a-z][a-z0-9_]*$/D', $key))) {
                throw new \RuntimeException('Invalid scoped metadata.');
            }
        }
        if ($task['version'] === 6) {
            self::identity($snapshot);
            if (!array_key_exists('origin_epoch', $snapshot) || !is_array($snapshot['modification'] ?? null)
                || !is_array($snapshot['native_values'] ?? null) || array_keys($snapshot['native_values']) !== ['name', 'comment']) {
                throw new \RuntimeException('V6 modification evidence missing.');
            }
            foreach ([$snapshot['modification']['epoch'] ?? null, $snapshot['origin_epoch']] as $epoch) {
                if ($epoch !== null && (!is_int($epoch) || $epoch <= 0 || $epoch > time() + 30)) { throw new \RuntimeException('Invalid native epoch.'); }
            }
            $mod = $snapshot['modification'];
            if (array_keys($mod) !== ['epoch', 'untrusted_date_mod', 'history'] || ($mod['untrusted_date_mod'] !== null && !is_string($mod['untrusted_date_mod']))
                || !is_array($mod['history']) || array_keys($mod['history']) !== ['id', 'epoch', 'field']
                || !is_int($mod['history']['id']) || $mod['history']['id'] < 0 || !in_array($mod['history']['field'], ['', 'name', 'comment'], true)
                || ($mod['history']['epoch'] !== null && (!is_int($mod['history']['epoch']) || $mod['history']['epoch'] <= 0 || $mod['history']['epoch'] > time() + 30))) {
                throw new \RuntimeException('Invalid native History anchor.');
            }
            foreach (['name', 'comment'] as $key) {
                if ($snapshot['native_values'][$key] !== ($snapshot['fields'][$key]['value'] ?? null)) { throw new \RuntimeException('Native atomic read mismatch.'); }
            }
            if ($task['kind'] === 'apply' && (($result['mutation']['modification'] ?? null) !== $snapshot['modification']
                || ($result['mutation']['native_values'] ?? null) !== $snapshot['native_values']
                || ($result['mutation']['origin_epoch'] ?? null) !== $task['guard']['origin_epoch'])) { throw new \RuntimeException('Post-write provenance missing.'); }
        }
        foreach ($task['changes'] as $key => $change) {
            if (($snapshot['fields'][$key]['value'] ?? null) !== $change['value']
                || ($snapshot['fields'][$key]['metadata'] ?? '') !== $change['metadata']
                || ($snapshot['fields'][$key]['type'] ?? '') !== ($change['type'] ?? 'text')
                || ($change['expected'] !== $change['value'] && !is_int($result['changed'][$key] ?? null))) {
                throw new \RuntimeException('Result lacks readback or named history evidence.');
            }
        }
    }

    private static function validateCreationResult(array $task, array $result): void
    {
        if ($result['changed'] !== []) { throw new \RuntimeException('Creation uses separate creation History evidence.'); }
        if (isset($result['attempted_id']) && ($task['kind'] !== 'create' || $result['outcome'] !== 'uncertain'
            || !is_int($result['attempted_id']) || $result['attempted_id'] <= 0)) {
            throw new \RuntimeException('Invalid uncertain creation identity.');
        }
        if (isset($result['evidence']) && (!is_int($result['evidence']['observed_at'] ?? null)
            || $result['evidence']['observed_at'] < $task['expires'] - 300 || $result['evidence']['observed_at'] > $task['expires'])) {
            throw new \RuntimeException('Creation evidence is outside the task window.');
        }
        if (($result['existing'] ?? false) === true) {
            if (!in_array($task['kind'], ['prepare_create', 'create'], true) || $result['outcome'] !== 'stale'
                || isset($result['created_id'])) { throw new \RuntimeException('Invalid precreation match.'); }
            self::chooseMatch($task['identity'], $result['evidence'] ?? []);
            return;
        }
        if (!in_array($result['outcome'], ['verified', 'unchanged'], true)) { return; }
        if ($task['kind'] === 'prepare_create') {
            if (!preg_match('/^[a-f0-9]{64}$/D', $result['prerequisites'] ?? '')
                || !(self::chooseMatch($task['identity'], $result['evidence'] ?? [])['no_match'] ?? false)) {
                throw new \RuntimeException('Incomplete creation prerequisites.');
            }
            return;
        }
        $snapshotTask = $task;
        $snapshotTask['version'] = 4;
        $snapshotTask['kind'] = $task['kind'] === 'confirm_create' ? 'reverify' : 'inspect';
        if ($task['kind'] === 'create') {
            if ($result['outcome'] !== 'verified' || !is_int($result['created_id'] ?? null) || $result['created_id'] <= 0
                || !is_int($result['creation_history'] ?? null) || $result['creation_history'] <= 0
                || !is_array($result['field_history'] ?? null)) { throw new \RuntimeException('Creation identity or audit evidence missing.'); }
            $snapshotTask['items_id'] = $result['created_id'];
        }
        self::validateResult($snapshotTask, $result);
        if ($task['kind'] === 'create') {
            foreach ($task['seed'] as $key => $seed) {
                if (($result['snapshot']['fields'][$key]['value'] ?? null) !== $seed['value']
                    || ($result['snapshot']['fields'][$key]['type'] ?? '') !== $seed['type']) {
                    throw new \RuntimeException('Created seed readback mismatch.');
                }
                if (!in_array($key, ['name', 'comment'], true) && !empty($seed['value'])
                    && (!is_int($result['field_history'][$key] ?? null) || $result['field_history'][$key] <= 0)) {
                    throw new \RuntimeException('Created custom field History missing.');
                }
            }
        }
    }

    /** Agent scalars are stricter than the shared writer; blank reads remain distinct from zero. */
    public static function normalizeValue(string $type, mixed $value, bool $authoritative = false): string|int
    {
        if (!in_array($type, ['text', 'yesno', 'date'], true)) {
            throw new \RuntimeException('Unsupported agent scalar type.');
        }
        if ($type === 'yesno' && !in_array($value, [0, 1, '0', '1'], true)
            && ($authoritative || !in_array($value, [null, ''], true))) {
            throw new \RuntimeException('Agent yesno requires 0 or 1; blank authority is unsupported.');
        }
        if ($type === 'date' && is_string($value) && str_starts_with($value, '0000')) {
            throw new \RuntimeException('Agent date requires a valid YYYY-MM-DD.');
        }
        return FieldsText::normalizeReadValue($type, $value);
    }

    private static function storeResult(array $task, array $result, bool $fresh = true): void
    {
        global $DB;
        self::write($DB->update(self::TABLE, ['result' => json_encode($result, JSON_THROW_ON_ERROR),
            'state' => !$fresh && in_array($result['outcome'], ['verified', 'unchanged'], true) ? 'stale' : $result['outcome']], ['id' => $task['id']]));
        if ($fresh && isset($result['discovery']) && in_array($result['outcome'], ['verified', 'unchanged'], true)) {
            self::write($DB->update(self::BINDINGS, ['discovery_observed_id' => $task['id']], ['connection_id' => $task['connection_id']]));
        }
        if ($task['queue_id'] !== null) {
            self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']));
        } else {
            if ($fresh && isset($result['snapshot']) && in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                self::write($DB->update(self::PAIRS, ['snapshot' => json_encode($result['snapshot'], JSON_THROW_ON_ERROR),
                    'observed_at' => min((int) $result['snapshot']['observed_at'], time(), (int) $task['expires'])], ['id' => self::taskPair($task)['id']]));
            }
            if (!in_array($result['outcome'], ['partial', 'uncertain'], true)) {
                self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $task['id']]));
                $pair = self::taskPair($task);
                if ($fresh && $pair && isset($result['snapshot']) && in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                    self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['itemtype' => $pair['itemtype'],
                        'items_id' => (int) $pair['local_id'], 'glpi_b_connection_id' => $pair['connection_id'], 'status' => 'waiting_agent']));
                }
            }
        }
    }

    /** Receipt proves timely delivery; observations still have only a five-minute useful life. */
    private static function timelyResult(array $task): bool
    {
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        $received = $task['result_received_at'] ?? null;
        $now = time();
        if ($received === null || (int) $received < $body['expires'] - 300
            || (int) $received > $body['expires'] || (int) $received > $now) { return false; }
        $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
        foreach (['snapshot', 'evidence', 'discovery'] as $key) {
            if (!isset($result[$key])) { continue; }
            $observed = $result[$key]['observed_at'] ?? null;
            if (!is_int($observed) || $observed < $body['expires'] - 300 || $observed > $body['expires']
                || $observed > (int) $received || $observed > $now || $observed < $now - 300) { return false; }
        }
        return true;
    }

    public static function write(bool $ok): void
    {
        if (!$ok) { throw new \RuntimeException('Agent task storage write failed.'); }
    }

    public static function context(array $pair): array
    {
        $scope = self::scope($pair);
        $context = self::mappingContext($pair, $scope['asset'], self::policy($pair));
        if (in_array('both', array_column($context['mappings'], 'source_of_truth'), true)) {
            if (empty($pair['confirmed'])) { throw new \RuntimeException('Both requires an existing independently confirmed pair.'); }
            $native = self::nativeEvidence($pair);
            $context['identity'] = self::identity($native['asset']);
            $context['modification'] = $native['modification'];
            $context['native_values'] = $native['native_values'];
            foreach ($context['values'] as $key => $value) {
                if (isset($native['native_values'][$key])) { $context['values'][$key] = $native['native_values'][$key]; }
            }
            $context['origin_epoch'] = self::localOrigin($pair, $native);
        }
        return $context;
    }

    private static function nativeEvidence(array $pair): array
    {
        global $DB;
        $table = self::model($pair['itemtype'])::getTable();
        $schema = AgentInbox::one('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $DB->quote($table) . ' AND COLUMN_NAME = \'date_mod\'');
        $row = AgentInbox::one('SELECT *, UNIX_TIMESTAMP(`date_mod`) AS epoch, (SELECT COALESCE(MAX(`id`),0) FROM `glpi_logs` WHERE `itemtype` = '
            . $DB->quote($pair['itemtype']) . ' AND `items_id` = ' . (int) $pair['local_id'] . ') AS history_id FROM `' . $table . '` WHERE `id` = ' . (int) $pair['local_id']);
        if (!$row || (int) $row['entities_id'] !== (int) $pair['local_entity'] || !empty($row['is_deleted']) || !empty($row['is_template'])) {
            throw new \RuntimeException('Native comparison target changed.');
        }
        $epoch = strtolower((string) ($schema['DATA_TYPE'] ?? '')) === 'timestamp' && is_numeric($row['epoch'])
            && (int) $row['epoch'] > 0 && (int) $row['epoch'] <= time() + 30 ? (int) $row['epoch'] : null;
        $log = AgentInbox::one('SELECT *, UNIX_TIMESTAMP(`date_mod`) AS epoch FROM `glpi_logs` WHERE `id` = ' . (int) $row['history_id']);
        $field = '';
        $options = \Glpi\Search\SearchOption::getOptionsForItemtype($pair['itemtype']);
        $dateOptions = array_filter($options, static fn ($option): bool => is_array($option) && ($option['table'] ?? '') === $table && ($option['field'] ?? '') === 'date_mod');
        if ($log && $epoch !== null && count($dateOptions) === 1 && (int) $log['id_search_option'] === (int) array_key_first($dateOptions)
            && $log['itemtype_link'] === '' && (int) $log['linked_action'] === 0 && (int) $log['epoch'] === $epoch
            && self::nativeTimestampLog($log, $epoch)) {
            // GLPI may append Last update after the real field. Skip only that proven audit row.
            $previous = AgentInbox::one('SELECT *, UNIX_TIMESTAMP(`date_mod`) AS epoch FROM `glpi_logs` WHERE `itemtype` = '
                . $DB->quote($pair['itemtype']) . ' AND `items_id` = ' . (int) $pair['local_id'] . ' AND `id` < ' . (int) $row['history_id'] . ' ORDER BY `id` DESC LIMIT 1');
            if ($previous && (int) $previous['epoch'] === $epoch && $previous['user_name'] === $log['user_name']) { $log = $previous; }
        }
        foreach (['name', 'comment'] as $key) {
            $matches = array_filter($options, static fn ($option): bool => is_array($option) && ($option['table'] ?? '') === $table && ($option['field'] ?? '') === $key);
            if ($log && count($matches) === 1 && (int) $log['id_search_option'] === (int) array_key_first($matches) && $log['itemtype_link'] === ''
                && (int) $log['linked_action'] === 0 && $log['new_value'] === self::nativeHistoryValue((string) $row[$key])) { $field = $key; }
        }
        return ['asset' => $row, 'history_row' => $log, 'native_values' => ['name' => (string) $row['name'], 'comment' => (string) $row['comment']],
            'modification' => ['epoch' => $epoch, 'untrusted_date_mod' => $epoch === null ? $row['date_mod'] : null, 'history' => ['id' => (int) $row['history_id'],
                'epoch' => is_numeric($log['epoch'] ?? null) && (int) $log['epoch'] > 0 && (int) $log['epoch'] <= time() + 30 ? (int) $log['epoch'] : null, 'field' => $field]]];
    }

    private static function nativeHistoryValue(string $value): string
    {
        $decoded = json_decode($value);
        return is_array($decoded) || is_object($decoded) ? '' : mb_substr($value, 0, 255);
    }

    private static function nativeTimestampLog(array $log, int $epoch): bool
    {
        if (!preg_match('/\(([1-9][0-9]*)\)$/', $log['user_name'], $match)
            || \User::getNameForLog((int) $match[1]) !== $log['user_name']) { return false; }
        $user = new \User();
        if (!$user->getFromDB((int) $match[1])) { return false; }
        $zone = $user->fields['timezone'] ?? (\Config::getConfigurationValues('core')['timezone'] ?? '');
        if ($zone === '' || $zone === '0') { return false; }
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $log['new_value'], new \DateTimeZone($zone));
            return $date !== false && $date->format('Y-m-d H:i:s') === $log['new_value'] && $date->getTimestamp() === $epoch
                && $log['old_value'] !== $log['new_value'];
        } catch (\Exception) { return false; }
    }

    private static function legacyOwnMutation(array $pair, array $native): bool
    {
        global $DB;
        $log = $native['history_row'];
        if (!$log || self::ownHistoryAuthor($log['user_name'])) { return true; }
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['connection_id' => $pair['connection_id'],
            new \Glpi\DBAL\QueryExpression('`queue_id` IS NOT NULL AND JSON_EXTRACT(`body`, \'$.version\') IN (1,2,3,4,5) AND '
                . '(JSON_UNQUOTE(JSON_EXTRACT(`body`, \'$.pair_id\')) = ' . $DB->quote($pair['id'])
                . ' OR (JSON_EXTRACT(`body`, \'$.version\') IN (1,2) AND JSON_EXTRACT(`body`, \'$.computer_id\') = ' . (int) $pair['computer_id'] . '))')], 'ORDER' => 'created_at DESC,id DESC', 'LIMIT' => 32]);
        foreach ($rows as $task) {
            $target = self::taskPair($task);
            if (!$target || (int) $target['local_id'] !== (int) $pair['local_id'] || $target['itemtype'] !== $pair['itemtype']) { continue; }
            $context = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            // A completed no-op, or an undelivered replaced task, cannot explain an A mutation.
            if ($task['state'] === 'unchanged' || ($task['state'] === 'accepted' && $task['slot'] === null && $task['result'] === '{}')) { continue; }
            foreach ($context['mappings'] ?? [] as $mapping) {
                if ($mapping['source_of_truth'] !== 'glpi_b' || $mapping['glpi_a_field'] !== $native['modification']['history']['field']) { continue; }
                $before = $context['values'][$mapping['glpi_a_field']] ?? null;
                $after = $result['snapshot']['fields'][$mapping['glpi_b_field']]['value'] ?? null;
                if (is_string($before) && is_string($after) && $before !== $after && $after === $native['native_values'][$mapping['glpi_a_field']]
                    && $log['old_value'] === self::nativeHistoryValue($before) && $log['new_value'] === self::nativeHistoryValue($after)) { return true; }
            }
            // An unanchored write in a retained operation's window cannot be called human.
            $epoch = $native['modification']['epoch'];
            if ($epoch !== null && $epoch >= (int) $task['created_at'] && $epoch <= (int) $task['expires'] + 300) { return true; }
        }
        return false;
    }

    private static function ownHistoryAuthor(string $author): bool
    {
        if ($author === '' || $author === \User::getNameForLog(0) || $author === 'cron_' . AssetSyncCron::TASK_NAME) { return true; }
        if (\Session::isCron()) {
            $id = \Session::getLoginUserID(false);
            return $author === (is_numeric($id) ? \User::getNameForLog((int) $id) : (string) $id);
        }
        return false;
    }

    private static function localOrigin(array $pair, array $native): ?int
    {
        global $DB;
        $anchors = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['connection_id' => $pair['connection_id'],
            new \Glpi\DBAL\QueryExpression('JSON_UNQUOTE(JSON_EXTRACT(`body`, \'$.pair_id\')) = ' . $DB->quote($pair['id'])
                . ' AND (JSON_EXTRACT(`context`, \'$.local_mutation\') IS NOT NULL OR JSON_EXTRACT(`context`, \'$.field_conflict\') IS NOT NULL)')], 'ORDER' => 'created_at DESC,id DESC', 'LIMIT' => 32]);
        $seen = false;
        $latestHistory = 0;
        foreach ($anchors as $task) {
            $stored = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $anchor = $stored['local_mutation'] ?? $stored;
            $seen = true;
            $latestHistory = max($latestHistory, $anchor['modification']['history']['id']);
            if (self::sameEvidence($anchor['modification'], $native['modification']) && self::sameEvidence($anchor['native_values'], $native['native_values'])) { return $anchor['origin_epoch']; }
            // Older false conflicts did not recognize GLPI's trailing timestamp audit. Re-prove
            // that exact generation once; do not invent a fresh edit or rewrite its ledger entry.
            $corrected = $anchor['modification'];
            $corrected['history']['field'] = $native['modification']['history']['field'];
            if (isset($stored['field_conflict']) && $anchor['origin_epoch'] === null && $anchor['modification']['history']['field'] === ''
                && $native['modification']['epoch'] !== null && $native['modification']['history']['epoch'] === $native['modification']['epoch']
                && $native['modification']['history']['field'] !== '' && self::sameEvidence($corrected, $native['modification'])
                && self::sameEvidence($anchor['native_values'], $native['native_values'])
                && !self::ownHistoryAuthor($native['history_row']['user_name']) && !self::legacyOwnMutation($pair, $native)) {
                return $native['modification']['epoch'];
            }
        }
        $mod = $native['modification'];
        $author = $native['history_row']['user_name'] ?? '';
        if ($mod['epoch'] !== null && $mod['history']['epoch'] === $mod['epoch'] && $mod['history']['field'] !== ''
            && !self::ownHistoryAuthor($author)
            && ($seen ? $mod['history']['id'] > $latestHistory : !self::legacyOwnMutation($pair, $native))) { return $mod['epoch']; }
        return null;
    }

    private static function sameLocalContext(array $expected, array $current): bool
    {
        foreach (['policy', 'values', 'metadata', 'identity', 'modification', 'native_values', 'origin_epoch'] as $key) {
            if (array_key_exists($key, $expected) && !self::sameEvidence($current[$key] ?? null, $expected[$key])) { return false; }
        }
        return true;
    }

    /** JSON objects are unordered; list order and scalar types remain part of the guard. */
    private static function sameEvidence(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) { return $left === $right; }
        if (count($left) !== count($right) || array_is_list($left) !== array_is_list($right)) { return false; }
        if (array_is_list($left) && array_keys($left) !== array_keys($right)) { return false; }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::sameEvidence($value, $right[$key])) { return false; }
        }
        return true;
    }

    private static function bothDirection(array $context, array $snapshot, string $key): string
    {
        if ($context['values'][$key] === $snapshot['fields'][$key]['value']) { return 'equal'; }
        $aRaw = $context['modification']['epoch'];
        $bRaw = $snapshot['modification']['epoch'];
        $a = $context['origin_epoch'];
        $b = $snapshot['origin_epoch'];
        // Unknown provenance supplies an upper bound only; it never wins a difference.
        if ($aRaw !== null && $bRaw !== null) {
            if ($a !== null && $a > ($b ?? $bRaw)) { return 'glpi_a'; }
            if ($b !== null && $b > ($a ?? $aRaw)) { return 'glpi_b'; }
        }
        throw new \RuntimeException('blocked_field_conflict: ' . $key . ' differs without a strictly newer reliable native asset instant.');
    }

    private static function mappingContext(array $pair, array $asset, string $policy): array
    {
        global $DB;
        // Agent results may arrive in a later profile or worker pass; metadata must be fresh.
        FieldMapping::endRunCache();
        FieldsText::endRunCache();
        $type = $pair['itemtype'];
        \Glpi\Search\SearchOption::clearSearchOptionCache($type);
        $rows = FieldMapping::load($pair['connection_id'], $type);
        self::validateMappings($type, $rows);
        $mappings = FieldMapping::syncMappings($pair['connection_id'], $type, $asset);
        if ($mappings === []) { throw new \RuntimeException('No supported agent mappings.'); }
        $values = [];
        $metadata = [];
        $types = [];
        foreach ($mappings as $mapping) {
            $key = $mapping['glpi_a_field'];
            if (isset($values[$key])) { continue; }
            if (in_array($key, ['name', 'comment'], true)) {
                if (!array_key_exists($key, $asset)) { throw new \RuntimeException('Local native field is unavailable.'); }
                $types[$key] = 'text';
                $values[$key] = (string) $asset[$key];
                $metadata[$key] = NativeField::local($type, $key);
            } else {
                $meta = FieldsText::customMetadata($type, [$key])[$key];
                if (!in_array($meta['type'], ['text', 'yesno', 'date'], true)) { throw new \RuntimeException('Agent custom mappings require text, yesno or date fields.'); }
                $types[$key] = $meta['type'];
                $container = new \PluginFieldsContainer();
                $definition = new \PluginFieldsField();
                if (!$definition->getFromDB($meta['field_id'])) { throw new \RuntimeException('Local Fields definition is unavailable.'); }
                $meta['container_id'] = (int) $definition->fields['plugin_fields_containers_id'];
                if (!$container->getFromDB($meta['container_id']) || !$definition->fields['is_active'] || $definition->fields['type'] !== $meta['type']
                    || $definition->fields['name'] !== $meta['field']
                    || !$container->fields['is_active'] || $container->fields['is_recursive']
                    || (int) $container->fields['entities_id'] !== (int) $pair['local_entity']) {
                    throw new \RuntimeException('Local Fields scope is unsupported.');
                }
                $children = iterator_to_array($DB->request(['FROM' => $meta['table'], 'WHERE' => [
                    'items_id' => (int) $pair['local_id']], 'LIMIT' => 2]));
                if (count($children) !== 1) { throw new \RuntimeException('Agent mode requires exactly one existing local custom row.'); }
                $child = array_values($children)[0];
                if (($child['itemtype'] ?? '') !== $type || (int) ($child['plugin_fields_containers_id'] ?? 0) !== $meta['container_id']) {
                    throw new \RuntimeException('Local custom row identity changed.');
                }
                if (!array_key_exists($meta['field'], $child)) { throw new \RuntimeException('Local Fields column is unavailable.'); }
                $values[$key] = self::normalizeValue($meta['type'], $child[$meta['field']]);
                $metadata[$key] = self::hash([$meta, $container->fields, $definition->fields, (int) $child['id']]);
            }
        }
        $context = ['policy' => $policy, 'metadata' => self::hash($metadata), 'values' => $values, 'mappings' => $mappings, 'types' => $types];
        if (!empty($pair['identity_basis'])) { $context['identity'] = self::identity($asset); }
        return $context;
    }

    public static function policy(array $pair): string
    {
        FieldMapping::endRunCache();
        $scope = self::scope($pair);
        return self::hash([FieldMapping::load($pair['connection_id'], $pair['itemtype']), $scope['route'],
            $scope['registration']['generation'], $pair['installation'], $scope['registration']['connection_identity'],
            \Config::getConfigurationValues('plugin:assetsync20', ['billing_fields'])]);
    }

    public static function matchTask(string $connection, string $type, int $id): ?array
    {
        global $DB;
        return AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote('match:' . self::hash([$connection, $type, $id])));
    }

    private static function sameMatchScope(array $fresh, array $expected): bool
    {
        // Only the chosen identifier's count controls confirmation. Unrelated inventory
        // lookup errors or duplicates must not veto a unique serial.
        unset($fresh['local_counts'], $expected['local_counts']);
        return $fresh === $expected;
    }

    /** No discovery pages or permission filtering: two exact rows already prove ambiguity. */
    private static function localIdentityCount(string $type, int $entity, string $column, string $value, int $deadline): int
    {
        global $DB;
        $item = self::model($type);
        $result = self::identityQuery('SELECT `id` FROM `' . $item::getTable() . '` WHERE `entities_id` = ' . $entity
            . ' AND `is_deleted` = 0 AND `is_template` = 0 AND CAST(TRIM(`' . $column . '`) AS BINARY) = CAST('
            . $DB->quote($value) . ' AS BINARY) LIMIT 2', $deadline);
        $count = 0;
        while ($DB->fetchAssoc($result)) { ++$count; }
        return $count;
    }

    /** Per-statement limits do not alter pooled/session settings or lock other assets. */
    private static function identityQuery(string $sql, int $deadline): mixed
    {
        $version = (string) (AgentInbox::one('SELECT VERSION() AS version')['version'] ?? '');
        $milliseconds = min(1000, (int) (($deadline - hrtime(true)) / 1_000_000));
        if ($milliseconds < 1) { throw new \RuntimeException('Local identity read budget exhausted.'); }
        if (stripos($version, 'MariaDB') !== false && preg_match('/(\d+)\.(\d+)\.\d+-MariaDB/i', $version, $parts)
            && ((int) $parts[1] > 10 || ((int) $parts[1] === 10 && (int) $parts[2] >= 6))) {
            return AgentInbox::sql('SET STATEMENT max_statement_time=' . sprintf('%.3F', $milliseconds / 1000) . ' FOR ' . $sql);
        }
        if (stripos($version, 'MariaDB') === false && preg_match('/^(8|9)\./', $version)) {
            return AgentInbox::sql('SELECT /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */ ' . substr($sql, 7));
        }
        throw new \RuntimeException('Automatic matching requires MySQL 8/9 or MariaDB 10.6+ statement time limits.');
    }

    public static function matchContext(string $connection, string $type, int $id, bool $checkDuplicates = true, bool $lock = false): array
    {
        $binding = self::binding($connection);
        if (!$binding) { throw new \RuntimeException('Automatic matching needs an active B registration.'); }
        $registration = self::registration($binding);
        $item = self::model($type);
        if ($lock) {
            $item->fields = AgentInbox::one('SELECT * FROM `' . $item::getTable() . '` WHERE `id` = ' . $id . ' FOR UPDATE') ?? [];
        } else {
            $item->getFromDB($id);
        }
        if ($item->fields === [] || !array_key_exists('entities_id', $item->fields)
            || !array_key_exists('is_deleted', $item->fields) || !array_key_exists('is_template', $item->fields)
            || $item->fields['is_deleted'] || $item->fields['is_template']) {
            throw new \RuntimeException('Automatic matching local asset unavailable.');
        }
        if (self::pair($connection, $type, $id) || AssetSyncLink::find($type, $id, $connection)) {
            throw new \RuntimeException('Existing pair or link retained; automatic matching skipped.');
        }
        EntitySyncRoute::loadAll(true);
        $match = EntitySyncRoute::matchAsset($type, (string) $item->fields['entities_id'], [], $connection);
        $route = $match['matches'][0]['route'] ?? null;
        if (!$route || count($match['matches']) !== 1 || $match['conflicts'] !== [] || !$route['active']
            || $route['include_child_entities'] || $route['asset_types'] !== [$type]
            || $route['glpi_b_target_entity_id'] === '' || $route['glpi_a_source_entity_id'] === ''
            || (int) $route['glpi_a_source_entity_id'] !== (int) $item->fields['entities_id']) {
            throw new \RuntimeException('Automatic matching requires one exact active single-type route.');
        }
        // Enrollment proves only scope. Identity/metadata failures must reach the queue
        // so eligible assets have a visible failure and retain the normal retry clock.
        if (!$checkDuplicates) { return []; }
        $approved = json_decode($registration['routes'], true, 32, JSON_THROW_ON_ERROR);
        if (isset($approved[$route['id']]) && $approved[$route['id']] !== [
            'fingerprint' => AgentInbox::routeIdentity($route), 'source_entity' => (int) $item->fields['entities_id']]) {
            throw new \RuntimeException('Retained route approval differs; automatic matching blocked.');
        }
        // Like scope(), controller reads use the authenticated binding and A's route,
        // not an interactive session (the B bearer endpoint has no A login).
        FieldMapping::endRunCache();
        self::validateMappings($type, FieldMapping::load($connection, $type));
        $identity = self::identity($item->fields);
        if ($identity['serial'] === '' && $identity['otherserial'] === '') {
            throw new \RuntimeException('No native serial or inventory identifier.');
        }
        $counts = [];
        $deadline = hrtime(true) + 2_000_000_000;
        foreach ($identity as $column => $value) {
            try {
                $counts[$column] = $value !== '' ? self::localIdentityCount($type, (int) $item->fields['entities_id'], $column, $value, $deadline) : 0;
            } catch (\Throwable $error) {
                if ($column === 'serial' || $identity['serial'] === '') { throw $error; }
                $counts[$column] = null;
            }
            if ($checkDuplicates && $value !== '' && ($column === 'serial' || $identity['serial'] === '') && $counts[$column] !== 1) {
                throw new \RuntimeException('Duplicate local identity; automatic matching blocked.');
            }
        }
        return ['binding' => array_intersect_key($binding, array_flip(['connection_id', 'registration', 'installation'])),
            'generation' => $registration['generation'], 'connection_identity' => $registration['connection_identity'],
            'route' => $route, 'identity' => $identity, 'local_counts' => $counts, 'local_entity' => (int) $item->fields['entities_id']];
    }

    /** Called only by the bounded scanner; never by inventory hooks or page rendering. */
    public static function queueMatch(string $connection, string $type, int $id): bool
    {
        global $DB;
        $task = self::matchTask($connection, $type, $id);
        if ($task) {
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($body['version'] === 5 && !in_array($task['state'], ['accepted', 'executing'], true)) { return false; }
            if ((int) $task['expires'] < time() && !($task['state'] === 'accepted' && $task['result'] === '{}')) {
                self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'],
                    ['id' => (int) $task['queue_id'], 'status' => 'waiting_agent']));
            }
            return false;
        }
        try { self::matchContext($connection, $type, $id, false); }
        catch (\Throwable) { return false; }
        return AssetSyncQueue::notify($type, $id, $connection);
    }

    /** A alone makes the matching decision from complete native exact-lookup evidence. */
    public static function chooseMatch(array $identity, array $evidence): array
    {
        if (($evidence['complete'] ?? false) !== true || !is_int($evidence['observed_at'] ?? null)
            || !is_array($evidence['lookup_complete'] ?? null) || array_keys($evidence['lookup_complete']) !== ['serial', 'otherserial']) {
            throw new \RuntimeException('Incomplete identity evidence.');
        }
        foreach (['serial', 'otherserial'] as $column) {
            if (!is_array($evidence[$column] ?? null) || !array_is_list($evidence[$column]) || count($evidence[$column]) > 2
                || !is_bool($evidence['lookup_complete'][$column])
                || (!$evidence['lookup_complete'][$column] && $evidence[$column] !== [])
                || ($identity[$column] === '' && $evidence[$column] !== [])) { throw new \RuntimeException('Invalid identity candidates.'); }
            if (isset($evidence['lookup_errors']) && (!is_string($evidence['lookup_errors'][$column] ?? null)
                || strlen($evidence['lookup_errors'][$column]) > 160)) { throw new \RuntimeException('Invalid lookup error.'); }
            $ids = [];
            foreach ($evidence[$column] as $row) {
                if (!is_array($row) || !is_int($row['items_id'] ?? null) || $row['items_id'] <= 0
                    || !is_int($row['entity_id'] ?? null) || $row['entity_id'] < 0
                    || !in_array($row['itemtype'] ?? '', ['Computer', 'Printer'], true)
                    || self::identity($row) !== array_intersect_key($row, array_flip(['serial', 'otherserial']))
                    || $row[$column] !== $identity[$column] || isset($ids[$row['items_id']])) {
                    throw new \RuntimeException('Invalid exact candidate identity.');
                }
                $ids[$row['items_id']] = true;
            }
        }
        $serial = $evidence['serial'];
        $inventory = $evidence['otherserial'];
        if (!$evidence['lookup_complete']['serial']) { return ['outcome' => 'Serial lookup incomplete; matching blocked. ' . ($evidence['lookup_errors']['serial'] ?? '')]; }
        if (count($serial) > 1) { return ['outcome' => 'Duplicate B serial; matching blocked.']; }
        if (count($serial) === 1) { return ['outcome' => 'Unique serial match.', 'candidate' => $serial[0], 'method' => 'serial']; }
        if (!$evidence['lookup_complete']['otherserial']) { return ['outcome' => 'Inventory lookup incomplete; matching blocked. ' . ($evidence['lookup_errors']['otherserial'] ?? '')]; }
        if (count($inventory) > 1) { return ['outcome' => 'Duplicate B inventory number; matching blocked.']; }
        if ($inventory === []) { return ['outcome' => 'No exact native identity match.', 'no_match' => true]; }
        if ($identity['serial'] !== '' && $inventory[0]['serial'] !== '' && $identity['serial'] !== $inventory[0]['serial']) {
            return ['outcome' => 'Conflicting serials on inventory candidate; matching blocked.'];
        }
        return ['outcome' => 'Unique inventory match.', 'candidate' => $inventory[0], 'method' => 'otherserial'];
    }

    private static function publishMatch(array $job, array $scope, ?array $previous = null, ?array $candidate = null): void
    {
        global $DB;
        $body = ['version' => 4, 'id' => bin2hex(random_bytes(16)), 'kind' => $candidate ? 'reverify' : 'match',
            'installation' => $scope['binding']['installation'], 'registration' => $scope['binding']['registration'],
            'generation' => $scope['generation'], 'connection_id' => $job['glpi_b_connection_id'], 'pair_id' => '',
            'itemtype' => $job['itemtype'], 'items_id' => $candidate['items_id'] ?? 0,
            'entity_id' => (int) $scope['route']['glpi_b_target_entity_id'], 'serial' => '', 'policy' => self::hash($scope),
            'expires' => time() + 300, 'changes' => [], 'identity' => $scope['identity']];
        $body['hash'] = self::hash($body);
        $context = ['local_id' => (int) $job['items_id'], 'scope' => $scope, 'candidate' => $candidate];
        AgentInbox::sql('START TRANSACTION');
        try {
            if (!AssetSyncQueue::owns($job)) { throw new \RuntimeException('Matching queue claim changed.'); }
            if ($previous) {
                self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $previous['id'], 'slot' => $previous['slot']]));
                if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Matching task ownership changed.'); }
            }
            self::write($DB->insert(self::TABLE, ['id' => $body['id'], 'connection_id' => $body['connection_id'],
                'slot' => 'match:' . self::hash([$body['connection_id'], $body['itemtype'], (int) $job['items_id']]),
                'hash' => $body['hash'], 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'state' => 'accepted', 'result' => '{}',
                'queue_id' => (int) $job['id'], 'job' => json_encode($job, JSON_THROW_ON_ERROR),
                'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => time(), 'expires' => $body['expires']]));
            self::write(AssetSyncQueue::waitForAgent($job));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function processMatch(array $job): void
    {
        global $DB;
        $task = self::matchTask($job['glpi_b_connection_id'], $job['itemtype'], (int) $job['items_id']);
        if ($task && json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR)['version'] === 5) {
            self::processCreation($job, $task);
            return;
        }
        try {
            $other = AgentInbox::one('SELECT `id` FROM `' . self::TABLE . '` WHERE `queue_id` = ' . (int) $job['id']
                . ' AND `slot` IS NOT NULL AND `id` <> ' . $DB->quote($task['id'] ?? '') . ' LIMIT 1');
            if ($other) {
                // A retained apply may have written. Matching must not change its queue or slot.
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            $scope = self::matchContext($job['glpi_b_connection_id'], $job['itemtype'], (int) $job['items_id']);
            if (!$task) { self::publishMatch($job, $scope); return; }
            if ((int) $task['queue_id'] !== (int) $job['id']) { throw new \RuntimeException('Another matching queue owns this task.'); }
            if ($task['result'] === '{}' && in_array($task['state'], ['accepted', 'executing'], true)
                && ($task['state'] === 'accepted' || (int) $task['expires'] >= time())) { self::park($job, $task); return; }
            if (!self::timelyResult($task)) { throw new \RuntimeException('Read-only matching expired; retry scheduled.'); }
            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            if (!self::sameMatchScope($scope, $expected['scope']) || !in_array($task['state'], ['verified', 'unchanged'], true)) {
                throw new \RuntimeException('Matching scope changed or read failed; retry scheduled.');
            }
            self::validateResult($body, $result);
            $choice = self::chooseMatch($scope['identity'], $result['evidence']);
            if (($choice['no_match'] ?? false) === true) {
                $source = self::creationSource($job, $scope);
                self::publishCreation($job, $scope, $task, 'prepare_create', $source);
                return;
            }
            if (!isset($choice['candidate'])) { throw new \RuntimeException($choice['outcome']); }
            if ($scope['local_counts'][$choice['method']] !== 1) { throw new \RuntimeException('Duplicate local identity; automatic matching blocked.'); }
            $candidate = $choice['candidate'];
            foreach (['serial', 'otherserial'] as $column) {
                foreach ($result['evidence'][$column] as $row) {
                    if ($row['itemtype'] !== $body['itemtype'] || $row['entity_id'] !== $body['entity_id']) {
                        throw new \RuntimeException('Candidate type or entity differs.');
                    }
                }
            }
            if ($body['kind'] === 'match') { self::publishMatch($job, $scope, $task, $candidate); return; }
            if ($candidate !== $expected['candidate'] || $candidate['items_id'] !== $body['items_id']
                || self::identity($result['snapshot']) !== self::identity($candidate)) {
                throw new \RuntimeException('Fresh B identity evidence changed.');
            }
            self::confirmMatch($job, $task, $scope, $choice, $result['snapshot']);
        } catch (\Throwable $error) {
            AgentInbox::sql('START TRANSACTION');
            try {
                if (AssetSyncQueue::owns($job)) {
                    if ($task && (int) $task['queue_id'] === (int) $job['id']) {
                        // Match/reverify are read-only. Retain immutable bodies/results and release only this slot.
                        self::write($DB->update(self::TABLE, ['slot' => null, 'state' => 'blocked'], ['id' => $task['id'], 'slot' => $task['slot']]));
                    }
                    self::write(AssetSyncQueue::retry($job, 'Verification failed: ' . $error->getMessage()));
                }
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $storageError) { $DB->doQuery('ROLLBACK'); throw $storageError; }
        }
    }

    private static function confirmMatch(array $job, array $task, array $scope, array $choice, array $snapshot): void
    {
        global $DB;
        $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
        if (isset($expected['source'])) {
            // Only this transaction: after waiting for child locks, read the committed edit,
            // not an earlier repeatable-read snapshot. The row locks remain held to COMMIT.
            AgentInbox::sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        AgentInbox::sql('START TRANSACTION');
        try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($job['glpi_b_connection_id']) . ' FOR UPDATE');
            $fresh = self::matchContext($job['glpi_b_connection_id'], $job['itemtype'], (int) $job['items_id'], true, true);
            if (!AssetSyncQueue::owns($job) || !self::timelyResult($task)
                || !self::sameMatchScope($fresh, $scope) || $fresh['local_counts'][$choice['method']] !== 1) {
                throw new \RuntimeException('Fresh local matching scope or claim changed.');
            }
            if (isset($expected['source'])) {
                self::lockLocalRows(['itemtype' => $job['itemtype'], 'local_id' => (int) $job['items_id']],
                    array_keys($expected['source']['context']['values']));
                if (self::creationSource($job, $fresh) !== $expected['source']) {
                    throw new \RuntimeException('Creation source changed before link confirmation; created identity retained.');
                }
            }
            $candidate = $choice['candidate'];
            $reverse = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
                'itemtype' => $job['itemtype'], 'glpi_b_connection_id' => $job['glpi_b_connection_id'], 'remote_items_id' => $candidate['items_id']], 'LIMIT' => 1]));
            if ($reverse !== []) { throw new \RuntimeException('Existing reverse link retained.'); }
            $route = $scope['route'];
            $id = bin2hex(random_bytes(16));
            $basis = ['normalization_version' => 4, 'method' => $choice['method'], 'a' => $scope['identity'], 'b' => self::identity($candidate)];
            $snapshot['pair_id'] = $id;
            self::write($DB->insert(self::PAIRS, $scope['binding'] + ['id' => $id, 'itemtype' => $job['itemtype'],
                'local_id' => (int) $job['items_id'], 'local_entity' => $scope['local_entity'], 'computer_id' => $candidate['items_id'],
                'entity_id' => $candidate['entity_id'], 'serial' => $candidate['serial'], 'route_id' => $route['id'], 'confirmed' => 1,
                'identity_basis' => json_encode($basis, JSON_THROW_ON_ERROR), 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'observed_at' => $snapshot['observed_at']]));
            self::write(AssetSyncLink::saveStatus($job['itemtype'], (int) $job['items_id'], $job['glpi_b_connection_id'], $route['id'],
                AssetSyncLink::STATUS_BLOCKED_CONFIGURATION, 'Automatically matched; synchronization pending.', $candidate['items_id']));
            self::write($DB->update(AssetSyncLink::TABLE, ['confirmed_remote_id' => $candidate['items_id'],
                'confirmed_entity_id' => $candidate['entity_id'], 'confirmed_identity' => $scope['connection_identity']],
                ['itemtype' => $job['itemtype'], 'items_id' => (int) $job['items_id'], 'glpi_b_connection_id' => $job['glpi_b_connection_id']]));
            $registration = AgentInbox::one('SELECT * FROM `' . AgentInbox::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($scope['binding']['registration']) . ' FOR UPDATE');
            $approved = json_decode($registration['routes'], true, 32, JSON_THROW_ON_ERROR);
            $approved[$route['id']] = ['fingerprint' => AgentInbox::routeIdentity($route), 'source_entity' => $scope['local_entity']];
            self::write($DB->update(AgentInbox::REGISTRATIONS, ['routes' => json_encode($approved, JSON_THROW_ON_ERROR)], ['id' => $registration['id']]));
            self::scope(self::byId($job['glpi_b_connection_id'], $id));
            self::write($DB->update(self::TABLE, ['slot' => null, 'state' => 'matched'], ['id' => $task['id'], 'slot' => $task['slot']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Matching task ownership changed.'); }
            self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending', 'claim_token' => '', 'started_at' => null,
                'route_id' => $route['id'], 'remote_items_id' => $candidate['items_id'],
                'last_error' => 'Automatically matched; synchronization pending.'], ['id' => (int) $job['id'], 'claim_token' => $job['claim_token'], 'status' => 'running']));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Matching queue ownership changed.'); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    /** Capture every mapped A value, including B-authority values, for new-item initialization only. */
    private static function creationSource(array $job, array $scope): array
    {
        self::validateMappings($job['itemtype'], FieldMapping::load($job['glpi_b_connection_id'], $job['itemtype']), true);
        foreach ($scope['identity'] as $column => $value) {
            if ($value !== '' && $scope['local_counts'][$column] !== 1) {
                throw new \RuntimeException('Creation requires unique local identifiers.');
            }
        }
        $item = self::model($job['itemtype']);
        if (!$item->getFromDB((int) $job['items_id'])) { throw new \RuntimeException('Creation source unavailable.'); }
        $pair = ['itemtype' => $job['itemtype'], 'connection_id' => $job['glpi_b_connection_id'],
            'local_id' => (int) $job['items_id'], 'local_entity' => $scope['local_entity']];
        $context = self::mappingContext($pair, $item->fields, self::hash([$scope,
            FieldMapping::load($pair['connection_id'], $pair['itemtype'])]));
        $seed = [];
        foreach ($context['mappings'] as $mapping) {
            $key = $mapping['glpi_a_field'];
            $type = $context['types'][$key];
            $value = self::normalizeValue($type, $context['values'][$key], true);
            if (is_string($value) && strlen($value) > 16384) { throw new \RuntimeException('Creation seed exceeds field budget.'); }
            $destination = $mapping['glpi_b_field'];
            $entry = ['type' => $type, 'value' => $value];
            if (isset($seed[$destination]) && $seed[$destination] !== $entry) {
                throw new \RuntimeException('Conflicting initialization values target the same B field.');
            }
            $seed[$destination] = $entry;
        }
        if (count($seed) > 32) { throw new \RuntimeException('Creation mapping budget exceeded.'); }
        return ['context' => $context, 'seed' => $seed, 'guard' => self::creationSourceGuard($job)];
    }

    /** Bearer pulls have no interactive Fields catalog. Compare the worker-validated persisted source. */
    private static function creationSourceGuard(array $job): string
    {
        global $DB;
        $type = $job['itemtype'];
        $item = self::model($type);
        if (!$item->getFromDB((int) $job['items_id'])) { throw new \RuntimeException('Creation source unavailable.'); }
        $rows = FieldMapping::load($job['glpi_b_connection_id'], $type);
        self::validateMappings($type, $rows);
        $source = [];
        foreach ($rows as $row) {
            $key = $row['glpi_a_field_key'];
            if (!FieldsText::isCustom($key)) {
                $column = str_starts_with($key, $type . '.') ? substr($key, strlen($type) + 1) : $key;
                if (!array_key_exists($column, $item->fields)) { throw new \RuntimeException('Creation source column unavailable.'); }
                $source[$key] = $item->fields[$column];
                continue;
            }
            $descriptor = FieldsText::descriptor($type, $key);
            $children = array_values(iterator_to_array($DB->request(['FROM' => $descriptor['table'],
                'WHERE' => ['items_id' => (int) $job['items_id']], 'LIMIT' => 2])));
            if (count($children) !== 1 || $children[0]['itemtype'] !== $type
                || !array_key_exists($descriptor['field'], $children[0])) { throw new \RuntimeException('Creation source row unavailable.'); }
            $child = $children[0];
            $container = new \PluginFieldsContainer();
            if (!$container->getFromDB((int) $child['plugin_fields_containers_id'])) { throw new \RuntimeException('Creation source container unavailable.'); }
            $definitions = array_values(iterator_to_array($DB->request(['FROM' => 'glpi_plugin_fields_fields',
                'WHERE' => ['plugin_fields_containers_id' => (int) $child['plugin_fields_containers_id'], 'name' => $descriptor['field']], 'LIMIT' => 2])));
            if (count($definitions) !== 1) { throw new \RuntimeException('Creation source definition unavailable.'); }
            $source[$key] = [$child['id'], $child['itemtype'], $child[$descriptor['field']], $container->fields, $definitions[0]];
        }
        return self::hash([$rows, $source]);
    }

    private static function publishCreation(array $job, array $scope, array $previous, string $kind, array $source,
        string $prerequisites = '', ?array $created = null): void
    {
        global $DB;
        $body = ['version' => 5, 'id' => bin2hex(random_bytes(16)), 'kind' => $kind,
            'installation' => $scope['binding']['installation'], 'registration' => $scope['binding']['registration'],
            'generation' => $scope['generation'], 'connection_id' => $job['glpi_b_connection_id'], 'pair_id' => '',
            'itemtype' => $job['itemtype'], 'items_id' => $created['items_id'] ?? 0,
            'entity_id' => (int) $scope['route']['glpi_b_target_entity_id'], 'serial' => '', 'policy' => self::hash([$scope, $source]),
            'expires' => time() + 300, 'changes' => [], 'identity' => $scope['identity'],
            'seed' => $kind === 'confirm_create' ? [] : $source['seed'], 'prerequisites' => $prerequisites];
        $body['hash'] = self::hash($body);
        if (strlen(json_encode($body, JSON_THROW_ON_ERROR)) > 49152) { throw new \RuntimeException('Creation task exceeds transport budget.'); }
        $context = ['local_id' => (int) $job['items_id'], 'scope' => $scope, 'source' => $source, 'created' => $created];
        AgentInbox::sql('START TRANSACTION');
        try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($job['glpi_b_connection_id']) . ' FOR UPDATE');
            $current = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($previous['id']) . ' FOR UPDATE');
            if (!AssetSyncQueue::owns($job)) { throw new \RuntimeException('Creation queue ownership changed.'); }
            if (!$current || $current['slot'] !== $previous['slot'] || $current['result'] !== $previous['result']) {
                throw new \RuntimeException('Creation result arrived during task renewal.');
            }
            $previousBody = json_decode($current['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($previousBody['kind'] === 'create' && $kind === 'create'
                && ($current['state'] !== 'accepted' || $current['result'] !== '{}')) {
                throw new \RuntimeException('A delivered create cannot be republished.');
            }
            self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $previous['id'], 'slot' => $previous['slot']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Creation task ownership changed.'); }
            self::write($DB->insert(self::TABLE, ['id' => $body['id'], 'connection_id' => $body['connection_id'], 'slot' => $previous['slot'],
                'hash' => $body['hash'], 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'state' => 'accepted', 'result' => '{}',
                'queue_id' => (int) $job['id'], 'job' => json_encode($job, JSON_THROW_ON_ERROR),
                'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => time(), 'expires' => $body['expires']]));
            self::write(AssetSyncQueue::waitForAgent($job));
            self::write($DB->update(AssetSyncQueue::TABLE, ['last_error' => $created
                ? 'Created B asset ' . $created['items_id'] . '; awaiting independent confirmation.'
                : 'Preparing automatic B creation.'], ['id' => (int) $job['id']]));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function processCreation(array $job, array $task): void
    {
        global $DB;
        try {
            if ((int) $task['queue_id'] !== (int) $job['id']) { throw new \RuntimeException('Creation queue changed.'); }
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $scope = self::matchContext($job['glpi_b_connection_id'], $job['itemtype'], (int) $job['items_id']);
            if (!self::sameMatchScope($scope, $expected['scope']) || self::creationSource($job, $scope) !== $expected['source']) {
                throw new \RuntimeException('Creation source or mapping changed; retained operation requires review.');
            }
            if ($task['state'] === 'accepted' && $task['result'] === '{}') {
                self::park($job, $task);
                return;
            }
            if ($body['kind'] !== 'create' && $task['result'] !== '{}' && !self::timelyResult($task)) {
                self::publishCreation($job, $scope, $task, $body['kind'], $expected['source'], '', $expected['created']);
                return;
            }
            if ($task['result'] === '{}') {
                if ((int) $task['expires'] < time()) {
                    if ($body['kind'] !== 'create') {
                        self::publishCreation($job, $scope, $task, $body['kind'], $expected['source'], $body['prerequisites'], $expected['created']);
                        return;
                    }
                    throw new \RuntimeException('Creation task expired; retained operation awaits evidence or review.');
                }
                self::park($job, $task);
                return;
            }
            $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            self::validateResult($body, $result);
            if (($result['existing'] ?? false) === true) {
                $choice = self::chooseMatch($scope['identity'], $result['evidence']);
                if (!isset($choice['candidate'])) { throw new \RuntimeException('Identity appeared before creation but is not uniquely matchable.'); }
                self::publishMatch($job, $scope, $task, $choice['candidate']);
                return;
            }
            if (!in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                throw new \RuntimeException('Creation ' . $result['outcome'] . '; retained operation requires review.');
            }
            if ($body['kind'] === 'prepare_create') {
                self::publishCreation($job, $scope, $task, 'create', $expected['source'], $result['prerequisites']);
            } elseif ($body['kind'] === 'create') {
                // Retained committed creation evidence survives expiry; it never authorizes another add.
                self::publishCreation($job, $scope, $task, 'confirm_create', $expected['source'], '',
                    ['task_id' => $body['id'], 'items_id' => $result['created_id']]);
            } else {
                $choice = self::chooseMatch($scope['identity'], $result['evidence']);
                if (!isset($choice['candidate']) || $choice['candidate']['items_id'] !== $expected['created']['items_id']
                    || self::identity($choice['candidate']) !== $scope['identity']
                    || self::identity($result['snapshot']) !== $scope['identity']) {
                    throw new \RuntimeException('Created B identity could not be independently confirmed.');
                }
                self::confirmMatch($job, $task, $scope, $choice, $result['snapshot']);
            }
        } catch (\Throwable $error) {
            // Every v5 chain retains its slot, including source changes after a successful add.
            // No expiry, scanner pass or failure can manufacture a second creation intent.
            AgentInbox::sql('START TRANSACTION');
            try {
                $current = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' FOR UPDATE');
                if ($current && $current['slot'] === $task['slot'] && AssetSyncQueue::owns($job)) {
                    self::write(AssetSyncQueue::waitForAgent($job));
                    if ($current['result'] !== '{}' && $current['result'] !== $task['result']) {
                        // Receipt either wins this lock and wakes us now, or wakes the parked queue later.
                        self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $job['id'], 'status' => 'waiting_agent']));
                    } else {
                        $state = in_array($current['state'], ['executing', 'uncertain'], true) ? 'uncertain' : 'blocked';
                        self::write($DB->update(self::TABLE, ['state' => $state], ['id' => $current['id']]));
                    }
                    self::write($DB->update(AssetSyncQueue::TABLE, ['last_error' => $error->getMessage()], ['id' => (int) $job['id']]));
                }
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $storageError) { $DB->doQuery('ROLLBACK'); throw $storageError; }
        }
    }

    public static function process(array $job, callable $applyLocal): void
    {
        global $DB;
        $type = $job['itemtype'];
        $pair = self::pair($job['glpi_b_connection_id'], $type, (int) $job['items_id']);
        if (!$pair || self::matchTask($job['glpi_b_connection_id'], $type, (int) $job['items_id'])) {
            AssetUuidOperation::withAssetLock($type, (int) $job['items_id'], static fn () => self::processMatch($job));
            return;
        }
        $task = $pair ? self::outstanding($job['glpi_b_connection_id'], $pair) : null;
        $recovering = false;
        $fieldConflict = false;
        if ($task && (int) $task['queue_id'] !== (int) $job['id']) {
            self::write(AssetSyncQueue::retry($job, 'Waiting for another agent operation on this pair.'));
            return;
        }
        try {
            if ($task && $task['state'] === 'finalizing') {
                self::write($DB->update(self::TABLE, ['state' => 'uncertain'], ['id' => $task['id'], 'state' => 'finalizing']));
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            if (!$pair || (int) $pair['local_id'] !== (int) $job['items_id']) {
                throw new \RuntimeException('Agent mode requires an independently confirmed typed pair.');
            }
            $context = self::context($pair);
            if (!$task) {
                self::publish($pair, 'inspect', [], $job, $context);
                return;
            }
            $recovery = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR)['recovery_proof'] ?? null;
            if ($task['state'] === 'stale' && $recovery !== null) {
                $recovering = true;
                $oldBody = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
                if ($oldBody['kind'] !== 'apply' || !in_array($oldBody['version'], [1, 2, 3, 4, 6], true)) { throw new \RuntimeException('Recovery kind changed.'); }
                self::publish($pair, 'inspect', [], $job, $context, $task['id']);
                return;
            }
            if ($task['result'] === '{}') {
                self::park($job, $task);
                return;
            }
            $captured = json_decode($task['job'], true, 32, JSON_THROW_ON_ERROR);
            $expected = json_decode($task['context'], true, 32, JSON_THROW_ON_ERROR);
            $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($task['state'] === 'blocked' && $body['version'] === 6 && $body['kind'] === 'inspect'
                && isset($expected['field_conflict']) && ($result['changed'] ?? null) === []
                && ((int) ($job['agent_snapshot'] ?? 0) > (int) ($captured['agent_snapshot'] ?? 0)
                    || !self::sameLocalContext($expected, $context))) {
                self::publish($pair, 'inspect', [], $job, $context, $task['id']);
                return;
            }
            if ($task['state'] === 'blocked' && $body['version'] === 6 && $body['kind'] === 'inspect'
                && isset($expected['field_conflict']) && ($result['changed'] ?? null) === []) {
                self::write(AssetSyncQueue::block($job, $expected['field_conflict']));
                return;
            }
            if (!in_array($task['state'], ['verified', 'unchanged'], true)
                || !in_array($result['outcome'], ['verified', 'unchanged'], true)) {
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            if (!self::timelyResult($task) || !self::sameLocalContext($expected, $context)
                || $body['serial'] !== $pair['serial'] || $body['generation'] !== self::scope($pair)['registration']['generation']) {
                self::write($DB->update(self::TABLE, ['state' => 'stale'], ['id' => $task['id']]));
                self::write(AssetSyncQueue::waitForAgent($job));
                return;
            }
            // Only the original captured notification generation may be completed by this chain.
            $job['agent_snapshot'] = (int) ($captured['agent_snapshot'] ?? 0);
            $context['recheck'] = !empty($expected['recheck']) || !empty($job['previous_needs_recheck']);
            if ($body['version'] === 6) { $context['source_guard'] = $expected['source_guard']; }
            if ($body['kind'] === 'inspect') {
                $changes = [];
                $needsBWrite = false;
                if ($body['version'] === 6) {
                    $context['b_snapshot'] = $result['snapshot'];
                    $context['directions'] = [];
                    $context['b_origin'] = $result['snapshot']['origin_epoch'];
                    foreach ($context['mappings'] as $mapping) {
                        $key = $mapping['glpi_a_field'];
                        $direction = $mapping['source_of_truth'] === 'both' ? self::bothDirection($context, $result['snapshot'], $key) : $mapping['source_of_truth'];
                        $context['directions'][$key] = $direction;
                        if ($mapping['source_of_truth'] === 'both' && $direction === 'glpi_a') { $context['b_origin'] = $context['origin_epoch']; }
                    }
                }
                foreach ($context['mappings'] as $mapping) {
                    $key = $mapping['glpi_b_field'];
                    $field = $result['snapshot']['fields'][$key] ?? null;
                    $type = $context['types'][$mapping['glpi_a_field']];
                    if (!$field || $field['type'] !== $type) { throw new \RuntimeException('Mapped B field type or existing row is unavailable.'); }
                    $direction = $context['directions'][$mapping['glpi_a_field']] ?? $mapping['source_of_truth'];
                    $value = $direction === 'glpi_a' ? $context['values'][$mapping['glpi_a_field']] : $field['value'];
                    $changes[$key] = ['type' => $type, 'metadata' => $field['metadata'], 'expected' => $field['value'],
                        'value' => self::normalizeValue($type, $value, true)];
                    $needsBWrite = $needsBWrite || $changes[$key]['value'] !== $field['value'];
                }
                if ($needsBWrite || $body['version'] === 6) {
                    self::publish($pair, 'apply', $changes, $job, $context, $task['id']);
                    return;
                }
            }
            if ($body['version'] === 6) { $context = array_replace($context, array_intersect_key($expected, array_flip(['directions', 'b_snapshot', 'b_origin']))); }
            if (!AssetSyncQueue::owns($job)) { return; }
            // This durable marker prevents replay after process death during any A-side model/billing write.
            self::write($DB->update(self::TABLE, ['state' => 'finalizing'], ['id' => $task['id'], 'state' => $task['state']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Task already finalized.'); }
            $localChanges = [];
            foreach ($context['mappings'] as $mapping) {
                if (($context['directions'][$mapping['glpi_a_field']] ?? $mapping['source_of_truth']) === 'glpi_b') {
                    $value = $result['snapshot']['fields'][$mapping['glpi_b_field']]['value'];
                    if ($context['values'][$mapping['glpi_a_field']] !== $value) { $localChanges[$mapping['glpi_a_field']] = $value; }
                }
            }
            AgentInbox::sql('START TRANSACTION');
            try {
                self::lockLocalRows($pair, array_keys($context['values']));
                $current = self::context($pair);
                if (!self::sameLocalContext($context, $current)) {
                    throw new \RuntimeException('Local expected values changed.');
                }
                foreach ($localChanges as $key => $value) {
                    if ($current['types'][$key] === 'date' && $value === '') {
                        $meta = FieldsText::customMetadata($pair['itemtype'], [$key])[$key];
                        $definition = new \PluginFieldsField();
                        $computer = self::model($pair['itemtype']);
                        if (!$definition->getFromDB($meta['field_id']) || !$computer->getFromDB((int) $pair['local_id'])) {
                            throw new \RuntimeException('Local date metadata disappeared.');
                        }
                        $mandatory = !empty($definition->fields['mandatory']);
                        $statusField = \PluginFieldsStatusOverride::getStatusFieldName($pair['itemtype']);
                        foreach (\PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus(
                            (int) $definition->fields['plugin_fields_containers_id'], $pair['itemtype'], (int) $computer->fields[$statusField]) as $override) {
                            if ((int) $override['plugin_fields_fields_id'] === (int) $meta['field_id']) { $mandatory = !empty($override['mandatory']); }
                        }
                        if ($mandatory) {
                            throw new \RuntimeException('Local mandatory date cannot be cleared.');
                        }
                    }
                }
                $billingChanged = $applyLocal($localChanges, $context['mappings']) === true;
                if ($body['version'] === 6) {
                    $after = self::nativeEvidence($pair);
                    if (self::identity($after['asset']) !== $context['identity']) { throw new \RuntimeException('Local identity changed during finalization.'); }
                    foreach ($localChanges as $key => $value) {
                        if (isset($after['native_values'][$key]) && $after['native_values'][$key] !== $value) { throw new \RuntimeException('Local native readback failed.'); }
                    }
                    $origin = $context['origin_epoch'];
                    foreach ($context['mappings'] as $mapping) {
                        if ($mapping['source_of_truth'] === 'both' && ($context['directions'][$mapping['glpi_a_field']] ?? '') === 'glpi_b') {
                            $origin = $context['b_snapshot']['origin_epoch'];
                        }
                    }
                    $expected['local_mutation'] = ['modification' => $after['modification'], 'native_values' => $after['native_values'], 'origin_epoch' => $origin];
                    self::write($DB->update(self::TABLE, ['context' => json_encode($expected, JSON_THROW_ON_ERROR)], ['id' => $task['id'], 'state' => 'finalizing']));
                }
                AgentInbox::sql('COMMIT');
            } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
            $outcome = $billingChanged || $localChanges !== [] || $result['changed'] !== [] ? 'verified' : 'unchanged';
            self::finish($task, $job, $outcome, $context['recheck'] || $billingChanged, self::localFingerprint(self::context($pair)));
        } catch (\Throwable $error) {
            if ($task) {
                $current = self::outstanding($job['glpi_b_connection_id'], $pair);
                if ($current && $current['id'] === $task['id']) {
                    if (str_starts_with($error->getMessage(), 'blocked_field_conflict:') && ($body['version'] ?? 0) === 6
                        && ($body['kind'] ?? '') === 'inspect' && ($result['changed'] ?? null) === []) {
                        $expected['field_conflict'] = $error->getMessage();
                        self::write($DB->update(self::TABLE, ['context' => json_encode($expected, JSON_THROW_ON_ERROR)], ['id' => $task['id']]));
                        $fieldConflict = true;
                    }
                    $state = $recovering && $current['state'] === 'stale' ? 'stale' : (in_array($current['state'], ['uncertain', 'partial'], true) ? $current['state']
                        : ($current['state'] === 'finalizing' ? 'uncertain' : 'blocked'));
                    self::write($DB->update(self::TABLE, ['state' => $state], ['id' => $task['id']]));
                }
            }
            // A failed task remains outstanding for inspection. Never silently republish an uncertain write.
            if (AssetSyncQueue::owns($job)) {
                if ($fieldConflict) {
                    self::write(AssetSyncLink::saveStatus($pair['itemtype'], (int) $pair['local_id'], $pair['connection_id'],
                        $pair['route_id'], AssetSyncLink::STATUS_BLOCKED_FIELD_CONFLICT, $expected['field_conflict']));
                    self::write(AssetSyncQueue::block($job, $expected['field_conflict']));
                }
                elseif ($recovering) { self::write(AssetSyncQueue::retry($job, 'Fresh inspection publication failed; recovery evidence retained.')); }
                elseif ($task) { self::write(AssetSyncQueue::waitForAgent($job)); }
                else { AssetSyncQueue::retry($job, 'Blocked before task publication: agent scope or mapping is unsupported; inspect Agent Pair configuration.'); }
            }
        }
    }

    private static function finish(array $task, array $job, string $outcome, bool $recheck, ?string $fingerprint = null): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($job['glpi_b_connection_id']) . ' FOR UPDATE');
            $current = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' FOR UPDATE');
            if (!$current || $current['hash'] !== $task['hash'] || $current['state'] !== 'finalizing'
                || $current['slot'] !== $task['slot'] || (int) $current['queue_id'] !== (int) $job['id']) {
                throw new \RuntimeException('Task changed before finalization.');
            }
            $pair = self::taskPair($current);
            if (!$pair || $pair['itemtype'] !== $job['itemtype'] || (int) $pair['local_id'] !== (int) $job['items_id']) {
                throw new \RuntimeException('Pair changed before finalization.');
            }
            $scope = self::scope($pair);
            $link = AssetSyncLink::find($pair['itemtype'], (int) $pair['local_id'], $pair['connection_id']);
            if (!$link || (int) $link['remote_items_id'] !== (int) $pair['computer_id'] || $link['route_id'] !== $pair['route_id']) {
                throw new \RuntimeException('Confirmed link changed before finalization.');
            }
            $fingerprint ??= self::localFingerprint(self::context($pair));
            if (!AssetSyncQueue::owns($job)) { throw new \RuntimeException('Queue claim changed before finalization.'); }
            self::write(AssetSyncLink::save([
                'itemtype' => $pair['itemtype'], 'items_id' => (int) $pair['local_id'], 'glpi_b_connection_id' => $pair['connection_id'],
                'route_id' => $pair['route_id'], 'remote_items_id' => (int) $pair['computer_id'], 'status' => AssetSyncLink::STATUS_SYNCED,
                'last_payload_hash' => $fingerprint, 'last_payload_date' => $job['payload_date'] ?? null, 'last_error' => '',
                'confirmed_remote_id' => (int) $pair['computer_id'], 'confirmed_entity_id' => (int) $pair['entity_id'],
                'confirmed_identity' => AgentInbox::identity($scope['connection']),
            ]));
            self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'done', 'claim_token' => '',
                'route_id' => $pair['route_id'], 'remote_items_id' => (int) $pair['computer_id'], 'payload_hash' => $fingerprint,
                'agent_completed' => (int) $job['agent_snapshot'], 'needs_recheck' => new \Glpi\DBAL\QueryExpression('GREATEST(`needs_recheck`, ' . (int) $recheck . ')'),
                'last_error' => 'Agent task ' . $outcome . '.', 'finished_at' => new \Glpi\DBAL\QueryExpression('NOW()')],
                ['id' => (int) $job['id'], 'claim_token' => $job['claim_token'], 'status' => 'running']));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Queue finalization lost ownership.'); }
            AgentInbox::sql('UPDATE `' . AgentInbox::RECEIPTS . '` SET `outcome` = ' . $DB->quote($outcome)
                . ', `completed_revision` = `admitted_revision`, `outcome_at` = ' . time()
                . ' WHERE `queue_id` = ' . (int) $job['id'] . ' AND `work_generation` <= ' . (int) $job['agent_snapshot']);
            self::write($DB->update(self::TABLE, ['state' => $outcome, 'slot' => null], ['id' => $task['id'], 'state' => 'finalizing']));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Task finalization lost ownership.'); }
            self::write($DB->update(self::PAIRS, ['local_fingerprint' => $fingerprint, 'checked_at' => time()], ['id' => $pair['id']]));
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }

    private static function park(array $job, array $task): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($job['glpi_b_connection_id']) . ' FOR UPDATE');
            $current = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' FOR UPDATE');
            if (!$current || !AssetSyncQueue::owns($job)) { throw new \RuntimeException('Task claim changed.'); }
            if ($current['slot'] !== $task['slot']) {
                // A first pull may have transferred the slot while this worker held the old row.
                $current = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `slot` = ' . $DB->quote($task['slot']) . ' FOR UPDATE');
                if (!$current || (int) $current['queue_id'] !== (int) $job['id']) { throw new \RuntimeException('Task slot changed.'); }
            }
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
        $type = $pair['itemtype'];
        $item = self::model($type);
        $tables = [$item::getTable() => 'id'];
        $keys = array_merge($keys, HardwareBilling::localOutputKeys($type), SwsdBilling::localOutputKeys($type));
        foreach (FieldsText::customMetadata($type, $keys) as $meta) { $tables[$meta['table']] = 'items_id'; }
        foreach ($tables as $table => $column) {
            $engine = AgentInbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $DB->quote($table));
            if (strtolower((string) ($engine['ENGINE'] ?? '')) !== 'innodb') { throw new \RuntimeException('Transactional local storage is required.'); }
            AgentInbox::sql('SELECT `id` FROM `' . $table . '` WHERE `' . $column . '` = ' . (int) $pair['local_id'] . ' FOR UPDATE');
            if ($column === 'items_id') {
                $rows = iterator_to_array($DB->request(['FROM' => $table, 'WHERE' => ['items_id' => (int) $pair['local_id']], 'LIMIT' => 2]));
                if (count($rows) !== 1 || (array_values($rows)[0]['itemtype'] ?? '') !== $type) {
                    throw new \RuntimeException('Existing local mapped/billing child row required.');
                }
            }
        }
    }

    public static function canRetry(?array $task): bool
    {
        if (!$task || !in_array($task['state'], ['stale', 'blocked'], true)) { return false; }
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        $result = json_decode($task['result'], true, 32, JSON_THROW_ON_ERROR);
        return ($body['kind'] ?? '') === 'inspect'
            || (($body['kind'] ?? '') === 'apply' && (int) ($task['result_received_at'] ?? 0) > 0
                && ($result['outcome'] ?? '') === 'stale' && ($result['changed'] ?? null) === []);
    }

    /** Normal runs recover read-only inspections; retained write tasks require independent B evidence. */
    public static function recoverStaleInspections(int $deadline): void
    {
        global $DB;
        if (!$DB || !method_exists($DB, 'tableExists') || !$DB->tableExists(self::TABLE) || hrtime(true) >= $deadline) { return; }
        $connections = array_column(array_filter(GlpiBConnection::loadAll(false, false),
            static fn (array $connection): bool => $connection['active']), 'id');
        if ($connections === []) { return; }
        $tasks = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['connection_id' => $connections,
            'state' => ['stale', 'blocked'], new \Glpi\DBAL\QueryExpression('`slot` IS NOT NULL')],
            'ORDER' => new \Glpi\DBAL\QueryExpression("COALESCE(JSON_EXTRACT(`context`, '$.inspection_checked_at'), 0) ASC, created_at ASC, id ASC"), 'LIMIT' => 10]);
        foreach ($tasks as $task) {
            if (hrtime(true) >= $deadline) { break; }
            // Atomic JSON update preserves any separately recorded apply proof while skipped rows rotate.
            AgentInbox::sql('UPDATE `' . self::TABLE . '` SET `context` = JSON_SET(`context`, \'$.inspection_checked_at\', '
                . (int) (microtime(true) * 1_000_000) . ') WHERE `id` = ' . $DB->quote($task['id'])
                . ' AND `slot` = ' . $DB->quote($task['slot']) . ' AND `state` = ' . $DB->quote($task['state']));
            $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
            if (($body['kind'] ?? '') !== 'inspect' || $task['queue_id'] === null) { continue; }
            $pair = self::taskPair($task);
            if (!$pair || !$pair['confirmed']) { continue; }
            try {
                AssetUuidOperation::withAssetLock($pair['itemtype'], (int) $pair['local_id'], static function () use ($pair, $task, $deadline): void {
                    if (hrtime(true) >= $deadline) { return; }
                    self::scope($pair);
                    $current = self::outstanding($pair['connection_id'], $pair);
                    if ($current && $current['id'] === $task['id'] && $current['state'] === 'blocked') {
                        $body = json_decode($current['body'], true, 32, JSON_THROW_ON_ERROR);
                        $expected = json_decode($current['context'], true, 32, JSON_THROW_ON_ERROR);
                        $result = json_decode($current['result'], true, 32, JSON_THROW_ON_ERROR);
                        $captured = json_decode($current['job'], true, 32, JSON_THROW_ON_ERROR);
                        $queue = AgentInbox::one('SELECT * FROM `' . AssetSyncQueue::TABLE . '` WHERE `id` = ' . (int) $current['queue_id']);
                        if ($body['version'] !== 6 || $body['kind'] !== 'inspect' || !isset($expected['field_conflict'])
                            || ($result['changed'] ?? null) !== [] || !$queue
                            || ((int) $queue['agent_requested'] <= (int) ($captured['agent_snapshot'] ?? 0)
                                && self::sameLocalContext($expected, self::context($pair)))) { return; }
                        self::retryStale($pair['connection_id'], $pair['id']);
                    } elseif ($current && $current['id'] === $task['id'] && $current['state'] === 'stale') {
                        self::retryStale($pair['connection_id'], $pair['id']);
                    }
                });
            } catch (\RuntimeException) {
                // Retain revoked or changed scope for inspection without stopping other due work.
            }
        }
    }

    /** Explicit administrator retry is restricted to tasks known not to have attempted a write. */
    public static function retryStale(string $connection, ?string $pairId = null): void
    {
        global $DB;
        AgentInbox::sql('START TRANSACTION');
        try {
            AgentInbox::sql('SELECT `connection_id` FROM `' . self::BINDINGS . '` WHERE `connection_id` = ' . $DB->quote($connection) . ' FOR UPDATE');
            $pair = $pairId === null ? self::pair($connection) : self::byId($connection, $pairId);
            $task = $pair ? self::outstanding($connection, $pair) : null;
            if ($task) { $task = AgentInbox::one('SELECT * FROM `' . self::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' FOR UPDATE'); }
            if (!self::canRetry($task)) {
                throw new \RuntimeException('Only a confirmed no-write stale operation can be retried. Partial or uncertain evidence requires investigation.');
            }
            self::write($DB->update(self::TABLE, ['slot' => null], ['id' => $task['id'], 'slot' => $task['slot'], 'state' => $task['state']]));
            if ($DB->affectedRows() !== 1) { throw new \RuntimeException('Retry task ownership changed.'); }
            if ($task['queue_id'] !== null) { self::write($DB->update(AssetSyncQueue::TABLE, ['status' => 'pending'], ['id' => (int) $task['queue_id'], 'status' => ['waiting_agent', 'blocked']])); }
            AgentInbox::sql('COMMIT');
        } catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
    }
}
