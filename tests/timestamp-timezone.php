<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/query-expression.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\AssetSyncDbTime;

final class TimestampTestDB
{
    public array $rows = [];
    public string $timezone = 'UTC';
    public bool $failUtcSwitch = false;
    public bool $failRestore = false;

    public function doQuery(string $sql)
    {
        if ($sql === 'SELECT @@SESSION.time_zone AS session_timezone') {
            return [['session_timezone' => $this->timezone]];
        }
        if (preg_match("/^SET SESSION time_zone = '([^']+)'$/", $sql, $match)) {
            if (($match[1] === '+00:00' && $this->failUtcSwitch) || ($match[1] !== '+00:00' && $this->failRestore)) {
                return false;
            }
            $previous = new DateTimeZone($this->timezone);
            $next = new DateTimeZone($match[1]);
            foreach ($this->rows as &$rows) {
                foreach ($rows as &$row) {
                    foreach (['date_creation', 'date_mod', 'last_sync_at', 'last_payload_date', 'blocked_at', 'payload_date', 'available_at', 'started_at', 'finished_at'] as $field) {
                        if (is_string($row[$field] ?? null) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row[$field])) {
                            $row[$field] = (new DateTimeImmutable($row[$field], $previous))->setTimezone($next)->format('Y-m-d H:i:s');
                        }
                    }
                }
                unset($row);
            }
            unset($rows);
            $this->timezone = $match[1];
            return true;
        }
        throw new RuntimeException('Unexpected SQL query: ' . $sql);
    }

    public function fetchAssoc(array $rows): ?array
    {
        return $rows[0] ?? null;
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function request(array $query): array
    {
        $rows = $this->rows[$query['FROM']] ?? [];
        foreach ($query['WHERE'] ?? [] as $field => $value) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row[$field] ?? null, (array) $value, true)));
        }

        return array_map(function (array $row) use ($query): array {
            $selected = $query['SELECT'] ?? ['*'];
            $result = in_array('*', $selected, true) ? $row : array_intersect_key($row, array_flip(array_filter($selected, 'is_string')));
            foreach ($selected as $column) {
                if (!$column instanceof \Glpi\DBAL\QueryExpression || !preg_match('/^UNIX_TIMESTAMP\(`(\w+)`\)$/', $column->expression, $matches)) {
                    continue;
                }
                $value = $row[$matches[1]] ?? null;
                $result[$column->alias] = $value === null ? null : (new DateTimeImmutable((string) $value, new DateTimeZone($this->timezone)))->getTimestamp();
            }
            return $result;
        }, $rows);
    }

    public function insert(string $table, array $fields): bool
    {
        $fields = $this->resolveExpressions($fields);
        $fields['id'] = count($this->rows[$table] ?? []) + 1;
        $this->rows[$table][] = $fields;

        return true;
    }

    public function update(string $table, array $fields, array $where): bool
    {
        $fields = $this->resolveExpressions($fields);
        foreach ($this->rows[$table] as &$row) {
            if ($row['id'] === $where['id']) {
                $row = array_merge($row, $fields);
            }
        }

        return true;
    }

    private function resolveExpressions(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (!$value instanceof \Glpi\DBAL\QueryExpression) {
                continue;
            }
            $time = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
            if (preg_match('/^DATE_ADD\(NOW\(\), INTERVAL (\d+) SECOND\)$/', $value->expression, $matches)) {
                $time = $time->modify('+' . $matches[1] . ' seconds');
            } elseif ($value->expression !== 'NOW()') {
                throw new RuntimeException('Unexpected SQL expression: ' . $value->expression);
            }
            $fields[$key] = $time->format('Y-m-d H:i:s');
        }
        return $fields;
    }
}

