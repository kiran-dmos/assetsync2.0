<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    if (($argv[1] ?? '') === 'wrong') {
        final class Worker {
            public const WAKE_VERSION = 0;
            public static int $calls = 0;
            public function run(): int { self::$calls++; return 0; }
        }
    } else {
        final class Worker {
            public static int $calls = 0;
            public function run(): int { self::$calls++; return 0; }
        }
    }
}

namespace {
    require __DIR__ . '/../assetsync20agent/src/Wake.php';
    $rejected = false;
    try { \GlpiPlugin\Assetsync20agent\Wake::receive([], ''); }
    catch (RuntimeException $error) {
        $rejected = $error->getMessage() === 'HTTPS wake requires the upgraded worker.';
    }
    if (!$rejected || \GlpiPlugin\Assetsync20agent\Worker::$calls !== 0) {
        throw new RuntimeException('A mixed cached worker must be rejected before settings, admission or execution.');
    }
    echo 'Wake upgrade compatibility passed (' . (($argv[1] ?? '') === 'wrong' ? 'wrong' : 'missing') . " marker).\n";
}
