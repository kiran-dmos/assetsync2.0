<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Settings for Agent B's internal, audited GLPI B execution path. */
final class ExecutionSettings
{
    private const CONTEXT = 'plugin:assetsync20agent_execution';

    public static function load(): array
    {
        $values = \Config::getConfigurationValues(self::CONTEXT);
        $values['command_token'] = (new \GLPIKey())->decrypt($values['command_token'] ?? '') ?? '';
        return $values + ['service_user_id' => '0'];
    }

    public static function save(array $input): void
    {
        $old = self::load();
        $serviceUserId = (int) ($input['service_user_id'] ?? 0);
        $commandToken = trim((string) ($input['command_token'] ?? '')) ?: (string) $old['command_token'];
        if ($serviceUserId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $commandToken)) {
            throw new \RuntimeException('Enter the assetsync-agent-b GLPI user ID and a 64-character command token.');
        }
        $user = new \User();
        if (!$user->getFromDB($serviceUserId) || !empty($user->fields['is_deleted'])) {
            throw new \RuntimeException('The selected Agent B service user does not exist or is deleted.');
        }
        \Config::setConfigurationValues(self::CONTEXT, [
            'service_user_id' => (string) $serviceUserId,
            'command_token'   => (new \GLPIKey())->encrypt($commandToken),
        ]);
    }

    public static function clear(): void
    {
        \Config::deleteConfigurationValues(self::CONTEXT, ['service_user_id', 'command_token']);
    }
}
