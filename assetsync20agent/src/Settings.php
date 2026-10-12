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
        return $settings;
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
        $settings = [];
        $settings['mode'] = (string) ($input['mode'] ?? $old['mode'] ?? 'rest');
        if (!in_array($settings['mode'], ['rest', 'agent'], true)) {
            throw new \RuntimeException('Invalid agent mode.');
        }
        foreach (['endpoint', 'registration', 'generation', 'token'] as $key) {
            $settings[$key] = trim((string) ($input[$key] ?? ''));
        }
        if ($settings['token'] === '' && $settings['registration'] === ($old['registration'] ?? '')
            && $settings['generation'] === ($old['generation'] ?? '')) {
            $settings['token'] = $old['token'];
        }
        if (!self::validEndpoint($settings['endpoint']) || !preg_match('/^[a-f0-9]{32}$/D', $settings['registration'])
            || !preg_match('/^[a-f0-9]{32}$/D', $settings['generation']) || !preg_match('/^[a-f0-9]{64}$/D', $settings['token'])) {
            throw new \RuntimeException('Enter a direct HTTPS receiver URL and valid registration credentials.');
        }
        // The generation is part of each outbox claim; old requests cannot acknowledge a reset.
        if ($settings['mode'] === 'agent') {
            foreach (['connection_id', 'computer_id', 'entity_id', 'service_user', 'service_profile', 'service_name'] as $key) {
                $settings[$key] = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            }
            foreach (['computer_id', 'service_user', 'service_profile'] as $key) {
                if (!ctype_digit($settings[$key]) || (int) $settings[$key] <= 0) { throw new \RuntimeException('Invalid service scope.'); }
            }
            if (!ctype_digit($settings['entity_id']) || $settings['connection_id'] === '' || $settings['service_name'] === '') {
                throw new \RuntimeException('An exact entity and connection binding are required.');
            }
            $password = (string) ($input['service_password'] ?? '');
            if ($password === '' && $settings['service_name'] === ($old['service_name'] ?? '')) { $password = $old['service_password']; }
            if ($password === '') { throw new \RuntimeException('A dedicated service login is required.'); }
            $settings['service_password'] = (new \GLPIKey())->encrypt($password);
        }
        $settings['token'] = (new \GLPIKey())->encrypt($settings['token']);
        \Config::setConfigurationValues(self::CONTEXT, $settings);
    }

    public static function install(): void
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        if (empty($settings['installation'])) {
            \Config::setConfigurationValues(self::CONTEXT, ['installation' => bin2hex(random_bytes(16))]);
        }
    }
}
