<?php
declare(strict_types=1);

namespace Glpi\Console {
    class AbstractCommand { public const SUCCESS = 0; public const FAILURE = 1; }
}
namespace Symfony\Component\Console\Input {
    interface InputInterface { public function getOption(string $name): mixed; }
}
namespace Symfony\Component\Console\Output {
    interface OutputInterface { public function writeln(string $text): void; }
}
namespace GlpiPlugin\Assetsync20agent {
    class Worker {
        public function run(): int {
            return \LoopFixture::runCycle();
        }
    }
}
namespace GlpiPlugin\Assetsync20agent\Console {
    function hrtime(bool $number): int { return \LoopFixture::$now; }
    function date(string $format): string {
        \LoopFixture::$dateFormats[] = $format;
        return \date($format, \LoopFixture::$epoch);
    }
    function usleep(int $microseconds): void {
        \LoopFixture::sleep($microseconds);
    }
}
namespace GlpiPlugin\Assetsync20 {
    class AssetSyncService {
        public function run(int $batch, int $seconds, bool $force): int {
            if (($_SESSION['glpicronuserrunning'] ?? null) !== 'cron_assetsync20_sync') {
                throw new \LogicException('CLI synchronization requires its normal GLPI cron context.');
            }
            return \LoopFixture::runCycle();
        }
    }
}
namespace GlpiPlugin\Assetsync20\Config {
    class WorkerSettings {
        public static function getInterval(): int { return \LoopFixture::$interval; }
    }
}
namespace GlpiPlugin\Assetsync20\Console {
    function hrtime(bool $number): int { return \LoopFixture::$now; }
    function date(string $format): string {
        \LoopFixture::$dateFormats[] = $format;
        return \date($format, \LoopFixture::$epoch);
    }
    function usleep(int $microseconds): void {
        \LoopFixture::sleep($microseconds);
    }
}
namespace {
    class CycleObserved extends RuntimeException {}
    class LoopFixture {
        public static int $now = 0;
        public static int $elapsed = 0;
        public static int $calls = 0;
        public static int $sleep = 0;
        public static int $interval = 2;
        public static bool $failCycle = false;
        public static int $epoch = 1791870528;
        public static int $stopAfterCycles = 1;
        public static array $observedSessions = [];
        public static array $dateFormats = [];
        public static array $expectedSession = [];

        public static function runCycle(): int {
            self::$observedSessions[] = $_SESSION;
            self::$calls++;
            self::$now += self::$elapsed;
            $_SESSION['glpi_currenttime'] = 'changed_during_cycle';
            if (self::$failCycle) { throw new RuntimeException('Injected cycle failure.'); }
            return 1;
        }