function checkRecent(string $value, int $expected, string $message): void
{
    if (abs(strtotime($value) - $expected) > 3) {
        throw new RuntimeException($message . ': ' . $value);
    }
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');
$GLOBALS['DB'] = new TimestampTestDB();
$GLOBALS['DB']->timezone = 'Asia/Brunei';

try {
    $started = time();
    AssetSyncLink::save([
        'itemtype' => 'Computer',
        'items_id' => 1,
        'glpi_b_connection_id' => 'test',
        'route_id' => 'test-route',
    ]);
    $link = AssetSyncLink::find('Computer', 1, 'test');
    checkRecent((string) $link['last_sync_at'] . ' +08:00', $started, 'Link sync time must use the DB session timezone');

    $needsCheck = new ReflectionMethod(AssetSyncService::class, 'linkedAssetNeedsRemoteCheck');
    $service = new AssetSyncService();
    $inbound = [['source_of_truth' => 'glpi_b']];
    if ($needsCheck->invoke($service, $link, $inbound)) {
        throw new RuntimeException('A recent link must not immediately queue another inbound check');
    }

    $missingEpoch = $link;
    unset($missingEpoch['last_sync_epoch']);
    if (!$needsCheck->invoke($service, $missingEpoch, $inbound)) {
        throw new RuntimeException('A missing DB epoch must trigger a recheck without parsing timestamp text');
    }

    $link['last_sync_epoch'] = $started + 8 * 3600;
    if (!$needsCheck->invoke($service, $link, $inbound)) {
        throw new RuntimeException('A future legacy link should get one recheck');
    }
    $GLOBALS['DB']->update(AssetSyncLink::TABLE, ['last_sync_at' => gmdate('Y-m-d H:i:s', $started)], ['id' => $link['id']]);
    if (!$needsCheck->invoke($service, AssetSyncLink::find('Computer', 1, 'test'), $inbound)) {
        throw new RuntimeException('A legacy UTC-text link must get a recheck');
    }

    AssetSyncQueue::enqueue('Computer', 1, 'test', 'test-route', 'hash', null);
    $queue = $GLOBALS['DB']->rows[AssetSyncQueue::TABLE][0];
    checkRecent((string) $queue['date_creation'] . ' +08:00', $started, 'Queue creation time must use the DB session timezone');
    AssetSyncQueue::retry((int) $queue['id'], 1, 'retry');
    $queue = $GLOBALS['DB']->rows[AssetSyncQueue::TABLE][0];
    checkRecent((string) $queue['available_at'] . ' +08:00', $started + 60, 'Retry must use the DB session timezone');
    if (AssetSyncQueue::enqueue('Computer', 1, 'test', 'test-route', 'hash', null)) {
        throw new RuntimeException('Backfill must not reset an unchanged retry');
    }
    if ($GLOBALS['DB']->rows[AssetSyncQueue::TABLE][0] !== $queue) {
        throw new RuntimeException('An unchanged retry must keep its attempts and backoff');
    }
    if (AssetSyncQueue::claimDue(1) !== []) {
        throw new RuntimeException('A retry must not be claimed before its backoff expires');
    }

    $GLOBALS['DB']->update(AssetSyncQueue::TABLE, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_at' => gmdate('Y-m-d H:i:s', $started - 1801 + 8 * 3600),
    ], ['id' => $queue['id']]);
    $claimed = AssetSyncQueue::claimDue(1);
    if (count($claimed) !== 1 || (int) $claimed[0]['id'] !== (int) $queue['id']) {
        throw new RuntimeException('A running job must become stale after 30 minutes');
    }
    checkRecent((string) $claimed[0]['started_at'] . ' +08:00', $started, 'Claim time must use the DB session timezone');

    $payloadDate = new ReflectionMethod(AssetSyncService::class, 'payloadDate');
    if ($payloadDate->invoke($service, []) !== '') {
        throw new RuntimeException('Missing payload date must not fabricate an asset edit time');
    }

    AssetSyncQueue::enqueue('Computer', 2, 'test', 'test-route', 'old-hash', null);
    $secondQueue = $GLOBALS['DB']->rows[AssetSyncQueue::TABLE][1];
    AssetSyncQueue::retry((int) $secondQueue['id'], 1, 'retry');
    if (!AssetSyncQueue::enqueue('Computer', 2, 'test', 'test-route', 'new-hash', null)) {
        throw new RuntimeException('A changed payload must replace a retry');
    }
    $secondQueue = $GLOBALS['DB']->rows[AssetSyncQueue::TABLE][1];
    if ($secondQueue['status'] !== AssetSyncQueue::STATUS_PENDING || $secondQueue['attempts'] !== 0 || $secondQueue['payload_hash'] !== 'new-hash') {
        throw new RuntimeException('A changed payload must get a fresh pending attempt');
    }

    $isDue = new ReflectionMethod(AssetSyncQueue::class, 'rowIsDue');
    $foldNow = strtotime('2026-11-01 01:00:40 -05:00');
    if (!$isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RETRY,
        'attempts' => 1,
        'finished_epoch' => strtotime('2026-11-01 01:59:30 -04:00'),
    ], $foldNow)) {
        throw new RuntimeException('Retry must expire by elapsed seconds across a DST fold');
    }
    if (!$isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_epoch' => strtotime('2026-11-01 01:25:00 -04:00'),
    ], $foldNow)) {
        throw new RuntimeException('Stale running job must recover across a DST fold');
    }
    if ($isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_epoch' => null,
        'modified_epoch' => $foldNow - 60,
    ], $foldNow) || $isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_epoch' => null,
        'modified_epoch' => null,
    ], $foldNow) || $isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_epoch' => null,
        'modified_epoch' => $foldNow + 8 * 3600,
    ], $foldNow) || !$isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_epoch' => null,
        'modified_epoch' => $foldNow - 1801,
    ], $foldNow)) {
        throw new RuntimeException('Missing start time must use the stale interval when modification time is known');
    }
    if (!$isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RETRY,
        'attempts' => 1,
        'finished_epoch' => time() + 8 * 3600,
    ], time()) || !$isDue->invoke(null, [
        'status' => AssetSyncQueue::STATUS_RUNNING,
        'started_epoch' => time() + 8 * 3600,
    ], time())) {
        throw new RuntimeException('Future legacy queue timestamps must not strand work');
    }

    AssetSyncQueue::finish((int) $queue['id']);
    AssetSyncQueue::finish((int) $secondQueue['id']);
    date_default_timezone_set('Asia/Brunei');
    $GLOBALS['DB']->timezone = 'UTC';
    AssetSyncLink::save([
        'itemtype' => 'Computer',
        'items_id' => 3,
        'glpi_b_connection_id' => 'test',
        'route_id' => 'test-route',
    ]);
    $webLink = AssetSyncLink::find('Computer', 3, 'test');
    checkRecent((string) $webLink['last_sync_at'] . ' +00:00', time(), 'Web PHP timezone must not shift DB timestamp writes');
    if ($needsCheck->invoke($service, $webLink, $inbound)) {
        throw new RuntimeException('Web PHP timezone must not cause an immediate inbound recheck');
    }
    AssetSyncQueue::enqueue('Computer', 3, 'test', 'test-route', 'web-hash', null);
    $webQueue = $GLOBALS['DB']->rows[AssetSyncQueue::TABLE][2];
    AssetSyncQueue::retry((int) $webQueue['id'], 1, 'retry');
    if (AssetSyncQueue::claimDue(1) !== []) {
        throw new RuntimeException('Web PHP timezone must not skip retry backoff');
    }
    try {
        AssetSyncDbTime::write($GLOBALS['DB'], static function (): bool {
            throw new RuntimeException('simulated write failure');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'simulated write failure') {
            throw $error;
        }
    }
    if ($GLOBALS['DB']->timezone !== 'UTC') {
        throw new RuntimeException('Failed writes must restore the database session timezone');
    }

    $failingDb = new TimestampTestDB();
    $failingDb->timezone = 'Asia/Brunei';
    $failingDb->failUtcSwitch = true;
    $writeRan = false;
    $result = AssetSyncDbTime::write($failingDb, static function () use (&$writeRan): bool {
        $writeRan = true;
        return true;
    });
    if ($result || $writeRan || $failingDb->timezone !== 'Asia/Brunei') {
        throw new RuntimeException('Failed UTC switch must prevent the write');
    }

    $failingDb->failUtcSwitch = false;
    $failingDb->failRestore = true;
    try {
        AssetSyncDbTime::write($failingDb, static fn (): bool => true);
        throw new RuntimeException('Failed timezone restoration was ignored');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Could not restore the database session timezone.') {
            throw $error;
        }
    }
} finally {
    date_default_timezone_set($previousTimezone);
    unset($GLOBALS['DB']);
}

echo "Timestamp timezone test passed.\n";
