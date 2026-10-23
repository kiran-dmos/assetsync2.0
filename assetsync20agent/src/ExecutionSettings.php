<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Settings for Agent B's internal, audited GLPI B execution path. */
final class ExecutionSettings
{
    private const CONTEXT = 'plugin:assetsync20agent_execution';

    public static function load(): array
    {
        return \Config::getConfigurationValues(self::CONTEXT) + ['service_user_id' => '0'];
    }

    public static function save(array $input): void
    {
        $serviceUserId = (int) ($input['service_user_id'] ?? 0);
        if ($serviceUserId <= 0) {
            throw new \RuntimeException('Enter the assetsync-agent-b GLPI user ID.');
        }
        $user = new \User();
        if (!$user->getFromDB($serviceUserId) || !empty($user->fields['is_deleted'])) {
            throw new \RuntimeException('The selected Agent B service user does not exist or is deleted.');
        }
        \Config::setConfigurationValues(self::CONTEXT, [
            'service_user_id' => (string) $serviceUserId,
        ]);
    }

    public static function clear(): void
    {
        \Config::deleteConfigurationValues(self::CONTEXT, ['service_user_id', 'command_token']);
    }
}
