<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetUuidOperation
{
    public const TABLE = 'glpi_plugin_assetsync20_uuidoperations';
    private const CONTEXT = 'plugin:assetsync20';
    private const CURSOR = 'uuid_link_cursor';
    private const WATERMARK = 'uuid_link_watermark';
    private static array $assetLocks = [];

    public static function available(): bool
    {
        $db = $GLOBALS['DB'] ?? null;
        return is_object($db) && method_exists($db, 'tableExists') && $db->tableExists(self::TABLE);
    }

    public static function install(): bool
    {
        $db = $GLOBALS['DB'] ?? null;
        if (!is_object($db)) {
            return true;
        }
        if (self::available()) {
            return true;
        }
        $charset = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();
        return (bool) $db->doQuery('CREATE TABLE IF NOT EXISTS `' . self::TABLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `itemtype` VARCHAR(100) NOT NULL,
            `items_id` INT UNSIGNED NOT NULL,
            `generated_value` CHAR(36) DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `next_attempt` TIMESTAMP NULL DEFAULT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `claim_token` CHAR(32) DEFAULT NULL,
            `lease_until` TIMESTAMP NULL DEFAULT NULL,
            `state_json` MEDIUMTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `asset` (`itemtype`,`items_id`),
            KEY `due` (`next_attempt`,`lease_until`,`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    public static function uninstall(): bool
    {
        \Config::deleteConfigurationValues(self::CONTEXT, [self::CURSOR, self::WATERMARK]);
        return !self::available() || (bool) $GLOBALS['DB']->doQuery('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }

    public static function find(string $itemtype, int $itemsId): ?array
    {
        if (!self::available()) {
            return null;
        }
        foreach ($GLOBALS['DB']->request(['FROM' => self::TABLE,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return null;
    }

    public static function state(array $row): array
    {
        $state = json_decode((string) ($row['state_json'] ?? '{}'), true, 32);
        if (!is_array($state)) {
            throw new \RuntimeException('UUID operation state is unavailable.');
        }
        return $state;
    }

    /** Scan at most 50 existing link IDs; inserting never resets an existing intent or retry. */
    public static function bootstrap(int $deadlineNs): int
    {
        if (!self::available() || hrtime(true) >= $deadlineNs) {
            return 0;
        }
        $db = $GLOBALS['DB'];
        $saved = \Config::getConfigurationValues(self::CONTEXT, [self::CURSOR, self::WATERMARK]);
        if (hrtime(true) >= $deadlineNs) {
            return 0;
        }
        $cursor = max(0, (int) ($saved[self::CURSOR] ?? 0));
        $upper = max(0, (int) ($saved[self::WATERMARK] ?? 0));
        if ($upper <= $cursor) {
            $cursor = 0;
            foreach ($db->request(['SELECT' => ['id'], 'FROM' => AssetSyncLink::TABLE, 'ORDER' => ['id DESC'], 'LIMIT' => 1]) as $row) {
                $upper = (int) $row['id'];
            }
        }
        $count = 0;
        if (hrtime(true) >= $deadlineNs) {
            return 0;
        }
        foreach ($db->request(['FROM' => AssetSyncLink::TABLE, 'WHERE' => [
            ['id' => ['>', $cursor]], ['id' => ['<=', $upper]],
        ], 'ORDER' => ['id ASC'], 'LIMIT' => 50]) as $link) {
            if (hrtime(true) >= $deadlineNs) {
                break;
            }
            $type = (string) $link['itemtype'];
            $id = (int) $link['items_id'];
            if (isset(FieldMapping::assetTypes()[$type]) && $id > 0 && (int) $link['remote_items_id'] > 0) {
                $sql = 'INSERT IGNORE INTO `' . self::TABLE . '` (`itemtype`,`items_id`,`date_creation`,`date_mod`) VALUES ('
                    . $db->quote($type) . ',' . $id . ',NOW(),NOW())';
                $inserted = 0;
                if (!AssetSyncDbTime::write($db, static function () use ($db, $sql, &$inserted): bool {
                    $ok = (bool) $db->doQuery($sql);
                    $inserted = $ok ? $db->affectedRows() : 0;
                    return $ok;
                })) {
                    break;
                }
                $count += $inserted;
            }
            $cursor = (int) $link['id'];
        }
        \Config::setConfigurationValues(self::CONTEXT, [self::CURSOR => (string) $cursor, self::WATERMARK => (string) $upper]);
        return $count;
    }

    private static function dueCondition(): \Glpi\DBAL\QueryExpression
    {
        return new \Glpi\DBAL\QueryExpression('(`next_attempt` IS NULL OR UNIX_TIMESTAMP(`next_attempt`) <= UNIX_TIMESTAMP()) AND (`lease_until` IS NULL OR UNIX_TIMESTAMP(`lease_until`) <= UNIX_TIMESTAMP())');
    }

    public static function hasDue(): bool
    {
        if (!self::available()) {
            return false;
        }
        foreach ($GLOBALS['DB']->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => [self::dueCondition()], 'LIMIT' => 1]) as $row) {
            return true;
        }
        return false;
    }

    /** One SQL-fenced claim per phase. Expired workers cannot persist or start another write. */
    public static function claim(): ?array
    {
        if (!self::available()) {
            return null;
        }
        $db = $GLOBALS['DB'];
        $due = self::dueCondition();
        foreach ($db->request(['FROM' => self::TABLE, 'WHERE' => [$due], 'ORDER' => ['next_attempt ASC', 'id ASC'], 'LIMIT' => 1]) as $row) {
            $token = bin2hex(random_bytes(16));
            $fields = ['status' => 'running', 'claim_token' => $token,
                'lease_until' => new \Glpi\DBAL\QueryExpression('DATE_ADD(NOW(), INTERVAL 60 SECOND)'),
                'attempts' => (int) $row['attempts'] + 1, 'date_mod' => new \Glpi\DBAL\QueryExpression('NOW()')];
            $ok = AssetSyncDbTime::write($db, static fn (): bool => $db->update(self::TABLE, $fields,
                ['id' => (int) $row['id'], $due]) && $db->affectedRows() === 1);
            if ($ok) {
                return array_merge($row, $fields);
            }
        }
        return null;
    }

    private static function fence(array $claim): array
    {
        return ['id' => (int) $claim['id'], 'status' => 'running', 'claim_token' => $claim['claim_token'],
            new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`lease_until`) > UNIX_TIMESTAMP()')];
    }

    public static function owns(array $claim): bool
    {
        if (!self::available() || empty($claim['claim_token'])) {
            return false;
        }
        foreach ($GLOBALS['DB']->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => self::fence($claim), 'LIMIT' => 1]) as $row) {
            return true;
        }
        return false;
    }

    public static function persist(array $claim, array $state, ?string $generated = null): bool
    {
        $fields = ['state_json' => json_encode($state, JSON_THROW_ON_ERROR), 'date_mod' => new \Glpi\DBAL\QueryExpression('NOW()')];
        $where = self::fence($claim);
        if ($generated !== null) {
            $fields['generated_value'] = $generated;
            $where['generated_value'] = null;
        }
        $db = $GLOBALS['DB'];
        $affected = 0;
        $written = AssetSyncDbTime::write($db, static function () use ($db, $fields, $where, &$affected): bool {
            $ok = $db->update(self::TABLE, $fields, $where);
            $affected = $ok ? $db->affectedRows() : 0;
            return $ok;
        });
        if (!$written || ($generated !== null && $affected !== 1) || !self::owns($claim)) {
            return false;
        }
        $stored = self::find((string) $claim['itemtype'], (int) $claim['items_id']);
        return $stored !== null && ($stored['state_json'] ?? null) === $fields['state_json'];
    }

    public static function finish(array $claim, array $state, string $status): bool
    {
        $delay = $status === 'equal' || $status === 'verified' ? 3600 : min(3600, 60 * (2 ** min(6, (int) $claim['attempts'] - 1)));
        $fields = ['state_json' => json_encode($state, JSON_THROW_ON_ERROR), 'status' => $status,
            'next_attempt' => new \Glpi\DBAL\QueryExpression('DATE_ADD(NOW(), INTERVAL ' . $delay . ' SECOND)'),
            'lease_until' => null, 'claim_token' => null, 'date_mod' => new \Glpi\DBAL\QueryExpression('NOW()')];
        $db = $GLOBALS['DB'];
        return AssetSyncDbTime::write($db, static fn (): bool => $db->update(self::TABLE, $fields, self::fence($claim)) && $db->affectedRows() === 1);
    }

    /** Ordinary preparation, direct jobs and UUID work share the same per-asset mutex. */
    public static function withAssetLock(string $itemtype, int $itemsId, callable $work): mixed
    {
        if (!self::available()) {
            return $work();
        }
        $key = hash('sha256', $itemtype . ':' . $itemsId);
        if (isset(self::$assetLocks[$key])) {
            return $work();
        }
        $db = $GLOBALS['DB'];
        $name = $db->quote('as20:' . substr(AssetSyncLockName::databaseHash($db), 0, 8) . ':' . substr($key, 0, 40));
        $result = $db->doQuery('SELECT GET_LOCK(' . $name . ', 0) AS acquired');
        $row = $result === false ? null : $db->fetchAssoc($result);
        if (!in_array($row['acquired'] ?? null, [1, '1'], true)) {
            throw new \RuntimeException('Asset synchronization is already running or its lock is unavailable.');
        }
        self::$assetLocks[$key] = true;
        try {
            return $work();
        } finally {
            unset(self::$assetLocks[$key]);
            $db->doQuery('SELECT RELEASE_LOCK(' . $name . ') AS released');
        }
    }
}
