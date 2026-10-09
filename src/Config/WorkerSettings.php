<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20\Config;

final class WorkerSettings
{
    private const CONTEXT = 'plugin:assetsync20';
    private const INTERVAL_KEY = 'worker_interval_seconds';
    private const DEFAULT_INTERVAL = 10;
    private const MIN_INTERVAL = 10;
    private const MAX_INTERVAL = 3600;

    public static function getInterval(): int
    {
        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'getConfigurationValues')
        ) {
            return self::DEFAULT_INTERVAL;
        }

        $values = \Config::getConfigurationValues(
            self::CONTEXT,
            [self::INTERVAL_KEY]
        );

        $interval = (int) ($values[self::INTERVAL_KEY] ?? self::DEFAULT_INTERVAL);

        return max(
            self::MIN_INTERVAL,
            min(self::MAX_INTERVAL, $interval)
        );
    }

    public static function saveInterval(int $interval): void
    {
        if ($interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL) {
            throw new \InvalidArgumentException(
                'Worker interval must be between 10 and 3600 seconds.'
            );
        }

        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'setConfigurationValues')
        ) {
            throw new \RuntimeException(
                'GLPI configuration storage is unavailable.'
            );
        }

        \Config::setConfigurationValues(self::CONTEXT, [
            self::INTERVAL_KEY => (string) $interval,
        ]);
    }
}
