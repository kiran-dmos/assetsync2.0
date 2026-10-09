<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use GlpiPlugin\Assetsync20\AssetSyncQueue;

if (!extension_loaded('pdo_sqlite')) {
    echo "Queue due SQL test skipped (PDO SQLite unavailable).\n";
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($value): ?int => $value === null ? null : (int) $value, 1);
$db->sqliteCreateFunction('LEAST', static fn (...$values): int => (int) min($values));
$db->sqliteCreateFunction('GREATEST', static fn (...$values): int => (int) max($values));
$db->sqliteCreateFunction('POW', static fn ($base, $power): float => pow((float) $base, (float) $power), 2);
$db->exec('CREATE TABLE jobs (id INTEGER, status TEXT, attempts INTEGER, finished_at INTEGER, available_at INTEGER, started_at INTEGER, date_mod INTEGER, needs_recheck INTEGER NOT NULL DEFAULT 0, agent_requested INTEGER NOT NULL DEFAULT 0, agent_completed INTEGER NOT NULL DEFAULT 0)');

$now = time();
$insert = $db->prepare('INSERT INTO jobs (id, status, attempts, finished_at, available_at, started_at, date_mod) VALUES (?, ?, ?, ?, ?, ?, ?)');
for ($id = 1; $id <= 50; $id++) {
    $insert->execute([$id, 'retry', 1, $now, $now + 60, null, null]);
}
foreach ([
    [51, 'pending', 0, null, null, null, null],
    [52, 'retry', 1, $now - 61, null, null, null],
    [53, 'retry', 1, $now + 3600, null, null, null],
    [54, 'retry', 1, null, $now - 1, null, null],
    [55, 'retry', 1, null, $now + 3600, null, null],
    [56, 'retry', 1, $now - 59, null, null, null],
    [57, 'running', 1, null, null, $now - 1801, null],
    [58, 'running', 1, null, null, $now + 61, null],
    [59, 'running', 1, null, null, null, $now - 1801],
    [60, 'running', 1, null, null, $now - 60, null],
    [61, 'running', 1, null, null, null, null],
    [62, 'retry', 1, null, null, null, null],
] as $row) {
    $insert->execute($row);
}

$db->exec("INSERT INTO jobs (id, status, attempts, needs_recheck) VALUES (63, 'done', 8, 1), (64, 'blocked', 9, 1), (65, 'done', 7, 0), (66, 'blocked', 7, 0)");
$db->exec('UPDATE jobs SET needs_recheck = 1 WHERE id IN (1, 60)');
$dueSql = (new ReflectionMethod(AssetSyncQueue::class, 'dueSql'))->invoke(null, $now);
$ids = $db->query('SELECT id FROM jobs WHERE ' . $dueSql . ' ORDER BY id ASC LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
if (array_map('intval', $ids) !== [51, 52, 53, 54, 55, 57, 58, 59, 62, 63, 64]) {
    throw new RuntimeException('SQL due filtering did not preserve queue retry, stale, or legacy-future rules.');
}

echo "Queue due SQL test passed.\n";
