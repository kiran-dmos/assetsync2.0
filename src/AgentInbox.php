<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Durable current-state notifications. All HTTP acknowledgements follow COMMIT. */
final class AgentInbox
{
    public const REGISTRATIONS = 'glpi_plugin_assetsync20_agents';
    public const RECEIPTS = 'glpi_plugin_assetsync20_agentreceipts';

    public static function install(): bool
    {
        global $DB;
        $installed = (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::REGISTRATIONS . '` (
            `id` CHAR(32) PRIMARY KEY, `generation` CHAR(32) NOT NULL, `token_hash` CHAR(64) NOT NULL,
            `connection_id` VARCHAR(255) NOT NULL, `connection_identity` CHAR(64) NOT NULL,
            `routes` TEXT NOT NULL, `active` TINYINT NOT NULL DEFAULT 1, `last_seen` BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
            && (bool) $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . self::RECEIPTS . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `registration_id` CHAR(32) NOT NULL, `computer_id` INT UNSIGNED NOT NULL,
            `received_revision` BIGINT NOT NULL DEFAULT 0, `admitted_revision` BIGINT NOT NULL DEFAULT 0,
            `completed_revision` BIGINT NOT NULL DEFAULT 0, `work_generation` BIGINT NOT NULL DEFAULT 0,
            `queue_id` INT UNSIGNED DEFAULT NULL, `lifecycle` VARCHAR(12) NOT NULL DEFAULT \'present\',
            `outcome` VARCHAR(20) NOT NULL DEFAULT \'received\', `received_at` BIGINT NOT NULL DEFAULT 0,
            `outcome_at` BIGINT NOT NULL DEFAULT 0,
            UNIQUE KEY `agent_computer` (`registration_id`, `computer_id`), KEY `queue_lookup` (`queue_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$installed) {
            return false;
        }
        foreach (['last_heartbeat' => 'BIGINT NOT NULL DEFAULT 0', 'reconcile_status' => "VARCHAR(12) NOT NULL DEFAULT 'unknown'"] as $name => $definition) {
            if (!$DB->fieldExists(self::REGISTRATIONS, $name)
                && !$DB->doQuery('ALTER TABLE `' . self::REGISTRATIONS . '` ADD `' . $name . '` ' . $definition)) {
                return false;
            }
        }
        return true;
    }

    public static function identity(array $connection): string
    {
        return hash('sha256', json_encode([$connection['id'], rtrim($connection['base_url'], '/'),
            $connection['app_token'], $connection['user_token']], JSON_THROW_ON_ERROR));
    }

    public static function connection(string $id): ?array
    {
        foreach (GlpiBConnection::loadAll(true) as $connection) {
            if ($connection['id'] === $id && !empty($connection['active'])) {
                return $connection;
            }
        }
        return null;
    }

    /** Called only by the config-right protected form; the plaintext is shown once. */
    public static function register(string $connectionId, array $routeIds): array
    {
        global $DB;
        $connection = self::connection($connectionId);
        $approved = [];
        foreach (EntitySyncRoute::loadAll() as $route) {
            if (in_array($route['id'], $routeIds, true) && $route['glpi_b_connection_id'] === $connectionId
                && $route['active'] && in_array('Computer', $route['asset_types'], true)) {
                $approved[$route['id']] = ['fingerprint' => self::routeIdentity($route), 'source_entity' => (int) $route['glpi_a_source_entity_id']];
            }
        }
        if ($connection === null || $approved === [] || count($approved) !== count(array_unique($routeIds))) {
            throw new \RuntimeException('Select enabled Computer routes on one enabled connection.');
        }
        $token = bin2hex(random_bytes(32));
        $registration = ['id' => bin2hex(random_bytes(16)), 'generation' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $token), 'connection_id' => $connectionId,
            'connection_identity' => self::identity($connection), 'routes' => json_encode($approved, JSON_THROW_ON_ERROR), 'active' => 1];
        if (!$DB->insert(self::REGISTRATIONS, $registration)) {
            throw new \RuntimeException('Could not save registration.');
        }
        return ['registration' => $registration['id'], 'generation' => $registration['generation'], 'token' => $token];
    }

    public static function rotate(string $id): string
    {
        global $DB;
        $token = bin2hex(random_bytes(32));
        if (!$DB->update(self::REGISTRATIONS, ['token_hash' => hash('sha256', $token)], ['id' => $id, 'active' => 1])
            || $DB->affectedRows() !== 1) {
            throw new \RuntimeException('Registration unavailable.');
        }
        return $token;
    }

    /** Verify one saved pair without running mappings, billing, discovery or remote writes. */
    public static function verifySavedPair(array $registration, int $localId): void
    {
        global $DB;
        $item = new \Computer();
        if ($localId <= 0 || !$item->can($localId, READ)) {
            throw new \RuntimeException('Computer unavailable.');
        }
        AssetUuidOperation::withAssetLock('Computer', $localId, static function () use ($registration, $localId, $DB): void {
            $connection = self::connection($registration['connection_id']);
            $link = AssetSyncLink::find('Computer', $localId, $registration['connection_id']);
            $asset = self::one('SELECT * FROM `glpi_computers` WHERE `id` = ' . $localId);
            if (!$registration['active'] || !$connection || self::identity($connection) !== $registration['connection_identity']
                || !$link || (int) $link['remote_items_id'] <= 0 || !$asset || $asset['is_deleted'] || $asset['is_template']
                || trim((string) $asset['serial']) === '') {
                throw new \RuntimeException('Saved pair unavailable.');
            }
            EntitySyncRoute::loadAll(true);
            $scope = EntitySyncRoute::matchAsset('Computer', (string) $asset['entities_id'], [], $connection['id']);
            $route = $scope['matches'][0]['route'] ?? null;
            $approved = json_decode($registration['routes'], true, 32, JSON_THROW_ON_ERROR);
            if ($scope['conflicts'] !== [] || count($scope['matches']) !== 1 || !$route
                || $route['id'] !== $link['route_id'] || ($approved[$route['id']]['fingerprint'] ?? '') !== self::routeIdentity($route)) {
                throw new \RuntimeException('Saved pair route unavailable.');
            }
            $remoteId = (int) $link['remote_items_id'];
            $duplicates = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
                'itemtype' => 'Computer', 'glpi_b_connection_id' => $connection['id'], 'remote_items_id' => $remoteId], 'LIMIT' => 2]));
            if (count($duplicates) !== 1) {
                throw new \RuntimeException('Saved pair is ambiguous.');
            }
            GlpiBConnection::beginOrdinaryAttempt(hrtime(true) + 5_000_000_000);
            try {
                $result = GlpiBConnection::getItem($connection, 'Computer', $remoteId, false);
            } finally {
                GlpiBConnection::endOrdinaryAttempt();
            }
            $remote = $result['item'] ?? [];
            $current = self::one('SELECT * FROM `glpi_computers` WHERE `id` = ' . $localId);
            $currentRegistration = self::one('SELECT * FROM `' . self::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($registration['id']));
            $currentConnection = self::connection($connection['id']);
            $routes = array_column(EntitySyncRoute::loadAll(true), null, 'id');
            if (empty($result['success']) || (int) ($remote['id'] ?? 0) !== $remoteId
                || !empty($remote['is_deleted']) || !empty($remote['is_template'])
                || (int) ($remote['entities_id'] ?? -1) !== (int) $route['glpi_b_target_entity_id']
                || trim((string) ($remote['serial'] ?? '')) !== trim((string) $asset['serial'])
                || $current !== $asset || !$currentRegistration || !$currentRegistration['active']
                || !$currentConnection || self::identity($currentConnection) !== self::identity($connection)
                || !isset($routes[$route['id']]) || self::routeIdentity($routes[$route['id']]) !== self::routeIdentity($route)) {
                throw new \RuntimeException('Saved pair verification failed.');
            }
            self::written($DB->update(AssetSyncLink::TABLE, ['confirmed_remote_id' => $remoteId,
                'confirmed_entity_id' => (int) $route['glpi_b_target_entity_id'], 'confirmed_identity' => self::identity($connection)],
                ['id' => (int) $link['id'], 'remote_items_id' => $remoteId, 'route_id' => $route['id']]));
            if (self::pair($currentRegistration, $remoteId) === null) {
                throw new \RuntimeException('Saved pair changed during verification.');
            }
        });
    }

