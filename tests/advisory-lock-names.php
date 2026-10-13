<?php

declare(strict_types=1);

$smokeBootstrapOnly = true;
require __DIR__ . '/smoke.php';

use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\AssetSyncLockName;
use GlpiPlugin\Assetsync20\AssetSyncQueue;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\AssetUuidOperation;
use GlpiPlugin\Assetsync20\AssetUuidService;

$checks = 0;
function lockNameCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function lockNameDb(): FakeDB
{
    $db = new FakeDB();
    $GLOBALS['DB'] = $db;
    Config::$values = [];
    AssetSyncQueue::install();
    AssetSyncLink::install();
    AssetUuidOperation::install();
    $db->sqlQueries = [];
    return $db;
}
function lockNameQueries(FakeDB $db, string $function): array
{
    return array_values(array_filter($db->sqlQueries, static fn (string $sql): bool => str_contains($sql, $function . '(')));
}

// Each cleanup failure must stay fenced for the lifetime of its own process.
if (($argv[1] ?? '') === '--cleanup-failure') {
    $entrypoint = $argv[2]; $failure = $argv[3]; $original = ($argv[4] ?? '') === 'original';
    $db = lockNameDb();
    $db->queryResult = static function (string $sql) use ($entrypoint, $failure) {
        $prefix = $entrypoint === 'asset' ? "SELECT RELEASE_LOCK('as20:" : "SELECT RELEASE_LOCK('assetsync20:run:";
        if (!str_starts_with($sql, $prefix)) { return null; }
        return match ($failure) {
            'false' => false,
            'throws' => throw new RuntimeException('Release query failed'),
            'no_row' => [],
            'missing' => [[]],
            'null' => [['released' => null]],
            default => [['released' => 0]],
        };
    };
    if ($entrypoint === 'run' && $original) {
        $db->afterTableExists = static function (string $table): void {
            if ($table === \GlpiPlugin\Assetsync20\AgentTask::TABLE) { throw new RuntimeException('Original work error'); }
        };
    }
    $caught = null;
    try {
        if ($entrypoint === 'asset') {
            AssetUuidOperation::withAssetLock('Computer', 123, static function () use ($original): void {
                if ($original) { throw new RuntimeException('Original work error'); }
            });
        } else { (new AssetSyncService())->run(1); }
    } catch (RuntimeException $error) { $caught = $error; }
    lockNameCheck($caught !== null, 'Cleanup-only failure is surfaced.');
    if ($original) { lockNameCheck($caught->getMessage() === 'Original work error', 'Original exception survives failed cleanup.'); }
    lockNameCheck(AssetUuidOperation::hasAssetLocks(), 'Uncertain cleanup fences wakes.');
    if ($entrypoint === 'asset') {
        $locks = (new ReflectionProperty(AssetUuidOperation::class, 'assetLocks'))->getValue();
        lockNameCheck(isset($locks[hash('sha256', 'Computer:123')]), 'Failed release retains the owned asset entry.');
    }
    $beforeQueries = $db->sqlQueries; $worked = false;
    $db->queryResult = null; $db->afterTableExists = null;
    foreach (['same_asset', 'new_asset', 'next_cycle'] as $retry) {
        $rejected = false;
        try {
            if ($retry === 'next_cycle') { (new AssetSyncService())->run(1); }
            else { AssetUuidOperation::withAssetLock('Computer', $retry === 'same_asset' ? 123 : 456,
                static function () use (&$worked): void { $worked = true; }); }
        } catch (RuntimeException) { $rejected = true; }
        lockNameCheck($rejected && !$worked && $db->sqlQueries === $beforeQueries,
            'Latch rejects shortcuts and later cycles before SQL: ' . $retry);
    }
    echo "Cleanup failure checks passed ($checks).\n";
    exit;
}

