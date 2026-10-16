<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Settings
{
    public const CONTEXT = 'plugin:assetsync20agent';

    public static function load(): array
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        $settings['token'] = (new \GLPIKey())->decrypt($settings['token'] ?? '') ?? '';
        $settings['service_password'] = (new \GLPIKey())->decrypt($settings['service_password'] ?? '') ?? '';
        foreach (['agent_app_token', 'agent_user_token'] as $key) {
            $settings[$key] = (new \GLPIKey())->decrypt($settings[$key] ?? '') ?? '';
        }
        return $settings;
    }

    public static function transportReady(array $settings): bool
    {
        if (($settings['agent_auth'] ?? 'legacy_bearer') === 'glpi_session') {
            return AgentSession::validUrl($settings['agent_api_url'] ?? '')
                && !empty($settings['agent_app_token']) && !empty($settings['agent_user_token']);
        }
        return ($settings['agent_auth'] ?? 'legacy_bearer') === 'legacy_bearer'
            && self::validEndpoint($settings['endpoint'] ?? '') && !empty($settings['token']);
    }

    public static function validEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/front/agent-notify.php') && !preg_match('/[\x00-\x20\x7f]/', $url);
    }

    public static function save(array $input): void
    {
        $old = self::load();
        $settings = AgentSession::configuration($input, $old);
        $settings['mode'] = (string) ($input['mode'] ?? $old['mode'] ?? 'rest');
        if ($settings['agent_auth'] === 'glpi_session' && $settings['mode'] !== 'agent') {
            throw new \InvalidArgumentException('Native agent authentication requires agent mode.');
        }
        if (!in_array($settings['mode'], ['rest', 'agent'], true)) {
            throw new \RuntimeException('Invalid agent mode.');
        }
        foreach (['endpoint', 'registration', 'generation', 'token'] as $key) {
            $settings[$key] = trim((string) ($input[$key] ?? ''));
        }
        if ($settings['agent_auth'] === 'glpi_session' && $settings['endpoint'] === '') {
            $settings['endpoint'] = (string) ($old['endpoint'] ?? '');
        }
        if ($settings['token'] === '' && $settings['registration'] === ($old['registration'] ?? '')
            && $settings['generation'] === ($old['generation'] ?? '')) {
            $settings['token'] = $old['token'];
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $settings['registration'])
            || !preg_match('/^[a-f0-9]{32}$/D', $settings['generation'])
            || ($settings['agent_auth'] === 'legacy_bearer' && (!self::validEndpoint($settings['endpoint'])
                || !preg_match('/^[a-f0-9]{64}$/D', $settings['token'])))) {
            throw new \RuntimeException('Enter a direct HTTPS receiver URL and valid registration credentials.');
        }
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
        $settings['token'] = (new \GLPIKey())->encrypt($settings['token']);
        foreach (['agent_app_token', 'agent_user_token'] as $key) {
            $settings[$key] = (new \GLPIKey())->encrypt($settings[$key]);
        }
        \Config::setConfigurationValues(self::CONTEXT, $settings);
    }

    public static function install(): void
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        if (empty($settings['installation'])) {
            \Config::setConfigurationValues(self::CONTEXT, ['installation' => bin2hex(random_bytes(16))]);
        }
    }

    /** The independent wake secret is shown once; disable/rotation revokes it immediately. */
    public static function configureWake(bool $enabled, bool $rotate = false): ?string
    {
        $old = self::load();
        if ($enabled && (($old['mode'] ?? '') !== 'agent' || !self::transportReady($old))) {
            throw new \RuntimeException('Wake requires a configured agent binding.');
        }
        if ($enabled) { ServiceContext::httpAccount((int) ($old['service_user'] ?? 0)); }
        $binding = $enabled ? self::wakeBinding($old) : '';
        $native = ($old['agent_auth'] ?? 'legacy_bearer') === 'glpi_session';
        $token = !$native && $enabled && ($rotate || empty($old['wake_token_hash']) || ($old['wake_binding'] ?? '') !== $binding) ? bin2hex(random_bytes(32)) : null;
        $hash = $enabled ? ($token !== null ? hash('sha256', $token) : ($old['wake_token_hash'] ?? '')) : '';
        \Config::setConfigurationValues(self::CONTEXT, ['wake_enabled' => $enabled ? '1' : '0',
            'wake_token_hash' => $hash, 'wake_binding' => $binding]);
        $saved = self::load();
        if (($saved['wake_enabled'] ?? null) !== ($enabled ? '1' : '0') || ($saved['wake_token_hash'] ?? null) !== $hash
            || ($saved['wake_binding'] ?? null) !== $binding) { throw new \RuntimeException('Wake credential storage unavailable.'); }
        return $token;
    }

    public static function wakeBinding(array $settings): string
    {
        $binding = [];
        $keys = ['mode', 'installation', 'registration', 'generation', 'connection_id',
            'service_user', 'service_profile', 'service_name', 'service_password'];
        if (($settings['agent_auth'] ?? 'legacy_bearer') === 'legacy_bearer') { $keys = array_merge($keys, ['endpoint', 'token']); }
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
