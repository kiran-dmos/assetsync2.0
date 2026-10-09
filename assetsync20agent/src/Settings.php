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
        $settings['token'] = (new \GLPIKey())->encrypt($settings['token']);
        \Config::setConfigurationValues(self::CONTEXT, $settings);
    }
}