        public static function sleep(int $microseconds): void {
            if ($_SESSION !== self::$expectedSession) {
                throw new RuntimeException('The caller session must be restored before loop sleep.');
            }
            self::$sleep = $microseconds;
            self::$now += $microseconds * 1000;
            self::$epoch += 60;
            if (self::$calls >= self::$stopAfterCycles) { throw new CycleObserved(); }
        }
    }
    class LoopInput implements Symfony\Component\Console\Input\InputInterface {
        public function __construct(private string $interval, private bool $loop = true) {}
        public function getOption(string $name): mixed {
            return match ($name) { 'loop' => $this->loop, 'interval' => $this->interval, default => false };
        }
    }
    class LoopOutput implements Symfony\Component\Console\Output\OutputInterface {
        public function writeln(string $text): void {}
    }
    require __DIR__ . '/../assetsync20agent/src/Console/WorkerCommand.php';
    require __DIR__ . '/../src/Console/WorkerCommand.php';
    $_SESSION = [];
    $checks = 0;
    foreach ([GlpiPlugin\Assetsync20agent\Console\WorkerCommand::class => 1,
        GlpiPlugin\Assetsync20\Console\WorkerCommand::class => 2] as $class => $interval) {
        foreach ([0, 100_000_000, 900_000_000] as $elapsed) {
            LoopFixture::$now = LoopFixture::$calls = LoopFixture::$sleep = 0;
            LoopFixture::$elapsed = $elapsed;
            LoopFixture::$interval = $interval;
            try {
                (new ReflectionMethod($class, 'execute'))->invoke(new $class(), new LoopInput((string) $interval), new LoopOutput());
                throw new LogicException('The continuous worker did not wait.');
            } catch (CycleObserved) {}
            if (LoopFixture::$calls !== 1 || LoopFixture::$sleep !== $interval * 1_000_000 - intdiv($elapsed, 1000)) {
                throw new RuntimeException('A healthy short cycle must wait until its configured next start.');
            }
            $checks++;
            if ($class === GlpiPlugin\Assetsync20\Console\WorkerCommand::class && isset($_SESSION['glpicronuserrunning'])) {
                throw new RuntimeException('The worker must restore the session context after each cycle.');
            }
        }
    }
    foreach ([false, true] as $fail) {
        foreach ([false, true] as $existing) {
            LoopFixture::$failCycle = $fail;
            unset($_SESSION['glpicronuserrunning']);
            if ($existing) { $_SESSION['glpicronuserrunning'] = 'prior_cron_context'; }
            $worker = new GlpiPlugin\Assetsync20\Console\WorkerCommand();
            $result = (new ReflectionMethod($worker, 'runOneCycle'))->invoke($worker, new LoopOutput());
            if ($result !== (int) $fail || array_key_exists('glpicronuserrunning', $_SESSION) !== $existing
                || ($existing && $_SESSION['glpicronuserrunning'] !== 'prior_cron_context')) {
                throw new RuntimeException('Success and failure must restore the prior cron context exactly.');
            }
            $checks++;
        }
    }
    $originalTimezone = date_default_timezone_get();
    try {
        foreach (['UTC', 'Asia/Brunei'] as $timezone) {
            date_default_timezone_set($timezone);
            foreach ([GlpiPlugin\Assetsync20agent\Console\WorkerCommand::class => 1,
                GlpiPlugin\Assetsync20\Console\WorkerCommand::class => 2] as $class => $interval) {
                foreach ([false, true] as $loop) {
                    foreach ([false, true] as $fail) {
                        foreach (['absent', 'old', 'null'] as $clockState) {
                            foreach (['absent', 'old', 'null'] as $cronState) {
                                $_SESSION = ['caller' => 'preserved'];
                                if ($clockState !== 'absent') {
                                    $_SESSION['glpi_currenttime'] = $clockState === 'null' ? null : '2000-01-01 00:00:00';
                                }
                                if ($cronState !== 'absent') {
                                    $_SESSION['glpicronuserrunning'] = $cronState === 'null' ? null : 'prior_cron_context';
                                }
                                LoopFixture::$expectedSession = $_SESSION;
                                LoopFixture::$now = LoopFixture::$calls = LoopFixture::$sleep = 0;
                                LoopFixture::$elapsed = 100_000_000;
                                LoopFixture::$interval = $interval;
                                LoopFixture::$failCycle = $fail;
                                LoopFixture::$epoch = 1791870528;
                                LoopFixture::$stopAfterCycles = 2;
                                LoopFixture::$observedSessions = LoopFixture::$dateFormats = [];
                                $expectedTimes = [date('Y-m-d H:i:s', LoopFixture::$epoch), date('Y-m-d H:i:s', LoopFixture::$epoch + 60)];
                                $worker = new $class();
                                $execute = new ReflectionMethod($class, 'execute');
                                $input = new LoopInput((string) $interval, $loop);
                                if ($loop) {
                                    try {
                                        $execute->invoke($worker, $input, new LoopOutput());
                                        throw new LogicException('The continuous worker did not wait.');
                                    } catch (CycleObserved) {}
                                    if (LoopFixture::$sleep !== $interval * 1_000_000 - 100_000) {
                                        throw new RuntimeException('Clock refresh must preserve cycle cadence on success and failure.');
                                    }
                                } else {
                                    for ($cycle = 0; $cycle < 2; $cycle++) {
                                        $result = $execute->invoke($worker, $input, new LoopOutput());
                                        if ($result !== (int) $fail || $_SESSION !== LoopFixture::$expectedSession) {
                                            throw new RuntimeException('One-off success and failure must restore absent, old and null clock/cron values.');
                                        }
                                        LoopFixture::$epoch += 60;
                                    }
                                }
                                if (LoopFixture::$calls !== 2 || LoopFixture::$dateFormats !== ['Y-m-d H:i:s', 'Y-m-d H:i:s']
                                    || array_column(LoopFixture::$observedSessions, 'glpi_currenttime') !== $expectedTimes
                                    || $_SESSION !== LoopFixture::$expectedSession || date_default_timezone_get() !== $timezone) {
                                    throw new RuntimeException('Each cycle must refresh the clock using the configured timezone and restore the caller session exactly.');
                                }
                                foreach (LoopFixture::$observedSessions as $observedSession) {
                                    $expectedSession = LoopFixture::$expectedSession;
                                    if ($class === GlpiPlugin\Assetsync20\Console\WorkerCommand::class) {
                                        $expectedSession['glpicronuserrunning'] = 'cron_assetsync20_sync';
                                    }
                                    $expectedSession['glpi_currenttime'] = $observedSession['glpi_currenttime'];
                                    if ($observedSession !== $expectedSession) {
                                        throw new RuntimeException('The cycle must preserve caller context and the A cron marker.');
                                    }
                                }
                                $checks++;
                            }
                        }
                    }
                }
            }
        }
    } finally {
        date_default_timezone_set($originalTimezone);
    }
    echo "Worker interval tests passed ($checks checks; simulated cycles, no database or transport).\n";
}
