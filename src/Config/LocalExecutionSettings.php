<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20\Config;

/** Dedicated GLPI A identity used for inbound (B-to-A) asset writes. */
final class LocalExecutionSettings
{
    private const CONTEXT = 'plugin:assetsync20_local_execution';
    private const SERVICE_USER_KEY = 'service_user_id';

    public static function getServiceUserId(): int
    {
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            return 0;
        }

        $values = \Config::getConfigurationValues(self::CONTEXT, [self::SERVICE_USER_KEY]);
        return max(0, (int) ($values[self::SERVICE_USER_KEY] ?? 0));
    }

    public static function saveServiceUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Enter the GLPI A service user ID.');
        }
        if (!class_exists('\Config') || !method_exists('\Config', 'setConfigurationValues')) {
            throw new \RuntimeException('GLPI configuration storage is unavailable.');
        }

        $user = new \User();
        if (!$user->getFromDB($userId) || !empty($user->fields['is_deleted']) || empty($user->fields['is_active'])) {
            throw new \RuntimeException('The GLPI A service user does not exist, is inactive, or is deleted.');
        }

        \Config::setConfigurationValues(self::CONTEXT, [self::SERVICE_USER_KEY => (string) $userId]);
    }
}