foreach ([
    ['glpi', '41ece51526515624ff89973668497d00'],
    ['GLPI', 'd6d85810e9a0e36c0a729e64b455eee5'],
    [" tenant's-db ", 'b6cbb0c73657f2f9975839d4ec6414fc'],
    ['0', 'cfcd208495d565ef66e7dff9f98764da'],
    ["glpi_\xC3\xA9", '73cf1a03e39f63adc8414ea1253ddf35'],
] as [$database, $hash]) {
    $db = lockNameDb();
    $db->databaseName = $database;
    lockNameCheck(AssetSyncLockName::databaseHash($db) === $hash, 'Selected database bytes are hashed without trimming, case changes or connection-name guessing.');
    $metrics = (new AssetUuidService())->run(hrtime(true) + 10_000_000_000);
    $quotedRun = "'assetsync20:run:" . $hash . "'";
    lockNameCheck(lockNameQueries($db, 'GET_LOCK') === ['SELECT GET_LOCK(' . $quotedRun . ', 0) AS acquired'], 'UUID global name exactly matches the previous SQL MD5 name.');
    lockNameCheck(lockNameQueries($db, 'RELEASE_LOCK') === ['SELECT RELEASE_LOCK(' . $quotedRun . ') AS released'], 'UUID run releases the exact acquired name.');
    lockNameCheck($metrics['attempted'] === 0, 'Empty UUID phase remains empty.');

    $db->sqlQueries = [];
    $workCount = 0;
    $value = AssetUuidOperation::withAssetLock('Computer', 123, static function () use (&$workCount): string {
        $workCount++;
        return AssetUuidOperation::withAssetLock('Computer', 123, static function () use (&$workCount): string {
            $workCount++;
            return 'nested';
        });
    });
    $assetName = 'as20:' . substr($hash, 0, 8) . ':' . substr(hash('sha256', 'Computer:123'), 0, 40);
    lockNameCheck($value === 'nested' && $workCount === 2, 'Same-asset reentrant work still runs.');
    lockNameCheck(lockNameQueries($db, 'GET_LOCK') === ["SELECT GET_LOCK('" . $assetName . "', 0) AS acquired"], 'Asset name matches old prefix/hash truncation with one acquisition.');
    lockNameCheck(lockNameQueries($db, 'RELEASE_LOCK') === ["SELECT RELEASE_LOCK('" . $assetName . "') AS released"], 'Same-asset nesting releases only its outer acquisition.');
    lockNameCheck(count(array_filter($db->sqlQueries, static fn (string $sql): bool => $sql === 'SELECT DATABASE() AS database_name')) === 1, 'Same-asset nesting does not repeat database discovery.');
    lockNameCheck(strlen($assetName) === 54, 'Asset lock names stay below the MySQL name limit.');
}

// Model MySQL reentrant named locks while rejecting every SQL MD5 call, as on the deployment server.
$db = lockNameDb();
$depth = 0;
$maximumDepth = 0;
$db->queryResult = static function (string $sql) use (&$depth, &$maximumDepth) {
    if (preg_match('/\bMD5\s*\(/i', $sql)) {
        throw new RuntimeException('FUNCTION glpi.MD5 does not exist');
    }
    if (str_starts_with($sql, "SELECT GET_LOCK('assetsync20:run:")) {
        $depth++;
        $maximumDepth = max($maximumDepth, $depth);
        return [['acquired' => 1]];
    }
    if (str_starts_with($sql, "SELECT RELEASE_LOCK('assetsync20:run:")) {
        $depth--;
        return [['released' => 1]];
    }
    return null;
};
lockNameCheck((new AssetSyncService())->run(1) === 0, 'Ordinary run completes without SQL MD5 support.');
lockNameCheck($maximumDepth === 1 && $depth === 0, 'No due UUID work needs only the outer run lock, with balanced cleanup.');
$db->insert(AssetUuidOperation::TABLE, ['itemtype' => 'Computer', 'items_id' => 123, 'status' => 'pending',
    'attempts' => 0, 'next_attempt' => null, 'lease_until' => null, 'claim_token' => null, 'state_json' => null]);
lockNameCheck((new AssetSyncService())->run(1) === 0, 'Due UUID work can run under the ordinary scheduler without changing its return volume.');
lockNameCheck($maximumDepth === 2 && $depth === 0, 'Ordinary and nested UUID phases acquire the same reentrant global lock and balance releases.');
$runQueries = array_filter(lockNameQueries($db, 'GET_LOCK'), static fn (string $sql): bool => str_contains($sql, 'assetsync20:run:'));
lockNameCheck(count(array_unique($runQueries)) === 1, 'Both run entrypoints use exactly the same global lock literal.');
foreach ($db->sqlQueries as $sql) {
    lockNameCheck(!preg_match('/\bMD5\s*\(/i', $sql), 'No server-side hash is sent during ordinary/UUID work.');
}