    private static function routeIdentity(array $route): string
    {
        return hash('sha256', json_encode([$route['id'], $route['glpi_b_connection_id'],
            $route['glpi_a_source_entity_id'], $route['include_child_entities'], $route['glpi_b_target_entity_id'],
            $route['asset_types'], $route['active']], JSON_THROW_ON_ERROR));
    }

    public static function invalidate(string $connectionId): void
    {
        global $DB;
        if (isset($DB) && $DB->tableExists(self::REGISTRATIONS)) {
            if (!$DB->update(self::REGISTRATIONS, ['active' => 0], ['connection_id' => $connectionId])
                || !$DB->update(AssetSyncLink::TABLE, ['confirmed_identity' => ''], ['glpi_b_connection_id' => $connectionId])) {
                throw new \RuntimeException('Could not invalidate agent registrations.');
            }
        }
    }

    public static function decode(string $body): array
    {
        if (strlen($body) > 1024) {
            throw new \InvalidArgumentException('Invalid notification.');
        }
        $event = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $keys = ['computer_id', 'generation', 'lifecycle', 'registration', 'revision'];
        if (!is_array($event)) {
            throw new \InvalidArgumentException('Invalid notification.');
        }
        $actual = array_keys($event);
        sort($actual);
        if (($event['kind'] ?? '') === 'health') {
            if ($actual !== ['generation', 'kind', 'reconcile_status', 'registration']
                || !is_string($event['registration']) || !preg_match('/^[a-f0-9]{32}$/D', $event['registration'])
                || !is_string($event['generation']) || !preg_match('/^[a-f0-9]{32}$/D', $event['generation'])
                || !in_array($event['reconcile_status'], ['ok', 'degraded'], true)) {
                throw new \InvalidArgumentException('Invalid health report.');
            }
            return $event;
        }
        if ($actual !== $keys || !is_int($event['computer_id']) || $event['computer_id'] < 1 || $event['computer_id'] > 4294967295
            || !is_int($event['revision']) || $event['revision'] < 1 || $event['revision'] > 9007199254740991
            || !is_string($event['registration']) || !preg_match('/^[a-f0-9]{32}$/D', $event['registration'])
            || !is_string($event['generation']) || !preg_match('/^[a-f0-9]{32}$/D', $event['generation'])
            || !in_array($event['lifecycle'], ['present', 'deleted', 'purged'], true)) {
            throw new \InvalidArgumentException('Invalid notification.');
        }
        return $event;
    }

