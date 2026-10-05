<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncQueue
{
    public const TABLE = 'glpi_plugin_assetsync20_syncqueue';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_RETRY = 'retry';
    public const STATUS_DONE = 'done';
    public const STATUS_BLOCKED = 'blocked';

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

    public static function enqueue(
        string $itemtype,
        int $itemsId,
        string $connectionId,
        string $routeId,
        string $payloadHash,
        ?string $payloadDate,
        ?int $remoteItemsId = null
    ): bool {
        $db = self::db();
        if ($db === null) {
            return true;
        }

        if ($itemtype === '' || $itemsId <= 0 || $connectionId === '') {
            return false;
        }

        $existing = self::findForAsset($itemtype, $itemsId, $connectionId);
        if ($existing !== null && (string) ($existing['status'] ?? '') === self::STATUS_RUNNING) {
            return false;
        }

        if (
            $existing !== null
            && in_array((string) ($existing['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_RETRY], true)
            && (string) ($existing['route_id'] ?? '') === $routeId
            && (string) ($existing['payload_hash'] ?? '') === $payloadHash
        ) {
            return false;
        }

        $fields = [
            'route_id'             => $routeId,
            'remote_items_id'      => self::nullablePositiveInt($remoteItemsId),
            'payload_hash'         => $payloadHash,
            'payload_date'         => self::nullableDate($payloadDate),
            'status'               => self::STATUS_PENDING,
            'attempts'             => 0,
            'available_at'         => null,
            'started_at'           => null,
            'finished_at'          => null,
            'last_error'           => null,
            'date_mod'             => self::now(),
        ];

        if ($existing === null) {
            if (!method_exists($db, 'insert')) {
                return false;
            }

            $fields['itemtype'] = $itemtype;
            $fields['items_id'] = $itemsId;
            $fields['glpi_b_connection_id'] = $connectionId;
            $fields['date_creation'] = self::now();

            return AssetSyncDbTime::write($db, static fn (): bool => $db->insert(self::TABLE, $fields));
        }

        if (!method_exists($db, 'update') || !method_exists($db, 'affectedRows')) {
            return false;
        }

        $affectedRows = 0;
        $updated = AssetSyncDbTime::write($db, static function () use ($db, $fields, $existing, &$affectedRows): bool {
            // Do not replace preparation that another worker has already claimed or changed.
            $written = $db->update(self::TABLE, $fields, [
                'id'           => (int) $existing['id'],
                'status'       => (string) ($existing['status'] ?? ''),
                'attempts'     => (int) ($existing['attempts'] ?? 0),
                'route_id'     => (string) ($existing['route_id'] ?? ''),
                'payload_hash' => (string) ($existing['payload_hash'] ?? ''),
            ]);
            if ($written) {
                $affectedRows = $db->affectedRows();
            }
            return $written;
        });
        return $updated && $affectedRows === 1;
    }

    /** @return list<string> */
    public static function dueConnectionIds(): array
    {
        $db = self::db();
        if ($db === null || !method_exists($db, 'request')) {
            return [];
        }
        $rows = $db->request([
            'SELECT' => ['glpi_b_connection_id'],
            'FROM' => self::TABLE,
            'WHERE' => [new \Glpi\DBAL\QueryExpression(self::dueSql(time()))],
            'GROUPBY' => 'glpi_b_connection_id',
            'ORDER' => new \Glpi\DBAL\QueryExpression('CAST(glpi_b_connection_id AS BINARY) ASC'),
        ]);
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['glpi_b_connection_id'])) {
                $ids[] = (string) $row['glpi_b_connection_id'];
            }
        }
        return $ids;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function claimDue(int $limit, ?string $connectionId = null): array
    {
        $db = self::db();
        if ($db === null || !method_exists($db, 'request') || !method_exists($db, 'update') || !method_exists($db, 'affectedRows')) {
            return [];
        }

        $limit = max(1, $limit);
        $now = time();
        $where = [new \Glpi\DBAL\QueryExpression(self::dueSql($now))];
        if ($connectionId !== null) {
            $where['glpi_b_connection_id'] = $connectionId;
        }
        $rows = $db->request([
            'SELECT' => [
                '*',
                new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`available_at`)', 'available_epoch'),
                new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`date_mod`)', 'modified_epoch'),
                new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`finished_at`)', 'finished_epoch'),
                new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`payload_date`)', 'payload_epoch'),
                new \Glpi\DBAL\QueryExpression('UNIX_TIMESTAMP(`started_at`)', 'started_epoch'),
            ],
            'FROM'  => self::TABLE,
            'WHERE' => $where,
            'ORDER' => 'id ASC',
            'LIMIT' => max(50, $limit * 5),
        ]);

        $claimed = [];

        foreach ($rows as $row) {
            if (!is_array($row) || count($claimed) >= $limit || !self::rowIsDue($row, $now)) {
                continue;
            }

            $attempts = (int) ($row['attempts'] ?? 0) + 1;
            $affectedRows = 0;
            $updated = AssetSyncDbTime::write($db, static function () use ($db, $row, $attempts, &$affectedRows): bool {
                $written = $db->update(self::TABLE, [
                    'status'      => self::STATUS_RUNNING,
                    'attempts'    => $attempts,
                    'started_at'  => self::now(),
                    'finished_at' => null,
                    'date_mod'    => self::now(),
                ], [
                    'id'           => (int) $row['id'],
                    'status'       => (string) $row['status'],
                    'attempts'     => (int) ($row['attempts'] ?? 0),
                    'route_id'     => (string) ($row['route_id'] ?? ''),
                    'payload_hash' => (string) ($row['payload_hash'] ?? ''),
                    new \Glpi\DBAL\QueryExpression(self::dueSql(time())),
                ]);
                if ($written) {
                    $affectedRows = $db->affectedRows();
                }

                return $written;
            });
            if (!$updated || $affectedRows !== 1) {
                continue;
            }

            $row['status'] = self::STATUS_RUNNING;
            $row['attempts'] = $attempts;
            $startedRows = $db->request([
                'SELECT' => ['started_at'],
                'FROM' => self::TABLE,
                'WHERE' => ['id' => (int) $row['id']],
                'LIMIT' => 1,
            ]);
            foreach ($startedRows as $startedRow) {
                $row['started_at'] = $startedRow['started_at'] ?? null;
                break;
            }
            $claimed[] = $row;
        }

        return $claimed;
    }

    /**
     * Ages describe the current enqueue, retry eligibility, or running attempt.
     * @return array<string,mixed>
     */
    public static function metricsSnapshot(): array
    {
        $unavailable = ['available' => false];
        $db = self::db();
        if ($db === null || !method_exists($db, 'doQuery') || !method_exists($db, 'fetchAssoc')) {
            return $unavailable;
        }

        $now = time();
        $due = self::dueSql($now);
        $backoff = 'LEAST(3600, 60 * POW(2, LEAST(5, GREATEST(0, `attempts` - 1))))';
        // Convert each TIMESTAMP to an epoch before fallback or aggregation.
        $pending = 'COALESCE(NULLIF(UNIX_TIMESTAMP(`date_mod`), 0), UNIX_TIMESTAMP(`date_creation`))';
        $finished = 'COALESCE(UNIX_TIMESTAMP(`finished_at`), 0)';
        $retry = "CASE WHEN {$finished} > 0 THEN {$finished} + {$backoff} ELSE UNIX_TIMESTAMP(`available_at`) END";
        $running = 'COALESCE(NULLIF(UNIX_TIMESTAMP(`started_at`), 0), UNIX_TIMESTAMP(`date_mod`))';
        $sql = <<<SQL
SELECT category, COUNT(*) AS count,
       MAX(CASE WHEN age_epoch > 0 AND age_epoch <= {$now} THEN {$now} - age_epoch END) AS oldest_age_s,
       SUM(CASE WHEN category IN ('pending', 'retry_due', 'running_reclaimable', 'running_active')
                    AND (age_epoch IS NULL OR age_epoch <= 0 OR age_epoch > {$now}) THEN 1 ELSE 0 END) AS unknown_age
FROM (
    SELECT CASE
               WHEN `status` = 'pending' THEN 'pending'
               WHEN `status` = 'retry' AND {$due} THEN 'retry_due'
               WHEN `status` = 'retry' THEN 'retry_waiting'
               WHEN `status` = 'running' AND {$due} THEN 'running_reclaimable'
               WHEN `status` = 'running' THEN 'running_active'
               ELSE 'blocked'
           END AS category,
           CASE `status`
               WHEN 'pending' THEN {$pending}
               WHEN 'retry' THEN {$retry}
               WHEN 'running' THEN {$running}
           END AS age_epoch
    FROM `glpi_plugin_assetsync20_syncqueue`
    WHERE `status` IN ('pending', 'retry', 'running', 'blocked')
) AS queue_metrics
GROUP BY category
SQL;

        try {
            $result = $db->doQuery($sql);
            if ($result === false) {
                return $unavailable;
            }
            $summary = ['available' => true];
            $ageKeys = [
                'pending' => 'oldest_wait_s',
                'retry_due' => 'oldest_overdue_s',
                'retry_waiting' => null,
                'running_reclaimable' => 'oldest_job_age_s',
                'running_active' => 'oldest_job_age_s',
                'blocked' => null,
            ];
            foreach ($ageKeys as $category => $ageKey) {
                $summary[$category] = ['count' => 0];
                if ($ageKey !== null) {
                    $summary[$category][$ageKey] = null;
                    $summary[$category]['unknown_age'] = 0;
                }
            }
            while ($row = $db->fetchAssoc($result)) {
                $category = (string) ($row['category'] ?? '');
                if (!array_key_exists($category, $ageKeys)) {
                    return $unavailable;
                }
                $summary[$category]['count'] = (int) $row['count'];
                $ageKey = $ageKeys[$category];
                if ($ageKey !== null) {
                    $summary[$category][$ageKey] = $row['oldest_age_s'] === null ? null : (int) $row['oldest_age_s'];
                    $summary[$category]['unknown_age'] = (int) $row['unknown_age'];
                }
            }
            return $summary;
        } catch (\Throwable) {
            return $unavailable;
        }
    }

    public static function finish(int $id, string $message = '', ?int $remoteItemsId = null): bool
    {
        return self::updateStatus($id, self::STATUS_DONE, null, $message, $remoteItemsId);
    }

    public static function block(int $id, string $message, ?int $remoteItemsId = null): bool
    {
        return self::updateStatus($id, self::STATUS_BLOCKED, null, $message, $remoteItemsId);
    }

    public static function retry(int $id, int $attempts, string $message): bool
    {
        $backoffSeconds = self::backoffSeconds($attempts);
        $availableAt = new \Glpi\DBAL\QueryExpression('DATE_ADD(NOW(), INTERVAL ' . $backoffSeconds . ' SECOND)');

        return self::updateStatus($id, self::STATUS_RETRY, $availableAt, $message, null);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findForAsset(string $itemtype, int $itemsId, string $connectionId): ?array
    {
        $db = self::db();
        if ($db === null || !method_exists($db, 'request')) {
            return null;
        }

        $rows = $db->request([
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

    private static function updateStatus(int $id, string $status, ?\Glpi\DBAL\QueryExpression $availableAt, string $message, ?int $remoteItemsId): bool
    {
        $db = self::db();
        if ($db === null) {
            return true;
        }

        if (!method_exists($db, 'update')) {
            return false;
        }

        $fields = [
            'status'       => $status,
            'available_at' => $availableAt,
            'finished_at'  => self::now(),
            'last_error'   => trim($message) !== '' ? trim($message) : null,
            'date_mod'     => self::now(),
        ];

        if ($remoteItemsId !== null && $remoteItemsId > 0) {
            $fields['remote_items_id'] = $remoteItemsId;
        }

        return AssetSyncDbTime::write($db, static fn (): bool => $db->update(self::TABLE, $fields, ['id' => $id]));
    }

    /**
     * @return object|null
     */
    private static function db(): ?object
    {
        return isset($GLOBALS['DB']) && is_object($GLOBALS['DB']) ? $GLOBALS['DB'] : null;
    }

    private static function rowIsDue(array $row, int $now): bool
    {
        $status = (string) ($row['status'] ?? '');
        if ($status === self::STATUS_PENDING) {
            return true;
        }

        if ($status === self::STATUS_RETRY) {
            $finishedAt = (int) ($row['finished_epoch'] ?? 0);
            if ($finishedAt > 0) {
                return $finishedAt > $now + 60
                    || $finishedAt + self::backoffSeconds((int) ($row['attempts'] ?? 1)) <= $now;
            }

            $availableAt = (int) ($row['available_epoch'] ?? 0);

            return $availableAt <= $now || $availableAt > $now + self::backoffSeconds((int) ($row['attempts'] ?? 1)) + 60;
        }

        if ($status === self::STATUS_RUNNING) {
            $startedAt = (int) ($row['started_epoch'] ?? 0);
            if ($startedAt <= 0) {
                $modifiedAt = (int) ($row['modified_epoch'] ?? 0);

                return $modifiedAt > 0 && $modifiedAt <= $now - 1800;
            }

            return $startedAt <= $now - 1800 || $startedAt > $now + 60;
        }

        return false;
    }

    private static function dueSql(int $now): string
    {
        $backoff = 'LEAST(3600, 60 * POW(2, LEAST(5, GREATEST(0, `attempts` - 1))))';
        $finished = 'COALESCE(UNIX_TIMESTAMP(`finished_at`), 0)';
        $available = 'COALESCE(UNIX_TIMESTAMP(`available_at`), 0)';
        $started = 'COALESCE(UNIX_TIMESTAMP(`started_at`), 0)';
        $modified = 'COALESCE(UNIX_TIMESTAMP(`date_mod`), 0)';
        $legacyFuture = $now + 60;
        $staleBefore = $now - 1800;

        return <<<SQL
(
  `status` = 'pending'
  OR (
    `status` = 'retry'
    AND (
      ({$finished} > 0 AND ({$finished} > {$legacyFuture} OR {$finished} + {$backoff} <= {$now}))
      OR ({$finished} <= 0 AND ({$available} <= {$now} OR {$available} > {$now} + {$backoff} + 60))
    )
  )
  OR (
    `status` = 'running'
    AND (
      ({$started} <= 0 AND {$modified} > 0 AND {$modified} <= {$staleBefore})
      OR ({$started} > 0 AND ({$started} <= {$staleBefore} OR {$started} > {$legacyFuture}))
    )
  )
)
SQL;
    }

    private static function backoffSeconds(int $attempts): int
    {
        return min(3600, 60 * (2 ** min(5, max(0, $attempts - 1))));
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
CREATE TABLE IF NOT EXISTS `glpi_plugin_assetsync20_syncqueue` (
  `id` INT {$primaryKeySign} NOT NULL AUTO_INCREMENT,
  `itemtype` VARCHAR(100) NOT NULL,
  `items_id` INT {$primaryKeySign} NOT NULL DEFAULT '0',
  `glpi_b_connection_id` VARCHAR(255) NOT NULL,
  `route_id` VARCHAR(255) NOT NULL DEFAULT '',
  `remote_items_id` INT {$primaryKeySign} DEFAULT NULL,
  `payload_hash` CHAR(64) NOT NULL DEFAULT '',
  `payload_date` TIMESTAMP NULL DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `attempts` INT {$primaryKeySign} NOT NULL DEFAULT '0',
  `available_at` TIMESTAMP NULL DEFAULT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `finished_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error` TEXT DEFAULT NULL,
  `date_creation` TIMESTAMP NULL DEFAULT NULL,
  `date_mod` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_connection` (`itemtype`,`items_id`,`glpi_b_connection_id`),
  KEY `queue_due` (`status`,`available_at`,`id`),
  KEY `route_lookup` (`route_id`),
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

    private static function now(): \Glpi\DBAL\QueryExpression
    {
        return new \Glpi\DBAL\QueryExpression('NOW()');
    }
}
