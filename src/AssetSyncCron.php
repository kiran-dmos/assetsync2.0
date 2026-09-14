<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetSyncCron
{
    public const TASK_NAME = 'assetsync20_sync';
    private const DEFAULT_BATCH_SIZE = 10;
    private const MAX_BATCH_SIZE = 100;
    private const TIME_LIMIT_SECONDS = 25;

    /**
     * @return array<string,string>
     */
    public static function cronInfo(string $name): array
    {
        if ($name !== self::TASK_NAME) {
            return [];
        }

        return [
            'description' => 'Queue and process AssetSync2.0 GLPI A to GLPI B asset synchronization.',
            'parameter'   => 'Maximum assets to queue and process per run.',
        ];
    }

    public static function cronassetsync20_sync(\CronTask $task): int
    {
        $batchSize = self::batchSize($task);
        $count = (new AssetSyncService())->run($batchSize, self::TIME_LIMIT_SECONDS, self::manualForceRequested());

        if (method_exists($task, 'addVolume')) {
            $task->addVolume($count);
        }

        return $count > 0 ? 1 : 0;
    }

    public static function register(): void
    {
        if (!class_exists('\CronTask') || !method_exists('\CronTask', 'register')) {
            return;
        }

        \CronTask::register(self::class, self::TASK_NAME, 300, [
            'state'   => \CronTask::STATE_WAITING,
            'param'   => self::DEFAULT_BATCH_SIZE,
            'comment' => 'Queues and synchronizes AssetSync2.0 assets in small cron batches.',
        ]);
    }

    public static function unregister(): void
    {
        if (class_exists('\CronTask') && method_exists('\CronTask', 'unregister')) {
            \CronTask::unregister('Assetsync20');
        }
    }

    private static function batchSize(\CronTask $task): int
    {
        $value = (int) ($task->fields['param'] ?? 0);
        if ($value <= 0) {
            return self::DEFAULT_BATCH_SIZE;
        }

        return min(self::MAX_BATCH_SIZE, $value);
    }

    private static function manualForceRequested(): bool
    {
        return self::taskFormForceRequested() || self::cliTaskForceRequested();
    }

    private static function taskFormForceRequested(): bool
    {
        $execute = $_POST['execute'] ?? null;

        return is_scalar($execute) && !is_numeric($execute) && (string) $execute === self::TASK_NAME;
    }

    private static function cliTaskForceRequested(): bool
    {
        if (PHP_SAPI !== 'cli' || !isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            return false;
        }

        $force = false;
        foreach (array_slice($_SERVER['argv'], 1) as $argument) {
            if (!is_scalar($argument)) {
                continue;
            }

            $argument = (string) $argument;
            if ($argument === '--force') {
                $force = true;
                continue;
            }

            if (is_numeric($argument)) {
                return false;
            }

            if ($argument === self::TASK_NAME) {
                return $force;
            }
        }

        return false;
    }
}
