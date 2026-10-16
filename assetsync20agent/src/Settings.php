<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Settings
{
    public const CONTEXT = 'plugin:assetsync20agent';

    public static function load(): array
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        unset($settings['token'], $settings['endpoint'], $settings['wake_token_hash']);
        $settings['agent_auth'] = 'glpi_session';
        $settings['service_password'] = (new \GLPIKey())->decrypt($settings['service_password'] ?? '') ?? '';
        foreach (['agent_app_token', 'agent_user_token'] as $key) {
            $settings[$key] = (new \GLPIKey())->decrypt($settings[$key] ?? '') ?? '';
        }
        return $settings;
    }

    public static function transportReady(array $settings): bool
    {
        return ($settings['mode'] ?? '') === 'agent'
            && AgentSession::validUrl($settings['agent_api_url'] ?? '')
            && !empty($settings['agent_app_token']) && !empty($settings['agent_user_token']);
    }

    public static function save(array $input): void
    {
        $old = self::load();
        $settings = AgentSession::configuration($input, $old);
        $settings['mode'] = (string) ($input['mode'] ?? $old['mode'] ?? 'rest');
        if ($settings['mode'] !== 'agent') { throw new \InvalidArgumentException('Notification-only mode is retired. Configure native agent mode.'); }
        foreach (['registration', 'generation'] as $key) {
            $settings[$key] = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            if (!preg_match('/^[a-f0-9]{32}$/D', $settings[$key])) { throw new \InvalidArgumentException('Valid registration and generation are required.'); }
        }
        if (!self::transportReady($settings)) { throw new \InvalidArgumentException('Native Agent API URL, app token and user token are required.'); }
        // The generation is part of each outbox claim; old requests cannot acknowledge a reset.
        if ($settings['mode'] === 'agent') {
            foreach (['connection_id', 'service_user', 'service_profile'] as $key) {
                $settings[$key] = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            }
            foreach (['service_user', 'service_profile'] as $key) {
                if (!ctype_digit($settings[$key]) || (int) $settings[$key] <= 0) { throw new \RuntimeException('Invalid service scope.'); }
            }
            if ($settings['connection_id'] === '' || strlen($settings['connection_id']) > 255) {
                throw new \RuntimeException('A connection binding is required.');
            }
            ServiceContext::account((int) $settings['service_user']);
            if (self::entities((int) $settings['service_user'], (int) $settings['service_profile']) === []) {
                throw new \RuntimeException('Select a profile with a direct nonrecursive entity grant.');
            }
            $user = new \User();
            $user->getFromDB((int) $settings['service_user']);
            $settings['service_name'] = (string) $user->fields['name'];
            $password = (string) ($input['service_password'] ?? '');
            if ($password === '' && $settings['service_name'] === ($old['service_name'] ?? '')) { $password = $old['service_password']; }
            if ($password === '') { throw new \RuntimeException('A dedicated service login is required.'); }
            $settings['service_password'] = (new \GLPIKey())->encrypt($password);
        }
        foreach (['agent_app_token', 'agent_user_token'] as $key) {
            $settings[$key] = (new \GLPIKey())->encrypt($settings[$key]);
        }
        \Config::setConfigurationValues(self::CONTEXT, $settings);
    }

    public static function install(): void
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        if (($settings['native_only_upgrade'] ?? '') !== '1') {
            \Config::deleteConfigurationValues(self::CONTEXT, ['token', 'endpoint', 'wake_token_hash']);
            \Config::setConfigurationValues(self::CONTEXT, ['agent_auth' => 'glpi_session',
                'wake_enabled' => '0', 'wake_binding' => '', 'native_only_upgrade' => '1']);
        }
        if (empty($settings['installation'])) {
            \Config::setConfigurationValues(self::CONTEXT, ['installation' => bin2hex(random_bytes(16))]);
        }
    }

    public static function configureWake(bool $enabled): void
    {
        $old = self::load();
        if ($enabled && !self::transportReady($old)) { throw new \RuntimeException('Wake requires a native agent binding.'); }
        if ($enabled) { ServiceContext::httpAccount((int) ($old['service_user'] ?? 0)); }
        $values = ['wake_enabled' => $enabled ? '1' : '0', 'wake_binding' => $enabled ? self::wakeBinding($old) : ''];
        \Config::setConfigurationValues(self::CONTEXT, $values);
        $saved = self::load();
        foreach ($values as $key => $value) {
            if (($saved[$key] ?? null) !== $value) { throw new \RuntimeException('Wake setting storage unavailable.'); }
        }
    }

    public static function wakeBinding(array $settings): string
    {
        $binding = [];
        $keys = ['mode', 'installation', 'registration', 'generation', 'connection_id',
            'service_user', 'service_profile', 'service_name', 'service_password'];
        foreach ($keys as $key) {
            if (!isset($settings[$key]) || (string) $settings[$key] === '') { throw new \RuntimeException('Wake binding is incomplete.'); }
            $binding[$key] = (string) $settings[$key];
        }
        $binding['transport'] = AgentSession::fingerprint($settings);
        return hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
    }

    public static function entities(int $user, int $profile): array
    {
        global $DB;
        $result = [];
        $rows = Outbox::sql('SELECT DISTINCT `entities_id` FROM `glpi_profiles_users` WHERE `users_id` = ' . $user
            . ' AND `profiles_id` = ' . $profile . ' AND `is_recursive` = 0 ORDER BY `entities_id` LIMIT 51');
        while ($grant = $DB->fetchAssoc($rows)) {
            $id = (int) $grant['entities_id'];
            $result[$id] = \Dropdown::getDropdownName('glpi_entities', $id);
        }
        if (count($result) > 50) { throw new \RuntimeException('Service scope exceeds 50 exact entities. Use a narrower profile.'); }
        return $result;
    }
}
