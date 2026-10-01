<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncLink
{
    public const TABLE = 'glpi_plugin_assetsync20_assetlinks';

    public const STATUS_SYNCED = 'synced';
    public const STATUS_OUT_OF_SCOPE = 'out_of_scope';
    public const STATUS_BLOCKED_CONFIGURATION = 'blocked_configuration';
    public const STATUS_BLOCKED_IDENTITY = 'blocked_identity';
    public const STATUS_BLOCKED_DUPLICATE = 'blocked_duplicate';
    public const STATUS_BLOCKED_ROUTE_CONFLICT = 'blocked_route_conflict';
    public const STATUS_BLOCKED_FIELD_CONFLICT = 'blocked_field_conflict';
    public const STATUS_BLOCKED_MISSING_REMOTE = 'blocked_missing_remote';
    public const STATUS_BLOCKED_LOCAL_UPDATE = 'blocked_local_update';
    public const STATUS_BLOCKED_REMOTE_ERROR = 'blocked_remote_error';

    public static function install(): bool
    {
        $db = self::db();
        if ($db === null) {
            return true;
        }

        if (!method_exists($db, 'tableExists') || !method_exists($db, 'doQuery')) {
            return false;
        }

        if ($db->tableExists(self::TABLE)) {
            return true;
        }

        return (bool) $db->doQuery(self::createTableSql());
    }

    public static function uninstall(): bool
    {
        $db = self::db();
        if ($db === null) {
            return true;
        }

        if (!method_exists($db, 'tableExists') || !method_exists($db, 'doQuery')) {
            return false;
        }

        if (!$db->tableExists(self::TABLE)) {
            return true;
        }

        return (bool) $db->doQuery('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $itemtype, int $itemsId, string $connectionId): ?array
    {
        $db = self::db();
        if ($db === null || !method_exists($db, 'request')) {
            return null;
        }

        $rows = $db->request([
            'SELECT' => ['*', new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`last_sync_at`)', 'last_sync_epoch')],
            'FROM'  => self::TABLE,
            'WHERE' => [
                'itemtype'             => $itemtype,
                'items_id'             => $itemsId,
                'glpi_b_connection_id' => $connectionId,
            ],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            return is_array($row) ? $row : null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function save(array $data): bool
    {
        $db = self::db();
        if ($db === null) {
            return true;
        }

        $itemtype = (string) ($data['itemtype'] ?? '');
        $itemsId = (int) ($data['items_id'] ?? 0);
        $connectionId = (string) ($data['glpi_b_connection_id'] ?? '');

        if ($itemtype === '' || $itemsId <= 0 || $connectionId === '') {
            return false;
        }

        $now = self::now();
        $status = (string) ($data['status'] ?? self::STATUS_SYNCED);
        $fields = [
            'route_id'             => (string) ($data['route_id'] ?? ''),
            'remote_items_id'      => self::nullablePositiveInt($data['remote_items_id'] ?? null),
            'status'               => $status,
            'last_payload_hash'    => (string) ($data['last_payload_hash'] ?? ''),
            'last_payload_date'    => self::nullableDate($data['last_payload_date'] ?? null),
            'last_sync_at'         => array_key_exists('last_sync_at', $data) ? self::nullableDate($data['last_sync_at']) : $now,
            'blocked_at'           => str_starts_with($status, 'blocked_') ? $now : null,
            'last_error'           => self::nullableText($data['last_error'] ?? null),
            'date_mod'             => $now,
        ];
        if (!array_key_exists('last_payload_date', $data)) {
            unset($fields['last_payload_date']);
        }

        $existing = self::find($itemtype, $itemsId, $connectionId);
        if ($existing === null) {
            $fields['itemtype'] = $itemtype;
            $fields['items_id'] = $itemsId;
            $fields['glpi_b_connection_id'] = $connectionId;
            $fields['date_creation'] = $now;

            return method_exists($db, 'insert') && AssetSyncDbTime::write($db, static fn (): bool => $db->insert(self::TABLE, $fields));
        }

        if (!method_exists($db, 'update')) {
            return false;
        }

        return AssetSyncDbTime::write($db, static fn (): bool => $db->update(self::TABLE, $fields, ['id' => (int) $existing['id']]));
    }

    public static function saveStatus(
        string $itemtype,
        int $itemsId,
        string $connectionId,
        string $routeId,
        string $status,
        string $message,
        ?int $remoteItemsId = null,
        ?string $payloadHash = null,
        ?string $payloadDate = null
    ): bool {
        $existing = self::find($itemtype, $itemsId, $connectionId);

        if ($remoteItemsId === null) {
            $remoteItemsId = $existing !== null ? self::nullablePositiveInt($existing['remote_items_id'] ?? null) : null;
        }

        $data = [
            'itemtype'             => $itemtype,
            'items_id'             => $itemsId,
            'glpi_b_connection_id' => $connectionId,
            'route_id'             => $routeId,
            'remote_items_id'      => $remoteItemsId,
            'status'               => $status,
            'last_error'           => $message,
            'last_sync_at'         => null,
            'last_payload_hash'    => $payloadHash ?? (string) ($existing['last_payload_hash'] ?? ''),
        ];
        if ($payloadDate !== null) {
            $data['last_payload_date'] = $payloadDate;
        }

        return self::save($data);
    }

    /**
     * @return object|null
     */
    private static function db(): ?object
    {
        return isset($GLOBALS['DB']) && is_object($GLOBALS['DB']) ? $GLOBALS['DB'] : null;
    }

    private static function createTableSql(): string
    {
        $charset = class_exists('\DBConnection') && method_exists('\DBConnection', 'getDefaultCharset')
            ? \DBConnection::getDefaultCharset()
            : 'utf8mb4';
        $collation = class_exists('\DBConnection') && method_exists('\DBConnection', 'getDefaultCollation')
            ? \DBConnection::getDefaultCollation()
            : 'utf8mb4_unicode_ci';
        $primaryKeySign = class_exists('\DBConnection') && method_exists('\DBConnection', 'getDefaultPrimaryKeySignOption')
            ? \DBConnection::getDefaultPrimaryKeySignOption()
            : 'UNSIGNED';

        return <<<SQL
CREATE TABLE IF NOT EXISTS `glpi_plugin_assetsync20_assetlinks` (
  `id` INT {$primaryKeySign} NOT NULL AUTO_INCREMENT,
  `itemtype` VARCHAR(100) NOT NULL,
  `items_id` INT {$primaryKeySign} NOT NULL DEFAULT '0',
  `glpi_b_connection_id` VARCHAR(255) NOT NULL,
  `route_id` VARCHAR(255) NOT NULL DEFAULT '',
  `remote_items_id` INT {$primaryKeySign} DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'synced',
  `last_payload_hash` CHAR(64) NOT NULL DEFAULT '',
  `last_payload_date` TIMESTAMP NULL DEFAULT NULL,
  `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
  `blocked_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error` TEXT DEFAULT NULL,
  `date_creation` TIMESTAMP NULL DEFAULT NULL,
  `date_mod` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_connection` (`itemtype`,`items_id`,`glpi_b_connection_id`),
  KEY `connection_status` (`glpi_b_connection_id`,`status`),
  KEY `remote_lookup` (`glpi_b_connection_id`,`itemtype`,`remote_items_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
SQL;
    }

    private static function nullablePositiveInt($value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private static function nullableDate($value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private static function nullableText($value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private static function now(): \Glpi\DBAL\QueryExpression
    {
        return new \Glpi\DBAL\QueryExpression('NOW()');
    }
}