    /** No field values or caller-selected local IDs, routes, URLs or entities enter admission. */
    public static function receive(array $event, string $token): array
    {
        global $DB;
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return ['status' => 401];
        }
        self::sql('START TRANSACTION');
        try {
            $registration = self::one('SELECT * FROM `' . self::REGISTRATIONS . '` WHERE `id` = '
                . $DB->quote($event['registration']) . ' FOR UPDATE');
            $connection = $registration ? self::connection($registration['connection_id']) : null;
            if (!$registration || !$registration['active'] || !$connection
                || !hash_equals($registration['token_hash'], hash('sha256', $token))
                || !hash_equals($registration['generation'], $event['generation'])
                || !hash_equals($registration['connection_identity'], self::identity($connection))) {
                self::sql('ROLLBACK');
                return ['status' => 401];
            }
            if (($event['kind'] ?? '') === 'health') {
                $approved = json_decode($registration['routes'], true, 32, JSON_THROW_ON_ERROR);
                $routes = array_column(EntitySyncRoute::loadAll(true), null, 'id');
                foreach ($approved as $routeId => $scope) {
                    if (!isset($routes[$routeId]) || $scope['fingerprint'] !== self::routeIdentity($routes[$routeId])) {
                        self::sql('ROLLBACK');
                        return ['status' => 409, 'kind' => 'health', 'recorded' => false];
                    }
                }
                self::written($DB->update(self::REGISTRATIONS, ['last_heartbeat' => time(),
                    'reconcile_status' => $event['reconcile_status']], ['id' => $registration['id']]));
                self::sql('COMMIT');
                return ['status' => 200, 'kind' => 'health', 'recorded' => true,
                    'registration' => $registration['id'], 'generation' => $registration['generation']];
            }
            $reg = $DB->quote($registration['id']);
            $id = $event['computer_id'];
            $revision = $event['revision'];
            $receipt = self::one('SELECT * FROM `' . self::RECEIPTS . "` WHERE `registration_id` = {$reg} AND `computer_id` = {$id} FOR UPDATE");
            $where = ['registration_id' => $registration['id'], 'computer_id' => $id];
            if ($receipt === null) {
                self::written($DB->insert(self::RECEIPTS, $where));
                $receipt = ['received_revision' => 0, 'admitted_revision' => 0];
            }
            self::written($DB->update(self::REGISTRATIONS, ['last_seen' => time()], ['id' => $registration['id']]));
            if ($revision > (int) $receipt['received_revision']) {
                self::written($DB->update(self::RECEIPTS, ['received_revision' => $revision,
                    'lifecycle' => $event['lifecycle'], 'received_at' => time(), 'outcome' => 'received'], $where));
            }
            // An ACK lost after commit can be repeated only while its durable queue still exists.
            if ($revision <= (int) $receipt['admitted_revision'] && !empty($receipt['queue_id'])
                && self::one('SELECT `id` FROM `' . AssetSyncQueue::TABLE . '` WHERE `id` = ' . (int) $receipt['queue_id'])) {
                self::sql('COMMIT');
                return self::ack($event);
            }
            $pair = self::pair($registration, $id);
            if ($event['lifecycle'] !== 'present' || $revision < (int) $receipt['received_revision'] || $pair === null) {
                self::written($DB->update(self::RECEIPTS, ['outcome' => 'blocked', 'outcome_at' => time()], $where));
                self::sql('COMMIT');
                return ['status' => 409, 'outcome' => 'blocked'];
            }
            $local = (int) $pair['items_id'];
            $conn = $DB->quote($registration['connection_id']);
            $route = $DB->quote($pair['route_id']);
            // Receipt admission increments a dedicated counter, leaving lease/backoff and needs_recheck intact.
            self::sql('INSERT INTO `' . AssetSyncQueue::TABLE . '` (`itemtype`,`items_id`,`glpi_b_connection_id`,`route_id`,`remote_items_id`,`agent_requested`,`date_creation`,`date_mod`)'
                . " VALUES ('Computer',{$local},{$conn},{$route},{$id},1,NOW(),NOW()) ON DUPLICATE KEY UPDATE `agent_requested` = `agent_requested` + 1");
            $queue = self::one('SELECT * FROM `' . AssetSyncQueue::TABLE . "` WHERE `itemtype` = 'Computer' AND `items_id` = {$local} AND `glpi_b_connection_id` = {$conn} FOR UPDATE");
            if (!$queue || (int) $queue['agent_requested'] <= (int) $queue['agent_completed']) {
                throw new \RuntimeException('Queue admission failed.');
            }
            self::written($DB->update(self::RECEIPTS, ['admitted_revision' => $revision, 'queue_id' => (int) $queue['id'],
                'work_generation' => (int) $queue['agent_requested'], 'outcome' => 'queued', 'outcome_at' => time()], $where));
            $readback = self::one('SELECT * FROM `' . self::RECEIPTS . "` WHERE `registration_id` = {$reg} AND `computer_id` = {$id}");
            if (!$readback || (int) $readback['admitted_revision'] !== $revision || (int) $readback['queue_id'] !== (int) $queue['id']) {
                throw new \RuntimeException('Receipt admission failed.');
            }
            self::sql('COMMIT');
            return self::ack($event);
        } catch (\Throwable $error) {
            $DB->doQuery('ROLLBACK');
            throw new \RuntimeException('Notification storage unavailable.', 0, $error);
        }
    }

    private static function ack(array $event): array
    {
        return ['status' => 200, 'accepted' => true, 'registration' => $event['registration'],
            'generation' => $event['generation'], 'computer_id' => $event['computer_id'], 'revision' => $event['revision']];
    }

    /** Exactly one confirmed reverse link, still on an enabled approved route. */
    public static function pair(array $registration, int $remoteId): ?array
    {
        global $DB;
        $connection = self::connection($registration['connection_id']);
        if (!$registration['active'] || !$connection || self::identity($connection) !== $registration['connection_identity']) {
            return null;
        }
        $links = iterator_to_array($DB->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
            'itemtype' => 'Computer', 'glpi_b_connection_id' => $registration['connection_id'],
            'remote_items_id' => $remoteId], 'LIMIT' => 2]));
        if (count($links) !== 1) {
            return null;
        }
        $link = array_values($links)[0];
        if ((int) $link['confirmed_remote_id'] !== $remoteId || $link['confirmed_identity'] !== $registration['connection_identity']) {
            return null;
        }
        $asset = self::one('SELECT * FROM `glpi_computers` WHERE `id` = ' . (int) $link['items_id']);
        if (!$asset || $asset['is_deleted'] || $asset['is_template']) {
            return null;
        }
        EntitySyncRoute::loadAll(true);
        $scope = EntitySyncRoute::matchAsset('Computer', (string) $asset['entities_id'], [], $registration['connection_id']);
        if ($scope['conflicts'] !== [] || count($scope['matches']) !== 1) {
            return null;
        }
        $route = $scope['matches'][0]['route'];
        $approved = json_decode($registration['routes'], true, 32, JSON_THROW_ON_ERROR);
        if (($approved[$route['id']]['fingerprint'] ?? '') !== self::routeIdentity($route)
            || $link['route_id'] !== $route['id'] || $route['glpi_b_target_entity_id'] === ''
            || (int) $link['confirmed_entity_id'] !== (int) $route['glpi_b_target_entity_id']) {
            return null;
        }
        return $link;
    }

    public static function jobPair(array $job): ?array
    {
        global $DB;
        $pair = null;
        foreach ($DB->request(['FROM' => self::RECEIPTS, 'WHERE' => ['queue_id' => (int) $job['id']]]) as $receipt) {
            // A newer notification may replace this current-state receipt during the claim.
            // Validate its same pair, but completion still consumes only the claimed generation.
            if ((int) $receipt['admitted_revision'] <= (int) $receipt['completed_revision']) {
                continue;
            }
            $registration = self::one('SELECT * FROM `' . self::REGISTRATIONS . '` WHERE `id` = ' . $DB->quote($receipt['registration_id']));
            $found = $registration ? self::pair($registration, (int) $receipt['computer_id']) : null;
            if (!$found || $receipt['lifecycle'] !== 'present' || (int) $receipt['received_revision'] > (int) $receipt['admitted_revision']
                || (int) $found['items_id'] !== (int) $job['items_id'] || ($pair && $pair['id'] !== $found['id'])) {
                return null;
            }
            $pair = $found;
        }
        return $pair;
    }

    /** Queue and receipt outcomes commit together, fenced by the random claim token. */
    public static function complete(array $job, array $fields, array $where): bool
    {
        global $DB;
        self::sql('START TRANSACTION');
        try {
            $terminal = $fields['status'] !== AssetSyncQueue::STATUS_RETRY;
            if ($terminal) {
                $fields['agent_completed'] = (int) $job['agent_snapshot'];
            }
            if (!AssetSyncDbTime::write($DB, static fn (): bool => $DB->update(AssetSyncQueue::TABLE, $fields, $where) && $DB->affectedRows() === 1)) {
                self::sql('ROLLBACK');
                return false;
            }
            $outcome = $fields['status'] === 'retry' ? 'retrying' : ($fields['status'] === 'blocked' ? 'blocked'
                : (($job['agent_changed'] ?? false) ? 'synchronized' : 'unchanged'));
            self::sql('UPDATE `' . self::RECEIPTS . '` SET `outcome` = ' . $DB->quote($outcome) . ', `outcome_at` = ' . time()
                . ($terminal ? ', `completed_revision` = `admitted_revision`' : '')
                . ' WHERE `queue_id` = ' . (int) $job['id'] . ' AND `work_generation` <= ' . (int) $job['agent_snapshot']
                . ' AND `completed_revision` < `admitted_revision`');
            self::sql('COMMIT');
            return true;
        } catch (\Throwable) {
            $DB->doQuery('ROLLBACK');
            return false;
        }
    }

    public static function one(string $sql): ?array
    {
        global $DB;
        $result = self::sql($sql);
        $row = $DB->fetchAssoc($result);
        return is_array($row) ? $row : null;
    }

    public static function sql(string $sql): mixed
    {
        global $DB;
        $result = $DB->doQuery($sql);
        if ($result === false) {
            throw new \RuntimeException('Agent storage operation failed.');
        }
        return $result;
    }

    private static function written(bool $success): void
    {
        if (!$success) {
            throw new \RuntimeException('Agent storage write failed.');
        }
    }
}