foreach (['ordinary', 'uuid', 'asset'] as $entrypoint) {
    foreach (['query_failed', 'query_throws', 'no_row', 'missing_column', 'null', 'empty', 'non_string'] as $failure) {
        $db = lockNameDb();
        $db->queryResult = static function (string $sql) use ($failure) {
            if ($sql !== 'SELECT DATABASE() AS database_name') { return null; }
            return match ($failure) {
                'query_failed' => false,
                'query_throws' => throw new RuntimeException('Database discovery failed'),
                'no_row' => [],
                'missing_column' => [[]],
                'null' => [['database_name' => null]],
                'empty' => [['database_name' => '']],
                'non_string' => [['database_name' => 123]],
            };
        };
        $beforeConfig = Config::$values;
        $beforeTables = $db->tables;
        $worked = false;
        $threw = false;
        try {
            if ($entrypoint === 'ordinary') {
                lockNameCheck((new AssetSyncService())->run(1) === 0, 'Ordinary run returns zero when database scope cannot be resolved.');
            } elseif ($entrypoint === 'uuid') {
                (new AssetUuidService())->run(hrtime(true) + 10_000_000_000);
            } else {
                AssetUuidOperation::withAssetLock('Computer', 123, static function () use (&$worked): void { $worked = true; });
            }
        } catch (RuntimeException) {
            $threw = true;
        }
        lockNameCheck($entrypoint === 'ordinary' || $threw, 'Direct UUID and asset entrypoints fail closed on unavailable database scope.');
        lockNameCheck(!$worked && $db->tables === $beforeTables && Config::$values === $beforeConfig, 'Failed discovery must not run work, claim rows or advance cursors.');
        lockNameCheck(lockNameQueries($db, 'GET_LOCK') === [] && lockNameQueries($db, 'RELEASE_LOCK') === [], 'Failed discovery neither acquires a guessed lock nor releases an unowned one.');
    }
}

foreach (['uuid', 'asset'] as $entrypoint) {
    $db = lockNameDb();
    $db->queryResult = static fn (string $sql) => str_contains($sql, 'GET_LOCK(') ? [['acquired' => 0]] : null;
    $worked = false;
    try {
        if ($entrypoint === 'uuid') {
            lockNameCheck(!empty((new AssetUuidService())->run(hrtime(true) + 10_000_000_000)['busy']), 'Busy UUID global lock remains a no-work return.');
        } else {
            AssetUuidOperation::withAssetLock('Computer', 123, static function () use (&$worked): void { $worked = true; });
        }
    } catch (RuntimeException) {
    }
    lockNameCheck(!$worked && lockNameQueries($db, 'RELEASE_LOCK') === [], 'Busy lock is never released and protected work does not run.');
}

$db = lockNameDb();
try {
    AssetUuidOperation::withAssetLock('Computer', 123, static function () use ($db): void {
        $db->databaseName = 'different_database';
        throw new RuntimeException('Original work error');
    });
} catch (RuntimeException $error) {
    lockNameCheck($error->getMessage() === 'Original work error', 'Normal lock cleanup preserves the work exception.');
}
$assetName = 'as20:41ece515:' . substr(hash('sha256', 'Computer:123'), 0, 40);
lockNameCheck(lockNameQueries($db, 'RELEASE_LOCK') === ["SELECT RELEASE_LOCK('" . $assetName . "') AS released"], 'Release uses the captured name, even if the selected database changes during work.');

foreach (['AssetSyncService.php', 'AssetUuidService.php', 'AssetUuidOperation.php'] as $file) {
    lockNameCheck(!preg_match('/\bMD5\s*\(/i', file_get_contents(__DIR__ . '/../src/' . $file)), 'Runtime lock call sites contain no SQL MD5: ' . $file);
}
foreach (['asset', 'run'] as $entrypoint) {
    foreach (['false', 'throws', 'no_row', 'missing', 'null', 'zero'] as $failure) {
        foreach (['clean', 'original'] as $work) {
            $process = proc_open([PHP_BINARY, __FILE__, '--cleanup-failure', $entrypoint, $failure, $work],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            lockNameCheck(proc_close($process) === 0, 'Isolated cleanup case ' . "$entrypoint/$failure/$work: " . $output . $error);
        }
    }
}
echo 'Advisory lock name tests passed (' . $checks . " checks).\n";
