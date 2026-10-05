<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function time(): int
    {
        return \QueueMetricsClock::$now;
    }
}

namespace {
    require_once __DIR__ . '/../src/autoload.php';

    use GlpiPlugin\Assetsync20\AssetSyncQueue;

    if (!extension_loaded('pdo_sqlite')) {
        echo "Queue metrics test skipped (PDO SQLite unavailable).\n";
        exit(0);
    }

    final class QueueMetricsClock
    {
        public static int $now;
    }

    final class QueueMetricsDB
    {
        public PDO $pdo;
        public array $queries = [];
        public bool $fail = false;
        public bool $throw = false;

        public function __construct()
        {
            $this->pdo = new PDO('sqlite::memory:');
            // Native TIMESTAMP epochs stay the same when their display timezone changes.
            $this->pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($value): ?int => $value === null ? null : (int) $value, 1);
            $this->pdo->sqliteCreateFunction('LEAST', static fn (...$values): int => (int) min($values));
            $this->pdo->sqliteCreateFunction('GREATEST', static fn (...$values): int => (int) max($values));
            $this->pdo->sqliteCreateFunction('POW', static fn ($base, $power): float => pow((float) $base, (float) $power), 2);
            $this->pdo->exec('CREATE TABLE ' . AssetSyncQueue::TABLE . ' (id INTEGER, status TEXT, attempts INTEGER, finished_at INTEGER, available_at INTEGER, started_at INTEGER, date_mod INTEGER, date_creation INTEGER)');
        }

        public function doQuery(string $sql)
        {
            $this->queries[] = $sql;
            if ($this->throw) {
                throw new RuntimeException('private database error');
            }
            return $this->fail ? false : $this->pdo->query($sql);
        }

        public function fetchAssoc(PDOStatement $result): ?array
        {
            return $result->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    function queueMetricsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $previousTimezone = date_default_timezone_get();
    $previousDb = $GLOBALS['DB'] ?? null;
    $now = strtotime('2026-11-01 06:00:30 UTC');
    QueueMetricsClock::$now = $now;
    $db = new QueueMetricsDB();
    $GLOBALS['DB'] = $db;
    $insert = $db->pdo->prepare('INSERT INTO ' . AssetSyncQueue::TABLE . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    for ($id = 1; $id <= 45; $id++) {
        $insert->execute([$id, 'retry', 1, $now, $now + 60, null, $now, $now - 900000]);
    }
    foreach ([
        [51, 'pending', 0, null, null, null, $now - 20, $now - 900000],
        [52, 'retry', 2, $now - 300, $now + 999, null, $now - 300, null],
        [53, 'retry', 1, $now + 3600, $now - 9000, null, $now, null],
        [54, 'retry', 1, null, $now - 25, null, null, null],
        [55, 'retry', 1, null, $now + 3600, null, null, null],
        [56, 'retry', 1, null, null, null, null, null],
        [57, 'running', 1, null, null, $now - 1800, $now, null],
        [58, 'running', 1, null, null, $now + 61, $now, null],
        [59, 'running', 1, null, null, null, $now - 1801, null],
        [60, 'running', 1, null, null, $now - 100, $now, null],
        [61, 'running', 1, null, null, null, null, null],
        [62, 'blocked', 1, null, null, null, $now - 50000, null],
        [63, 'done', 1, null, null, null, $now - 900000, $now - 900000],
        [64, 'running', 1, null, null, null, $now + 3600, null],
        [65, 'pending', 0, null, null, null, null, $now - 30],
        [66, 'pending', 0, null, null, null, $now + 3600, $now - 900000],
        [67, 'pending', 0, null, null, null, null, null],
        [68, 'retry', 1, $now - 60, $now + 3600, null, null, null],
        [69, 'retry', 1, $now - 59, $now - 3600, null, null, null],
        [70, 'retry', 1, $now + 60, $now - 3600, null, null, null],
        [71, 'running', 1, null, null, $now + 60, null, null],
        [72, 'retry', 99, $now - 1921, null, null, null, null],
        [73, 'retry', 1, null, 0, null, null, null],
    ] as $row) {
        $insert->execute($row);
    }

    try {
        $due = (new ReflectionMethod(AssetSyncQueue::class, 'dueSql'))->invoke(null, $now);
        $ids = $db->pdo->query('SELECT id FROM ' . AssetSyncQueue::TABLE . ' WHERE ' . $due . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        queueMetricsCheck(array_map('intval', $ids) === [51, 52, 53, 54, 55, 56, 57, 58, 59, 65, 66, 67, 68, 72, 73], 'Independent expected SQL ids must preserve backoff, missing dates, stale and future boundaries.');

        foreach (['UTC', 'Asia/Brunei', 'America/New_York'] as $timezone) {
            date_default_timezone_set($timezone);
            $db->queries = [];
            $summary = AssetSyncQueue::metricsSnapshot();
            queueMetricsCheck(count($db->queries) === 1 && $summary['available'], 'Each snapshot must use one aggregate query.');
            queueMetricsCheck(str_contains($db->queries[0], "WHERE `status` IN ('pending', 'retry', 'running', 'blocked')"), 'The query must exclude historical done rows with a status filter.');
            queueMetricsCheck($summary['pending'] === ['count' => 4, 'oldest_wait_s' => 30, 'unknown_age' => 2], 'Pending age must use the current enqueue and disclose missing/future dates.');
            queueMetricsCheck($summary['retry_due'] === ['count' => 8, 'oldest_overdue_s' => 180, 'unknown_age' => 4], 'Retry overdue must start at finished plus backoff, ignoring available when finished exists.');
            queueMetricsCheck($summary['retry_waiting'] === ['count' => 47], 'Delayed retry classification must use the exact existing due predicate.');
            queueMetricsCheck($summary['running_reclaimable'] === ['count' => 3, 'oldest_job_age_s' => 1801, 'unknown_age' => 1], 'Reclaimable running jobs must preserve stale and future-legacy rules.');
            queueMetricsCheck($summary['running_active'] === ['count' => 4, 'oldest_job_age_s' => 100, 'unknown_age' => 3], 'Missing/future running ages must not be reported as zero.');
            queueMetricsCheck($summary['blocked'] === ['count' => 1], 'Blocked counts must not include done history.');
        }

        // Two running attempts on opposite sides of the repeated local hour.
        $db->pdo->exec('DELETE FROM ' . AssetSyncQueue::TABLE);
        $insert->execute([1, 'running', 1, null, null, $now - 60, $now, null]);
        $insert->execute([2, 'running', 1, null, null, $now - 3660, $now, null]);
        $fold = AssetSyncQueue::metricsSnapshot();
        queueMetricsCheck($fold['running_active']['oldest_job_age_s'] === 60 && $fold['running_reclaimable']['oldest_job_age_s'] === 3660, 'Epoch aggregation must distinguish attempts during the DST fold.');

        $db->fail = true;
        queueMetricsCheck(AssetSyncQueue::metricsSnapshot() === ['available' => false], 'Failed queries must report unavailable.');
        $db->fail = false;
        $db->throw = true;
        queueMetricsCheck(AssetSyncQueue::metricsSnapshot() === ['available' => false], 'Query exceptions must report unavailable without private errors.');
        unset($GLOBALS['DB']);
        queueMetricsCheck(AssetSyncQueue::metricsSnapshot() === ['available' => false], 'Missing databases must report unavailable.');
    } finally {
        date_default_timezone_set($previousTimezone);
        $GLOBALS['DB'] = $previousDb;
    }

    echo "Queue metrics tests passed.\n";
}
